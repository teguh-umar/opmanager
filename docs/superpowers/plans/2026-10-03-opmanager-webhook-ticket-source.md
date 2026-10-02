# Ticket Source = OpManager Webhook Criteria (v1.1.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the OpManager webhook profile decide which alarms become tickets: in the new default mode only a webhook (or a deferred webhook) may create, reopen or restore a ticket, while the poll keeps storing alarms and updating existing tickets.

**Architecture:** One new setting `ticket_source` (`webhook` | `rules`) read by `AlarmSync`. Each alarm is processed with a `requested` flag (true from the webhook receiver, or from a new `ticketrequests` table filled when the receiver defers with 202). The gateway gains `restore()` and `mergedInto()` so a trashed ticket is restored on a webhook and an alarm on a merged ticket follows the merge target. Mode `rules` keeps v1.0.2 behaviour byte for byte.

**Tech Stack:** PHP 8.2, GLPI 11.0.5 core APIs, offline test harness `tests/*_test.php` (`check()` / `done()`), run with `php tests/run_all.php`.

**Spec:** `docs/superpowers/specs/2026-09-29-opmanager-design.md` — D32 and §16 (also D21, D30, D31, §7).

## Global Constraints

- Suite schema rules (hub §4): keys `INT UNSIGNED`; OpManager ids are `VARCHAR *_key`; dates `TIMESTAMP` only, never `DATETIME`.
- HTTP stays on ext-curl; never `GuzzleHttp` (prod telegrambot clash).
- Never write core ticket tables raw (`glpi_tickets`, `glpi_itilfollowups`, `glpi_itilsolutions`); use core classes inside `GlpiTicketGateway::asPlugin()`.
- `ticket_source` default `webhook`, also on upgrade (`Config::setDefaults()` adds missing keys only).
- Mode `rules` = v1.0.2 behaviour; every existing test must pass unchanged.
- Release 1.1.0 is a minor: commit + tag locally only, no push, no GitHub Release (hub §0 rule 6).
- Every production zip is also installed into `C:/Users/teguh.umar/Downloads/glpi/plugins/opmanager/` via `tools/release_check.py --install`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Setup save in webhook mode** must not wipe the severity / filter settings: the inputs stay enabled (a `disabled` input is not posted and `sanitizeTicketing` would store `[]`). Pinned in Task 6.
2. **A deferred webhook whose alarm fails processing** keeps its request for the next run (only a successful run deletes it). Pinned in Task 4.
3. **A webhook Clear on a trashed ticket** must not restore it (restore only for an active alarm). Pinned in Task 4.
4. **A merge chain longer than 5 or a cycle** leaves the alarm alone instead of looping. Pinned in Task 4.
5. **Receiver failing to record a request** (DB error) still answers 202, never 500. Pinned in Task 5.

---

### Task 1: Setting `ticket_source`

**Files:**
- Modify: `src/Config.php` (constants, `DEFAULTS`, `sanitizeTicketing()`, `ticketingConfig()`)
- Test: `tests/config_test.php`

**Interfaces:**
- Produces: `Config::TICKET_SOURCES = ['webhook', 'rules']`; `ticketingConfig()['ticket_source']` is `'webhook'|'rules'`; `sanitizeTicketing()['values']['ticket_source']`.

- [ ] **Step 1: Write the failing test** — append before `done();` in `tests/config_test.php` (`$t` is the valid ticketing POST already defined in that file):

```php
// v1.1.0 (D32): ticket source.
check('ticket source defaults to webhook', Config::DEFAULTS['ticket_source'] === 'webhook' && Config::TICKET_SOURCES[0] === 'webhook');
check('ticket source rules accepted', Config::sanitizeTicketing(['ticket_source' => 'rules'] + $t)['values']['ticket_source'] === 'rules');
check('unknown ticket source falls back to webhook', Config::sanitizeTicketing(['ticket_source' => 'x'] + $t)['values']['ticket_source'] === 'webhook');
```

- [ ] **Step 2: Run it** — `php tests/config_test.php` → FAIL (undefined index / constant).

- [ ] **Step 3: Implement** in `src/Config.php`:

Next to `FILTER_MODES`:
```php
    /** D32: who decides that an alarm becomes a ticket (first = default, also on upgrade). */
    public const TICKET_SOURCES = ['webhook', 'rules'];
```
In `DEFAULTS`, after `'ticket_severities' => '[1]',`:
```php
        'ticket_source'               => 'webhook',
```
In `sanitizeTicketing()`, right after `$errors = [];`:
```php
        $source = (string) ($post['ticket_source'] ?? '');
        $values['ticket_source'] = in_array($source, self::TICKET_SOURCES, true) ? $source : self::TICKET_SOURCES[0];
```
In `ticketingConfig()` add to the returned array (and `ticket_source: string` to its `@return` shape):
```php
            'ticket_source'        => in_array(self::get('ticket_source'), self::TICKET_SOURCES, true) ? self::get('ticket_source') : self::TICKET_SOURCES[0],
```

- [ ] **Step 4: Run** `php tests/config_test.php` → ALL PASS.

- [ ] **Step 5: Commit**
```bash
git add src/Config.php tests/config_test.php
git commit -m "feat: ticket_source setting, default webhook (D32)"
```

---

### Task 2: Ticket request table and store

**Files:**
- Create: `src/Ticketing/TicketRequestStore.php`, `src/Ticketing/DbTicketRequestStore.php`
- Modify: `install/install.php` (new table block after the outbox block), `install/uninstall.php`
- Test: `tests/schema_test.php`

**Interfaces:**
- Produces:
```php
interface TicketRequestStore
{
    /** Upsert by alarm_key; refreshes the time. */
    public function record(string $alarm_key, string $entity_key): void;
    /** @return list<string> alarm keys with an open request */
    public function keys(): array;
    public function delete(string $alarm_key): void;
    /** Deletes requests last recorded before $before ('Y-m-d H:i:s'). */
    public function purgeBefore(string $before): void;
}
```
`DbTicketRequestStore::TABLE = 'glpi_plugin_opmanager_ticketrequests'`.

- [ ] **Step 1: Failing test** — append before `done();` in `tests/schema_test.php`:

```php
// v1.1.0 (D32): deferred webhook requests.
check('install creates glpi_plugin_opmanager_ticketrequests', in_array('glpi_plugin_opmanager_ticketrequests', array_column($tables, 1), true));
check('ticketrequests.alarm_key is UNIQUE', (bool) preg_match('/ticketrequests.*?UNIQUE KEY `alarm_key` \(`alarm_key`\)/s', $src));
check('uninstall drops ticketrequests', str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_opmanager_ticketrequests'));
```

- [ ] **Step 2: Run** `php tests/schema_test.php` → FAIL.

- [ ] **Step 3: Implement**

`install/install.php`, after the outbox `CREATE TABLE` block:
```php
    // v1.1.0 (D32): webhooks answered 202 - the next AlarmSync run treats them as webhook calls.
    $table = 'glpi_plugin_opmanager_ticketrequests';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `alarm_key` VARCHAR(255) NOT NULL DEFAULT '',
            `entity_key` VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `alarm_key` (`alarm_key`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }
```
`install/uninstall.php`: put `'glpi_plugin_opmanager_ticketrequests'` first in the drop list.

`src/Ticketing/TicketRequestStore.php`: the interface above, namespace `GlpiPlugin\Opmanager\Ticketing`, class doc `/** Deferred webhook calls (spec §16.4). */`.

`src/Ticketing/DbTicketRequestStore.php`:
```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** Spec §16.4: one row per alarm whose webhook was answered 202. */
final class DbTicketRequestStore implements TicketRequestStore
{
    public const TABLE = 'glpi_plugin_opmanager_ticketrequests';

    public function record(string $alarm_key, string $entity_key): void
    {
        global $DB;
        $now = (string) \Session::getCurrentTime();
        if ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['alarm_key' => $alarm_key], 'LIMIT' => 1])->count() > 0) {
            $DB->update(self::TABLE, ['entity_key' => $entity_key, 'date_mod' => $now], ['alarm_key' => $alarm_key]);
            return;
        }
        $DB->insert(self::TABLE, ['alarm_key' => $alarm_key, 'entity_key' => $entity_key, 'date_creation' => $now, 'date_mod' => $now]);
    }

    public function keys(): array
    {
        global $DB;
        $keys = [];
        foreach ($DB->request(['SELECT' => ['alarm_key'], 'FROM' => self::TABLE]) as $row) {
            $keys[] = (string) $row['alarm_key'];
        }
        return $keys;
    }

    public function delete(string $alarm_key): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['alarm_key' => $alarm_key]);
    }

    public function purgeBefore(string $before): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['date_mod' => ['<', $before]]);
    }
}
```

- [ ] **Step 4: Run** `php tests/schema_test.php` → ALL PASS (the generic loop also checks `alarm_key`/`entity_key` are VARCHAR, `id` INT UNSIGNED, no DATETIME).

- [ ] **Step 5: Commit**
```bash
git add install src/Ticketing/TicketRequestStore.php src/Ticketing/DbTicketRequestStore.php tests/schema_test.php
git commit -m "feat: ticketrequests table and store for deferred webhooks (D32)"
```

---

### Task 3: Gateway `restore()` and `mergedInto()`

**Files:**
- Modify: `src/Ticketing/TicketGateway.php`, `src/Ticketing/GlpiTicketGateway.php`, `tests/alarmsync_test.php` (fake `Gw` must implement the interface)
- Test: `tests/gateway_static_test.php`

**Interfaces:**
- Produces:
```php
    /** Takes the ticket out of the GLPI trash. */
    public function restore(int $tickets_id): void;

    /** The ticket a merge moved this one into (Ticket_Ticket SON_OF), 0 when not merged. */
    public function mergedInto(int $tickets_id): int;
```

- [ ] **Step 1: Failing test** — append before `require OPM_ROOT . '/src/Ticketing/TicketGateway.php';` in `tests/gateway_static_test.php`:

```php
// v1.1.0 (D32): restore from trash via core, merge target via Ticket_Ticket SON_OF.
check('restore uses core Ticket::restore inside asPlugin', (bool) preg_match('/function restore\(.*?asPlugin.*?->restore\(\[\'id\' => \$tickets_id\]\)/s', $src));
check('mergedInto reads Ticket_Ticket SON_OF', (bool) preg_match("/function mergedInto\\(.*?'tickets_id_1' => \\\$tickets_id, 'link' => \\\\Ticket_Ticket::SON_OF/s", $src));
```

- [ ] **Step 2: Run** `php tests/gateway_static_test.php` → FAIL.

- [ ] **Step 3: Implement**

Add the two signatures above to `TicketGateway`. In `GlpiTicketGateway`, after `link()`:
```php
    public function restore(int $tickets_id): void
    {
        $this->asPlugin(function () use ($tickets_id): void {
            (new \Ticket())->restore(['id' => $tickets_id]);
        });
    }

    public function mergedInto(int $tickets_id): int
    {
        // Ticket::merge() trashes the source and links source (1) SON_OF target (2).
        $rows = (new \Ticket_Ticket())->find(['tickets_id_1' => $tickets_id, 'link' => \Ticket_Ticket::SON_OF], ['id DESC'], 1);
        return $rows === [] ? 0 : (int) reset($rows)['tickets_id_2'];
    }
```
In `tests/alarmsync_test.php`, class `Gw`, add (keeps all existing tests valid):
```php
    public array $merged = []; public array $beforeTrash = [];
    public function restore(int $t): void { $this->status[$t] = $this->beforeTrash[$t] ?? 2; $this->log[] = "restore {$t}"; }
    public function mergedInto(int $t): int { return $this->merged[$t] ?? 0; }
```

- [ ] **Step 4: Run** `php tests/run_all.php` → `SUITES OK`.

- [ ] **Step 5: Commit**
```bash
git add src/Ticketing/TicketGateway.php src/Ticketing/GlpiTicketGateway.php tests/gateway_static_test.php tests/alarmsync_test.php
git commit -m "feat: gateway restore() and mergedInto() (D32)"
```

---

### Task 4: AlarmSync webhook mode, trash restore, merge follow, requests

**Files:**
- Modify: `src/Ticketing/AlarmSync.php`, `src/Ticketing/AlarmStore.php`, `src/Ticketing/DbAlarmStore.php`
- Test: `tests/alarmsync_test.php`

**Interfaces:**
- Consumes: `TicketRequestStore` (Task 2), `TicketGateway::restore()/mergedInto()` (Task 3), `cfg['ticket_source']` (Task 1; missing key = `'rules'` so existing tests stay in rules mode).
- Produces: `AlarmSync::__construct(..., array $cfg, ?TicketRequestStore $requests = null)`; stats key `restored`; `AlarmStore::moveTicket(int $id, int $tickets_id): void`.

- [ ] **Step 1: Failing tests** — in `tests/alarmsync_test.php` add `'Ticketing/TicketRequestStore'` to the `require` list (before `'Ticketing/AlarmSync'`), `use GlpiPlugin\Opmanager\Ticketing\TicketRequestStore;`, add to class `Store`:
```php
    public function moveTicket(int $id, int $t): void { $this->rows[$id]['tickets_id'] = $t; }
```
add a fake after class `Assets`:
```php
final class Req implements TicketRequestStore
{
    public array $at = [];
    public function record(string $k, string $e): void { $this->at[$k] = '2026-09-30 10:00:00'; }
    public function keys(): array { return array_map('strval', array_keys($this->at)); }
    public function delete(string $k): void { unset($this->at[$k]); }
    public function purgeBefore(string $b): void { $this->at = array_filter($this->at, fn ($t) => $t >= $b); }
}
```
then append before the final `done();`:
```php
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
```

Note on the "kept on failure" test: with `throwOn = [100]`, `r3` creates ticket 100 (create never throws), `r4` on the same device joins 100 and its followup throws, so `r4` fails and its request must stay.

- [ ] **Step 2: Run** `php tests/alarmsync_test.php` → FAIL (constructor arity / missing behaviour).

- [ ] **Step 3: Implement**

`src/Ticketing/AlarmStore.php`, add:
```php
    /** The alarm's ticket was merged away: point it at the merge target (D32). */
    public function moveTicket(int $id, int $tickets_id): void;
```
`src/Ticketing/DbAlarmStore.php`, after `setTicket()`:
```php
    public function moveTicket(int $id, int $tickets_id): void
    {
        global $DB;
        $DB->update(Alarm::getTable(), ['tickets_id' => $tickets_id], ['id' => $id]);
    }
```

`src/Ticketing/AlarmSync.php`:

Constants next to `CLOSED_STATUS`:
```php
    /** Deferred webhook requests older than this are dropped (spec §16.4). */
    private const REQUEST_TTL_HOURS = 24;

    /** Merge chains are followed at most this far (spec §16.5). */
    private const MERGE_HOPS = 5;
```
Constructor: add a last parameter `private ?TicketRequestStore $requests = null,` and `ticket_source?: string` to the `@param` shape.

Both `array_fill_keys([...])` lists (in `run()` and `processOne()`): add `'restored'` after `'reopened'`.

`run()` becomes:
```php
    public function run(string $now): array
    {
        $active = $this->source->listActiveAlarms();

        $this->errors = [];
        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'restored', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked', 'errors'], 0);
        $stats['fetched'] = count($active);

        // D32: webhooks answered 202 are processed now as if the webhook had come through.
        $requested = array_fill_keys($this->requests?->keys() ?? [], true);

        $seen = [];
        foreach ($active as $alarm) {
            $seen[]  = $alarm['alarm_key'];
            $is_asked = isset($requested[$alarm['alarm_key']]);
            if ($this->guarded($alarm, $now, $stats, $is_asked) && $is_asked) {
                $this->requests->delete($alarm['alarm_key']);
            }
        }

        foreach ($this->store->activeNotSeen($seen) as $row) {
            try {
                $current = $this->source->alarmByEntity($row['entity_key']);
            } catch (ClientException) {
                continue;
            }
            if ($current !== null && $current['alarm_key'] === $row['alarm_key'] && (int) $current['severity'] === self::CLEAR) {
                $this->guarded($current, $now, $stats, false);
            }
        }

        $this->requests?->purgeBefore($this->since($now, self::REQUEST_TTL_HOURS * 3600));

        return $stats;
    }
```
`processOne()`: `$this->guarded($alarm, $now, $stats, true);` (and `'restored'` in its stats list).

`guarded()`:
```php
    /** @return bool true when the alarm was processed without error */
    private function guarded(array $alarm, string $now, array &$stats, bool $requested): bool
    {
        try {
            $this->process($alarm, $now, $stats, $requested);
            return true;
        } catch (\Throwable $e) {
            $stats['errors']++;
            $this->errors[] = sprintf('alarm %s: %s', $alarm['alarm_key'], $e->getMessage());
            return false;
        }
    }
```
Start of `process()` (replace from the signature down to the `$has_ticket = ...` line):
```php
    /** @param bool $requested the webhook asked for this alarm (directly or deferred, spec §16.2) */
    private function process(array $alarm, string $now, array &$stats, bool $requested): void
    {
        $by_webhook = ($this->cfg['ticket_source'] ?? 'rules') === 'webhook';
        $is_clear   = (int) $alarm['severity'] === self::CLEAR;

        $existing = $this->store->find($alarm['alarm_key']);
        $status   = null;
        if ($existing !== null && $existing['tickets_id'] > 0) {
            // §16.5: a merged-away ticket hands its alarms to the merge target.
            $target = $this->followMerge($existing['tickets_id']);
            if ($target !== $existing['tickets_id']) {
                $this->store->moveTicket($existing['id'], $target);
                $existing['tickets_id'] = $target;
            }
            $status = $this->tickets->status($existing['tickets_id']);
            // §16.3: only a webhook for the active alarm brings a trashed ticket back; it is
            // then judged as a recurrence (Solved -> reopen, Closed -> D30, open -> followup).
            if ($status === TicketGateway::TRASHED && $by_webhook && $requested && !$is_clear
                && $this->tickets->mergedInto($existing['tickets_id']) === 0) {
                $this->tickets->restore($existing['tickets_id']);
                $stats['restored']++;
                $status            = $this->tickets->status($existing['tickets_id']);
                $existing['state'] = 'cleared';
            }
            if ($status === null) {
                // Ticket purged from GLPI: treat the alarm as ticketless again.
                // A trashed/merged ticket (TRASHED) stays bound - Decision leaves it alone.
                $existing['tickets_id'] = 0;
            }
        }

        // D30 (v1.0.1): the same alarm id active again after its ticket was Closed is a new
        // alarm: severity, production and filter apply again, and a new ticket is linked
        // to the closed one. Before, the closed ticket was reopened on every flap.
        $reborn_from = 0;
        if ($existing !== null && $existing['tickets_id'] > 0 && $status === self::CLOSED_STATUS
            && !$is_clear && $existing['state'] === 'cleared') {
            $reborn_from            = $existing['tickets_id'];
            $existing['tickets_id'] = 0;
        }

        $has_ticket = $existing !== null && $existing['tickets_id'] > 0;
```
(The old `$is_clear = ...` line further down is removed; it now sits at the top.)

Replace the `$ticketable = ...` statement:
```php
        $asset = $this->assets->resolve($alarm);
        // D32: in webhook mode OpManager's webhook criteria pick the alarms; the plugin only
        // checks the production ranges (D21). Mode rules: severities + filter + production.
        $ticketable = $by_webhook
            ? ($has_ticket || $is_clear || ($requested && $this->production->isProduction($alarm, $asset)))
            : in_array((int) $alarm['severity'], $this->cfg['ticket_severities'], true)
                && ($has_ticket || $is_clear || ($this->filter->allows($alarm) && $this->production->isProduction($alarm, $asset)));
```
and in the `Decision::decide([...])` array:
```php
            'reopen_allowed'       => $by_webhook ? $requested : $this->filter->allows($alarm),
```
Add the helper next to `openTicketFor()`:
```php
    /** The live end of a merge chain, or $tid itself when the chain is too long or loops. */
    private function followMerge(int $tid): int
    {
        $current = $tid;
        for ($hop = 0; $hop <= self::MERGE_HOPS; $hop++) {
            if ($this->tickets->status($current) !== TicketGateway::TRASHED) {
                return $current;
            }
            $next = $this->tickets->mergedInto($current);
            if ($next <= 0) {
                return $current;
            }
            $current = $next;
        }
        return $tid;
    }
```
Check: chain 100→…→106 needs 6 hops: hops 0..5 move 100→106 and the loop ends with `$current = 106` unchecked → returns `$tid` (100). The cycle 100↔101 never reaches a live ticket → returns 100. A single merge 100→200 returns 200 at hop 1.

- [ ] **Step 4: Run** `php tests/run_all.php` → `SUITES OK` (all v1.0.2 tests unchanged, new tests pass).

- [ ] **Step 5: Commit**
```bash
git add src/Ticketing/AlarmSync.php src/Ticketing/AlarmStore.php src/Ticketing/DbAlarmStore.php tests/alarmsync_test.php
git commit -m "feat: webhook ticket source, trash restore, merge follow, deferred requests (D32)"
```

---

### Task 5: Receiver records deferrals; wire cron and webhook

**Files:**
- Modify: `src/Webhook/Receiver.php`, `front/webhook.php`, `src/Cron.php`
- Test: `tests/receiver_test.php`, `tests/webhook_wiring_test.php`

**Interfaces:**
- Consumes: `TicketRequestStore`, `DbTicketRequestStore` (Task 2); `AlarmSync` last ctor param (Task 4); `ticketingConfig()['ticket_source']` (Task 1).
- Produces: `Receiver::__construct(string $token, AlarmSource $source, SyncLock $lock, callable $runner, ?TicketRequestStore $requests = null)`.

- [ ] **Step 1: Failing tests**

`tests/receiver_test.php`: add `'Ticketing/TicketRequestStore'` to the `require` list, `use GlpiPlugin\Opmanager\Ticketing\TicketRequestStore;`, a fake after class `Lock`:
```php
final class Req implements TicketRequestStore
{
    public array $rec = []; public bool $boom = false;
    public function record(string $k, string $e): void { if ($this->boom) { throw new RuntimeException('db down'); } $this->rec[] = "{$k}@{$e}"; }
    public function keys(): array { return []; }
    public function delete(string $k): void {}
    public function purgeBefore(string $b): void {}
}
```
and before `done();`:
```php
// v1.1.0 (D32): every 202 leaves a request for the next AlarmSync run; 200/4xx do not.
$req = new Req();
$mkr = static fn ($answer) => new Receiver('secret-token', new Src($answer), new Lock(), $runner, $req);
$mkr(null)->handle('POST', 'secret-token', $body);
$mkr(new ClientException('down'))->handle('POST', 'secret-token', $body);
$busy = new Lock(); $busy->free = false;
(new Receiver('secret-token', new Src($alarm), $busy, $runner, $req))->handle('POST', 'secret-token', $body);
check('each 202 records a request', $req->rec === array_fill(0, 3, '10000003244@10000024830'));
$req->rec = [];
$mkr($alarm)->handle('POST', 'secret-token', $body);
$mkr($alarm)->handle('POST', 'nope', $body);
check('200 and 401 record nothing', $req->rec === []);
$req->boom = true;
check('a failing request store still answers 202', $mkr(null)->handle('POST', 'secret-token', $body)['status'] === 202);
```
`tests/webhook_wiring_test.php`, before `done();` (the file already holds `front/webhook.php` in `$hook`):
```php
$cron = (string) file_get_contents(OPM_ROOT . '/src/Cron.php');
check('webhook passes the request store to the receiver', str_contains($hook, 'new DbTicketRequestStore()'));
check('cron AlarmSync consumes deferred requests', str_contains($cron, 'new DbTicketRequestStore()'));
```

- [ ] **Step 2: Run** `php tests/receiver_test.php; php tests/webhook_wiring_test.php` → FAIL.

- [ ] **Step 3: Implement**

`src/Webhook/Receiver.php`: `use GlpiPlugin\Opmanager\Ticketing\TicketRequestStore;`; constructor gets `private ?TicketRequestStore $requests = null` as last parameter (after `callable $runner`). Add:
```php
    /**
     * D32: a deferred webhook is still a webhook - the next AlarmSync run handles it as one.
     * A failing store must not turn the 202 into a 500: the alarm is still polled.
     */
    private function defer(string $message, string $alarm_id, string $entity): array
    {
        try {
            $this->requests?->record($alarm_id, $entity);
        } catch (\Throwable) {
        }
        return self::reply(202, $message, $alarm_id);
    }
```
Replace the three `return self::reply(202, ...)` calls with `return $this->defer(<same message>, $alarm_id, $entity);`.

`front/webhook.php`: `use GlpiPlugin\Opmanager\Ticketing\DbTicketRequestStore;`; pass `new DbTicketRequestStore()` as 5th `Receiver` argument; inside the runner the `new AlarmSync(...)` keeps 6 arguments (processOne is always "requested").

`src/Cron.php`: `use GlpiPlugin\Opmanager\Ticketing\DbTicketRequestStore;`; in `cronAlarmSync` pass `new DbTicketRequestStore()` as 7th `AlarmSync` argument; change the warning condition to
```php
        if ($ranges === [] && ($cfg['ticket_source'] === 'webhook' || $cfg['ticket_severities'] !== [])) {
```

- [ ] **Step 4: Run** `php tests/run_all.php` → `SUITES OK`.

- [ ] **Step 5: Commit**
```bash
git add src/Webhook/Receiver.php front/webhook.php src/Cron.php tests/receiver_test.php tests/webhook_wiring_test.php
git commit -m "feat: deferred webhooks recorded and consumed by AlarmSync (D32)"
```

---

### Task 6: Setup — ticket source select, hints, status badge

**Files:**
- Modify: `src/SuiteConfig.php` (`tabTickets()`, `showAlarmFilter()`, `statusStrip()`), `src/SetupStatus.php`
- Create: `tests/ticketsource_wiring_test.php` (static source checks; `tests/suiteconfig_test.php` is a render test with stubs, leave it alone)
- Test: `tests/setupstatus_test.php`

**Interfaces:**
- Consumes: `Config::TICKET_SOURCES`, `Config::get('ticket_source')` (Task 1).
- Produces: `SetupStatus::items()` accepts optional `ticket_source` (default `'rules'`).

- [ ] **Step 1: Failing tests**

`tests/setupstatus_test.php`, before `done();`:
```php
// v1.1.0 (D32): without a token the webhook mode creates no ticket at all.
check('webhook mode without token is an error', $lv(SetupStatus::items(['webhook_token_set' => false, 'ticket_source' => 'webhook'] + $good, $now))['webhook'] === 'error');
check('rules mode without token stays a warning', $lv(SetupStatus::items(['webhook_token_set' => false, 'ticket_source' => 'rules'] + $good, $now))['webhook'] === 'warn');
```
New file `tests/ticketsource_wiring_test.php`:
```php
<?php

require __DIR__ . '/bootstrap.php';

// v1.1.0 (D32): Setup wiring of the ticket source, checked on the source text.
$src = (string) file_get_contents(OPM_ROOT . '/src/SuiteConfig.php');
check('Setup offers the ticket source', str_contains($src, 'name="ticket_source"') && str_contains($src, 'Config::TICKET_SOURCES'));
check('status strip receives the ticket source', (bool) preg_match("/function statusStrip\\(.*?'ticket_source' => Config::get\\('ticket_source'\\)/s", $src));
// Review Focus 1: a disabled input is not posted, so Save would wipe severities/filter.
check('ticket source never disables severity or filter inputs', !preg_match("/'webhook'\\s*\\?\\s*' disabled'/", $src) && !str_contains($src, '$by_webhook ? \' disabled\''));
check('webhook mode shows the "Plugin rules only" hint', substr_count($src, 'Used only when the ticket source is') >= 2);

done();
```

- [ ] **Step 2: Run** `php tests/setupstatus_test.php; php tests/ticketsource_wiring_test.php` → FAIL.

- [ ] **Step 3: Implement**

`src/SetupStatus.php`, webhook item:
```php
            self::webhook($f['webhook_token_set'], (string) ($f['ticket_source'] ?? 'rules')),
```
and
```php
    /** D32: in webhook mode a missing token means no new ticket at all. */
    private static function webhook(bool $token_set, string $source): array
    {
        if ($token_set) {
            return ['key' => 'webhook', 'label' => __('Webhook', 'opmanager'), 'level' => 'ok', 'text' => __('token stored', 'opmanager')];
        }
        return $source === 'webhook'
            ? ['key' => 'webhook', 'label' => __('Webhook', 'opmanager'), 'level' => 'error', 'text' => __('no token - no new tickets', 'opmanager')]
            : ['key' => 'webhook', 'label' => __('Webhook', 'opmanager'), 'level' => 'warn', 'text' => __('no token - disabled', 'opmanager')];
    }
```
Add `ticket_source?: string` to the `@param` shape.

`src/SuiteConfig.php`:
- `statusStrip()`: add `'ticket_source' => Config::get('ticket_source'),` to the array.
- Top of `tabTickets()`:
```php
        $by_webhook = Config::get('ticket_source') === 'webhook';
        echo '<div class="mb-3"><label class="form-label" for="opm-source' . $sfx . '">' . __s('Ticket source', 'opmanager') . '</label>';
        echo '<select class="form-select" id="opm-source' . $sfx . '" name="ticket_source"' . $disabled . '>';
        $labels = [
            'webhook' => __('OpManager webhook criteria: only alarms sent by the webhook profile get a ticket', 'opmanager'),
            'rules'   => __('Plugin rules: severities and alarm filter below, from every poll', 'opmanager'),
        ];
        foreach (Config::TICKET_SOURCES as $source) {
            echo '<option value="' . htmlescape($source) . '"' . (Config::get('ticket_source') === $source ? ' selected' : '') . '>' . htmlescape($labels[$source]) . '</option>';
        }
        echo '</select></div>';
```
- Right after the severities checkbox loop in `tabTickets()`, and at the top of `showAlarmFilter()`:
```php
        if (Config::get('ticket_source') === 'webhook') {
            echo '<div class="form-hint">' . __s('Used only when the ticket source is "Plugin rules". Kept when saved.', 'opmanager') . '</div>';
        }
```
(in `tabTickets()` reuse `$by_webhook` instead of calling `Config::get` again). The `$disabled` variable is the existing "no edit right" flag only; the source never disables inputs.

- [ ] **Step 4: Run** `php tests/run_all.php` → `SUITES OK`.

- [ ] **Step 5: Commit**
```bash
git add src/SuiteConfig.php src/SetupStatus.php tests/setupstatus_test.php tests/ticketsource_wiring_test.php
git commit -m "feat: Setup ticket source select and webhook status (D32)"
```

---

### Task 7: Release 1.1.0

**Files:**
- Modify: `setup.php` (version), `CHANGELOG.md`, `docs/superpowers/specs/2026-09-29-opmanager-design.md` (§16.1 wording)

- [ ] **Step 1: Spec wording** — in §16.1 replace "tampil nonaktif dengan catatan" with "tetap bisa diedit dan diberi catatan" (inputs stay enabled so Save keeps their values; see Review Focus 1).

- [ ] **Step 2: Version + changelog** — `setup.php`: `define('PLUGIN_OPMANAGER_VERSION', '1.1.0');`. `CHANGELOG.md`, new top section:
```markdown
## 1.1.0

- New Setup option **Ticket source** (tab Tickets), default **OpManager webhook criteria** (D32):
  - only alarms that the OpManager "Invoke a Webhook" profile sends get a ticket, if their
    IP is in a production range; the severities and the alarm filter are not used;
  - a Solved ticket is reopened, and a ticket in the trash is restored, only when the
    webhook sends that alarm again; the poll alone never creates, reopens or restores;
  - the poll still stores every alarm and keeps followups, Clear and Solve of existing tickets;
  - a webhook answered 202 (sync busy, API down) is remembered and handled by the next poll.
- **Plugin rules** keeps the 1.0.2 behaviour.
- An alarm whose ticket was merged now follows the ticket it was merged into.
- Setup: the Webhook badge is red when the webhook mode has no token (no ticket would be created).
- Upgrade adds the table `glpi_plugin_opmanager_ticketrequests`. Check in OpManager that the
  webhook profile has the criteria you want (e.g. Device Down, Critical) and "Notify when the
  alarm is cleared".
```

- [ ] **Step 3: Full suite** — `php tests/run_all.php` → `SUITES OK`.

- [ ] **Step 4: Commit, tag, zip, verify, install locally**
```bash
git add setup.php CHANGELOG.md docs/superpowers/specs/2026-09-29-opmanager-design.md
git commit -m "chore: release 1.1.0 (ticket source = OpManager webhook criteria, D32)"
git tag 1.1.0
git archive --prefix=opmanager/ -o opmanagerv1.1.0.zip 1.1.0
python tools/release_check.py opmanagerv1.1.0.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins
```
Expected: `RELEASE OK`, `setup.php version: 1.1.0`. No push (minor release).
