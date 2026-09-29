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

done();
