<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Badge;
use GlpiPlugin\Gamification\BadgeUser;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_dashboard', READ);
Menu::checkPanelEnabled();

Html::header(__('Badges', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'badges');

$users_id   = Session::getLoginUserID();
$all_badges = Badge::getAll(true);
$earned_raw = BadgeUser::getForUser($users_id);

$earned_ids = array_column($earned_raw, 'badges_id');
$total      = count($all_badges);
$got        = count($earned_ids);
$pct        = $total > 0 ? round(($got / $total) * 100) : 0;

$plugin_dir = \Plugin::getWebDir('gamification');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// ─────────────────────────────────────────────────── collection header ────
echo "<div class='gx-card gx-card-pad mb-4'>";
echo "<div class='d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3'>";
echo "<div>";
echo "<p class='gx-eyebrow mb-1'>" . __('Coleção de conquistas', 'gamification') . "</p>";
echo "<h1 class='h4 m-0'>" . sprintf(__('%d de %d desbloqueadas', 'gamification'), $got, $total) . "</h1>";
echo "</div>";
echo "<div class='gx-num' style='font-size:2.2rem;color:var(--gx-violet)'>{$pct}%</div>";
echo "</div>";
echo "<div class='gx-xpbar mt-3'><div class='gx-xpbar-fill' style='width:{$pct}%'></div></div>";
echo "</div>";

// ───────────────────────────────────────────────────────────── grid ───────
echo "<div class='row g-4'>";
foreach ($all_badges as $badge) {
    $earned  = in_array($badge['id'], $earned_ids);
    $rarity  = strtolower($badge['rarity']);
    $state   = $earned ? 'is-earned' : 'is-locked';
    $svg     = strtolower(str_replace(' ', '', $badge['name'])) . '.svg';
    $svgPath = __DIR__ . '/../public/pics/badges/' . $svg;

    echo "<div class='col-sm-6 col-md-4 col-lg-3'>";
    echo "<div class='gx-badge rarity-{$rarity} {$state}'>";

    if ($earned) {
        echo "<i class='ti ti-circle-check-filled gx-badge-check'></i>";
    }

    echo "<div class='gx-badge-orb'>";
    if (file_exists($svgPath)) {
        echo "<img src='{$plugin_dir}/pics/badges/{$svg}' width='54' height='54' alt=''>";
    } else {
        echo "<i class='{$badge['icon']}' style='font-size:2.6rem;color:{$badge['icon_color']}'></i>";
    }
    echo "</div>";

    echo "<div class='gx-badge-name'>" . htmlspecialchars($badge['name']) . "</div>";
    echo "<div class='gx-badge-desc'>" . htmlspecialchars((string) $badge['description']) . "</div>";
    echo "<span class='rarity-tag'>" . __($badge['rarity'], 'gamification') . "</span>";

    echo "</div></div>";
}
echo "</div>";

echo "</div>"; // wrapper
Html::footer();
