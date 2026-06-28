<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\BattlePass;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

// CSRF validated/consumed by GLPI 11 kernel before this legacy script runs.

global $DB;
$back = \Plugin::getWebDir('gamification') . '/front/managebattlepass.php';
$id   = (int) ($_POST['id'] ?? 0);

if (isset($_POST['seed'])) {
    $seasons_id = (int) ($_POST['seasons_id'] ?? 0);
    if ($seasons_id > 0) {
        // Force-seed: delete existing tiers first so defaults are applied cleanly.
        if (isset($_POST['force'])) {
            $DB->delete(BattlePass::$table, ['seasons_id' => $seasons_id]);
        }
        BattlePass::seedForSeason($seasons_id);
        Session::addMessageAfterRedirect(__('Tiers padrão criados.', 'gamification'), true, INFO);
    }
    Html::redirect($back);
}

if (isset($_POST['delete']) && $id > 0) {
    $DB->delete(BattlePass::$table, ['id' => $id]);
    Session::addMessageAfterRedirect(__('Tier excluído.', 'gamification'), true, INFO);
    Html::redirect($back);
}

if (isset($_POST['save'])) {
    $reward_types = ['xp_bonus', 'cosmetic', 'badge'];
    $pick = static fn(string $key, array $allowed, string $def): string =>
        in_array($_POST[$key] ?? '', $allowed, true) ? $_POST[$key] : $def;

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        Session::addMessageAfterRedirect(__('O nome é obrigatório.', 'gamification'), true, ERROR);
        Html::redirect($back);
    }

    $data = [
        'name'         => $name,
        'icon'         => trim((string) ($_POST['icon'] ?? '')) ?: 'ti ti-gift',
        'icon_color'   => trim((string) ($_POST['icon_color'] ?? '')) ?: '#FFD700',
        'xp_required'  => max(1, (int) ($_POST['xp_required'] ?? 100)),
        'reward_type'  => $pick('reward_type', $reward_types, 'xp_bonus'),
        'reward_value' => trim((string) ($_POST['reward_value'] ?? '')),
    ];

    if ($id > 0) {
        $DB->update(BattlePass::$table, $data, ['id' => $id]);
        Session::addMessageAfterRedirect(__('Tier atualizado.', 'gamification'), true, INFO);
    }

    Html::redirect($back);
}

Html::redirect($back);
