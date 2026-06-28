<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Profile;

Session::checkLoginUser();
Session::checkRight('profile', UPDATE);

// CSRF is validated/consumed by GLPI 11's kernel CheckCsrfListener before this
// legacy script runs, so no manual Session::checkCSRF() here (it would fail on
// the already-consumed token). The form still renders _glpi_csrf_token.

$profiles_id = (int) ($_POST['id'] ?? 0);

if (isset($_POST['_update_gamification_rights']) && $profiles_id > 0) {
    $posted  = $_POST['_plugin_gamification_rights'] ?? [];
    $changed = Profile::saveRights($profiles_id, is_array($posted) ? $posted : []);

    Session::addMessageAfterRedirect(
        $changed > 0
            ? __('Permissões da Gamificação atualizadas', 'gamification')
            : __('Nenhuma alteração nas permissões da Gamificação', 'gamification'),
        true,
        INFO
    );
}

// Redirect back to the profile form (not HTTP_REFERER — it may be stripped).
Html::redirect(\Profile::getFormURLWithID($profiles_id));
