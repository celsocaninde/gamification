<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Html;
use Session;

class Config extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_configs';
    public static $rightname = 'plugin_gamification_admin';

    public static function getTypeName($nb = 0): string
    {
        return __('Configuração Gamificação', 'gamification');
    }

    public static function getConfig(string $key): ?string
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'value',
            'FROM'   => self::$table,
            'WHERE'  => ['key_name' => $key]
        ])->current();

        return $row ? $row['value'] : null;
    }

    public static function setConfig(string $key, string $value): void
    {
        global $DB;
        $exists = countElementsInTable(self::$table, ['key_name' => $key]);

        if ($exists) {
            $DB->update(self::$table, [
                'value' => $value,
                'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
            ], [
                'key_name' => $key
            ]);
        } else {
            $DB->insert(self::$table, [
                'key_name' => $key,
                'value' => $value
            ]);
        }
    }

    /**
     * Is the player-facing panel enabled for the user's current (active) entity?
     * Empty/unset allowlist = enabled everywhere. Root entity (id 0) is honoured.
     */
    public static function isEnabledForCurrentEntity(): bool
    {
        $csv = self::getConfig('enabled_entities');
        if ($csv === null) {
            return true;
        }
        $allowed = array_filter(array_map('trim', explode(',', $csv)), static fn($v) => $v !== '');
        if (empty($allowed)) {
            return true; // vazio = todas as entidades
        }
        $active = (string) ($_SESSION['glpiactive_entity'] ?? '0');
        return in_array($active, $allowed, true);
    }

    public function showConfigForm(): bool
    {
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return false;
        }

        global $DB;

        $cfg = [
            'enabled_entities'      => self::getConfig('enabled_entities') ?? '',
            'plugin_enabled'        => self::getConfig('plugin_enabled') ?? '1',
            'business_hours_start'  => self::getConfig('business_hours_start') ?? '08:00',
            'business_hours_end'    => self::getConfig('business_hours_end') ?? '18:00',
            'business_days'         => self::getConfig('business_days') ?? '1,2,3,4,5',
            'season_duration'       => self::getConfig('season_duration') ?? 'monthly',
            'fcr_max_minutes'       => self::getConfig('fcr_max_minutes') ?? '60',
            'adherence_target_minutes' => self::getConfig('adherence_target_minutes') ?? '15',
            'xp_per_level_base'     => self::getConfig('xp_per_level_base') ?? '100',
            'enable_penalties'      => self::getConfig('enable_penalties') ?? '1',
            'enable_rewards_shop'   => self::getConfig('enable_rewards_shop') ?? '1',
            'enable_streak_bonus'   => self::getConfig('enable_streak_bonus') ?? '0',
            'streak_bonus_step'     => self::getConfig('streak_bonus_step') ?? '5',
            'streak_bonus_pct'      => self::getConfig('streak_bonus_pct') ?? '5',
            'streak_bonus_cap'      => self::getConfig('streak_bonus_cap') ?? '50',
        ];

        // Row renderer: label + help on the left, control on the right.
        $row = function (string $icon, string $label, string $help, string $control): void {
            echo "<div class='row align-items-center gx-cfg-row'>";
            echo "<div class='col-md-7'>";
            echo "<div class='d-flex align-items-start gap-2'>";
            echo "<i class='ti {$icon} gx-cfg-ico'></i>";
            echo "<div><div class='fw-semibold'>{$label}</div><div class='small text-muted'>{$help}</div></div>";
            echo "</div></div>";
            echo "<div class='col-md-5 mt-2 mt-md-0'>{$control}</div>";
            echo "</div>";
        };

        $yesno = function (string $name, string $value): string {
            ob_start();
            \Dropdown::showYesNo($name, $value);
            return ob_get_clean();
        };

        echo "<form action='" . \Toolbox::getItemTypeFormURL(self::class) . "' method='post' class='gamification-wrapper'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        // ── Section: General ────────────────────────────────────────────────
        echo "<div class='gx-card gx-card-pad mb-4'>";
        echo "<p class='gx-eyebrow mb-3'><i class='ti ti-settings me-1'></i>" . __('Geral', 'gamification') . "</p>";
        $row('ti-power', __('Plugin ativo', 'gamification'),
            __('Liga ou desliga toda a pontuação e premiação.', 'gamification'),
            $yesno('plugin_enabled', $cfg['plugin_enabled']));
        $row('ti-stairs-up', __('XP base por nível', 'gamification'),
            __('Quanto maior, mais XP é exigido para subir de nível (fórmula: nível² × base).', 'gamification'),
            "<input type='number' name='xp_per_level_base' min='10' step='10' class='form-control' value='" . (int)$cfg['xp_per_level_base'] . "'>");
        echo "</div>";

        // ── Section: Business hours & SLA ──────────────────────────────────
        echo "<div class='gx-card gx-card-pad mb-4'>";
        echo "<p class='gx-eyebrow mb-3'><i class='ti ti-clock-hour-8 me-1'></i>" . __('Horário comercial & SLA', 'gamification') . "</p>";
        $row('ti-sunrise', __('Início do expediente', 'gamification'),
            __('Usado para identificar atendimentos fora do horário (badge Coruja).', 'gamification'),
            "<input type='time' name='business_hours_start' class='form-control' value='" . Html::cleanInputText($cfg['business_hours_start']) . "'>");
        $row('ti-sunset', __('Fim do expediente', 'gamification'),
            __('Atendimentos após este horário contam como fora do expediente.', 'gamification'),
            "<input type='time' name='business_hours_end' class='form-control' value='" . Html::cleanInputText($cfg['business_hours_end']) . "'>");
        // Business days as checkboxes
        $days = [1 => __('Seg', 'gamification'), 2 => __('Ter', 'gamification'), 3 => __('Qua', 'gamification'),
                 4 => __('Qui', 'gamification'), 5 => __('Sex', 'gamification'), 6 => __('Sáb', 'gamification'), 7 => __('Dom', 'gamification')];
        $active_days = explode(',', $cfg['business_days']);
        $days_html = "<div class='d-flex flex-wrap gap-1'>";
        foreach ($days as $num => $lbl) {
            $checked = in_array((string)$num, $active_days, true) ? 'checked' : '';
            $days_html .= "<label class='gx-day'><input type='checkbox' name='business_days[]' value='{$num}' {$checked}><span>{$lbl}</span></label>";
        }
        $days_html .= "</div>";
        $row('ti-calendar-week', __('Dias úteis', 'gamification'),
            __('Dias considerados horário comercial.', 'gamification'), $days_html);
        $row('ti-rocket', __('Tempo máx. FCR (minutos)', 'gamification'),
            __('Resolução em até este tempo, sem escalonamento, conta como 1º contato.', 'gamification'),
            "<input type='number' name='fcr_max_minutes' min='1' class='form-control' value='" . (int)$cfg['fcr_max_minutes'] . "'>");
        $row('ti-clock-play', __('Tempo-alvo de aderência (minutos)', 'gamification'),
            __('Meta para o técnico iniciar o atendimento de um chamado novo. Usado no painel de Análises quando o chamado não tem SLA (Tempo para atribuir); acima disso conta como estouro.', 'gamification'),
            "<input type='number' name='adherence_target_minutes' min='1' class='form-control' value='" . (int)$cfg['adherence_target_minutes'] . "'>");
        echo "</div>";

        // ── Section: Seasons & rewards ─────────────────────────────────────
        echo "<div class='gx-card gx-card-pad mb-4'>";
        echo "<p class='gx-eyebrow mb-3'><i class='ti ti-trophy me-1'></i>" . __('Temporadas & Recompensas', 'gamification') . "</p>";
        ob_start();
        \Dropdown::showFromArray('season_duration', [
            'monthly'   => __('Mensal', 'gamification'),
            'quarterly' => __('Trimestral', 'gamification'),
        ], ['value' => $cfg['season_duration']]);
        $season_ctrl = ob_get_clean();
        $row('ti-calendar-event', __('Duração da temporada', 'gamification'),
            __('Período de cada disputa do ranking antes de zerar.', 'gamification'), $season_ctrl);
        $row('ti-building-store', __('Loja de recompensas', 'gamification'),
            __('Permite que técnicos troquem XP por benefícios.', 'gamification'),
            $yesno('enable_rewards_shop', $cfg['enable_rewards_shop']));
        $row('ti-mood-sad', __('Penalidades', 'gamification'),
            __('Desconta XP em eventos negativos, como tickets reabertos.', 'gamification'),
            $yesno('enable_penalties', $cfg['enable_penalties']));
        echo "</div>";

        // ── Section: Streak bonus ──────────────────────────────────────────
        echo "<div class='gx-card gx-card-pad mb-4'>";
        echo "<p class='gx-eyebrow mb-3'><i class='ti ti-flame me-1'></i>" . __('Bônus de sequência (streak)', 'gamification') . "</p>";
        $row('ti-flame', __('Ativar bônus de sequência', 'gamification'),
            __('Multiplica o XP conforme a sequência de tickets sem reabertura cresce.', 'gamification'),
            $yesno('enable_streak_bonus', $cfg['enable_streak_bonus']));
        $row('ti-stairs', __('Passo da sequência', 'gamification'),
            __('A cada N tickets na sequência, ganha mais um degrau de bônus.', 'gamification'),
            "<input type='number' name='streak_bonus_step' min='1' class='form-control' value='" . (int)$cfg['streak_bonus_step'] . "'>");
        $row('ti-percentage', __('Bônus por degrau (%)', 'gamification'),
            __('Percentual de XP extra adicionado a cada degrau alcançado.', 'gamification'),
            "<input type='number' name='streak_bonus_pct' min='0' class='form-control' value='" . (int)$cfg['streak_bonus_pct'] . "'>");
        $row('ti-ceiling', __('Teto do bônus (%)', 'gamification'),
            __('Limite máximo do bônus de sequência.', 'gamification'),
            "<input type='number' name='streak_bonus_cap' min='0' class='form-control' value='" . (int)$cfg['streak_bonus_cap'] . "'>");
        echo "</div>";

        // ── Section: Panel visibility per entity ───────────────────────────
        $all_entities = [];
        foreach ($DB->request(['FROM' => 'glpi_entities', 'ORDER' => 'completename']) as $e) {
            $label = trim((string) ($e['completename'] ?? '')) ?: trim((string) $e['name']) ?: __('Entidade raiz', 'gamification');
            $all_entities[(int) $e['id']] = $label;
        }
        $selected_ent = array_filter(array_map('trim', explode(',', $cfg['enabled_entities'])), static fn($v) => $v !== '');
        ob_start();
        echo Html::hidden('enabled_entities[]', ['value' => '']); // garante envio mesmo se nada for selecionado
        \Dropdown::showFromArray('enabled_entities', $all_entities, [
            'multiple' => true,
            'values'   => $selected_ent,
            'width'    => '100%',
            'display'  => true,
        ]);
        $ent_ctrl = ob_get_clean();

        echo "<div class='gx-card gx-card-pad mb-4'>";
        echo "<p class='gx-eyebrow mb-3'><i class='ti ti-eye-cog me-1'></i>" . __('Visibilidade do painel', 'gamification') . "</p>";
        $row('ti-building-community', __('Entidades habilitadas', 'gamification'),
            __('Selecione em quais entidades o painel (Dashboard, Ranking, Conquistas, Missões e Recompensas) aparece. Deixe vazio para liberar em TODAS. Os menus de administração não são afetados.', 'gamification'),
            $ent_ctrl);
        echo "</div>";

        echo "<div class='d-flex justify-content-end mb-4'>";
        echo "<button type='submit' name='update' class='btn btn-primary btn-lg px-4'><i class='ti ti-device-floppy me-1'></i>" . __('Salvar configurações', 'gamification') . "</button>";
        echo "</div>";

        Html::closeForm();

        return true;
    }
}
