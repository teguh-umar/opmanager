<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Hygiene/Liveness.php';

use GlpiPlugin\Opmanager\Hygiene\Liveness as L;

$now = '2026-11-06 12:00:00';

// --- GLPI agent ---
$e = L::fromAgent('2026-11-01 00:00:00', $now, 7);
check('agent recent: alive', ($e['verdict'] ?? null) === 'alive');
check('agent detail names the last contact', str_contains((string) ($e['detail'] ?? ''), '2026-11-01'));
check('agent old: dead', (L::fromAgent('2026-10-01 00:00:00', $now, 7)['verdict'] ?? null) === 'dead');
check('no agent: no evidence', L::fromAgent(null, $now, 7) === null);
check('empty last contact: no evidence', L::fromAgent('', $now, 7) === null);

// --- Mist ---
check('mist connected: alive', (L::fromMist(['status' => 'connected', 'mist_last_seen' => '2020-01-01 00:00:00'], $now, 7)['verdict'] ?? null) === 'alive');
check('mist disconnected but seen recently: alive', (L::fromMist(['status' => 'disconnected', 'mist_last_seen' => '2026-11-05 00:00:00'], $now, 7)['verdict'] ?? null) === 'alive');
check('mist disconnected, seen long ago: dead', (L::fromMist(['status' => 'disconnected', 'mist_last_seen' => '2026-10-01 00:00:00'], $now, 7)['verdict'] ?? null) === 'dead');
check('mist disconnected, never seen: dead', (L::fromMist(['status' => 'disconnected', 'mist_last_seen' => null], $now, 7)['verdict'] ?? null) === 'dead');
check('no mist row: no evidence', L::fromMist(null, $now, 7) === null);

// --- TCP probe ---
$e = L::fromProbe(['open' => 443, 'tried' => [22, 443]]);
check('probe open: alive', ($e['verdict'] ?? null) === 'alive');
check('probe open detail', ($e['detail'] ?? null) === 'TCP 443 open');
$e = L::fromProbe(['open' => null, 'tried' => [22, 443]]);
check('probe closed: dead', ($e['verdict'] ?? null) === 'dead');
check('probe closed detail', ($e['detail'] ?? null) === 'TCP 22,443 no answer');
check('not probed: no evidence', L::fromProbe(null) === null);

// --- combine ---
check('no evidence: unknown', L::combine([]) === ['state' => 'unknown', 'source' => '', 'detail' => 'no evidence source applies']);
$r = L::combine([
    ['verdict' => 'dead', 'source' => 'agent', 'detail' => 'agent last contact 2026-10-01'],
    ['verdict' => 'alive', 'source' => 'probe', 'detail' => 'TCP 443 open'],
]);
check('any alive wins', $r['state'] === 'alive' && $r['source'] === 'probe' && $r['detail'] === 'TCP 443 open');
$r = L::combine([
    ['verdict' => 'dead', 'source' => 'agent', 'detail' => 'a'],
    ['verdict' => 'dead', 'source' => 'mist', 'detail' => 'b'],
]);
check('all dead: dead from the first source', $r['state'] === 'dead' && $r['source'] === 'agent');
check('all dead: details joined', $r['detail'] === 'a; b');
check('detail cut to 255 chars', strlen(L::combine([['verdict' => 'dead', 'source' => 'x', 'detail' => str_repeat('z', 300)]])['detail']) === 255);

done();
