<?php

require __DIR__ . '/bootstrap.php';

// Suite schema rules (hub §4 rules 1, 2c, 2d) checked against the literal DDL.
$src = (string) @file_get_contents(OPM_ROOT . '/install/install.php');
// DDL is written as CREATE TABLE `$table` (...), so take the name from the
// `$table = '...'` assignment that precedes each block.
preg_match_all(
    '/\$table = \'(\w+)\';\s*if \(!\$DB->tableExists\(\$table\)\) \{\s*\$DB->doQuery\("CREATE TABLE `\$table` \((.*?)\) ENGINE/s',
    $src,
    $tables,
    PREG_SET_ORDER
);
check('install creates the devices table', in_array('glpi_plugin_opmanager_devices', array_column($tables, 1), true));

foreach ($tables as [, $table, $body]) {
    check("{$table}: no DATETIME column", stripos($body, 'DATETIME') === false);
    preg_match_all('/^\s*`(\w+)`\s+([^,\n]+)/m', $body, $cols, PREG_SET_ORDER);
    foreach ($cols as [, $col, $type]) {
        if ($col === 'id' || str_ends_with($col, '_id')) {
            check("{$table}.{$col} is INT UNSIGNED", (bool) preg_match('/^INT UNSIGNED\b/i', trim($type)), trim($type));
        }
        if (str_ends_with($col, '_key')) {
            check("{$table}.{$col} external id is VARCHAR", stripos($type, 'VARCHAR') === 0);
        }
    }
}
check('devices.device_key is UNIQUE', (bool) preg_match('/UNIQUE KEY `device_key` \(`device_key`\)/', $src));

foreach (['glpi_plugin_opmanager_alarms', 'glpi_plugin_opmanager_storms'] as $t) {
    check("install creates {$t}", in_array($t, array_column($tables, 1), true));
}
check('alarms.alarm_key is UNIQUE (D3)', (bool) preg_match('/UNIQUE KEY `alarm_key` \(`alarm_key`\)/', $src));
check('alarms keep entity_key for notes/clear', str_contains($src, '`entity_key` VARCHAR(255)'));
check('uninstall drops the new tables', str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_opmanager_alarms')
    && str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_opmanager_storms'));

check('alarms track last followup time', str_contains($src, '`last_followup_at` TIMESTAMP'));

// v1.1.0 (D32): deferred webhook requests.
check('install creates glpi_plugin_opmanager_ticketrequests', in_array('glpi_plugin_opmanager_ticketrequests', array_column($tables, 1), true));
check('ticketrequests.alarm_key is UNIQUE', (bool) preg_match('/ticketrequests.*?UNIQUE KEY `alarm_key` \(`alarm_key`\)/s', $src));
check('uninstall drops ticketrequests', str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_opmanager_ticketrequests'));

done();
