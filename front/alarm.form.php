<?php

use GlpiPlugin\Opmanager\Alarm;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::redirect(Alarm::getSearchURL());
}
$menus = ['central' => [\GlpiPlugin\Pgnsuite\Menu::SECTOR, 'opmanager', 'alarm'], 'helpdesk' => []];
Alarm::displayFullPageForItem($id, $menus, []);
