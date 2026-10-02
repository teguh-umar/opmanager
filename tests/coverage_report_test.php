<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Coverage/Report.php';
require OPM_ROOT . '/src/Coverage/Csv.php';

use GlpiPlugin\Opmanager\Coverage\Csv;
use GlpiPlugin\Opmanager\Coverage\Report;

$dev = static fn (int $id, string $name, string $ip, string $state, string $itemtype = '', int $items_id = 0, string $source = '', string $seen = '2026-10-02 11:00:00', string $cat = 'Server') => [
    'id' => $id, 'device_key' => "k{$id}", 'name' => $name, 'display_name' => $name, 'ip' => $ip, 'category' => $cat, 'type' => 'Linux',
    'itemtype' => $itemtype, 'items_id' => $items_id, 'match_state' => $state, 'match_source' => $source, 'last_seen' => $seen,
];
$asset = static fn (string $t, int $id, string $name, array $ips) => ['itemtype' => $t, 'items_id' => $id, 'name' => $name, 'entity' => 'Root', 'state' => 'In use', 'ips' => $ips];

$devices = [
    $dev(1, 'core-sw-01', '10.20.14.1', 'matched', 'NetworkEquipment', 10, 'name'),
    $dev(2, 'srv-app.example.com', '10.20.14.2', 'matched', 'Computer', 20, 'ip'),
    $dev(3, 'mystery', '10.20.14.3', 'unmatched'),
    $dev(4, 'twin', '10.1.1.4', 'review'),
    $dev(5, 'old-box', '10.1.1.5', 'unmatched', '', 0, '', '2026-09-01 00:00:00', 'Switch'),
];
$assets = [
    $asset('NetworkEquipment', 10, 'CORE-SW-01', ['10.20.14.1']),
    $asset('Computer', 20, 'APP-SERVER', ['10.20.14.2']),
    $asset('Computer', 30, 'db-01', ['192.168.1.9', '10.20.14.30']),
    $asset('Computer', 40, 'lab-pc', ['192.168.50.4']),
];
$opt = ['ranges' => [['begin' => ip2long('10.20.14.1'), 'end' => ip2long('10.20.14.254')]], 'production_only' => false, 'category' => '',
        'last_device_sync' => '2026-10-02 11:30:00', 'stale_minutes' => 90];

$r = Report::build($devices, $assets, $opt);
check('kpis', $r['kpi'] === ['devices' => 5, 'matched' => 2, 'not_in_glpi' => 2, 'ambiguous' => 1, 'assets' => 4, 'monitored' => 2, 'not_monitored' => 2, 'coverage_pct' => 50]);
check('not in GLPI = unmatched devices', array_column($r['lists']['not_in_glpi'], 'id') === [3, 5]);
check('not monitored = assets without a matched device', array_column($r['lists']['not_monitored'], 'items_id') === [30, 40]);
check('ambiguous = review devices', array_column($r['lists']['ambiguous'], 'id') === [4]);
check('name differs only for IP matches with a different name', array_column($r['lists']['name_differs'], 'id') === [2] && $r['lists']['name_differs'][0]['asset_name'] === 'APP-SERVER');
check('name normalised: case, domain, spaces', Report::normaliseName('  SRV-App.example.com ') === 'srv-app');
check('stale = not seen since last sync minus threshold', array_column($r['lists']['stale'], 'id') === [5]);
check('categories listed for the filter', $r['categories'] === ['Server', 'Switch']);

$p = Report::build($devices, $assets, ['production_only' => true] + $opt);
check('production only: devices filtered by IP', $p['kpi']['devices'] === 3);
check('any IP in range makes the asset production', array_column($p['lists']['not_monitored'], 'items_id') === [30]);
check('production coverage', $p['kpi']['assets'] === 3 && $p['kpi']['coverage_pct'] === 67);
$c = Report::build($devices, $assets, ['category' => 'Switch'] + $opt);
check('category filter applies to devices', $c['kpi']['devices'] === 1);
check('no asset in scope: coverage 0, no division by zero', Report::build([], [], $opt)['kpi']['coverage_pct'] === 0);
check('bad IP never in range', !Report::inRanges('not-an-ip', $opt['ranges']));

$csv = Csv::render(['Name', 'IP'], [['=HYPERLINK("x")', '10.0.0.1'], ['a;b', '+1']]);
check('csv has BOM and ; separator', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "Name;IP\r\n"));
check('formula cells neutralised', str_contains($csv, "\"'=HYPERLINK(\"\"x\"\")\"") && str_contains($csv, "'+1"));
check('separator in a cell is quoted', str_contains($csv, '"a;b"'));

done();
