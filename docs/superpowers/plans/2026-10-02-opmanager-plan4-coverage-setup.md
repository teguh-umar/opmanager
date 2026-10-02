# opmanager Plan 4 — Coverage page and Setup redesign (v0.6.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Coverage page and native GLPI dashboard cards (OpManager devices vs GLPI assets, with five follow-up lists and CSV export), and restyle the OpManager Setup section as a status strip plus tabs.

**Architecture:**
- Pure classes hold everything that is decided:
  - `SetupStatus` (badges);
  - `Coverage\Report` (KPIs and the five lists from plain arrays);
  - `Coverage\View` (HTML from a report);
  - `Coverage\Csv`.
- Thin GLPI glue:
  - `Coverage\DbCoverageSource` reads the devices and the in-scope assets;
  - `front/coverage.php` wires source → report → view/CSV;
  - `SuiteConfig` renders tabs around the existing fields.

**Tech Stack:** GLPI 11.0.5 plugin (PHP 8.2+), Tabler/Bootstrap 5 classes already loaded by GLPI, offline tests `C:/php/php.exe tests/run_all.php`.

**Spec:** `docs/superpowers/specs/2026-09-29-opmanager-design.md` — D28, D29, §15.

## Global Constraints

- Form field names in Setup do not change, so `front/config.php` keeps working. The only new field is `coverage_states[]`.
- The shared `lib/pgnsuite/*` files are not touched (byte-identical rule, `suite/lib-shas`).
- Every value shown is escaped with `htmlescape`. Every right is checked: Coverage needs `plugin_opmanager_monitoring` READ.
- GLPI assets are limited to the user's active entities. Device-only lists (not in GLPI, ambiguous, stale) are shown to every holder of the monitoring right.
- CSV is `;`-separated UTF-8 with a BOM. A cell starting with `= + - @` is prefixed with `'` (formula injection).
- Release: 0.6.0 is a commit plus a local tag only, with no push.

## Review Focus

1. **A large GLPI** (thousands of assets): the IP lookup is one batched query per itemtype, not one per asset. Pinned in Task 4 (`ips fetched in one query per itemtype`).
2. **An asset with several IPs, one of them in a production range:** it counts as production. Pinned in Task 3 (`any IP in range makes the asset production`).
3. **A user restricted to one entity:** the not-monitored list never shows other entities' assets. Pinned in Task 4 (`entity restriction applied`).
4. **CSV opened in Excel:** a name like `=HYPERLINK(...)` must not execute. Pinned in Task 3 (`formula cells neutralised`).
5. **A read-only Setup user:** tabs still render, fields are disabled, and there is no Save button. Pinned in Task 2 (existing checks plus `tabs render for read-only`).

---

### Task 1: Setup status badges and the coverage states setting

**Files:**
- Create: `src/SetupStatus.php`, `tests/setupstatus_test.php`
- Modify: `src/Config.php`, `tests/config_test.php`

**Interfaces:**
- Produces:
  - `SetupStatus::items(array $f, string $now): list<array{key:string, label:string, level:'ok'|'warn'|'error', text:string}>`. Facts in `$f`:
    - `api_key_set` (bool);
    - `last_device_sync` and `last_device_sync_error` (string);
    - `stale_minutes` (int);
    - `last_alarm_sync` and `last_alarm_sync_error` (string);
    - `alarm_minutes` (int);
    - `webhook_token_set` (bool);
    - `outbox_pending` and `outbox_failed` (int).
  - `Config::DEFAULTS['coverage_states'] = '[]'`.
  - `Config::sanitize()` stores `coverage_states` as a JSON list of positive ints.
  - `Config::coverageStates(): list<int>`.

- [ ] **Step 1: Failing tests** — `tests/setupstatus_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/SetupStatus.php';

use GlpiPlugin\Opmanager\SetupStatus;

$now  = '2026-10-02 12:00:00';
$good = ['api_key_set' => true, 'last_device_sync' => '2026-10-02 11:30:00', 'last_device_sync_error' => '', 'stale_minutes' => 90,
         'last_alarm_sync' => '2026-10-02 11:55:00', 'last_alarm_sync_error' => '', 'alarm_minutes' => 10,
         'webhook_token_set' => true, 'outbox_pending' => 0, 'outbox_failed' => 0];
$lv = static fn (array $items) => array_column($items, 'level', 'key');

check('all good: five ok badges', $lv(SetupStatus::items($good, $now)) === ['api' => 'ok', 'devices' => 'ok', 'alarms' => 'ok', 'webhook' => 'ok', 'outbox' => 'ok']);
check('no api key is an error', $lv(SetupStatus::items(['api_key_set' => false] + $good, $now))['api'] === 'error');
check('device sync error wins', $lv(SetupStatus::items(['last_device_sync_error' => 'boom'] + $good, $now))['devices'] === 'error');
check('device data older than the stale threshold warns', $lv(SetupStatus::items(['last_device_sync' => '2026-10-02 10:00:00'] + $good, $now))['devices'] === 'warn');
check('never synced warns', $lv(SetupStatus::items(['last_alarm_sync' => ''] + $good, $now))['alarms'] === 'warn');
check('alarm sync older than 3 intervals warns', $lv(SetupStatus::items(['last_alarm_sync' => '2026-10-02 11:25:00'] + $good, $now))['alarms'] === 'warn');
check('alarm sync error', $lv(SetupStatus::items(['last_alarm_sync_error' => 'x'] + $good, $now))['alarms'] === 'error');
check('no webhook token warns', $lv(SetupStatus::items(['webhook_token_set' => false] + $good, $now))['webhook'] === 'warn');
check('pending outbox warns, failed is an error', $lv(SetupStatus::items(['outbox_pending' => 2] + $good, $now))['outbox'] === 'warn'
    && $lv(SetupStatus::items(['outbox_failed' => 1, 'outbox_pending' => 2] + $good, $now))['outbox'] === 'error');
check('texts never empty', !in_array('', array_column(SetupStatus::items($good, $now), 'text'), true));

done();
```

Append to `tests/config_test.php` before the last `done();`:

```php
// Plan 4: GLPI states counted by the Coverage page (empty = all)
check('coverage states default to all', Config::DEFAULTS['coverage_states'] === '[]');
$r = Config::sanitize(['coverage_states' => ['3', 'x', '-1', '7', '3']] + $base, $allowed);
check('coverage states stored as unique positive ids', json_decode($r['values']['coverage_states'], true) === [3, 7]);
$r = Config::sanitize($base, $allowed);
check('no coverage state posted = all', $r['values']['coverage_states'] === '[]');
```

- [ ] **Step 2: Run them** — `C:/php/php.exe tests/setupstatus_test.php; C:/php/php.exe tests/config_test.php`. Expected: a fatal error on the missing `SetupStatus.php`, and `FAIL` on the three coverage checks.

- [ ] **Step 3: Implement** `src/SetupStatus.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

/** Pure: the health badges at the top of the Setup section (spec §15.2). */
final class SetupStatus
{
    /**
     * @param array{api_key_set: bool, last_device_sync: string, last_device_sync_error: string, stale_minutes: int,
     *   last_alarm_sync: string, last_alarm_sync_error: string, alarm_minutes: int, webhook_token_set: bool,
     *   outbox_pending: int, outbox_failed: int} $f
     * @return list<array{key: string, label: string, level: string, text: string}>
     */
    public static function items(array $f, string $now): array
    {
        $age = static function (string $when) use ($now): ?int {
            return $when === '' ? null : intdiv(max(0, strtotime($now) - strtotime($when)), 60);
        };
        $sync = static function (string $key, string $label, string $last, string $error, int $limit) use ($age): array {
            $mins = $age($last);
            if ($error !== '') {
                return ['key' => $key, 'label' => $label, 'level' => 'error', 'text' => $error];
            }
            if ($mins === null) {
                return ['key' => $key, 'label' => $label, 'level' => 'warn', 'text' => __('never ran', 'opmanager')];
            }
            return ['key' => $key, 'label' => $label, 'level' => $mins > $limit ? 'warn' : 'ok', 'text' => sprintf(__('%d min ago', 'opmanager'), $mins)];
        };

        $outbox = $f['outbox_failed'] > 0 ? 'error' : ($f['outbox_pending'] > 0 ? 'warn' : 'ok');
        return [
            ['key' => 'api', 'label' => __('API key', 'opmanager'), 'level' => $f['api_key_set'] ? 'ok' : 'error',
             'text' => $f['api_key_set'] ? __('stored', 'opmanager') : __('not set', 'opmanager')],
            $sync('devices', __('Device sync', 'opmanager'), $f['last_device_sync'], $f['last_device_sync_error'], $f['stale_minutes']),
            $sync('alarms', __('Alarm sync', 'opmanager'), $f['last_alarm_sync'], $f['last_alarm_sync_error'], 3 * $f['alarm_minutes']),
            ['key' => 'webhook', 'label' => __('Webhook', 'opmanager'), 'level' => $f['webhook_token_set'] ? 'ok' : 'warn',
             'text' => $f['webhook_token_set'] ? __('token stored', 'opmanager') : __('no token - disabled', 'opmanager')],
            ['key' => 'outbox', 'label' => __('To OpManager', 'opmanager'), 'level' => $outbox,
             'text' => sprintf(__('%d waiting, %d given up', 'opmanager'), $f['outbox_pending'], $f['outbox_failed'])],
        ];
    }
}
```

In `src/Config.php`:
- add `'coverage_states' => '[]',` to `DEFAULTS` after `stale_warning_minutes`;
- in `sanitize()`, before `return`, add:

```php
        $states = array_values(array_unique(array_filter(array_map('intval', (array) ($post['coverage_states'] ?? [])), static fn (int $id) => $id > 0)));
        $values['coverage_states'] = (string) json_encode($states);
```

- add:

```php
    /** @return list<int> GLPI states counted by the Coverage page; [] = every state (D28). */
    public static function coverageStates(): array
    {
        return array_values(array_map('intval', (array) (json_decode(self::get('coverage_states'), true) ?: [])));
    }
```

- [ ] **Step 4: Run** — `C:/php/php.exe tests/setupstatus_test.php && C:/php/php.exe tests/config_test.php && C:/php/php.exe tests/run_all.php`. Expected: `ALL PASS (10 passed, 0 failed)`, then `ALL PASS`, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: setup status badges and coverage states setting`

---

### Task 2: Setup as status strip plus tabs

**Files:**
- Modify: `src/SuiteConfig.php`, `tests/suiteconfig_test.php`

**Interfaces:**
- Consumes: `SetupStatus::items`, `DbOutboxStore::summary`, `Config::coverageStates`.
- Produces: `SuiteConfig::TABS = ['connection','matching','tickets','filter','webhook','outbound']`. Tab pane ids are `opm-tab-<key><sfx>`.

- [ ] **Step 1: Failing tests** — in `tests/suiteconfig_test.php`:
- add stubs to the first `namespace {}` block:

```php
    if (!method_exists('Html', 'scriptBlock')) {}
```

  Instead of that conditional, add the method `public static function scriptBlock($s) { return "<script>{$s}</script>"; }` to the existing `class Html` stub, and add `class State { public static function dropdown($o = []) { echo "<select name='{$o['name']}' multiple></select>"; } }`;
- add `require OPM_ROOT . '/src/SetupStatus.php';` next to the other requires;
- in the editor block, add:

```php
    // Plan 4: status strip + tabs (D29)
    foreach (\GlpiPlugin\Opmanager\SuiteConfig::TABS as $tab) {
        check("tab {$tab} has a nav link and a pane", str_contains($html, 'href="#opm-tab-' . $tab . '"') && str_contains($html, 'id="opm-tab-' . $tab . '"'));
    }
    check('status strip shown above the tabs', strpos($html, 'opm-status') !== false && strpos($html, 'opm-status') < strpos($html, 'nav-tabs'));
    check('one save button for all tabs', substr_count($html, "name='update'") === 1);
    check('coverage states picker in the matching tab', (bool) preg_match('/id="opm-tab-matching".*?coverage_states\[\].*?id="opm-tab-tickets"/s', $html));
    check('last open tab remembered per browser', str_contains($html, 'sessionStorage'));
```

  In the read-only block (the first render), add:

```php
    check('tabs render for read-only', str_contains($html, 'nav-tabs') && str_contains($html, 'id="opm-tab-tickets"'));
```

- [ ] **Step 2: Run** — `C:/php/php.exe tests/suiteconfig_test.php`. Expected: `FAIL` on the new checks only.

- [ ] **Step 3: Implement** in `src/SuiteConfig.php`:
- add `public const TABS = ['connection', 'matching', 'tickets', 'filter', 'webhook', 'outbound'];`
- split the body of `showForm` into six private static methods, each echoing exactly the fields it renders today:
  - `tabConnection`: URL, key, SSL;
  - `tabMatching`: mode, itemtypes, stale minutes, plus the new coverage states;
  - `tabTickets`: severities, production ranges, template, service user, entity, ipnetwork, patterns, relink, storm, and the last alarm sync lines;
  - `tabFilter`: `showAlarmFilter`;
  - `tabWebhook`: the webhook block;
  - `tabOutbound`: retries and the queue summary.

  Each takes `(string $sfx, string $disabled, bool $canedit)`. Remove the `<fieldset>`/`<legend>` wrappers; the tab title replaces them.
- in `showForm`, after the hidden field, echo:
  - the status strip;
  - `<ul class="nav nav-tabs" role="tablist">` with one `<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#opm-tab-<key><sfx>">Label</a></li>` per tab (the first is `active`);
  - `<div class="tab-content border border-top-0 p-3">` holding one `<div class="tab-pane" id="opm-tab-<key><sfx>">` per tab (the first is `active show`).

  The Save button stays after the tab content, so it is a single button.

Tab labels are `__('Connection')`, `__('Matching')`, `__('Tickets')`, `__('Alarm filter')`, `__('Webhook')` and `__('To OpManager')`, all in the `opmanager` domain.

Status strip:

```php
        $box   = (new \GlpiPlugin\Opmanager\Outbound\DbOutboxStore())->summary();
        $items = SetupStatus::items([
            'api_key_set'            => Config::get('opm_api_key') !== '',
            'last_device_sync'       => Config::get('last_device_sync'),
            'last_device_sync_error' => Config::get('last_device_sync_error'),
            'stale_minutes'          => max(1, (int) Config::get('stale_warning_minutes')),
            'last_alarm_sync'        => Config::get('last_alarm_sync'),
            'last_alarm_sync_error'  => Config::get('last_alarm_sync_error'),
            'alarm_minutes'          => Config::ALARM_SYNC_MINUTES,
            'webhook_token_set'      => Config::get('webhook_token') !== '',
            'outbox_pending'         => $box['pending'],
            'outbox_failed'          => $box['failed'],
        ], (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')));
        $color = ['ok' => 'success', 'warn' => 'warning', 'error' => 'danger'];
        echo '<div class="opm-status d-flex flex-wrap gap-2 mb-3">';
        foreach ($items as $it) {
            echo '<span class="badge bg-' . $color[$it['level']] . '-lt" title="' . htmlescape($it['text']) . '">'
                . htmlescape($it['label']) . ': ' . htmlescape(mb_strimwidth($it['text'], 0, 60, '…')) . '</span>';
        }
        echo '</div>';
```

PHP without mbstring fails on `mb_strimwidth`, and the offline tests run without it. Use `strlen($t) > 60 ? substr($t, 0, 57) . '...' : $t` instead.

Coverage states picker, in `tabMatching`:

```php
        echo '<div class="mt-2"><label class="form-label">' . __s('Coverage page: GLPI statuses counted as in use (empty = all)', 'opmanager') . '</label>';
        \State::dropdown(['name' => 'coverage_states[]', 'multiple' => true, 'values' => Config::coverageStates(), 'width' => '100%', 'readonly' => !$canedit]);
        echo '</div>';
```

Tab memory, at the end of the form:

```php
        echo \Html::scriptBlock("(function(){var k='opm-setup-tab';try{var h=sessionStorage.getItem(k);if(h){var a=document.querySelector('a[href=\"'+h+'\"]');if(a&&window.bootstrap){bootstrap.Tab.getOrCreateInstance(a).show();}}}catch(e){}document.querySelectorAll('a[href^=\"#opm-tab-\"]').forEach(function(a){a.addEventListener('shown.bs.tab',function(){try{sessionStorage.setItem(k,a.getAttribute('href'));}catch(e){}});});})();");
```

- [ ] **Step 4: Run** — `C:/php/php.exe tests/suiteconfig_test.php && C:/php/php.exe -l src/SuiteConfig.php && C:/php/php.exe tests/run_all.php`. Expected: `ALL PASS`, no syntax errors, then `SUITES OK`. All earlier suiteconfig checks still pass, because the field names are unchanged.

- [ ] **Step 5: Commit** — `feat: OpManager setup as status strip plus tabs`

---

### Task 3: Coverage report (pure)

**Files:**
- Create: `src/Coverage/Report.php`, `src/Coverage/Csv.php`, `tests/coverage_report_test.php`

**Interfaces:**
- Consumes: `RangeProductionCheck`-style ranges `list<array{id,begin,end}>` (`ip2long` ints) from `AddressingRanges::load`.
- Produces:
  - `Report::build(array $devices, array $assets, array $opt): array`, where
    - `$devices` is `list<{id, device_key, name, display_name, ip, category, type, itemtype, items_id, match_state, match_source, last_seen}>`;
    - `$assets` is `list<{itemtype, items_id, name, entity, state, ips: list<string>}>`;
    - `$opt` is `{ranges: list<{begin:int,end:int}>, production_only: bool, category: string, last_device_sync: string, stale_minutes: int}`.

    It returns `{kpi: {devices, matched, not_in_glpi, ambiguous, assets, monitored, not_monitored, coverage_pct}, lists: {not_in_glpi, not_monitored, ambiguous, name_differs, stale}, categories: list<string>}`.
  - `Report::normaliseName(string): string`.
  - `Report::inRanges(string $ip, array $ranges): bool`.
  - `Csv::render(list<string> $header, list<list<scalar>> $rows): string`.

- [ ] **Step 1: Failing test** — `tests/coverage_report_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Coverage/Report.php';
require OPM_ROOT . '/src/Coverage/Csv.php';

use GlpiPlugin\Opmanager\Coverage\Csv;
use GlpiPlugin\Opmanager\Coverage\Report;

$dev = static fn (int $id, string $name, string $ip, string $state, string $itemtype = '', int $items_id = 0, string $source = '', string $seen = '2026-10-02 11:00:00', string $cat = 'Server') => [
    'id' => $id, 'device_key' => "k{$id}", 'name' => $name, 'display_name' => $name, 'ip' => $ip, 'category' => $cat, 'type' => 'Linux',
    'itemtype' => $itemtype, 'items_id' => $items_id, 'match_state' => $state, 'match_source' => $source, 'last_seen' => $seen,
];
$asset = static fn (string $t, int $id, string $name, array $ips) => ['itemtype' => $t, 'items_id' => $id, 'name' => $name, 'entity' => 'Root', 'state' => 'In use', 'ips' => $ips];

$devices = [
    $dev(1, 'core-sw-01', '10.20.14.1', 'matched', 'NetworkEquipment', 10, 'name'),
    $dev(2, 'srv-app.example.com', '10.20.14.2', 'matched', 'Computer', 20, 'ip'),
    $dev(3, 'mystery', '10.20.14.3', 'unmatched'),
    $dev(4, 'twin', '10.1.1.4', 'review'),
    $dev(5, 'old-box', '10.1.1.5', 'unmatched', '', 0, '', '2026-09-01 00:00:00', 'Switch'),
];
$assets = [
    $asset('NetworkEquipment', 10, 'CORE-SW-01', ['10.20.14.1']),
    $asset('Computer', 20, 'APP-SERVER', ['10.20.14.2']),
    $asset('Computer', 30, 'db-01', ['192.168.1.9', '10.20.14.30']),
    $asset('Computer', 40, 'lab-pc', ['192.168.50.4']),
];
$opt = ['ranges' => [['begin' => ip2long('10.20.14.1'), 'end' => ip2long('10.20.14.254')]], 'production_only' => false, 'category' => '',
        'last_device_sync' => '2026-10-02 11:30:00', 'stale_minutes' => 90];

$r = Report::build($devices, $assets, $opt);
check('kpis', $r['kpi'] === ['devices' => 5, 'matched' => 2, 'not_in_glpi' => 2, 'ambiguous' => 1, 'assets' => 4, 'monitored' => 2, 'not_monitored' => 2, 'coverage_pct' => 50]);
check('not in GLPI = unmatched devices', array_column($r['lists']['not_in_glpi'], 'id') === [3, 5]);
check('not monitored = assets without a matched device', array_column($r['lists']['not_monitored'], 'items_id') === [30, 40]);
check('ambiguous = review devices', array_column($r['lists']['ambiguous'], 'id') === [4]);
check('name differs only for IP matches with a different name', array_column($r['lists']['name_differs'], 'id') === [2] && $r['lists']['name_differs'][0]['asset_name'] === 'APP-SERVER');
check('name normalised: case, domain, spaces', Report::normaliseName('  SRV-App.example.com ') === 'srv-app');
check('stale = not seen since last sync minus threshold', array_column($r['lists']['stale'], 'id') === [5]);
check('categories listed for the filter', $r['categories'] === ['Server', 'Switch']);

$p = Report::build($devices, $assets, ['production_only' => true] + $opt);
check('production only: devices filtered by IP', $p['kpi']['devices'] === 3);
check('any IP in range makes the asset production', array_column($p['lists']['not_monitored'], 'items_id') === [30]);
check('production coverage', $p['kpi']['assets'] === 3 && $p['kpi']['coverage_pct'] === 67);
$c = Report::build($devices, $assets, ['category' => 'Switch'] + $opt);
check('category filter applies to devices', $c['kpi']['devices'] === 1);
check('no asset in scope: coverage 0, no division by zero', Report::build([], [], $opt)['kpi']['coverage_pct'] === 0);
check('bad IP never in range', !Report::inRanges('not-an-ip', $opt['ranges']));

$csv = Csv::render(['Name', 'IP'], [['=HYPERLINK("x")', '10.0.0.1'], ['a;b', '+1']]);
check('csv has BOM and ; separator', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "Name;IP\r\n"));
check('formula cells neutralised', str_contains($csv, "\"'=HYPERLINK(\"\"x\"\")\"") && str_contains($csv, "'+1"));
check('separator in a cell is quoted', str_contains($csv, '"a;b"'));

done();
```

- [ ] **Step 2: Run** — `C:/php/php.exe tests/coverage_report_test.php`. Expected: a fatal error on the missing `Report.php`.

- [ ] **Step 3: Implement**

`src/Coverage/Report.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Coverage;

/** Pure: OpManager devices vs GLPI assets (spec §15.1). */
final class Report
{
    /**
     * @param list<array<string, mixed>> $devices
     * @param list<array<string, mixed>> $assets
     * @param array{ranges: list<array{begin: int, end: int}>, production_only: bool, category: string, last_device_sync: string, stale_minutes: int} $opt
     * @return array{kpi: array<string, int>, lists: array<string, list<array<string, mixed>>>, categories: list<string>}
     */
    public static function build(array $devices, array $assets, array $opt): array
    {
        $categories = array_values(array_unique(array_filter(array_map(static fn ($d) => (string) $d['category'], $devices))));
        sort($categories);

        if ($opt['production_only']) {
            $devices = array_values(array_filter($devices, static fn ($d) => self::inRanges((string) $d['ip'], $opt['ranges'])));
            $assets  = array_values(array_filter($assets, static function ($a) use ($opt) {
                foreach ($a['ips'] as $ip) {
                    if (self::inRanges((string) $ip, $opt['ranges'])) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if ($opt['category'] !== '') {
            $devices = array_values(array_filter($devices, static fn ($d) => $d['category'] === $opt['category']));
        }

        $by_state = static fn (string $s) => array_values(array_filter($devices, static fn ($d) => $d['match_state'] === $s));
        $matched  = $by_state('matched');
        $linked   = [];
        foreach ($matched as $d) {
            $linked[$d['itemtype'] . '#' . $d['items_id']] = true;
        }
        $names = [];
        foreach ($assets as $a) {
            $names[$a['itemtype'] . '#' . $a['items_id']] = (string) $a['name'];
        }

        $not_monitored = array_values(array_filter($assets, static fn ($a) => !isset($linked[$a['itemtype'] . '#' . $a['items_id']])));
        $name_differs  = [];
        foreach ($matched as $d) {
            $key = $d['itemtype'] . '#' . $d['items_id'];
            $dev = $d['display_name'] !== '' ? $d['display_name'] : $d['name'];
            if ($d['match_source'] === 'ip' && isset($names[$key]) && self::normaliseName((string) $dev) !== self::normaliseName($names[$key])) {
                $name_differs[] = $d + ['asset_name' => $names[$key]];
            }
        }
        $limit = $opt['last_device_sync'] === '' ? null : strtotime($opt['last_device_sync']) - 60 * $opt['stale_minutes'];
        $stale = $limit === null ? [] : array_values(array_filter($devices, static fn ($d) => $d['last_seen'] !== '' && strtotime((string) $d['last_seen']) < $limit));

        $in_scope  = count($assets);
        $monitored = $in_scope - count($not_monitored);
        return [
            'kpi' => [
                'devices'       => count($devices),
                'matched'       => count($matched),
                'not_in_glpi'   => count($by_state('unmatched')),
                'ambiguous'     => count($by_state('review')),
                'assets'        => $in_scope,
                'monitored'     => $monitored,
                'not_monitored' => count($not_monitored),
                'coverage_pct'  => $in_scope === 0 ? 0 : (int) round(100 * $monitored / $in_scope),
            ],
            'lists' => [
                'not_in_glpi'   => $by_state('unmatched'),
                'not_monitored' => $not_monitored,
                'ambiguous'     => $by_state('review'),
                'name_differs'  => $name_differs,
                'stale'         => $stale,
            ],
            'categories' => $categories,
        ];
    }

    public static function normaliseName(string $name): string
    {
        $name = strtolower(trim($name));
        $dot  = strpos($name, '.');
        // A dotted IP is not a domain name: keep it whole.
        if ($dot !== false && filter_var($name, FILTER_VALIDATE_IP) === false) {
            $name = substr($name, 0, $dot);
        }
        return $name;
    }

    /** @param list<array{begin: int, end: int}> $ranges */
    public static function inRanges(string $ip, array $ranges): bool
    {
        $n = ip2long($ip);
        if ($n === false) {
            return false;
        }
        foreach ($ranges as $r) {
            if ($n >= $r['begin'] && $n <= $r['end']) {
                return true;
            }
        }
        return false;
    }
}
```

Production coverage check: the in-scope assets are 20 and 30 (10 is also in range), so there are 3 assets, of which 10 and 20 are monitored: 2/3 = 67%. That matches the test.

`src/Coverage/Csv.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Coverage;

/** Pure: Excel-friendly CSV (UTF-8 BOM, ';'), formula cells neutralised. */
final class Csv
{
    /**
     * @param list<string>             $header
     * @param list<list<scalar|null>>  $rows
     */
    public static function render(array $header, array $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }
        return $out;
    }

    /** @param list<scalar|null> $cells */
    private static function line(array $cells): string
    {
        $cells = array_map(static function ($v): string {
            $v = (string) $v;
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                $v = "'" . $v;
            }
            return preg_match('/[;"\r\n]/', $v) === 1 ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }, $cells);
        return implode(';', $cells) . "\r\n";
    }
}
```

- [ ] **Step 4: Run** — `C:/php/php.exe tests/coverage_report_test.php && C:/php/php.exe tests/run_all.php`. Expected: `ALL PASS (17 passed, 0 failed)`, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: coverage report and CSV (pure)`

---

### Task 4: Coverage data source (GLPI)

**Files:**
- Create: `src/Coverage/DbCoverageSource.php`, `tests/coverage_source_test.php`

**Interfaces:**
- Produces:
  - `DbCoverageSource::devices(): list<array>`, in the Task 3 device shape, with `ip` from `ip_address` and `category` from `device_category`;
  - `DbCoverageSource::assets(array $itemtypes, array $states): list<array>`, in the Task 3 asset shape.

- [ ] **Step 1: Failing test** — `tests/coverage_source_test.php`, a source-level test (GLPI runtime only on the VM):

```php
<?php

require __DIR__ . '/bootstrap.php';

$src = (string) @file_get_contents(OPM_ROOT . '/src/Coverage/DbCoverageSource.php');
check('source exists', $src !== '');
check('entity restriction applied', str_contains($src, 'getEntitiesRestrictCriteria('));
check('custom assets keep their system criteria', str_contains($src, 'getSystemSQLCriteria()'));
check('deleted and template assets excluded', str_contains($src, "'is_deleted'") && str_contains($src, "'is_template'"));
check('states filter only when states chosen', (bool) preg_match('/if \(\$states !== \[\]\).*?states_id/s', $src));
check('ips fetched in one query per itemtype', (bool) preg_match("/glpi_ipaddresses.*?'mainitemtype'\s*=>\s*\\\$itemtype.*?'mainitems_id'\s*=>\s*\\\$ids/s", $src));
check('assets without an IP are left out', str_contains($src, "if (\$ips === [])") || str_contains($src, 'if (!isset($ips['));

done();
```

- [ ] **Step 2: Run** — expected: `FAIL` on everything except possibly none.

- [ ] **Step 3: Implement** `src/Coverage/DbCoverageSource.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Coverage;

use GlpiPlugin\Opmanager\Device;

/** Reads what the Coverage page compares (spec §15.1). Runs in a user request: entity-scoped. */
final class DbCoverageSource
{
    /** @return list<array<string, mixed>> */
    public function devices(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => Device::getTable(), 'ORDER' => 'display_name']) as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'device_key' => (string) $r['device_key'], 'name' => (string) $r['name'],
                'display_name' => (string) $r['display_name'], 'ip' => (string) $r['ip_address'],
                'category' => (string) $r['device_category'], 'type' => (string) $r['device_type'],
                'itemtype' => (string) $r['itemtype'], 'items_id' => (int) $r['items_id'],
                'match_state' => (string) $r['match_state'], 'match_source' => (string) $r['match_source'],
                'last_seen' => (string) ($r['last_seen'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $itemtypes
     * @param list<int>    $states    [] = every state
     * @return list<array<string, mixed>>
     */
    public function assets(array $itemtypes, array $states): array
    {
        global $DB;

        $out = [];
        foreach ($itemtypes as $itemtype) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof \CommonDBTM) {
                continue;
            }
            $table = $itemtype::getTable();
            $where = $itemtype::getSystemSQLCriteria() + getEntitiesRestrictCriteria($table, '', '', $item->maybeRecursive());
            if ($item->maybeDeleted()) {
                $where[$table . '.is_deleted'] = 0;
            }
            if ($item->maybeTemplate()) {
                $where[$table . '.is_template'] = 0;
            }
            if ($states !== [] && $DB->fieldExists($table, 'states_id')) {
                $where[$table . '.states_id'] = $states;
            }
            $rows = [];
            foreach ($DB->request(['SELECT' => [$table . '.id', $table . '.name', $table . '.entities_id'] + ($DB->fieldExists($table, 'states_id') ? [$table . '.states_id'] : []), 'FROM' => $table, 'WHERE' => $where]) as $r) {
                $rows[(int) $r['id']] = $r;
            }
            if ($rows === []) {
                continue;
            }
            $ids = array_keys($rows);
            $ips = [];
            foreach ($DB->request([
                'SELECT' => ['mainitems_id', 'name'],
                'FROM'   => 'glpi_ipaddresses',
                'WHERE'  => ['mainitemtype' => $itemtype, 'mainitems_id' => $ids, 'is_deleted' => 0, 'NOT' => ['name' => '']],
            ]) as $ip) {
                $ips[(int) $ip['mainitems_id']][] = (string) $ip['name'];
            }
            foreach ($rows as $id => $r) {
                if (!isset($ips[$id])) {
                    continue; // D28: only assets with an IP are in scope
                }
                $out[] = [
                    'itemtype' => $itemtype, 'items_id' => $id, 'name' => (string) $r['name'],
                    'entity' => \Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']),
                    'state'  => isset($r['states_id']) ? \Dropdown::getDropdownName('glpi_states', (int) $r['states_id']) : '',
                    'ips'    => array_values(array_unique($ips[$id])),
                ];
            }
        }
        return $out;
    }
}
```

Ruling to carry: the `if ($states !== [])` check is combined with `fieldExists`, because custom asset tables may lack `states_id`. The test regex still matches.

- [ ] **Step 4: Run** — `C:/php/php.exe tests/coverage_source_test.php && C:/php/php.exe -l src/Coverage/DbCoverageSource.php && C:/php/php.exe tests/run_all.php`. Expected: `ALL PASS (7 passed, 0 failed)`, no syntax errors, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: coverage data source (entity-scoped, batched IPs)`

---

### Task 5: Coverage page, menu, CSV export

**Files:**
- Create: `src/Coverage/View.php`, `src/Coverage/Page.php`, `front/coverage.php`, `tests/coverage_view_test.php`
- Modify: `src/Menu.php`

**Interfaces:**
- Consumes: `Report`, `Csv`, `DbCoverageSource`, `AddressingRanges::load`, `Config`.
- Produces:
  - `View::LISTS = ['not_in_glpi','not_monitored','ambiguous','name_differs','stale']`;
  - `View::render(array $report, array $state, string $base_url): string`, where `$state` is `{list, page, production_only, category}`;
  - `View::csvRows(string $list, array $rows): array{header: list<string>, rows: list<list<string>>}`;
  - `Page` (a `CommonGLPI` for the menu, with `getSearchURL` pointing to `front/coverage.php`) and a menu entry `coverage`.

- [ ] **Step 1: Failing test** — `tests/coverage_view_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Coverage/View.php';

use GlpiPlugin\Opmanager\Coverage\View;

$report = ['kpi' => ['devices' => 5, 'matched' => 2, 'not_in_glpi' => 2, 'ambiguous' => 1, 'assets' => 4, 'monitored' => 2, 'not_monitored' => 2, 'coverage_pct' => 50],
    'lists' => [
        'not_in_glpi' => [['id' => 3, 'name' => '<b>mystery</b>', 'display_name' => '<b>mystery</b>', 'ip' => '10.1.1.3', 'category' => 'Server', 'type' => 'Linux', 'last_seen' => 'x']],
        'not_monitored' => array_map(fn ($i) => ['itemtype' => 'Computer', 'items_id' => $i, 'name' => "pc{$i}", 'entity' => 'Root', 'state' => 'In use', 'ips' => ['10.0.0.' . $i]], range(1, 60)),
        'ambiguous' => [], 'name_differs' => [], 'stale' => [],
    ], 'categories' => ['Server']];
$state = ['list' => 'not_monitored', 'page' => 2, 'production_only' => true, 'category' => ''];
$html = View::render($report, $state, '/plugins/opmanager/front/coverage.php');

check('kpi cards show the coverage percentage', str_contains($html, '50%') && str_contains($html, '>4<'));
check('one tab per list with its count', count(array_filter(View::LISTS, fn ($l) => str_contains($html, 'list=' . $l))) === 5 && str_contains($html, '(60)'));
check('page 2 shows rows 51-60', str_contains($html, '>pc51<') && !str_contains($html, '>pc50<'));
check('csv link keeps the filters', str_contains($html, 'export=csv') && str_contains($html, 'production_only=1'));
check('values escaped', !str_contains(View::render($report, ['list' => 'not_in_glpi'] + $state, '/x'), '<b>mystery</b>'));
check('production toggle rendered checked', (bool) preg_match('/name="production_only"[^>]*checked/', $html));
$csv = View::csvRows('not_monitored', $report['lists']['not_monitored']);
check('csv rows for every item, not only one page', count($csv['rows']) === 60 && $csv['header'][0] !== '');
check('csv joins several IPs', View::csvRows('not_monitored', [['itemtype' => 'Computer', 'items_id' => 1, 'name' => 'a', 'entity' => 'R', 'state' => '', 'ips' => ['1.1.1.1', '2.2.2.2']]])['rows'][0][3] === '1.1.1.1, 2.2.2.2');

$menu = (string) file_get_contents(OPM_ROOT . '/src/Menu.php');
check('menu has a coverage entry', str_contains($menu, "'coverage'") && str_contains($menu, 'Coverage\\Page'));
$front = (string) @file_get_contents(OPM_ROOT . '/front/coverage.php');
check('front checks the monitoring right', str_contains($front, 'Session::checkRight(RightDefinition::MONITORING, READ)'));
check('front sends CSV with a download name', str_contains($front, 'Content-Disposition: attachment') && str_contains($front, 'Csv::render('));

done();
```

The View tests need `__`/`__s`/`htmlescape` from `bootstrap.php`, which already provides them.

- [ ] **Step 2: Run** — expected: a fatal error on the missing `View.php`.

- [ ] **Step 3: Implement**

`src/Coverage/View.php`: a pure HTML builder.
- **KPI row:** `<div class="row row-cards">`, with each card `<div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="subheader">Label</div><div class="h1 mb-0">value</div></div></div></div>`. Cards, in order:
  - Coverage `{coverage_pct}%`, with "{monitored} of {assets} GLPI assets";
  - OpManager devices;
  - Not in GLPI;
  - Not monitored;
  - Ambiguous.
- **Filter form:** GET, `production_only` checkbox, category select (from `$report['categories']`), and the hidden `list`.
- **Tabs:** `<ul class="nav nav-tabs">` with one link per `LISTS` entry, `href="{base}?list=<l>&production_only=<0|1>&category=<c>"` and label "Name (count)".
- **Table** of the current list, 50 rows per page (`array_slice($rows, ($page-1)*50, 50)`), plus pager links and a CSV link `{same query}&export=csv`.
- **Columns per list:**
  - not_in_glpi: Device, IP, Category, Type, Last seen;
  - not_monitored: Asset (link `$itemtype::getFormURLWithID($id)` when `class_exists`; plain text otherwise), Type, Entity, IPs, Status;
  - ambiguous: Device (link `Device::getFormURLWithID`), IP, Category;
  - name_differs: Device name, GLPI asset name, IP;
  - stale: Device, IP, Last seen.
- Every cell is passed through `htmlescape()`. Links are built only from the itemtype class and an int id.
- `csvRows()` uses the same columns, without links. IPs are joined with `', '`.

`src/Coverage/Page.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Coverage;

use GlpiPlugin\Opmanager\RightDefinition;

/** Menu anchor for the Coverage page (D28). */
final class Page extends \CommonGLPI
{
    public static $rightname = RightDefinition::MONITORING;

    public static function getTypeName($nb = 0)
    {
        return __('Coverage', 'opmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-pie';
    }

    public static function getSearchURL($full = true)
    {
        return \Html::getPrefixedUrl('/plugins/opmanager/front/coverage.php');
    }
}
```

In `src/Menu.php`, add after the `alarm` entry:

```php
            'coverage'         => [
                'title' => \GlpiPlugin\Opmanager\Coverage\Page::getTypeName(),
                'page'  => \GlpiPlugin\Opmanager\Coverage\Page::getSearchURL(false),
                'icon'  => \GlpiPlugin\Opmanager\Coverage\Page::getIcon(),
            ],
```

`front/coverage.php`:

```php
<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\Coverage\Csv;
use GlpiPlugin\Opmanager\Coverage\DbCoverageSource;
use GlpiPlugin\Opmanager\Coverage\Page;
use GlpiPlugin\Opmanager\Coverage\Report;
use GlpiPlugin\Opmanager\Coverage\View;
use GlpiPlugin\Opmanager\Menu;
use GlpiPlugin\Opmanager\RightDefinition;
use GlpiPlugin\Opmanager\Ticketing\AddressingRanges;

Session::checkRight(RightDefinition::MONITORING, READ);

$list  = in_array($_GET['list'] ?? '', View::LISTS, true) ? $_GET['list'] : View::LISTS[0];
$state = [
    'list'            => $list,
    'page'            => max(1, (int) ($_GET['page'] ?? 1)),
    'production_only' => !empty($_GET['production_only']),
    'category'        => (string) ($_GET['category'] ?? ''),
];
$cfg    = Config::ticketingConfig();
$source = new DbCoverageSource();
$report = Report::build($source->devices(), $source->assets(Config::assetItemtypes(), Config::coverageStates()), [
    'ranges'           => AddressingRanges::load($cfg['production_addressings']),
    'production_only'  => $state['production_only'],
    'category'         => $state['category'],
    'last_device_sync' => Config::get('last_device_sync'),
    'stale_minutes'    => max(1, (int) Config::get('stale_warning_minutes')),
]);

if (($_GET['export'] ?? '') === 'csv') {
    $csv = View::csvRows($list, $report['lists'][$list]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="opmanager-coverage-' . $list . '-' . date('Ymd') . '.csv"');
    echo Csv::render($csv['header'], $csv['rows']);
    return;
}

Html::header(Page::getTypeName(), '', 'opmanager', Menu::class, 'coverage');
echo View::render($report, $state, Page::getSearchURL(false));
Html::footer();
```

Ruling to check while implementing: `AddressingRanges::load()` returns `list<{id, begin, end}>` with `ip2long` ints, as `RangeProductionCheck` consumes it. If its shape differs, adapt it in `front/coverage.php` with `array_map` and ledger the ruling.

- [ ] **Step 4: Run** — `C:/php/php.exe tests/coverage_view_test.php && for f in src/Coverage/View.php src/Coverage/Page.php front/coverage.php src/Menu.php; do C:/php/php.exe -l $f; done && C:/php/php.exe tests/run_all.php`. Expected: `ALL PASS (11 passed, 0 failed)`, four with no syntax errors, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: coverage page with five follow-up lists and CSV export`

---

### Task 6: Native GLPI dashboard cards

**Files:**
- Create: `src/Coverage/Cards.php`, `tests/cards_test.php`
- Modify: `src/Coverage/Report.php`, `src/Coverage/DbCoverageSource.php`, `setup.php`, `tests/coverage_report_test.php`, `tests/coverage_source_test.php`

**Interfaces:**
- Consumes: `Report::build`, `DbCoverageSource`, `Page::getSearchURL`.
- Produces:
  - `Report::byCategory(list<device>): array<string, array{matched:int, unmatched:int, review:int}>`, sorted by category name, with an empty category shown as `'-'`;
  - `DbCoverageSource::alarmsBySeverity(): array<string,int>`, counting active alarms by `severity_label`;
  - `DbCoverageSource::openAlarmTickets(): int`, counting distinct tickets linked to alarms that are not solved, closed or deleted, limited to the user's entities;
  - `Cards::cards(array $cards = []): array`, the hook target;
  - providers `Cards::coverage|devices|notMonitored|notInGlpi|matching|byCategory|alarmsBySeverity|openAlarmTickets(array $params = []): array`;
  - pure helpers `Cards::categoryBars(array $by_category): array{labels, series}` and `Cards::cached(array &$store, string $key, int $ttl, int $now, callable $compute): mixed`.

- [ ] **Step 1: Failing tests**

Append to `tests/coverage_report_test.php` before `done();`:

```php
$bc = Report::byCategory($devices);
check('by category counts per match state', $bc === [
    'Server' => ['matched' => 2, 'unmatched' => 1, 'review' => 1],
    'Switch' => ['matched' => 0, 'unmatched' => 1, 'review' => 0],
]);
check('empty category shown as dash', array_keys(Report::byCategory([$dev(9, 'x', '1.1.1.1', 'unmatched', '', 0, '', '', '')])) === ['-']);
```

Append to `tests/coverage_source_test.php` before `done();`:

```php
check('active alarms grouped by severity', (bool) preg_match("/function alarmsBySeverity.*?'state' => 'active'.*?'GROUPBY'/s", $src));
check('open alarm tickets are entity-scoped and exclude solved/closed/deleted', (bool) preg_match("/function openAlarmTickets.*?glpi_tickets.*?getEntitiesRestrictCriteria\('glpi_tickets'.*?'is_deleted' => 0.*?\[5, 6\]/s", $src));
```

`tests/cards_test.php`:

```php
<?php

namespace {
    require __DIR__ . '/bootstrap.php';
    $GLOBALS['RIGHTS'] = [];
    class Session
    {
        public static function haveRight($n, $m) { return ((int) ($GLOBALS['RIGHTS'][$n] ?? 0) & $m) === $m; }
    }
    class Html { public static function getPrefixedUrl($u) { return $u; } }
    class CommonGLPI {}
    require OPM_ROOT . '/src/RightDefinition.php';
    require OPM_ROOT . '/src/Coverage/Page.php';
    require OPM_ROOT . '/src/Coverage/Report.php';
    require OPM_ROOT . '/src/Coverage/Cards.php';

    use GlpiPlugin\Opmanager\Coverage\Cards;
    use GlpiPlugin\Opmanager\RightDefinition as R;

    check('no monitoring right: no card offered', Cards::cards(['core' => []]) === ['core' => []]);
    check('no monitoring right: provider returns zero, never data', Cards::coverage()['number'] === 0 && Cards::matching()['data'] === []);

    $GLOBALS['RIGHTS'] = [R::MONITORING => READ];
    $cards = Cards::cards(['core' => []]);
    $mine  = array_filter($cards, fn ($k) => str_starts_with($k, 'plugin_opmanager_'), ARRAY_FILTER_USE_KEY);
    check('eight cards registered, existing kept', count($mine) === 8 && isset($cards['core']));
    $ok = true;
    foreach ($mine as $card) {
        [$class, $method] = explode('::', $card['provider']);
        $ok = $ok && is_string($card['provider']) && method_exists($class, $method) && $card['group'] === 'OpManager' && $card['widgettype'] !== [];
    }
    check('providers are Class::method strings that exist (GLPI explodes them)', $ok);

    $bars = Cards::categoryBars(['Server' => ['matched' => 2, 'unmatched' => 1, 'review' => 1], 'Switch' => ['matched' => 0, 'unmatched' => 1, 'review' => 0]]);
    check('stacked bars shape', $bars['labels'] === ['Server', 'Switch'] && count($bars['series']) === 3 && $bars['series'][0]['data'] === [2, 0]);

    $store = []; $calls = 0;
    $f = function () use (&$calls) { return ++$calls; };
    Cards::cached($store, 'k', 300, 1000, $f);
    Cards::cached($store, 'k', 300, 1200, $f);
    check('cache reused within ttl', $calls === 1);
    Cards::cached($store, 'k', 300, 1301, $f);
    check('cache recomputed after ttl', $calls === 2);
    Cards::cached($store, 'other', 300, 1301, $f);
    check('cache keyed (per entity set)', $calls === 3);

    $setup = (string) file_get_contents(OPM_ROOT . '/setup.php');
    check('dashboard hook registered with a Class::method string', str_contains($setup, "\$PLUGIN_HOOKS['dashboard_cards']['opmanager'] = 'GlpiPlugin\\\\Opmanager\\\\Coverage\\\\Cards::cards';"));

    done();
}
```

- [ ] **Step 2: Run** — `C:/php/php.exe tests/coverage_report_test.php; C:/php/php.exe tests/coverage_source_test.php; C:/php/php.exe tests/cards_test.php`. Expected: `FAIL` on the new checks, then a fatal error on the missing `Cards.php`.

- [ ] **Step 3: Implement**

In `src/Coverage/Report.php`, add:

```php
    /**
     * @param list<array<string, mixed>> $devices
     * @return array<string, array{matched: int, unmatched: int, review: int}>
     */
    public static function byCategory(array $devices): array
    {
        $out = [];
        foreach ($devices as $d) {
            $cat = (string) $d['category'] !== '' ? (string) $d['category'] : '-';
            $out[$cat] ??= ['matched' => 0, 'unmatched' => 0, 'review' => 0];
            if (isset($out[$cat][$d['match_state']])) {
                $out[$cat][$d['match_state']]++;
            }
        }
        ksort($out);
        return $out;
    }
```

In `src/Coverage/DbCoverageSource.php`, add:

```php
    /** @return array<string, int> active alarms per severity label */
    public function alarmsBySeverity(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'  => ['severity_label', 'COUNT' => 'id AS cpt'],
            'FROM'    => \GlpiPlugin\Opmanager\Alarm::getTable(),
            'WHERE'   => ['state' => 'active'],
            'GROUPBY' => 'severity_label',
        ]) as $r) {
            $out[(string) $r['severity_label']] = (int) $r['cpt'];
        }
        return $out;
    }

    public function openAlarmTickets(): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT'     => ['COUNT DISTINCT' => 'glpi_tickets.id AS cpt'],
            'FROM'       => \GlpiPlugin\Opmanager\Alarm::getTable(),
            'INNER JOIN' => ['glpi_tickets' => ['ON' => [\GlpiPlugin\Opmanager\Alarm::getTable() => 'tickets_id', 'glpi_tickets' => 'id']]],
            'WHERE'      => getEntitiesRestrictCriteria('glpi_tickets', '', '', true) + ['glpi_tickets.is_deleted' => 0, 'NOT' => ['glpi_tickets.status' => [5, 6]]],
        ])->current();
        return (int) ($row['cpt'] ?? 0);
    }
```

`src/Coverage/Cards.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Coverage;

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;

/**
 * Native GLPI dashboard cards (D28 addendum). Core renders card providers WITHOUT a right
 * check (integrasipaman lesson, hub §7): every provider and the card list check
 * plugin_opmanager_monitoring READ themselves. Figures come from the same Report as the
 * Coverage page, cached per user and entity set for five minutes.
 */
final class Cards
{
    private const TTL = 300;

    private const GROUP = 'OpManager';

    /** @param array<string, mixed> $cards */
    public static function cards(array $cards = []): array
    {
        if (!self::allowed()) {
            return $cards;
        }
        $defs = [
            'coverage'         => [['bigNumber'], __('OpManager coverage of GLPI assets (%)', 'opmanager')],
            'devices'          => [['bigNumber'], __('OpManager devices', 'opmanager')],
            'notMonitored'     => [['bigNumber'], __('GLPI assets not monitored', 'opmanager')],
            'notInGlpi'        => [['bigNumber'], __('OpManager devices not in GLPI', 'opmanager')],
            'matching'         => [['pie', 'donut', 'halfpie'], __('OpManager device matching', 'opmanager')],
            'byCategory'       => [['stackedbars', 'stackedHBars', 'bars'], __('OpManager devices by category', 'opmanager')],
            'alarmsBySeverity' => [['pie', 'donut', 'halfpie', 'bar'], __('Active OpManager alarms by severity', 'opmanager')],
            'openAlarmTickets' => [['bigNumber'], __('Open tickets from OpManager alarms', 'opmanager')],
        ];
        foreach ($defs as $method => [$types, $label]) {
            $cards['plugin_opmanager_' . strtolower($method)] = [
                'widgettype' => $types,
                'group'      => self::GROUP,
                'label'      => $label,
                'provider'   => self::class . '::' . $method,
            ];
        }
        return $cards;
    }

    public static function coverage(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['coverage_pct' => 0];
        return self::big(__('Coverage %', 'opmanager'), $kpi['coverage_pct'], 'ti ti-chart-pie', 'not_monitored');
    }

    public static function devices(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['devices' => 0];
        return self::big(__('OpManager devices', 'opmanager'), $kpi['devices'], 'ti ti-activity', 'not_in_glpi');
    }

    public static function notMonitored(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['not_monitored' => 0];
        return self::big(__('GLPI assets not monitored', 'opmanager'), $kpi['not_monitored'], 'ti ti-eye-off', 'not_monitored');
    }

    public static function notInGlpi(array $params = []): array
    {
        $kpi = self::allowed() ? self::summary()['kpi'] : ['not_in_glpi' => 0];
        return self::big(__('OpManager devices not in GLPI', 'opmanager'), $kpi['not_in_glpi'], 'ti ti-database-off', 'not_in_glpi');
    }

    public static function matching(array $params = []): array
    {
        $out = ['data' => [], 'label' => __('OpManager device matching', 'opmanager'), 'icon' => 'ti ti-link'];
        if (!self::allowed()) {
            return $out;
        }
        $k = self::summary()['kpi'];
        foreach ([[__('Matched', 'opmanager'), $k['matched'], 'not_monitored'], [__('Not in GLPI', 'opmanager'), $k['not_in_glpi'], 'not_in_glpi'], [__('Ambiguous', 'opmanager'), $k['ambiguous'], 'ambiguous']] as [$label, $n, $list]) {
            $out['data'][] = ['number' => $n, 'label' => $label, 'url' => self::url($list)];
        }
        return $out;
    }

    public static function byCategory(array $params = []): array
    {
        return [
            'data'  => self::allowed() ? self::categoryBars(self::summary()['by_category']) : ['labels' => [], 'series' => []],
            'label' => __('OpManager devices by category', 'opmanager'),
            'icon'  => 'ti ti-category',
        ];
    }

    public static function alarmsBySeverity(array $params = []): array
    {
        $out = ['data' => [], 'label' => __('Active OpManager alarms by severity', 'opmanager'), 'icon' => 'ti ti-bell'];
        if (!self::allowed()) {
            return $out;
        }
        foreach (self::summary()['alarms'] as $label => $n) {
            $out['data'][] = ['number' => $n, 'label' => $label, 'url' => \GlpiPlugin\Opmanager\Alarm::getSearchURL()];
        }
        return $out;
    }

    public static function openAlarmTickets(array $params = []): array
    {
        $n = self::allowed() ? self::summary()['open_tickets'] : 0;
        return ['number' => $n, 'label' => __('Open tickets from OpManager alarms', 'opmanager'), 'icon' => 'ti ti-ticket'];
    }

    /**
     * @param array<string, array{matched: int, unmatched: int, review: int}> $by_category
     * @return array{labels: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    public static function categoryBars(array $by_category): array
    {
        $series = [];
        foreach (['matched' => __('Matched', 'opmanager'), 'unmatched' => __('Not in GLPI', 'opmanager'), 'review' => __('Ambiguous', 'opmanager')] as $key => $name) {
            $series[] = ['name' => $name, 'data' => array_values(array_map(static fn ($c) => $c[$key], $by_category))];
        }
        return ['labels' => array_keys($by_category), 'series' => $series];
    }

    /** @param array<string, array{at: int, value: mixed}> $store */
    public static function cached(array &$store, string $key, int $ttl, int $now, callable $compute): mixed
    {
        if (!isset($store[$key]) || $now - $store[$key]['at'] >= $ttl) {
            $store[$key] = ['at' => $now, 'value' => $compute()];
        }
        return $store[$key]['value'];
    }

    private static function allowed(): bool
    {
        return \Session::haveRight(RightDefinition::MONITORING, READ);
    }

    /** @return array{kpi: array<string, int>, by_category: array, alarms: array<string, int>, open_tickets: int} */
    private static function summary(): array
    {
        $_SESSION['plugin_opmanager_cards'] ??= [];
        $key = md5((string) json_encode($_SESSION['glpiactiveentities'] ?? []));
        return self::cached($_SESSION['plugin_opmanager_cards'], $key, self::TTL, time(), static function (): array {
            $src     = new DbCoverageSource();
            $devices = $src->devices();
            $report  = Report::build($devices, $src->assets(Config::assetItemtypes(), Config::coverageStates()), [
                'ranges' => [], 'production_only' => false, 'category' => '',
                'last_device_sync' => Config::get('last_device_sync'), 'stale_minutes' => max(1, (int) Config::get('stale_warning_minutes')),
            ]);
            return [
                'kpi'          => $report['kpi'],
                'by_category'  => Report::byCategory($devices),
                'alarms'       => $src->alarmsBySeverity(),
                'open_tickets' => $src->openAlarmTickets(),
            ];
        });
    }

    private static function big(string $label, int $number, string $icon, string $list): array
    {
        return ['number' => $number, 'label' => $label, 'icon' => $icon, 'url' => self::url($list)];
    }

    private static function url(string $list): string
    {
        return Page::getSearchURL(false) . '?list=' . rawurlencode($list);
    }
}
```

Widget type keys were checked against `Glpi\Dashboard\Widget::getAllTypes()` in 11.0.5: `pie`, `donut`, `halfpie`, `bar`, `bars`, `stackedbars`, `stackedHBars` and `bigNumber` all exist.

In `setup.php` `plugin_init_opmanager()`, after the item hooks, add:

```php
    // Native dashboard cards (v0.6.0). Provider strings are exploded by core (hub §4).
    $PLUGIN_HOOKS['dashboard_cards']['opmanager'] = 'GlpiPlugin\\Opmanager\\Coverage\\Cards::cards';
```

- [ ] **Step 4: Run** — `C:/php/php.exe tests/cards_test.php && C:/php/php.exe tests/coverage_report_test.php && C:/php/php.exe tests/coverage_source_test.php && C:/php/php.exe -l src/Coverage/Cards.php && C:/php/php.exe tests/run_all.php`. Expected: `ALL PASS` for each, no syntax errors, then `SUITES OK`.

- [ ] **Step 5: Commit** — `feat: native GLPI dashboard cards (rights-checked, cached)`

---

### Task 7: Release 0.6.0

**Files:**
- Modify: `setup.php`, `CHANGELOG.md`
- Create: `docs/runbook-v0.6.0.md`

- [ ] **Step 1:** Set `PLUGIN_OPMANAGER_VERSION` to `0.6.0`. Add a `## 0.6.0` section to the CHANGELOG covering:
  - the Coverage page: KPIs, five lists, CSV, production and category filters;
  - Setup as a status strip plus tabs;
  - the new setting "GLPI statuses counted as in use", and the native dashboard cards (group "OpManager").
- [ ] **Step 2:** Write `docs/runbook-v0.6.0.md` (frontmatter `date: 2026-10-02`, `tags: [opmanager, runbook]`, `status: active`) with these checks:
  - **Install and selfcheck:** `ALL PASS (18)`.
  - **Setup:**
    - the status strip shows five badges matching reality;
    - every tab opens;
    - Save keeps values across tabs;
    - after a reload the last tab reopens;
    - a read-only profile sees no Save.
  - **Coverage** (OpManager → Coverage):
    - the KPIs are plausible (devices = the 933 count, matched about 346);
    - each tab lists rows;
    - "Production only" narrows the numbers;
    - CSV opens in Excel with the right columns;
    - a user restricted to one entity sees only that entity's assets in "Not monitored".
  - **Statuses:** pick the GLPI status(es) meaning "in use" in Setup → Matching, then check that the "GLPI assets" KPI changes.
- [ ] **Step 3:** Run the full suite, commit `chore: release 0.6.0 (coverage page, setup tabs)`, build the zip from HEAD, and run `release_check --install` → expected `RELEASE OK`.
- [ ] **Step 4:** Hub §2 row 7: "v0.6.0 built, not deployed (Coverage page, Setup tabs)".
