<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Leaderboard;
use GlpiPlugin\Gamification\Season;
use GlpiPlugin\Gamification\Ui;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_leaderboard', READ);
Menu::checkPanelEnabled();

$seasons_id = $_GET['seasons_id'] ?? null;
$groups_id  = $_GET['groups_id'] ?? null;
$view       = ($_GET['view'] ?? 'individual') === 'teams' ? 'teams' : 'individual';

$activeSeason = Season::getActiveSeason();
if (!$seasons_id && $activeSeason) {
    $seasons_id = $activeSeason['id'];
}

$my_id = Session::getLoginUserID();

Html::header(__('Leaderboard', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'leaderboard');

echo "<div class='container-fluid py-4 gamification-wrapper'>";

// ─────────────────────────────────────────────────── view toggle + filters ─
echo "<div class='gx-card gx-card-pad mb-4'>";

// Segmented toggle
$season_q = $seasons_id ? "&seasons_id={$seasons_id}" : '';
echo "<div class='gx-seg mb-3'>";
echo "<a href='?view=individual{$season_q}' class='gx-seg-btn" . ($view === 'individual' ? ' is-active' : '') . "'><i class='ti ti-user me-1'></i>" . __('Individual', 'gamification') . "</a>";
echo "<a href='?view=teams{$season_q}' class='gx-seg-btn" . ($view === 'teams' ? ' is-active' : '') . "'><i class='ti ti-users-group me-1'></i>" . __('Equipes', 'gamification') . "</a>";
echo "</div>";

echo "<form method='GET' id='leaderboard-filter-form' class='row g-3 align-items-end'>";
echo "<input type='hidden' name='view' value='{$view}'>";
echo "<div class='col-md-5'>";
echo "<label class='gx-eyebrow mb-1 d-block'>" . __('Temporada', 'gamification') . "</label>";
\Dropdown::show('GlpiPlugin\Gamification\Season', [
    'name' => 'seasons_id', 'value' => $seasons_id,
    'display_emptychoice' => false, 'class' => 'form-select lb-filter',
]);
echo "</div>";
if ($view === 'individual') {
    echo "<div class='col-md-5'>";
    echo "<label class='gx-eyebrow mb-1 d-block'>" . __('Grupo', 'gamification') . "</label>";
    \Dropdown::show('Group', ['name' => 'groups_id', 'value' => $groups_id, 'class' => 'form-select lb-filter']);
    echo "</div>";
}
echo "<div class='col-md-2'>";
echo "<button type='submit' class='btn btn-primary w-100'><i class='ti ti-filter me-1'></i>" . __('Filtrar', 'gamification') . "</button>";
echo "</div>";
echo "</form>";
echo "</div>";

// ════════════════════════════════════════════════════════ TEAMS VIEW ══════
if ($view === 'teams') {
    $teams = Leaderboard::getTeamRanking($seasons_id, 100);

    if (empty($teams)) {
        echo "<div class='gx-card gx-card-pad text-center text-muted py-5'>";
        echo "<i class='ti ti-users-group fs-1 d-block mb-3'></i>" . __('Nenhuma equipe pontuou nesta temporada.', 'gamification');
        echo "</div></div>";
        Html::footer();
        return;
    }

    echo "<div class='gx-card gx-card-pad'>";
    echo "<h2 class='h5 mb-3'><i class='ti ti-trophy me-2 text-warning'></i>" . __('Ranking de equipes', 'gamification') . "</h2>";
    echo "<div class='table-responsive'><table class='gx-board'>";
    echo "<thead><tr>";
    echo "<th style='width:70px'>" . __('Pos.', 'gamification') . "</th>";
    echo "<th>" . __('Equipe', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Membros', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Nível', 'gamification') . "</th>";
    echo "<th class='text-center'>" . __('Conquistas', 'gamification') . "</th>";
    echo "<th class='text-end'>" . __('XP Total', 'gamification') . "</th>";
    echo "</tr></thead><tbody>";
    foreach ($teams as $t) {
        $r    = (int) $t['dynamic_rank'];
        $tier = $r <= 3 ? "tier-{$r}" : '';
        $rankcell = $r <= 3
            ? "<i class='ti ti-medal-2' style='font-size:1.5rem;color:var(--tier)'></i>"
            : "<span class='gx-rank-pill'>{$r}</span>";

        $team_badges = Leaderboard::getTeamBadges((int) $t['groups_id'], null, 6);

        echo "<tr class='{$tier}'>";
        echo "<td>{$rankcell}</td>";
        echo "<td><div class='d-flex align-items-center gap-3'>";
        echo "<span class='gx-ava {$tier}'><i class='ti ti-users-group'></i></span>";
        echo "<div>";
        echo "<div class='fw-bold'>" . htmlspecialchars((string) $t['group_name']) . "</div>";
        if (!empty($team_badges)) {
            echo "<div class='d-flex gap-1 mt-1'>";
            foreach ($team_badges as $b) {
                $title = htmlspecialchars($b['name'] . ' (' . $b['earners'] . ')', ENT_QUOTES);
                $color = htmlspecialchars((string) $b['icon_color'], ENT_QUOTES);
                $icon  = htmlspecialchars((string) $b['icon'], ENT_QUOTES);
                echo "<i class='ti {$icon}' title='{$title}' style='color:{$color};font-size:1.1rem'></i>";
            }
            echo "</div>";
        }
        echo "</div>";
        echo "</div></td>";
        echo "<td class='text-center'>" . (int) $t['members'] . "</td>";
        echo "<td class='text-center'><span class='badge bg-secondary-subtle text-secondary-emphasis px-3 py-2'>" . __('Nv', 'gamification') . " {$t['level']}</span></td>";
        echo "<td class='text-center'><i class='ti ti-medal text-warning me-1'></i>" . (int) $t['badges_count'] . "</td>";
        echo "<td class='text-end'><span class='gx-num fs-5 text-success'>" . number_format((int) $t['total_xp'], 0, ',', '.') . "</span> <span class='small text-muted'>XP</span></td>";
        echo "</tr>";
    }
    echo "</tbody></table></div></div>";

    echo "</div>"; // wrapper
    Html::footer();
    return;
}

// ════════════════════════════════════════════════════ INDIVIDUAL VIEW ═════
$ranking = Leaderboard::getRanking($seasons_id, $groups_id, 100);

if (empty($ranking)) {
    echo "<div class='gx-card gx-card-pad text-center text-muted py-5'>";
    echo "<i class='ti ti-ghost-2 fs-1 d-block mb-3'></i>" . __('Nenhum dado encontrado para esta temporada.', 'gamification');
    echo "</div></div>";
    Html::footer();
    return;
}

// podium
$top3 = array_slice($ranking, 0, 3);
if (count($top3) >= 2) {
    echo "<div class='gx-card gx-card-pad mb-4'>";
    echo "<p class='gx-eyebrow text-center mb-4'>" . __('Pódio da temporada', 'gamification') . "</p>";
    echo "<div class='gx-podium'>";
    foreach ($top3 as $row) {
        $r     = (int) $row['dynamic_rank'];
        $crown = $r === 1 ? "<i class='ti ti-crown gx-crown'></i>" : "";
        echo "<div class='gx-podium-col tier-{$r}' data-rank='{$r}'>";
        echo $crown;
        echo Ui::avatar($row, 0, 'gx-podium-ava');
        echo "<div class='gx-podium-name'>" . getUserName($row['users_id']) . "</div>";
        echo "<div class='gx-podium-xp'>{$row['xp_earned']} XP</div>";
        echo "<div class='gx-podium-step'>{$r}</div>";
        echo "</div>";
    }
    echo "</div></div>";
}

// table
echo "<div class='gx-card gx-card-pad'>";
echo "<h2 class='h5 mb-3'><i class='ti ti-list-numbers me-2 text-primary'></i>" . __('Classificação completa', 'gamification') . "</h2>";
echo "<div class='table-responsive'><table class='gx-board'>";
echo "<thead><tr>";
echo "<th style='width:70px'>" . __('Pos.', 'gamification') . "</th>";
echo "<th>" . __('Técnico', 'gamification') . "</th>";
echo "<th class='text-center'>" . __('Nível', 'gamification') . "</th>";
echo "<th class='text-center'>" . __('Conquistas', 'gamification') . "</th>";
echo "<th class='text-end'>" . __('XP Temporada', 'gamification') . "</th>";
echo "</tr></thead><tbody>";

foreach ($ranking as $row) {
    $r     = (int) $row['dynamic_rank'];
    $is_me = ($row['users_id'] == $my_id);
    $tier  = $r <= 3 ? "tier-{$r}" : '';

    $rankcell = $r <= 3
        ? "<i class='ti ti-medal-2' style='font-size:1.5rem;color:var(--tier)'></i>"
        : "<span class='gx-rank-pill'>{$r}</span>";

    echo "<tr class='" . trim("{$tier}" . ($is_me ? ' is-me' : '')) . "'>";
    echo "<td>{$rankcell}</td>";
    echo "<td><div class='d-flex align-items-center gap-3'>";
    echo Ui::avatar($row, 40, "gx-ava {$tier}");
    echo "<div class='fw-bold'>" . getUserName($row['users_id']) . ($is_me ? " <span class='badge bg-primary ms-1'>" . __('Você', 'gamification') . "</span>" : "") . "</div>";
    echo "</div></td>";
    echo "<td class='text-center'><span class='badge bg-secondary-subtle text-secondary-emphasis px-3 py-2'>" . __('Nv', 'gamification') . " {$row['level']}</span></td>";
    echo "<td class='text-center'><i class='ti ti-medal text-warning me-1'></i>{$row['badges_count']}</td>";
    echo "<td class='text-end'><span class='gx-num fs-5 text-success'>{$row['xp_earned']}</span> <span class='small text-muted'>XP</span></td>";
    echo "</tr>";
}
echo "</tbody></table></div></div>";

echo "</div>"; // wrapper
Html::footer();
