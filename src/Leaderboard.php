<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;

class Leaderboard extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_leaderboard';

    public static function getRanking(?int $seasons_id = null, ?int $groups_id = null, int $limit = 50, ?int $entities_id = null): array
    {
        global $DB;

        if (!$seasons_id) {
            $active = Season::getActiveSeason();
            if (!$active) {
                return [];
            }
            $seasons_id = $active['id'];
        }
        $entities_id ??= Score::curEntity();

        $where = [
            self::$table . '.seasons_id'  => $seasons_id,
            self::$table . '.entities_id' => $entities_id,
        ];
        $join = [];

        $join['glpi_users'] = [
            'ON' => [
                'glpi_users' => 'id',
                self::$table => 'users_id'
            ]
        ];

        $join['glpi_plugin_gamification_scores'] = [
            'ON' => [
                'glpi_plugin_gamification_scores' => 'users_id',
                self::$table => 'users_id',
                ['AND' => ['glpi_plugin_gamification_scores.entities_id' => new \Glpi\DBAL\QueryExpression($DB->quoteName(self::$table . '.entities_id'))]],
            ]
        ];

        if ($groups_id) {
            $join['glpi_groups_users'] = [
                'ON' => [
                    'glpi_groups_users' => 'users_id',
                    self::$table => 'users_id'
                ]
            ];
            $where['glpi_groups_users.groups_id'] = $groups_id;
        }

        $where['glpi_users.is_deleted'] = 0;
        $where['glpi_users.is_active'] = 1;

        $iterator = $DB->request([
            'SELECT' => [
                self::$table . '.*',
                'glpi_users.firstname',
                'glpi_users.realname',
                'glpi_users.picture',
                'glpi_plugin_gamification_scores.level',
                'glpi_plugin_gamification_scores.xp_total'
            ],
            'FROM'   => self::$table,
            'LEFT JOIN' => $join,
            'WHERE'  => $where,
            'ORDER'  => self::$table . '.xp_earned DESC',
            'LIMIT'  => $limit
        ]);

        $ranking = [];
        $rank = 1;
        foreach ($iterator as $row) {
            // Include dynamic rank since rank_position is only final on season close
            $row['dynamic_rank'] = $rank++;
            
            // Get badge count
            $row['badges_count'] = countElementsInTable('glpi_plugin_gamification_badgeusers', ['users_id' => $row['users_id'], 'entities_id' => $entities_id]);
            
            $ranking[] = $row;
        }

        return $ranking;
    }

    /**
     * Rank groups/teams by the total season XP earned by their members.
     *
     * @return array<int,array> rows with groups_id, group_name, total_xp, members, dynamic_rank
     */
    public static function getTeamRanking(?int $seasons_id = null, int $limit = 50, ?int $entities_id = null): array
    {
        global $DB;

        if (!$seasons_id) {
            $active = Season::getActiveSeason();
            if (!$active) {
                return [];
            }
            $seasons_id = $active['id'];
        }
        $entities_id ??= Score::curEntity();

        $qn = fn(string $n): string => $DB->quoteName($n);

        // Correlated subquery: distinct badges earned by any member of the group.
        // Done as a subquery (not a JOIN) so it doesn't fan out the SUM(xp_earned) above.
        $badges_count_expr = new \Glpi\DBAL\QueryExpression(
            '(SELECT COUNT(DISTINCT `bu`.`badges_id`) FROM `glpi_plugin_gamification_badgeusers` `bu` '
            . 'INNER JOIN `glpi_groups_users` `bgu` ON `bgu`.`users_id` = `bu`.`users_id` '
            . 'WHERE `bgu`.`groups_id` = `glpi_groups`.`id` AND `bu`.`entities_id` = ' . (int) $entities_id
            . ') AS ' . $qn('badges_count')
        );

        $iterator = $DB->request([
            'SELECT' => [
                new \Glpi\DBAL\QueryExpression($qn('glpi_groups.id') . ' AS ' . $qn('groups_id')),
                new \Glpi\DBAL\QueryExpression($qn('glpi_groups.name') . ' AS ' . $qn('group_name')),
                new \Glpi\DBAL\QueryExpression('SUM(' . $qn(self::$table . '.xp_earned') . ') AS ' . $qn('total_xp')),
                new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $qn(self::$table . '.users_id') . ') AS ' . $qn('members')),
                $badges_count_expr,
            ],
            'FROM'       => self::$table,
            'INNER JOIN' => [
                'glpi_groups_users' => ['ON' => ['glpi_groups_users' => 'users_id', self::$table => 'users_id']],
                'glpi_groups'       => ['ON' => ['glpi_groups' => 'id', 'glpi_groups_users' => 'groups_id']],
                'glpi_users'        => ['ON' => ['glpi_users' => 'id', self::$table => 'users_id']],
            ],
            'WHERE'   => [
                self::$table . '.seasons_id'  => $seasons_id,
                self::$table . '.entities_id' => $entities_id,
                'glpi_users.is_deleted'       => 0,
                'glpi_users.is_active'        => 1,
            ],
            'GROUPBY' => ['glpi_groups.id'],
            'ORDER'   => 'total_xp DESC',
            'LIMIT'   => $limit,
        ]);

        $rows = [];
        $rank = 1;
        foreach ($iterator as $row) {
            $row['dynamic_rank'] = $rank++;
            // Group level: same level curve as individual users, fed by the group's summed XP.
            $row['level'] = Score::calculateLevel((int) $row['total_xp']);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Distinct badges earned by any member of a group (union, not duplicated per member).
     *
     * @return array<int,array> rows with id, name, icon, icon_color, rarity, earners (how many members earned it)
     */
    public static function getTeamBadges(int $groups_id, ?int $entities_id = null, int $limit = 12): array
    {
        global $DB;
        $entities_id ??= Score::curEntity();

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_gamification_badges.id',
                'glpi_plugin_gamification_badges.name',
                'glpi_plugin_gamification_badges.icon',
                'glpi_plugin_gamification_badges.icon_color',
                'glpi_plugin_gamification_badges.rarity',
                new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT `glpi_plugin_gamification_badgeusers`.`users_id`) AS `earners`'),
            ],
            'FROM'       => 'glpi_plugin_gamification_badgeusers',
            'INNER JOIN' => [
                'glpi_groups_users' => [
                    'ON' => ['glpi_groups_users' => 'users_id', 'glpi_plugin_gamification_badgeusers' => 'users_id'],
                ],
                'glpi_plugin_gamification_badges' => [
                    'ON' => ['glpi_plugin_gamification_badges' => 'id', 'glpi_plugin_gamification_badgeusers' => 'badges_id'],
                ],
            ],
            'WHERE'   => [
                'glpi_groups_users.groups_id'                => $groups_id,
                'glpi_plugin_gamification_badgeusers.entities_id' => $entities_id,
            ],
            'GROUPBY' => ['glpi_plugin_gamification_badges.id'],
            'ORDER'   => 'earners DESC',
            'LIMIT'   => $limit,
        ]);

        $badges = [];
        foreach ($iterator as $row) {
            $badges[] = $row;
        }
        return $badges;
    }

    public static function updateEntry(int $users_id, int $xp_amount, int $seasons_id, ?int $entities_id = null): void
    {
        global $DB;
        $entities_id ??= Score::curEntity();
        $exists = countElementsInTable(self::$table, [
            'users_id'    => $users_id,
            'seasons_id'  => $seasons_id,
            'entities_id' => $entities_id
        ]);

        if ($exists) {
            $DB->update(self::$table, [
                'xp_earned' => $xp_amount,
                'date_mod'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
            ], [
                'users_id'    => $users_id,
                'seasons_id'  => $seasons_id,
                'entities_id' => $entities_id
            ]);
        } else {
            $DB->insert(self::$table, [
                'users_id'    => $users_id,
                'seasons_id'  => $seasons_id,
                'entities_id' => $entities_id,
                'xp_earned'   => $xp_amount
            ]);
        }
    }

    public static function getPositionForUser(int $users_id, ?int $seasons_id = null, ?int $entities_id = null): ?int
    {
        global $DB;

        if (!$seasons_id) {
            $active = Season::getActiveSeason();
            if (!$active) return null;
            $seasons_id = $active['id'];
        }
        $entities_id ??= Score::curEntity();

        $row = $DB->request([
            'SELECT' => 'xp_earned',
            'FROM'   => self::$table,
            'WHERE'  => [
                'users_id'    => $users_id,
                'seasons_id'  => $seasons_id,
                'entities_id' => $entities_id
            ]
        ])->current();

        if (!$row) return null;

        $my_xp = $row['xp_earned'];

        // Count how many people have MORE xp (same season + entity)
        $higher = countElementsInTable(self::$table, [
            'seasons_id'  => $seasons_id,
            'entities_id' => $entities_id,
            'xp_earned'   => ['>', $my_xp]
        ]);

        return $higher + 1;
    }
}
