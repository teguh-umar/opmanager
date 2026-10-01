# opmanager Plan 2b — Webhook Receiver (v0.3.0) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship opmanager v0.3.0. OpManager's "Invoke a Webhook" action POSTs `{"alarm_id":"$alarmid","entity":"$entity"}` with header `X-OPM-Token` to `/plugins/opmanager/front/webhook.php`. The plugin checks the token, fetches that alarm from the OpManager API, and runs it through the same ticket engine as the 10-minute poll, so tickets appear and are solved within seconds. The poll stays as the safety net.

**Architecture:**
- The webhook is a **trigger only** (design approved 2026-10-01). `Webhook\Receiver` is pure; its inputs are the token, the raw body, an `AlarmSource`, a lock and a runner callback. It returns a status code and a JSON body.
- `front/webhook.php` is registered as a stateless path in `plugin_opmanager_boot()`, so it gets no session and no CSRF check. In 11.0.5 a stateless request gets `Session::initVars()` exactly like CLI cron (`SessionStart.php:117`), so the engine runs in the same context as `AlarmSync`.
- One MySQL named lock (`GET_LOCK('opmanager_alarm_sync')`) serialises the webhook and the cron, so they cannot both create a ticket for one alarm.
- The deferred CRLF pattern bug is fixed here too.

**Tech Stack:** PHP 8.2+ (local 8.4 CLI, no GLPI runtime), GLPI 11.0.5, MySQL `GET_LOCK`.

**Spec:** `docs/superpowers/specs/2026-09-29-opmanager-design.md` (D7, D17, D20–D22, §3 receiver, §11 security, §14 webhook facts). Base: `main` at `aee6195` (0.2.1). Out of scope: Plan 3 (notes/clear back to OpManager).

## Global Constraints

- Stateless path registered **in `plugin_opmanager_boot()`** via `Glpi\Http\SessionManager::registerPluginStatelessPath('opmanager', '#^/front/webhook\.php$#')`. 11.0.5 calls `plugin_<key>_boot()` from `Plugin::bootPlugins()` (`Plugin.php:365`), before `SessionStart` decides statelessness. `plugin_init` is too late. Reference: core plugin `glpiinventory/setup.php:428`.
- Webhook auth: header `X-OPM-Token` only, compared with `hash_equals` against config `webhook_token` (secured, so encrypted at rest; `Config::SECURED` gains it). An empty configured token means the webhook is disabled and every call gets 401. The token is never logged and never echoed back except once, right after "Generate new token".
- Responses (JSON):
  - `405` non-POST;
  - `401` bad or missing token, or webhook disabled;
  - `413` body over 8 KB;
  - `400` invalid JSON or `alarm_id`/`entity` missing or not matching `^[A-Za-z0-9._:-]{1,64}$`;
  - `202` OpManager unreachable, alarm not found, a different alarm on that entity, or lock busy (the poll handles it);
  - `200` processed.
- The engine is shared: the webhook calls `AlarmSync::processOne()`. No second decision path.
- Lock: `GET_LOCK('opmanager_alarm_sync', N)`, with N = 10 s for the webhook and 60 s for the cron. Always released in `finally`.
- Pattern line endings normalised to `\n` on save and on upgrade compare (deferred bug from 0.2.1).
- Keys `INT UNSIGNED`, dates `TIMESTAMP`, no raw writes to core tables, no count badges, `echo Html::submit`.
- Release: tag `0.3.0` local only (hub §0 rule 6: GitHub Release only at x.0.0 / x.10.0). The lib SHAs are checked inside the zip, and the zip is installed into the local GLPI copy.

## Review Focus

1. **A webhook and the cron process the same new alarm at the same second.** Exactly one ticket. Pinned in Task 3 (`lock serialises processing`) plus the existing externalid reuse.
2. **A webhook with a valid token but an entity whose current alarm is a different alarmId** (OpManager re-raised it) must not attach the new alarm to the old one. It is 202 and left to the poll. Pinned in Task 4 (`different alarm on entity is deferred`).
3. **A replayed or forged request**: a wrong or empty token, the token in a query string instead of the header, or an oversized body. Nothing is processed. Pinned in Task 4.
4. **The webhook arrives while OpManager's API is down.** 202, nothing written, poll retries. Pinned in Task 4 (`api failure deferred`).
5. **The admin regenerates the token.** The old token stops working immediately and the new one is shown exactly once. Pinned in Task 2 (`new token differs and is 48 hex`) and Task 5 (`token never rendered in setup`).

---

### Task 1: CRLF-safe description patterns

**Files:**
- Modify: `src/Config.php`, `tests/config_test.php`

**Interfaces:**
- Produces: `Config::normaliseLines(string $text): string` (pure); `sanitizeTicketing()` stores patterns with `\n`; `upgradedContentPattern()` compares normalised text.

- [ ] **Step 1: Write the failing test**

Append to `tests/config_test.php` before `done();`:

```php
// v0.3.0: a textarea posts \r\n; stored patterns and the upgrade compare must not care.
$crlf = str_replace("\n", "\r\n", Config::PREVIOUS_CONTENT_PATTERNS[0]);
check('upgrade recognises an old default saved with CRLF', Config::upgradedContentPattern($crlf) === Config::DEFAULT_CONTENT_PATTERN);
$r = Config::sanitizeTicketing(['ticket_content_pattern' => "Line 1\r\nLine 2\rLine 3"] + $t);
check('patterns stored with \n only', $r['values']['ticket_content_pattern'] === "Line 1\nLine 2\nLine 3");
check('normaliseLines helper', Config::normaliseLines("a\r\nb\rc\n") === "a\nb\nc\n");
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/config_test.php`
Expected: `FAIL upgrade recognises an old default saved with CRLF`, `FAIL patterns stored with \n only`, then a fatal on `normaliseLines`.

- [ ] **Step 3: Implement**

In `src/Config.php`, add before `upgradedContentPattern()`:

```php
    /** Pure: CRLF / CR -> LF (a browser textarea posts CRLF). */
    public static function normaliseLines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
```

and change `upgradedContentPattern()`'s body to:

```php
        return in_array(self::normaliseLines($current), self::PREVIOUS_CONTENT_PATTERNS, true) ? self::DEFAULT_CONTENT_PATTERN : null;
```

In `sanitizeTicketing()`, change `$v = trim((string) ($post[$key] ?? ''));` (inside the pattern loop) to:

```php
            $v = trim(self::normaliseLines((string) ($post[$key] ?? '')));
```

- [ ] **Step 4: Run test**

Run: `C:/php/php.exe tests/config_test.php && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (48 passed, 0 failed)`; `SUITES OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Config.php tests/config_test.php
git commit -m "fix: CRLF-safe ticket patterns and upgrade compare

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Webhook token config

**Files:**
- Modify: `src/Config.php`, `tests/config_test.php`

**Interfaces:**
- Produces:
  - `Config::SECURED = ['opm_api_key', 'webhook_token']`;
  - DEFAULTS `webhook_token` `''`;
  - `Config::webhookToken(): string` (decrypted);
  - `Config::newWebhookToken(): string` (48 lowercase hex from `random_bytes(24)`);
  - `Config::WEBHOOK_HEADER = 'X-OPM-Token'`;
  - `Config::WEBHOOK_PATH = '/plugins/opmanager/front/webhook.php'`.

- [ ] **Step 1: Write the failing test**

Append to `tests/config_test.php` before `done();`:

```php
// v0.3.0 webhook token
check('webhook token is secured', in_array('webhook_token', Config::SECURED, true) && Config::DEFAULTS['webhook_token'] === '');
$a = Config::newWebhookToken();
$b = Config::newWebhookToken();
check('new token differs and is 48 hex', $a !== $b && preg_match('/^[0-9a-f]{48}$/', $a) === 1);
check('webhook header and path constants', Config::WEBHOOK_HEADER === 'X-OPM-Token' && Config::WEBHOOK_PATH === '/plugins/opmanager/front/webhook.php');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/config_test.php`
Expected: `FAIL webhook token is secured`, then a fatal on `newWebhookToken`.

- [ ] **Step 3: Implement**

In `src/Config.php`:
- change `public const SECURED = ['opm_api_key'];` to `public const SECURED = ['opm_api_key', 'webhook_token'];`
- in DEFAULTS, after `'opm_api_key' => '',`, add `'webhook_token' => '',`
- add below `SECURED`:

```php
    public const WEBHOOK_HEADER = 'X-OPM-Token';

    public const WEBHOOK_PATH = '/plugins/opmanager/front/webhook.php';
```

- add after `apiKey()`:

```php
    /** Decrypted webhook token; '' = webhook disabled. */
    public static function webhookToken(): string
    {
        $raw = self::get('webhook_token');
        return $raw === '' ? '' : (string) ((new \GLPIKey())->decrypt($raw) ?? '');
    }

    public static function newWebhookToken(): string
    {
        return bin2hex(random_bytes(24));
    }
```

- [ ] **Step 4: Run test**

Run: `C:/php/php.exe tests/config_test.php`
Expected: `ALL PASS (51 passed, 0 failed)`.

- [ ] **Step 5: Commit**

```bash
git add src/Config.php tests/config_test.php
git commit -m "feat: encrypted webhook token config

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Shared sync lock and single-alarm processing

**Files:**
- Create: `src/Ticketing/SyncLock.php`, `src/Ticketing/DbSyncLock.php`, `tests/synclock_test.php`
- Modify: `src/Ticketing/AlarmSync.php`, `src/Cron.php`, `tests/alarmsync_test.php`

**Interfaces:**
- Produces:
  - `interface SyncLock { acquire(int $timeout_seconds): bool; release(): void; }`;
  - `DbSyncLock implements SyncLock` (MySQL `GET_LOCK`/`RELEASE_LOCK` on name `opmanager_alarm_sync`);
  - `SyncLock::NAME = 'opmanager_alarm_sync'` as a constant on the interface;
  - `AlarmSync::processOne(array $alarm, string $now): array<string,int>` (same stats keys as `run`, with the `fetched` key = 1).

- [ ] **Step 1: Write the failing tests**

`tests/synclock_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/SyncLock.php';

$src = (string) @file_get_contents(OPM_ROOT . '/src/Ticketing/DbSyncLock.php');
check('lock uses MySQL GET_LOCK with the shared name', str_contains($src, 'GET_LOCK') && str_contains($src, 'RELEASE_LOCK') && str_contains($src, 'self::NAME'));
check('shared lock name', \GlpiPlugin\Opmanager\Ticketing\SyncLock::NAME === 'opmanager_alarm_sync');
$cron = (string) file_get_contents(OPM_ROOT . '/src/Cron.php');
check('cron AlarmSync takes the lock and releases it in finally', (bool) preg_match('/cronAlarmSync.*?->acquire\(60\).*?finally.*?->release\(\)/s', $cron));

done();
```

Append to `tests/alarmsync_test.php` before `done();`:

```php
// v0.3.0: the webhook processes one alarm through the same engine.
[$sync, $src, $store, $gw] = $mk([]);
$stats = $sync->processOne($al('w1', 1, 'dev-w'), $now);
check('processOne creates the ticket', $stats['created'] === 1 && $store->find('w1')['tickets_id'] === 100);
$stats = $sync->processOne($al('w1', 1, 'dev-w'), $now);
check('lock serialises processing: second identical call is a no-op', $stats['created'] === 0 && $gw->log === ['create 100 w1']);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/php/php.exe tests/synclock_test.php; C:/php/php.exe tests/alarmsync_test.php`
Expected: the first fatals on the missing `src/Ticketing/SyncLock.php`; the second fatals on `undefined method …processOne()`.

- [ ] **Step 3: Implement**

`src/Ticketing/SyncLock.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** One lock shared by the AlarmSync cron and the webhook, so one alarm is never ticketed twice. */
interface SyncLock
{
    public const NAME = 'opmanager_alarm_sync';

    public function acquire(int $timeout_seconds): bool;

    public function release(): void;
}
```

`src/Ticketing/DbSyncLock.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** MySQL named lock - held per connection, released explicitly or when the connection ends. */
final class DbSyncLock implements SyncLock
{
    public function acquire(int $timeout_seconds): bool
    {
        global $DB;

        $result = $DB->doQuery(sprintf("SELECT GET_LOCK('%s', %d) AS l", self::NAME, max(0, $timeout_seconds)));
        $row    = $result ? $result->fetch_assoc() : null;
        return (int) ($row['l'] ?? 0) === 1;
    }

    public function release(): void
    {
        global $DB;

        $DB->doQuery(sprintf("SELECT RELEASE_LOCK('%s')", self::NAME));
    }
}
```

In `src/Ticketing/AlarmSync.php`, add after `run()`:

```php
    /**
     * One alarm, e.g. from the webhook (v0.3.0). Same engine and guard as run();
     * the caller holds the SyncLock.
     *
     * @return array<string, int>
     */
    public function processOne(array $alarm, string $now): array
    {
        $this->errors = [];
        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked', 'errors'], 0);
        $stats['fetched'] = 1;
        $this->guarded($alarm, $now, $stats);
        return $stats;
    }
```

In `src/Cron.php`:
- add `use GlpiPlugin\Opmanager\Ticketing\DbSyncLock;`;
- in `cronAlarmSync()`, replace the line `$stats = $sync->run($now);` with:

```php
            $lock = new DbSyncLock();
            if (!$lock->acquire(60)) {
                $task->log('another alarm sync (webhook) is running; skipped this run');
                return 0;
            }
            try {
                $stats = $sync->run($now);
            } finally {
                $lock->release();
            }
```

- [ ] **Step 4: Run tests**

Run: `C:/php/php.exe tests/synclock_test.php && C:/php/php.exe tests/alarmsync_test.php && C:/php/php.exe -l src/Cron.php && C:/php/php.exe -l src/Ticketing/DbSyncLock.php`
Expected: `ALL PASS (3 passed, 0 failed)`, `ALL PASS (29 passed, 0 failed)`, two `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Ticketing/SyncLock.php src/Ticketing/DbSyncLock.php src/Ticketing/AlarmSync.php src/Cron.php tests/synclock_test.php tests/alarmsync_test.php
git commit -m "feat: shared MySQL lock for cron and webhook; single-alarm processing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Pure webhook receiver

**Files:**
- Create: `src/Webhook/Receiver.php`, `tests/receiver_test.php`

**Interfaces:**
- Consumes: `AlarmSource::alarmByEntity` (Plan 2), `SyncLock` (T3), `ClientException`.
- Produces: `Receiver::__construct(string $token, AlarmSource $source, SyncLock $lock, callable $runner)`, where `$runner` is `callable(array $alarm): array<string,int>`. `Receiver::handle(string $method, string $header_token, string $raw_body): array{status: int, body: array<string, mixed>}`. `Receiver::MAX_BODY = 8192`.

- [ ] **Step 1: Write the failing test**

`tests/receiver_test.php`:

```php
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
    public function __construct(public mixed $answer) {}
    public function listActiveAlarms(): array { return []; }
    public function alarmByEntity(string $e): ?array { $this->calls[] = $e; if ($this->answer instanceof ClientException) { throw $this->answer; } return $this->answer; }
}
final class Lock implements SyncLock
{
    public bool $free = true; public int $held = 0; public int $released = 0;
    public function acquire(int $t): bool { if ($this->free) { $this->held++; } return $this->free; }
    public function release(): void { $this->released++; }
}

$alarm  = ['alarm_key' => '10000003244', 'entity_key' => '10000024830', 'severity' => 1];
$runs   = [];
$runner = function (array $a) use (&$runs) { $runs[] = $a['alarm_key']; return ['created' => 1]; };
$mk     = static fn ($answer, string $token = 'secret-token') => [new Receiver($token, $s = new Src($answer), $l = new Lock(), $runner), $s, $l];
$body   = json_encode(['alarm_id' => '10000003244', 'entity' => '10000024830']);

[$r, $s, $l] = $mk($alarm);
check('GET refused', $r->handle('GET', 'secret-token', $body)['status'] === 405);
check('wrong token refused', $r->handle('POST', 'nope', $body)['status'] === 401 && $runs === []);
check('empty token refused', $r->handle('POST', '', $body)['status'] === 401);
check('oversized body refused', $r->handle('POST', 'secret-token', str_repeat('x', Receiver::MAX_BODY + 1))['status'] === 413);
check('invalid JSON refused', $r->handle('POST', 'secret-token', '{nope')['status'] === 400);
check('missing entity refused', $r->handle('POST', 'secret-token', '{"alarm_id":"1"}')['status'] === 400);
check('unsafe id refused', $r->handle('POST', 'secret-token', '{"alarm_id":"1 OR 1=1","entity":"2"}')['status'] === 400);
check('nothing fetched for refused calls', $s->calls === []);

$res = $r->handle('POST', 'secret-token', $body);
check('valid call processed', $res['status'] === 200 && $runs === ['10000003244'] && $s->calls === ['10000024830']);
check('lock taken and released', $l->held === 1 && $l->released === 1);

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
[$r, , $l] = $mk($alarm);
$l->free = false;
check('lock busy is deferred, not processed', $r->handle('POST', 'secret-token', $body)['status'] === 202 && $runs === [] && $l->released === 0);
[$r, , $l] = $mk($alarm);
$boom = new Receiver('secret-token', new Src($alarm), $l, function () { throw new RuntimeException('engine down'); });
check('engine exception still releases the lock', $boom->handle('POST', 'secret-token', $body)['status'] === 500 && $l->released === 1);

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/receiver_test.php`
Expected: fatal on the missing `src/Webhook/Receiver.php`.

- [ ] **Step 3: Implement**

`src/Webhook/Receiver.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Webhook;

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\SyncLock;

/**
 * Pure webhook handling (v0.3.0, design approved 2026-10-01). The webhook is only a
 * trigger: the alarm itself is fetched from the OpManager API by entity and run
 * through the same engine as the poll. Anything uncertain answers 202 and is left
 * to the 10-minute poll - never guessed.
 */
final class Receiver
{
    public const MAX_BODY = 8192;

    private const ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    /** @var callable(array): array<string, int> */
    private $runner;

    public function __construct(private string $token, private AlarmSource $source, private SyncLock $lock, callable $runner)
    {
        $this->runner = $runner;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function handle(string $method, string $header_token, string $raw_body): array
    {
        if (strtoupper($method) !== 'POST') {
            return self::out(405, 'method not allowed');
        }
        if ($this->token === '' || $header_token === '' || !hash_equals($this->token, $header_token)) {
            return self::out(401, 'unauthorized');
        }
        if (strlen($raw_body) > self::MAX_BODY) {
            return self::out(413, 'body too large');
        }
        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            return self::out(400, 'invalid JSON');
        }
        $alarm_id = is_scalar($data['alarm_id'] ?? null) ? trim((string) $data['alarm_id']) : '';
        $entity   = is_scalar($data['entity'] ?? null) ? trim((string) $data['entity']) : '';
        if (preg_match(self::ID_PATTERN, $alarm_id) !== 1 || preg_match(self::ID_PATTERN, $entity) !== 1) {
            return self::out(400, 'alarm_id and entity are required');
        }

        try {
            $alarm = $this->source->alarmByEntity($entity);
        } catch (ClientException) {
            return self::out(202, 'deferred: OpManager not reachable, the poll will handle it');
        }
        if ($alarm === null || (string) $alarm['alarm_key'] !== $alarm_id) {
            return self::out(202, 'deferred: alarm not current on this entity, the poll will handle it');
        }

        if (!$this->lock->acquire(10)) {
            return self::out(202, 'deferred: alarm sync busy, the poll will handle it');
        }
        try {
            $stats = ($this->runner)($alarm);
        } catch (\Throwable) {
            return self::out(500, 'processing failed, see files/_log/opmanager.log');
        } finally {
            $this->lock->release();
        }

        return ['status' => 200, 'body' => ['status' => 'ok', 'alarm_id' => $alarm_id, 'stats' => $stats]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function out(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['status' => $status < 300 ? 'ok' : 'error', 'message' => $message]];
    }
}
```

- [ ] **Step 4: Run test**

Run: `C:/php/php.exe tests/receiver_test.php`
Expected: `ALL PASS (16 passed, 0 failed)`.

- [ ] **Step 5: Prove the token test bites**

Temporarily replace the token condition with `if (false) {`. Confirm `FAIL wrong token refused`, `FAIL empty token refused` and `FAIL webhook disabled…`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/Webhook/Receiver.php tests/receiver_test.php
git commit -m "feat: pure webhook receiver (token, validation, fetch-by-entity, lock)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Endpoint, boot registration, Setup

**Files:**
- Create: `front/webhook.php`, `tests/webhook_wiring_test.php`
- Modify: `setup.php`, `src/SuiteConfig.php`, `front/config.php`, `tests/suiteconfig_test.php`

**Interfaces:**
- Consumes: `Receiver` (T4), `DbSyncLock` (T3), `Config::webhookToken/newWebhookToken/WEBHOOK_*` (T2), `AlarmSync::processOne` (T3).
- Produces: function `plugin_opmanager_boot(): void`; endpoint `front/webhook.php`; Setup "Webhook" fieldset with button `generate_webhook_token`.

- [ ] **Step 1: Write the failing tests**

`tests/webhook_wiring_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';

$setup = (string) file_get_contents(OPM_ROOT . '/setup.php');
check('stateless path registered in plugin_opmanager_boot', (bool) preg_match('/function plugin_opmanager_boot\(\).*?registerPluginStatelessPath\(\'opmanager\', \'#\^\/front\/webhook\\\\\.php\$#\'\)/s', $setup));
check('not registered in plugin_init (too late)', !preg_match('/function plugin_init_opmanager\(\).*?registerPluginStatelessPath/s', $setup));

$hook = (string) @file_get_contents(OPM_ROOT . '/front/webhook.php');
check('endpoint exists', $hook !== '');
check('endpoint reads the token from the header only', str_contains($hook, "HTTP_X_OPM_TOKEN") && !str_contains($hook, '$_GET') && !str_contains($hook, '$_REQUEST'));
check('endpoint sets status and JSON', str_contains($hook, 'http_response_code(') && str_contains($hook, 'application/json'));
check('endpoint has no session or CSRF calls', !preg_match('/checkCSRF|checkRight|includes\.php/', $hook));
check('endpoint uses the shared engine', str_contains($hook, '->processOne('));

$cfg = (string) file_get_contents(OPM_ROOT . '/front/config.php');
check('token regeneration needs config UPDATE', (bool) preg_match('/generate_webhook_token.*?checkRight\(RightDefinition::CONFIG, UPDATE\)/s', $cfg));

done();
```

In `tests/suiteconfig_test.php`:
- in the first `namespace {}` block, add `$GLOBALS['CFG_GLPI'] = ['url_base' => 'https://comit.pgn.co.id'];`;
- in the editor block (after `SuiteConfig::showForm(false);` and its `$html` assignment), before `done();`, add:

```php
    check('webhook URL shown', str_contains($html, 'https://comit.pgn.co.id/plugins/opmanager/front/webhook.php'));
    check('webhook header name shown', str_contains($html, 'X-OPM-Token'));
    check('generate token button shown to editors', str_contains($html, "name='generate_webhook_token'"));
    $GLOBALS['CFG_VALUES']['webhook_token'] = 'ENCRYPTED-WEBHOOK-TOKEN';
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('token never rendered in setup', !str_contains($html, 'ENCRYPTED-WEBHOOK-TOKEN'));
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/php/php.exe tests/webhook_wiring_test.php; C:/php/php.exe tests/suiteconfig_test.php`
Expected: `FAIL` lines for the wiring checks and for the three webhook UI checks.

- [ ] **Step 3: Implement**

In `setup.php`, add after `plugin_opmanager_check_config()`:

```php
/**
 * Runs from Plugin::bootPlugins(), before SessionStart decides whether a request is
 * stateless - plugin_init is too late for this (11.0.5; same as core glpiinventory).
 */
function plugin_opmanager_boot(): void
{
    // Machine-to-machine webhook from OpManager: no session, no CSRF, own token auth.
    \Glpi\Http\SessionManager::registerPluginStatelessPath('opmanager', '#^/front/webhook\.php$#');
}
```

`front/webhook.php`:

```php
<?php

/**
 * OpManager "Invoke a Webhook" receiver (v0.3.0). Stateless (see plugin_opmanager_boot):
 * no session, no CSRF; authenticated by the X-OPM-Token header only. The body is a
 * trigger {"alarm_id":"$alarmid","entity":"$entity"}; the alarm itself is fetched from
 * the OpManager API and run through the same engine as the AlarmSync poll.
 */

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\Matching\DbAssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\Ticketing\AddressingRanges;
use GlpiPlugin\Opmanager\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Opmanager\Ticketing\AlarmSync;
use GlpiPlugin\Opmanager\Ticketing\DbAlarmStore;
use GlpiPlugin\Opmanager\Ticketing\DbDeviceIndex;
use GlpiPlugin\Opmanager\Ticketing\DbSyncLock;
use GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway;
use GlpiPlugin\Opmanager\Ticketing\RangeProductionCheck;
use GlpiPlugin\Opmanager\Webhook\Receiver;

$client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
$cfg    = Config::ticketingConfig();

$receiver = new Receiver(
    Config::webhookToken(),
    $client,
    new DbSyncLock(),
    static function (array $alarm) use ($client, $cfg): array {
        $sync = new AlarmSync(
            $client,
            new DbAlarmStore(),
            new GlpiTicketGateway($cfg),
            new AlarmAssetResolver(new DbDeviceIndex(), new Matcher(new DbAssetLookup(Config::assetItemtypes())), Config::get('match_mode')),
            new RangeProductionCheck(AddressingRanges::load($cfg['production_addressings']), [AddressingRanges::class, 'assetIps']),
            $cfg
        );
        $stats = $sync->processOne($alarm, (string) \Session::getCurrentTime());
        foreach ($sync->lastErrors() as $error) {
            \Toolbox::logInFile('opmanager', 'Webhook: ' . $error . "\n");
        }
        return $stats;
    }
);

$result = $receiver->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['HTTP_X_OPM_TOKEN'] ?? ''),
    (string) file_get_contents('php://input', false, null, 0, Receiver::MAX_BODY + 1)
);

if ($result['status'] >= 400) {
    \Toolbox::logInFile('opmanager', sprintf("Webhook: HTTP %d %s from %s\n", $result['status'], $result['body']['message'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '?'));
}
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
```

In `src/SuiteConfig.php`, directly before the line `echo '<fieldset class="mb-3"><legend class="fs-4">' . __s('Tickets from alarms', 'opmanager') . '</legend>';`, insert:

```php
        global $CFG_GLPI;
        $webhook_url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . Config::WEBHOOK_PATH;
        echo '<fieldset class="mb-3"><legend class="fs-4">' . __s('Webhook from OpManager', 'opmanager') . '</legend>';
        echo '<div>' . __s('OpManager > Notification Profile > Invoke a Webhook: POST, raw JSON', 'opmanager') . '</div>';
        echo '<div class="mb-1">URL: <code>' . htmlescape($webhook_url) . '</code></div>';
        echo '<div class="mb-1">' . __s('Header', 'opmanager') . ': <code>' . htmlescape(Config::WEBHOOK_HEADER) . '</code> = '
            . (Config::get('webhook_token') !== '' ? __s('stored token (shown once when generated)', 'opmanager') : '<strong>' . __s('not set - webhook disabled', 'opmanager') . '</strong>') . '</div>';
        echo '<div class="mb-1">' . __s('Body', 'opmanager') . ': <code>{"alarm_id":"$alarmid","entity":"$entity"}</code></div>';
        if ($canedit) {
            echo '<div class="mt-1">' . \Html::submit(__('Generate new token', 'opmanager'), ['name' => 'generate_webhook_token', 'class' => 'btn btn-outline-secondary']) . '</div>';
        }
        echo '</fieldset>';
```

In `front/config.php`, directly after `Session::checkRight(RightDefinition::CONFIG, READ);`, add:

```php
if (isset($_POST['generate_webhook_token'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $token = Config::newWebhookToken();
    Config::set(['webhook_token' => $token]);
    // Shown exactly once; afterwards only "stored" is displayed (Review Focus 5).
    Session::addMessageAfterRedirect(
        sprintf(__s('New webhook token (copy it into OpManager now, it is not shown again): %s', 'opmanager'), htmlescape($token)),
        false,
        WARNING
    );
    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
}
```

- [ ] **Step 4: Run tests**

Run: `C:/php/php.exe tests/webhook_wiring_test.php && C:/php/php.exe tests/suiteconfig_test.php && for f in setup.php front/webhook.php front/config.php src/SuiteConfig.php; do C:/php/php.exe -l $f; done && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (8 passed, 0 failed)`, suiteconfig `ALL PASS (25 passed, 0 failed)`, four `No syntax errors detected`, `SUITES OK`.

- [ ] **Step 5: Commit**

```bash
git add setup.php front/webhook.php front/config.php src/SuiteConfig.php tests/webhook_wiring_test.php tests/suiteconfig_test.php
git commit -m "feat: OpManager webhook endpoint, boot-time stateless path, Setup token

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Selfcheck, runbook, release 0.3.0, hub

**Files:**
- Modify: `src/SelfCheck.php`, `setup.php`, `CHANGELOG.md`
- Create: `docs/runbook-v0.3.0.md`

- [ ] **Step 1: Selfcheck entries**

In `src/SelfCheck.php`, add before `'tickets/template-resolves'`:

```php
            'webhook/stateless' => static function (): void {
                $request = \Symfony\Component\HttpFoundation\Request::create(Config::WEBHOOK_PATH, 'POST');
                self::assert((new \Glpi\Http\SessionManager())->isResourceStateless($request), 'front/webhook.php is not registered as stateless (plugin_opmanager_boot)');
            },
            'webhook/token' => static function (): void {
                self::assert(Config::webhookToken() !== '', 'Setup > OpManager: generate a webhook token (webhook disabled until then)');
                self::assert(Config::get('webhook_token') !== Config::webhookToken(), 'webhook_token is stored in clear text');
            },
            'webhook/lock' => static function (): void {
                $lock = new \GlpiPlugin\Opmanager\Ticketing\DbSyncLock();
                self::assert($lock->acquire(1), 'GET_LOCK opmanager_alarm_sync not obtainable');
                $lock->release();
            },
```

Run: `C:/php/php.exe -l src/SelfCheck.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2: Version and changelog**

In `setup.php`: `define('PLUGIN_OPMANAGER_VERSION', '0.3.0');`

In `CHANGELOG.md`, add at the top below `# Changelog`:

```markdown
## 0.3.0

- OpManager webhook receiver `/plugins/opmanager/front/webhook.php`:
  - OpManager "Invoke a Webhook" POSTs `{"alarm_id":"$alarmid","entity":"$entity"}` with header
    `X-OPM-Token`; the alarm is fetched from the API and processed by the same engine as
    AlarmSync, so tickets appear and are solved within seconds;
  - the 10-minute poll stays as the safety net.
- The token is generated in Setup, stored encrypted, and shown once.
- Cron and webhook share a MySQL lock, so one alarm is never ticketed twice.
- Fix: ticket patterns saved from the Setup textarea (CRLF) are normalised, and the
  0.2.1 description upgrade now also recognises them.
```

- [ ] **Step 3: Runbook**

`docs/runbook-v0.3.0.md`:

````markdown
---
date: 2026-10-01
tags: [opmanager, runbook]
status: active
---

# opmanager v0.3.0 — VM verification runbook

## 1. Install

```bash
cd /data/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.3.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /data/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
```

- [ ] Setup > PGN Suite > OpManager > "Webhook from OpManager": click **Generate new token**,
      and copy the token from the message (it is shown once).
- [ ] `sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php` prints `ALL PASS`
      (including `webhook/stateless`, `webhook/token`, `webhook/lock`).

## 2. Endpoint checks from the VM

```bash
URL=https://comit.pgn.co.id/plugins/opmanager/front/webhook.php
read -rs TOKEN; echo
curl -s -o /dev/null -w "%{http_code}\n" -X POST "$URL" -H 'Content-Type: application/json' -d '{"alarm_id":"1","entity":"1"}'                        # expect 401
curl -s -o /dev/null -w "%{http_code}\n" -X POST "$URL" -H "X-OPM-Token: wrong" -d '{"alarm_id":"1","entity":"1"}'                                 # expect 401
curl -s -w "\n%{http_code}\n" -X POST "$URL" -H "X-OPM-Token: $TOKEN" -d '{"alarm_id":"1","entity":"1"}'                                          # expect 202 (no such alarm)
curl -s -w "\n%{http_code}\n" -X POST "$URL" -H "X-OPM-Token: $TOKEN" -d 'not json'                                                                # expect 400
unset TOKEN
```

- [ ] All four codes match. If the 202 case answers 302/login HTML instead, the stateless
      path is not active: report the selfcheck output.

## 3. OpManager action

- [ ] Edit the disabled "GLPI_Create_Ticket" action (Invoke a Webhook):
  - URL = the plugin URL above, method POST, raw JSON;
  - headers: `Content-Type: application/json` and `X-OPM-Token: <token>` (remove App-Token / User-Token);
  - body: `{"alarm_id":"$alarmid","entity":"$entity"}`.
- [ ] Use it in a Notification Profile for **Critical and Clear** on the devices that matter.
      The plugin still filters on the production ranges.
- [ ] Re-enable the profile. Use "Test" in OpManager, or wait for a real alarm; the plugin log shows
      no `Webhook: HTTP 4xx` lines.

## 4. Real event

- [ ] A new Critical alarm on a production IP gets its ticket **within a minute** (not up to 10).
- [ ] When it clears, the ticket is Solved within a minute (if all its alarms are Clear).
- [ ] Still no duplicate ticket (cron + webhook): one ticket per alarm id.
- [ ] If no alarm happened during the test window, write "case untested in production".
````

- [ ] **Step 4: Full suite, build, verify**

Run: `C:/php/php.exe tests/run_all.php`
Expected: `SUITES OK`.

```bash
git add src/SelfCheck.php setup.php CHANGELOG.md docs/runbook-v0.3.0.md
git commit -m "chore: release 0.3.0 (webhook receiver)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git archive --format=zip --prefix=opmanager/ -o opmanagerv0.3.0.zip HEAD
python tools/release_check.py opmanagerv0.3.0.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins
```

Expected: `RELEASE OK`. The tag `0.3.0` is created after the final review and merge (local only, hub §0 rule 6).

- [ ] **Step 5: Hub**

- In §2 row 7, set "v0.3.0 built, not deployed".
- In §7, add an entry recording two corrections:
  - **stateless plugin endpoints must be registered in `plugin_<key>_boot()`**, not `plugin_init`. This corrects the 2026-09-29 opmanager entry, which named only the API.
  - **in 11.0.5, a stateless request gets `Session::initVars()` like CLI cron**, so a webhook can safely run cron-style code.
