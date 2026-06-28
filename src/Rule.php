<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Html;
use Session;

class Rule extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_rules';
    public static $rightname = 'plugin_gamification_admin';
    public $dohistory = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Regra de Gamificação', 'Regras de Gamificação', $nb, 'gamification');
    }

    public static function getActiveRules(): array
    {
        global $DB;
        $iterator = $DB->request([
            'FROM'   => self::$table,
            'WHERE'  => ['is_active' => 1]
        ]);

        $rules = [];
        foreach ($iterator as $row) {
            $rules[] = $row;
        }
        return $rules;
    }

    public static function getRuleForEvent(string $event_type): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'   => self::$table,
            'WHERE'  => [
                'event_type' => $event_type,
                'is_active'  => 1
            ]
        ])->current();

        return $row ?: null;
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
        echo "<textarea name='description' cols='80' rows='4' class='form-control'>" . Html::cleanInputText($this->fields['description']) . "</textarea>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Event Type', 'gamification') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('event_type', [
            'ticket_resolved_fcr' => __('First Contact Resolution', 'gamification'),
            'sla_met' => __('SLA Met', 'gamification'),
            'satisfaction_max' => __('5-Star Satisfaction', 'gamification'),
            'satisfaction_good' => __('4-Star Satisfaction', 'gamification'),
            'kb_article_created' => __('KB Article Created', 'gamification'),
            'ticket_reopened' => __('Ticket Reopened (Penalty)', 'gamification'),
            'ticket_resolved' => __('Ticket Resolved', 'gamification')
        ], ['value' => $this->fields['event_type']]);
        echo "</td>";
        echo "<td>" . __('XP Value', 'gamification') . "</td>";
        echo "<td><input type='number' name='xp_value' value='" . (int)$this->fields['xp_value'] . "' class='form-control'></td>";
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
            'massiveaction'      => false
        ];

        $tab[] = [
            'id'                 => '2',
            'table'              => $this->getTable(),
            'field'              => 'description',
            'name'               => __('Description'),
            'datatype'           => 'text',
            'massiveaction'      => false
        ];

        $tab[] = [
            'id'                 => '3',
            'table'              => $this->getTable(),
            'field'              => 'event_type',
            'name'               => __('Event Type', 'gamification'),
            'datatype'           => 'string'
        ];

        $tab[] = [
            'id'                 => '4',
            'table'              => $this->getTable(),
            'field'              => 'xp_value',
            'name'               => __('XP Value', 'gamification'),
            'datatype'           => 'integer'
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
