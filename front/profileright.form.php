<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Profile;

Session::checkLoginUser();
Session::checkRight('profile', UPDATE);

// CSRF is validated/consumed by GLPI 11's kernel CheckCsrfListener before this
// legacy script runs, so no manual Session::checkCSRF() here (it would fail on
// the already-consumed token). The form still sends _glpi_csrf_token.

$profiles_id = (int) ($_POST['id'] ?? 0);
$is_ajax     = isset($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if (isset($_POST['_update_gamification_rights']) && $profiles_id > 0) {
    $posted  = $_POST['_plugin_gamification_rights'] ?? [];
    $changed = Profile::saveRights($profiles_id, is_array($posted) ? $posted : []);

    if ($is_ajax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok'      => true,
            'changed' => $changed,
            'message' => $changed > 0
                ? sprintf(__('%d permissão(ões) atualizada(s)', 'gamification'), $changed)
                : __('Nenhuma alteração', 'gamification'),
            // Token novo para um proximo salvar sem recarregar a aba.
            'csrf'    => Session::getNewCSRFToken(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    Session::addMessageAfterRedirect(
        $changed > 0
            ? __('Permissões da Gamificação atualizadas', 'gamification')
            : __('Nenhuma alteração nas permissões da Gamificação', 'gamification'),
        true,
        INFO
    );
}

if ($is_ajax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'message' => __('Requisição inválida', 'gamification')], JSON_UNESCAPED_UNICODE);
    exit;
}

// Redirect back to the profile form (not HTTP_REFERER — it may be stripped).
Html::redirect(\Profile::getFormURLWithID($profiles_id));
