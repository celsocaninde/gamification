<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;

class BattlePass extends CommonDBTM
{
    public static $table        = 'glpi_plugin_gamification_battlepass_tiers';
    public static $claims_table = 'glpi_plugin_gamification_battlepass_claims';

    public static function getTypeName($nb = 0): string
    {
        return _n('Battle Pass', 'Battle Pass', $nb, 'gamification');
    }

    /** 10 default tiers seeded when a new season starts. */
    public static function defaultTiers(): array
    {
        return [
            ['tier_num' => 1,  'xp_required' => 200,   'name' => 'Iniciante',         'icon' => 'ti ti-seedling',  'icon_color' => '#94a3b8', 'reward_type' => 'xp_bonus', 'reward_value' => '50'],
            ['tier_num' => 2,  'xp_required' => 500,   'name' => 'Empolgado',          'icon' => 'ti ti-flame',     'icon_color' => '#f97316', 'reward_type' => 'xp_bonus', 'reward_value' => '80'],
            ['tier_num' => 3,  'xp_required' => 1000,  'name' => 'Em Forma',           'icon' => 'ti ti-bolt',      'icon_color' => '#eab308', 'reward_type' => 'xp_bonus', 'reward_value' => '120'],
            ['tier_num' => 4,  'xp_required' => 1800,  'name' => 'Veterano',           'icon' => 'ti ti-shield',    'icon_color' => '#3b82f6', 'reward_type' => 'cosmetic',  'reward_value' => 'Veterano'],
            ['tier_num' => 5,  'xp_required' => 3000,  'name' => 'Metade do Caminho',  'icon' => 'ti ti-map-pin',   'icon_color' => '#8b5cf6', 'reward_type' => 'xp_bonus', 'reward_value' => '250'],
            ['tier_num' => 6,  'xp_required' => 4500,  'name' => 'Determinado',        'icon' => 'ti ti-dumbbell',  'icon_color' => '#06b6d4', 'reward_type' => 'xp_bonus', 'reward_value' => '350'],
            ['tier_num' => 7,  'xp_required' => 6500,  'name' => 'Especialista',       'icon' => 'ti ti-star',      'icon_color' => '#f59e0b', 'reward_type' => 'cosmetic',  'reward_value' => 'Especialista'],
            ['tier_num' => 8,  'xp_required' => 9000,  'name' => 'Quase Elite',        'icon' => 'ti ti-crown',     'icon_color' => '#dc2626', 'reward_type' => 'xp_bonus', 'reward_value' => '500'],
            ['tier_num' => 9,  'xp_required' => 12000, 'name' => 'Quase Lá',           'icon' => 'ti ti-rocket',    'icon_color' => '#7c3aed', 'reward_type' => 'xp_bonus', 'reward_value' => '750'],
            ['tier_num' => 10, 'xp_required' => 16000, 'name' => 'Campeão',            'icon' => 'ti ti-trophy',    'icon_color' => '#ffd700', 'reward_type' => 'cosmetic',  'reward_value' => 'Campeão da Temporada'],
        ];
    }

    /** Seed default tiers for a season. Idempotent — skips if tiers already exist. */
    public static function seedForSeason(int $seasons_id): void
    {
        global $DB;
        if (countElementsInTable(self::$table, ['seasons_id' => $seasons_id]) > 0) {
            return;
        }
        foreach (self::defaultTiers() as $tier) {
            $tier['seasons_id'] = $seasons_id;
            $DB->insert(self::$table, $tier);
        }
    }

    /** All tiers for a season ordered by tier_num. */
    public static function getTiersForSeason(int $seasons_id): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => self::$table, 'WHERE' => ['seasons_id' => $seasons_id], 'ORDER' => 'tier_num ASC']) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Tier numbers already claimed by a user for a season+entity. */
    public static function getClaimedTiers(int $users_id, int $seasons_id, int $entities_id): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['tier_num'], 'FROM' => self::$claims_table,
            'WHERE' => ['users_id' => $users_id, 'seasons_id' => $seasons_id, 'entities_id' => $entities_id]]) as $row) {
            $out[] = (int) $row['tier_num'];
        }
        return $out;
    }

    /**
     * Compare a user's xp_season against each unclaimed tier and award rewards.
     * Called from cronCheckBattlePass — NOT from Score::addXP (avoids recursion).
     */
    public static function processUserProgress(int $users_id, int $seasons_id, int $entities_id): void
    {
        global $DB;

        $score   = Score::getOrCreate($users_id, $entities_id);
        $xp      = (int) $score['xp_season'];
        $tiers   = self::getTiersForSeason($seasons_id);
        $claimed = self::getClaimedTiers($users_id, $seasons_id, $entities_id);

        foreach ($tiers as $tier) {
            $num = (int) $tier['tier_num'];
            if (in_array($num, $claimed, true) || $xp < (int) $tier['xp_required']) {
                continue;
            }

            self::awardTier($users_id, $entities_id, $tier);

            try {
                $DB->insert(self::$claims_table, [
                    'users_id'    => $users_id,
                    'entities_id' => $entities_id,
                    'seasons_id'  => $seasons_id,
                    'tier_num'    => $num,
                ]);
            } catch (\Throwable $e) {
                // Duplicate — race between two cron processes, ignore.
            }
        }
    }

    private static function awardTier(int $users_id, int $entities_id, array $tier): void
    {
        $desc = sprintf(__('Battle Pass Tier %d: %s', 'gamification'), (int) $tier['tier_num'], $tier['name']);

        switch ($tier['reward_type']) {
            case 'xp_bonus':
                $xp = max(0, (int) $tier['reward_value']);
                if ($xp > 0) {
                    Score::addXP($users_id, $xp, 'battlepass_tier', null, null, $desc, $entities_id);
                }
                break;

            case 'badge':
                $badge = Badge::getByName((string) $tier['reward_value']);
                if ($badge) {
                    BadgeUser::award((int) $badge['id'], $users_id, null, $entities_id);
                }
                break;

            case 'cosmetic':
                $score = Score::getOrCreate($users_id, $entities_id);
                XPTransaction::log($users_id, 0, (int) $score['xp_available'],
                    'battlepass_tier', null, null, $desc . ' — ' . $tier['reward_value'], $entities_id);
                break;
        }
    }
}
