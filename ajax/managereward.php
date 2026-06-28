<?php

include('../../../inc/includes.php');

header('Content-Type: application/json');

// CSRF is enforced globally by GLPI 11's CheckCsrfListener (kernel) before this
// script runs — it validates the X-Glpi-Csrf-Token header for AJAX requests.
Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$action = $_POST['action'] ?? '';
$notes = $_POST['notes'] ?? '';

if ($order_id <= 0 || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

if ($action === 'approve') {
    $result = \GlpiPlugin\Gamification\RewardOrder::approve($order_id, $notes);
} else {
    $result = \GlpiPlugin\Gamification\RewardOrder::reject($order_id, $notes);
}

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Operation failed']);
}
