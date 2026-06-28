<?php

namespace GlpiPlugin\Gamification;

use CommonGLPI;
use Session;

class Dashboard extends CommonGLPI
{
    public static function getCards(): array
    {
        return [
            'gamification_top_scorers' => [
                'label'      => __('Top Scorers', 'gamification'),
                'widgettype' => ['bigNumber'],
                'group'      => __('Gamification', 'gamification'),
                'provider'   => self::class . '::getTopScorer',
            ],
            'gamification_recent_badges' => [
                'label'      => __('Recent Badges', 'gamification'),
                'widgettype' => ['searchCount'],
                'group'      => __('Gamification', 'gamification'),
                'provider'   => self::class . '::getRecentBadgesCount',
            ],
            'gamification_team_xp' => [
                'label'      => __('Team XP (Season)', 'gamification'),
                'widgettype' => ['bigNumber'],
                'group'      => __('Gamification', 'gamification'),
                'provider'   => self::class . '::getTeamXP',
            ],
        ];
    }

    public static function getTopScorer(): array
    {
        $top = Score::getTopUsers(1);
        if (empty($top)) {
            return [
                'number' => 0,
                'label'  => __('No data', 'gamification'),
            ];
        }

        $user = $top[0];
        $name = getUserName($user['users_id']);

        return [
            'number' => $user['xp_season'],
            'label'  => $name . ' (' . __('Top Scorer', 'gamification') . ')',
            'url'    => \Plugin::getWebDir('gamification') . '/front/leaderboard.php',
            'icon'   => 'ti ti-trophy'
        ];
    }

    public static function getRecentBadgesCount(): array
    {
        global $DB;
        // Count badges awarded in last 7 days
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        
        $count = countElementsInTable(BadgeUser::$table, [
            'earned_date' => ['>=', $seven_days_ago]
        ]);

        return [
            'number' => $count,
            'label'  => __('Badges earned this week', 'gamification'),
            'url'    => \Plugin::getWebDir('gamification') . '/front/badges.php',
            'icon'   => 'ti ti-medal'
        ];
    }

    public static function getTeamXP(): array
    {
        global $DB;
        $active = Season::getActiveSeason();
        if (!$active) {
             return [
                'number' => 0,
                'label'  => __('No active season', 'gamification'),
            ];
        }

        $row = $DB->request([
            'SELECT' => ['SUM(xp_earned) AS total'],
            'FROM'   => Leaderboard::$table,
            'WHERE'  => ['seasons_id' => $active['id'], 'entities_id' => Score::curEntity()]
        ])->current();

        $total = $row ? (int)$row['total'] : 0;

        return [
            'number' => $total,
            'label'  => __('Total XP this season', 'gamification'),
            'url'    => \Plugin::getWebDir('gamification') . '/front/leaderboard.php',
            'icon'   => 'ti ti-bolt'
        ];
    }

    public static function showOnCentral(): void
    {
        if (!Session::haveRight('plugin_gamification_dashboard', READ)) {
            return;
        }
        if (!Config::isEnabledForCurrentEntity()) {
            return;
        }

        $users_id = Session::getLoginUserID();
        $score = Score::getOrCreate($users_id);
        $rank = Leaderboard::getPositionForUser($users_id);

        $base    = (int) Config::getConfig('xp_per_level_base') ?: 100;
        $level   = (int) $score['level'];
        $xp_cur  = pow($level - 1, 2) * $base;
        $xp_next = pow($level, 2) * $base;
        $xp_need = max(1, $xp_next - $xp_cur);
        $progress = min(100, max(0, round((($score['xp_total'] - $xp_cur) / $xp_need) * 100)));
        $dir = \Plugin::getWebDir('gamification');

        echo "<div class='gamification-wrapper mb-3'>";
        echo "<div class='gx-central'>";
        echo "<div class='gx-central-head d-flex align-items-center justify-content-between'>";
        echo "<span><i class='ti ti-trophy me-2'></i>" . __('Gamificação', 'gamification') . "</span>";
        if ($rank) {
            echo "<span class='badge bg-light text-dark'><i class='ti ti-trophy me-1'></i>#{$rank}</span>";
        }
        echo "</div>";
        echo "<div class='p-3 d-flex align-items-center gap-3'>";
        echo "<div class='gx-orb gx-orb--ink' style='--gx-end:{$progress};--gx-orb-size:74px'>";
        echo "<div class='gx-orb-core'><div class='gx-num' style='font-size:1.3rem'>{$level}</div></div>";
        echo "</div>";
        echo "<div class='flex-grow-1'>";
        echo "<div class='gx-num' style='font-size:1.25rem'>" . number_format($score['xp_total'], 0, ',', '.') . " <span class='small text-muted fw-normal'>XP</span></div>";
        echo "<div class='gx-xpbar mt-2' style='height:8px'><div class='gx-xpbar-fill' style='width:{$progress}%'></div></div>";
        echo "<a href='{$dir}/front/dashboard.php' class='btn btn-sm btn-outline-primary mt-2'>" . __('Ver painel', 'gamification') . "</a>";
        echo "</div>";
        echo "</div></div></div>";
    }
}
