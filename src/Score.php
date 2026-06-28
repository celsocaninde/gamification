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

    /** Active entity of the current session (0 = root). Used as default scope. */
    public static function curEntity(): int
    {
        return (int) ($_SESSION['glpiactive_entity'] ?? 0);
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

    public static function removeXP(int $users_id, int $amount, string $event_type, ?string $source_itemtype = null, ?int $source_items_id = null, string $description = '', ?int $entities_id = null): void
    {
        global $DB;
        $entities_id ??= self::curEntity();
        $score = self::getOrCreate($users_id, $entities_id);

        $new_xp_total = max(0, $score['xp_total'] - $amount);
        $new_xp_available = max(0, $score['xp_available'] - $amount);
        $new_xp_season = max(0, $score['xp_season'] - $amount);

        $new_level = self::calculateLevel($new_xp_total);

        $DB->update(self::$table, [
            'xp_total'     => $new_xp_total,
            'xp_available' => $new_xp_available,
            'xp_season'    => $new_xp_season,
            'level'        => $new_level,
            'date_mod'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ], ['users_id' => $users_id, 'entities_id' => $entities_id]);

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
