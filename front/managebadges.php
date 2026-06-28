<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Badge;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

global $DB;

$dir      = \Plugin::getWebDir('gamification');
$edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing  = $edit_id > 0 ? $DB->request(['FROM' => Badge::$table, 'WHERE' => ['id' => $edit_id]])->current() : null;

$categories = [
    'general'    => __('Geral', 'gamification'),
    'speed'      => __('Velocidade', 'gamification'),
    'quality'    => __('Qualidade', 'gamification'),
    'knowledge'  => __('Conhecimento', 'gamification'),
    'dedication' => __('Dedicação', 'gamification'),
    'milestone'  => __('Marco', 'gamification'),
];
$rarities = [
    'common'    => __('Comum', 'gamification'),
    'uncommon'  => __('Incomum', 'gamification'),
    'rare'      => __('Rara', 'gamification'),
    'epic'      => __('Épica', 'gamification'),
    'legendary' => __('Lendária', 'gamification'),
    'mythic'    => __('Mítica', 'gamification'),
];
$criteria_types = [
    'tickets_resolved_total'     => __('Tickets resolvidos (total)', 'gamification'),
    'fcr_total'                  => __('FCR / 1º contato (total)', 'gamification'),
    'perfect_satisfaction_total' => __('Satisfação 5★ (total)', 'gamification'),
    'kb_articles_total'          => __('Artigos KB (total)', 'gamification'),
    'no_reopen_streak'           => __('Sequência sem reabertura', 'gamification'),
    'high_priority_sla_weekly'   => __('SLA cumprido (total)', 'gamification'),
    'after_hours_top_monthly'    => __('Top fora de horário (não automático)', 'gamification'),
];
$periods = [
    'all_time' => __('Sempre', 'gamification'),
    'rolling'  => __('Sequência', 'gamification'),
    'week'     => __('Semana', 'gamification'),
    'month'    => __('Mês', 'gamification'),
];

Html::header(__('Gerenciar Conquistas', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'managebadges');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

echo "<div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4'>";
echo "<div><p class='gx-eyebrow mb-1'>" . __('Administração', 'gamification') . "</p>";
echo "<h1 class='h3 m-0'>" . __('Conquistas', 'gamification') . "</h1></div>";
if ($editing) {
    echo "<a href='{$dir}/front/managebadges.php' class='btn btn-outline-secondary'><i class='ti ti-plus me-1'></i>" . __('Nova conquista', 'gamification') . "</a>";
}
echo "</div>";

// ── Form (create / edit) ────────────────────────────────────────────────────
$v = function (string $k, $default = '') use ($editing) {
    return htmlspecialchars((string) ($editing[$k] ?? $default));
};
$sel = function (string $name, array $map, string $current): string {
    $h = "<select name='{$name}' class='form-select'>";
    foreach ($map as $val => $lbl) {
        $s = ($val === $current) ? 'selected' : '';
        $h .= "<option value='" . htmlspecialchars($val) . "' {$s}>" . htmlspecialchars($lbl) . "</option>";
    }
    return $h . "</select>";
};

echo "<div class='gx-card gx-card-pad mb-4'>";
echo "<p class='gx-eyebrow mb-3'><i class='ti ti-edit me-1'></i>" . ($editing ? __('Editar conquista', 'gamification') : __('Nova conquista', 'gamification')) . "</p>";
echo "<form method='post' action='{$dir}/front/badge.form.php'>";
echo Html::hidden('id', ['value' => (int) ($editing['id'] ?? 0)]);
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='row g-3'>";
echo "<div class='col-md-6'><label class='form-label'>" . __('Nome', 'gamification') . "</label><input type='text' name='name' required class='form-control' value='" . $v('name') . "'></div>";
echo "<div class='col-md-6'><label class='form-label'>" . __('Descrição', 'gamification') . "</label><input type='text' name='description' class='form-control' value='" . $v('description') . "'></div>";
echo "<div class='col-md-4'><label class='form-label'>" . __('Categoria', 'gamification') . "</label>" . $sel('category', $categories, (string) ($editing['category'] ?? 'milestone')) . "</div>";
echo "<div class='col-md-4'><label class='form-label'>" . __('Raridade', 'gamification') . "</label>" . $sel('rarity', $rarities, (string) ($editing['rarity'] ?? 'common')) . "</div>";
echo "<div class='col-md-4'><label class='form-label'>" . __('Período', 'gamification') . "</label>" . $sel('criteria_period', $periods, (string) ($editing['criteria_period'] ?? 'all_time')) . "</div>";
echo "<div class='col-md-6'><label class='form-label'>" . __('Critério', 'gamification') . "</label>" . $sel('criteria_type', $criteria_types, (string) ($editing['criteria_type'] ?? 'tickets_resolved_total')) . "</div>";
echo "<div class='col-md-3'><label class='form-label'>" . __('Meta (valor)', 'gamification') . "</label><input type='number' name='criteria_value' min='1' class='form-control' value='" . (int) ($editing['criteria_value'] ?? 1) . "'></div>";
echo "<div class='col-md-3'><label class='form-label'>" . __('Ativa', 'gamification') . "</label><div class='pt-1'>";
echo "<input type='hidden' name='is_active' value='0'>";
$chk = (!isset($editing['is_active']) || $editing['is_active']) ? 'checked' : '';
echo "<label class='gx-switch gx-right-row--admin'><input type='checkbox' name='is_active' value='1' {$chk}><span class='gx-switch-track'></span></label>";
echo "</div></div>";
echo "<div class='col-md-6'><label class='form-label'>" . __('Ícone (classe Tabler)', 'gamification') . "</label><input type='text' name='icon' class='form-control' placeholder='ti ti-trophy' value='" . $v('icon', 'ti ti-trophy') . "'></div>";
echo "<div class='col-md-3'><label class='form-label'>" . __('Cor do ícone', 'gamification') . "</label><input type='color' name='icon_color' class='form-control form-control-color' value='" . $v('icon_color', '#FFD700') . "'></div>";
echo "</div>";

echo "<div class='gx-rights-foot'>";
if ($editing) {
    echo "<button type='submit' name='delete' value='1' class='btn btn-outline-danger me-auto' onclick=\"return confirm('" . __('Excluir esta conquista? Os registros de quem já a recebeu também serão removidos.', 'gamification') . "')\"><i class='ti ti-trash me-1'></i>" . __('Excluir', 'gamification') . "</button>";
}
echo "<button type='submit' name='save' value='1' class='btn btn-primary px-4'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'gamification') . "</button>";
echo "</div>";
echo "</form>";
echo "</div>";

// ── List ────────────────────────────────────────────────────────────────────
$all = Badge::getAll(false);
echo "<div class='gx-card gx-card-pad'>";
echo "<h2 class='h5 mb-3'><i class='ti ti-list me-2 text-primary'></i>" . sprintf(__('Todas as conquistas (%d)', 'gamification'), count($all)) . "</h2>";
echo "<div class='table-responsive'><table class='gx-board'>";
echo "<thead><tr>";
echo "<th>" . __('Conquista', 'gamification') . "</th>";
echo "<th>" . __('Raridade', 'gamification') . "</th>";
echo "<th>" . __('Critério', 'gamification') . "</th>";
echo "<th class='text-center'>" . __('Recebida', 'gamification') . "</th>";
echo "<th class='text-center'>" . __('Ativa', 'gamification') . "</th>";
echo "<th></th>";
echo "</tr></thead><tbody>";
foreach ($all as $b) {
    $earned = countElementsInTable('glpi_plugin_gamification_badgeusers', ['badges_id' => $b['id']]);
    $crit   = ($criteria_types[$b['criteria_type']] ?? $b['criteria_type']) . ' ≥ ' . (int) $b['criteria_value'];
    echo "<tr>";
    echo "<td><div class='d-flex align-items-center gap-2'><i class='" . htmlspecialchars($b['icon']) . "' style='font-size:1.3rem;color:" . htmlspecialchars($b['icon_color']) . "'></i><span class='fw-bold'>" . htmlspecialchars($b['name']) . "</span></div></td>";
    echo "<td><span class='rarity-tag rarity-" . htmlspecialchars(strtolower($b['rarity'])) . "'>" . htmlspecialchars($rarities[$b['rarity']] ?? $b['rarity']) . "</span></td>";
    echo "<td class='small text-muted'>" . htmlspecialchars($crit) . "</td>";
    echo "<td class='text-center'>{$earned}</td>";
    echo "<td class='text-center'>" . ($b['is_active'] ? "<span class='gx-pill gx-pill--on'>" . __('Sim', 'gamification') . "</span>" : "<span class='gx-pill gx-pill--off'>" . __('Não', 'gamification') . "</span>") . "</td>";
    echo "<td class='text-end'><a href='{$dir}/front/managebadges.php?edit=" . (int) $b['id'] . "' class='btn btn-sm btn-outline-primary'><i class='ti ti-edit'></i></a></td>";
    echo "</tr>";
}
echo "</tbody></table></div>";
echo "</div>";

echo "</div>";
Html::footer();
