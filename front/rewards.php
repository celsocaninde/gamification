<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Reward;
use GlpiPlugin\Gamification\RewardOrder;
use GlpiPlugin\Gamification\Score;
use GlpiPlugin\Gamification\Config;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_rewards', READ);
Menu::checkPanelEnabled();

if (!Config::getConfig('enable_rewards_shop')) {
    Html::header(__('Rewards', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'rewards');
    echo "<div class='container-fluid py-5 text-center gamification-wrapper'>";
    echo "<i class='ti ti-building-store fs-1 text-muted mb-3 d-block'></i>";
    echo "<h3>" . __('Loja Fechada', 'gamification') . "</h3>";
    echo "<p class='text-muted'>" . __('A loja de recompensas está temporariamente desativada pelo administrador.', 'gamification') . "</p>";
    echo "</div>";
    Html::footer();
    exit;
}

$users_id = Session::getLoginUserID();
$score    = Score::getOrCreate($users_id);
$rewards  = Reward::getAvailable();
$orders   = RewardOrder::getForUser($users_id);

Html::header(__('Rewards', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'rewards');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// ─────────────────────────────────────────────────────────── shop hero ────
echo "<div class='gx-shop-hero mb-4'>";
echo "<div>";
echo "<p class='gx-eyebrow mb-1'>" . __('Loja de recompensas', 'gamification') . "</p>";
echo "<h1 class='h3 m-0' style='color:#1a1205'>" . __('Troque seu XP por benefícios reais', 'gamification') . "</h1>";
echo "</div>";
echo "<div class='text-end'>";
echo "<p class='gx-eyebrow mb-1'>" . __('Saldo disponível', 'gamification') . "</p>";
echo "<div class='gx-num gx-balance'><i class='ti ti-coin me-1'></i>" . number_format($score['xp_available'], 0, ',', '.') . " XP</div>";
echo "</div>";
echo "</div>";

// ────────────────────────────────────────────────────────────── grid ──────
echo "<div class='row g-4 mb-4'>";
if (empty($rewards)) {
    echo "<div class='col-12 text-center text-muted py-5'>" . __('Nenhuma recompensa disponível no momento.', 'gamification') . "</div>";
} else {
    foreach ($rewards as $reward) {
        $afford   = $score['xp_available'] >= $reward['xp_cost'];
        $pct      = min(100, round(($score['xp_available'] / max(1, $reward['xp_cost'])) * 100));
        $btnClass = $afford ? 'btn-primary btn-redeem-reward' : 'btn-outline-secondary disabled';
        $btnText  = $afford ? __('Resgatar', 'gamification') : __('XP insuficiente', 'gamification');

        $icon = 'ti ti-gift';
        if ($reward['category'] == 'time_off')      $icon = 'ti ti-plane-departure';
        if ($reward['category'] == 'gadgets')       $icon = 'ti ti-device-laptop';
        if ($reward['category'] == 'entertainment') $icon = 'ti ti-ticket';
        if ($reward['category'] == 'education')      $icon = 'ti ti-school';

        echo "<div class='col-md-6 col-lg-4'>";
        echo "<div class='gx-card gx-reward h-100 is-hover'>";
        echo "<div class='gx-reward-top'>";
        echo "<i class='{$icon}'></i>";
        echo "<span class='gx-reward-cost'><i class='ti ti-coin me-1'></i>" . number_format($reward['xp_cost'], 0, ',', '.') . "</span>";
        echo "</div>";
        echo "<div class='p-3 d-flex flex-column flex-grow-1'>";
        echo "<h3 class='h6 fw-bold mb-1'>" . htmlspecialchars($reward['name']) . "</h3>";
        echo "<p class='small text-muted flex-grow-1'>" . htmlspecialchars((string) $reward['description']) . "</p>";

        if (!$afford) {
            echo "<div class='gx-xpbar mb-2' style='height:8px'><div class='gx-xpbar-fill' style='width:{$pct}%'></div></div>";
            echo "<div class='small text-muted mb-2'>" . sprintf(__('Faltam %s XP', 'gamification'), number_format($reward['xp_cost'] - $score['xp_available'], 0, ',', '.')) . "</div>";
        }

        echo "<div class='d-flex justify-content-between align-items-center mt-auto'>";
        echo "<span class='badge bg-secondary-subtle text-secondary-emphasis'>";
        echo $reward['stock'] == -1 ? __('Ilimitado', 'gamification') : sprintf(__('%d disponíveis', 'gamification'), $reward['stock']);
        echo "</span>";
        echo "<button type='button' class='btn btn-sm {$btnClass}' data-id='{$reward['id']}' data-name=\"" . htmlspecialchars($reward['name'], ENT_QUOTES) . "\" data-cost='{$reward['xp_cost']}'>{$btnText}</button>";
        echo "</div>";
        echo "</div></div></div>";
    }
}
echo "</div>";

// ──────────────────────────────────────────────────────── order history ───
echo "<div class='gx-card gx-card-pad'>";
echo "<h2 class='h5 mb-3'><i class='ti ti-history me-2 text-primary'></i>" . __('Meus pedidos', 'gamification') . "</h2>";
if (empty($orders)) {
    echo "<p class='text-muted text-center py-3'>" . __('Você ainda não fez nenhum resgate.', 'gamification') . "</p>";
} else {
    echo "<div class='table-responsive'><table class='table table-hover align-middle mb-0'>";
    echo "<thead><tr><th>" . __('Data', 'gamification') . "</th><th>" . __('Recompensa', 'gamification') . "</th><th>" . __('XP Gasto', 'gamification') . "</th><th>" . __('Status', 'gamification') . "</th></tr></thead><tbody>";
    foreach ($orders as $order) {
        $map = [
            'pending'  => ['bg-warning text-dark', __('Pendente', 'gamification')],
            'approved' => ['bg-success',            __('Aprovado', 'gamification')],
            'rejected' => ['bg-danger',             __('Rejeitado', 'gamification')],
        ];
        [$scls, $stext] = $map[$order['status']] ?? ['bg-secondary', $order['status']];
        echo "<tr>";
        echo "<td>" . Html::convDateTime($order['date_creation']) . "</td>";
        echo "<td class='fw-bold'>" . htmlspecialchars((string) $order['reward_name']) . "</td>";
        echo "<td>{$order['xp_spent']}</td>";
        echo "<td><span class='badge {$scls}'>{$stext}</span>";
        if ($order['admin_notes']) {
            echo " <i class='ti ti-info-circle text-muted ms-1' title='" . Html::cleanInputText($order['admin_notes']) . "'></i>";
        }
        echo "</td></tr>";
    }
    echo "</tbody></table></div>";
}
echo "</div>";

echo "</div>"; // wrapper
Html::footer();
