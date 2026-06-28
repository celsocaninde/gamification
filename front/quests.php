<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Quest;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_dashboard', READ);
Menu::checkPanelEnabled();

$users_id   = Session::getLoginUserID();
$week_start = Quest::getWeekStart();
$week_end   = date('Y-m-d', strtotime($week_start . ' +6 days'));
$quests     = Quest::getActive();

$done = 0;
foreach ($quests as $q) {
    $progress = Quest::getProgress($users_id, $q['metric'], $week_start);
    if ($progress >= (int) $q['target'] || Quest::isClaimed($q['id'], $users_id, $week_start)) {
        $done++;
    }
}

Html::header(__('Missões', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'quests');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// header
echo "<div class='gx-card gx-card-pad mb-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3'>";
echo "<div>";
echo "<p class='gx-eyebrow mb-1'><i class='ti ti-target-arrow me-1'></i>" . __('Missões da semana', 'gamification') . "</p>";
echo "<h1 class='h4 m-0'>" . sprintf(__('%d de %d concluídas', 'gamification'), $done, count($quests)) . "</h1>";
echo "<p class='text-muted small m-0 mt-1'>" . sprintf(__('Período: %s até %s · zera toda segunda-feira', 'gamification'), Html::convDate($week_start), Html::convDate($week_end)) . "</p>";
echo "</div>";
$pct_all = count($quests) > 0 ? round(($done / count($quests)) * 100) : 0;
echo "<div class='gx-num' style='font-size:2.2rem;color:var(--gx-violet)'>{$pct_all}%</div>";
echo "</div>";

if (empty($quests)) {
    echo "<div class='gx-card gx-card-pad text-center text-muted py-5'>";
    echo "<i class='ti ti-target-off fs-1 d-block mb-3'></i>" . __('Nenhuma missão ativa no momento.', 'gamification');
    echo "</div>";
} else {
    echo "<div class='row g-4'>";
    foreach ($quests as $q) {
        $target   = max(1, (int) $q['target']);
        $progress = Quest::getProgress($users_id, $q['metric'], $week_start);
        $claimed  = Quest::isClaimed($q['id'], $users_id, $week_start);
        $complete = $claimed || $progress >= $target;
        $pct      = min(100, round(($progress / $target) * 100));
        $shown    = min($progress, $target);

        echo "<div class='col-md-6 col-lg-4'>";
        echo "<div class='gx-card gx-card-pad gx-quest h-100" . ($complete ? ' is-complete' : '') . "'>";

        echo "<div class='d-flex align-items-start gap-3 mb-3'>";
        echo "<div class='gx-quest-ico'><i class='{$q['icon']}'></i></div>";
        echo "<div class='flex-grow-1'>";
        echo "<h2 class='h6 fw-bold mb-1'>" . htmlspecialchars($q['name']) . "</h2>";
        echo "<div class='small text-muted'>" . htmlspecialchars((string) $q['description']) . "</div>";
        echo "</div>";
        if ($complete) {
            echo "<i class='ti ti-circle-check-filled text-success fs-4'></i>";
        }
        echo "</div>";

        echo "<div class='d-flex justify-content-between small mb-1'>";
        echo "<span class='fw-semibold'>{$shown} / {$target}</span>";
        echo "<span class='gx-quest-reward'><i class='ti ti-bolt'></i> +{$q['xp_reward']} XP</span>";
        echo "</div>";
        echo "<div class='gx-xpbar'><div class='gx-xpbar-fill' style='width:{$pct}%'></div></div>";

        if ($complete) {
            echo "<div class='mt-2 small text-success fw-semibold'><i class='ti ti-check me-1'></i>" .
                ($claimed ? __('Recompensa creditada', 'gamification') : __('Concluída — recompensa a caminho', 'gamification')) . "</div>";
        }

        echo "</div></div>";
    }
    echo "</div>";
}

echo "</div>"; // wrapper
Html::footer();
