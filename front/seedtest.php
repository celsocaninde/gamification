<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Score;
use GlpiPlugin\Gamification\XPTransaction;
use GlpiPlugin\Gamification\Rule;
use GlpiPlugin\Gamification\Leaderboard;
use GlpiPlugin\Gamification\Season;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', READ);

const TEST_SOURCE_TYPE = 'GamificationTest';

$message   = '';
$msg_type  = 'success';
$seeded    = [];

// ── Active GLPI users (technicians / admins) ─────────────────────────────────
function gx_get_active_users(): array
{
    global $DB;
    $iter = $DB->request([
        'SELECT'   => ['id', 'firstname', 'realname', 'name'],
        'FROM'     => 'glpi_users',
        'WHERE'    => ['is_deleted' => 0, 'is_active' => 1],
        'ORDER'    => 'id ASC',
        'LIMIT'    => 10,
    ]);
    $users = [];
    foreach ($iter as $r) {
        $users[] = $r;
    }
    return $users;
}

// ── Seed one user's data ──────────────────────────────────────────────────────
function gx_seed_user(array $u): array
{
    global $DB;
    $uid      = (int)$u['id'];
    $eid      = 0;
    $now      = date('Y-m-d H:i:s');

    // Deterministic but varied dataset per user (based on user ID)
    $resolved = 8  + ($uid % 17);   // 8–24 resolved tickets
    $fcr      = max(1, (int)round($resolved * (0.30 + ($uid % 3) * 0.10))); // 30–50 % FCR
    $sla      = max(1, (int)round($resolved * (0.55 + ($uid % 4) * 0.08))); // 55–79 % SLA
    $sat5     = 1  + ($uid % 5);    // 1–5 perfect ratings
    $kb       = 1  + ($uid % 3);    // 1–3 KB articles

    // XP values from active rules (fall back to defaults)
    $rules_xp = [];
    foreach (['ticket_resolved', 'ticket_resolved_fcr', 'sla_met', 'satisfaction_max', 'kb_article_created'] as $et) {
        $rule = Rule::getRuleForEvent($et);
        $rules_xp[$et] = $rule ? (int)$rule['xp_value'] : 10;
    }

    $events = [];

    // ticket_resolved (and optionally sla_met + ticket_resolved_fcr for each ticket)
    for ($i = 1; $i <= $resolved; $i++) {
        $fakeId = 90000 + ($uid * 100) + $i;

        // Only insert if not already seeded
        $exists = countElementsInTable(XPTransaction::$table, [
            'users_id'        => $uid,
            'event_type'      => 'ticket_resolved',
            'source_itemtype' => TEST_SOURCE_TYPE,
            'source_items_id' => $fakeId,
        ]);
        if (!$exists) {
            Score::addXP($uid, $rules_xp['ticket_resolved'], 'ticket_resolved', TEST_SOURCE_TYPE, $fakeId,
                sprintf('Teste: ticket %d resolvido', $fakeId), $eid);
            $events[] = 'ticket_resolved';
        }

        if ($i <= $sla) {
            $exists = countElementsInTable(XPTransaction::$table, [
                'users_id'        => $uid,
                'event_type'      => 'sla_met',
                'source_itemtype' => TEST_SOURCE_TYPE,
                'source_items_id' => $fakeId,
            ]);
            if (!$exists) {
                Score::addXP($uid, $rules_xp['sla_met'], 'sla_met', TEST_SOURCE_TYPE, $fakeId,
                    sprintf('Teste: SLA cumprido no ticket %d', $fakeId), $eid);
                $events[] = 'sla_met';
            }
        }

        if ($i <= $fcr) {
            $exists = countElementsInTable(XPTransaction::$table, [
                'users_id'        => $uid,
                'event_type'      => 'ticket_resolved_fcr',
                'source_itemtype' => TEST_SOURCE_TYPE,
                'source_items_id' => $fakeId,
            ]);
            if (!$exists) {
                Score::addXP($uid, $rules_xp['ticket_resolved_fcr'], 'ticket_resolved_fcr', TEST_SOURCE_TYPE, $fakeId,
                    sprintf('Teste: FCR no ticket %d', $fakeId), $eid);
                $events[] = 'ticket_resolved_fcr';
            }
        }
    }

    // satisfaction_max
    for ($i = 1; $i <= $sat5; $i++) {
        $fakeId = 89000 + ($uid * 10) + $i;
        $exists = countElementsInTable(XPTransaction::$table, [
            'users_id'        => $uid,
            'event_type'      => 'satisfaction_max',
            'source_itemtype' => TEST_SOURCE_TYPE,
            'source_items_id' => $fakeId,
        ]);
        if (!$exists) {
            Score::addXP($uid, $rules_xp['satisfaction_max'], 'satisfaction_max', TEST_SOURCE_TYPE, $fakeId,
                sprintf('Teste: satisfação 5★ no ticket %d', $fakeId), $eid);
            $events[] = 'satisfaction_max';
        }
    }

    // kb_article_created
    for ($i = 1; $i <= $kb; $i++) {
        $fakeId = 88000 + ($uid * 10) + $i;
        $exists = countElementsInTable(XPTransaction::$table, [
            'users_id'        => $uid,
            'event_type'      => 'kb_article_created',
            'source_itemtype' => TEST_SOURCE_TYPE,
            'source_items_id' => $fakeId,
        ]);
        if (!$exists) {
            Score::addXP($uid, $rules_xp['kb_article_created'], 'kb_article_created', TEST_SOURCE_TYPE, $fakeId,
                sprintf('Teste: artigo KB %d criado', $fakeId), $eid);
            $events[] = 'kb_article_created';
        }
    }

    // Sync counter columns from the transaction log
    Score::recalculate($uid, $eid);

    return [
        'user'     => trim($u['firstname'] . ' ' . $u['realname']) ?: $u['name'],
        'events'   => count($events),
        'score'    => Score::getOrCreate($uid, $eid),
    ];
}

// ── Clear test data ───────────────────────────────────────────────────────────
function gx_clear_test_data(): int
{
    global $DB;
    $DB->delete(XPTransaction::$table, ['source_itemtype' => TEST_SOURCE_TYPE]);
    $affected = $DB->affectedRows();
    Score::recalculateAll();
    return $affected;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'seed') {
        $users = gx_get_active_users();
        foreach ($users as $u) {
            $seeded[] = gx_seed_user($u);
        }
        $message = sprintf(
            __('Dados de teste criados para %d usuário(s). Contadores sincronizados.', 'gamification'),
            count($seeded)
        );
    } elseif ($action === 'clear') {
        $deleted = gx_clear_test_data();
        $message = sprintf(__('%d transação(ões) de teste removida(s). Contadores recalculados.', 'gamification'), $deleted);
        $msg_type = 'warning';
    }
}

// ── Render ────────────────────────────────────────────────────────────────────
Html::header(
    __('Dados de Teste', 'gamification'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    Menu::class,
    'seedtest'
);

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// Card: actions
echo "<div class='gx-card gx-card-pad mb-4' style='max-width:720px'>";
echo "<h2 class='h5 mb-1'><i class='ti ti-flask me-2 text-warning'></i>" . __('Gerador de Dados de Teste', 'gamification') . "</h2>";
echo "<p class='text-muted mb-3'>" . __('Cria transações XP fictícias para todos os usuários ativos a fim de verificar se os contadores, ranking e badges funcionam corretamente. Os dados são marcados como "GamificationTest" e podem ser removidos a qualquer momento.', 'gamification') . "</p>";

if ($message) {
    echo "<div class='alert alert-{$msg_type} mb-3'><i class='ti ti-" . ($msg_type === 'warning' ? 'trash' : 'circle-check') . " me-1'></i>" . htmlspecialchars($message) . "</div>";
}

echo "<div class='d-flex gap-2'>";

// Seed button
echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('action', ['value' => 'seed']);
echo "<button type='submit' class='btn btn-primary'>";
echo "<i class='ti ti-seed me-1'></i> " . __('Criar Dados de Teste', 'gamification');
echo "</button>";
echo "</form>";

// Clear button
echo "<form method='post' action='" . htmlspecialchars($_SERVER['PHP_SELF']) . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('action', ['value' => 'clear']);
echo "<button type='submit' class='btn btn-outline-danger'>";
echo "<i class='ti ti-trash me-1'></i> " . __('Limpar Dados de Teste', 'gamification');
echo "</button>";
echo "</form>";

echo "</div>";
echo "</div>";

// After seed: show per-user summary
if (!empty($seeded)) {
    echo "<div class='gx-card gx-card-pad mb-4'>";
    echo "<h3 class='h6 mb-3'><i class='ti ti-check me-2 text-success'></i>" . __('Resultado do Seeding', 'gamification') . "</h3>";
    echo "<div class='table-responsive'>";
    echo "<table class='table table-sm table-striped'>";
    echo "<thead><tr>";
    echo "<th>" . __('Usuário', 'gamification') . "</th>";
    echo "<th class='text-end'>Eventos novos</th>";
    echo "<th class='text-end'>XP Total</th>";
    echo "<th class='text-end'>Nível</th>";
    echo "<th class='text-end'>Resolvidos</th>";
    echo "<th class='text-end'>FCR</th>";
    echo "<th class='text-end'>SLA</th>";
    echo "<th class='text-end'>Sat. 5★</th>";
    echo "<th class='text-end'>KB</th>";
    echo "</tr></thead><tbody>";
    foreach ($seeded as $row) {
        $s = $row['score'];
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['user']) . "</td>";
        echo "<td class='text-end'>" . (int)$row['events'] . "</td>";
        echo "<td class='text-end fw-bold'>" . number_format((int)$s['xp_total'], 0, ',', '.') . "</td>";
        echo "<td class='text-end'>" . (int)$s['level'] . "</td>";
        echo "<td class='text-end'>" . (int)$s['tickets_resolved'] . "</td>";
        echo "<td class='text-end'>" . (int)$s['fcr_count'] . "</td>";
        echo "<td class='text-end'>" . (int)$s['sla_met_count'] . "</td>";
        echo "<td class='text-end'>" . (int)$s['perfect_satisfaction'] . "</td>";
        echo "<td class='text-end'>" . (int)$s['kb_articles'] . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table></div>";
    echo "</div>";
}

// Current ranking snapshot
global $DB;
$top = Score::getTopUsers(15);
if (!empty($top)) {
    echo "<div class='gx-card gx-card-pad'>";
    echo "<h3 class='h6 mb-3'><i class='ti ti-trophy me-2 text-warning'></i>" . __('Ranking Atual (Top 15)', 'gamification') . "</h3>";
    echo "<div class='table-responsive'>";
    echo "<table class='table table-sm table-striped'>";
    echo "<thead><tr>";
    echo "<th>#</th><th>" . __('Usuário', 'gamification') . "</th>";
    echo "<th class='text-end'>XP Temporada</th><th class='text-end'>Nível</th>";
    echo "<th class='text-end'>Resolvidos</th><th class='text-end'>FCR</th><th class='text-end'>SLA</th>";
    echo "</tr></thead><tbody>";
    $pos = 1;
    foreach ($top as $tu) {
        echo "<tr>";
        echo "<td><strong>#{$pos}</strong></td>";
        echo "<td>" . htmlspecialchars(getUserName($tu['users_id'])) . "</td>";
        echo "<td class='text-end fw-bold text-success'>" . number_format((int)$tu['xp_season'], 0, ',', '.') . "</td>";
        echo "<td class='text-end'>" . (int)$tu['level'] . "</td>";
        echo "<td class='text-end'>" . (int)$tu['tickets_resolved'] . "</td>";
        echo "<td class='text-end'>" . (int)$tu['fcr_count'] . "</td>";
        echo "<td class='text-end'>" . (int)$tu['sla_met_count'] . "</td>";
        echo "</tr>";
        $pos++;
    }
    echo "</tbody></table></div>";
    echo "</div>";
}

echo "</div>"; // wrapper

Html::footer();
