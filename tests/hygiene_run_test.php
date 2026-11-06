<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Config.php';
require OPM_ROOT . '/src/Ticketing/TicketGateway.php';
foreach (['Classifier', 'Cidr', 'Liveness', 'Providers', 'TicketPlanner', 'Report', 'HygieneStore', 'HygieneGateway', 'HygieneRun'] as $c) {
    require OPM_ROOT . "/src/Hygiene/{$c}.php";
}

use GlpiPlugin\Opmanager\Hygiene\HygieneGateway;
use GlpiPlugin\Opmanager\Hygiene\HygieneRun;
use GlpiPlugin\Opmanager\Hygiene\HygieneStore;

final class MemStore implements HygieneStore
{
    public array $devices = [];
    public array $exceptions = [];
    public array $saved = [];
    public array $tickets = [];   // id => [flow, tickets_id]
    public array $items = [];     // hygienetickets_id => [key => [state, resolved]]

    public function devices(): array { return $this->devices; }
    public function activeExceptionKeys(): array { return $this->exceptions; }
    public function saveDevice(int $id, array $f): void
    {
        $this->saved[$id] = $f;
        foreach ($this->devices as &$d) {
            if ($d['id'] === $id) {
                $d['liveness_state'] = $f['liveness_state'];
            }
        }
    }
    public function latestTicket(string $flow): ?array
    {
        $found = null;
        foreach ($this->tickets as $id => [$f, $tid]) {
            if ($f === $flow) {
                $found = ['id' => $id, 'tickets_id' => $tid];
            }
        }
        return $found;
    }
    public function reportedItems(int $hid): array
    {
        $out = [];
        foreach ($this->items[$hid] ?? [] as $k => [$s, $r]) {
            $out[$k] = ['state' => $s, 'resolved' => $r];
        }
        return $out;
    }
    public function recordTicket(string $flow, int $tickets_id, string $now): int
    {
        $id = count($this->tickets) + 1;
        $this->tickets[$id] = [$flow, $tickets_id];
        return $id;
    }
    public function recordItems(int $hid, array $plan, string $now): void
    {
        foreach ($plan['new'] as $k => $s) { $this->items[$hid][$k] = [$s, false]; }
        foreach ($plan['changed'] as $k => [, $s]) { $this->items[$hid][$k] = [$s, false]; }
        foreach ($plan['resolved'] as $k) { $this->items[$hid][$k][1] = true; }
    }
    public function counts(): array { return []; }
}

final class FakeGateway implements HygieneGateway
{
    public array $created = [];
    public array $followups = [];
    public array $status = [];
    public int $fail_create = 0;
    public function status(int $tickets_id): ?int { return $this->status[$tickets_id] ?? 2; }
    public function create(string $title, string $html, int $template_id, array $assign, array $observer, string $externalid = ''): int
    {
        if ($this->fail_create > 0) {
            $this->fail_create--;
            return 0;
        }
        $id = 100 + count($this->created);
        $this->created[$id] = compact('title', 'html', 'template_id', 'assign', 'observer', 'externalid');
        return $id;
    }
    public function followup(int $tickets_id, string $html): bool
    {
        $this->followups[] = [$tickets_id, $html];
        return true;
    }
}

$now = '2026-11-06 12:00:00';
$dev = static fn (int $id, string $key, string $avail, string $match, string $live = '') => [
    'id' => $id, 'device_key' => $key, 'name' => $key, 'display_name' => $key, 'ip_address' => '10.129.0.' . $id,
    'device_category' => 'Server', 'availability' => $avail, 'itemtype' => $match === 'matched' ? 'Computer' : '',
    'items_id' => $match === 'matched' ? $id : 0, 'match_state' => $match, 'last_seen' => '2026-11-06 11:30:00', 'liveness_state' => $live,
];
$cfg = [
    'enabled' => true, 'frequency_days' => 7, 'unmanaged_values' => ['UnManaged', 'Device Not Monitored.'],
    'agent_stale_days' => 7, 'mist_stale_days' => 7, 'probe_cidrs' => [], 'probe_ports' => [], 'probe_timeout_ms' => 500,
    'template_id' => 0, 'glpi_groups' => [11, 12], 'nms_group' => 20, 'service_user' => 5, 'entity_default' => 0,
    'list_url' => 'https://glpi/plugins/opmanager/front/device.php', 'device_data_fresh' => true,
];
$calls = 0;
$dead  = static function (array $d) use (&$calls): ?array { $calls++; return ['verdict' => 'dead', 'detail' => 'agent silent']; };
$none  = static fn (): ?array => null;
$by    = static fn (MemStore $s, int $id): string => $s->saved[$id]['hygiene_state'] ?? '?';

// --- liveness and classification ---
$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched'), $dev(2, 'k2', 'Clear', 'unmatched')];
$g = new FakeGateway();
(new HygieneRun($s, ['agent' => $dead], $g, $cfg))->run($now, false);
check('unmanaged + dead agent: nms_dead', $by($s, 1) === 'nms_dead' && $s->saved[1]['liveness_source'] === 'agent');
check('monitored device: never calls a provider', $calls === 1);
check('monitored device: liveness cleared', $s->saved[2]['liveness_state'] === '' && $by($s, 2) === 'glpi_missing');
check('tickets not due: no ticket', $g->created === []);

$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'unmatched')];
(new HygieneRun($s, ['agent' => $none, 'mist' => $none], new FakeGateway(), $cfg))->run($now, false);
check('no evidence: nms_unknown', $by($s, 1) === 'nms_unknown' && $s->saved[1]['liveness_state'] === 'unknown');

$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched')];
$alive = static fn (): ?array => ['verdict' => 'alive', 'detail' => 'seen by FortiCloud'];
(new HygieneRun($s, ['agent' => $dead, 'forticloud' => $alive], new FakeGateway(), $cfg))->run($now, false);
check('hook provider alive beats agent dead', $by($s, 1) === 'unmanaged_alive' && $s->saved[1]['liveness_source'] === 'forticloud');

$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched')];
$run = new HygieneRun($s, ['boom' => static function (): ?array { throw new RuntimeException('API down'); }], new FakeGateway(), $cfg);
$stats = $run->run($now, false);
check('throwing provider: run error, device still classified', in_array('provider boom: API down', $stats['errors'], true) && $by($s, 1) === 'nms_unknown');

$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched', 'alive')];
(new HygieneRun($s, ['probe' => $none], new FakeGateway(), $cfg))->run($now, false);
check('no evidence this run keeps a stored alive', $by($s, 1) === 'unmanaged_alive' && $s->saved[1]['liveness_state'] === 'alive');

// --- exceptions ---
$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched'), $dev(2, 'k2', 'Clear', 'unmatched')];
$s->exceptions = ['k1', 'k2'];
$g = new FakeGateway();
(new HygieneRun($s, ['agent' => $dead], $g, $cfg))->run($now, true);
check('excepted devices: excepted, no ticket', $by($s, 1) === 'excepted' && $by($s, 2) === 'excepted' && $g->created === []);

// --- tickets ---
$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched'), $dev(2, 'k2', 'Clear', 'unmatched'), $dev(3, 'k3', 'Clear', 'matched')];
$g = new FakeGateway();
$run   = new HygieneRun($s, ['agent' => $dead], $g, $cfg);
$stats = $run->run($now, true);
$glpi  = array_values(array_filter($g->created, static fn ($t) => str_contains($t['title'], 'not in GLPI')))[0] ?? null;
$nms   = array_values(array_filter($g->created, static fn ($t) => str_contains($t['title'], 'Unmanaged')))[0] ?? null;
check('two tickets created', count($g->created) === 2 && $stats['tickets_created'] === 2);
check('GLPI ticket: GLPI groups assigned, NMS observer', $glpi !== null && $glpi['assign'] === [11, 12] && $glpi['observer'] === [20]);
check('NMS ticket: NMS group assigned, no observer', $nms !== null && $nms['assign'] === [20] && $nms['observer'] === []);
check('GLPI ticket lists only the unmatched device', $glpi !== null && str_contains($glpi['html'], 'k2') && !str_contains($glpi['html'], 'k3'));
check('items recorded per ticket', count($s->items) === 2);

$stats = $run->run($now, true);
check('second run, same state, open tickets: no gateway call', count($g->created) === 2 && $g->followups === [] && $stats['errors'] === []);

$s->devices[] = $dev(4, 'k4', 'Clear', 'unmatched');
$run->run($now, true);
check('new device while ticket open: one followup', count($g->created) === 2 && count($g->followups) === 1 && str_contains($g->followups[0][1], 'k4'));

$g->status[100] = 6; $g->status[101] = 6;
$run->run($now, true);
check('closed tickets: new tickets', count($g->created) === 4);

$s = new MemStore();
$s->devices = [$dev(2, 'k2', 'Clear', 'unmatched')];
$g = new FakeGateway();
$g->fail_create = 1;
$stats = (new HygieneRun($s, [], $g, $cfg))->run($now, true);
check('create failure: error, no items recorded', $stats['errors'] !== [] && $s->tickets === [] && $s->items === []);

$s = new MemStore();
$s->devices = [$dev(2, 'k2', 'Clear', 'unmatched')];
$g = new FakeGateway();
$stats = (new HygieneRun($s, [], $g, ['enabled' => false] + $cfg))->run($now, true);
check('disabled: classifies, no ticket', $by($s, 2) === 'glpi_missing' && $g->created === []);

// --- final review fixes ---
// #2: a broken provider must not block the weekly ticket gate.
$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched'), $dev(2, 'k2', 'Clear', 'unmatched')];
$g = new FakeGateway();
$stats = (new HygieneRun($s, ['boom' => static function (): ?array { throw new RuntimeException('API down'); }], $g, $cfg))->run($now, true);
check('#2 provider error is a run error but not a ticket error', $stats['errors'] !== [] && $stats['ticket_errors'] === [] && count($g->created) === 2);
$g = new FakeGateway();
$g->fail_create = 1;
$s = new MemStore();
$s->devices = [$dev(2, 'k2', 'Clear', 'unmatched')];
$stats = (new HygieneRun($s, [], $g, $cfg))->run($now, true);
check('#2 a failed create is a ticket error', $stats['ticket_errors'] !== []);

// #3: stale OpManager data never turns into "no longer applies".
$s = new MemStore();
$s->devices = [$dev(2, 'k2', 'Clear', 'unmatched')];
$g = new FakeGateway();
(new HygieneRun($s, [], $g, $cfg))->run($now, true);
$s->devices[0]['last_seen'] = '2026-11-06 01:00:00'; // DeviceSync failing for hours
$stats = (new HygieneRun($s, [], $g, ['device_data_fresh' => false] + $cfg))->run($now, true);
check('#3 stale device data: no followup, ticket error raised', $g->followups === [] && $stats['ticket_errors'] !== [] && $s->items[1]['k2'][1] === false);

// #4a: a spent probe budget never downgrades a device the probe saw alive.
$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched', 'alive')];
$skip = static fn (): ?array => ['verdict' => 'skipped', 'detail' => 'probe budget spent'];
(new HygieneRun($s, ['agent' => $dead, 'probe' => $skip], new FakeGateway(), $cfg))->run($now, false);
check('#4a budget spent + agent dead keeps stored alive', $s->saved[1]['liveness_state'] === 'alive' && $by($s, 1) === 'unmanaged_alive');
// #4b: no evidence leaves liveness_checked_at untouched.
$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched')];
(new HygieneRun($s, ['probe' => $skip], new FakeGateway(), $cfg))->run($now, false);
check('#4b no evidence: checked_at not set', $s->saved[1]['liveness_checked_at'] === null && $s->saved[1]['liveness_state'] === 'unknown');

// #5: tickets carry a per-flow externalid so the gateway can adopt one after a crash.
$s = new MemStore();
$s->devices = [$dev(1, 'k1', 'UnManaged', 'matched'), $dev(2, 'k2', 'Clear', 'unmatched')];
$g = new FakeGateway();
(new HygieneRun($s, ['agent' => $dead], $g, $cfg))->run($now, true);
check('#5 per-flow externalid', array_column($g->created, 'externalid') === ['opmanager:hygiene:glpi', 'opmanager:hygiene:nms']);

done();
