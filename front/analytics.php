<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Config;
use GlpiPlugin\Gamification\Score;
use GlpiPlugin\Gamification\Season;
use GlpiPlugin\Gamification\BadgeUser;
use GlpiPlugin\Gamification\Ui;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', READ);

global $DB;

$qexpr = fn(string $sql) => new \Glpi\DBAL\QueryExpression($sql);
$qn    = fn(string $n) => $DB->quoteName($n);

$active = Season::getActiveSeason();

// ── KPIs ────────────────────────────────────────────────────────────────────
$totals = $DB->request([
    'SELECT' => [
        $qexpr('COUNT(*) AS ' . $qn('players')),
        $qexpr('SUM(' . $qn('xp_season') . ') AS ' . $qn('xp_season')),
        $qexpr('SUM(' . $qn('tickets_resolved') . ') AS ' . $qn('tickets')),
        $qexpr('SUM(' . $qn('fcr_count') . ') AS ' . $qn('fcr')),
        $qexpr('SUM(' . $qn('sla_met_count') . ') AS ' . $qn('sla')),
        $qexpr('SUM(' . $qn('perfect_satisfaction') . ') AS ' . $qn('stars')),
        $qexpr('SUM(' . $qn('kb_articles') . ') AS ' . $qn('kb')),
    ],
    'FROM' => Score::$table,
])->current();

$active_players = (int) $DB->request([
    'SELECT' => [$qexpr('COUNT(*) AS ' . $qn('c'))],
    'FROM'   => Score::$table,
    'WHERE'  => ['xp_season' => ['>', 0]],
])->current()['c'];

$badges_awarded = countElementsInTable(BadgeUser::$table);

// ── XP over the last 14 days (aggregated in PHP to keep the query portable) ──
$since = date('Y-m-d 00:00:00', strtotime('-13 days'));
$by_day = [];
$iter = $DB->request([
    'SELECT' => ['date_creation', 'xp_amount'],
    'FROM'   => 'glpi_plugin_gamification_xptransactions',
    'WHERE'  => ['date_creation' => ['>=', $since], 'xp_amount' => ['>', 0]],
]);
foreach ($iter as $r) {
    $day = substr((string) $r['date_creation'], 0, 10);
    $by_day[$day] = ($by_day[$day] ?? 0) + (int) $r['xp_amount'];
}

// ── Reopens per user ────────────────────────────────────────────────────────
$reopens = [];
$iter = $DB->request([
    'SELECT'  => ['users_id', $qexpr('COUNT(*) AS ' . $qn('c'))],
    'FROM'    => 'glpi_plugin_gamification_xptransactions',
    'WHERE'   => ['event_type' => 'ticket_reopened'],
    'GROUPBY' => ['users_id'],
]);
foreach ($iter as $r) {
    $reopens[(int) $r['users_id']] = (int) $r['c'];
}

$techs = Score::getTopUsers(50);

// ── Aderência (tempo para atender o chamado) ─────────────────────────────────
// Período via querystring (?from=YYYY-MM-DD&to=YYYY-MM-DD); padrão = últimos 30 dias.
$parse_date = static function (?string $v, string $fallback): string {
    $d = $v ? \DateTime::createFromFormat('Y-m-d', $v) : false;
    return ($d && $d->format('Y-m-d') === $v) ? $v : $fallback;
};
$to   = $parse_date($_GET['to']   ?? null, date('Y-m-d'));
$from = $parse_date($_GET['from'] ?? null, date('Y-m-d', strtotime('-29 days')));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$target_min     = max(1, (int) (Config::getConfig('adherence_target_minutes') ?? 15));
$target_seconds = $target_min * 60;

// Parse seguro de datetime (NULL ou '0000-00-00 00:00:00' → null).
$safe_ts = static function ($v): ?int {
    if (empty($v) || $v[0] === '0') {
        return null;
    }
    $t = strtotime((string) $v);
    return $t ?: null;
};

// Avalia um chamado: foi atendido? quanto demorou? estourou o prazo?
$eval_adh = static function (array $r) use ($safe_ts, $target_seconds): array {
    $created = $safe_ts($r['date'] ?? null);
    $tiad_ts = $safe_ts($r['takeintoaccountdate'] ?? null);
    $tia     = (int) ($r['takeintoaccount_delay_stat'] ?? 0);
    $taken   = $tiad_ts !== null || $tia > 0;

    $delay = null;
    $adh_ts = null;
    if ($taken) {
        $delay  = $tia > 0 ? $tia : ($tiad_ts !== null && $created !== null ? max(0, $tiad_ts - $created) : 0);
        $adh_ts = $tiad_ts !== null ? $tiad_ts : ($created !== null ? $created + $delay : null);
    }

    $tto_ts = $safe_ts($r['time_to_own'] ?? null); // SLA "Tempo para atribuir"
    if ($tto_ts !== null) {
        // Com SLA: atendido após o prazo, ou ainda não atendido e já vencido.
        $breached = $taken ? ($adh_ts !== null && $adh_ts > $tto_ts) : (time() > $tto_ts);
    } else {
        // Sem SLA: usa o tempo-alvo configurável.
        $breached = $taken ? ($delay !== null && $delay > $target_seconds) : ($created !== null && (time() - $created) > $target_seconds);
    }

    return ['taken' => $taken, 'delay' => $delay, 'breached' => $breached];
};

$adh_fields   = ['date', 'takeintoaccount_delay_stat', 'takeintoaccountdate', 'time_to_own'];
$period_where = array_merge(
    [
        'glpi_tickets.is_deleted' => 0,
        ['glpi_tickets.date' => ['>=', $from . ' 00:00:00']],
        ['glpi_tickets.date' => ['<=', $to   . ' 23:59:59']],
    ],
    getEntitiesRestrictCriteria('glpi_tickets')
);

// Visão geral (sem JOIN → evita contagem dupla em chamados com vários atribuídos).
$adh = ['total' => 0, 'taken' => 0, 'sum' => 0, 'breached' => 0, 'pending' => 0];
$iter = $DB->request([
    'SELECT' => array_map(fn ($f) => 'glpi_tickets.' . $f, $adh_fields),
    'FROM'   => 'glpi_tickets',
    'WHERE'  => $period_where,
]);
foreach ($iter as $r) {
    $e = $eval_adh($r);
    $adh['total']++;
    if ($e['taken']) {
        $adh['taken']++;
        $adh['sum'] += (int) $e['delay'];
    } else {
        $adh['pending']++;
    }
    if ($e['breached']) {
        $adh['breached']++;
    }
}
$adh_avg     = $adh['taken'] > 0 ? (int) round($adh['sum'] / $adh['taken']) : null;
$adh_rate    = $adh['total'] > 0 ? (int) round(max(0, $adh['total'] - $adh['breached']) / $adh['total'] * 100) : 0;

// Por técnico atribuído.
$adh_by_user = [];
$iter = $DB->request([
    'SELECT'     => array_merge(['glpi_tickets_users.users_id'], array_map(fn ($f) => 'glpi_tickets.' . $f, $adh_fields)),
    'FROM'       => 'glpi_tickets',
    'INNER JOIN' => [
        'glpi_tickets_users' => [
            'ON' => [
                'glpi_tickets_users' => 'tickets_id',
                'glpi_tickets'       => 'id',
                ['AND' => ['glpi_tickets_users.type' => \CommonITILActor::ASSIGN]],
            ],
        ],
    ],
    'WHERE'      => $period_where,
]);
foreach ($iter as $r) {
    $uid = (int) $r['users_id'];
    if (!$uid) {
        continue;
    }
    $adh_by_user[$uid] ??= ['total' => 0, 'taken' => 0, 'sum' => 0, 'breached' => 0];
    $e = $eval_adh($r);
    $adh_by_user[$uid]['total']++;
    if ($e['taken']) {
        $adh_by_user[$uid]['taken']++;
        $adh_by_user[$uid]['sum'] += (int) $e['delay'];
    }
    if ($e['breached']) {
        $adh_by_user[$uid]['breached']++;
    }
}
uasort($adh_by_user, fn ($a, $b) => ($b['breached'] <=> $a['breached']) ?: ($b['total'] <=> $a['total']));

// Formata segundos em texto curto (12 min, 1h 05m, 2d 3h).
$fmt_dur = static function (?int $s): string {
    if ($s === null) {
        return '—';
    }
    if ($s < 60) {
        return $s . 's';
    }
    $m = intdiv($s, 60);
    if ($m < 60) {
        return $m . ' min';
    }
    $h = intdiv($m, 60);
    $m %= 60;
    if ($h < 24) {
        return sprintf('%dh %02dm', $h, $m);
    }
    $d = intdiv($h, 24);
    $h %= 24;
    return sprintf('%dd %dh', $d, $h);
};

Html::header(__('Análises', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'analytics');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// header
echo "<div class='d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4'>";
echo "<div><p class='gx-eyebrow mb-1'>" . __('Painel do gestor', 'gamification') . "</p>";
echo "<h1 class='h3 m-0'>" . __('Análises da equipe', 'gamification') . "</h1></div>";
if ($active) {
    echo "<span class='badge bg-primary-subtle text-primary-emphasis px-3 py-2'><i class='ti ti-calendar-event me-1'></i>" . htmlspecialchars($active['name']) . "</span>";
}
echo "</div>";

// ── KPI tiles ───────────────────────────────────────────────────────────────
$kpis = [
    ['ti-users',        __('Jogadores ativos', 'gamification'), $active_players,                'violet'],
    ['ti-bolt',         __('XP na temporada', 'gamification'),  (int) $totals['xp_season'],     'cyan'],
    ['ti-ticket',       __('Tickets resolvidos', 'gamification'), (int) $totals['tickets'],     'green'],
    ['ti-rocket',       __('FCR acumulado', 'gamification'),     (int) $totals['fcr'],           'gold'],
    ['ti-star-filled',  __('Avaliações 5★', 'gamification'),     (int) $totals['stars'],         'ember'],
    ['ti-medal-2',      __('Conquistas dadas', 'gamification'),  $badges_awarded,                'slate'],
];
echo "<div class='gx-stats mb-4'>";
foreach ($kpis as [$ico, $lbl, $val, $accent]) {
    echo "<div class='gx-stat gx-stat--{$accent}'>";
    echo "<div class='gx-stat-ico'><i class='ti {$ico}'></i></div>";
    echo "<div class='gx-num gx-stat-val animate-number' data-target='" . (int) $val . "'>0</div>";
    echo "<div class='gx-stat-lbl'>{$lbl}</div>";
    echo "</div>";
}
echo "</div>";

// ── Aderência: filtro de período + KPIs + tabela por técnico ─────────────────
$self = $_SERVER['PHP_SELF'];

echo "<div class='gx-card gx-card-pad mb-4'>";

// header + date-range form (calendário nativo)
echo "<div class='d-flex align-items-end justify-content-between flex-wrap gap-3 mb-3'>";
echo "<div><h2 class='h5 m-0'><i class='ti ti-clock-play me-2 text-primary'></i>" . __('Aderência — tempo para atender o chamado', 'gamification') . "</h2>";
echo "<p class='text-muted small m-0'>" . __('Do momento em que o chamado é aberto (Novo) até o técnico iniciar o atendimento.', 'gamification') . "</p></div>";
echo "<form method='get' action='" . Html::cleanInputText($self) . "' class='d-flex align-items-end gap-2 flex-wrap'>";
echo "<div><label class='form-label small mb-1'>" . __('De', 'gamification') . "</label>";
echo "<input type='date' name='from' value='" . Html::cleanInputText($from) . "' max='" . date('Y-m-d') . "' class='form-control form-control-sm'></div>";
echo "<div><label class='form-label small mb-1'>" . __('Até', 'gamification') . "</label>";
echo "<input type='date' name='to' value='" . Html::cleanInputText($to) . "' max='" . date('Y-m-d') . "' class='form-control form-control-sm'></div>";
echo "<button type='submit' class='btn btn-sm btn-primary'><i class='ti ti-filter me-1'></i>" . __('Aplicar', 'gamification') . "</button>";
echo "</form>";
echo "</div>";

// atalhos rápidos de período
$presets = [7 => __('7 dias', 'gamification'), 30 => __('30 dias', 'gamification'), 90 => __('90 dias', 'gamification')];
echo "<div class='d-flex gap-2 mb-3 flex-wrap'>";
foreach ($presets as $d => $lbl) {
    $pf  = date('Y-m-d', strtotime('-' . ($d - 1) . ' days'));
    $pt  = date('Y-m-d');
    $cls = ($from === $pf && $to === $pt) ? 'btn-primary' : 'btn-outline-secondary';
    $url = $self . '?from=' . $pf . '&to=' . $pt;
    echo "<a href='" . Html::cleanInputText($url) . "' class='btn btn-sm {$cls}'>" . $lbl . "</a>";
}
echo "</div>";

// KPIs de aderência
$adh_kpis = [
    ['ti-clock-play',     __('Tempo médio de aderência', 'gamification'), $fmt_dur($adh_avg),       'cyan'],
    ['ti-alert-triangle', __('Estouraram o prazo', 'gamification'),       (string) $adh['breached'], 'ember'],
    ['ti-circle-check',   __('Dentro do prazo', 'gamification'),          $adh_rate . '%',          'green'],
    ['ti-hourglass',      __('Aguardando atendimento', 'gamification'),   (string) $adh['pending'],  'slate'],
];
echo "<div class='gx-stats mb-2'>";
foreach ($adh_kpis as [$ico, $lbl, $val, $accent]) {
    echo "<div class='gx-stat gx-stat--{$accent}'>";
    echo "<div class='gx-stat-ico'><i class='ti {$ico}'></i></div>";
    echo "<div class='gx-num gx-stat-val'>" . htmlspecialchars($val) . "</div>";
    echo "<div class='gx-stat-lbl'>{$lbl}</div>";
    echo "</div>";
}
echo "</div>";
echo "<p class='text-muted small mb-0'>" . sprintf(
    __('%1$s chamados abertos entre %2$s e %3$s. Estouro = aderência após o SLA (Tempo para atribuir) ou, sem SLA, acima de %4$d min.', 'gamification'),
    $adh['total'],
    date('d/m/Y', strtotime($from)),
    date('d/m/Y', strtotime($to)),
    $target_min
) . "</p>";
echo "</div>";

// Tabela: aderência por técnico
echo "<div class='gx-card gx-card-pad mb-4'>";
echo "<h2 class='h5 mb-3'><i class='ti ti-user-clock me-2 text-primary'></i>" . __('Aderência por técnico', 'gamification') . "</h2>";
if (empty($adh_by_user)) {
    echo "<p class='text-muted text-center py-4'>" . __('Nenhum chamado atribuído no período.', 'gamification') . "</p>";
} else {
    echo "<div class='table-responsive'><table class='gx-board'>";
    echo "<thead><tr>";
    echo "<th>" . __('Técnico', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Atribuídos', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Atendidos', 'gamification') . "</th>";
    echo "<th class='text-end'>" . __('Tempo médio', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Estouraram', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('% no prazo', 'gamification') . "</th>";
    echo "</tr></thead><tbody>";
    foreach ($adh_by_user as $uid => $d) {
        $avg      = $d['taken'] > 0 ? (int) round($d['sum'] / $d['taken']) : null;
        $rate     = $d['total'] > 0 ? (int) round(max(0, $d['total'] - $d['breached']) / $d['total'] * 100) : 0;
        $br_cls   = $d['breached'] > 0 ? 'text-danger fw-bold' : 'text-muted';
        $rate_cls = $rate >= 90 ? 'text-success' : ($rate >= 70 ? 'text-warning' : 'text-danger');
        echo "<tr>";
        echo "<td><div class='fw-bold'>" . getUserName($uid) . "</div></td>";
        echo "<td class='text-center'>{$d['total']}</td>";
        echo "<td class='text-center'>{$d['taken']}</td>";
        echo "<td class='text-end'>" . $fmt_dur($avg) . "</td>";
        echo "<td class='text-center {$br_cls}'>{$d['breached']}</td>";
        echo "<td class='text-center {$rate_cls} fw-bold'>{$rate}%</td>";
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}
echo "</div>";

// ── XP trend (last 14 days) ─────────────────────────────────────────────────
$max = max(1, $by_day ? max($by_day) : 1);
echo "<div class='gx-card gx-card-pad mb-4'>";
echo "<h2 class='h5 mb-1'><i class='ti ti-chart-bar me-2 text-primary'></i>" . __('XP concedido nos últimos 14 dias', 'gamification') . "</h2>";
echo "<p class='text-muted small mb-4'>" . __('Soma diária de XP ganho por toda a equipe.', 'gamification') . "</p>";
echo "<div class='gx-chart'>";
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $val = $by_day[$day] ?? 0;
    $h   = max(2, round(($val / $max) * 100));
    $lbl = date('d/m', strtotime($day));
    echo "<div class='gx-chart-col'>";
    echo "<div class='gx-chart-bar' style='height:{$h}%' title='{$val} XP'></div>";
    echo "<div class='gx-chart-val'>{$val}</div>";
    echo "<div class='gx-chart-day'>{$lbl}</div>";
    echo "</div>";
}
echo "</div></div>";

// ── Per-technician table ────────────────────────────────────────────────────
echo "<div class='gx-card gx-card-pad'>";
echo "<h2 class='h5 mb-3'><i class='ti ti-table me-2 text-primary'></i>" . __('Desempenho por técnico', 'gamification') . "</h2>";
if (empty($techs)) {
    echo "<p class='text-muted text-center py-4'>" . __('Nenhum técnico pontuou ainda.', 'gamification') . "</p>";
} else {
    echo "<div class='table-responsive'><table class='gx-board'>";
    echo "<thead><tr>";
    echo "<th>" . __('Técnico', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Nível', 'gamification') . "</th>";
    echo "<th class='text-end'>" . __('XP Temp.', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Tickets', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('FCR', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('SLA', 'gamification') . "</th>";
    echo "<th class='text-center'>5★</th>";
    echo "<th class='text-center'>" . __('Reaberturas', 'gamification') . "</th>";
    echo "</tr></thead><tbody>";
    foreach ($techs as $t) {
        $uid = (int) $t['users_id'];
        $ro  = $reopens[$uid] ?? 0;
        $ro_cls = $ro > 0 ? 'text-danger fw-bold' : 'text-muted';
        echo "<tr>";
        echo "<td><div class='d-flex align-items-center gap-3'>" . Ui::avatar($t, 36) . "<div class='fw-bold'>" . getUserName($uid) . "</div></div></td>";
        echo "<td class='text-center'><span class='badge bg-secondary-subtle text-secondary-emphasis'>" . __('Nv', 'gamification') . " {$t['level']}</span></td>";
        echo "<td class='text-end'><span class='gx-num text-success'>{$t['xp_season']}</span></td>";
        echo "<td class='text-center'>{$t['tickets_resolved']}</td>";
        echo "<td class='text-center'>{$t['fcr_count']}</td>";
        echo "<td class='text-center'>{$t['sla_met_count']}</td>";
        echo "<td class='text-center'>{$t['perfect_satisfaction']}</td>";
        echo "<td class='text-center {$ro_cls}'>{$ro}</td>";
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}
echo "</div>";

echo "</div>"; // wrapper
Html::footer();
