<?php

require __DIR__ . '/bootstrap.php';
foreach (['OpManager/ClientException', 'OpManager/AlarmSource', 'Ticketing/SyncLock', 'Webhook/Receiver'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\SyncLock;
use GlpiPlugin\Opmanager\Webhook\Receiver;

final class Src implements AlarmSource
{
    public array $calls = [];
    public array $locked = [];
    public ?Lock $lock = null;
    public function __construct(public mixed $answer) {}
    public function listActiveAlarms(): array { return []; }
    public function alarmByEntity(string $e): ?array { $this->calls[] = $e; $this->locked[] = $this->lock?->holding; if ($this->answer instanceof ClientException) { throw $this->answer; } return $this->answer; }
}
final class Lock implements SyncLock
{
    public bool $free = true; public int $held = 0; public int $released = 0; public bool $holding = false;
    public function acquire(int $t): bool { if ($this->free) { $this->held++; $this->holding = true; } return $this->free; }
    public function release(): void { $this->released++; $this->holding = false; }
}

$alarm  = ['alarm_key' => '10000003244', 'entity_key' => '10000024830', 'severity' => 1];
$runs   = [];
$runner = function (array $a) use (&$runs) { $runs[] = $a['alarm_key']; return ['created' => 1]; };
$mk     = static fn ($answer, string $token = 'secret-token') => [new Receiver($token, $s = new Src($answer), $l = new Lock(), $runner), $s, $l, $s->lock = $l];
$body   = json_encode(['alarm_id' => '10000003244', 'entity' => '10000024830']);

[$r, $s, $l] = $mk($alarm);
check('GET refused', $r->handle('GET', 'secret-token', $body)['status'] === 405);
check('wrong token refused', $r->handle('POST', 'nope', $body)['status'] === 401 && $runs === []);
check('empty token refused', $r->handle('POST', '', $body)['status'] === 401);
check('oversized body refused', $r->handle('POST', 'secret-token', str_repeat('x', Receiver::MAX_BODY + 1))['status'] === 413);
check('invalid JSON refused', $r->handle('POST', 'secret-token', '{nope')['status'] === 400);
check('missing entity refused', $r->handle('POST', 'secret-token', '{"alarm_id":"1"}')['status'] === 400);
check('control characters refused', $r->handle('POST', 'secret-token', json_encode(['alarm_id' => '1', 'entity' => "a\nb"]))['status'] === 400);
check('nothing fetched for refused calls', $s->calls === []);

$res = $r->handle('POST', 'secret-token', $body);
check('valid call processed', $res['status'] === 200 && $runs === ['10000003244'] && $s->calls === ['10000024830']);
check('lock taken and released', $l->held === 1 && $l->released === 1);
check('alarm fetched while holding the lock (no stale snapshot)', $s->locked === [true]);

[$r] = $mk($alarm, '');
check('webhook disabled when no token configured', $r->handle('POST', '', $body)['status'] === 401);

$runs = [];
[$r] = $mk(['alarm_key' => '99999', 'entity_key' => '10000024830', 'severity' => 1]);
check('different alarm on entity is deferred', $r->handle('POST', 'secret-token', $body)['status'] === 202 && $runs === []);
[$r] = $mk(null);
check('alarm not found is deferred', $r->handle('POST', 'secret-token', $body)['status'] === 202 && $runs === []);
[$r] = $mk(new ClientException('OpManager error 5000: key'));
$res = $r->handle('POST', 'secret-token', $body);
check('api failure deferred', $res['status'] === 202 && $runs === [] && !str_contains(json_encode($res), 'secret-token'));
[$r, $s, $l] = $mk($alarm);
$l->free = false;
check('lock busy is deferred, not processed', $r->handle('POST', 'secret-token', $body)['status'] === 202 && $runs === [] && $l->released === 0 && $s->calls === []);
[$r, , $l] = $mk(new ClientException('down'));
$r->handle('POST', 'secret-token', $body);
check('lock released after an api failure', $l->held === 1 && $l->released === 1);
// I4: deferred answers carry the alarm id for the log
[$r] = $mk(null);
check('deferred answer names the alarm', ($r->handle('POST', 'secret-token', $body)['body']['alarm_id'] ?? null) === '10000003244');
[$r, , $l] = $mk($alarm);
$boom = new Receiver('secret-token', new Src($alarm), $l, function () { throw new RuntimeException('engine down'); });
check('engine exception still releases the lock', $boom->handle('POST', 'secret-token', $body)['status'] === 500 && $l->released === 1);

// 0.4.1: real OpManager entities can hold '/', spaces and exceed 64 chars (interfaces).
$runs   = [];
$long   = 'IF-192.168.172.245.10000000001-Gi0/1 uplink to core ' . str_repeat('x', 80) . '_Poll';
[$r, $s] = $mk(['alarm_key' => '10000006678', 'entity_key' => $long, 'severity' => 1]);
$res = $r->handle('POST', 'secret-token', json_encode(['alarm_id' => '10000006678', 'entity' => $long]));
check('long entity with slash and spaces is processed', $res['status'] === 200 && $runs === ['10000006678'] && $s->calls === [$long]);
[$r] = $mk($alarm);
check('entity over 255 refused', $r->handle('POST', 'secret-token', json_encode(['alarm_id' => '1', 'entity' => str_repeat('e', 256)]))['status'] === 400);

// OpManager "Test Action" sends the placeholders unfilled: answer 200 so the test passes.
$runs = [];
[$r, $s] = $mk($alarm);
$res = $r->handle('POST', 'secret-token', '{"alarm_id":"$alarmid","entity":"$entity"}');
check('unfilled placeholders = test ping, 200, nothing processed', $res['status'] === 200 && ($res['body']['status'] ?? '') === 'test' && $runs === [] && $s->calls === []);

// A refused body is echoed (truncated, control chars removed) so the log shows what arrived.
$res = $r->handle('POST', 'secret-token', "{\"alarm_id\":\"1\",\n\"x\":1}");
check('400 answer carries what was received, one line', $res['status'] === 400 && str_contains($res['body']['received'] ?? '', 'alarm_id') && !str_contains($res['body']['received'] ?? "\n", "\n"));

done();
