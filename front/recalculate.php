<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Score;
use GlpiPlugin\Gamification\XPTransaction;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', READ);

$message = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = Score::recalculateAll();
    $message = sprintf(
        __('Contadores sincronizados para %d usuário(s) a partir do log de transações XP.', 'gamification'),
        $count
    );
}

Html::header(
    __('Sincronizar Contadores', 'gamification'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    Menu::class,
    'recalculate'
);

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// ── Page title ──────────────────────────────────────────────────────────────
echo "<div class='gx-card gx-card-pad mb-4' style='max-width:680px'>";
echo "<h2 class='h5 mb-1'><i class='ti ti-refresh me-2 text-primary'></i>" . __('Sincronizar Contadores de Estatísticas', 'gamification') . "</h2>";
echo "<p class='text-muted mb-3'>" . __('Recalcula os contadores dos tiles do dashboard (Tickets resolvidos, FCR, SLA, etc.) diretamente a partir do log de transações XP. Seguro para executar a qualquer momento — é idempotente.', 'gamification') . "</p>";

if ($message) {
    echo "<div class='alert alert-success'><i class='ti ti-circle-check me-1'></i>" . htmlspecialchars($message) . "</div>";
}

echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<button type='submit' class='btn btn-primary'>";
echo "<i class='ti ti-refresh me-1'></i> " . __('Recalcular Agora', 'gamification');
echo "</button>";
echo "</form>";
echo "</div>";

// ── Current scores table ─────────────────────────────────────────────────────
global $DB;
$rows = $DB->request([
    'SELECT' => [
        'glpi_plugin_gamification_scores.*',
        'glpi_users.firstname',
        'glpi_users.realname',
        'glpi_users.name AS login',
    ],
    'FROM'       => Score::$table,
    'LEFT JOIN'  => [
        'glpi_users' => [
            'ON' => [
                'glpi_users'                      => 'id',
                'glpi_plugin_gamification_scores' => 'users_id',
            ]
        ]
    ],
    'WHERE'  => ['glpi_users.is_deleted' => 0],
    'ORDER'  => 'xp_total DESC',
    'LIMIT'  => 50,
]);

$all = [];
foreach ($rows as $r) {
    $all[] = $r;
}

if (!empty($all)) {
    echo "<div class='gx-card gx-card-pad'>";
    echo "<h3 class='h6 mb-3'><i class='ti ti-table me-2'></i>" . __('Pontuações Atuais', 'gamification') . "</h3>";
    echo "<div class='table-responsive'>";
    echo "<table class='table table-sm table-striped'>";
    echo "<thead><tr>";
    echo "<th>" . __('Usuário', 'gamification') . "</th>";
    echo "<th class='text-end'>XP Total</th>";
    echo "<th class='text-end'>Nível</th>";
    echo "<th class='text-end'>Resolvidos</th>";
    echo "<th class='text-end'>FCR</th>";
    echo "<th class='text-end'>SLA</th>";
    echo "<th class='text-end'>Sat. 5★</th>";
    echo "<th class='text-end'>KB</th>";
    echo "<th class='text-end'>Seq.</th>";
    echo "</tr></thead><tbody>";
    foreach ($all as $r) {
        $name = trim($r['firstname'] . ' ' . $r['realname']) ?: $r['login'];
        echo "<tr>";
        echo "<td>" . htmlspecialchars($name) . "</td>";
        echo "<td class='text-end fw-bold'>" . number_format((int)$r['xp_total'], 0, ',', '.') . "</td>";
        echo "<td class='text-end'>" . (int)$r['level'] . "</td>";
        echo "<td class='text-end'>" . (int)$r['tickets_resolved'] . "</td>";
        echo "<td class='text-end'>" . (int)$r['fcr_count'] . "</td>";
        echo "<td class='text-end'>" . (int)$r['sla_met_count'] . "</td>";
        echo "<td class='text-end'>" . (int)$r['perfect_satisfaction'] . "</td>";
        echo "<td class='text-end'>" . (int)$r['kb_articles'] . "</td>";
        echo "<td class='text-end'>" . (int)$r['current_streak'] . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table></div>";
    echo "</div>";
}

echo "</div>"; // wrapper

Html::footer();
