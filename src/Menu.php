<?php

namespace GlpiPlugin\Gamification;

use CommonGLPI;
use Session;
use Plugin;
use Html;

class Menu extends CommonGLPI
{
    public static $rightname = 'plugin_gamification_dashboard';

    public static function getMenuName(): string
    {
        return __('Gamificação', 'gamification');
    }

    /**
     * Stops a player-facing page when the panel is disabled for the active
     * entity, rendering a friendly notice. Call right after the right check.
     */
    public static function checkPanelEnabled(): void
    {
        if (Config::isEnabledForCurrentEntity()) {
            return;
        }
        Html::header(self::getMenuName(), $_SERVER['PHP_SELF'], 'helpdesk', self::class);
        echo "<div class='container-fluid py-5 gamification-wrapper'>";
        echo "<div class='gx-card gx-card-pad text-center' style='max-width:540px;margin:2rem auto'>";
        echo "<i class='ti ti-lock-off' style='font-size:2.6rem;color:var(--gx-muted)'></i>";
        echo "<h2 class='h5 mt-3'>" . __('Painel indisponível nesta entidade', 'gamification') . "</h2>";
        echo "<p class='text-muted m-0'>" . __('A Gamificação não está habilitada para a entidade ativa. Selecione outra entidade ou fale com o administrador.', 'gamification') . "</p>";
        echo "</div></div>";
        Html::footer();
        exit;
    }

    public static function getMenuContent(): array|false
    {
        $is_admin    = Session::haveRight('plugin_gamification_admin', READ);
        $show_player = $is_admin || (Session::haveRight(self::$rightname, READ) && Config::isEnabledForCurrentEntity());

        if (!$show_player && !$is_admin) {
            return false;
        }

        $dir  = Plugin::getWebDir('gamification');
        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $show_player ? $dir . '/front/dashboard.php' : $dir . '/front/analytics.php',
            'icon'    => 'ti ti-trophy',
            'options' => [],
        ];

        if ($show_player) {
            $menu['options']['dashboard'] = [
                'title' => __('Dashboard', 'gamification'),
                'page'  => $dir . '/front/dashboard.php',
                'icon'  => 'ti ti-chart-dots-3',
            ];
            $menu['options']['leaderboard'] = [
                'title' => __('Leaderboard', 'gamification'),
                'page'  => $dir . '/front/leaderboard.php',
                'icon'  => 'ti ti-podium',
            ];
            $menu['options']['badges'] = [
                'title' => __('Badges', 'gamification'),
                'page'  => $dir . '/front/badges.php',
                'icon'  => 'ti ti-medal-2',
            ];
            $menu['options']['quests'] = [
                'title' => __('Missões', 'gamification'),
                'page'  => $dir . '/front/quests.php',
                'icon'  => 'ti ti-target-arrow',
            ];
            $menu['options']['battlepass'] = [
                'title' => __('Battle Pass', 'gamification'),
                'page'  => $dir . '/front/battlepass.php',
                'icon'  => 'ti ti-layers-intersect',
            ];

            if (Session::haveRight('plugin_gamification_rewards', READ)) {
                $menu['options']['rewards'] = [
                    'title' => __('Recompensas', 'gamification'),
                    'page'  => $dir . '/front/rewards.php',
                    'icon'  => 'ti ti-gift',
                ];
            }

            $menu['options']['myprofile'] = [
                'title' => __('Meu Perfil', 'gamification'),
                'page'  => $dir . '/front/myprofile.php',
                'icon'  => 'ti ti-user-circle',
            ];
        }

        if ($is_admin) {
            $menu['options']['analytics'] = [
                'title' => __('Análises', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/analytics.php',
                'icon'  => 'ti ti-chart-histogram',
            ];
            $menu['options']['rules'] = [
                'title' => __('Regras', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/rules.php',
                'icon'  => 'ti ti-gavel',
            ];
            $menu['options']['managebadges'] = [
                'title' => __('Conquistas', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/managebadges.php',
                'icon'  => 'ti ti-medal-2',
            ];
            $menu['options']['managebattlepass'] = [
                'title' => __('Battle Pass', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/managebattlepass.php',
                'icon'  => 'ti ti-layers-intersect',
            ];
            $menu['options']['managequests'] = [
                'title' => __('Missões', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/managequests.php',
                'icon'  => 'ti ti-target-arrow',
            ];
            $menu['options']['seasons'] = [
                'title' => __('Temporadas', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/seasons.php',
                'icon'  => 'ti ti-calendar-event',
            ];
            $menu['options']['rewardorders'] = [
                'title' => __('Pedidos', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/rewardorders.php',
                'icon'  => 'ti ti-shopping-cart',
            ];
            $menu['options']['config'] = [
                'title' => __('Configuração', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/config.form.php',
                'icon'  => 'ti ti-settings',
            ];
            $menu['options']['recalculate'] = [
                'title' => __('Recalcular Pontuações', 'gamification'),
                'page'  => Plugin::getWebDir('gamification') . '/front/recalculate.php',
                'icon'  => 'ti ti-refresh',
            ];
        }

        return $menu;
    }
}
