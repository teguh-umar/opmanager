<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;

function plugin_opmanager_uninstall()
{
    global $DB;

    foreach (['glpi_plugin_opmanager_hygieneitems', 'glpi_plugin_opmanager_hygienetickets', 'glpi_plugin_opmanager_deviceexceptions', 'glpi_plugin_opmanager_ticketrequests', 'glpi_plugin_opmanager_outbox', 'glpi_plugin_opmanager_storms', 'glpi_plugin_opmanager_alarms', 'glpi_plugin_opmanager_devices'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    ProfileRight::deleteProfileRights(RightDefinition::names());
    \Config::deleteConfigurationValues(Config::CONTEXT, array_keys(Config::DEFAULTS));
    CronTask::unregister('opmanager');

    return true;
}
