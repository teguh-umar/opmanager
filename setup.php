<?php

/**
 * OpManager - ManageEngine OpManager <-> GLPI 11 integration (PGN GLPI Suite plugin #7)
 *
 * @author  PGN ICT
 * @license GPL-3.0-or-later
 */

define('PLUGIN_OPMANAGER_VERSION', '0.2.1');
define('PLUGIN_OPMANAGER_MIN_GLPI', '11.0.0');
define('PLUGIN_OPMANAGER_MAX_GLPI', '11.99.99');

include_once(__DIR__ . '/install/install.php');
include_once(__DIR__ . '/install/uninstall.php');

function plugin_version_opmanager()
{
    return [
        'name'         => 'OpManager',
        'version'      => PLUGIN_OPMANAGER_VERSION,
        'author'       => 'PGN ICT',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_OPMANAGER_MIN_GLPI,
                'max' => PLUGIN_OPMANAGER_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_opmanager_check_prerequisites()
{
    return true;
}

function plugin_opmanager_check_config()
{
    return true;
}

function plugin_init_opmanager()
{
    global $PLUGIN_HOOKS;

    // GLPI 11's CheckCsrfListener validates + consumes CSRF tokens before a
    // legacy front file runs - never call Session::checkCSRF() ourselves.
    $PLUGIN_HOOKS['csrf_compliant']['opmanager'] = true;

    // Core encrypts these on write (GLPIKey::getConfigs maps them to plugin:opmanager).
    $PLUGIN_HOOKS['secured_configs']['opmanager'] = \GlpiPlugin\Opmanager\Config::SECURED;

    \Plugin::registerClass(\GlpiPlugin\Opmanager\Profile::class, ['addtabon' => ['Profile']]);

    // Array-of-classes value is mandatory for a NEW sector (hub §4).
    $PLUGIN_HOOKS['menu_toadd']['opmanager'] = ['opmanager' => [\GlpiPlugin\Opmanager\Menu::class]];

    // Shared "PGN Suite" tab on Setup > General (hub §5). class_exists(..., false)
    // skips the autoloader, which would look for a nonexistent plugins/pgnsuite/.
    if (!class_exists(\GlpiPlugin\Pgnsuite\ConfigTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
    }
    \GlpiPlugin\Pgnsuite\ConfigTab::register();
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\ConfigTab::HOOK]['opmanager'] =
        \GlpiPlugin\Opmanager\SuiteConfig::class . '::section';

    // Shared "PGN Suite" tab on asset forms (hub §5). The itemtype list is plain
    // strings read from config + glpi_assets_assetdefinitions: custom asset classes
    // are not loaded yet here, so NEVER filter it with class_exists().
    if (!class_exists(\GlpiPlugin\Pgnsuite\AssetTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/AssetTab.php';
    }
    \GlpiPlugin\Pgnsuite\AssetTab::register(\GlpiPlugin\Opmanager\Config::assetItemtypes());
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\AssetTab::HOOK]['opmanager'] =
        \GlpiPlugin\Opmanager\AssetSections::class . '::sections';
}
