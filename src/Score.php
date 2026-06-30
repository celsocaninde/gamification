<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Session;

class Score extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_scores';

    public static function getTypeName($nb = 0): string
    {
        return _n('Pontuação', 'Pontuações', $nb, 'gamification');
    }

    /** All XP data is stored at entity 0 (global). Visibility per-entity is
     *  handled separately by Config::isEnabledForCurrentEntity(). */
    public static function curEntity(): int
    {
        return 0;
    }

    public static function getOrCreate(int $users_id, ?int $entities_id = null): array
    {
        global $DB;
        $entities_id ??= self::curEntity();

        $score = $DB->request([
            'FROM'  => self::$table,
            'WHERE' => ['users_id' => $users_id, 'entities_id' => $entities_id]
        ])->current();

        if ($score) {
            return $score;
        }

        $DB->insert(self::$table, [
            'users_id' => $users_id,
            'entities_id' => $entities_id,
            'xp_total' => 0,
            'xp_available' => 0,
            'xp_season' => 0,
            'level' => 1
        ]);

        return $DB->request([
            'FROM'  => self::$table,
            'WHERE' => ['users_id' => $users_id, 'entities_id' => $entities_id]
        ])->current();
    }

    public static function addXP(int $users_id, int $amount, string $event_type, ?string $source_itemtype = null, ?int $source_items_id = null, string $description = '', ?int $entities_id = null): void
    {
        global $DB;
        $entities_id ??= self::curEntity();
        $score = self::getOrCreate($users_id, $entities_id);

        // Streak bonus (optional): reward consecutive resolutions without reopen.
        if ($amount > 0 && Config::getConfig('enable_streak_bonus')) {
            $step = max(1, (int)(Config::getConfig('streak_bonus_step') ?? 5));
            $pct  = max(0, (int)(Config::getConfig('streak_bonus_pct') ?? 5));
            $cap  = max(0, (int)(Config::getConfig('streak_bonus_cap') ?? 50));
            $bonus_pct = min($cap, intdiv((int)$score['current_streak'], $step) * $pct);
            if ($bonus_pct > 0) {
                $bonus = (int)round($amount * $bonus_pct / 100);
                if ($bonus > 0) {
                    $amount += $bonus;
                    $suffix = sprintf(__('(+%d%% sequência)', 'gamification'), $bonus_pct);
                    $description = $description !== '' ? $description . ' ' . $suffix : $suffix;
                }
            }
        }

        $new_xp_total = $score['xp_total'] + $amount;
        $new_xp_available = $score['xp_available'] + $amount;
        $new_xp_season = $score['xp_season'] + $amount;
        
        $new_level = self::calculateLevel($new_xp_total);

        $updates = [
            'xp_total'     => $new_xp_total,
            'xp_available' => $new_xp_available,
            'xp_season'    => $new_xp_season,
            'level'        => $new_level,
            'date_mod'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ];

        // Update stats based on event_type
        if ($event_type === 'ticket_resolved') {
            $updates['tickets_resolved'] = $score['tickets_resolved'] + 1;
        } elseif ($event_type === 'ticket_resolved_fcr') {
            $updates['fcr_count'] = $score['fcr_count'] + 1;
        } elseif ($event_type === 'sla_met') {
            $updates['sla_met_count'] = $score['sla_met_count'] + 1;
        } elseif ($event_type === 'sla_tto_met') {
            $updates['sla_tto_count'] = ($score['sla_tto_count'] ?? 0) + 1;
        } elseif ($event_type === 'satisfaction_max') {
            $updates['perfect_satisfaction'] = $score['perfect_satisfaction'] + 1;
        } elseif ($event_type === 'kb_article_created') {
            $updates['kb_articles'] = $score['kb_articles'] + 1;
        }

        $DB->update(self::$table, $updates, ['users_id' => $users_id, 'entities_id' => $entities_id]);

        // Log transaction
        XPTransaction::log($users_id, $amount, $new_xp_available, $event_type, $source_itemtype, $source_items_id, $description, $entities_id);

        // Level-up event (drives in-app toast + confetti)
        if ($new_level > (int) $score['level']) {
            XPTransaction::log($users_id, 0, $new_xp_available, 'level_up', null, null, sprintf(__('Subiu para o nível %d!', 'gamification'), $new_level), $entities_id);
        }

        // Update leaderboard
        $active_season = Season::getActiveSeason();
        if ($active_season) {
            Leaderboard::updateEntry($users_id, $new_xp_season, $active_season['id'], $entities_id);
        }
    }

    /**
     * Sync the denormalized counter columns (tickets_resolved, fcr_count, etc.)
     * from the authoritative XP transaction log. Safe to call multiple times.
     * Fixes counters stuck at 0 when addXP ran but the UPDATE was skipped due
     * to missing columns on an older install.
     */
    public static function recalculate(int $users_id, ?int $entities_id = null): void
    {
        global $DB;
        $entities_id ??= self::curEntity();

        $event_map = [
            'ticket_resolved'     => 'tickets_resolved',
            'ticket_resolved_fcr' => 'fcr_count',
            'sla_met'             => 'sla_met_count',
            'sla_tto_met'         => 'sla_tto_count',
            'sla_tto_breached'    => 'sla_tto_breach_count',
            'satisfaction_max'    => 'perfect_satisfaction',
            'kb_article_created'  => 'kb_articles',
        ];

        $updates = [];
        foreach ($event_map as $event_type => $col) {
            $updates[$col] = (int) countElementsInTable(
                XPTransaction::$table,
                ['users_id' => $users_id, 'entities_id' => $entities_id, 'event_type' => $event_type]
            );
        }

        self::getOrCreate($users_id, $entities_id);
        $DB->update(self::$table, $updates, ['users_id' => $users_id, 'entities_id' => $entities_id]);
    }

    /**
     * Run recalculate() for every user that has at least one XP transaction.
     * Returns the number of users processed.
     */
    public static function recalculateAll(): int
    {
        global $DB;
        $iterator = $DB->request([
            'SELECT'  => ['users_id', 'entities_id'],
            'FROM'    => XPTransaction::$table,
            'GROUPBY' => ['users_id', 'entities_id'],
        ]);

        $count = 0;
        foreach ($iterator as $row) {
            self::recalculate((int) $row['users_id'], (int) $row['entities_id']);
            $count++;
        }
        return $count;
    }

    public static function removeXP(int $users_id, int $amount, string $event_type, ?string $source_itemtype = null, ?int $source_items_id = null, string $description = '', ?int $entities_id = null): void
    {
        global $DB;
        $entities_id ??= self::curEntity();
        $score = self::getOrCreate($users_id, $entities_id);

        $new_xp_total = max(0, $score['xp_total'] - $amount);
        $new_xp_available = max(0, $score['xp_available'] - $amount);
        $new_xp_season = max(0, $score['xp_season'] - $amount);

        $new_level = self::calculateLevel($new_xp_total);

        $penalty_updates = [
            'xp_total'     => $new_xp_total,
            'xp_available' => $new_xp_available,
            'xp_season'    => $new_xp_season,
            'level'        => $new_level,
            'date_mod'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ];

        if ($event_type === 'sla_tto_breached') {
            $penalty_updates['sla_tto_breach_count'] = ($score['sla_tto_breach_count'] ?? 0) + 1;
        }

        $DB->update(self::$table, $penalty_updates, ['users_id' => $users_id, 'entities_id' => $entities_id]);

        XPTransaction::log($users_id, -$amount, $new_xp_available, $event_type, $source_itemtype, $source_items_id, $description, $entities_id);

        $active_season = Season::getActiveSeason();
        if ($active_season) {
            Leaderboard::updateEntry($users_id, $new_xp_season, $active_season['id'], $entities_id);
        }
    }

    public static function spendXP(int $users_id, int $amount, ?int $entities_id = null): bool
    {
        global $DB;
        $entities_id ??= self::curEntity();
        $score = self::getOrCreate($users_id, $entities_id);

        if ($score['xp_available'] < $amount) {
            return false;
        }

        $new_xp_available = $score['xp_available'] - $amount;

        $DB->update(self::$table, [
            'xp_available' => $new_xp_available,
            'date_mod'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ], ['users_id' => $users_id, 'entities_id' => $entities_id]);

        XPTransaction::log($users_id, -$amount, $new_xp_available, 'reward_redemption', null, null, __('Gasto na loja de recompensas', 'gamification'), $entities_id);

        return true;
    }

    public static function calculateLevel(int $xp_total): int
    {
        $base = (int)(Config::getConfig('xp_per_level_base') ?? 100);
        if ($base <= 0) $base = 100;
        // Formula: floor(sqrt(xp_total / base)) + 1
        return (int)floor(sqrt(max(0, $xp_total) / $base)) + 1;
    }

    /**
     * Rebuild the scores aggregate for a single user from XP transactions.
     * Safe to call multiple times — fully idempotent.
     */
    public static function recalculate(int $users_id, ?int $entities_id = null): void
    {
        global $DB;
        $entities_id ??= self::curEntity();

        // Counters from positive transactions by event type
        $counts = [
            'tickets_resolved'     => 0,
            'fcr_count'            => 0,
            'sla_met_count'        => 0,
            'perfect_satisfaction' => 0,
            'kb_articles'          => 0,
        ];

        $event_map = [
            'ticket_resolved'     => 'tickets_resolved',
            'ticket_resolved_fcr' => 'fcr_count',
            'sla_met'             => 'sla_met_count',
            'satisfaction_max'    => 'perfect_satisfaction',
            'kb_article_created'  => 'kb_articles',
        ];

        // Replay transactions chronologically to rebuild xp_total / xp_available
        $xp_total     = 0;
        $xp_available = 0;

        $txs = $DB->request([
            'FROM'  => XPTransaction::$table,
            'WHERE' => ['users_id' => $users_id, 'entities_id' => $entities_id],
            'ORDER' => 'id ASC',
        ]);

        foreach ($txs as $tx) {
            $amount     = (int) $tx['xp_amount'];
            $event_type = (string) $tx['event_type'];

            // Rebuild xp_total (same logic as addXP / removeXP, no reward spending)
            if ($event_type === 'reward_redemption') {
                $xp_available = max(0, $xp_available - abs($amount));
            } elseif ($amount >= 0) {
                $xp_total     += $amount;
                $xp_available += $amount;
            } else {
                // Penalty: reduces both
                $xp_total     = max(0, $xp_total + $amount);
                $xp_available = max(0, $xp_available + $amount);
            }

            // Count stat events
            if ($amount > 0 && isset($event_map[$event_type])) {
                $counts[$event_map[$event_type]]++;
            }
        }

        // XP for current active season only
        $xp_season = 0;
        $active    = Season::getActiveSeason();
        if ($active) {
            $row = $DB->request([
                'SELECT' => [new \Glpi\DBAL\QueryExpression('SUM(`xp_amount`) AS `total`')],
                'FROM'   => XPTransaction::$table,
                'WHERE'  => [
                    'users_id'    => $users_id,
                    'entities_id' => $entities_id,
                    'event_type'  => ['<>', 'reward_redemption'],
                    ['date_creation' => ['>=', $active['date_start'] . ' 00:00:00']],
                ],
            ])->current();
            $xp_season = max(0, (int) ($row['total'] ?? 0));
        }

        $new_level = self::calculateLevel($xp_total);

        $exists = countElementsInTable(self::$table, [
            'users_id'    => $users_id,
            'entities_id' => $entities_id,
        ]);

        $data = [
            'xp_total'             => $xp_total,
            'xp_available'         => $xp_available,
            'xp_season'            => $xp_season,
            'level'                => $new_level,
            'tickets_resolved'     => $counts['tickets_resolved'],
            'fcr_count'            => $counts['fcr_count'],
            'sla_met_count'        => $counts['sla_met_count'],
            'perfect_satisfaction' => $counts['perfect_satisfaction'],
            'kb_articles'          => $counts['kb_articles'],
            'date_mod'             => date('Y-m-d H:i:s'),
        ];

        if ($exists) {
            $DB->update(self::$table, $data, [
                'users_id'    => $users_id,
                'entities_id' => $entities_id,
            ]);
        } else {
            $DB->insert(self::$table, array_merge($data, [
                'users_id'    => $users_id,
                'entities_id' => $entities_id,
            ]));
        }

        // Sync leaderboard
        if ($active) {
            Leaderboard::updateEntry($users_id, $xp_season, $active['id'], $entities_id);
        }
    }

    /**
     * Recalculate scores for ALL users who have XP transactions.
     * Returns number of users processed.
     */
    public static function recalculateAll(?int $entities_id = null): int
    {
        global $DB;
        $entities_id ??= self::curEntity();

        $users = [];
        foreach ($DB->request([
            'SELECT'   => ['users_id'],
            'FROM'     => XPTransaction::$table,
            'WHERE'    => ['entities_id' => $entities_id],
            'GROUPBY'  => ['users_id'],
        ]) as $row) {
            $users[] = (int) $row['users_id'];
        }

        foreach ($users as $users_id) {
            self::recalculate($users_id, $entities_id);
        }

        return count($users);
    }

    public static function getTopUsers(int $limit = 10, ?int $groups_id = null, ?int $entities_id = null): array
    {
        global $DB;
        $entities_id ??= self::curEntity();
        $where = [self::$table . '.entities_id' => $entities_id];
        $join = [];
        
        $select = [
            'glpi_plugin_gamification_scores.*',
            'glpi_users.firstname',
            'glpi_users.realname',
            'glpi_users.picture'
        ];

        if ($groups_id) {
            $join['glpi_groups_users'] = [
                'ON' => [
                    'glpi_groups_users' => 'users_id',
                    'glpi_plugin_gamification_scores' => 'users_id'
                ]
            ];
            $where['glpi_groups_users.groups_id'] = $groups_id;
        }

        $join['glpi_users'] = [
            'ON' => [
                'glpi_users' => 'id',
                'glpi_plugin_gamification_scores' => 'users_id'
            ]
        ];

        $where['glpi_users.is_deleted'] = 0;
        $where['glpi_users.is_active'] = 1;

        $iterator = $DB->request([
            'SELECT' => $select,
            'FROM'   => self::$table,
            'LEFT JOIN' => $join,
            'WHERE'  => $where,
            'ORDER'  => 'xp_season DESC',
            'LIMIT'  => $limit
        ]);

        $users = [];
        foreach ($iterator as $row) {
            $users[] = $row;
        }

        return $users;
    }
}
