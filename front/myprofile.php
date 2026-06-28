<?php
include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\UserTab;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_dashboard', READ);
Menu::checkPanelEnabled();

Html::header(__('Meu Perfil', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'myprofile');

$user = new User();
if ($user->getFromDB(Session::getLoginUserID())) {
    echo "<div class='container-fluid py-4'>";
    UserTab::displayTabContentForItem($user);
    echo "</div>";
} else {
    echo "<div class='text-center py-5'>Erro ao carregar usuário.</div>";
}

Html::footer();
