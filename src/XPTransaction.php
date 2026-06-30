<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Html;

class XPTransaction extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_xptransactions';

    public static function getTypeName($nb = 0): string
    {
        return _n('Transação de XP', 'Transações de XP', $nb, 'gamification');
    }

    public static function log(int $users_id, int $xp_amount, int $xp_balance_after, string $event_type, ?string $source_itemtype = null, ?int $source_items_id = null, string $description = '', ?int $entities_id = null): void
    {
        global $DB;
        $entities_id ??= Score::curEntity();
        $DB->insert(self::$table, [
            'users_id'         => $users_id,
            'entities_id'      => $entities_id,
            'xp_amount'        => $xp_amount,
            'xp_balance_after' => $xp_balance_after,
            'event_type'       => $event_type,
            'source_itemtype'  => $source_itemtype,
            'source_items_id'  => $source_items_id,
            'description'      => $description,
            'date_creation'    => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ]);
    }

    public static function getForUser(int $users_id, int $limit = 50, ?int $entities_id = null): array
    {
        global $DB;
        $entities_id ??= Score::curEntity();
        $iterator = $DB->request([
            'FROM'   => self::$table,
            'WHERE'  => ['users_id' => $users_id, 'entities_id' => $entities_id],
            'ORDER'  => 'date_creation DESC',
            'LIMIT'  => $limit
        ]);

        $transactions = [];
        foreach ($iterator as $row) {
            $transactions[] = $row;
        }
        return $transactions;
    }

    public static function getRecent(int $limit = 20): array
    {
        global $DB;
        $iterator = $DB->request([
            'SELECT' => [
                self::$table . '.*',
                'glpi_users.firstname',
                'glpi_users.realname'
            ],
            'FROM'   => self::$table,
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_users' => 'id',
                        self::$table => 'users_id'
                    ]
                ]
            ],
            'ORDER'  => self::$table . '.date_creation DESC',
            'LIMIT'  => $limit
        ]);

        $transactions = [];
        foreach ($iterator as $row) {
            $transactions[] = $row;
        }
        return $transactions;
    }

    public static function getByPeriod(int $users_id, string $start, string $end, ?int $entities_id = null): array
    {
        global $DB;
        $entities_id ??= Score::curEntity();
        $iterator = $DB->request([
            'FROM'   => self::$table,
            'WHERE'  => [
                'users_id'      => $users_id,
                'entities_id'   => $entities_id,
                ['date_creation' => ['>=', $start]],
                ['date_creation' => ['<=', $end]],
            ],
            'ORDER'  => 'date_creation ASC'
        ]);

        $transactions = [];
        foreach ($iterator as $row) {
            $transactions[] = $row;
        }
        return $transactions;
    }

    public static function getForUserByEvent(int $users_id, string $event_type, ?int $entities_id = null, int $limit = 200): array
    {
        global $DB;
        $entities_id ??= Score::curEntity();
        $iterator = $DB->request([
            'FROM'  => self::$table,
            'WHERE' => ['users_id' => $users_id, 'entities_id' => $entities_id, 'event_type' => $event_type],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => $limit,
        ]);
        return iterator_to_array($iterator);
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
            'field'              => 'description',
            'name'               => __('Description'),
            'datatype'           => 'text'
        ];

        $tab[] = [
            'id'                 => '2',
            'table'              => $this->getTable(),
            'field'              => 'xp_amount',
            'name'               => __('XP Amount', 'gamification'),
            'datatype'           => 'integer'
        ];

        $tab[] = [
            'id'                 => '3',
            'table'              => 'glpi_users',
            'field'              => 'name',
            'name'               => __('User'),
            'datatype'           => 'itemlink'
        ];

        $tab[] = [
            'id'                 => '4',
            'table'              => $this->getTable(),
            'field'              => 'event_type',
            'name'               => __('Event Type', 'gamification'),
            'datatype'           => 'string'
        ];

        $tab[] = [
            'id'                 => '5',
            'table'              => $this->getTable(),
            'field'              => 'date_creation',
            'name'               => __('Date'),
            'datatype'           => 'datetime'
        ];

        return $tab;
    }
}
