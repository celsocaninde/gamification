<?php

/**
 * -------------------------------------------------------------------------
 * Gamificação plugin for GLPI — Install / Uninstall
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2024-2026 by Gamificação plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

/**
 * Install the plugin — create tables, seed default data, register rights.
 */
function plugin_gamification_install(): bool
{
    global $DB;

    $charset = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 1: Configurations
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_configs` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key_name`    VARCHAR(255) NOT NULL,
            `value`       TEXT,
            `date_mod`    TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `key_name` (`key_name`)
        ) $charset");

        // Seed default config
        $defaults = [
            ['key_name' => 'business_hours_start',  'value' => '08:00'],
            ['key_name' => 'business_hours_end',     'value' => '18:00'],
            ['key_name' => 'business_days',          'value' => '1,2,3,4,5'], // Mon-Fri
            ['key_name' => 'season_duration',        'value' => 'monthly'],   // monthly | quarterly
            ['key_name' => 'fcr_max_minutes',        'value' => '60'],
            ['key_name' => 'enable_penalties',        'value' => '1'],
            ['key_name' => 'enable_rewards_shop',     'value' => '1'],
            ['key_name' => 'xp_per_level_base',       'value' => '100'],
            ['key_name' => 'plugin_enabled',           'value' => '1'],
            ['key_name' => 'enable_streak_bonus',      'value' => '0'],
            ['key_name' => 'streak_bonus_step',        'value' => '5'],
            ['key_name' => 'streak_bonus_pct',         'value' => '5'],
            ['key_name' => 'streak_bonus_cap',         'value' => '50'],
        ];
        foreach ($defaults as $row) {
            $DB->insert('glpi_plugin_gamification_configs', $row);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 2: XP Rules (configurable scoring rules)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_rules')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_rules` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(255) NOT NULL,
            `description`    TEXT,
            `event_type`     VARCHAR(100) NOT NULL,
            `xp_value`       INT NOT NULL DEFAULT 0,
            `conditions`     JSON DEFAULT NULL,
            `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
            `date_creation`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `date_mod`       TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `event_type` (`event_type`),
            KEY `is_active`  (`is_active`)
        ) $charset");

        // Seed default rules
        $rules = [
            [
                'name'        => 'Resolução no 1º Contato (FCR)',
                'description' => 'Ticket aberto e resolvido em menos de 1 hora sem escalonamento',
                'event_type'  => 'ticket_resolved_fcr',
                'xp_value'    => 50,
                'is_active'   => 1,
            ],
            [
                'name'        => 'Cumprimento de SLA',
                'description' => 'Ticket resolvido antes do tempo limite de solução',
                'event_type'  => 'sla_met',
                'xp_value'    => 20,
                'is_active'   => 1,
            ],
            [
                'name'        => 'Pesquisa de Satisfação 5 Estrelas',
                'description' => 'Receber nota máxima (5 estrelas) do usuário final',
                'event_type'  => 'satisfaction_max',
                'xp_value'    => 100,
                'is_active'   => 1,
            ],
            [
                'name'        => 'Artigo na Base de Conhecimento',
                'description' => 'Criar um artigo de FAQ aprovado e publicado',
                'event_type'  => 'kb_article_created',
                'xp_value'    => 80,
                'is_active'   => 1,
            ],
            [
                'name'        => 'Penalidade: Ticket Reaberto',
                'description' => 'Ticket reaberto pelo usuário por falha na solução',
                'event_type'  => 'ticket_reopened',
                'xp_value'    => -30,
                'is_active'   => 1,
            ],
            [
                'name'        => 'Ticket Resolvido',
                'description' => 'Qualquer ticket resolvido pelo técnico',
                'event_type'  => 'ticket_resolved',
                'xp_value'    => 10,
                'is_active'   => 1,
            ],
            [
                'name'        => 'Pesquisa de Satisfação 4 Estrelas',
                'description' => 'Receber nota 4 estrelas do usuário final',
                'event_type'  => 'satisfaction_good',
                'xp_value'    => 40,
                'is_active'   => 1,
            ],
        ];
        foreach ($rules as $rule) {
            $DB->insert('glpi_plugin_gamification_rules', $rule);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 3: User Scores (aggregate per user)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_scores')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_scores` (
            `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`              INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `xp_total`              INT NOT NULL DEFAULT 0,
            `xp_available`          INT NOT NULL DEFAULT 0,
            `xp_season`             INT NOT NULL DEFAULT 0,
            `level`                 INT NOT NULL DEFAULT 1,
            `tickets_resolved`      INT NOT NULL DEFAULT 0,
            `fcr_count`             INT NOT NULL DEFAULT 0,
            `sla_met_count`         INT NOT NULL DEFAULT 0,
            `perfect_satisfaction`  INT NOT NULL DEFAULT 0,
            `kb_articles`           INT NOT NULL DEFAULT 0,
            `current_streak`        INT NOT NULL DEFAULT 0,
            `best_streak`           INT NOT NULL DEFAULT 0,
            `date_mod`              TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            UNIQUE KEY `users_entity` (`users_id`, `entities_id`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 4: XP Transactions (immutable audit log)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_xptransactions')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_xptransactions` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`          INT UNSIGNED NOT NULL,
            `entities_id`       INT UNSIGNED NOT NULL DEFAULT 0,
            `xp_amount`         INT NOT NULL,
            `xp_balance_after`  INT NOT NULL DEFAULT 0,
            `event_type`        VARCHAR(100) NOT NULL,
            `source_itemtype`   VARCHAR(100) DEFAULT NULL,
            `source_items_id`   INT UNSIGNED DEFAULT NULL,
            `description`       TEXT,
            `notified`          TINYINT(1) NOT NULL DEFAULT 0,
            `date_creation`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id`       (`users_id`),
            KEY `entities_id`    (`entities_id`),
            KEY `event_type`     (`event_type`),
            KEY `notified`       (`notified`),
            KEY `date_creation`  (`date_creation`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 5: Badge Definitions
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_badges')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_badges` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`             VARCHAR(255) NOT NULL,
            `description`      TEXT,
            `icon`             VARCHAR(100) NOT NULL DEFAULT 'ti ti-trophy',
            `icon_color`       VARCHAR(20) DEFAULT '#FFD700',
            `category`         VARCHAR(50) DEFAULT 'general',
            `criteria_type`    VARCHAR(100) NOT NULL,
            `criteria_value`   INT NOT NULL DEFAULT 0,
            `criteria_period`  VARCHAR(20) DEFAULT 'all_time',
            `rarity`           VARCHAR(20) DEFAULT 'common',
            `is_active`        TINYINT(1) DEFAULT 1,
            `date_creation`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $charset");

        // Seed default badges
        $badges = [
            [
                'name'            => 'O Flash',
                'description'     => 'Resolve 10 tickets de alta prioridade dentro do SLA na mesma semana',
                'icon'            => 'ti ti-bolt',
                'icon_color'      => '#FFD700',
                'category'        => 'speed',
                'criteria_type'   => 'high_priority_sla_weekly',
                'criteria_value'  => 10,
                'criteria_period' => 'week',
                'rarity'          => 'epic',
                'is_active'       => 1,
            ],
            [
                'name'            => 'O Coruja',
                'description'     => 'O técnico que mais resolveu tickets fora do horário comercial no mês',
                'icon'            => 'ti ti-moon-stars',
                'icon_color'      => '#7C3AED',
                'category'        => 'dedication',
                'criteria_type'   => 'after_hours_top_monthly',
                'criteria_value'  => 1,
                'criteria_period' => 'month',
                'rarity'          => 'rare',
                'is_active'       => 1,
            ],
            [
                'name'            => 'Mestre Jedi da Documentação',
                'description'     => 'Criou 50 artigos para a Base de Conhecimento',
                'icon'            => 'ti ti-book',
                'icon_color'      => '#059669',
                'category'        => 'knowledge',
                'criteria_type'   => 'kb_articles_total',
                'criteria_value'  => 50,
                'criteria_period' => 'all_time',
                'rarity'          => 'legendary',
                'is_active'       => 1,
            ],
            [
                'name'            => 'Escudo de Aço',
                'description'     => 'Passou 30 dias sem ter nenhum ticket reaberto',
                'icon'            => 'ti ti-shield-check',
                'icon_color'      => '#2563EB',
                'category'        => 'quality',
                'criteria_type'   => 'no_reopen_streak',
                'criteria_value'  => 30,
                'criteria_period' => 'rolling',
                'rarity'          => 'epic',
                'is_active'       => 1,
            ],
            [
                'name'            => 'Primeira Vitória',
                'description'     => 'Resolveu seu primeiro ticket',
                'icon'            => 'ti ti-star',
                'icon_color'      => '#F59E0B',
                'category'        => 'milestone',
                'criteria_type'   => 'tickets_resolved_total',
                'criteria_value'  => 1,
                'criteria_period' => 'all_time',
                'rarity'          => 'common',
                'is_active'       => 1,
            ],
            [
                'name'            => 'Centurião',
                'description'     => 'Resolveu 100 tickets',
                'icon'            => 'ti ti-award',
                'icon_color'      => '#DC2626',
                'category'        => 'milestone',
                'criteria_type'   => 'tickets_resolved_total',
                'criteria_value'  => 100,
                'criteria_period' => 'all_time',
                'rarity'          => 'rare',
                'is_active'       => 1,
            ],
            [
                'name'            => 'Embaixador da Qualidade',
                'description'     => 'Recebeu 20 avaliações de 5 estrelas',
                'icon'            => 'ti ti-hearts',
                'icon_color'      => '#EC4899',
                'category'        => 'quality',
                'criteria_type'   => 'perfect_satisfaction_total',
                'criteria_value'  => 20,
                'criteria_period' => 'all_time',
                'rarity'          => 'epic',
                'is_active'       => 1,
            ],
            [
                'name'            => 'Velocista',
                'description'     => 'Resolveu 50 tickets no primeiro contato (FCR)',
                'icon'            => 'ti ti-rocket',
                'icon_color'      => '#F97316',
                'category'        => 'speed',
                'criteria_type'   => 'fcr_total',
                'criteria_value'  => 50,
                'criteria_period' => 'all_time',
                'rarity'          => 'epic',
                'is_active'       => 1,
            ],
        ];
        foreach ($badges as $badge) {
            $DB->insert('glpi_plugin_gamification_badges', $badge);
        }
    }

    // Idempotent top-up: ensures every catalog badge exists, on fresh installs
    // and on re-install/upgrade alike (inserts only what's missing, by name).
    \GlpiPlugin\Gamification\Badge::seedMissing();

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 6: Badges Earned by Users
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_badgeusers')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_badgeusers` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `badges_id`    INT UNSIGNED NOT NULL,
            `users_id`     INT UNSIGNED NOT NULL,
            `entities_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `seasons_id`   INT UNSIGNED DEFAULT NULL,
            `earned_date`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `badges_id`  (`badges_id`),
            KEY `users_id`   (`users_id`),
            KEY `entities_id` (`entities_id`),
            UNIQUE KEY `badge_user_season` (`badges_id`, `users_id`, `seasons_id`, `entities_id`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 7: Seasons
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_seasons')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_seasons` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(255) NOT NULL,
            `date_start`     DATE NOT NULL,
            `date_end`       DATE NOT NULL,
            `is_active`      TINYINT(1) DEFAULT 0,
            `is_archived`    TINYINT(1) DEFAULT 0,
            `date_creation`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`)
        ) $charset");

        // Create initial season
        $now      = new \DateTime();
        $monthEnd = new \DateTime('last day of this month');
        $DB->insert('glpi_plugin_gamification_seasons', [
            'name'       => $now->format('F Y'),
            'date_start' => $now->format('Y-m-d'),
            'date_end'   => $monthEnd->format('Y-m-d'),
            'is_active'  => 1,
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 8: Leaderboard (per-season ranking snapshots)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_leaderboard')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_leaderboard` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`       INT UNSIGNED NOT NULL,
            `entities_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `seasons_id`     INT UNSIGNED NOT NULL,
            `xp_earned`      INT NOT NULL DEFAULT 0,
            `rank_position`  INT DEFAULT NULL,
            `groups_id`      INT UNSIGNED DEFAULT NULL,
            `date_mod`       TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id`    (`users_id`),
            KEY `entities_id` (`entities_id`),
            KEY `seasons_id`  (`seasons_id`),
            KEY `groups_id`   (`groups_id`),
            UNIQUE KEY `user_season` (`users_id`, `seasons_id`, `entities_id`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 9: Rewards (shop items)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_rewards')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_rewards` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(255) NOT NULL,
            `description`    TEXT,
            `xp_cost`        INT NOT NULL DEFAULT 0,
            `stock`          INT DEFAULT -1,
            `image_path`     VARCHAR(500) DEFAULT NULL,
            `category`       VARCHAR(100) DEFAULT NULL,
            `is_active`      TINYINT(1) DEFAULT 1,
            `date_creation`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `date_mod`       TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`)
        ) $charset");

        // Seed example rewards
        $rewards = [
            [
                'name'        => 'Fone de Ouvido / Mouse Pad Ergonômico',
                'description' => 'Um acessório de qualidade para seu setup',
                'xp_cost'     => 1000,
                'stock'       => -1,
                'category'    => 'gadgets',
                'is_active'   => 1,
            ],
            [
                'name'        => 'Day Off (Tarde de Sexta)',
                'description' => 'Uma tarde de sexta-feira de folga merecida',
                'xp_cost'     => 3000,
                'stock'       => -1,
                'category'    => 'time_off',
                'is_active'   => 1,
            ],
            [
                'name'        => 'Ingresso Cinema / Show / Evento',
                'description' => 'Ingresso para entretenimento à sua escolha',
                'xp_cost'     => 5000,
                'stock'       => -1,
                'category'    => 'entertainment',
                'is_active'   => 1,
            ],
            [
                'name'        => 'Curso de Especialização / Certificação',
                'description' => 'Curso pago de especialização ou certificação profissional',
                'xp_cost'     => 10000,
                'stock'       => -1,
                'category'    => 'education',
                'is_active'   => 1,
            ],
        ];
        foreach ($rewards as $reward) {
            $DB->insert('glpi_plugin_gamification_rewards', $reward);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 10: Reward Orders (redemptions)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_rewardorders')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_rewardorders` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`       INT UNSIGNED NOT NULL,
            `entities_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `rewards_id`     INT UNSIGNED NOT NULL,
            `xp_spent`       INT NOT NULL DEFAULT 0,
            `status`         VARCHAR(20) DEFAULT 'pending',
            `admin_notes`    TEXT,
            `date_creation`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `date_mod`       TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id`    (`users_id`),
            KEY `entities_id` (`entities_id`),
            KEY `rewards_id`  (`rewards_id`),
            KEY `status`      (`status`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 11: Weekly Quests (challenge definitions)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_quests')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_quests` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`           VARCHAR(255) NOT NULL,
            `description`    TEXT,
            `icon`           VARCHAR(100) NOT NULL DEFAULT 'ti ti-target',
            `metric`         VARCHAR(100) NOT NULL,
            `target`         INT NOT NULL DEFAULT 1,
            `xp_reward`      INT NOT NULL DEFAULT 0,
            `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
            `date_creation`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`)
        ) $charset");

        $quests = [
            ['name' => 'Maratona de Tickets', 'description' => 'Resolva 15 tickets esta semana',          'icon' => 'ti ti-ticket',      'metric' => 'ticket_resolved',     'target' => 15, 'xp_reward' => 150],
            ['name' => 'Velocidade da Luz',   'description' => 'Resolva 5 tickets no 1º contato (FCR)',     'icon' => 'ti ti-rocket',      'metric' => 'ticket_resolved_fcr', 'target' => 5,  'xp_reward' => 120],
            ['name' => 'Guardião do SLA',      'description' => 'Cumpra o SLA em 10 tickets',                'icon' => 'ti ti-clock-check', 'metric' => 'sla_met',             'target' => 10, 'xp_reward' => 100],
            ['name' => 'Cliente Feliz',        'description' => 'Receba 3 avaliações de 5 estrelas',         'icon' => 'ti ti-mood-happy',  'metric' => 'satisfaction_max',    'target' => 3,  'xp_reward' => 90],
            ['name' => 'Documentador',         'description' => 'Crie 2 artigos na Base de Conhecimento',    'icon' => 'ti ti-book',        'metric' => 'kb_article_created',  'target' => 2,  'xp_reward' => 80],
        ];
        foreach ($quests as $q) {
            $q['is_active'] = 1;
            $DB->insert('glpi_plugin_gamification_quests', $q);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 12: Quest Claims (one reward per user per quest per week)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_questclaims')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_questclaims` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `quests_id`      INT UNSIGNED NOT NULL,
            `users_id`       INT UNSIGNED NOT NULL,
            `entities_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `week_start`     DATE NOT NULL,
            `xp_awarded`     INT NOT NULL DEFAULT 0,
            `date_creation`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `entities_id` (`entities_id`),
            UNIQUE KEY `quest_user_week` (`quests_id`, `users_id`, `week_start`, `entities_id`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 13: Battle Pass Tiers (per season)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_battlepass_tiers')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_battlepass_tiers` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `seasons_id`   INT UNSIGNED NOT NULL,
            `tier_num`     INT NOT NULL,
            `xp_required`  INT NOT NULL,
            `name`         VARCHAR(255) NOT NULL,
            `icon`         VARCHAR(100) NOT NULL DEFAULT 'ti ti-gift',
            `icon_color`   VARCHAR(20) DEFAULT '#FFD700',
            `reward_type`  VARCHAR(20) NOT NULL DEFAULT 'xp_bonus',
            `reward_value` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `seasons_id` (`seasons_id`),
            UNIQUE KEY `season_tier` (`seasons_id`, `tier_num`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TABLE 14: Battle Pass Claims (which tiers each user earned)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    if (!$DB->tableExists('glpi_plugin_gamification_battlepass_claims')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_battlepass_claims` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`      INT UNSIGNED NOT NULL,
            `entities_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `seasons_id`    INT UNSIGNED NOT NULL,
            `tier_num`      INT NOT NULL,
            `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id`   (`users_id`),
            KEY `seasons_id` (`seasons_id`),
            UNIQUE KEY `user_season_tier` (`users_id`, `seasons_id`, `tier_num`, `entities_id`)
        ) $charset");
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Register profile rights
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    $rights = [
        'plugin_gamification_dashboard',
        'plugin_gamification_leaderboard',
        'plugin_gamification_rewards',
        'plugin_gamification_admin',
    ];
    foreach ($rights as $right) {
        if (countElementsInTable('glpi_profilerights', ['name' => $right]) === 0) {
            ProfileRight::addProfileRights([$right]);
        }
    }

    // Grant full access to Super-Admin (profile id 4)
    foreach ($rights as $right) {
        $existing = countElementsInTable('glpi_profilerights', [
            'profiles_id' => 4,
            'name'        => $right,
        ]);
        if ($existing > 0) {
            global $DB;
            $DB->update('glpi_profilerights', ['rights' => 31], [
                'profiles_id' => 4,
                'name'        => $right,
            ]);
        }
    }

    // Grant dashboard + leaderboard READ to Technician (profile id 6)
    $techRights = ['plugin_gamification_dashboard', 'plugin_gamification_leaderboard'];
    foreach ($techRights as $right) {
        $existing = countElementsInTable('glpi_profilerights', [
            'profiles_id' => 6,
            'name'        => $right,
        ]);
        if ($existing > 0) {
            $DB->update('glpi_profilerights', ['rights' => READ], [
                'profiles_id' => 6,
                'name'        => $right,
            ]);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Register automatic cron tasks (idempotent — skips if already present)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    \CronTask::register(\GlpiPlugin\Gamification\Cron::class, 'CheckBadges', HOUR_TIMESTAMP, [
        'comment' => 'Verifica e concede conquistas automaticamente',
        'mode'    => \CronTask::MODE_EXTERNAL,
    ]);
    \CronTask::register(\GlpiPlugin\Gamification\Cron::class, 'ProcessSeason', DAY_TIMESTAMP, [
        'comment' => 'Rotaciona temporadas (fecha a vencida e abre a próxima)',
        'mode'    => \CronTask::MODE_EXTERNAL,
    ]);
    \CronTask::register(\GlpiPlugin\Gamification\Cron::class, 'CheckQuests', HOUR_TIMESTAMP, [
        'comment' => 'Concede XP por missões semanais concluídas',
        'mode'    => \CronTask::MODE_EXTERNAL,
    ]);
    \CronTask::register(\GlpiPlugin\Gamification\Cron::class, 'CheckBattlePass', HOUR_TIMESTAMP, [
        'comment' => 'Concede recompensas de tiers do Battle Pass',
        'mode'    => \CronTask::MODE_EXTERNAL,
    ]);

    // Idempotent schema upgrade: add per-entity scoping to existing installs.
    plugin_gamification_ensure_entity_columns();
    plugin_gamification_ensure_notify_column();
    plugin_gamification_ensure_battlepass_tables();

    return true;
}

/**
 * Idempotently add the `notified` flag to the XP transaction log (drives the
 * in-app toast notifications). Existing history is marked as already-seen so
 * users are not flooded with toasts for past events on first load.
 */
function plugin_gamification_ensure_notify_column(): void
{
    global $DB;
    $t = 'glpi_plugin_gamification_xptransactions';
    if ($DB->tableExists($t) && !$DB->fieldExists($t, 'notified')) {
        $DB->doQuery("ALTER TABLE `$t` ADD COLUMN `notified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `description`");
        $DB->doQuery("ALTER TABLE `$t` ADD KEY `notified` (`notified`)");
        $DB->doQuery("UPDATE `$t` SET `notified` = 1"); // don't toast pre-existing history
    }
}

/**
 * Idempotently add `entities_id` to the per-user data tables and fix the unique
 * keys so scores/leaderboard/quests/badges can be scoped per entity. Safe to run
 * on every install/upgrade: it only acts on tables missing the column. Existing
 * rows default to entity 0 (root).
 */
function plugin_gamification_ensure_entity_columns(): void
{
    global $DB;

    $tables = [
        'glpi_plugin_gamification_scores',
        'glpi_plugin_gamification_xptransactions',
        'glpi_plugin_gamification_leaderboard',
        'glpi_plugin_gamification_badgeusers',
        'glpi_plugin_gamification_questclaims',
        'glpi_plugin_gamification_rewardorders',
    ];

    $added = [];
    foreach ($tables as $t) {
        if ($DB->tableExists($t) && !$DB->fieldExists($t, 'entities_id')) {
            $DB->doQuery("ALTER TABLE `$t` ADD COLUMN `entities_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `users_id`");
            $DB->doQuery("ALTER TABLE `$t` ADD KEY `entities_id` (`entities_id`)");
            $added[$t] = true;
        }
    }

    // Swap unique keys to include entities_id — only when the column was just added.
    // $drop = old index name to remove; $name/$cols = composite unique to (re)create.
    $swap = function (string $table, string $drop, string $name, string $cols) use ($DB): void {
        try {
            $DB->doQuery("ALTER TABLE `$table` DROP INDEX `$drop`");
        } catch (\Throwable $e) {
            // index may not exist on hand-modified DBs — ignore
        }
        try {
            $DB->doQuery("ALTER TABLE `$table` ADD UNIQUE KEY `$name` ($cols)");
        } catch (\Throwable $e) {
            // composite already present — ignore
        }
    };

    if (!empty($added['glpi_plugin_gamification_scores'])) {
        $swap('glpi_plugin_gamification_scores', 'users_id', 'users_entity', '`users_id`, `entities_id`');
    }
    if (!empty($added['glpi_plugin_gamification_leaderboard'])) {
        $swap('glpi_plugin_gamification_leaderboard', 'user_season', 'user_season', '`users_id`, `seasons_id`, `entities_id`');
    }
    if (!empty($added['glpi_plugin_gamification_badgeusers'])) {
        $swap('glpi_plugin_gamification_badgeusers', 'badge_user_season', 'badge_user_season', '`badges_id`, `users_id`, `seasons_id`, `entities_id`');
    }
    if (!empty($added['glpi_plugin_gamification_questclaims'])) {
        $swap('glpi_plugin_gamification_questclaims', 'quest_user_week', 'quest_user_week', '`quests_id`, `users_id`, `week_start`, `entities_id`');
    }
}

/**
 * Uninstall the plugin — drop all tables, remove rights.
 */
function plugin_gamification_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_gamification_configs',
        'glpi_plugin_gamification_rules',
        'glpi_plugin_gamification_scores',
        'glpi_plugin_gamification_xptransactions',
        'glpi_plugin_gamification_badges',
        'glpi_plugin_gamification_badgeusers',
        'glpi_plugin_gamification_seasons',
        'glpi_plugin_gamification_leaderboard',
        'glpi_plugin_gamification_rewards',
        'glpi_plugin_gamification_rewardorders',
        'glpi_plugin_gamification_quests',
        'glpi_plugin_gamification_questclaims',
        'glpi_plugin_gamification_battlepass_tiers',
        'glpi_plugin_gamification_battlepass_claims',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    // Remove profile rights
    $rights = [
        'plugin_gamification_dashboard',
        'plugin_gamification_leaderboard',
        'plugin_gamification_rewards',
        'plugin_gamification_admin',
    ];
    foreach ($rights as $right) {
        ProfileRight::deleteProfileRights([$right]);
    }

    // Remove registered cron tasks
    \CronTask::unregister('gamification');

    return true;
}

/**
 * Idempotently create the two battle pass tables on existing installs that
 * ran before the feature was added. Safe to call on every install/upgrade.
 */
function plugin_gamification_ensure_battlepass_tables(): void
{
    global $DB;
    $charset = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

    if (!$DB->tableExists('glpi_plugin_gamification_battlepass_tiers')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_battlepass_tiers` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `seasons_id`   INT UNSIGNED NOT NULL,
            `tier_num`     INT NOT NULL,
            `xp_required`  INT NOT NULL,
            `name`         VARCHAR(255) NOT NULL,
            `icon`         VARCHAR(100) NOT NULL DEFAULT 'ti ti-gift',
            `icon_color`   VARCHAR(20) DEFAULT '#FFD700',
            `reward_type`  VARCHAR(20) NOT NULL DEFAULT 'xp_bonus',
            `reward_value` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `seasons_id` (`seasons_id`),
            UNIQUE KEY `season_tier` (`seasons_id`, `tier_num`)
        ) $charset");
    }

    if (!$DB->tableExists('glpi_plugin_gamification_battlepass_claims')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_gamification_battlepass_claims` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id`      INT UNSIGNED NOT NULL,
            `entities_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `seasons_id`    INT UNSIGNED NOT NULL,
            `tier_num`      INT NOT NULL,
            `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id`   (`users_id`),
            KEY `seasons_id` (`seasons_id`),
            UNIQUE KEY `user_season_tier` (`users_id`, `seasons_id`, `tier_num`, `entities_id`)
        ) $charset");
    }
}
