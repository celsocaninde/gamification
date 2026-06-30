<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Score;
use GlpiPlugin\Gamification\XPTransaction;
use GlpiPlugin\Gamification\Config;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_dashboard', READ);
Menu::checkPanelEnabled();

global $CFG_GLPI;
$root = $CFG_GLPI['root_doc'] ?? '';

$event_meta = [
    'ticket_resolved'      => ['ti-ticket',       'violet', __('Tickets Resolvidos', 'gamification')],
    'ticket_resolved_fcr'  => ['ti-rocket',       'cyan',   __('FCR – Resolução no 1º Contato', 'gamification')],
    'sla_met'              => ['ti-clock-check',  'green',  __('SLA Solução Cumprido', 'gamification')],
    'sla_tto_met'          => ['ti-clock-bolt',   'teal',   __('SLA Atendimento Cumprido', 'gamification')],
    'sla_tto_breached'     => ['ti-clock-x',      'red',    __('SLA Atendimento Estourado', 'gamification')],
    'satisfaction_max'     => ['ti-star-filled',  'gold',   __('Avaliações 5 Estrelas', 'gamification')],
    'satisfaction_good'    => ['ti-star-half-filled', 'amber', __('Avaliações 4 Estrelas', 'gamification')],
    'kb_article_created'   => ['ti-book',         'slate',  __('Artigos na Base de Conhecimento', 'gamification')],
    'ticket_reopened'      => ['ti-rotate',       'ember',  __('Tickets Reabertos (penalidade)', 'gamification')],
    'quest_completed'      => ['ti-checklist',    'cyan',   __('Missões Concluídas', 'gamification')],
    'badge_earned'         => ['ti-medal',        'gold',   __('Conquistas Desbloqueadas', 'gamification')],
    'level_up'             => ['ti-trending-up',  'violet', __('Subidas de Nível', 'gamification')],
];

$valid_events = array_keys($event_meta);
$event = $_GET['event'] ?? '';
if (!in_array($event, $valid_events, true)) {
    Html::displayErrorAndDie(__('Evento inválido.', 'gamification'));
}

$users_id    = Session::getLoginUserID();
$transactions = XPTransaction::getForUserByEvent($users_id, $event);

[$ico, $accent, $title] = $event_meta[$event];
$is_penalty = in_array($event, ['ticket_reopened', 'sla_tto_breached'], true);

Html::header($title, $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'dashboard');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// Back + header
echo "<div class='d-flex align-items-center gap-3 mb-4'>";
echo "<a href='dashboard.php' class='btn btn-outline-secondary btn-sm'><i class='ti ti-arrow-left'></i> " . __('Voltar', 'gamification') . "</a>";
echo "<h1 class='h4 m-0'><i class='ti {$ico} me-2'></i>" . htmlspecialchars($title) . "</h1>";
echo "<span class='badge bg-secondary'>" . count($transactions) . " " . __('registros', 'gamification') . "</span>";
echo "</div>";

if (empty($transactions)) {
    echo "<div class='gx-card gx-card-pad text-center text-muted py-5'>";
    echo "<i class='ti {$ico} fs-1 d-block mb-3' style='opacity:.3'></i>";
    echo "<p>" . __('Nenhum evento registrado ainda.', 'gamification') . "</p>";
    echo "</div>";
} else {
    echo "<div class='gx-card' style='overflow:hidden'>";
    echo "<table class='table table-hover mb-0'>";
    echo "<thead class='table-light'><tr>";
    echo "<th>" . __('Data', 'gamification') . "</th>";
    echo "<th>" . __('Descrição', 'gamification') . "</th>";
    echo "<th class='text-end'>" . __('XP', 'gamification') . "</th>";
    echo "<th>" . __('Chamado / Item', 'gamification') . "</th>";
    echo "</tr></thead><tbody>";

    foreach ($transactions as $tx) {
        $amt  = (int) $tx['xp_amount'];
        $pos  = $amt >= 0 && !$is_penalty;
        $xp_class = $is_penalty ? 'text-danger' : ($amt >= 0 ? 'text-success' : 'text-danger');
        $sign = ($is_penalty || $amt < 0) ? '−' : '+';
        $abs  = abs($amt);

        // Build link to source item
        $item_link  = '';
        $is_test    = ($tx['source_itemtype'] === 'GamificationTest');
        $item_id    = (int) ($tx['source_items_id'] ?? 0);

        if (!$is_test && $item_id > 0) {
            if ($tx['source_itemtype'] === 'Ticket') {
                $item_link = "<a href='{$root}/front/ticket.form.php?id={$item_id}' target='_blank' class='text-decoration-none'>"
                    . "<i class='ti ti-external-link me-1'></i>#" . $item_id . "</a>";
            } elseif ($tx['source_itemtype'] === 'KnowbaseItem') {
                $item_link = "<a href='{$root}/front/knowbaseitem.form.php?id={$item_id}' target='_blank' class='text-decoration-none'>"
                    . "<i class='ti ti-external-link me-1'></i>KB #{$item_id}</a>";
            }
        }

        $desc_html = htmlspecialchars((string) $tx['description']);
        if ($is_test) {
            $desc_html .= " <span class='badge bg-secondary ms-1' title='" . __('Dado de teste — use Dados de Teste para limpar', 'gamification') . "'>"
                       . __('Teste', 'gamification') . "</span>";
        }

        echo "<tr" . ($is_test ? " class='text-muted'" : '') . ">";
        echo "<td class='text-nowrap'>" . Html::convDateTime($tx['date_creation']) . "</td>";
        echo "<td>{$desc_html}</td>";
        echo "<td class='text-end fw-bold {$xp_class}'>";
        if ($abs > 0) echo "{$sign}{$abs} XP";
        else echo "<span class='text-muted'>—</span>";
        echo "</td>";
        echo "<td>{$item_link}</td>";
        echo "</tr>";
    }

    echo "</tbody></table></div>";
}

echo "</div>";
Html::footer();
