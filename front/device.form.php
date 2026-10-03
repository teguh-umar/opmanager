<?php

use GlpiPlugin\Opmanager\Device;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

$device = new Device();

if (isset($_POST['pin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $error = $device->pin((int) $_POST['id'], (string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
    if ($error !== null) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(__s('Device pinned to the asset', 'opmanager'));
    }
    Html::back();
}

if (isset($_POST['unpin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $device->unpin((int) $_POST['id']);
    Session::addMessageAfterRedirect(__s('Manual pin removed; the next device sync matches it automatically', 'opmanager'));
    Html::back();
}

Session::checkRight(RightDefinition::MONITORING, READ);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    // Devices are created by the sync only - there is no add form.
    Html::redirect(Device::getSearchURL());
}

$menus = ['central' => [\GlpiPlugin\Pgnsuite\Menu::SECTOR, 'opmanager', 'device'], 'helpdesk' => []];
Device::displayFullPageForItem($id, $menus, []);
