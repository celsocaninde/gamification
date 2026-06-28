<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\BattlePass;
use GlpiPlugin\Gamification\Season;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

$dir    = \Plugin::getWebDir('gamification');
$season = Season::getActiveSeason();

$reward_types = [
    'xp_bonus' => __('Bônus de XP', 'gamification'),
    'cosmetic'  => __('Título cosmético', 'gamification'),
    'badge'     => __('Conquista (por nome)', 'gamification'),
];

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = null;
if ($edit_id > 0) {
    global $DB;
    $editing = $DB->request(['FROM' => BattlePass::$table, 'WHERE' => ['id' => $edit_id]])->current();
}

Html::header(__('Battle Pass — Admin', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'managebattlepass');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

echo "<div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4'>";
echo "<div><p class='gx-eyebrow mb-1'>" . __('Administração', 'gamification') . "</p>";
echo "<h1 class='h3 m-0'>Battle Pass</h1></div>";
if ($season) {
    echo "<span class='badge bg-success fs-6'>" . htmlspecialchars($season['name']) . "</span>";
}
echo "</div>";

if (!$season) {
    echo "<div class='alert alert-warning'>" . __('Nenhuma temporada ativa. Crie uma temporada primeiro.', 'gamification') . "</div>";
    echo "</div>";
    Html::footer();
    exit;
}

$seasons_id = (int) $season['id'];
$tiers      = BattlePass::getTiersForSeason($seasons_id);

// ── Seed button (if no tiers yet or force-reset) ───────────────────────────
echo "<div class='gx-card gx-card-pad mb-4 d-flex align-items-center gap-3 flex-wrap'>";
if (empty($tiers)) {
    echo "<p class='m-0 text-muted flex-grow-1'>" . __('Nenhum tier configurado para esta temporada.', 'gamification') . "</p>";
    echo "<form method='post' action='{$dir}/front/battlepasstier.form.php'>";
    echo \Html::hidden('seasons_id', ['value' => $seasons_id]);
    echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
    echo "<button type='submit' name='seed' value='1' class='btn btn-primary'><i class='ti ti-wand me-1'></i>" . __('Criar tiers padrão', 'gamification') . "</button>";
    echo "</form>";
} else {
    echo "<p class='m-0 text-muted flex-grow-1'>" . sprintf(__('%d tiers configurados para a temporada atual.', 'gamification'), count($tiers)) . "</p>";
    echo "<form method='post' action='{$dir}/front/battlepasstier.form.php' onsubmit=\"return confirm('" . __('Isso substitui todos os tiers atuais pelos padrão. Continuar?', 'gamification') . "')\">";
    echo \Html::hidden('seasons_id', ['value' => $seasons_id]);
    echo \Html::hidden('force', ['value' => '1']);
    echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
    echo "<button type='submit' name='seed' value='1' class='btn btn-outline-secondary btn-sm'><i class='ti ti-refresh me-1'></i>" . __('Restaurar padrões', 'gamification') . "</button>";
    echo "</form>";
}
echo "</div>";

// ── Edit form ──────────────────────────────────────────────────────────────
if ($editing) {
    $v = static fn(string $k, $d = '') => htmlspecialchars((string) ($editing[$k] ?? $d));
    echo "<div class='gx-card gx-card-pad mb-4'>";
    echo "<p class='gx-eyebrow mb-3'><i class='ti ti-edit me-1'></i>" . sprintf(__('Editar Tier %d', 'gamification'), (int) ($editing['tier_num'] ?? 0)) . "</p>";
    echo "<form method='post' action='{$dir}/front/battlepasstier.form.php'>";
    echo \Html::hidden('id', ['value' => $edit_id]);
    echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
    echo "<div class='row g-3'>";
    echo "<div class='col-md-4'><label class='form-label'>" . __('Nome', 'gamification') . "</label><input type='text' name='name' required class='form-control' value='" . $v('name') . "'></div>";
    echo "<div class='col-md-4'><label class='form-label'>" . __('XP necessário', 'gamification') . "</label><input type='number' name='xp_required' min='1' class='form-control' value='" . (int) ($editing['xp_required'] ?? 0) . "'></div>";
    // Reward type selector
    $rt_html = "<select name='reward_type' class='form-select'>";
    foreach ($reward_types as $val => $lbl) {
        $s = ($val === ($editing['reward_type'] ?? '')) ? 'selected' : '';
        $rt_html .= "<option value='" . htmlspecialchars($val) . "' {$s}>" . htmlspecialchars($lbl) . "</option>";
    }
    $rt_html .= "</select>";
    echo "<div class='col-md-4'><label class='form-label'>" . __('Tipo de recompensa', 'gamification') . "</label>{$rt_html}</div>";
    echo "<div class='col-md-4'><label class='form-label'>" . __('Valor da recompensa', 'gamification') . "</label>";
    echo "<input type='text' name='reward_value' class='form-control' placeholder='ex: 100 (para XP) ou Especialista (para título)' value='" . $v('reward_value') . "'></div>";
    echo "<div class='col-md-4'><label class='form-label'>" . __('Ícone (classe Tabler)', 'gamification') . "</label><input type='text' name='icon' class='form-control' placeholder='ti ti-gift' value='" . $v('icon', 'ti ti-gift') . "'></div>";
    echo "<div class='col-md-2'><label class='form-label'>" . __('Cor do ícone', 'gamification') . "</label><input type='color' name='icon_color' class='form-control form-control-color' value='" . $v('icon_color', '#FFD700') . "'></div>";
    echo "</div>";
    echo "<div class='gx-rights-foot mt-3'>";
    echo "<button type='submit' name='delete' value='1' class='btn btn-outline-danger me-auto' onclick=\"return confirm('" . __('Excluir este tier?', 'gamification') . "')\"><i class='ti ti-trash me-1'></i>" . __('Excluir', 'gamification') . "</button>";
    echo "<a href='{$dir}/front/managebattlepass.php' class='btn btn-outline-secondary me-2'>" . __('Cancelar', 'gamification') . "</a>";
    echo "<button type='submit' name='save' value='1' class='btn btn-primary px-4'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar', 'gamification') . "</button>";
    echo "</div>";
    echo "</form></div>";
}

// ── Tier table ─────────────────────────────────────────────────────────────
if (!empty($tiers)) {
    echo "<div class='gx-card gx-card-pad'>";
    echo "<h2 class='h5 mb-3'><i class='ti ti-list me-2 text-primary'></i>" . __('Tiers da temporada', 'gamification') . "</h2>";
    echo "<div class='table-responsive'><table class='gx-board'>";
    echo "<thead><tr>";
    echo "<th class='text-center'>" . __('Tier', 'gamification') . "</th>";
    echo "<th>" . __('Nome', 'gamification') . "</th>";
    echo "<th class='text-end'>" . __('XP necessário', 'gamification') . "</th>";
    echo "<th>" . __('Recompensa', 'gamification') . "</th>";
    echo "<th></th>";
    echo "</tr></thead><tbody>";
    foreach ($tiers as $t) {
        $reward_disp = match ($t['reward_type']) {
            'xp_bonus' => '<span class="text-success fw-bold">+' . (int) $t['reward_value'] . ' XP</span>',
            'cosmetic'  => '<span class="text-warning">🏆 ' . htmlspecialchars((string) $t['reward_value']) . '</span>',
            'badge'     => '<span class="text-primary">🎖 ' . htmlspecialchars((string) $t['reward_value']) . '</span>',
            default     => htmlspecialchars((string) $t['reward_value']),
        };
        echo "<tr>";
        echo "<td class='text-center fw-bold'>" . (int) $t['tier_num'] . "</td>";
        echo "<td><div class='d-flex align-items-center gap-2'>";
        echo "<i class='" . htmlspecialchars($t['icon']) . "' style='font-size:1.2rem;color:" . htmlspecialchars($t['icon_color']) . "'></i>";
        echo "<span>" . htmlspecialchars($t['name']) . "</span></div></td>";
        echo "<td class='text-end'>" . number_format((int) $t['xp_required'], 0, ',', '.') . " XP</td>";
        echo "<td>{$reward_disp}</td>";
        echo "<td class='text-end'><a href='{$dir}/front/managebattlepass.php?edit=" . (int) $t['id'] . "' class='btn btn-sm btn-outline-primary'><i class='ti ti-edit'></i></a></td>";
        echo "</tr>";
    }
    echo "</tbody></table></div></div>";
}

echo "</div>"; // wrapper
Html::footer();
