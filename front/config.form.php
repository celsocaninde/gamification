<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Menu;
use GlpiPlugin\Gamification\Config;

Session::checkLoginUser();
Session::checkRight('plugin_gamification_admin', UPDATE);

$config = new Config();

if (isset($_POST['update'])) {
    // CSRF is already enforced globally by GLPI 11's CheckCsrfListener (kernel),
    // which validates and consumes the token before this script runs. A second
    // manual Session::checkCSRF() here would fail on the already-consumed token.

    foreach ($_POST as $key => $value) {
        if (in_array($key, ['update', '_glpi_csrf_token'], true)) {
            continue;
        }
        // Checkbox groups (e.g. business_days[]) arrive as arrays — store as CSV.
        if (is_array($value)) {
            $value = implode(',', $value);
        }
        Config::setConfig($key, (string) $value);
    }
    
    Session::addMessageAfterRedirect(__('Configurações salvas', 'gamification'), true, INFO);
    // Redirect back to this form. Don't rely on HTTP_REFERER — browsers/proxies
    // may strip it (Referrer-Policy), which would break the redirect.
    Html::redirect(\Plugin::getWebDir('gamification') . '/front/config.form.php');
}

Html::header(__('Configuration', 'gamification'), $_SERVER['PHP_SELF'], 'helpdesk', Menu::class, 'config');

echo "<div class='container-fluid py-4 gamification-wrapper'>";
$config->showConfigForm();
echo "</div>";

Html::footer();
