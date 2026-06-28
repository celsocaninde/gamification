<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Html;

class Badge extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_badges';
    public static $rightname = 'plugin_gamification_admin';

    public static function getTypeName($nb = 0): string
    {
        return _n('Conquista', 'Conquistas', $nb, 'gamification');
    }

    public static function getByName(string $name): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::$table, 'WHERE' => ['name' => $name], 'LIMIT' => 1])->current();
        return $row ?: null;
    }

    public static function getAll(bool $activeOnly = true): array
    {
        global $DB;
        $where = [];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }

        $iterator = $DB->request([
            'FROM'   => self::$table,
            'WHERE'  => $where,
            'ORDER'  => 'id ASC'
        ]);

        $badges = [];
        foreach ($iterator as $row) {
            $badges[] = $row;
        }
        return $badges;
    }

    /**
     * Canonical list of every badge the plugin ships with.
     * Single source of truth used both by install() (fresh installs) and by
     * seedMissing() (idempotent top-up on re-install / upgrade).
     *
     * Only criteria_type values that cronCheckBadges actually evaluates are used,
     * so every badge here is earnable:
     *   tickets_resolved_total, fcr_total, perfect_satisfaction_total,
     *   kb_articles_total, no_reopen_streak, high_priority_sla_weekly (= SLA met).
     */
    public static function catalog(): array
    {
        // Icon colour follows the rarity tier for visual consistency.
        $col = [
            'common'    => '#94a3b8',
            'uncommon'  => '#22c55e',
            'rare'      => '#3b82f6',
            'epic'      => '#a855f7',
            'legendary' => '#f5b301',
            'mythic'    => '#f43f5e',
        ];
        $b = static function (string $name, string $desc, string $icon, string $cat, string $type, int $val, string $rarity, string $period = 'all_time') use ($col): array {
            return [
                'name'            => $name,
                'description'     => $desc,
                'icon'            => $icon,
                'icon_color'      => $col[$rarity] ?? '#94a3b8',
                'category'        => $cat,
                'criteria_type'   => $type,
                'criteria_value'  => $val,
                'criteria_period' => $period,
                'rarity'          => $rarity,
                'is_active'       => 1,
            ];
        };

        return [
            // ── Originais (mantidos com nome/valores idênticos) ──────────────
            $b('O Flash', 'Resolve 10 tickets de alta prioridade dentro do SLA na mesma semana', 'ti ti-bolt', 'speed', 'high_priority_sla_weekly', 10, 'epic', 'week'),
            $b('O Coruja', 'O técnico que mais resolveu tickets fora do horário comercial no mês', 'ti ti-moon-stars', 'dedication', 'after_hours_top_monthly', 1, 'rare', 'month'),
            $b('Mestre Jedi da Documentação', 'Criou 50 artigos para a Base de Conhecimento', 'ti ti-book', 'knowledge', 'kb_articles_total', 50, 'legendary'),
            $b('Escudo de Aço', 'Passou 30 dias sem ter nenhum ticket reaberto', 'ti ti-shield-check', 'quality', 'no_reopen_streak', 30, 'epic', 'rolling'),
            $b('Primeira Vitória', 'Resolveu seu primeiro ticket', 'ti ti-star', 'milestone', 'tickets_resolved_total', 1, 'common'),
            $b('Centurião', 'Resolveu 100 tickets', 'ti ti-award', 'milestone', 'tickets_resolved_total', 100, 'rare'),
            $b('Embaixador da Qualidade', 'Recebeu 20 avaliações de 5 estrelas', 'ti ti-hearts', 'quality', 'perfect_satisfaction_total', 20, 'epic'),
            $b('Velocista', 'Resolveu 50 tickets no primeiro contato (FCR)', 'ti ti-rocket', 'speed', 'fcr_total', 50, 'epic'),

            // ── Escada: Tickets resolvidos (milestone) ───────────────────────
            $b('Aprendiz de Suporte', 'Resolveu 10 tickets', 'ti ti-thumb-up', 'milestone', 'tickets_resolved_total', 10, 'uncommon'),
            $b('Atendente Dedicado', 'Resolveu 25 tickets', 'ti ti-headset', 'milestone', 'tickets_resolved_total', 25, 'uncommon'),
            $b('Meio Centurião', 'Resolveu 50 tickets', 'ti ti-medal', 'milestone', 'tickets_resolved_total', 50, 'rare'),
            $b('Veterano do Suporte', 'Resolveu 250 tickets', 'ti ti-military-rank', 'milestone', 'tickets_resolved_total', 250, 'epic'),
            $b('Mestre do Suporte', 'Resolveu 500 tickets', 'ti ti-crown', 'milestone', 'tickets_resolved_total', 500, 'legendary'),
            $b('Lenda do Help Desk', 'Resolveu 1000 tickets', 'ti ti-diamond', 'milestone', 'tickets_resolved_total', 1000, 'mythic'),

            // ── Escada: FCR / 1º contato (speed) ─────────────────────────────
            $b('Primeiro Acerto', 'Resolveu 1 ticket no primeiro contato', 'ti ti-target-arrow', 'speed', 'fcr_total', 1, 'common'),
            $b('Mão Rápida', 'Resolveu 10 tickets no primeiro contato', 'ti ti-hand-finger', 'speed', 'fcr_total', 10, 'uncommon'),
            $b('Resolvedor Ágil', 'Resolveu 25 tickets no primeiro contato', 'ti ti-bolt', 'speed', 'fcr_total', 25, 'rare'),
            $b('Relâmpago', 'Resolveu 100 tickets no primeiro contato', 'ti ti-flame', 'speed', 'fcr_total', 100, 'legendary'),
            $b('Velocidade da Luz', 'Resolveu 250 tickets no primeiro contato', 'ti ti-flare', 'speed', 'fcr_total', 250, 'mythic'),

            // ── Escada: Satisfação 5★ (quality) ──────────────────────────────
            $b('Primeiro Sorriso', 'Recebeu 1 avaliação de 5 estrelas', 'ti ti-mood-smile', 'quality', 'perfect_satisfaction_total', 1, 'common'),
            $b('Bem Avaliado', 'Recebeu 5 avaliações de 5 estrelas', 'ti ti-star', 'quality', 'perfect_satisfaction_total', 5, 'uncommon'),
            $b('Queridinho dos Usuários', 'Recebeu 10 avaliações de 5 estrelas', 'ti ti-heart', 'quality', 'perfect_satisfaction_total', 10, 'rare'),
            $b('Ídolo do Atendimento', 'Recebeu 50 avaliações de 5 estrelas', 'ti ti-stars', 'quality', 'perfect_satisfaction_total', 50, 'legendary'),
            $b('Lenda da Satisfação', 'Recebeu 100 avaliações de 5 estrelas', 'ti ti-sparkles', 'quality', 'perfect_satisfaction_total', 100, 'mythic'),

            // ── Escada: Base de Conhecimento (knowledge) ─────────────────────
            $b('Primeiro Artigo', 'Criou 1 artigo na Base de Conhecimento', 'ti ti-file-text', 'knowledge', 'kb_articles_total', 1, 'common'),
            $b('Escriba', 'Criou 5 artigos na Base de Conhecimento', 'ti ti-pencil', 'knowledge', 'kb_articles_total', 5, 'uncommon'),
            $b('Documentador', 'Criou 10 artigos na Base de Conhecimento', 'ti ti-notebook', 'knowledge', 'kb_articles_total', 10, 'rare'),
            $b('Bibliotecário', 'Criou 25 artigos na Base de Conhecimento', 'ti ti-books', 'knowledge', 'kb_articles_total', 25, 'epic'),
            $b('Oráculo do Conhecimento', 'Criou 100 artigos na Base de Conhecimento', 'ti ti-brain', 'knowledge', 'kb_articles_total', 100, 'mythic'),

            // ── Escada: Sem reabertura (quality) ─────────────────────────────
            $b('Trabalho Limpo', 'Sequência de 5 tickets sem reabertura', 'ti ti-circle-check', 'quality', 'no_reopen_streak', 5, 'common', 'rolling'),
            $b('Consistente', 'Sequência de 10 tickets sem reabertura', 'ti ti-shield', 'quality', 'no_reopen_streak', 10, 'uncommon', 'rolling'),
            $b('Fortaleza', 'Sequência de 60 tickets sem reabertura', 'ti ti-building-castle', 'quality', 'no_reopen_streak', 60, 'legendary', 'rolling'),
            $b('Inabalável', 'Sequência de 100 tickets sem reabertura', 'ti ti-shield-lock', 'quality', 'no_reopen_streak', 100, 'mythic', 'rolling'),

            // ── Escada: SLA cumprido (speed) ─────────────────────────────────
            $b('Pontual', 'Cumpriu o SLA em 1 ticket', 'ti ti-clock-check', 'speed', 'high_priority_sla_weekly', 1, 'common'),
            $b('Guardião do SLA', 'Cumpriu o SLA em 25 tickets', 'ti ti-clock-cog', 'speed', 'high_priority_sla_weekly', 25, 'epic'),
            $b('Senhor do Tempo', 'Cumpriu o SLA em 50 tickets', 'ti ti-hourglass-high', 'speed', 'high_priority_sla_weekly', 50, 'legendary'),
            $b('Mestre do SLA', 'Cumpriu o SLA em 100 tickets', 'ti ti-clock-bolt', 'speed', 'high_priority_sla_weekly', 100, 'mythic'),
        ];
    }

    /**
     * Insert any catalog badge whose name is not yet present. Idempotent:
     * safe to call on every install/upgrade. Returns number of badges inserted.
     */
    public static function seedMissing(): int
    {
        global $DB;
        $inserted = 0;
        foreach (self::catalog() as $badge) {
            if (countElementsInTable(self::$table, ['name' => $badge['name']]) === 0) {
                $DB->insert(self::$table, $badge);
                $inserted++;
            }
        }
        return $inserted;
    }

    public function showForm($id, array $options = []): bool
    {
        $this->initForm($id, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Name') . "</td>";
        echo "<td>";
        Html::autocompletionTextField($this, 'name');
        echo "</td>";
        echo "<td>" . __('Active') . "</td>";
        echo "<td>";
        \Dropdown::showYesNo('is_active', $this->fields['is_active']);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Description') . "</td>";
        echo "<td colspan='3'>";
        echo "<textarea name='description' cols='80' rows='3' class='form-control'>" . Html::cleanInputText($this->fields['description']) . "</textarea>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Icon', 'gamification') . "</td>";
        echo "<td>";
        Html::autocompletionTextField($this, 'icon', ['value' => $this->fields['icon'] ?: 'ti ti-trophy']);
        echo "</td>";
        echo "<td>" . __('Icon Color', 'gamification') . "</td>";
        echo "<td>";
        Html::showColorField('icon_color', ['value' => $this->fields['icon_color'] ?: '#FFD700']);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Category', 'gamification') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('category', [
            'general' => __('General', 'gamification'),
            'speed' => __('Speed', 'gamification'),
            'quality' => __('Quality', 'gamification'),
            'knowledge' => __('Knowledge', 'gamification'),
            'dedication' => __('Dedication', 'gamification'),
            'milestone' => __('Milestone', 'gamification')
        ], ['value' => $this->fields['category']]);
        echo "</td>";
        echo "<td>" . __('Rarity', 'gamification') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('rarity', [
            'common' => __('Common', 'gamification'),
            'uncommon' => __('Uncommon', 'gamification'),
            'rare' => __('Rare', 'gamification'),
            'epic' => __('Epic', 'gamification'),
            'legendary' => __('Legendary', 'gamification'),
            'mythic' => __('Mythic', 'gamification')
        ], ['value' => $this->fields['rarity']]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Criteria Type', 'gamification') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('criteria_type', [
            'high_priority_sla_weekly' => 'High Priority SLA (Weekly)',
            'after_hours_top_monthly' => 'After Hours Top (Monthly)',
            'kb_articles_total' => 'KB Articles (Total)',
            'no_reopen_streak' => 'No Reopen Streak',
            'tickets_resolved_total' => 'Tickets Resolved (Total)',
            'perfect_satisfaction_total' => 'Perfect Satisfaction (Total)',
            'fcr_total' => 'FCR (Total)'
        ], ['value' => $this->fields['criteria_type']]);
        echo "</td>";
        echo "<td>" . __('Criteria Value', 'gamification') . "</td>";
        echo "<td><input type='number' name='criteria_value' value='" . (int)$this->fields['criteria_value'] . "' class='form-control'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Criteria Period', 'gamification') . "</td>";
        echo "<td colspan='3'>";
        \Dropdown::showFromArray('criteria_period', [
            'all_time' => 'All Time',
            'rolling' => 'Rolling (Streak)',
            'week' => 'Weekly',
            'month' => 'Monthly'
        ], ['value' => $this->fields['criteria_period']]);
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);
        return true;
    }

    public function rawSearchOptions(): array
    {
        $tab = [];

        $tab[] = [
            'id'                 => 'common',
            'name'               => __('Characteristics')
        ];

        $tab[] = [
            'id'                 => '1',
            'table'              => $this->getTable(),
            'field'              => 'name',
            'name'               => __('Name'),
            'datatype'           => 'itemlink',
        ];

        $tab[] = [
            'id'                 => '2',
            'table'              => $this->getTable(),
            'field'              => 'description',
            'name'               => __('Description'),
            'datatype'           => 'text',
        ];

        $tab[] = [
            'id'                 => '3',
            'table'              => $this->getTable(),
            'field'              => 'category',
            'name'               => __('Category', 'gamification'),
            'datatype'           => 'string'
        ];
        
        $tab[] = [
            'id'                 => '4',
            'table'              => $this->getTable(),
            'field'              => 'rarity',
            'name'               => __('Rarity', 'gamification'),
            'datatype'           => 'string'
        ];

        $tab[] = [
            'id'                 => '86',
            'table'              => $this->getTable(),
            'field'              => 'is_active',
            'name'               => __('Active'),
            'datatype'           => 'bool'
        ];

        return $tab;
    }
}
