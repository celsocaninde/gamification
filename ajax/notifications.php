<?php

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();

// Read-only style endpoint (GET): no CSRF needed. Returns this user's unseen
// XP / level-up / badge events and marks them seen so each toasts only once.
if (!Session::haveRight('plugin_gamification_dashboard', READ)) {
    echo json_encode([]);
    exit;
}

global $DB;
$users_id = (int) Session::getLoginUserID();
$table    = 'glpi_plugin_gamification_xptransactions';

if (!$DB->fieldExists($table, 'notified')) {
    echo json_encode([]);
    exit;
}

// Toastable unseen events (most recent first, capped).
$rows = [];
foreach ($DB->request([
    'FROM'  => $table,
    'WHERE' => [
        'users_id' => $users_id,
        'notified' => 0,
        'OR'       => [
            ['xp_amount'  => ['>', 0]],
            ['event_type' => ['level_up', 'badge_earned']],
        ],
    ],
    'ORDER' => 'id DESC',
    'LIMIT' => 6,
]) as $r) {
    $rows[] = $r;
}

// Mark ALL of this user's unseen rows as seen (keeps the unseen set small and
// avoids re-toasting events beyond the cap).
$DB->update($table, ['notified' => 1], ['users_id' => $users_id, 'notified' => 0]);

// Build payload (oldest first so toasts stack chronologically).
$out = [];
foreach (array_reverse($rows) as $r) {
    switch ($r['event_type']) {
        case 'level_up':
            $out[] = [
                'kind'   => 'level',
                'title'  => $r['description'] !== '' ? $r['description'] : __('Subiu de nível!', 'gamification'),
                'text'   => '',
                'icon'   => 'ti-arrow-up-circle',
                'accent' => 'violet',
            ];
            break;
        case 'badge_earned':
            $out[] = [
                'kind'   => 'badge',
                'title'  => __('Nova conquista!', 'gamification'),
                'text'   => (string) $r['description'],
                'icon'   => 'ti-medal-2',
                'accent' => 'gold',
            ];
            break;
        default:
            $out[] = [
                'kind'   => 'xp',
                'title'  => '+' . (int) $r['xp_amount'] . ' XP',
                'text'   => (string) $r['description'],
                'icon'   => 'ti-bolt',
                'accent' => 'cyan',
            ];
    }
}

echo json_encode($out);
