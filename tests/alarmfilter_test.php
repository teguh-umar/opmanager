<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/AlarmFilter.php';

use GlpiPlugin\Opmanager\Ticketing\AlarmFilter;

$a = static fn (string $type, string $msg = 'Device down') => ['event_type' => $type, 'message' => $msg];

$off = new AlarmFilter('exclude', [], []);
check('empty filter is off', !$off->isActive() && $off->allows($a('CPU Utilization')));
check('empty include filter is off too', (new AlarmFilter('include', [], []))->allows($a('anything')));

$ex = new AlarmFilter('exclude', ['CPU Utilization', 'Memory Utilization'], ['threshold']);
check('exclude: listed event type gets no ticket', !$ex->allows($a('CPU Utilization')));
check('exclude: event type match ignores case and spaces', !$ex->allows($a('  cpu utilization ')));
check('exclude: keyword in message gets no ticket', !$ex->allows($a('Custom', 'Disk C: Threshold violated')));
check('exclude: other alarms still ticketed', $ex->allows($a('Device Down', 'Device not responding')));

$in = new AlarmFilter('include', ['Device Down'], ['interface down']);
check('include: listed event type ticketed', $in->allows($a('device down')));
check('include: keyword in message ticketed', $in->allows($a('Custom', 'Gi0/1 Interface Down')));
check('include: everything else gets no ticket', !$in->allows($a('CPU Utilization', 'CPU at 95%')));

check('unknown mode behaves as exclude', !(new AlarmFilter('bogus', ['X'], []))->allows($a('X')) && (new AlarmFilter('bogus', ['X'], []))->allows($a('Y')));
check('blank list entries ignored', !(new AlarmFilter('exclude', ['', '  '], ['', ' ']))->isActive());

check('fromConfig without filter keys is off', !AlarmFilter::fromConfig(['ticket_severities' => [1]])->isActive());
$fc = AlarmFilter::fromConfig(['filter_mode' => 'include', 'filter_event_types' => ['Device Down'], 'filter_keywords' => []]);
check('fromConfig reads mode and lists', $fc->allows($a('Device Down')) && !$fc->allows($a('CPU Utilization')));

final class NoTableDB { public function tableExists($t) { return false; } }
$GLOBALS['DB'] = new NoTableDB();
check('known event types empty without the alarm table', AlarmFilter::knownEventTypes() === []);

done();
