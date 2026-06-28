<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Badge;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

// CSRF validated/consumed by GLPI 11 kernel before this legacy script runs.

global $DB;
$back = \Plugin::getWebDir('gamification') . '/front/managebadges.php';
$id   = (int) ($_POST['id'] ?? 0);

if (isset($_POST['delete']) && $id > 0) {
    $DB->delete(Badge::$table, ['id' => $id]);
    $DB->delete('glpi_plugin_gamification_badgeusers', ['badges_id' => $id]);
    Session::addMessageAfterRedirect(__('Conquista excluída', 'gamification'), true, INFO);
    Html::redirect($back);
}

if (isset($_POST['save'])) {
    // Whitelists keep enum columns sane.
    $cats      = ['general', 'speed', 'quality', 'knowledge', 'dedication', 'milestone'];
    $rarities  = ['common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic'];
    $criteria  = ['tickets_resolved_total', 'fcr_total', 'perfect_satisfaction_total', 'kb_articles_total', 'no_reopen_streak', 'high_priority_sla_weekly', 'after_hours_top_monthly'];
    $periods   = ['all_time', 'rolling', 'week', 'month'];
    $pick      = static fn(string $key, array $allowed, string $def): string => in_array($_POST[$key] ?? '', $allowed, true) ? $_POST[$key] : $def;

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        Session::addMessageAfterRedirect(__('O nome é obrigatório.', 'gamification'), true, ERROR);
        Html::redirect($back . ($id > 0 ? '?edit=' . $id : ''));
    }

    $data = [
        'name'            => $name,
        'description'     => trim((string) ($_POST['description'] ?? '')),
        'icon'            => trim((string) ($_POST['icon'] ?? '')) ?: 'ti ti-trophy',
        'icon_color'      => trim((string) ($_POST['icon_color'] ?? '')) ?: '#FFD700',
        'category'        => $pick('category', $cats, 'milestone'),
        'rarity'          => $pick('rarity', $rarities, 'common'),
        'criteria_type'   => $pick('criteria_type', $criteria, 'tickets_resolved_total'),
        'criteria_value'  => max(1, (int) ($_POST['criteria_value'] ?? 1)),
        'criteria_period' => $pick('criteria_period', $periods, 'all_time'),
        'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
    ];

    if ($id > 0) {
        $DB->update(Badge::$table, $data, ['id' => $id]);
    } else {
        $DB->insert(Badge::$table, $data);
    }
    Session::addMessageAfterRedirect(__('Conquista salva', 'gamification'), true, INFO);
    Html::redirect($back);
}

Html::redirect($back);
