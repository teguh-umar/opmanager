<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/ProductionCheck.php';
require OPM_ROOT . '/src/Ticketing/RangeProductionCheck.php';

use GlpiPlugin\Opmanager\Ticketing\RangeProductionCheck;

// Rows as stored by the IP Addressing plugin (glpi_plugin_addressing_addressings), production screenshot 2026-09-30.
$rows = [
    ['id' => 3, 'begin_ip' => '10.20.10.1', 'end_ip' => '10.20.11.254'],   // Prodcution IT
    ['id' => 7, 'begin_ip' => '10.20.18.0', 'end_ip' => '10.20.19.254'],   // Production OT
    ['id' => 9, 'begin_ip' => 'bogus', 'end_ip' => '10.0.0.1'],
    ['id' => 10, 'begin_ip' => '10.0.0.9', 'end_ip' => '10.0.0.1'],          // begin > end
];
$ranges = RangeProductionCheck::rangesFromRows($rows);
check('bad rows skipped', count($ranges) === 2);

$assetIps = ['Computer#77' => ['10.20.10.149', 'fe80::1']];
$lookup   = static fn (string $itemtype, int $id) => $assetIps[$itemtype . '#' . $id] ?? [];
$check    = new RangeProductionCheck($ranges, $lookup);

$alarm = static fn (string $ip) => ['ip_address' => $ip];
$none  = ['devices_id' => 0, 'itemtype' => '', 'items_id' => 0];
check('alarm IP inside a range is production', $check->isProduction($alarm('10.20.11.40'), $none));
check('range start is inclusive', $check->isProduction($alarm('10.20.10.1'), $none));
check('range end is inclusive', $check->isProduction($alarm('10.20.19.254'), $none));
check('IP outside every range is not production', !$check->isProduction($alarm('10.20.2.30'), $none));
check('asset IP inside a range makes it production', $check->isProduction($alarm('10.20.14.139'), ['devices_id' => 0, 'itemtype' => 'Computer', 'items_id' => 77]));
check('empty or IPv6 IP ignored', !$check->isProduction($alarm(''), $none) && !$check->isProduction($alarm('fe80::2'), $none));
check('no range selected → never production', !(new RangeProductionCheck([], $lookup))->isProduction($alarm('10.20.11.40'), $none));

done();
