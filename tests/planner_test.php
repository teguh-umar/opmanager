<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Outbound/Planner.php';

use GlpiPlugin\Opmanager\Outbound\Planner as P;

check('logged-in technician is human', P::isHuman(7, false, 3, false));
check('no login is not human (cron/webhook)', !P::isHuman(false, false, 3, false));
check('cron is not human', !P::isHuman(7, true, 3, false));
check('service user is not human', !P::isHuman(3, false, 3, false));
check('plugin writing blocks outbound', !P::isHuman(7, false, 3, true));

$active = [['alarm_key' => '1', 'entity_key' => 'E1'], ['alarm_key' => '2', 'entity_key' => 'E2']];
$none   = static fn (string $k) => false;

$rows = P::forFollowup(55, false, 'Kabel diganti', 'budi', $active, 7);
check('public followup: one note per active alarm', count($rows) === 2 && $rows[0]['action'] === 'note' && $rows[1]['entity_key'] === 'E2');
check('followup note names ticket and user', $rows[0]['payload'] === 'GLPI tiket #55 - followup oleh budi: Kabel diganti' && $rows[0]['tickets_id'] === 55 && $rows[0]['users_id'] === 7);
check('private followup not sent', P::forFollowup(55, true, 'internal', 'budi', $active, 7) === []);
check('empty followup not sent', P::forFollowup(55, false, '   ', 'budi', $active, 7) === []);
check('no active alarm, nothing queued', P::forFollowup(55, false, 'x', 'budi', [], 7) === [] && P::forStatus(55, 2, 5, 'done', 'budi', [], $none, 7) === []);

$rows = P::forStatus(55, 2, 5, 'Port di-reset', 'budi', $active, $none, 7);
check('solve: note then clear per alarm', array_map(fn ($r) => $r['action'] . $r['alarm_key'], $rows) === ['note1', 'clear1', 'note2', 'clear2']);
check('solve note carries the solution', $rows[0]['payload'] === 'GLPI tiket #55 di-Solve oleh budi: Port di-reset');
$rows = P::forStatus(55, 2, 6, 'Port di-reset', 'budi', $active, $none, 7);
check('solution text wins over close note (autoclose 0)', $rows[0]['payload'] === 'GLPI tiket #55 di-Solve oleh budi: Port di-reset');
$rows = P::forStatus(55, 2, 6, null, 'budi', $active, $none, 7);
check('close without solution: short note then clear (D26)', $rows[0]['payload'] === 'Tiket #55 ditutup oleh budi di GLPI' && $rows[1]['action'] === 'clear');
check('already solved/closed before: nothing', P::forStatus(55, 5, 6, null, 'budi', $active, $none, 7) === [] && P::forStatus(55, 6, 6, 'x', 'budi', $active, $none, 7) === []);
check('not a solve/close: nothing', P::forStatus(55, 1, 2, null, 'budi', $active, $none, 7) === []);
$rows = P::forStatus(55, 2, 5, 'x', 'budi', $active, static fn (string $k) => $k === '1', 7);
check('alarm with a clear already queued is skipped', array_map(fn ($r) => $r['alarm_key'], $rows) === ['2', '2']);
$long = P::forFollowup(55, false, str_repeat('a', 5000), 'budi', $active, 7);
check('note payload capped', strlen($long[0]['payload']) <= P::NOTE_MAX);

check('own followup by a technician is original', P::isOriginalFollowup(7, 3, 0));
check('followup authored by the service user is not sent', !P::isOriginalFollowup(3, 3, 0));
check('followup copied by a merge is not sent', !P::isOriginalFollowup(7, 3, 12));

done();
