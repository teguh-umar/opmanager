<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Hygiene/Classifier.php';
require OPM_ROOT . '/src/Hygiene/Report.php';

use GlpiPlugin\Opmanager\Hygiene\Report;

$d = static fn (string $key, string $state, string $name = 'dev') => [
    'device_key' => $key, 'name' => $name, 'ip' => '10.0.0.1', 'category' => 'Server', 'availability' => 'UnManaged',
    'asset' => '', 'state' => $state, 'liveness' => 'dead', 'detail' => 'TCP 22 no answer', 'last_seen' => '2026-11-06 10:00:00',
];
$url = 'https://glpi/plugins/opmanager/front/device.php?x=1';

check('nms title ends with the date', str_ends_with(Report::title('nms', '2026-11-06 12:00:00'), '2026-11-06'));
check('nms title tag', str_starts_with(Report::title('nms', '2026-11-06 12:00:00'), '[OPM-HYGIENE] Unmanaged in OpManager: remove or check'));
check('glpi title tag', str_starts_with(Report::title('glpi', '2026-11-06 12:00:00'), '[OPM-HYGIENE] Monitored in OpManager, not in GLPI'));

$body = Report::body('nms', [$d('u1', 'nms_unknown'), $d('d1', 'nms_dead', '<b>x</b>')], $url);
$dead = strpos($body, 'Dead - candidate for removal from OpManager');
$unk  = strpos($body, 'Liveness unknown - check manually');
check('nms body: dead section before unknown section', $dead !== false && $unk !== false && $dead < $unk);
check('device name escaped', str_contains($body, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($body, '<b>x</b>'));
check('body links the device list', str_contains($body, htmlspecialchars($url, ENT_QUOTES)));
check('body explains exclusions', str_contains($body, 'Exclude a device from these tickets on its OpManager device page (reason required).'));
check('body shows the liveness evidence', str_contains($body, 'TCP 22 no answer'));

// Opsi A (rc3): one NMS ticket, each section split by domain so DCOC and ITOC each see their part.
check('domain: Server is server', Report::domain('Server', '') === 'server');
check('domain: Storage is server', Report::domain('Storage', '') === 'server');
check('domain: Domain Controller (with space) is server', Report::domain('Domain Controller', '') === 'server');
check('domain: Switch is network', Report::domain('Switch', '') === 'network');
check('domain: wireless any case is network', Report::domain('WIRELESS', '') === 'network');
check('domain: Firewall is network', Report::domain('Firewall', '') === 'network');
check('domain: Unknown category falls back to the GLPI Computer', Report::domain('Unknown', 'Computer') === 'server');
check('domain: Unknown category falls back to the GLPI NetworkEquipment', Report::domain('Unknown', 'NetworkEquipment') === 'network');
check('domain: Unknown and no asset is other', Report::domain('Unknown', '') === 'other');
check('domain: category wins over the asset type', Report::domain('Switch', 'Computer') === 'network');

$sw  = ['category' => 'Switch'] + $d('s1', 'nms_dead', 'sw-one');
$srv = $d('v1', 'nms_dead', 'srv-one');
$unk = ['category' => 'Unknown'] + $d('x1', 'nms_dead', 'unk-one');
$body = Report::body('nms', [$unk, $sw, $srv], $url);
$p = static fn (string $s): int|false => strpos($body, $s);
check('nms body: server sub-heading', $p('Server, VM and storage (1)') !== false);
check('nms body: network sub-heading', $p('Network (1)') !== false);
check('nms body: unknown category sub-heading', $p('Unknown category (1)') !== false);
check('nms body: server, then network, then unknown', $p('srv-one') < $p('sw-one') && $p('sw-one') < $p('unk-one'));
check('nms body: no empty domain heading', !str_contains(Report::body('nms', [$srv], $url), 'Network ('));

// rc4: the "not in GLPI" ticket is split the same way (SysAdmin registers servers, ITOC network).
$gbody = Report::body('glpi', [['category' => 'Switch'] + $d('g2', 'glpi_missing', 'gsw'), $d('g3', 'glpi_missing', 'gsrv')], $url);
check('glpi body: split by domain, server before network', strpos($gbody, 'Server, VM and storage (1)') !== false
    && strpos($gbody, 'Network (1)') !== false && strpos($gbody, 'gsrv') < strpos($gbody, 'gsw'));

$body = Report::body('glpi', [$d('g1', 'glpi_missing')], $url);
check('glpi body has not-found section', str_contains($body, 'Not found in GLPI'));
check('glpi body without review devices has no pairing section', !str_contains($body, 'Needs manual pairing'));

$by = ['a' => $d('a', 'nms_dead', 'alpha'), 'b' => $d('b', 'nms_unknown', 'beta')];
$f  = Report::followup(['action' => 'followup', 'new' => [], 'changed' => [], 'resolved' => ['a']], $by);
check('followup with only resolved: no New heading', !str_contains($f, '>New<'));
check('followup with only resolved: no Changed heading', !str_contains($f, 'Changed condition'));
check('followup lists the resolved device', str_contains($f, 'No longer applies') && str_contains($f, 'alpha'));
$f = Report::followup(['action' => 'followup', 'new' => ['b' => 'nms_unknown'], 'changed' => ['a' => ['nms_dead', 'nms_unknown']], 'resolved' => []], $by);
check('followup new + changed headings', str_contains($f, '>New<') && str_contains($f, 'Changed condition (old → new)'));
check('changed row shows both labels', str_contains($f, 'Unmanaged, dead: remove from OpManager') && str_contains($f, 'Unmanaged, liveness unknown: check manually'));
$f = Report::followup(['action' => 'followup', 'new' => [], 'changed' => [], 'resolved' => ['zzz']], $by);
check('resolved device no longer in the table still listed by key', str_contains($f, 'zzz'));

done();
