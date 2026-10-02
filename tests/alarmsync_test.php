<?php

require __DIR__ . '/bootstrap.php';
foreach (['OpManager/ClientException', 'OpManager/AlarmSource', 'Ticketing/AssetResolver', 'Ticketing/AlarmStore',
          'Ticketing/TicketGateway', 'Ticketing/TicketRequestStore', 'Ticketing/ProductionCheck', 'Ticketing/Decision', 'Ticketing/Messages', 'Ticketing/AlarmFilter', 'Ticketing/AlarmSync'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\AlarmStore;
use GlpiPlugin\Opmanager\Ticketing\AlarmSync;
use GlpiPlugin\Opmanager\Ticketing\AssetResolver;
use GlpiPlugin\Opmanager\Ticketing\ProductionCheck;
use GlpiPlugin\Opmanager\Ticketing\TicketGateway;
use GlpiPlugin\Opmanager\Ticketing\TicketRequestStore;

final class Src implements AlarmSource
{
    public array $byEntity = [];
    public ?ClientException $fail = null;
    public array $entityCalls = [];
    public function __construct(public array $active) {}
    public function listActiveAlarms(): array { if ($this->fail) { throw $this->fail; } return $this->active; }
    public function alarmByEntity(string $e): ?array { $this->entityCalls[] = $e; if (($this->byEntity[$e] ?? null) instanceof ClientException) { throw $this->byEntity[$e]; } return $this->byEntity[$e] ?? null; }
}
final class Store implements AlarmStore
{
    public array $rows = []; public array $storms = []; private int $next = 1;
    public function find(string $k): ?array { foreach ($this->rows as $r) { if ($r['alarm_key'] === $k) { return $r; } } return null; }
    public function save(array $a, array $asset, string $state, string $now): int
    {
        foreach ($this->rows as $id => $r) {
            if ($r['alarm_key'] === $a['alarm_key']) {
                $this->rows[$id] = ['severity' => $a['severity'], 'message' => $a['message'], 'state' => $state] + $r;
                return $id;
            }
        }
        $id = $this->next++;
        $this->rows[$id] = ['id' => $id, 'alarm_key' => $a['alarm_key'], 'entity_key' => $a['entity_key'], 'device_name' => $a['device_name'],
            'alarm_code' => $a['alarm_code'], 'severity' => $a['severity'], 'message' => $a['message'], 'state' => $state,
            'tickets_id' => 0, 'role' => '', 'pending' => 0, 'created_at' => '', 'last_followup_at' => ''];
        return $id;
    }
    public function moveTicket(int $id, int $t): void { $this->rows[$id]['tickets_id'] = $t; }
    public function setTicket(int $id, int $t, string $role, string $now): void { $this->rows[$id]['tickets_id'] = $t; $this->rows[$id]['role'] = $role; $this->rows[$id]['pending'] = 0; $this->rows[$id]['created_at'] = $now; }
    public function markPending(int $id): void { $this->rows[$id]['pending'] = 1; }
    public function activeNotSeen(array $keys): array { return array_values(array_filter($this->rows, fn ($r) => $r['state'] === 'active' && !in_array($r['alarm_key'], $keys, true))); }
    public function ticketsForDevice(string $d): array { return array_values(array_unique(array_map(fn ($r) => $r['tickets_id'], array_filter($this->rows, fn ($r) => $r['device_name'] === $d && $r['tickets_id'] > 0 && $r['role'] !== 'storm')))); }
    public function allCleared(int $t): bool { foreach ($this->rows as $r) { if ($r['tickets_id'] === $t && $r['state'] !== 'cleared') { return false; } } return true; }
    public function ownTicketsSince(string $since): int { return count(array_filter($this->rows, fn ($r) => $r['role'] === 'own' && $r['created_at'] >= $since)); }
    public function recentRelatedTickets(string $d, string $c, int $ex): array { return array_values(array_unique(array_map(fn ($r) => $r['tickets_id'], array_filter($this->rows, fn ($r) => $r['device_name'] === $d && $r['alarm_code'] === $c && $r['tickets_id'] > 0 && $r['tickets_id'] !== $ex)))); }
    public array $stormWindows = [];
    public function activeStorm(string $now, int $w): int { $this->stormWindows[] = $w; return $this->storms['active'] ?? 0; }
    public function endStorm(int $t, string $now): void { unset($this->storms['active']); }
    public function markFollowup(int $id, string $now): void { $this->rows[$id]['last_followup_at'] = $now; }
    public function startStorm(int $t, string $now): void { $this->storms['active'] = $t; }
    public function hitStorm(int $t, string $now): void { $this->storms['hits'] = ($this->storms['hits'] ?? 0) + 1; }
}
final class Gw implements TicketGateway
{
    public array $status = []; public array $log = []; public bool $failCreate = false; private int $next = 100; public array $closedSince = [];
    public array $merged = []; public array $beforeTrash = [];
    public function status(int $t): ?int { return $this->status[$t] ?? null; }
    public function create(array $a, array $asset): int { if ($this->failCreate) { return 0; } $t = $this->next++; $this->status[$t] = 1; $this->log[] = "create {$t} {$a['alarm_key']}"; return $t; }
    public function createStorm(array $a, string $now): int { $t = $this->next++; $this->status[$t] = 1; $this->log[] = "storm {$t}"; return $t; }
    public array $throwOn = [];
    public function followup(int $t, string $x): void { if (in_array($t, $this->throwOn, true)) { throw new RuntimeException('hook exploded'); } $this->log[] = "followup {$t}"; }
    public function reopen(int $t, string $x): void { $this->status[$t] = 2; $this->log[] = "reopen {$t}"; }
    public function solve(int $t, string $x): void { $this->status[$t] = 5; $this->log[] = "solve {$t}"; }
    public function link(int $n, int $o): void { $this->log[] = "link {$n}->{$o}"; }
    public function restore(int $t): void { $this->status[$t] = $this->beforeTrash[$t] ?? 2; $this->log[] = "restore {$t}"; }
    public function mergedInto(int $t): int { return $this->merged[$t] ?? 0; }
    public function closedSince(int $t, string $since): bool { return in_array($t, $this->closedSince, true); }
}
final class Prod implements ProductionCheck
{
    public function isProduction(array $alarm, array $asset): bool { return !str_starts_with((string) $alarm['device_name'], 'np'); }
}
final class Assets implements AssetResolver
{
    public function resolve(array $a): array { return ['devices_id' => 0, 'itemtype' => 'Computer', 'items_id' => 1]; }
}
final class Req implements TicketRequestStore
{
    public array $at = [];
    public function record(string $k, string $e): void { $this->at[$k] = '2026-09-30 10:00:00'; }
    public function keys(): array { return array_map('strval', array_keys($this->at)); }
    public function delete(string $k): void { unset($this->at[$k]); }
    public function purgeBefore(string $b): void { $this->at = array_filter($this->at, fn ($t) => $t >= $b); }
}


$al = static fn (string $key, int $sev, string $dev = 'sw1', string $msg = 'Device down', string $code = 'DOWN') => [
    'alarm_key' => $key, 'entity_key' => "E{$key}", 'device_name' => $dev, 'display_name' => $dev, 'ip_address' => '',
    'category' => '', 'alarm_code' => $code, 'event_type' => '', 'severity' => $sev,
    'severity_label' => [1 => 'Critical', 3 => 'Attention', 5 => 'Clear'][$sev] ?? 'x', 'message' => $msg, 'mod_time' => '',
];
$cfg = ['ticket_severities' => [1], 'relink_window_hours' => 24, 'storm_threshold' => 10, 'storm_window_minutes' => 5, 'storm_idle_minutes' => 11];
$now = '2026-09-30 10:00:00';
$mk = static function (array $active) use ($cfg) {
    $s = new Src($active); $st = new Store(); $g = new Gw();
    return [new AlarmSync($s, $st, $g, new Assets(), new Prod(), $cfg), $s, $st, $g];
};

// create, then a second identical poll is a no-op
[$sync, $src, $store, $gw] = $mk([$al('1', 1)]);
$stats = $sync->run($now);
check('first poll creates one ticket', $stats['created'] === 1 && $gw->log === ['create 100 1']);
$gw->log = [];
$stats = $sync->run($now);
check('second poll is a no-op', $gw->log === [] && $stats['created'] === 0);

// second alarm on the same device joins the open ticket
$src->active[] = $al('2', 1);
$sync->run($now);
check('same device joins the open ticket', $gw->log === ['followup 100'] && $store->find('2')['tickets_id'] === 100);

// alarm 1 clears (still listed, severity 5): followup, but ticket stays open while alarm 2 is active
$gw->log = [];
$src->active = [$al('1', 5), $al('2', 1)];
$sync->run($now);
check('one of two alarms clear: followup, no solve', $gw->log === ['followup 100']);

// alarm 2 disappears from ActiveAlarms and is confirmed Clear by entity
$gw->log = [];
$src->active = [];
$src->byEntity = ['E2' => $al('2', 5)];
$stats = $sync->run($now);
check('missing alarm confirmed Clear → cleared', $store->find('2')['state'] === 'cleared');
check('all alarms clear → solved', in_array('solve 100', $gw->log, true) && $stats['solved'] === 1);

// the same alarm id comes back → reopen
$gw->log = [];
$src->active = [$al('1', 1)];
$sync->run($now);
check('same alarm id back on solved ticket → reopen', $gw->log === ['reopen 100']);

// D20: missing alarm WITHOUT confirmed clear stays active; source failure touches nothing
[$sync, $src, $store, $gw] = $mk([$al('7', 1)]);
$sync->run($now);
$src->active = [];
$src->byEntity = ['E7' => $al('7', 1)];
$sync->run($now);
check('missing alarm without confirmed clear stays active', $store->find('7')['state'] === 'active');
$src->byEntity = ['E7' => new ClientException('down')];
$sync->run($now);
check('entity lookup failure keeps it active', $store->find('7')['state'] === 'active');
$src->byEntity = [];
$sync->run($now);
check('entity lookup with no answer keeps it active', $store->find('7')['state'] === 'active');
$gw->log = [];
$src->fail = new ClientException('OpManager error 5000: key');
try {
    $sync->run($now);
    check('source failure touches nothing', false);
} catch (ClientException) {
    check('source failure touches nothing', $gw->log === [] && $store->find('7')['state'] === 'active');
}

// non-ticketable alarm stored only; severity rise creates the ticket
[$sync, $src, $store, $gw] = $mk([$al('9', 3)]);
$stats = $sync->run($now);
check('attention alarm stored without ticket', $stats['stored'] === 1 && $gw->log === []);
$src->active = [$al('9', 1)];
$sync->run($now);
check('attention → critical creates ticket', $gw->log === ['create 100 9']);

// failed create is pending and retried
[$sync, $src, $store, $gw] = $mk([$al('11', 1)]);
$gw->failCreate = true;
$stats = $sync->run($now);
check('failed create is pending', $stats['pending'] === 1 && $store->find('11')['pending'] === 1);
$gw->failCreate = false;
$sync->run($now);
check('failed create is pending and retried', $store->find('11')['tickets_id'] === 100);

// relink to a recently closed ticket of the same device + alarm type
[$sync, $src, $store, $gw] = $mk([$al('20', 1, 'sw9')]);
$sync->run($now);
$store->rows[1]['state'] = 'cleared';
$gw->status[100] = 6;
$gw->closedSince = [100];
$gw->log = [];
$src->active = [$al('21', 1, 'sw9')];
$stats = $sync->run($now);
check('repeat within window is linked', in_array('link 101->100', $gw->log, true) && $stats['linked'] === 1);

// storm: 10 own tickets in the window → next alarm starts a storm, the one after joins it
[$sync, $src, $store, $gw] = $mk(array_map(fn ($i) => $al("s{$i}", 1, "dev{$i}"), range(1, 12)));
$stats = $sync->run($now);
check('storm starts after threshold', $stats['created'] === 10 && $stats['storm'] === 2 && in_array('storm 110', $gw->log, true));
check('storm alarms point at the storm ticket', $store->find('s12')['tickets_id'] === 110);

// Final review Important 1: an old backlog on the first poll must not start a storm.
[$sync, $src, $store, $gw] = $mk(array_map(fn ($i) => ['mod_time' => '2026-09-29 08:00:00'] + $al("b{$i}", 1, "old{$i}"), range(1, 12)));
$stats = $sync->run($now);
check('backlog alarms never start a storm', $stats['created'] === 12 && $stats['storm'] === 0);

// Final review Important 2: the storm stays alive across polls (idle window >= poll interval).
[$sync, $src, $store, $gw] = $mk([$al('x1', 1, 'devx')]);
$store->storms['active'] = 500;
$gw->status[500] = 2;
$sync->run($now);
check('store asked with the idle window, not the raw storm window', in_array(11, $store->stormWindows, true) && !in_array(5, $store->stormWindows, true));

// Final review Important 3: a storm ticket closed by a technician ends the storm.
[$sync, $src, $store, $gw] = $mk([$al('y1', 1, 'devy')]);
$store->storms['active'] = 600;
$gw->status[600] = 5;
$sync->run($now);
check('closed storm ticket is not joined', $store->find('y1')['tickets_id'] !== 600 && !isset($store->storms['active']));

// Final review Important 4: a trashed ticket is not replaced by a new one.
[$sync, $src, $store, $gw] = $mk([$al('t1', 1)]);
$sync->run($now);
$gw->status[100] = TicketGateway::TRASHED;
$gw->log = [];
$src->active = [$al('t1', 1, 'sw1', 'changed text')];
$sync->run($now);
check('trashed ticket is not re-ticketed', $gw->log === [] && $store->find('t1')['tickets_id'] === 100);

// Final review Important 5: one failing alarm does not stop the run.
[$sync, $src, $store, $gw] = $mk([$al('e1', 1, 'dev-e'), $al('e2', 1, 'dev-e'), $al('e3', 1, 'dev-f')]);
$gw->throwOn = [100];
$stats = $sync->run($now);
check('exception on one alarm is isolated', $stats['errors'] === 1 && $store->find('e3')['tickets_id'] === 101);
check('errors are reported with the alarm id', str_contains(implode(' ', $sync->lastErrors()), 'e2'));

// Final review Important 7: a live-value message change is throttled to one followup per hour.
[$sync, $src, $store, $gw] = $mk([$al('m1', 1, 'swm', 'CPU 91 %')]);
$sync->run($now);
$gw->log = [];
$src->active = [$al('m1', 1, 'swm', 'CPU 93 %')];
$sync->run($now);
$src->active = [$al('m1', 1, 'swm', 'CPU 95 %')];
$sync->run('2026-09-30 10:10:00');
check('message-only changes throttled', $gw->log === ['followup 100']);

// Production-only tickets: a Critical alarm outside the production ranges is stored, not ticketed.
[$sync, $src, $store, $gw] = $mk([$al('p1', 1, 'np-lab-server'), $al('p2', 1, 'prod-db')]);
$stats = $sync->run($now);
check('non-production critical alarm is stored only', $store->find('p1')['tickets_id'] === 0 && $stats['stored'] === 1);
check('production critical alarm gets a ticket', $store->find('p2')['tickets_id'] === 100);

// v0.3.0: the webhook processes one alarm through the same engine.
[$sync, $src, $store, $gw] = $mk([]);
$stats = $sync->processOne($al('w1', 1, 'dev-w'), $now);
check('processOne creates the ticket', $stats['created'] === 1 && $store->find('w1')['tickets_id'] === 100);
$stats = $sync->processOne($al('w1', 1, 'dev-w'), $now);
check('lock serialises processing: second identical call is a no-op', $stats['created'] === 0 && $gw->log === ['create 100 w1']);

// v0.3.0: alarm filter (event type / message keyword) only blocks NEW tickets.
$typed = static fn (string $key, int $sev, string $type, string $msg = 'x') => ['event_type' => $type] + $al($key, $sev, "dev-{$key}", $msg);
$filtered = $cfg + ['filter_mode' => 'exclude', 'filter_event_types' => ['CPU Utilization'], 'filter_keywords' => ['threshold']];
$s = new Src([$typed('f1', 1, 'CPU Utilization'), $typed('f2', 1, 'Custom', 'Disk Threshold violated'), $typed('f3', 1, 'Device Down')]);
$store = new Store(); $gw = new Gw();
$sync = new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $filtered);
$sync->run($now);
check('excluded event type is stored without a ticket', $store->find('f1') !== null && $store->find('f1')['tickets_id'] === 0);
check('excluded keyword is stored without a ticket', $store->find('f2') !== null && $store->find('f2')['tickets_id'] === 0);
check('other alarm still gets its ticket', $store->find('f3')['tickets_id'] > 0);

// an alarm that already has a ticket keeps its lifecycle after it becomes filtered
[$sync0, $src0, $store0, $gw0] = $mk([$typed('g1', 1, 'CPU Utilization')]);
$sync0->run($now);
$t = $store0->find('g1')['tickets_id'];
$gw0->log = [];
$sync1 = new AlarmSync($src0, $store0, $gw0, new Assets(), new Prod(), $filtered);
$src0->active = [$typed('g1', 5, 'CPU Utilization')];
$sync1->run($now);
check('filtered alarm with an existing ticket is still solved on clear', $t > 0 && in_array("solve {$t}", $gw0->log, true));

$inc = $cfg + ['filter_mode' => 'include', 'filter_event_types' => ['Device Down'], 'filter_keywords' => []];
$store = new Store(); $gw = new Gw();
$sync = new AlarmSync(new Src([$typed('h1', 1, 'CPU Utilization'), $typed('h2', 1, 'Device Down')]), $store, $gw, new Assets(), new Prod(), $inc);
$sync->run($now);
check('include mode: only listed alarms get tickets', $store->find('h1')['tickets_id'] === 0 && $store->find('h2')['tickets_id'] > 0);

// v1.0.1 (D30): a Closed ticket is never reopened; a recurrence is a new alarm again.
$excl = $cfg + ['filter_mode' => 'exclude', 'filter_event_types' => ['Threshold Down'], 'filter_keywords' => []];
$cpu  = static fn (int $sev) => ['event_type' => 'Threshold Down'] + $al('c1', $sev, 'dbserver2', 'CPU 92%', 'CPU');
$dd   = static fn (int $sev) => ['event_type' => 'Device Down'] + $al('d1', $sev, 'sw9', 'Device down', 'DOWN');
$s = new Src([$cpu(1)]); $store = new Store(); $gw = new Gw();
(new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $cfg))->run($now);   // ticketed before the filter existed
$old = $store->find('c1')['tickets_id'];
$gw->status[$old] = 6; $gw->log = [];
$sync = new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $excl);
$s->active = [$cpu(5)];
$sync->run($now);
check('clear on a closed ticket: no followup', $gw->log === []);
$s->active = [$cpu(1)];
$sync->run($now);
check('filtered recurrence after Close: no reopen, no new ticket', $gw->log === [] && $gw->status[$old] === 6);
$s->active = [$cpu(5)]; $sync->run($now); $s->active = [$cpu(1)]; $sync->run($now);
check('flapping filtered alarm stays silent', $gw->log === []);

$s = new Src([$dd(1)]); $store = new Store(); $gw = new Gw();
$sync = new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $excl);
$sync->run($now);
$old = $store->find('d1')['tickets_id'];
$gw->status[$old] = 6; $gw->log = [];
$s->active = [$dd(5)]; $sync->run($now);
$s->active = [$dd(1)]; $sync->run($now);
$new = $store->find('d1')['tickets_id'];
check('allowed recurrence after Close: new ticket linked to the closed one', $new !== $old && in_array("create {$new} d1", $gw->log, true)
    && in_array("link {$new}->{$old}", $gw->log, true) && !in_array("reopen {$old}", $gw->log, true));

$s = new Src([$cpu(1)]); $store = new Store(); $gw = new Gw();
(new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $cfg))->run($now);
$old = $store->find('c1')['tickets_id'];
$gw->status[$old] = 5; $gw->log = [];
$sync = new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $excl);
$s->active = [$cpu(5)]; $sync->run($now);
$gw->log = [];
$s->active = [$cpu(1)]; $sync->run($now);
check('filtered recurrence on a Solved ticket: no reopen', $gw->log === []);

// v1.0.2 (D31): a flapping CPU alarm on a Solved ticket stays silent (prod 2026-10-03:
// one "is Clear" followup every flap, every 30 min).
$s = new Src([$cpu(1)]); $store = new Store(); $gw = new Gw();
(new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $cfg))->run($now);
$old = $store->find('c1')['tickets_id'];
$gw->status[$old] = 5; $gw->log = [];
$sync = new AlarmSync($s, $store, $gw, new Assets(), new Prod(), $excl);
foreach (['10:30', '11:00', '11:30', '12:00', '12:30', '13:00'] as $i => $hm) {
    $s->active = [$cpu($i % 2 === 0 ? 5 : 1)];
    $sync->run("2026-09-30 {$hm}:00");
}
check('flapping filtered alarm on a Solved ticket: no followup at all', $gw->log === []);

// D31: on an open ticket every plugin followup of one alarm is at most one per hour.
[$sync, $src, $store, $gw] = $mk([$al('f1', 1, 'swf'), $al('f2', 1, 'swf')]);
$sync->run($now);                                              // f1 creates 100, f2 joins it
$gw->log = [];
foreach (['10:30', '11:00', '11:30', '12:00'] as $i => $hm) {    // f2 flaps Clear / Critical
    $src->active = [$al('f1', 1, 'swf'), $al('f2', $i % 2 === 0 ? 5 : 1, 'swf')];
    $sync->run("2026-09-30 {$hm}:00");
}
check('flapping alarm on an open ticket: one followup per hour', $gw->log === ['followup 100', 'followup 100']);
$src->active = [$al('f1', 5, 'swf'), $al('f2', 5, 'swf')];
$sync->run('2026-09-30 12:10:00');
check('throttled Clear still solves the ticket', in_array('solve 100', $gw->log, true));

[$sync, $src, $store, $gw] = $mk([$al('v1', 1, 'swv', 'CPU')]);
$sync->run($now);
$gw->log = [];
foreach (['10:20', '10:40', '11:00', '11:20'] as $i => $hm) {           // severity flaps Critical / Attention
    $src->active = [$al('v1', $i % 2 === 0 ? 3 : 1, 'swv', 'CPU')];
    $sync->run("2026-09-30 {$hm}:00");
}
check('severity flapping on an open ticket: one followup per hour', $gw->log === ['followup 100', 'followup 100']);

// v1.1.0 (D32): ticket source = OpManager webhook criteria.
$wh = $cfg + ['ticket_source' => 'webhook'];
$mkw = static function (array $active, ?Req $req = null) use ($wh) {
    $s = new Src($active); $st = new Store(); $g = new Gw();
    return [new AlarmSync($s, $st, $g, new Assets(), new Prod(), $wh, $req), $s, $st, $g];
};

[$sync, $src, $store, $gw] = $mkw([$al('n1', 1, 'srv1', 'CPU 99%')]);
$stats = $sync->run($now);
check('webhook mode: poll alone never creates a ticket', $gw->log === [] && $store->find('n1')['tickets_id'] === 0 && $stats['stored'] === 1);
$sync->processOne($al('n1', 1, 'srv1', 'CPU 99%'), $now);
check('webhook mode: webhook creates the ticket', $gw->log === ['create 100 n1']);
$sync->processOne($al('n2', 2, 'np-lab', 'Device down'), $now);
check('webhook mode: non-production webhook alarm is stored only', $store->find('n2')['tickets_id'] === 0);
$sync->processOne($al('n3', 3, 'srv3'), $now);
check('webhook mode: severity is not a condition', $store->find('n3')['tickets_id'] > 0);

// Solved ticket: poll recurrence silent, webhook recurrence reopens.
[$sync, $src, $store, $gw] = $mkw([]);
$sync->processOne($al('s1', 1, 'srv-s'), $now);
$src->active = [$al('s1', 5, 'srv-s')]; $sync->run('2026-09-30 10:10:00');     // Clear -> Solved
$gw->log = [];
foreach (['10:40', '11:10', '11:40'] as $i => $hm) { $src->active = [$al('s1', $i % 2 === 0 ? 1 : 5, 'srv-s')]; $sync->run("2026-09-30 {$hm}:00"); }
check('webhook mode: poll flapping on a Solved ticket stays silent', $gw->log === []);
$sync->processOne($al('s1', 1, 'srv-s'), '2026-09-30 12:00:00');
check('webhook mode: webhook recurrence reopens the Solved ticket', $gw->log === ['reopen 100']);

// Trash: poll leaves it, webhook restores; Solved -> reopen, Closed -> new linked ticket.
[$sync, $src, $store, $gw] = $mkw([]);
$sync->processOne($al('t1', 1, 'srv-t'), $now);
$gw->status[100] = TicketGateway::TRASHED; $gw->beforeTrash[100] = 5; $gw->log = [];
$src->active = [$al('t1', 1, 'srv-t', 'Device down again')]; $sync->run('2026-09-30 11:30:00');
check('webhook mode: poll leaves a trashed ticket alone', $gw->log === []);
$sync->processOne($al('t1', 5, 'srv-t'), '2026-09-30 11:40:00');
check('webhook mode: webhook Clear does not restore', $gw->log === [] && $gw->status[100] === TicketGateway::TRASHED);
$sync->processOne($al('t1', 1, 'srv-t'), '2026-09-30 12:00:00');
check('webhook mode: webhook restores and reopens a trashed Solved ticket', $gw->log === ['restore 100', 'reopen 100']);

[$sync, $src, $store, $gw] = $mkw([]);
$sync->processOne($al('t2', 1, 'srv-u'), $now);
$gw->status[100] = TicketGateway::TRASHED; $gw->beforeTrash[100] = 6; $gw->log = [];
$sync->processOne($al('t2', 1, 'srv-u'), '2026-09-30 12:00:00');
check('webhook mode: trashed Closed ticket is restored and a new linked ticket created',
    $gw->log[0] === 'restore 100' && in_array('create 101 t2', $gw->log, true) && in_array('link 101->100', $gw->log, true) && $store->find('t2')['tickets_id'] === 101);

// Deferred requests: consumed when processed, kept on failure, purged after 24 h.
$req = new Req();
$req->record('r1', 'Er1'); $req->record('r2', 'Er2');
$req->at['old'] = '2026-09-29 09:00:00';
[$sync, $src, $store, $gw] = $mkw([$al('r1', 1, 'srv-r'), $al('r2', 1, 'srv-q')], $req);
$sync->run($now);
check('webhook mode: deferred request creates the ticket on the next poll', $store->find('r1')['tickets_id'] > 0 && $store->find('r2')['tickets_id'] > 0);
check('webhook mode: processed requests are deleted, old ones purged', $req->at === []);
$req->record('r3', 'Er3'); $req->record('r4', 'Er4');
[$sync, $src, $store, $gw] = $mkw([$al('r3', 1, 'srv-x'), $al('r4', 1, 'srv-x')], $req);
$gw->throwOn = [100];                       // r3 creates 100; r4 joins 100 and its followup throws
$sync->run($now);
check('webhook mode: a request whose alarm failed is kept', isset($req->at['r4']) && !isset($req->at['r3']));

// Merge: alarm follows the target; a chain longer than 5 is left alone.
[$sync, $src, $store, $gw] = $mkw([]);
$sync->processOne($al('m1', 1, 'srv-m', 'CPU 91'), $now);
$gw->status[100] = TicketGateway::TRASHED; $gw->merged[100] = 200; $gw->status[200] = 2; $gw->log = [];
$src->active = [$al('m1', 2, 'srv-m', 'CPU 91')]; $sync->run('2026-09-30 11:30:00');
check('merged ticket: alarm moves to the merge target', $store->find('m1')['tickets_id'] === 200 && $gw->log === ['followup 200']);
[$sync, $src, $store, $gw] = $mkw([]);
$sync->processOne($al('m2', 1, 'srv-n'), $now);
for ($t = 100; $t < 106; $t++) { $gw->status[$t] = TicketGateway::TRASHED; $gw->merged[$t] = $t + 1; }
$gw->status[106] = 2; $gw->log = [];
$sync->processOne($al('m2', 2, 'srv-n'), '2026-09-30 11:30:00');
check('merge chain longer than 5 is left alone', $store->find('m2')['tickets_id'] === 100 && $gw->log === []);
$gw->merged = [100 => 101, 101 => 100]; $gw->status[101] = TicketGateway::TRASHED;
$sync->processOne($al('m2', 1, 'srv-n'), '2026-09-30 12:30:00');
check('merge cycle is left alone', $store->find('m2')['tickets_id'] === 100 && $gw->log === []);

done();
