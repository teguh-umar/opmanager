<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/Decision.php';
require OPM_ROOT . '/src/Ticketing/Messages.php';

use GlpiPlugin\Opmanager\Ticketing\Decision as D;
use GlpiPlugin\Opmanager\Ticketing\Messages;

$base = ['severity' => 1, 'message' => 'Device down', 'is_clear' => false, 'existing' => null, 'ticket_status' => null,
         'ticketable' => true, 'device_open_ticket' => 0, 'storm_ticket' => 0, 'storm_should_start' => false, 'message_followup_due' => true];
$ex = static fn (int $tid, string $state, int $sev = 1, string $msg = 'Device down') => ['tickets_id' => $tid, 'severity' => $sev, 'message' => $msg, 'state' => $state];
$act = static fn (array $c) => D::decide($c + $GLOBALS['base']);

check('new ticketable alarm creates', $act([]) === ['action' => 'create', 'tickets_id' => 0]);
check('not ticketable is stored only', $act(['ticketable' => false])['action'] === 'store');
check('new alarm already clear is stored only', $act(['is_clear' => true, 'severity' => 5])['action'] === 'store');
check('device open ticket → join', $act(['device_open_ticket' => 55]) === ['action' => 'join_device', 'tickets_id' => 55]);
check('active storm → join storm', $act(['storm_ticket' => 66]) === ['action' => 'join_storm', 'tickets_id' => 66]);
check('device join beats storm', $act(['device_open_ticket' => 55, 'storm_ticket' => 66])['action'] === 'join_device');
check('storm threshold reached → start storm', $act(['storm_should_start' => true])['action'] === 'start_storm');

check('unchanged alarm does nothing', $act(['existing' => $ex(9, 'active'), 'ticket_status' => 2]) === ['action' => 'none', 'tickets_id' => 9]);
check('message change is a followup', $act(['message' => 'Device down again', 'existing' => $ex(9, 'active'), 'ticket_status' => 2])['action'] === 'followup');
check('severity drop is a followup', $act(['severity' => 3, 'ticketable' => false, 'existing' => $ex(9, 'active'), 'ticket_status' => 2])['action'] === 'followup');
check('clear on ticketed alarm → clear', $act(['is_clear' => true, 'severity' => 5, 'existing' => $ex(9, 'active'), 'ticket_status' => 2]) === ['action' => 'clear', 'tickets_id' => 9]);
check('clear seen twice → nothing', $act(['is_clear' => true, 'severity' => 5, 'existing' => $ex(9, 'cleared'), 'ticket_status' => 5])['action'] === 'none');
check('same alarm id active again on solved ticket → reopen', $act(['existing' => $ex(9, 'cleared'), 'ticket_status' => 5])['action'] === 'reopen');
check('same alarm id active again on closed ticket → reopen', $act(['existing' => $ex(9, 'cleared'), 'ticket_status' => 6])['action'] === 'reopen');
check('active again, ticket still open → followup', $act(['existing' => $ex(9, 'cleared'), 'ticket_status' => 2])['action'] === 'followup');
check('technician solved while alarm active → no reopen', $act(['existing' => $ex(9, 'active'), 'ticket_status' => 5])['action'] === 'none');
check('severity rise creates ticket', $act(['existing' => $ex(0, 'active', 3)])['action'] === 'create');
check('pending (no ticket yet) is retried as create', $act(['existing' => $ex(0, 'active')])['action'] === 'create');

check('storm starts at threshold', D::shouldStartStorm(10, 10) && !D::shouldStartStorm(9, 10));
check('storm brake off at 0', !D::shouldStartStorm(500, 0));

$alarm = ['alarm_key' => '1', 'severity_label' => 'Critical', 'message' => 'Device down', 'display_name' => 'sw1', 'device_name' => 'sw1', 'mod_time' => '2026-09-30 10:00:00'];
check('messages mention the alarm id', str_contains(Messages::cleared($alarm), '1') && str_contains(Messages::reopened($alarm), '1'));
check('related message names the old ticket', str_contains(Messages::related(123), '#123'));

// Final review Important 4: a trashed (or merged-away) ticket is left alone, never re-ticketed.
check('trashed ticket is left alone', $act(['message' => 'changed', 'existing' => $ex(9, 'active'), 'ticket_status' => D::TRASHED]) === ['action' => 'none', 'tickets_id' => 9]);
check('trashed ticket: clear does nothing', $act(['is_clear' => true, 'severity' => 5, 'existing' => $ex(9, 'active'), 'ticket_status' => D::TRASHED])['action'] === 'none');
// Final review Important 7: message-only changes are throttled; severity changes are not.
check('message-only change not yet due → nothing', $act(['message' => 'CPU 95 %', 'existing' => $ex(9, 'active'), 'ticket_status' => 2, 'message_followup_due' => false])['action'] === 'none');
check('severity change always a followup', $act(['severity' => 3, 'existing' => $ex(9, 'active'), 'ticket_status' => 2, 'message_followup_due' => false])['action'] === 'followup');

done();
