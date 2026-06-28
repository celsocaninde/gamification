<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Html;

class Season extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_seasons';
    public static $rightname = 'plugin_gamification_admin';

    public static function getTypeName($nb = 0): string
    {
        return _n('Temporada', 'Temporadas', $nb, 'gamification');
    }

    public static function getActiveSeason(): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::$table,
            'WHERE' => ['is_active' => 1],
            'ORDER' => 'id DESC',
            'LIMIT' => 1
        ])->current();

        return $row ?: null;
    }

    public static function closeSeason(int $season_id): void
    {
        global $DB;
        
        $DB->update(self::$table, [
            'is_active'   => 0,
            'is_archived' => 1
        ], ['id' => $season_id]);

        // Final rank per entity (leaderboard rows are per user + entity)
        $rows = $DB->request([
            'FROM'  => Leaderboard::$table,
            'WHERE' => ['seasons_id' => $season_id],
            'ORDER' => ['entities_id ASC', 'xp_earned DESC'],
        ]);
        $rank = 0;
        $cur_entity = null;
        foreach ($rows as $r) {
            if ($r['entities_id'] !== $cur_entity) {
                $cur_entity = $r['entities_id'];
                $rank = 0;
            }
            $rank++;
            $DB->update(Leaderboard::$table, ['rank_position' => $rank], ['id' => $r['id']]);
        }

        // Reset xp_season for all users
        $DB->update(Score::$table, ['xp_season' => 0], [new \Glpi\DBAL\QueryExpression('1=1')]);
    }

    public static function openNewSeason(?string $name = null): int
    {
        global $DB;
        
        $duration = Config::getConfig('season_duration') ?: 'monthly';
        
        $now = new \DateTime();
        
        if ($duration === 'quarterly') {
            $end = (clone $now)->modify('last day of +2 months');
            $default_name = 'Q' . ceil($now->format('n') / 3) . ' ' . $now->format('Y');
        } else {
            $end = (clone $now)->modify('last day of this month');
            $default_name = $now->format('F Y');
        }

        $DB->insert(self::$table, [
            'name'       => $name ?: $default_name,
            'date_start' => $now->format('Y-m-d'),
            'date_end'   => $end->format('Y-m-d'),
            'is_active'  => 1
        ]);
        
        return $DB->insertId();
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
        echo "<td>" . __('Start Date', 'gamification') . "</td>";
        echo "<td>";
        Html::showDateField('date_start', ['value' => $this->fields['date_start']]);
        echo "</td>";
        echo "<td>" . __('End Date', 'gamification') . "</td>";
        echo "<td>";
        Html::showDateField('date_end', ['value' => $this->fields['date_end']]);
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
            'field'              => 'date_start',
            'name'               => __('Start Date', 'gamification'),
            'datatype'           => 'date'
        ];

        $tab[] = [
            'id'                 => '3',
            'table'              => $this->getTable(),
            'field'              => 'date_end',
            'name'               => __('End Date', 'gamification'),
            'datatype'           => 'date'
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
