<?php

namespace GlpiPlugin\Gamification;

use CommonDBTM;
use Html;

class RewardOrder extends CommonDBTM
{
    public static $table = 'glpi_plugin_gamification_rewardorders';
    public static $rightname = 'plugin_gamification_admin';

    public static function getTypeName($nb = 0): string
    {
        return _n('Pedido de Resgate', 'Pedidos de Resgate', $nb, 'gamification');
    }

    public static function redeem(int $users_id, int $rewards_id): array
    {
        global $DB;

        // Check if shop is enabled
        if (!Config::getConfig('enable_rewards_shop')) {
            return ['success' => false, 'message' => __('A loja de recompensas está desativada.', 'gamification')];
        }

        $reward = $DB->request(['FROM' => 'glpi_plugin_gamification_rewards', 'WHERE' => ['id' => $rewards_id]])->current();
        
        if (!$reward || !$reward['is_active']) {
            return ['success' => false, 'message' => __('Recompensa indisponível.', 'gamification')];
        }

        if ($reward['stock'] == 0) {
            return ['success' => false, 'message' => __('Recompensa sem estoque.', 'gamification')];
        }

        $entities_id = Score::curEntity();
        if (Score::spendXP($users_id, $reward['xp_cost'], $entities_id)) {
            // Create order
            $DB->insert(self::$table, [
                'users_id'   => $users_id,
                'entities_id' => $entities_id,
                'rewards_id' => $rewards_id,
                'xp_spent'   => $reward['xp_cost'],
                'status'     => 'pending',
                'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
            ]);

            // Decrement stock
            if ($reward['stock'] > 0) {
                $DB->update('glpi_plugin_gamification_rewards', [
                    'stock' => $reward['stock'] - 1
                ], ['id' => $rewards_id]);
            }

            return ['success' => true, 'message' => __('Resgate realizado com sucesso! Aguarde aprovação.', 'gamification')];
        }

        return ['success' => false, 'message' => __('XP insuficiente.', 'gamification')];
    }

    public static function getForUser(int $users_id, ?int $entities_id = null): array
    {
        global $DB;
        $entities_id ??= Score::curEntity();
        $iterator = $DB->request([
            'SELECT' => [
                self::$table . '.*',
                'glpi_plugin_gamification_rewards.name AS reward_name',
                'glpi_plugin_gamification_rewards.category AS reward_category'
            ],
            'FROM'   => self::$table,
            'INNER JOIN' => [
                'glpi_plugin_gamification_rewards' => [
                    'ON' => [
                        'glpi_plugin_gamification_rewards' => 'id',
                        self::$table => 'rewards_id'
                    ]
                ]
            ],
            'WHERE'  => [
                self::$table . '.users_id'    => $users_id,
                self::$table . '.entities_id' => $entities_id,
            ],
            'ORDER'  => self::$table . '.date_creation DESC'
        ]);

        $orders = [];
        foreach ($iterator as $row) {
            $orders[] = $row;
        }
        return $orders;
    }

    public static function getPending(): array
    {
        global $DB;
        $iterator = $DB->request([
            'SELECT' => [
                self::$table . '.*',
                'glpi_users.firstname',
                'glpi_users.realname',
                'glpi_plugin_gamification_rewards.name AS reward_name'
            ],
            'FROM'   => self::$table,
            'INNER JOIN' => [
                'glpi_plugin_gamification_rewards' => [
                    'ON' => [
                        'glpi_plugin_gamification_rewards' => 'id',
                        self::$table => 'rewards_id'
                    ]
                ],
                'glpi_users' => [
                    'ON' => [
                        'glpi_users' => 'id',
                        self::$table => 'users_id'
                    ]
                ]
            ],
            'WHERE'  => [self::$table . '.status' => 'pending'],
            'ORDER'  => self::$table . '.date_creation ASC'
        ]);

        $orders = [];
        foreach ($iterator as $row) {
            $orders[] = $row;
        }
        return $orders;
    }

    public static function approve(int $order_id, ?string $notes = null): bool
    {
        global $DB;
        return $DB->update(self::$table, [
            'status' => 'approved',
            'admin_notes' => $notes,
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ], ['id' => $order_id]);
    }

    public static function reject(int $order_id, ?string $notes = null): bool
    {
        global $DB;
        $order = $DB->request(['FROM' => self::$table, 'WHERE' => ['id' => $order_id]])->current();
        
        if (!$order || $order['status'] != 'pending') {
            return false;
        }

        // Refund XP (to the entity where it was spent)
        $order_entity = (int) ($order['entities_id'] ?? 0);
        $score = Score::getOrCreate($order['users_id'], $order_entity);
        $new_xp_available = $score['xp_available'] + $order['xp_spent'];

        $DB->update(Score::$table, [
            'xp_available' => $new_xp_available
        ], ['users_id' => $order['users_id'], 'entities_id' => $order_entity]);

        XPTransaction::log($order['users_id'], $order['xp_spent'], $new_xp_available, 'refund', null, null, __('Reembolso de pedido rejeitado', 'gamification'), $order_entity);

        // Restore stock
        $reward = clone $DB->request(['FROM' => 'glpi_plugin_gamification_rewards', 'WHERE' => ['id' => $order['rewards_id']]])->current();
        if ($reward && $reward['stock'] >= 0) {
            $DB->update('glpi_plugin_gamification_rewards', [
                'stock' => $reward['stock'] + 1
            ], ['id' => $order['rewards_id']]);
        }

        return $DB->update(self::$table, [
            'status' => 'rejected',
            'admin_notes' => $notes,
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        ], ['id' => $order_id]);
    }
}
