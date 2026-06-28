<?php

/**
 * -------------------------------------------------------------------------
 * Gamificação plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Gamificação.
 *
 * Gamificação is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Gamificação is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Gamificação. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2024-2026 by Gamificação plugin team.
 * @license   GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/gamification-glpi
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Profile;
use GlpiPlugin\Gamification\UserTab;
use GlpiPlugin\Gamification\EventListener;
use GlpiPlugin\Gamification\Dashboard;
use GlpiPlugin\Gamification\Cron;

define('PLUGIN_GAMIFICATION_VERSION', '1.0.0');
define('PLUGIN_GAMIFICATION_MIN_GLPI', '11.0.0');
define('PLUGIN_GAMIFICATION_MAX_GLPI', '11.0.99');

/**
 * Init hooks, register classes, menus, CSS/JS.
 */
function plugin_init_gamification(): void
{
    global $PLUGIN_HOOKS;

    // ── 1) CSRF compliance (mandatory) ──────────────────────────────────
    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['gamification'] = true;

    // ── 2) CSS and JavaScript ───────────────────────────────────────────
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['gamification']        = 'css/gamification.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['gamification'] = 'js/gamification.js';

    // ── 3) Config page (gear icon in plugin list) ───────────────────────
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['gamification'] = 'front/config.form.php';

    // ── 4) Menu registration ────────────────────────────────────────────
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['gamification'] = [
        'helpdesk' => Menu::class,
    ];

    // ── 5) Tab registrations ────────────────────────────────────────────
    Plugin::registerClass(Profile::class, ['addtabon' => [\Profile::class]]);
    Plugin::registerClass(UserTab::class, ['addtabon' => [\User::class]]);

    // ── 6) Item lifecycle hooks — XP Engine ─────────────────────────────
    // Ticket created (track for FCR calculation)
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['gamification'] = [
        'Ticket'             => [EventListener::class, 'onTicketCreated'],
        'TicketSatisfaction'  => [EventListener::class, 'onSatisfactionReceived'],
        'KnowbaseItem'       => [EventListener::class, 'onKBArticleCreated'],
    ];

    // Ticket updated (solved, reopened, etc.)
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['gamification'] = [
        'Ticket'             => [EventListener::class, 'onTicketUpdated'],
        'TicketSatisfaction'  => [EventListener::class, 'onSatisfactionUpdated'],
    ];

    // ── 7) Dashboard widgets ────────────────────────────────────────────
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['gamification'] = [[Dashboard::class, 'getCards']];

    // ── 8) Display on Central page ──────────────────────────────────────
    $PLUGIN_HOOKS[Hooks::DISPLAY_CENTRAL]['gamification'] = [[Dashboard::class, 'showOnCentral']];
}

/**
 * Get the name and required versions of the plugin.
 */
function plugin_version_gamification(): array
{
    return [
        'name'         => __('Gamificação Service Desk', 'gamification'),
        'version'      => PLUGIN_GAMIFICATION_VERSION,
        'author'       => 'Gamification Team',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GAMIFICATION_MIN_GLPI,
                'max' => PLUGIN_GAMIFICATION_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Check plugin prerequisites before activation.
 */
function plugin_gamification_check_prerequisites(): bool
{
    return true;
}

/**
 * Check plugin configuration.
 */
function plugin_gamification_check_config(): bool
{
    return true;
}
