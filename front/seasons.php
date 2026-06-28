<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gamification\Season;

Session::checkLoginUser();

$season = new Season();
$season->display([
    'id' => $_GET['id'] ?? ''
]);
