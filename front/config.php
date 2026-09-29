<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;
use GlpiPlugin\Opmanager\SuiteConfig;
use GlpiPlugin\Pgnsuite\ConfigTab;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

if (isset($_POST['update'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);

    $result = Config::sanitize($_POST, array_keys(Config::offeredItemtypes()));
    Config::set($result['values']);
    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    if ($result['errors'] === []) {
        Session::addMessageAfterRedirect(__s('Settings saved', 'opmanager'));
    }

    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
}

// Users who can open Setup > General get the PGN Suite tab instead (hub §5).
if (Session::haveRight('config', READ)) {
    Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
}

// Standalone page for OpManager admins without core `config` READ. No menu entry.
Html::header('OpManager', '', 'opmanager', 'device');
SuiteConfig::showForm();
Html::footer();
