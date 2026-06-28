<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Quest;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

// CSRF validated/consumed by GLPI 11 kernel before this legacy script runs.

global $DB;
$back = \Plugin::getWebDir('gamification') . '/front/managequests.php';
$id   = (int) ($_POST['id'] ?? 0);

if (isset($_POST['delete']) && $id > 0) {
    $DB->delete(Quest::$table, ['id' => $id]);
    $DB->delete('glpi_plugin_gamification_questclaims', ['quests_id' => $id]);
    Session::addMessageAfterRedirect(__('Missão excluída', 'gamification'), true, INFO);
    Html::redirect($back);
}

if (isset($_POST['save'])) {
    $metrics = ['ticket_resolved', 'ticket_resolved_fcr', 'sla_met', 'satisfaction_max', 'kb_article_created'];
    $pick    = static fn(string $key, array $allowed, string $def): string =>
        in_array($_POST[$key] ?? '', $allowed, true) ? $_POST[$key] : $def;

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        Session::addMessageAfterRedirect(__('O nome é obrigatório.', 'gamification'), true, ERROR);
        Html::redirect($back . ($id > 0 ? '?edit=' . $id : ''));
        exit;
    }

    $data = [
        'name'        => $name,
        'description' => trim((string) ($_POST['description'] ?? '')),
        'icon'        => trim((string) ($_POST['icon'] ?? '')) ?: 'ti ti-target',
        'metric'      => $pick('metric', $metrics, 'ticket_resolved'),
        'target'      => max(1, (int) ($_POST['target'] ?? 1)),
        'xp_reward'   => max(0, (int) ($_POST['xp_reward'] ?? 0)),
        'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
    ];

    if ($id > 0) {
        $DB->update(Quest::$table, $data, ['id' => $id]);
    } else {
        $DB->insert(Quest::$table, $data);
    }
    Session::addMessageAfterRedirect(__('Missão salva', 'gamification'), true, INFO);
    Html::redirect($back);
}

Html::redirect($back);
