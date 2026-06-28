<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Html;

class Reward extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_rewards';
    public static $rightname = 'plugin_gamification_admin';

    public static function getTypeName($nb = 0): string
    {
        return _n('Recompensa', 'Recompensas', $nb, 'gamification');
    }

    public static function getAvailable(): array
    {
        global $DB;
        $iterator = $DB->request([
            'FROM'   => self::$table,
            'WHERE'  => [
                'is_active' => 1,
                'OR' => [
                    'stock' => -1,
                    'stock' => ['>', 0]
                ]
            ],
            'ORDER'  => 'xp_cost ASC'
        ]);

        $rewards = [];
        foreach ($iterator as $row) {
            $rewards[] = $row;
        }
        return $rewards;
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
        echo "<td>" . __('XP Cost', 'gamification') . "</td>";
        echo "<td><input type='number' name='xp_cost' value='" . (int)$this->fields['xp_cost'] . "' class='form-control' min='0'></td>";
        echo "<td>" . __('Stock', 'gamification') . "</td>";
        echo "<td><input type='number' name='stock' value='" . (int)$this->fields['stock'] . "' class='form-control'> <small class='text-muted'>-1 for unlimited</small></td>";
        echo "</tr>";
        
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Category', 'gamification') . "</td>";
        echo "<td colspan='3'>";
        \Dropdown::showFromArray('category', [
            'gadgets' => __('Gadgets', 'gamification'),
            'time_off' => __('Time Off', 'gamification'),
            'entertainment' => __('Entertainment', 'gamification'),
            'education' => __('Education', 'gamification'),
            'other' => __('Other', 'gamification')
        ], ['value' => $this->fields['category']]);
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
            'field'              => 'xp_cost',
            'name'               => __('XP Cost', 'gamification'),
            'datatype'           => 'integer'
        ];

        $tab[] = [
            'id'                 => '4',
            'table'              => $this->getTable(),
            'field'              => 'stock',
            'name'               => __('Stock', 'gamification'),
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
