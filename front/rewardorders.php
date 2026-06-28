<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\RewardOrder;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', READ);

Html::header(__('Reward Orders', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'rewardorders');

$orders = RewardOrder::getPending();

echo "<div class='container-fluid py-4 gamification-wrapper'>";
echo "<div class='gamify-card'>";
echo "<div class='card-header bg-transparent border-bottom-0 pt-4 px-4 d-flex justify-content-between align-items-center'>";
echo "<h3 class='m-0'><i class='ti ti-shopping-cart me-2'></i>" . __('Pedidos Pendentes', 'gamification') . "</h3>";
echo "</div>";
echo "<div class='card-body px-4 pb-4'>";

if (empty($orders)) {
    echo "<p class='text-muted text-center py-4'>" . __('Nenhum pedido pendente.', 'gamification') . "</p>";
} else {
    echo "<div class='table-responsive'><table class='table table-hover mb-0'>";
    echo "<thead><tr><th>" . __('Técnico', 'gamification') . "</th><th>" . __('Data', 'gamification') . "</th><th>" . __('Recompensa', 'gamification') . "</th><th>" . __('XP Gasto', 'gamification') . "</th><th>" . __('Ações', 'gamification') . "</th></tr></thead>";
    echo "<tbody>";
    foreach ($orders as $order) {
        echo "<tr>";
        echo "<td><div class='fw-bold'>" . getUserName($order['users_id']) . "</div></td>";
        echo "<td>" . Html::convDateTime($order['date_creation']) . "</td>";
        echo "<td class='fw-bold'>{$order['reward_name']}</td>";
        echo "<td>{$order['xp_spent']}</td>";
        echo "<td>";
        echo "<button class='btn btn-success btn-sm btn-order-action me-2' data-action='approve' data-id='{$order['id']}'>" . __('Aprovar', 'gamification') . "</button>";
        echo "<button class='btn btn-danger btn-sm btn-order-action' data-action='reject' data-id='{$order['id']}'>" . __('Rejeitar', 'gamification') . "</button>";
        echo "</td>";
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}

echo "</div></div></div>";

Html::footer();
