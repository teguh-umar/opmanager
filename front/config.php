<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;
use GlpiPlugin\Opmanager\SuiteConfig;
use GlpiPlugin\Pgnsuite\ConfigTab;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

if (isset($_POST['generate_webhook_token'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $token = Config::newWebhookToken();
    Config::set(['webhook_token' => $token]);
    // Shown exactly once, inline in the Setup form (a toast hides itself after 10 s).
    $_SESSION[SuiteConfig::NEW_TOKEN_KEY] = $token;
    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
}

if (isset($_POST['update'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);

    $result  = Config::sanitize($_POST, array_keys(Config::offeredItemtypes()));
    $tickets = Config::sanitizeTicketing($_POST);
    $hygiene = Config::sanitizeHygiene($_POST);
    $result  = [
        'values' => $result['values'] + $tickets['values'] + $hygiene['values'],
        'errors' => array_merge($result['errors'], $tickets['errors'], $hygiene['errors']),
    ];
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

// Users who can open Setup > General get the ICT Tools tab instead (hub §5).
if (Session::haveRight('config', READ)) {
    Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
}

// Standalone page for OpManager admins without core `config` READ. No menu entry.
Html::header('OpManager', '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'opmanager', 'device');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('opmanager', 'device');
SuiteConfig::showForm();
Html::footer();
