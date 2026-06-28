<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;

class BadgeUser extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_badgeusers';

    public static function getTypeName($nb = 0): string
    {
        return _n('Conquista de Usuário', 'Conquistas de Usuário', $nb, 'gamification');
    }

    public static function getForUser(int $users_id, ?int $entities_id = null): array
    {
        global $DB;
        $entities_id ??= Score::curEntity();
        $iterator = $DB->request([
            'SELECT' => [
                self::$table . '.*',
                'glpi_plugin_gamification_badges.name',
                'glpi_plugin_gamification_badges.description',
                'glpi_plugin_gamification_badges.icon',
                'glpi_plugin_gamification_badges.icon_color',
                'glpi_plugin_gamification_badges.category',
                'glpi_plugin_gamification_badges.rarity',
            ],
            'FROM'   => self::$table,
            'INNER JOIN' => [
                'glpi_plugin_gamification_badges' => [
                    'ON' => [
                        'glpi_plugin_gamification_badges' => 'id',
                        self::$table => 'badges_id'
                    ]
                ]
            ],
            'WHERE'  => [
                self::$table . '.users_id'    => $users_id,
                self::$table . '.entities_id' => $entities_id,
            ],
            'ORDER'  => self::$table . '.earned_date DESC'
        ]);

        $badges = [];
        foreach ($iterator as $row) {
            $badges[] = $row;
        }
        return $badges;
    }

    public static function hasEarned(int $badges_id, int $users_id, ?int $seasons_id = null, ?int $entities_id = null): bool
    {
        $entities_id ??= Score::curEntity();
        $where = [
            'badges_id'   => $badges_id,
            'users_id'    => $users_id,
            'entities_id' => $entities_id,
        ];

        if ($seasons_id) {
            $where['seasons_id'] = $seasons_id;
        } else {
            $where['seasons_id'] = null; // Assuming null means all-time badge
        }

        return countElementsInTable(self::$table, $where) > 0;
    }

    public static function award(int $badges_id, int $users_id, ?int $seasons_id = null, ?int $entities_id = null): bool
    {
        $entities_id ??= Score::curEntity();
        if (self::hasEarned($badges_id, $users_id, $seasons_id, $entities_id)) {
            return false;
        }

        global $DB;
        $DB->insert(self::$table, [
            'badges_id'   => $badges_id,
            'users_id'    => $users_id,
            'entities_id' => $entities_id,
            'seasons_id'  => $seasons_id,
            'earned_date' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ]);

        // Try to get badge details for logging/notification
        $badge = $DB->request(['FROM' => 'glpi_plugin_gamification_badges', 'WHERE' => ['id' => $badges_id]])->current();
        if ($badge) {
            XPTransaction::log($users_id, 0, Score::getOrCreate($users_id, $entities_id)['xp_available'], 'badge_earned', 'glpi_plugin_gamification_badges', $badges_id, sprintf(__('Conquistou: %s', 'gamification'), $badge['name']), $entities_id);
        }

        return true;
    }
}
