# opmanager Plan 3 — GLPI → OpManager (notes + clear) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a GLPI user (not the plugin) adds a public followup, solves, or closes an alarm ticket, the plugin sends a note — and on Solve/Close a clear — to every still-active OpManager alarm of that ticket, through a retrying outbox.

**Architecture:**
- A Ticket `item_update` hook and an ITILFollowup `item_add` hook decide, in the user's request, whether the action was typed by a human (D24). If so, they queue `note`/`clear` rows in a new outbox table and try to send them straight away with a short timeout.
- A one-minute `OutboxSend` cron retries what failed, with backoff 1, 2, 4 … 60 minutes, up to `outbound_max_attempts` (D13, default 10). After that a row is `failed` and shown in Setup.
- All decisions live in pure classes under `src/Outbound/` with offline tests. The GLPI glue is thin.

**Tech Stack:** GLPI 11.0.5 plugin (PHP 8.2+), ext-curl (no Guzzle, see 0.2.2), offline tests `C:/php/php.exe tests/run_all.php`.

**Spec:** `docs/superpowers/specs/2026-09-29-opmanager-design.md` — §8, D8, D13, D16, D24, D25, D26, §14 (addNotes verified: POST, key in `apiKey` header, form body `entity` + `notes`).

## Global Constraints

- The API key travels only in the `apiKey` header. It is never logged, printed, or put in a URL (spec §14).
- No Guzzle anywhere: telegrambot ships Guzzle 6 on production. HTTP goes through `Client` (ext-curl) only.
- Outbound covers only actions typed in a logged-in GLPI session (D24). The rule: `Session::getLoginUserID()` > 0, not `Session::isCron()`, author ≠ service user, and the plugin gateway is not currently writing.
- Only **public** followups are sent (D25).
- Close without a typed solution → a short note `Tiket #N ditutup oleh <user> di GLPI`, then clear (D26).
- Per alarm, a note is sent before its clear. A clear is never sent twice for the same alarm (pending or sent).
- A hook must never break the technician's save. Every outbound error is caught and logged to `opmanager.log`.
- Release rule (hub §0 rule 6): 0.4.0 is commit + local tag only, with no push.

## Review Focus

1. **The plugin's own writes inside a human session.** "Execute" on the AlarmSync cron from the GLPI UI runs the engine in a technician's session. Its auto-solve or followups must NOT be sent. Pinned in Task 4 (`plugin writing blocks outbound`) and Task 5 (gateway wraps every write).
2. **Solve with immediate auto-close** (entity autoclose delay 0 → status goes straight to Closed): one note carrying the solution text, then one clear. No extra D26 note. Pinned in Task 4 (`solution text wins over close note`) and Task 2 (`clear not queued twice`).
3. **OpManager down while the technician saves:** the save succeeds, rows stay `pending`, and the cron retries. Pinned in Task 3 (`client error schedules a retry`) and Task 5 (hook swallows exceptions).
4. **A note fails while its clear is due:** the clear must wait. It is never sent before its note. Pinned in Task 3 (`clear waits for its note`).
5. **A ticket with alarms already cleared** (OpManager cleared first, then the plugin auto-solved): nothing is queued, because there is no active alarm. Pinned in Task 4 (`no active alarm, nothing queued`).

---

### Task 1: Client write API (addNote, clearAlarm)

**Files:**
- Create: `src/OpManager/AlarmWriter.php`
- Modify: `src/OpManager/Client.php`, `tests/client_test.php`, `tests/curltransport_test.php`, `tests/fixtures/opm_stub.php`

**Interfaces:**
- Produces: `interface AlarmWriter { addNote(string $entity, string $notes): void; clearAlarm(string $entity): void; }` (both throw `ClientException`); `Client implements AlarmWriter`; `Client::__construct(string $baseUrl, string $apiKey, bool $verifySsl = true, ?callable $transport = null, int $timeout = 30)`; transport signature `(string $url, array $query, array $headers, ?array $form = null): array{status:int, body:string}` — `$form !== null` means POST.

- [ ] **Step 1: Write the failing tests**

Append to `tests/client_test.php` before `done();` (and add `'AlarmWriter'` to the require list before `'Client'`):

```php
// Plan 3: writes are POST with a form body; the key stays in the header.
$posted = [];
$writer = new Client('https://opm', 'S3CRET', true, function (string $url, array $query, array $headers, ?array $form = null) use (&$posted) {
    $posted[] = [$url, $query, $headers, $form];
    return ['status' => 200, 'body' => '{"result":{"message":"Successfully Added"}}'];
});
$writer->addNote('IF-1.2_Poll', 'hello');
check('addNote posts entity and notes', $posted[0][0] === 'https://opm/api/json/alarm/addNotes' && $posted[0][3] === ['entity' => 'IF-1.2_Poll', 'notes' => 'hello']);
check('addNote key only in header', ($posted[0][2]['apiKey'] ?? null) === 'S3CRET' && $posted[0][1] === [] && !isset($posted[0][3]['apiKey']));
$writer->clearAlarm('IF-1.2_Poll');
check('clearAlarm posts entity', $posted[1][0] === 'https://opm/api/json/alarm/clearAlarm' && $posted[1][3] === ['entity' => 'IF-1.2_Poll']);
// Real clearAlarm success shape (production probe 2026-10-01): no "result", no "error".
$cleared = new Client('https://opm', 'S3CRET', true, fn () => ['status' => 200, 'body' => '{"ackMessage":"Alarm has been successfully Cleared!","AlarmProperty":{"Details":{"displayName":"VM-SERVER-01"}}}']);
$ok = true;
try { $cleared->clearAlarm('E'); } catch (ClientException $e) { $ok = false; }
check('clearAlarm ackMessage answer is success', $ok);
$denied = new Client('https://opm', 'S3CRET', true, fn () => ['status' => 200, 'body' => '{"error":{"message":"You are not authorized to perform this operation","errorcode":"AUTHORIZATION_FAILED"}}']);
try {
    $denied->addNote('E', 'x');
    check('write error object throws', false);
} catch (ClientException $e) {
    check('write error object throws', str_contains($e->getMessage(), 'AUTHORIZATION_FAILED'));
}
check('client is an AlarmWriter', $writer instanceof \GlpiPlugin\Opmanager\OpManager\AlarmWriter);
```

In `tests/fixtures/opm_stub.php`, add `'form' => $_POST,` to the recorded array.

In `tests/curltransport_test.php`, add `'AlarmWriter'` to the require list before `'Client'`, and before the `try { (new Client(... '/fail' ...` block add:

```php
    $client->addNote('IF-1.2_Poll', 'note from test');
    $seen = json_decode((string) file_get_contents($log), true);
    check('curl POSTs the form body', ($seen['method'] ?? '') === 'POST' && ($seen['form'] ?? null) === ['entity' => 'IF-1.2_Poll', 'notes' => 'note from test']);
    check('curl POST keeps the key in the header only', ($seen['apikey'] ?? null) === 'S3CRET' && !isset($seen['form']['apiKey']) && !isset($seen['query']['apiKey']));
```

(The stub answers a JSON device list for any non-`/fail` path. That is not an error object, so `addNote` accepts it.)

- [ ] **Step 2: Run to verify they fail**

Run: `C:/php/php.exe tests/client_test.php; C:/php/php.exe tests/curltransport_test.php`
Expected: fatal error on the missing `AlarmWriter.php`.

- [ ] **Step 3: Implement**

`src/OpManager/AlarmWriter.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/** Writes back to OpManager (Plan 3). Both throw ClientException on any failure. */
interface AlarmWriter
{
    public function addNote(string $entity, string $notes): void;

    public function clearAlarm(string $entity): void;
}
```

In `src/OpManager/Client.php`:
- add `AlarmWriter` to `implements`;
- add the constants `ADD_NOTES_PATH = '/api/json/alarm/addNotes'` and `CLEAR_ALARM_PATH = '/api/json/alarm/clearAlarm'`;
- add the constructor parameter `private int $timeout = 30` after `$transport`;
- change the transport docblock to the 4-argument signature;
- add:

```php
    public function addNote(string $entity, string $notes): void
    {
        $this->post(self::ADD_NOTES_PATH, ['entity' => $entity, 'notes' => $notes]);
    }

    public function clearAlarm(string $entity): void
    {
        $this->post(self::CLEAR_ALARM_PATH, ['entity' => $entity]);
    }

    /**
     * OpManager answers HTTP 200 with {"error":{...}} on refusal (spec §14), so the body is
     * checked as well as the status.
     *
     * @param array<string, string> $form
     */
    private function post(string $path, array $form): void
    {
        $body = $this->request($path, [], $form);
        $data = json_decode($body, true);
        if (is_array($data) && array_key_exists('error', $data)) {
            throw new ClientException(sprintf('OpManager %s refused: %s', $path, ResponseGuard::errorMessage($data['error'])));
        }
    }
```

Rename the private `get()` to `request(string $path, array $query = [], ?array $form = null)`, call the transport with `$form` as its 4th argument, and keep a one-line `get($path, $query)` that returns `$this->request($path, $query)` so the read methods are unchanged.

In `curlTransport`:
- add the parameter `?array $form = null`;
- replace `CURLOPT_HTTPGET => true` with nothing;
- after `curl_setopt_array`, add:

```php
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
```

- use `CURLOPT_TIMEOUT => $this->timeout` and `CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout)`.

- [ ] **Step 4: Run tests**

Run: `C:/php/php.exe tests/client_test.php && C:/php/php.exe tests/curltransport_test.php && C:/php/php.exe tests/run_all.php`
Expected: both `ALL PASS`, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: OpManager write API (addNotes, clearAlarm) over curl POST`

---

### Task 2: Outbox table, store, config

**Files:**
- Create: `src/Outbound/OutboxStore.php`, `src/Outbound/DbOutboxStore.php`, `tests/outbox_schema_test.php`
- Modify: `install/install.php`, `install/uninstall.php`, `src/Config.php`, `tests/config_test.php`

**Interfaces:**
- Produces:

```php
interface OutboxStore {
    /** @param array{alarm_key:string, entity_key:string, tickets_id:int, users_id:int, action:string, payload:string} $row */
    public function enqueue(array $row, string $now): void;
    public function hasClear(string $alarm_key): bool;        // a pending or sent clear exists
    /** @return list<array{id:int, alarm_key:string, entity_key:string, action:string, payload:string, attempts:int, next_try_at:string}> oldest first */
    public function pending(int $limit): array;
    public function markSent(int $id, string $now): void;
    public function markRetry(int $id, int $attempts, string $next_try_at, string $error, string $now): void;
    public function markFailed(int $id, int $attempts, string $error, string $now): void;
    /** @return array{pending:int, failed:int, last_error:string} */
    public function summary(): array;
}
```

- `Config::DEFAULTS['outbound_max_attempts'] = '10'`; `Config::sanitizeTicketing` accepts `outbound_max_attempts` in the range 1–50; `ticketingConfig()['outbound_max_attempts']` is an int.

- [ ] **Step 1: Write the failing tests**

`tests/outbox_schema_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';

$install   = (string) file_get_contents(OPM_ROOT . '/install/install.php');
$uninstall = (string) file_get_contents(OPM_ROOT . '/install/uninstall.php');
$store     = (string) @file_get_contents(OPM_ROOT . '/src/Outbound/DbOutboxStore.php');

check('outbox table created', (bool) preg_match('/glpi_plugin_opmanager_outbox.*?`action` VARCHAR\(8\).*?`state` VARCHAR\(8\).*?`next_try_at` TIMESTAMP.*?KEY `due` \(`state`, `next_try_at`\)/s', $install));
check('outbox send cron registered every minute', (bool) preg_match("/CronTask::register\(Cron::class, 'OutboxSend', MINUTE_TIMESTAMP/", $install));
check('outbox dropped on uninstall', str_contains($uninstall, 'glpi_plugin_opmanager_outbox'));
check('store: pending oldest first', (bool) preg_match("/function pending.*?'state' => 'pending'.*?'ORDER' => 'id'/s", $store));
check('store: clear dedupe covers pending and sent', (bool) preg_match("/function hasClear.*?'action' => 'clear'.*?'state' => \['pending', 'sent'\]/s", $store));

done();
```

Append to `tests/config_test.php` before `done();`:

```php
// Plan 3: outbound retries (D13)
check('outbound retries default 10', Config::DEFAULTS['outbound_max_attempts'] === '10');
$r = Config::sanitizeTicketing(['outbound_max_attempts' => '0'] + $t);
check('outbound retries 0 rejected', !array_key_exists('outbound_max_attempts', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitizeTicketing(['outbound_max_attempts' => '7'] + $t);
check('outbound retries stored', $r['values']['outbound_max_attempts'] === '7');
```

Add `'outbound_max_attempts' => '10'` to the `$t` array at the top of the ticketing section, so the existing checks stay valid.

- [ ] **Step 2: Run to verify they fail**

Run: `C:/php/php.exe tests/outbox_schema_test.php; C:/php/php.exe tests/config_test.php`
Expected: `FAIL` on the outbox checks and on the three retry checks.

- [ ] **Step 3: Implement**

In `install/install.php`, after the storms table:

```php
    $table = 'glpi_plugin_opmanager_outbox';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `alarm_key` VARCHAR(255) NOT NULL DEFAULT '',
            `entity_key` VARCHAR(255) NOT NULL DEFAULT '',
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `action` VARCHAR(8) NOT NULL DEFAULT '',
            `payload` TEXT NULL,
            `state` VARCHAR(8) NOT NULL DEFAULT 'pending',
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `next_try_at` TIMESTAMP NULL DEFAULT NULL,
            `last_error` TEXT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `due` (`state`, `next_try_at`),
            KEY `alarm_key` (`alarm_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }
```

and after the AlarmSync registration:

```php
    CronTask::register(Cron::class, 'OutboxSend', MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'Send queued notes/clears to OpManager (retries)',
    ]);
```

In `install/uninstall.php`, add `'glpi_plugin_opmanager_outbox'` to the drop list.

`src/Outbound/OutboxStore.php`: the interface exactly as in Interfaces above (namespace `GlpiPlugin\Opmanager\Outbound`).

`src/Outbound/DbOutboxStore.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Outbound;

final class DbOutboxStore implements OutboxStore
{
    public const TABLE = 'glpi_plugin_opmanager_outbox';

    public function enqueue(array $row, string $now): void
    {
        global $DB;

        $DB->insert(self::TABLE, $row + [
            'state' => 'pending', 'attempts' => 0, 'next_try_at' => $now,
            'date_creation' => $now, 'date_mod' => $now,
        ]);
    }

    public function hasClear(string $alarm_key): bool
    {
        global $DB;

        return count($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['alarm_key' => $alarm_key, 'action' => 'clear', 'state' => ['pending', 'sent']],
            'LIMIT' => 1,
        ])) > 0;
    }

    public function pending(int $limit): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'alarm_key', 'entity_key', 'action', 'payload', 'attempts', 'next_try_at'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['state' => 'pending'],
            'ORDER'  => 'id',
            'LIMIT'  => $limit,
        ]) as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'alarm_key' => (string) $r['alarm_key'], 'entity_key' => (string) $r['entity_key'],
                'action' => (string) $r['action'], 'payload' => (string) $r['payload'], 'attempts' => (int) $r['attempts'],
                'next_try_at' => (string) ($r['next_try_at'] ?? ''),
            ];
        }
        return $out;
    }

    public function markSent(int $id, string $now): void
    {
        global $DB;

        $DB->update(self::TABLE, ['state' => 'sent', 'last_error' => '', 'date_mod' => $now], ['id' => $id]);
    }

    public function markRetry(int $id, int $attempts, string $next_try_at, string $error, string $now): void
    {
        global $DB;

        $DB->update(self::TABLE, ['attempts' => $attempts, 'next_try_at' => $next_try_at, 'last_error' => $error, 'date_mod' => $now], ['id' => $id]);
    }

    public function markFailed(int $id, int $attempts, string $error, string $now): void
    {
        global $DB;

        $DB->update(self::TABLE, ['state' => 'failed', 'attempts' => $attempts, 'last_error' => $error, 'date_mod' => $now], ['id' => $id]);
    }

    public function summary(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return ['pending' => 0, 'failed' => 0, 'last_error' => ''];
        }
        $count = static fn (string $state) => count($DB->request(['FROM' => self::TABLE, 'WHERE' => ['state' => $state]]));
        $last  = $DB->request([
            'SELECT' => ['last_error'], 'FROM' => self::TABLE,
            'WHERE'  => ['state' => ['pending', 'failed'], 'NOT' => ['last_error' => '']],
            'ORDER'  => 'date_mod DESC', 'LIMIT' => 1,
        ])->current();
        return ['pending' => $count('pending'), 'failed' => $count('failed'), 'last_error' => (string) ($last['last_error'] ?? '')];
    }
}
```

In `src/Config.php`:
- add `'outbound_max_attempts' => '10',` to `DEFAULTS` after `storm_window_minutes`;
- in `sanitizeTicketing`, add `'outbound_max_attempts' => [1, 50]` to the numeric range loop;
- in `ticketingConfig()`, add `'outbound_max_attempts' => max(1, (int) self::get('outbound_max_attempts')),`.

- [ ] **Step 4: Run tests**

Run: `C:/php/php.exe tests/outbox_schema_test.php && C:/php/php.exe tests/config_test.php && for f in install/install.php install/uninstall.php src/Outbound/DbOutboxStore.php; do C:/php/php.exe -l $f; done && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS` twice, three `No syntax errors detected`, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: outbox table, store and retry setting`

---

### Task 3: Sender (retry, backoff, note-before-clear)

**Files:**
- Create: `src/Outbound/Sender.php`, `tests/sender_test.php`

**Interfaces:**
- Consumes: `OutboxStore` (T2), `AlarmWriter` (T1), `ClientException`.
- Produces: `Sender::__construct(OutboxStore $store, AlarmWriter $writer, int $max_attempts)`; `run(string $now, int $limit = 50): array{sent:int, retried:int, failed:int}`; `static backoffMinutes(int $attempts): int` (1, 2, 4, 8, 16, 32, 60, 60 …); `lastErrors(): list<string>`.

- [ ] **Step 1: Write the failing test**

`tests/sender_test.php`:

```php
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

done();
```

- [ ] **Step 2: Run to verify it fails**

Run: `C:/php/php.exe tests/sender_test.php`
Expected: fatal error on the missing `Sender.php`.

- [ ] **Step 3: Implement** `src/Outbound/Sender.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Outbound;

use GlpiPlugin\Opmanager\OpManager\AlarmWriter;
use GlpiPlugin\Opmanager\OpManager\ClientException;

/**
 * Sends queued notes/clears oldest first. Per alarm the order is kept: once a row of an
 * alarm is not sent (not due yet, or failed now), later rows of that alarm wait, so a
 * clear never overtakes its note. Retries back off 1, 2, 4 ... 60 minutes (spec §8).
 */
final class Sender
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(private OutboxStore $store, private AlarmWriter $writer, private int $max_attempts)
    {
    }

    public static function backoffMinutes(int $attempts): int
    {
        return min(60, 2 ** max(0, $attempts - 1));
    }

    /** @return array{sent: int, retried: int, failed: int} */
    public function run(string $now, int $limit = 50): array
    {
        $this->errors = [];
        $stats   = ['sent' => 0, 'retried' => 0, 'failed' => 0];
        $blocked = [];
        foreach ($this->store->pending($limit) as $row) {
            $key = $row['alarm_key'];
            if (isset($blocked[$key])) {
                continue;
            }
            if ($row['next_try_at'] !== '' && $row['next_try_at'] > $now) {
                $blocked[$key] = true;
                continue;
            }
            $attempts = $row['attempts'] + 1;
            try {
                match ($row['action']) {
                    'note'  => $this->writer->addNote($row['entity_key'], $row['payload']),
                    'clear' => $this->writer->clearAlarm($row['entity_key']),
                    default => throw new \DomainException('unknown outbox action ' . $row['action']),
                };
                $this->store->markSent($row['id'], $now);
                $stats['sent']++;
            } catch (ClientException $e) {
                $blocked[$key] = true;
                $this->errors[] = sprintf('%s %s: %s', $row['action'], $key, $e->getMessage());
                if ($attempts >= $this->max_attempts) {
                    $this->store->markFailed($row['id'], $attempts, $e->getMessage(), $now);
                    $stats['failed']++;
                } else {
                    $next = date('Y-m-d H:i:s', strtotime($now) + self::backoffMinutes($attempts) * 60);
                    $this->store->markRetry($row['id'], $attempts, $next, $e->getMessage(), $now);
                    $stats['retried']++;
                }
            } catch (\DomainException $e) {
                $blocked[$key] = true;
                $this->store->markFailed($row['id'], $attempts, $e->getMessage(), $now);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /** @return list<string> */
    public function lastErrors(): array
    {
        return $this->errors;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `C:/php/php.exe tests/sender_test.php && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (10 passed, 0 failed)`, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: outbox sender with backoff and note-before-clear`

---

### Task 4: Planner (who is human, what to queue, message text)

**Files:**
- Create: `src/Outbound/Planner.php`, `tests/planner_test.php`

**Interfaces:**
- Produces (all static, pure):
  - `Planner::isHuman(mixed $login_user_id, bool $is_cron, int $service_user, bool $plugin_writing): bool`
  - `Planner::forFollowup(int $tickets_id, bool $is_private, string $text, string $user, list<array{alarm_key:string, entity_key:string}> $active, int $users_id): list<array>` — the rows for `OutboxStore::enqueue`
  - `Planner::forStatus(int $tickets_id, int $old_status, int $new_status, ?string $solution, string $user, list<array{alarm_key:string, entity_key:string}> $active, callable $has_clear, int $users_id): list<array>`
  - `Planner::NOTE_MAX = 2000`

- [ ] **Step 1: Write the failing test**

`tests/planner_test.php`:

```php
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

done();
```

- [ ] **Step 2: Run to verify it fails**

Run: `C:/php/php.exe tests/planner_test.php`
Expected: fatal error on the missing `Planner.php`.

- [ ] **Step 3: Implement** `src/Outbound/Planner.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Outbound;

/**
 * Decides what goes to OpManager for a GLPI action (spec §8, D24-D26). Pure: the hooks
 * gather the facts, this class turns them into outbox rows.
 */
final class Planner
{
    public const NOTE_MAX = 2000;

    private const SOLVED = 5;
    private const CLOSED = 6;

    /** D24: only actions typed in a logged-in GLPI session, never the plugin's own writes. */
    public static function isHuman(mixed $login_user_id, bool $is_cron, int $service_user, bool $plugin_writing): bool
    {
        $uid = is_numeric($login_user_id) ? (int) $login_user_id : 0;
        return $uid > 0 && !$is_cron && !$plugin_writing && $uid !== $service_user;
    }

    /**
     * @param list<array{alarm_key: string, entity_key: string}> $active
     * @return list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}>
     */
    public static function forFollowup(int $tickets_id, bool $is_private, string $text, string $user, array $active, int $users_id): array
    {
        $text = trim($text);
        if ($is_private || $text === '') {
            return [];
        }
        $note = self::cap(sprintf('GLPI tiket #%d - followup oleh %s: %s', $tickets_id, $user, $text));
        $rows = [];
        foreach ($active as $alarm) {
            $rows[] = self::row($alarm, $tickets_id, $users_id, 'note', $note);
        }
        return $rows;
    }

    /**
     * @param list<array{alarm_key: string, entity_key: string}> $active
     * @param callable(string): bool $has_clear
     * @return list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}>
     */
    public static function forStatus(int $tickets_id, int $old_status, int $new_status, ?string $solution, string $user, array $active, callable $has_clear, int $users_id): array
    {
        $done = [self::SOLVED, self::CLOSED];
        if (!in_array($new_status, $done, true) || in_array($old_status, $done, true)) {
            return [];
        }
        $solution = $solution === null ? '' : trim($solution);
        $note = $solution !== ''
            ? self::cap(sprintf('GLPI tiket #%d di-Solve oleh %s: %s', $tickets_id, $user, $solution))
            : sprintf('Tiket #%d ditutup oleh %s di GLPI', $tickets_id, $user);
        if ($solution === '' && $new_status === self::SOLVED) {
            $note = sprintf('GLPI tiket #%d di-Solve oleh %s', $tickets_id, $user);
        }
        $rows = [];
        foreach ($active as $alarm) {
            if ($has_clear($alarm['alarm_key'])) {
                continue;
            }
            $rows[] = self::row($alarm, $tickets_id, $users_id, 'note', $note);
            $rows[] = self::row($alarm, $tickets_id, $users_id, 'clear', '');
        }
        return $rows;
    }

    /** @param array{alarm_key: string, entity_key: string} $alarm */
    private static function row(array $alarm, int $tickets_id, int $users_id, string $action, string $payload): array
    {
        return [
            'alarm_key'  => $alarm['alarm_key'],
            'entity_key' => $alarm['entity_key'],
            'tickets_id' => $tickets_id,
            'users_id'   => $users_id,
            'action'     => $action,
            'payload'    => $payload,
        ];
    }

    private static function cap(string $text): string
    {
        return strlen($text) <= self::NOTE_MAX ? $text : substr($text, 0, self::NOTE_MAX - 3) . '...';
    }
}
```

- [ ] **Step 4: Run tests**

Run: `C:/php/php.exe tests/planner_test.php && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (18 passed, 0 failed)`, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: outbound planner (human-only, public followups, solve/close notes)`

---

### Task 5: GLPI glue (gateway flag, hooks, immediate send)

**Files:**
- Create: `src/Outbound/Hooks.php`, `tests/outbound_wiring_test.php`
- Modify: `src/Ticketing/GlpiTicketGateway.php`, `src/Ticketing/DbAlarmStore.php`, `setup.php`

**Interfaces:**
- Consumes: `Planner`, `Sender`, `DbOutboxStore`, `Client`.
- Produces:
  - `GlpiTicketGateway::isWriting(): bool` (static);
  - `DbAlarmStore::activeForTicket(int $tickets_id): list<array{alarm_key, entity_key}>` (static);
  - `Hooks::followupAdded(\ITILFollowup $f): void`;
  - `Hooks::ticketUpdated(\Ticket $t): void`;
  - hooks registered in `plugin_init_opmanager`.

- [ ] **Step 1: Write the failing test**

`tests/outbound_wiring_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';

$setup = (string) file_get_contents(OPM_ROOT . '/setup.php');
$gw    = (string) file_get_contents(OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php');
$hooks = (string) @file_get_contents(OPM_ROOT . '/src/Outbound/Hooks.php');
$store = (string) file_get_contents(OPM_ROOT . '/src/Ticketing/DbAlarmStore.php');

check('followup hook registered', (bool) preg_match("/\\\$PLUGIN_HOOKS\['item_add'\]\['opmanager'\]\s*=\s*\[\s*\\\\ITILFollowup::class\s*=>\s*\[\\\\GlpiPlugin\\\\Opmanager\\\\Outbound\\\\Hooks::class, 'followupAdded'\]/", $setup));
check('ticket update hook registered', (bool) preg_match("/\\\$PLUGIN_HOOKS\['item_update'\]\['opmanager'\]\s*=\s*\[\s*\\\\Ticket::class\s*=>\s*\[\\\\GlpiPlugin\\\\Opmanager\\\\Outbound\\\\Hooks::class, 'ticketUpdated'\]/", $setup));
foreach (['create', 'createStorm', 'followup', 'reopen', 'solve', 'link'] as $m) {
    check("gateway {$m} runs under the plugin-writing flag", (bool) preg_match('/public function ' . $m . '\(.*?\{\s*(return )?\$this->asPlugin\(/s', $gw));
}
check('hooks ask the planner who is human', substr_count($hooks, 'Planner::isHuman(') === 2 && substr_count($hooks, 'GlpiTicketGateway::isWriting()') === 2);
check('hooks never throw into GLPI', substr_count($hooks, 'catch (\Throwable $e)') >= 2);
check('hooks send with a short timeout', (bool) preg_match('/new Client\([^;]*Hooks::SEND_TIMEOUT/s', $hooks));
check('solution text read from the newest solution by this user', (bool) preg_match("/ITILSolution.*?'users_id' => \\\$uid.*?'ORDER' => 'id DESC'/s", $hooks));
check('active alarms of a ticket', (bool) preg_match("/function activeForTicket.*?'tickets_id' => \\\$tickets_id.*?'state' => 'active'/s", $store));

done();
```

- [ ] **Step 2: Run to verify it fails**

Run: `C:/php/php.exe tests/outbound_wiring_test.php`
Expected: `FAIL` lines for all checks except possibly none. (The hooks file does not exist yet.)

- [ ] **Step 3: Implement**

In `src/Ticketing/GlpiTicketGateway.php`, add:

```php
    /** Depth of plugin writes in progress; hooks ignore what happens meanwhile (D24). */
    private static int $writing = 0;

    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    /** @template T @param callable(): T $fn @return T */
    private function asPlugin(callable $fn): mixed
    {
        self::$writing++;
        try {
            return $fn();
        } finally {
            self::$writing--;
        }
    }
```

Then make the body of each of `create`, `createStorm`, `followup`, `reopen`, `solve`, and `link` start with `return $this->asPlugin(function () use (...) { <old body> });`. For void methods, use `$this->asPlugin(function () use (...) { <old body> });`. Keep the existing `withoutAutoAssign` calls inside.

In `src/Ticketing/DbAlarmStore.php`, add:

```php
    /** @return list<array{alarm_key: string, entity_key: string}> */
    public static function activeForTicket(int $tickets_id): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['alarm_key', 'entity_key'],
            'FROM'   => Alarm::getTable(),
            'WHERE'  => ['tickets_id' => $tickets_id, 'state' => 'active', 'NOT' => ['entity_key' => '']],
            'ORDER'  => 'id',
        ]) as $r) {
            $out[] = ['alarm_key' => (string) $r['alarm_key'], 'entity_key' => (string) $r['entity_key']];
        }
        return $out;
    }
```

`src/Outbound/Hooks.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Outbound;

use Glpi\RichText\RichText;
use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\Ticketing\DbAlarmStore;
use GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway;

/**
 * GLPI -> OpManager (Plan 3). Runs inside the technician's save: decide (Planner), queue,
 * try to send once with a short timeout, and never let an error reach GLPI. What is not
 * sent here is retried by the OutboxSend cron.
 */
final class Hooks
{
    public const SEND_TIMEOUT = 5;

    public static function followupAdded(\ITILFollowup $followup): void
    {
        try {
            if (($followup->fields['itemtype'] ?? '') !== \Ticket::class) {
                return;
            }
            $cfg = Config::ticketingConfig();
            $uid = \Session::getLoginUserID();
            if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                return;
            }
            $tickets_id = (int) $followup->fields['items_id'];
            $rows = Planner::forFollowup(
                $tickets_id,
                (int) ($followup->fields['is_private'] ?? 0) === 1,
                RichText::getTextFromHtml((string) ($followup->fields['content'] ?? '')),
                (string) getUserName((int) $uid),
                DbAlarmStore::activeForTicket($tickets_id),
                (int) $uid
            );
            self::queueAndSend($rows, $cfg);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('opmanager', 'Outbound followup hook: ' . $e->getMessage() . "\n");
        }
    }

    public static function ticketUpdated(\Ticket $ticket): void
    {
        try {
            if (!in_array('status', $ticket->updates ?? [], true)) {
                return;
            }
            $cfg = Config::ticketingConfig();
            $uid = \Session::getLoginUserID();
            if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                return;
            }
            global $DB;
            $tickets_id = (int) $ticket->getID();
            // The solution row is inserted before ITILSolution::post_addItem updates the
            // ticket status, so it is already readable here (CommonDBTM::add order).
            $sol = $DB->request([
                'SELECT' => ['content'],
                'FROM'   => \ITILSolution::getTable(),
                'WHERE'  => ['itemtype' => \Ticket::class, 'items_id' => $tickets_id, 'users_id' => $uid],
                'ORDER'  => 'id DESC',
                'LIMIT'  => 1,
            ])->current();
            $store = new DbOutboxStore();
            $rows  = Planner::forStatus(
                $tickets_id,
                (int) ($ticket->oldvalues['status'] ?? 0),
                (int) $ticket->fields['status'],
                $sol ? RichText::getTextFromHtml((string) $sol['content']) : null,
                (string) getUserName((int) $uid),
                DbAlarmStore::activeForTicket($tickets_id),
                [$store, 'hasClear'],
                (int) $uid
            );
            self::queueAndSend($rows, $cfg);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('opmanager', 'Outbound ticket hook: ' . $e->getMessage() . "\n");
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private static function queueAndSend(array $rows, array $cfg): void
    {
        if ($rows === []) {
            return;
        }
        $now   = (string) \Session::getCurrentTime();
        $store = new DbOutboxStore();
        foreach ($rows as $row) {
            $store->enqueue($row, $now);
        }
        try {
            $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1', null, self::SEND_TIMEOUT);
            $sender = new Sender($store, $client, $cfg['outbound_max_attempts']);
            $sender->run($now, 20);
            foreach ($sender->lastErrors() as $error) {
                \Toolbox::logInFile('opmanager', 'Outbound: ' . $error . " (will retry)\n");
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('opmanager', 'Outbound send: ' . $e->getMessage() . " (queued for cron)\n");
        }
    }
}
```

Ruling to carry: `Planner::forStatus` reads `$solution` only from the newest solution **by this user**. A solution by another user (for example one approved later) gives the D26 short note. That is acceptable, because that person did not type it in this action.

In `setup.php` `plugin_init_opmanager()`, after the `secured_configs` line:

```php
    // GLPI -> OpManager (Plan 3): human actions only, decided in Outbound\Planner (D24).
    $PLUGIN_HOOKS['item_add']['opmanager'] = [\ITILFollowup::class => [\GlpiPlugin\Opmanager\Outbound\Hooks::class, 'followupAdded']];
    $PLUGIN_HOOKS['item_update']['opmanager'] = [\Ticket::class => [\GlpiPlugin\Opmanager\Outbound\Hooks::class, 'ticketUpdated']];
```

- [ ] **Step 4: Run tests**

Run: `C:/php/php.exe tests/outbound_wiring_test.php && for f in setup.php src/Outbound/Hooks.php src/Ticketing/GlpiTicketGateway.php src/Ticketing/DbAlarmStore.php; do C:/php/php.exe -l $f; done && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (13 passed, 0 failed)`, four `No syntax errors detected`, then `SUITES OK`. The existing gateway_static_test must still pass.

- [ ] **Step 5: Commit** — `feat: GLPI hooks queue notes/clears for human actions; gateway marks its own writes`

---

### Task 6: Cron, Setup, alarm page, release 0.4.0

**Files:**
- Modify: `src/Cron.php`, `src/SuiteConfig.php`, `src/SelfCheck.php`, `src/Alarm.php`, `setup.php`, `CHANGELOG.md`, `tests/suiteconfig_test.php`, `tests/synclock_test.php`
- Create: `docs/runbook-v0.4.0.md`

**Interfaces:**
- Consumes: `Sender`, `DbOutboxStore`, `Client`.
- Produces: `Cron::cronOutboxSend(\CronTask $task): int`; Setup shows `outbound_max_attempts`, the queue summary, and the last error; the alarm page title is `<display_name> - <alarm_key>` and it shows an Entity row.

- [ ] **Step 1: Write the failing tests**

Append to `tests/synclock_test.php` before `done();`:

```php
check('outbox cron sends with the configured retry limit', (bool) preg_match('/function cronOutboxSend.*?new Sender\(new DbOutboxStore\(\), \$client, \$cfg\[\'outbound_max_attempts\'\]\)/s', $cron));
check('outbox cron described', str_contains($cron, "'OutboxSend' =>"));
$alarm = (string) file_get_contents(OPM_ROOT . '/src/Alarm.php');
check('alarm page title is device + alarm id, not N/A', (bool) preg_match("/function getName\(.*?display_name.*?alarm_key/s", $alarm));
check('alarm page shows the entity', str_contains($alarm, "'entity_key'"));
```

In `tests/suiteconfig_test.php`:
- add `require OPM_ROOT . '/src/Outbound/OutboxStore.php'; require OPM_ROOT . '/src/Outbound/DbOutboxStore.php';` next to the other requires (`summary()` handles the FakeDB `tableExists=false`);
- in the editor block, add:

```php
    check('outbound retry field rendered', str_contains($html, 'name="outbound_max_attempts"'));
    check('outbound queue status shown', str_contains($html, 'Sent to OpManager'));
```

- [ ] **Step 2: Run to verify they fail**

Run: `C:/php/php.exe tests/synclock_test.php; C:/php/php.exe tests/suiteconfig_test.php`
Expected: `FAIL` on the new checks.

- [ ] **Step 3: Implement**

In `src/Cron.php`:
- add the uses `GlpiPlugin\Opmanager\Outbound\DbOutboxStore` and `GlpiPlugin\Opmanager\Outbound\Sender`;
- add `'OutboxSend' => ['description' => __('Send queued notes and clears to OpManager', 'opmanager')],` to `cronInfo`;
- add:

```php
    public static function cronOutboxSend(\CronTask $task): int
    {
        $now    = (string) \Session::getCurrentTime();
        $cfg    = Config::ticketingConfig();
        $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
        $sender = new Sender(new DbOutboxStore(), $client, $cfg['outbound_max_attempts']);
        $stats  = $sender->run($now, 200);
        foreach ($sender->lastErrors() as $error) {
            $task->log('Outbound: ' . $error);
        }
        $task->addVolume($stats['sent']);
        return ($stats['sent'] + $stats['retried'] + $stats['failed']) > 0 ? 1 : 0;
    }
```

In `src/SuiteConfig.php`, at the end of the "Tickets from alarms" fieldset (before its closing `</fieldset>`), add:

```php
        echo '<div class="mt-3"><strong>' . __s('Sent to OpManager (notes and clears)', 'opmanager') . '</strong></div>';
        echo self::textRow('outbound_max_attempts' . $sfx, 'outbound_max_attempts', __('Retries before a note/clear is given up (1-50)', 'opmanager'), Config::get('outbound_max_attempts'), 'number', $disabled);
        $box = (new \GlpiPlugin\Opmanager\Outbound\DbOutboxStore())->summary();
        echo '<div>' . sprintf(__s('Waiting: %d, given up: %d', 'opmanager'), $box['pending'], $box['failed']) . '</div>';
        if ($box['last_error'] !== '') {
            echo '<div class="alert alert-warning">' . __s('Last OpManager write error', 'opmanager') . ': ' . htmlescape($box['last_error']) . '</div>';
        }
```

In `src/SelfCheck.php`, add before `'tickets/template-resolves'`:

```php
            'outbound/table' => static function (): void {
                global $DB;
                self::assert($DB->tableExists('glpi_plugin_opmanager_outbox'), 'outbox table missing - re-run plugin:install');
            },
            'outbound/cron' => static function (): void {
                self::assert((new \CronTask())->getFromDBbyName(Cron::class, 'OutboxSend'), 'OutboxSend cron not registered - re-run plugin:install');
            },
```

In `src/Alarm.php`, add:

```php
    public function getName($options = [])
    {
        $name = trim(($this->fields['display_name'] ?? '') . ' - ' . ($this->fields['alarm_key'] ?? ''), ' -');
        return $name !== '' ? $name : parent::getName($options);
    }
```

In `showForm`, add an Entity row with `'entity_key'` right after the Alarm ID row, following that row's exact markup.

In `setup.php`, set `define('PLUGIN_OPMANAGER_VERSION', '0.4.0');`. Add a `## 0.4.0` section at the top of `CHANGELOG.md`:

```markdown
## 0.4.0

- GLPI → OpManager: when a GLPI user adds a public followup, solves or closes an alarm ticket,
  each still-active alarm of the ticket gets a note, and on Solve/Close it is cleared in
  OpManager. Actions made by the plugin itself, cron or GLPI automation are never sent back.
- Queue with retries (1, 2, 4 … 60 min, Setup limit default 10); status and last error in Setup.
- Alarm page: title shows device and alarm id (was "N/A"), and the OpManager entity is shown.
```

`docs/runbook-v0.4.0.md`: frontmatter (`date: 2026-10-01`, `tags: [opmanager, runbook]`, `status: active`) and these sections:
- **Install:** same commands as runbook v0.3.0 §1 with the 0.4.0 zip, then selfcheck `ALL PASS`, including `outbound/table` and `outbound/cron`.
- **clearAlarm:** verified on production 2026-10-01 (POST, `ackMessage` answer). If it is refused later, report the error from Setup ("Last OpManager write error").
- **Real flow:** on an alarm ticket, check each of these:
  - a public followup → the note appears on the alarm in OpManager within a minute;
  - a private followup → nothing appears;
  - Solve → a "di-Solve oleh" note appears, then the alarm is Clear;
  - Close without a solution → the short note appears, then Clear;
  - an alarm that clears by itself → the plugin auto-solves the ticket and **no** note goes to OpManager.
- **Failure:** stop OpManager access, or use a bad URL on a test copy. The ticket save still works, Setup shows "Waiting", and once access is restored the cron sends it.

- [ ] **Step 4: Full suite, build, verify**

Run: `C:/php/php.exe tests/run_all.php` → expected `SUITES OK`. Commit with `chore: release 0.4.0 (GLPI to OpManager notes and clear)`. Build with `git archive --format=zip --prefix=opmanager/ -o opmanagerv0.4.0.zip HEAD` and run `python tools/release_check.py opmanagerv0.4.0.zip --install C:/Users/<user>/Downloads/glpi/plugins` → expected `RELEASE OK`.

- [ ] **Step 5: Hub** — §2 row 7: "v0.4.0 built, not deployed (GLPI → OpManager notes/clear, human actions only)".
