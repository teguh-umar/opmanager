<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\Cron;
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

    $table = 'glpi_plugin_opmanager_alarms';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `alarm_key` VARCHAR(255) NOT NULL DEFAULT '',
            `entity_key` VARCHAR(255) NOT NULL DEFAULT '',
            `device_name` VARCHAR(255) NOT NULL DEFAULT '',
            `display_name` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `category` VARCHAR(255) NOT NULL DEFAULT '',
            `alarm_code` VARCHAR(255) NOT NULL DEFAULT '',
            `event_type` VARCHAR(255) NOT NULL DEFAULT '',
            `severity` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `severity_label` VARCHAR(32) NOT NULL DEFAULT '',
            `message` TEXT NULL,
            `state` VARCHAR(16) NOT NULL DEFAULT 'active',
            `plugin_opmanager_devices_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `ticket_role` VARCHAR(16) NOT NULL DEFAULT '',
            `ticket_pending` TINYINT NOT NULL DEFAULT 0,
            `ticket_created_at` TIMESTAMP NULL DEFAULT NULL,
            `last_followup_at` TIMESTAMP NULL DEFAULT NULL,
            `mod_time` TIMESTAMP NULL DEFAULT NULL,
            `first_seen` TIMESTAMP NULL DEFAULT NULL,
            `last_change` TIMESTAMP NULL DEFAULT NULL,
            `cleared_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `alarm_key` (`alarm_key`),
            KEY `state` (`state`),
            KEY `device_name` (`device_name`),
            KEY `tickets_id` (`tickets_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `plugin_opmanager_devices_id` (`plugin_opmanager_devices_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }

    $table = 'glpi_plugin_opmanager_storms';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `alarm_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `last_hit_at` TIMESTAMP NULL DEFAULT NULL,
            `ended_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }

    Config::setDefaults();

    // setDefaults() never touches a stored value, so move an untouched old default
    // description pattern to the current one (v0.2.1 adds the OpManager category).
    $new_pattern = Config::upgradedContentPattern(Config::get('ticket_content_pattern'));
    if ($new_pattern !== null) {
        Config::set(['ticket_content_pattern' => $new_pattern]);
    }

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

    // CLI mode on purpose: GLPI assigns run mode per task, and web mode would
    // make the sync depend on someone browsing GLPI (hub §4).
    CronTask::register(Cron::class, 'DeviceSync', Config::DEVICE_SYNC_MINUTES * MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'OpManager device sync and asset matching',
    ]);
    CronTask::register(Cron::class, 'AlarmSync', Config::ALARM_SYNC_MINUTES * MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'OpManager alarm sync and tickets',
    ]);

    return true;
}
