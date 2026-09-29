<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;
use GlpiPlugin\Opmanager\RightSeeder;

function plugin_opmanager_install()
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    // Suite schema rules (hub §4): keys INT UNSIGNED; OpManager identifiers are
    // VARCHAR *_key (an INT *_id counts as a GLPI key); dates TIMESTAMP only.
    $table = 'glpi_plugin_opmanager_devices';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `device_key` VARCHAR(255) NOT NULL DEFAULT '',
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `display_name` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `device_type` VARCHAR(255) NOT NULL DEFAULT '',
            `device_category` VARCHAR(255) NOT NULL DEFAULT '',
            `availability` VARCHAR(32) NOT NULL DEFAULT '',
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `match_state` VARCHAR(16) NOT NULL DEFAULT 'unmatched',
            `match_source` VARCHAR(16) NOT NULL DEFAULT '',
            `is_manual` TINYINT NOT NULL DEFAULT 0,
            `last_seen` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `device_key` (`device_key`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `match_state` (`match_state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }

    Config::setDefaults();

    $new_rights = array_values(array_diff(
        RightDefinition::names(),
        array_keys(ProfileRight::getAllPossibleRights())
    ));
    if ($new_rights !== []) {
        ProfileRight::addProfileRights($new_rights);
    }

    // All profiles from the DB - never $_SESSION (a CLI install has none).
    $profiles = [];
    foreach ($DB->request(['SELECT' => ['id', 'interface'], 'FROM' => 'glpi_profiles']) as $row) {
        $profiles[(int) $row['id']] = ['interface' => (string) $row['interface'], 'config' => 0, 'computer' => 0];
    }
    foreach ($DB->request([
        'SELECT' => ['profiles_id', 'name', 'rights'],
        'FROM'   => 'glpi_profilerights',
        'WHERE'  => ['name' => ['config', 'computer']],
    ]) as $row) {
        $pid = (int) $row['profiles_id'];
        if (isset($profiles[$pid])) {
            $profiles[$pid][$row['name']] = (int) $row['rights'];
        }
    }
    foreach (RightSeeder::plan($new_rights, $profiles) as $pid => $rights) {
        ProfileRight::updateProfileRights($pid, $rights);
    }

    return true;
}
