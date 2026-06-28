<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;

/**
 * Weekly quests: time-boxed challenges that reward bonus XP.
 *
 * Progress is derived on the fly from the immutable XP transaction log
 * (count of the quest's metric event since the start of the week), so no
 * extra per-event bookkeeping is needed. A claim row is written once the
 * target is reached to guarantee the reward is granted only once per week.
 */
class Quest extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_quests';
    public static $rightname = 'plugin_gamification_admin';

    public const CLAIMS_TABLE = 'glpi_plugin_gamification_questclaims';

    public static function getTypeName($nb = 0): string
    {
        return _n('Missão', 'Missões', $nb, 'gamification');
    }

    /** Monday of the current week (Y-m-d). */
    public static function getWeekStart(): string
    {
        return date('Y-m-d', strtotime('monday this week'));
    }

    /** @return array<int,array> active quest definitions */
    public static function getActive(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request([
            'FROM'  => self::$table,
            'WHERE' => ['is_active' => 1],
            'ORDER' => 'xp_reward ASC',
        ]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /** How many times the quest metric happened for the user this week (in entity). */
    public static function getProgress(int $users_id, string $metric, string $week_start, ?int $entities_id = null): int
    {
        $entities_id ??= Score::curEntity();
        return countElementsInTable('glpi_plugin_gamification_xptransactions', [
            'users_id'      => $users_id,
            'entities_id'   => $entities_id,
            'event_type'    => $metric,
            'date_creation' => ['>=', $week_start . ' 00:00:00'],
        ]);
    }

    public static function isClaimed(int $quests_id, int $users_id, string $week_start, ?int $entities_id = null): bool
    {
        $entities_id ??= Score::curEntity();
        return countElementsInTable(self::CLAIMS_TABLE, [
            'quests_id'   => $quests_id,
            'users_id'    => $users_id,
            'entities_id' => $entities_id,
            'week_start'  => $week_start,
        ]) > 0;
    }
}
