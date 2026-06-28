<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Score;
use GlpiPlugin\Gamification\Season;
use GlpiPlugin\Gamification\BattlePass;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_dashboard', READ);
Menu::checkPanelEnabled();

$users_id = Session::getLoginUserID();
$season   = Season::getActiveSeason();

Html::header(__('Battle Pass', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'battlepass');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// ── Header ─────────────────────────────────────────────────────────────────
echo "<div class='d-flex align-items-center gap-3 mb-1'>";
echo "<p class='gx-eyebrow m-0'>" . __('Temporada', 'gamification') . "</p>";
echo "</div>";
echo "<h1 class='h3 mb-4'>Battle Pass" . ($season ? " — " . htmlspecialchars($season['name']) : "") . "</h1>";

if (!$season) {
    echo "<div class='gx-card gx-card-pad text-center py-5'>";
    echo "<i class='ti ti-calendar-off' style='font-size:2.5rem;color:var(--gx-muted)'></i>";
    echo "<p class='mt-3 text-muted'>" . __('Nenhuma temporada ativa no momento.', 'gamification') . "</p>";
    echo "</div></div>";
    Html::footer();
    exit;
}

$entities_id = Score::curEntity();
$score       = Score::getOrCreate($users_id, $entities_id);
$xp_season   = (int) $score['xp_season'];
$tiers       = BattlePass::getTiersForSeason((int) $season['id']);
$claimed     = BattlePass::getClaimedTiers($users_id, (int) $season['id'], $entities_id);

// If no tiers configured yet, seed defaults silently.
if (empty($tiers)) {
    BattlePass::seedForSeason((int) $season['id']);
    $tiers = BattlePass::getTiersForSeason((int) $season['id']);
}

$max_xp      = !empty($tiers) ? (int) end($tiers)['xp_required'] : 1;
$overall_pct = min(100, round($xp_season / max(1, $max_xp) * 100));
$last_tier   = count($claimed);

// ── Season progress summary ────────────────────────────────────────────────
echo "<div class='gx-card gx-card-pad mb-4'>";
echo "<div class='gx-bp-header'>";
echo "<div>";
echo "<div class='fw-bold fs-5'>" . number_format($xp_season, 0, ',', '.') . " <span class='small text-muted fw-normal'>XP na temporada</span></div>";
echo "<div class='small text-muted'>" . sprintf(__('Tier %d/%d desbloqueados', 'gamification'), $last_tier, count($tiers)) . "</div>";
echo "</div>";
$date_end = $season['date_end'] ?? null;
if ($date_end) {
    $days_left = max(0, (int) ceil((strtotime($date_end) - time()) / 86400));
    echo "<div class='text-end'>";
    echo "<div class='small text-muted'>" . __('Termina em', 'gamification') . "</div>";
    echo "<div class='fw-bold'>{$days_left} " . __('dias', 'gamification') . "</div>";
    echo "</div>";
}
echo "</div>";
echo "<div class='gx-bp-progress-bar'><div class='gx-bp-progress-fill' style='width:{$overall_pct}%'></div></div>";
echo "<div class='d-flex justify-content-between small text-muted'><span>0 XP</span><span>" . number_format($max_xp, 0, ',', '.') . " XP</span></div>";
echo "</div>";

// ── Tier track ─────────────────────────────────────────────────────────────
echo "<div class='gx-card' style='overflow-x:auto'>";
echo "<div class='gx-bp-track'>";

foreach ($tiers as $tier) {
    $num        = (int) $tier['tier_num'];
    $is_claimed = in_array($num, $claimed, true);
    $is_unlocked = !$is_claimed && $xp_season >= (int) $tier['xp_required'];

    $state_cls = $is_claimed ? 'is-claimed' : ($is_unlocked ? 'is-unlocked' : 'is-locked');

    // Per-tier XP progress (for locking display)
    $xp_req = (int) $tier['xp_required'];

    // Reward label
    $reward_label = match ($tier['reward_type']) {
        'xp_bonus'  => '+' . (int) $tier['reward_value'] . ' XP',
        'cosmetic'  => '🏆 ' . htmlspecialchars((string) $tier['reward_value']),
        'badge'     => '🎖 ' . htmlspecialchars((string) $tier['reward_value']),
        default     => htmlspecialchars((string) $tier['reward_value']),
    };

    echo "<div class='gx-bp-tier {$state_cls}'>";
    if ($is_claimed) {
        echo "<div class='gx-bp-check'><i class='ti ti-check'></i></div>";
    }
    echo "<div class='gx-bp-num'>" . __('Tier', 'gamification') . " {$num}</div>";
    echo "<div class='gx-bp-ico'><i class='" . htmlspecialchars($tier['icon']) . "' style='color:" . htmlspecialchars($tier['icon_color']) . "'></i></div>";
    echo "<div class='gx-bp-name'>" . htmlspecialchars($tier['name']) . "</div>";
    echo "<div class='gx-bp-reward'>{$reward_label}</div>";
    echo "<div class='gx-bp-xp'>" . number_format($xp_req, 0, ',', '.') . " XP</div>";
    if ($is_claimed) {
        echo "<div class='gx-bp-claimed-label'>" . __('Recebido', 'gamification') . "</div>";
    } elseif ($is_unlocked) {
        echo "<div class='gx-bp-claimed-label' style='color:var(--gx-gold)'>" . __('Pendente cron', 'gamification') . "</div>";
    }
    echo "</div>";
}

echo "</div>"; // track
echo "</div>"; // card

echo "<p class='small text-muted mt-2'><i class='ti ti-info-circle me-1'></i>";
echo __('As recompensas desbloqueadas são concedidas automaticamente pelo sistema a cada hora.', 'gamification');
echo "</p>";

echo "</div>"; // wrapper
Html::footer();
