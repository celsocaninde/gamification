<?php

namespace GlpiPlugin\Gamification;

use CommonGLPI;
use User;

class UserTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof User) {
            return __('🎮 Gamificação', 'gamification');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof User)) {
            return false;
        }

        $users_id = $item->getID();
        $score = Score::getOrCreate($users_id);
        $rank = Leaderboard::getPositionForUser($users_id);

        $base = (int)Config::getConfig('xp_per_level_base') ?: 100;
        $level = (int)$score['level'];

        // Calculate progress to next level
        $xp_for_current_level = pow($level - 1, 2) * $base;
        $xp_for_next_level = pow($level, 2) * $base;
        $xp_in_level = $score['xp_total'] - $xp_for_current_level;
        $xp_needed = max(1, $xp_for_next_level - $xp_for_current_level);
        $progress = min(100, max(0, round(($xp_in_level / $xp_needed) * 100)));

        $plugin_dir = \Plugin::getWebDir('gamification');

        echo "<div class='gamification-wrapper mt-4'>";

        // ── Level orb + progress ────────────────────────────────────────────
        echo "<div class='row g-4 mb-4'>";
        echo "<div class='col-md-4'>";
        echo "<div class='gx-card gx-card-pad h-100 d-flex flex-column align-items-center justify-content-center text-center'>";
        echo "<div class='gx-orb gx-orb--ink' style='--gx-end:{$progress};--gx-orb-size:128px'>";
        echo "<div class='gx-orb-core'><div class='gx-num gx-orb-lvl'>{$level}</div><div class='gx-orb-cap'>" . __('Nível', 'gamification') . "</div></div>";
        echo "</div>";
        echo "<div class='gx-num mt-3' style='font-size:1.4rem'>" . number_format($score['xp_total'], 0, ',', '.') . " <span class='small text-muted fw-normal'>XP</span></div>";
        if ($rank) {
            echo "<span class='badge bg-warning-subtle text-warning-emphasis mt-2'><i class='ti ti-trophy me-1'></i>" . sprintf(__('#%d no ranking', 'gamification'), $rank) . "</span>";
        }
        echo "<div class='w-100 mt-3'>";
        echo "<div class='gx-xpbar'><div class='gx-xpbar-fill' style='width:{$progress}%'></div></div>";
        echo "<div class='small text-muted mt-1'>" . sprintf(__('%d / %d XP para o nível %d', 'gamification'), $xp_in_level, $xp_needed, $level + 1) . "</div>";
        echo "</div>";
        echo "</div></div>";

        // ── Stat tiles ──────────────────────────────────────────────────────
        echo "<div class='col-md-8'>";
        echo "<div class='gx-card gx-card-pad h-100'>";
        echo "<h2 class='h6 mb-3'><i class='ti ti-chart-bar me-2 text-primary'></i>" . __('Estatísticas', 'gamification') . "</h2>";
        echo "<div class='gx-stats'>";
        $stats = [
            ['ti-ticket',      __('Tickets', 'gamification'),    $score['tickets_resolved'],     'violet'],
            ['ti-rocket',      __('FCR', 'gamification'),        $score['fcr_count'],            'cyan'],
            ['ti-clock-check', __('SLA', 'gamification'),        $score['sla_met_count'],        'green'],
            ['ti-star-filled', __('5 Estrelas', 'gamification'), $score['perfect_satisfaction'], 'gold'],
            ['ti-book',        __('KB Artigos', 'gamification'), $score['kb_articles'],          'slate'],
            ['ti-flame',       __('Sequência', 'gamification'),  $score['current_streak'],       'ember'],
        ];
        foreach ($stats as [$ico, $lbl, $val, $accent]) {
            echo "<div class='gx-stat gx-stat--{$accent}'>";
            echo "<div class='gx-stat-ico'><i class='ti {$ico}'></i></div>";
            echo "<div class='gx-num gx-stat-val'>" . (int)$val . "</div>";
            echo "<div class='gx-stat-lbl'>{$lbl}</div>";
            echo "</div>";
        }
        echo "</div></div></div></div>";

        // ── Badges ──────────────────────────────────────────────────────────
        $badges = BadgeUser::getForUser($users_id);
        echo "<div class='gx-card gx-card-pad'>";
        echo "<h2 class='h6 mb-3'><i class='ti ti-medal-2 me-2 text-warning'></i>" . __('Conquistas', 'gamification') . " (" . count($badges) . ")</h2>";
        if (empty($badges)) {
            echo "<div class='text-center text-muted py-4'>" . __('Nenhuma conquista ainda.', 'gamification') . "</div>";
        } else {
            echo "<div class='row g-3'>";
            foreach ($badges as $badge) {
                $rarity = strtolower($badge['rarity'] ?? 'common');
                $svg    = strtolower(str_replace(' ', '', $badge['name'])) . '.svg';
                echo "<div class='col-sm-6 col-md-4 col-lg-3'>";
                echo "<div class='d-flex align-items-center gap-3 p-2 rounded rarity-{$rarity}' style='border:1px solid var(--gx-line);border-left:4px solid var(--rarity)'>";
                echo "<div class='gx-badge-orb rarity-{$rarity} is-earned' style='width:52px;height:52px;margin:0;flex:0 0 auto'>";
                if (file_exists(__DIR__ . '/../public/pics/badges/' . $svg)) {
                    echo "<img src='{$plugin_dir}/pics/badges/{$svg}' width='32' height='32' alt=''>";
                } else {
                    echo "<i class='{$badge['icon']}' style='font-size:1.6rem;color:{$badge['icon_color']}'></i>";
                }
                echo "</div>";
                echo "<div class='min-w-0'><div class='fw-bold text-truncate'>" . htmlspecialchars($badge['name']) . "</div>";
                echo "<div class='small text-muted text-truncate'>" . htmlspecialchars((string)$badge['description']) . "</div></div>";
                echo "</div></div>";
            }
            echo "</div>";
        }
        echo "</div>";

        echo "</div>"; // wrapper

        return true;
    }
}
