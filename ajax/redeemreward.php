<?php

include('../../../inc/includes.php');

header('Content-Type: application/json');

// CSRF is enforced globally by GLPI 11's CheckCsrfListener (kernel) before this
// script runs — it validates the X-Glpi-Csrf-Token header for AJAX requests.
Session::checkLoginUser();
Session::checkRight('plugin_gamification_rewards', READ);

$rewards_id = isset($_POST['rewards_id']) ? (int)$_POST['rewards_id'] : 0;
if ($rewards_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reward ID']);
    exit;
}

$users_id = Session::getLoginUserID();
$result = \GlpiPlugin\Gamification\RewardOrder::redeem($users_id, $rewards_id);

echo json_encode($result);
