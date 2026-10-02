<?php

require __DIR__ . '/bootstrap.php';
foreach (['OpManager/ClientException', 'OpManager/AlarmWriter', 'Outbound/OutboxStore', 'Outbound/Sender'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\AlarmWriter;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Outbound\OutboxStore;
use GlpiPlugin\Opmanager\Outbound\Sender;

final class Box implements OutboxStore
{
    public array $rows = []; private int $next = 1;
    public function add(string $key, string $action, string $payload = '', string $due = '2026-10-01 10:00:00', int $attempts = 0): void
    { $this->rows[$this->next] = ['id' => $this->next, 'alarm_key' => $key, 'entity_key' => "E{$key}", 'action' => $action, 'payload' => $payload, 'attempts' => $attempts, 'next_try_at' => $due, 'state' => 'pending', 'error' => '']; $this->next++; }
    public function enqueue(array $row, string $now): void {}
    public function hasClear(string $k): bool { return false; }
    public function hasPendingAck(string $k): bool { return false; }
    public function pending(int $limit): array { return array_values(array_filter($this->rows, fn ($r) => $r['state'] === 'pending')); }
    public function markSent(int $id, string $now): void { $this->rows[$id]['state'] = 'sent'; }
    public function markRetry(int $id, int $a, string $n, string $e, string $now): void { $this->rows[$id]['attempts'] = $a; $this->rows[$id]['next_try_at'] = $n; $this->rows[$id]['error'] = $e; }
    public function markFailed(int $id, int $a, string $e, string $now): void { $this->rows[$id]['state'] = 'failed'; $this->rows[$id]['attempts'] = $a; $this->rows[$id]['error'] = $e; }
    public function summary(): array { return ['pending' => 0, 'failed' => 0, 'last_error' => '']; }
}
final class Opm implements AlarmWriter
{
    public array $calls = []; public array $down = [];
    public function addNote(string $e, string $n): void { $this->calls[] = "note {$e} {$n}"; if (in_array($e, $this->down, true)) { throw new ClientException('OpManager down'); } }
    public function clearAlarm(string $e): void { $this->calls[] = "clear {$e}"; if (in_array($e, $this->down, true)) { throw new ClientException('OpManager down'); } }
    public function acknowledgeAlarm(string $e): void { $this->calls[] = "ack {$e}"; if (in_array($e, $this->down, true)) { throw new ClientException('OpManager down'); } }
}

$now = '2026-10-01 10:00:00';
check('backoff doubles and caps at 60', array_map([Sender::class, 'backoffMinutes'], [1, 2, 3, 4, 7, 9]) === [1, 2, 4, 8, 60, 60]);

$box = new Box(); $opm = new Opm();
$box->add('1', 'note', 'solved'); $box->add('1', 'clear');
$stats = (new Sender($box, $opm, 10))->run($now);
check('note then clear sent in order', $opm->calls === ['note E1 solved', 'clear E1'] && $stats['sent'] === 2);
check('sent rows leave the queue', $box->pending(50) === []);

$box = new Box(); $opm = new Opm(); $opm->down = ['E2'];
$box->add('2', 'note', 'x'); $box->add('2', 'clear'); $box->add('3', 'note', 'y');
$stats = (new Sender($box, $opm, 10))->run($now);
check('client error schedules a retry', $box->rows[1]['state'] === 'pending' && $box->rows[1]['attempts'] === 1 && $box->rows[1]['next_try_at'] === '2026-10-01 10:01:00' && $stats['retried'] === 1);
check('clear waits for its note', !in_array('clear E2', $opm->calls, true) && $box->rows[2]['attempts'] === 0);
check('other alarms not blocked', in_array('note E3 y', $opm->calls, true));
check('error kept on the row', $box->rows[1]['error'] === 'OpManager down');

$box = new Box(); $opm = new Opm();
$box->add('4', 'note', 'later', '2026-10-01 10:05:00'); $box->add('4', 'clear');
(new Sender($box, $opm, 10))->run($now);
check('not yet due: skipped, and its clear waits', $opm->calls === []);

$box = new Box(); $opm = new Opm(); $opm->down = ['E5'];
$box->add('5', 'clear', '', $now, 9);
$stats = (new Sender($box, $opm, 10))->run($now);
check('last allowed attempt fails for good', $box->rows[1]['state'] === 'failed' && $box->rows[1]['attempts'] === 10 && $stats['failed'] === 1);

$box = new Box(); $opm = new Opm();
$box->add('6', 'bogus');
(new Sender($box, $opm, 10))->run($now);
check('unknown action fails without calling OpManager', $box->rows[1]['state'] === 'failed' && $opm->calls === []);

$box = new Box(); $opm = new Opm(); $opm->down = ['E7', 'E8'];
$box->add('7', 'note', 'a'); $box->add('8', 'note', 'b');
(new Sender($box, $opm, 10))->run($now, 20, true);
check('stop at first failure: one OpManager call only', $opm->calls === ['note E7 a'] && $box->rows[2]['attempts'] === 0);

$box = new Box(); $opm = new Opm();
$box->add('9', 'note', 'on it'); $box->add('9', 'ack');
(new Sender($box, $opm, 10))->run($now);
check('note then acknowledge sent', $opm->calls === ['note E9 on it', 'ack E9'] && $box->pending(50) === []);

done();
