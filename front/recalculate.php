<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Score;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

$processed = null;

if (isset($_POST['recalculate_all'])) {
    $processed = Score::recalculateAll();
    Session::addMessageAfterRedirect(
        sprintf(__('%d usuário(s) recalculado(s) com sucesso.', 'gamification'), $processed),
        true,
        INFO
    );
    Html::redirect(\Plugin::getWebDir('gamification') . '/front/recalculate.php');
}

Html::header(__('Recalcular Pontuações', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'recalculate');

echo "<div class='container-fluid py-4 gamification-wrapper'>";
echo "<div class='gx-card gx-card-pad' style='max-width:640px;margin:0 auto'>";

echo "<p class='gx-eyebrow mb-3'><i class='ti ti-refresh me-1'></i>" . __('Recalcular Pontuações', 'gamification') . "</p>";

echo "<p>" . __('Use esta ferramenta quando o painel mostrar zeros mesmo existindo dados no banco, ou após importar/inserir transações de XP manualmente.', 'gamification') . "</p>";
echo "<p class='text-muted small'>" . __('O recálculo lê o histórico de transações de XP e reconstrói os agregados de cada usuário (XP total, tickets resolvidos, FCR, SLA, etc.). A operação é idempotente e não cria duplicatas.', 'gamification') . "</p>";

echo "<form method='post' action='' class='mt-4'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<button type='submit' name='recalculate_all' class='btn btn-primary'>";
echo "<i class='ti ti-refresh me-2'></i>" . __('Recalcular todos os usuários', 'gamification');
echo "</button>";
echo "</form>";

echo "</div></div>";

Html::footer();
