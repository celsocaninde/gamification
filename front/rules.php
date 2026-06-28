<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Rule;

Session::checkLoginUser();

$rule = new Rule();
$rule->display([
    'id' => $_GET['id'] ?? ''
]);
