<?php

namespace GlpiPlugin\Opmanager;

use Glpi\Asset\AssetDefinition;

/**
 * Active GLPI 11 custom asset definitions (Setup > Asset definitions), read straight
 * from the definitions table. plugin_init runs before core boots custom objects
 * (InitializePlugins 110 > CustomObjectsBoot 100), so the classes are neither
 * autoloadable nor in $CFG_GLPI['asset_types'] at that point (hub §5). Never
 * class_exists() these names.
 */
final class CustomAssets
{
    /** @return array<string, string> class name => label */
    public static function active(): array
    {
        global $DB;

        $table = AssetDefinition::getTable();
        if (!$DB->tableExists($table)) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['system_name', 'label'],
            'FROM'   => $table,
            'WHERE'  => ['is_active' => 1],
        ]) as $row) {
            $class = AssetDefinition::getCustomObjectNamespace() . '\\' . $row['system_name']
                . AssetDefinition::getCustomObjectClassSuffix();
            $out[$class] = (string) ($row['label'] ?: $row['system_name']);
        }
        return $out;
    }
}
