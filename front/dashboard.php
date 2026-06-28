<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Score;
use GlpiPlugin\Gamification\Leaderboard;
use GlpiPlugin\Gamification\BadgeUser;
use GlpiPlugin\Gamification\XPTransaction;
use GlpiPlugin\Gamification\Config;
use GlpiPlugin\Gamification\Ui;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_dashboard', READ);
Menu::checkPanelEnabled();

$users_id  = Session::getLoginUserID();
$score     = Score::getOrCreate($users_id);
$rank      = Leaderboard::getPositionForUser($users_id);
$top5      = Score::getTopUsers(5);
$badges    = BadgeUser::getForUser($users_id);
$recent_tx = XPTransaction::getForUser($users_id, 8);

$base    = (int) Config::getConfig('xp_per_level_base') ?: 100;
$level   = (int) $score['level'];
$xp_cur  = pow($level - 1, 2) * $base;
$xp_next = pow($level, 2) * $base;
$xp_in   = $score['xp_total'] - $xp_cur;
$xp_need = max(1, $xp_next - $xp_cur);
$progress = min(100, max(0, round(($xp_in / $xp_need) * 100)));
$xp_left  = max(0, $xp_next - $score['xp_total']);

$me = new User();
$me->getFromDB($users_id);
$display_name = getUserName($users_id);
$first = trim((string) $me->fields['firstname']);
$initials = strtoupper(substr($first ?: ($me->fields['realname'] ?: $display_name), 0, 1));
$hour = (int) date('H');
$greet = $hour < 12 ? __('Bom dia', 'gamification')
       : ($hour < 18 ? __('Boa tarde', 'gamification') : __('Boa noite', 'gamification'));

Html::header(__('Dashboard', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'dashboard');

echo "<div class='container-fluid py-4 gamification-wrapper' data-gx-level='{$level}'>";

// ─────────────────────────────────────────────────────────── HERO ──────────
echo "<div class='gx-hero mb-4'>";
echo "<div class='d-flex flex-column flex-md-row align-items-md-center gap-4'>";

// Level orb
echo "<div class='gx-orb' style='--gx-end:{$progress}'>";
echo "<div class='gx-orb-core'>";
echo "<div class='gx-num gx-orb-lvl'>{$level}</div>";
echo "<div class='gx-orb-cap'>" . __('Nível', 'gamification') . "</div>";
echo "</div></div>";

// Identity + chips
echo "<div class='flex-grow-1'>";
echo "<p class='gx-eyebrow'>{$greet}, " . __('técnico', 'gamification') . "</p>";
echo "<h1 class='gx-hero-name'>" . htmlspecialchars($display_name) . "</h1>";
echo "<div class='gx-hero-chips'>";
echo "<span class='gx-chip'><i class='ti ti-bolt'></i> " . number_format($score['xp_total'], 0, ',', '.') . " XP " . __('total', 'gamification') . "</span>";
echo "<span class='gx-chip'><i class='ti ti-calendar-bolt'></i> {$score['xp_season']} XP " . __('na temporada', 'gamification') . "</span>";
echo "<span class='gx-chip'><i class='ti ti-trophy'></i> " . ($rank ? "#{$rank}" : "—") . " " . __('no ranking', 'gamification') . "</span>";
echo "<span class='gx-chip'><i class='ti ti-flame'></i> {$score['current_streak']} " . __('de sequência', 'gamification') . "</span>";
echo "</div>";

// XP progress to next level
echo "<div class='mt-3' style='max-width:520px'>";
echo "<div class='d-flex justify-content-between small mb-1' style='opacity:.9'>";
echo "<span>" . __('Progresso para o nível', 'gamification') . " " . ($level + 1) . "</span>";
echo "<span class='fw-bold'>{$progress}%</span>";
echo "</div>";
echo "<div class='gx-xpbar'><div class='gx-xpbar-fill' style='width:{$progress}%'></div></div>";
echo "<div class='small mt-1' style='opacity:.8'>" . sprintf(__('Faltam %s XP para subir de nível', 'gamification'), number_format($xp_left, 0, ',', '.')) . "</div>";
echo "</div>";

echo "</div>"; // identity
echo "</div></div>"; // hero flex / hero

// ───────────────────────────────────────────────────────── STAT TILES ──────
// Cada card leva o técnico direto para a lista correspondente no GLPI.
global $CFG_GLPI;
$root = $CFG_GLPI['root_doc'] ?? '';
$search_url = static function (string $path, array $criteria) use ($root): string {
    $params = [];
    foreach (array_values($criteria) as $i => $c) {
        if ($i > 0) {
            $params["criteria[{$i}][link]"] = 'AND';
        }
        [$field, $type, $value] = $c;
        $params["criteria[{$i}][field]"]      = $field;
        $params["criteria[{$i}][searchtype]"] = $type;
        $params["criteria[{$i}][value]"]      = $value;
    }
    return $root . $path . '?' . http_build_query($params);
};

// Ticket: 5=Técnico atribuído, 12=Status, 62=Satisfação. KnowbaseItem: 70=Usuário (autor).
$url_resolved = $search_url('/front/ticket.php', [[5, 'equals', $users_id], [12, 'equals', 'old']]);
$url_sat5     = $search_url('/front/ticket.php', [[5, 'equals', $users_id], [62, 'equals', 5]]);
$url_kb       = $search_url('/front/knowbaseitem.php', [[70, 'equals', $users_id]]);
$url_badges   = 'badges.php';

$tiles = [
    ['ti-ticket',       __('Tickets resolvidos', 'gamification'), $score['tickets_resolved'],    'violet', $url_resolved],
    ['ti-rocket',       __('FCR (1º contato)', 'gamification'),    $score['fcr_count'],           'cyan',   $url_resolved],
    ['ti-clock-check',  __('SLA cumprido', 'gamification'),        $score['sla_met_count'],       'green',  $url_resolved],
    ['ti-star-filled',  __('Avaliações 5★', 'gamification'),       $score['perfect_satisfaction'],'gold',   $url_sat5],
    ['ti-book',         __('Artigos KB', 'gamification'),          $score['kb_articles'],         'slate',  $url_kb],
    ['ti-medal-2',      __('Conquistas', 'gamification'),          count($badges),                'ember',  $url_badges],
];
echo "<div class='gx-stats mb-4'>";
foreach ($tiles as [$ico, $lbl, $val, $accent, $href]) {
    echo "<a href='" . htmlspecialchars($href) . "' class='gx-stat gx-stat--{$accent}'>";
    echo "<div class='gx-stat-ico'><i class='ti {$ico}'></i></div>";
    echo "<div class='gx-num gx-stat-val animate-number' data-target='" . (int) $val . "'>0</div>";
    echo "<div class='gx-stat-lbl'>{$lbl}</div>";
    echo "<span class='gx-stat-go'><i class='ti ti-arrow-up-right'></i></span>";
    echo "</a>";
}
echo "</div>";

// ──────────────────────────────────── ACTIVITY + MINI LEADERBOARD ──────────
echo "<div class='row g-4'>";

// Activity feed
echo "<div class='col-lg-7'>";
echo "<div class='gx-card gx-card-pad h-100'>";
echo "<div class='d-flex align-items-center justify-content-between mb-3'>";
echo "<h2 class='h5 m-0'><i class='ti ti-activity-heartbeat me-2 text-primary'></i>" . __('Atividade recente', 'gamification') . "</h2>";
echo "</div>";
if (empty($recent_tx)) {
    echo "<div class='text-center text-muted py-5'><i class='ti ti-sparkles fs-1 d-block mb-2'></i>" . __('Resolva seu primeiro ticket para começar a pontuar.', 'gamification') . "</div>";
} else {
    echo "<div class='gx-feed'>";
    foreach ($recent_tx as $tx) {
        $amt = (int) $tx['xp_amount'];
        $pos = $amt >= 0;
        $cls = $pos ? 'gx-feed-pos' : 'gx-feed-neg';
        $ico = $pos ? 'ti-plus' : 'ti-minus';
        $sign = $pos ? '+' : '−';
        echo "<div class='gx-feed-row'>";
        echo "<div class='gx-feed-ico {$cls}'><i class='ti {$ico}'></i></div>";
        echo "<div class='gx-feed-body'>";
        echo "<div class='gx-feed-desc text-truncate'>" . htmlspecialchars((string) $tx['description']) . "</div>";
        echo "<div class='gx-feed-time'>" . Html::convDateTime($tx['date_creation']) . "</div>";
        echo "</div>";
        echo "<div class='gx-feed-amt " . ($pos ? 'text-success' : 'text-danger') . "'>{$sign}" . abs($amt) . " XP</div>";
        echo "</div>";
    }
    echo "</div>";
}
echo "</div></div>";

// Mini leaderboard / podium
echo "<div class='col-lg-5'>";
echo "<div class='gx-card gx-card-pad h-100'>";
echo "<div class='d-flex align-items-center justify-content-between mb-3'>";
echo "<h2 class='h5 m-0'><i class='ti ti-trophy me-2 text-warning'></i>" . __('Top 5 da temporada', 'gamification') . "</h2>";
echo "<a href='leaderboard.php' class='btn btn-sm btn-outline-primary'>" . __('Ver ranking', 'gamification') . "</a>";
echo "</div>";
echo "<div class='gx-feed'>";
$pos = 1;
foreach ($top5 as $tu) {
    $is_me = ($tu['users_id'] == $users_id);
    $tier  = $pos <= 3 ? "tier-{$pos}" : '';
    echo "<div class='gx-feed-row" . ($is_me ? " fw-bold" : "") . "'>";
    echo Ui::avatar($tu, 40, "gx-ava {$tier}");
    echo "<div class='gx-feed-body'>";
    echo "<div class='gx-feed-desc text-truncate'>" . getUserName($tu['users_id']) . ($is_me ? " <span class='badge bg-primary ms-1'>" . __('Você', 'gamification') . "</span>" : "") . "</div>";
    echo "<div class='gx-feed-time'>" . __('Nível', 'gamification') . " {$tu['level']}</div>";
    echo "</div>";
    echo "<div class='gx-feed-amt text-success'>{$tu['xp_season']} XP</div>";
    echo "</div>";
    $pos++;
}
if (empty($top5)) {
    echo "<div class='text-center text-muted py-4'>" . __('Sem dados ainda.', 'gamification') . "</div>";
}
echo "</div>";
echo "</div></div>";

echo "</div>"; // row
echo "</div>"; // wrapper

Html::footer();
