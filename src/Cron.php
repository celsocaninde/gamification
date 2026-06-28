<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use CronTask;
use Ticket;

class Cron extends CommonDBTM
{
    public static function cronInfo($name): array
    {
        switch ($name) {
            case 'CheckBadges':
                return ['description' => __('Check and award badges', 'gamification')];
            case 'ProcessSeason':
                return ['description' => __('Process and rotate gamification seasons', 'gamification')];
            case 'CheckQuests':
                return ['description' => __('Award XP for completed weekly quests', 'gamification')];
            case 'CheckBattlePass':
                return ['description' => __('Award battle pass tier rewards', 'gamification')];
        }
        return [];
    }

    /**
     * Grant bonus XP to users who completed weekly quests.
     * Idempotent: a claim row guarantees one reward per user/quest/week.
     */
    public static function cronCheckQuests(CronTask $task): int
    {
        global $DB;

        $quests = Quest::getActive();
        if (empty($quests)) {
            return 1;
        }

        $week = Quest::getWeekStart();

        // Scores are per (user, entity) → award quests in the entity where the work happened.
        foreach ($DB->request(['SELECT' => ['users_id', 'entities_id'], 'FROM' => Score::$table]) as $u_row) {
            $users_id    = (int) $u_row['users_id'];
            $entities_id = (int) $u_row['entities_id'];

            foreach ($quests as $quest) {
                if (Quest::isClaimed($quest['id'], $users_id, $week, $entities_id)) {
                    continue;
                }

                $progress = Quest::getProgress($users_id, $quest['metric'], $week, $entities_id);
                if ($progress < (int) $quest['target']) {
                    continue;
                }

                $DB->insert(Quest::CLAIMS_TABLE, [
                    'quests_id'     => $quest['id'],
                    'users_id'      => $users_id,
                    'entities_id'   => $entities_id,
                    'week_start'    => $week,
                    'xp_awarded'    => (int) $quest['xp_reward'],
                    'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);

                Score::addXP(
                    $users_id,
                    (int) $quest['xp_reward'],
                    'quest_completed',
                    Quest::$table,
                    (int) $quest['id'],
                    sprintf(__('Missão concluída: %s', 'gamification'), $quest['name']),
                    $entities_id
                );

                $task->addVolume(1);
            }
        }

        return 1;
    }

    public static function cronCheckBadges(CronTask $task): int
    {
        global $DB;
        $active_badges = Badge::getAll(true);
        if (empty($active_badges)) {
            return 1;
        }

        // Iterate per (user, entity) score row → badges are earned per entity.
        $scores_iter = $DB->request([
            'FROM'   => Score::$table
        ]);

        foreach ($scores_iter as $score) {
            $users_id    = (int) $score['users_id'];
            $entities_id = (int) $score['entities_id'];

            foreach ($active_badges as $badge) {
                // If already earned in this entity, skip
                if (BadgeUser::hasEarned($badge['id'], $users_id, null, $entities_id)) {
                    continue;
                }

                $awarded = false;
                $val = (int)$badge['criteria_value'];

                switch ($badge['criteria_type']) {
                    case 'tickets_resolved_total':
                        if ($score['tickets_resolved'] >= $val) $awarded = true;
                        break;
                    case 'perfect_satisfaction_total':
                        if ($score['perfect_satisfaction'] >= $val) $awarded = true;
                        break;
                    case 'fcr_total':
                        if ($score['fcr_count'] >= $val) $awarded = true;
                        break;
                    case 'kb_articles_total':
                        if ($score['kb_articles'] >= $val) $awarded = true;
                        break;
                    case 'no_reopen_streak':
                        if ($score['current_streak'] >= $val) $awarded = true;
                        break;
                    case 'high_priority_sla_weekly':
                        // Complex: count high priority tickets solved within SLA this week
                        // For simplicity in this implementation, we check if they just have enough SLA met globally
                        if ($score['sla_met_count'] >= $val) $awarded = true;
                        break;
                    case 'after_hours_top_monthly':
                        // Complex: requires comparing with others. We skip in this basic cron for now
                        break;
                }

                if ($awarded) {
                    BadgeUser::award($badge['id'], $users_id, null, $entities_id);
                }
            }
        }

        return 1;
    }

    /**
     * Check each (user, entity) score row and award any unlocked battle pass tiers.
     * Idempotent: each tier is claimed at most once per user/season/entity.
     */
    public static function cronCheckBattlePass(CronTask $task): int
    {
        global $DB;

        $season = Season::getActiveSeason();
        if (!$season) {
            return 1;
        }
        $seasons_id = (int) $season['id'];

        // Seed tiers for the season if an admin hasn't configured them yet.
        BattlePass::seedForSeason($seasons_id);

        foreach ($DB->request(['SELECT' => ['users_id', 'entities_id'], 'FROM' => Score::$table]) as $row) {
            BattlePass::processUserProgress((int) $row['users_id'], $seasons_id, (int) $row['entities_id']);
            $task->addVolume(1);
        }

        return 1;
    }

    public static function cronProcessSeason(CronTask $task): int
    {
        $active = Season::getActiveSeason();
        if (!$active) {
            Season::openNewSeason();
            return 1;
        }

        $now = new \DateTime();
        $end = new \DateTime($active['date_end']);

        // Set time to end of day
        $end->setTime(23, 59, 59);

        if ($now > $end) {
            Season::closeSeason($active['id']);
            Season::openNewSeason();
            $task->addVolume(1);
        }

        return 1;
    }
}
