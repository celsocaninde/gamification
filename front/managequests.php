<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Quest;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

global $DB;

$dir     = \Plugin::getWebDir('gamification');
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = $edit_id > 0 ? $DB->request(['FROM' => Quest::$table, 'WHERE' => ['id' => $edit_id]])->current() : null;

// Quest metric = the XP event_type that counts toward the weekly goal.
$metrics = [
    'ticket_resolved'     => __('Ticket resolvido', 'gamification'),
    'ticket_resolved_fcr' => __('Resolvido no 1º contato (FCR)', 'gamification'),
    'sla_met'             => __('SLA cumprido', 'gamification'),
    'satisfaction_max'    => __('Avaliação 5★', 'gamification'),
    'kb_article_created'  => __('Artigo KB criado', 'gamification'),
];

Html::header(__('Gerenciar Missões', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'managequests');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

echo "<div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4'>";
echo "<div><p class='gx-eyebrow mb-1'>" . __('Administração', 'gamification') . "</p>";
echo "<h1 class='h3 m-0'>" . __('Missões semanais', 'gamification') . "</h1></div>";
if ($editing) {
    echo "<a href='{$dir}/front/managequests.php' class='btn btn-outline-secondary'><i class='ti ti-plus me-1'></i>" . __('Nova missão', 'gamification') . "</a>";
}
echo "</div>";

$v = function (string $k, $default = '') use ($editing) {
    return htmlspecialchars((string) ($editing[$k] ?? $default));
};

echo "<div class='gx-card gx-card-pad mb-4'>";
echo "<p class='gx-eyebrow mb-3'><i class='ti ti-edit me-1'></i>" . ($editing ? __('Editar missão', 'gamification') : __('Nova missão', 'gamification')) . "</p>";
echo "<form method='post' action='{$dir}/front/quest.form.php'>";
echo Html::hidden('id', ['value' => (int) ($editing['id'] ?? 0)]);
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

echo "<div class='row g-3'>";
echo "<div class='col-md-6'><label class='form-label'>" . __('Nome', 'gamification') . "</label><input type='text' name='name' required class='form-control' value='" . $v('name') . "'></div>";
echo "<div class='col-md-6'><label class='form-label'>" . __('Descrição', 'gamification') . "</label><input type='text' name='description' class='form-control' value='" . $v('description') . "'></div>";

echo "<div class='col-md-5'><label class='form-label'>" . __('Métrica', 'gamification') . "</label><select name='metric' class='form-select'>";
$cur_metric = (string) ($editing['metric'] ?? 'ticket_resolved');
foreach ($metrics as $val => $lbl) {
    $s = ($val === $cur_metric) ? 'selected' : '';
    echo "<option value='" . htmlspecialchars($val) . "' {$s}>" . htmlspecialchars($lbl) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Meta', 'gamification') . "</label><input type='number' name='target' min='1' class='form-control' value='" . (int) ($editing['target'] ?? 1) . "'></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('XP recompensa', 'gamification') . "</label><input type='number' name='xp_reward' min='0' class='form-control' value='" . (int) ($editing['xp_reward'] ?? 0) . "'></div>";
echo "<div class='col-md-3'><label class='form-label'>" . __('Ativa', 'gamification') . "</label><div class='pt-1'>";
echo "<input type='hidden' name='is_active' value='0'>";
$chk = (!isset($editing['is_active']) || $editing['is_active']) ? 'checked' : '';
echo "<label class='gx-switch gx-right-row--admin'><input type='checkbox' name='is_active' value='1' {$chk}><span class='gx-switch-track'></span></label>";
echo "</div></div>";
echo "<div class='col-md-6'><label class='form-label'>" . __('Ícone (classe Tabler)', 'gamification') . "</label><input type='text' name='icon' class='form-control' placeholder='ti ti-target' value='" . $v('icon', 'ti ti-target') . "'></div>";
echo "</div>";

echo "<div class='gx-rights-foot'>";
if ($editing) {
    echo "<button type='submit' name='delete' value='1' class='btn btn-outline-danger me-auto' onclick=\"return confirm('" . __('Excluir esta missão?', 'gamification') . "')\"><i class='ti ti-trash me-1'></i>" . __('Excluir', 'gamification') . "</button>";
}
echo "<button type='submit' name='save' value='1' class='btn btn-primary px-4'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'gamification') . "</button>";
echo "</div>";
echo "</form>";
echo "</div>";

// ── List ────────────────────────────────────────────────────────────────────
$all = [];
foreach ($DB->request(['FROM' => Quest::$table, 'ORDER' => 'xp_reward ASC']) as $q) {
    $all[] = $q;
}
echo "<div class='gx-card gx-card-pad'>";
echo "<h2 class='h5 mb-3'><i class='ti ti-list me-2 text-primary'></i>" . sprintf(__('Todas as missões (%d)', 'gamification'), count($all)) . "</h2>";
echo "<div class='table-responsive'><table class='gx-board'>";
echo "<thead><tr>";
echo "<th>" . __('Missão', 'gamification') . "</th>";
echo "<th>" . __('Métrica', 'gamification') . "</th>";
echo "<th class='text-center'>" . __('Meta', 'gamification') . "</th>";
echo "<th class='text-center'>" . __('XP', 'gamification') . "</th>";
echo "<th class='text-center'>" . __('Ativa', 'gamification') . "</th>";
echo "<th></th>";
echo "</tr></thead><tbody>";
foreach ($all as $q) {
    echo "<tr>";
    echo "<td><div class='d-flex align-items-center gap-2'><i class='" . htmlspecialchars($q['icon']) . "' style='font-size:1.2rem'></i><span class='fw-bold'>" . htmlspecialchars($q['name']) . "</span></div></td>";
    echo "<td class='small text-muted'>" . htmlspecialchars($metrics[$q['metric']] ?? $q['metric']) . "</td>";
    echo "<td class='text-center'>" . (int) $q['target'] . "</td>";
    echo "<td class='text-center'><span class='gx-num text-success'>" . (int) $q['xp_reward'] . "</span></td>";
    echo "<td class='text-center'>" . ($q['is_active'] ? "<span class='gx-pill gx-pill--on'>" . __('Sim', 'gamification') . "</span>" : "<span class='gx-pill gx-pill--off'>" . __('Não', 'gamification') . "</span>") . "</td>";
    echo "<td class='text-end'><a href='{$dir}/front/managequests.php?edit=" . (int) $q['id'] . "' class='btn btn-sm btn-outline-primary'><i class='ti ti-edit'></i></a></td>";
    echo "</tr>";
}
echo "</tbody></table></div>";
echo "</div>";

echo "</div>";
Html::footer();
