<?php

require __DIR__ . '/bootstrap.php';
foreach (['Cidr', 'Liveness', 'TcpProber', 'AgentProvider', 'MistProvider', 'ProbeProvider', 'Providers'] as $c) {
    require OPM_ROOT . "/src/Hygiene/{$c}.php";
}

use GlpiPlugin\Opmanager\Hygiene\AgentProvider;
use GlpiPlugin\Opmanager\Hygiene\ProbeProvider;
use GlpiPlugin\Opmanager\Hygiene\Providers;
use GlpiPlugin\Opmanager\Hygiene\TcpProber;

$alive = static fn (array $d, string $now): ?array => ['verdict' => 'alive', 'detail' => 'seen by FortiCloud'];
$cfg   = ['agent_stale_days' => 7, 'mist_stale_days' => 7, 'probe_cidrs' => [], 'probe_ports' => [22], 'probe_timeout_ms' => 200];

// --- normalise hook entries ---
check('single callable keyed by plugin name', array_keys(Providers::normalise(['forti' => $alive])) === ['forti']);
check('named callables; non-callables dropped', array_keys(Providers::normalise(['x' => ['fortimanager' => $alive, 'bad' => 'nope']])) === ['fortimanager']);
check('name cut to 32 chars', array_keys(Providers::normalise([str_repeat('n', 40) => $alive])) === [str_repeat('n', 32)]);
check('garbage entry dropped', Providers::normalise(['x' => 42]) === []);

// --- collect ---
$device = ['device_key' => 'k', 'ip_address' => '10.0.0.1', 'match_state' => 'unmatched', 'itemtype' => '', 'items_id' => 0];
$errors = [];
$ev = Providers::collect($device, '2026-11-06 12:00:00', [
    'forti' => $alive,
    'boom'  => static function (): ?array { throw new RuntimeException('API down'); },
    'weird' => static fn (): ?array => ['verdict' => 'maybe', 'detail' => ''],
    'none'  => static fn (): ?array => null,
    'dead'  => static fn (): ?array => ['verdict' => 'dead', 'detail' => 'gone'],
], $errors);
check('collect adds the provider name as source', ($ev[0]['source'] ?? null) === 'forti' && $ev[0]['verdict'] === 'alive');
check('a throwing provider is reported by name', $errors === ['provider boom: API down']);
check('providers after a throwing one still run', count($ev) === 2 && $ev[1]['source'] === 'dead');
check('invalid verdict dropped, null adds nothing', array_column($ev, 'source') === ['forti', 'dead']);

// --- registry ---
$built = Providers::builtIn($cfg);
check('built-in order agent, mist, probe', array_keys($built) === ['agent', 'mist', 'probe']);
$GLOBALS['PLUGIN_HOOKS'][Providers::HOOK] = ['forticloud' => ['forticloud' => $alive, 'agent' => $alive]];
$all = Providers::all($cfg);
check('hook provider added after built-ins', array_keys($all) === ['agent', 'mist', 'probe', 'forticloud']);
check('hook cannot override a built-in name', $all['agent'] instanceof AgentProvider);
unset($GLOBALS['PLUGIN_HOOKS']);
check('no hooks: built-ins only', array_keys(Providers::all($cfg)) === ['agent', 'mist', 'probe']);

// --- probe provider ---
$probe = new ProbeProvider(new TcpProber(200), ['10.20.0.0/16'], [22]);
check('ip outside the CIDRs: no evidence, no probe', $probe(['ip_address' => '10.30.8.6'] + $device, '2026-11-06 12:00:00') === null);
$ticks  = [0.0, 5.0];
$prober = new TcpProber(200, 1.0, static function () use (&$ticks): float { return array_shift($ticks) ?? 5.0; });
$prober->probe('127.0.0.1', []); // starts the run's budget at t=0
$spent = new ProbeProvider($prober, ['0.0.0.0/0'], [22]);
check('budget spent: skipped, not dead (Review Focus 1)', ($spent(['ip_address' => '127.0.0.1'] + $device, '2026-11-06 12:00:00')['verdict'] ?? null) === 'skipped');
$errors = [];
check('collect passes skipped through', array_column(Providers::collect($device, '2026-11-06 12:00:00', ['p' => static fn (): ?array => ['verdict' => 'skipped', 'detail' => 'x']], $errors), 'verdict') === ['skipped']);

// --- agent provider skips devices without a GLPI asset (no DB call) ---
$agent = new AgentProvider(7);
check('agent: unmatched device gives no evidence', $agent($device, '2026-11-06 12:00:00') === null);

done();
