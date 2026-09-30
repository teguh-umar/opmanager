<?php

require __DIR__ . '/bootstrap.php';
foreach (['OpManager/ClientException', 'OpManager/AlarmSource', 'Ticketing/AssetResolver', 'Ticketing/AlarmStore',
          'Ticketing/TicketGateway', 'Ticketing/ProductionCheck', 'Ticketing/Decision', 'Ticketing/Messages', 'Ticketing/AlarmSync'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\AlarmStore;
use GlpiPlugin\Opmanager\Ticketing\AlarmSync;
use GlpiPlugin\Opmanager\Ticketing\AssetResolver;
use GlpiPlugin\Opmanager\Ticketing\ProductionCheck;
use GlpiPlugin\Opmanager\Ticketing\TicketGateway;

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
    public function status(int $t): ?int { return $this->status[$t] ?? null; }
    public function create(array $a, array $asset): int { if ($this->failCreate) { return 0; } $t = $this->next++; $this->status[$t] = 1; $this->log[] = "create {$t} {$a['alarm_key']}"; return $t; }
    public function createStorm(array $a, string $now): int { $t = $this->next++; $this->status[$t] = 1; $this->log[] = "storm {$t}"; return $t; }
    public array $throwOn = [];
    public function followup(int $t, string $x): void { if (in_array($t, $this->throwOn, true)) { throw new RuntimeException('hook exploded'); } $this->log[] = "followup {$t}"; }
    public function reopen(int $t, string $x): void { $this->status[$t] = 2; $this->log[] = "reopen {$t}"; }
    public function solve(int $t, string $x): void { $this->status[$t] = 5; $this->log[] = "solve {$t}"; }
    public function link(int $n, int $o): void { $this->log[] = "link {$n}->{$o}"; }
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

done();
