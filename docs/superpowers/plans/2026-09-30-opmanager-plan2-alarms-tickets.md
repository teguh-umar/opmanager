# opmanager Plan 2 — Alarm Polling + Tickets (v0.2.0) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship opmanager v0.2.0. A CLI cron (`AlarmSync`, every 10 min) pulls OpManager's **active** alarms and keeps a local copy. It creates at most one GLPI ticket per OpManager alarm id, joins alarms of the same device into that device's open ticket, and reopens the ticket when the same alarm id comes back. It Solves the ticket only when every alarm on it is confirmed Clear, links repeats of the same device + alarm type within 24 h, brakes on alarm storms, and fills tickets through a GLPI ticket template. The Monitoring section also lists the asset's alarms.

**Architecture:** Every decision is pure and tested offline:
- `Ticketing\Decision` (what to do with one alarm);
- `Ticketing\TicketBuilder` (the ticket input);
- `Ticketing\EntityResolver` (asset entity → IP network entity → default);
- `OpManager\AlarmParser` (response → rows);
- `Ticketing\AlarmSync` (orchestration over four ports: `AlarmSource`, `AlarmStore`, `TicketGateway`, `AssetResolver`).

The GLPI-touching adapters (`DbAlarmStore`, `GlpiTicketGateway`, `DbDeviceIndex`) are thin and covered by the selfcheck and the VM runbook. All OpManager calls move the API key to the `apiKey` header.

**Tech Stack:** PHP 8.2+ (local CLI 8.4, no mbstring, no GLPI runtime), GLPI 11.0.5 core APIs (Ticket, ITILFollowup, ITILSolution, Ticket_Ticket, TicketTemplate), Guzzle from GLPI `vendor/`.

**Spec:** `docs/superpowers/specs/2026-09-29-opmanager-design.md` — D3, D6, D10–D14, D17–D20, §6 (alarms, storms), §7, §10, §11, §14. Base: `main` at `2a595ce` (v0.1.1). Out of scope: the webhook receiver (v0.3.0) and outbound notes/clear (Plan 3). Consequently the loop guard `Engine::$applying` and the `queue` table are not built here.

## Global Constraints

- Plugin key `opmanager`; tables `glpi_plugin_opmanager_*`; config context `plugin:opmanager`; PSR-4 `GlpiPlugin\Opmanager\` → `src/`; cron `GlpiPlugin\Opmanager\Cron::*`, all `CronTask::MODE_EXTERNAL`.
- Keys `INT UNSIGNED`. OpManager identifiers are `VARCHAR *_key` (`alarm_key` = `alarmId`, `entity_key` = `entity`), never `*_id`. Dates are `TIMESTAMP`.
- **D3:** one OpManager `alarmId` = at most one ticket (`UNIQUE KEY alarm_key`).
- **D19:** severity is `numericSeverity`: 1 Critical, 2 Trouble, 3 Attention, 4 Service Down, 5 Clear. Ticketable default `[1]`. Never render the `severity` field (it is HTML); use `severityString`.
- **D20:** polling uses `alertType=ActiveAlarms`. A locally active alarm that is missing from the list is only cleared if `listAlarms?entity=<entity_key>` returns that same alarm with severity 5. An error or non-list response never clears anything.
- **D6:** alarm Clear → ticket **Solved**, only when **all** alarms on the ticket are cleared. The same `alarm_key` active again after it was cleared → reopen (Solved/Closed) or followup (still open).
- **D10:** a new ticket is linked (`Ticket_Ticket::LINK_TO`) + gets a followup when a plugin ticket for the same `device_name` + `alarm_code` became Solved/Closed within `relink_window_hours` (default 24).
- **D12:** a new ticketable alarm whose device already has an open plugin ticket joins it (followup). Storm brake: when `>= storm_threshold` (default 10) own tickets were created in the last `storm_window_minutes` (default 5), further new alarms join one "alarm storm" ticket. The storm ends after one window without hits.
- **D14:** ticket entity = entity of the matched asset → entity of the most specific IPv4 `glpi_ipnetworks` row containing the alarm IP (toggle `ticket_use_ipnetwork_entity`) → `ticket_entity_default`.
- **D18:** the ticket template is the configured `TicketTemplate`, or the GLPI default via `getITILTemplateToUse()`. The plugin applies `$tt->predefined` itself and passes `_tickettemplate` so GLPI enforces mandatory fields. Title and content come from Setup patterns; urgency comes from severity only when the template does not predefine it.
- Followups are added with `_no_reopen => 1`; reopening uses GLPI's own path, a followup with `add_reopen => 1`. Every followup and solution carries `users_id` = the configured service user.
- A plugin never raw-writes core tables. Tickets, followups, solutions and links go through `->add()`.
- API key: header `apiKey` only (deprecated as a query parameter since OpManager 12.8.100; `listAlarms` is header-only).
- No pager in the asset-tab section; the "Showing N of M" line stays visible; no count badges.
- Offline tests: `C:/php/php.exe tests/run_all.php`. Every regression test is seen to fail first. Count results with `^PASS`.
- Release: tag locally, push only on a major version; lib SHAs are checked inside the zip; install into `C:/Users/teguh.umar/Downloads/glpi/plugins/opmanager/`.
- Never read `C:/Users/teguh.umar/Downloads/glpi/config/` or `files/`.

## Review Focus

1. **OpManager is down or answers with an error / an empty list while alarms are active**: nothing is cleared and no ticket is Solved. Pinned in Task 6 (`source failure touches nothing`, `missing alarm without confirmed clear stays active`).
2. **The same active alarm is polled every 10 minutes**: no second ticket and no followup spam, since a followup is only added when severity or message changed. Pinned in Task 5 (`unchanged alarm does nothing`) and Task 6 (`second poll is a no-op`).
3. **A template with a mandatory field the plugin does not fill**: the ticket is not created, the alarm is kept as `ticket_pending`, and the next poll retries it. Pinned in Task 6 (`failed create is pending and retried`).
4. **An alarm on a device that is not in the device list** (a VM under a VMware host): it still gets a ticket, matched by display name/IP or unmatched with the default entity. Pinned in Task 4 (`alarm device not in list falls back to matcher`) and Task 3 (`no asset, no network → default entity`).
5. **Severity rises from Attention (not ticketable) to Critical on the same alarm id**: a ticket is created then. Critical → Attention on a ticketed alarm → followup only. Pinned in Task 5 (`severity rise creates ticket`, `severity drop is a followup`).

---

## File map

| File | Responsibility |
|---|---|
| `src/OpManager/ResponseGuard.php` | Shared JSON decode + error-object + list unwrap + field pick |
| `src/OpManager/DeviceParser.php` | (modify) use ResponseGuard |
| `src/OpManager/AlarmParser.php` | Alarm response → normalised rows |
| `src/OpManager/AlarmSource.php` | Port: active alarms + one alarm by entity |
| `src/OpManager/Client.php` | (modify) header auth, `listActiveAlarms`, `alarmByEntity` |
| `src/Ticketing/Decision.php` | Pure per-alarm decision |
| `src/Ticketing/EntityResolver.php` | Pure D14 entity choice |
| `src/Ticketing/TicketBuilder.php` | Pure ticket input from alarm + template + config |
| `src/Ticketing/Messages.php` | Pure followup/solution texts |
| `src/Ticketing/AssetResolver.php`, `AlarmAssetResolver.php`, `DeviceIndex.php`, `DbDeviceIndex.php` | Alarm → GLPI asset |
| `src/Ticketing/AlarmStore.php`, `DbAlarmStore.php` | Port + DB adapter for alarms/storms |
| `src/Ticketing/TicketGateway.php`, `GlpiTicketGateway.php` | Port + GLPI adapter for tickets |
| `src/Ticketing/AlarmSync.php` | Pure orchestration of one poll |
| `src/Alarm.php` | Itemtype for `glpi_plugin_opmanager_alarms` (search list, asset lookup) |
| `src/Config.php`, `src/SuiteConfig.php`, `front/config.php` | (modify) ticketing keys and form |
| `src/Cron.php`, `install/*.php`, `src/Menu.php`, `front/alarm.php` | (modify/create) cron, schema, menu entry, list page |
| `src/AssetSections.php` | (modify) alarms in the Monitoring section |
| `src/SelfCheck.php` | (modify) new checks |

---

### Task 1: Header auth and alarm parsing

**Files:**
- Create: `src/OpManager/ResponseGuard.php`, `src/OpManager/AlarmParser.php`, `src/OpManager/AlarmSource.php`, `tests/alarmparser_test.php`
- Modify: `src/OpManager/DeviceParser.php`, `src/OpManager/Client.php`, `tests/client_test.php`

**Interfaces:**
- Produces:
  - `ResponseGuard::decode(string $json, string $what): array` (throws `ClientException` on invalid JSON with message `"OpManager {$what} is not valid JSON"`, or on an error object with message `"OpManager error <code>: <message>"`);
  - `ResponseGuard::unwrapList(array $data, string $what): array` (message `"OpManager {$what} has an unexpected shape"`);
  - `ResponseGuard::first(array $row, array $keys): string`.
  - `AlarmParser::parse(string $json): list<array>`. Each row is `array{alarm_key, entity_key, device_name, display_name, ip_address, category, alarm_code, event_type: string, severity: int, severity_label: string, message: string, mod_time: string}`, with `mod_time` as `'Y-m-d H:i:s'` or `''`.
  - `AlarmParser::SEVERITY_LABELS = [1 => 'Critical', 2 => 'Trouble', 3 => 'Attention', 4 => 'Service Down', 5 => 'Clear']`.
  - `interface AlarmSource { listActiveAlarms(): array; alarmByEntity(string $entity): ?array; }`.
  - `Client` implements `DeviceSource` and `AlarmSource`. Its transport signature becomes `callable(string $url, array $query, array $headers): array{status: int, body: string}`. Constant `Client::ALARM_LIST_PATH = '/api/json/alarm/listAlarms'`.

- [ ] **Step 1: Write the failing tests**

`tests/alarmparser_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'ResponseGuard', 'AlarmParser'] as $f) {
    require OPM_ROOT . "/src/OpManager/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\AlarmParser;
use GlpiPlugin\Opmanager\OpManager\ClientException;

// Shape captured on production 2026-09-30 (spec §14).
$row = [
    'severity' => "<img src='/images/devices/status3.gif'>&nbsp;Attention",
    'numericSeverity' => 3, 'deviceType' => '-', 'severityString' => 'Attention', 'statusStr' => 'Attention',
    'displayName' => 'VM-MSGIS-DBS', 'ipAddress' => '10.129.14.139', 'eventType' => 'Threshold Down',
    'message' => 'CPU Utilization is 85 %, threshold value for this monitor is 80 %',
    'deviceName' => '10.129.3.139.10000000001', 'statusNum' => 3, 'modTimeLong' => 1790739714008,
    'modTime' => '30 Sep 2026 10:41:54 AM ICT', 'alarmId' => '10000003244', 'category' => 'Server',
    'entity' => '10000024830', 'alarmcode' => 'THRESHOLD-DOWN_VMware-vmCPUUtilization', 'status' => 3, 'who' => 'Unacknowledged',
];
$rows = AlarmParser::parse(json_encode([$row, ['alarmId' => '', 'numericSeverity' => 1], ['alarmId' => '9', 'numericSeverity' => 0]]));
check('rows without alarmId or valid severity are skipped', count($rows) === 1);
$a = $rows[0];
check('keys mapped', $a['alarm_key'] === '10000003244' && $a['entity_key'] === '10000024830'
    && $a['device_name'] === '10.129.3.139.10000000001' && $a['display_name'] === 'VM-MSGIS-DBS'
    && $a['ip_address'] === '10.129.14.139' && $a['alarm_code'] === 'THRESHOLD-DOWN_VMware-vmCPUUtilization');
check('numeric severity used', $a['severity'] === 3 && $a['severity_label'] === 'Attention');
check('HTML severity field never copied', !str_contains(json_encode($a), '<img'));
check('mod_time from epoch ms', $a['mod_time'] === date('Y-m-d H:i:s', 1790739714));

$b = AlarmParser::parse(json_encode([['alarmId' => '7', 'severityString' => 'Critical']]))[0];
check('severity falls back to the label', $b['severity'] === 1 && $b['severity_label'] === 'Critical');
check('missing mod time is empty', $b['mod_time'] === '');

$threw = static function (callable $fn, string $needle): bool {
    try { $fn(); } catch (ClientException $e) { return str_contains($e->getMessage(), $needle); }
    return false;
};
check('error object surfaced', $threw(fn () => AlarmParser::parse('{"error":{"code":5000,"message":"Specified apiKey is not valid."}}'), 'OpManager error 5000'));
check('non-JSON rejected', $threw(fn () => AlarmParser::parse('<html>'), 'alarm list is not valid JSON'));
check('object without list rejected', $threw(fn () => AlarmParser::parse('{"x":1}'), 'alarm list has an unexpected shape'));
check('empty list is fine', AlarmParser::parse('[]') === []);

done();
```

In `tests/client_test.php`, make the transport-related changes below. Every other line in the file stays as it is.

1. Replace the `$transport = function (string $url, array $query) use (&$seen, $list) {` block with:

```php
$transport = function (string $url, array $query, array $headers) use (&$seen, $list) {
    $seen = [$url, $query, $headers];
    return ['status' => 200, 'body' => $list];
};
```

2. Replace the check `check('api key sent as query param', ($seen[1]['apiKey'] ?? null) === 'S3CRET');` with:

```php
check('api key sent as header', ($seen[2]['apiKey'] ?? null) === 'S3CRET');
check('api key never in the query string', !array_key_exists('apiKey', $seen[1]));
```

3. Before `check('unconfigured client refuses',`, add:

```php
require_once OPM_ROOT . '/src/OpManager/AlarmSource.php';
require_once OPM_ROOT . '/src/OpManager/AlarmParser.php';
$alarmJson = json_encode([
    ['alarmId' => '1', 'entity' => 'E1', 'numericSeverity' => 1, 'severityString' => 'Critical'],
    ['alarmId' => '2', 'entity' => 'E2', 'numericSeverity' => 5, 'severityString' => 'Clear'],
]);
$calls = [];
$client = new Client('https://opm', 'S3CRET', true, function (string $url, array $query, array $headers) use (&$calls, $alarmJson) {
    $calls[] = [$url, $query];
    return ['status' => 200, 'body' => $alarmJson];
});
check('active alarms use the ActiveAlarms filter', count($client->listActiveAlarms()) === 2
    && $calls[0] === ['https://opm/api/json/alarm/listAlarms', ['alertType' => 'ActiveAlarms']]);
$one = $client->alarmByEntity('E2');
check('alarm by entity filters on entity', $calls[1][1] === ['entity' => 'E2'] && ($one['alarm_key'] ?? null) === '2');
check('alarm by entity returns null when absent', $client->alarmByEntity('E9') === null);
```

In the same file:
- make the three other inline transports (`fn () => ['status' => 401, …]`, the throwing `function () { … }`, and the `$transport` passed to the unconfigured client) accept three parameters or none;
- change the file's `require` loop to `foreach (['ClientException', 'ResponseGuard', 'DeviceSource', 'DeviceParser', 'Client'] as $f)`.

PHP closures ignore extra arguments, so the zero-parameter ones already work.

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/php/php.exe tests/alarmparser_test.php; C:/php/php.exe tests/client_test.php`
Expected: first fatals on the missing `src/OpManager/ResponseGuard.php`; second fatals on the same.

- [ ] **Step 3: Write the implementation**

`src/OpManager/ResponseGuard.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * Shared handling of OpManager JSON responses. OpManager reports failures as
 * HTTP 200 with {"error":{"code","message"}} (spec §14), so every response goes
 * through decode() before anyone reads it.
 */
final class ResponseGuard
{
    private const WRAPPERS = ['rows', 'data', 'devices', 'alarms', 'result'];

    public static function decode(string $json, string $what): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ClientException("OpManager {$what} is not valid JSON");
        }
        if (isset($data['error'])) {
            throw new ClientException(self::errorMessage($data['error']));
        }
        return $data;
    }

    public static function unwrapList(array $data, string $what): array
    {
        foreach (self::WRAPPERS as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                $data = $data[$wrapper];
                break;
            }
        }
        if (!array_is_list($data)) {
            throw new ClientException("OpManager {$what} has an unexpected shape");
        }
        return $data;
    }

    /** @param string[] $keys */
    public static function first(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }

    public static function errorMessage(mixed $error): string
    {
        if (is_array($error)) {
            $code    = is_scalar($error['code'] ?? null) ? (string) $error['code'] : '?';
            $message = is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'no message';
            return sprintf('OpManager error %s: %s', $code, $message);
        }
        return 'OpManager error: ' . (is_scalar($error) ? (string) $error : 'unknown');
    }
}
```

`src/OpManager/DeviceParser.php` (replace the whole file):

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * Turns an OpManager device-list response into plain rows. Format verified on
 * production 2026-09-30 (933 devices, spec §14).
 */
final class DeviceParser
{
    /** @return list<array{device_key: string, name: string, display_name: string, ip_address: string, device_type: string, device_category: string, availability: string}> */
    public static function parse(string $json): array
    {
        $data = ResponseGuard::unwrapList(ResponseGuard::decode($json, 'device list'), 'device list');

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = ResponseGuard::first($row, ['deviceName', 'name', 'moid']);
            if ($key === '') {
                continue;
            }
            $out[] = [
                'device_key'      => $key,
                'name'            => ResponseGuard::first($row, ['deviceName', 'name']) ?: $key,
                'display_name'    => ResponseGuard::first($row, ['displayName', 'deviceName', 'name']),
                'ip_address'      => ResponseGuard::first($row, ['ipaddress', 'ipAddress', 'ip']),
                'device_type'     => ResponseGuard::first($row, ['type', 'deviceType']),
                'device_category' => ResponseGuard::first($row, ['category']),
                'availability'    => ResponseGuard::first($row, ['statusStr', 'status']),
            ];
        }
        return $out;
    }
}
```

`src/OpManager/AlarmParser.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * OpManager listAlarms response -> normalised rows. Field names verified on
 * production 2026-09-30 (spec §14). `severity` in the response is HTML and is
 * never copied; numericSeverity/severityString are used instead (D19).
 */
final class AlarmParser
{
    public const SEVERITY_LABELS = [1 => 'Critical', 2 => 'Trouble', 3 => 'Attention', 4 => 'Service Down', 5 => 'Clear'];

    /** @return list<array<string, mixed>> */
    public static function parse(string $json): array
    {
        $data = ResponseGuard::unwrapList(ResponseGuard::decode($json, 'alarm list'), 'alarm list');

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $alarm = self::row($row);
            if ($alarm !== null) {
                $out[] = $alarm;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function row(array $row): ?array
    {
        $key = ResponseGuard::first($row, ['alarmId']);
        if ($key === '') {
            return null;
        }
        $label    = ResponseGuard::first($row, ['severityString']);
        $severity = (int) ResponseGuard::first($row, ['numericSeverity']);
        if ($severity === 0 && $label !== '') {
            $severity = (int) (array_search($label, self::SEVERITY_LABELS, true) ?: 0);
        }
        if (!isset(self::SEVERITY_LABELS[$severity])) {
            return null;
        }
        $ms = ResponseGuard::first($row, ['modTimeLong']);

        return [
            'alarm_key'      => $key,
            'entity_key'     => ResponseGuard::first($row, ['entity']),
            'device_name'    => ResponseGuard::first($row, ['deviceName']),
            'display_name'   => ResponseGuard::first($row, ['displayName', 'deviceName']),
            'ip_address'     => ResponseGuard::first($row, ['ipAddress', 'ipaddress']),
            'category'       => ResponseGuard::first($row, ['category']),
            'alarm_code'     => ResponseGuard::first($row, ['alarmcode', 'alarmCode']),
            'event_type'     => ResponseGuard::first($row, ['eventType']),
            'severity'       => $severity,
            'severity_label' => $label !== '' ? $label : self::SEVERITY_LABELS[$severity],
            'message'        => ResponseGuard::first($row, ['message']),
            'mod_time'       => ctype_digit($ms) ? date('Y-m-d H:i:s', intdiv((int) $ms, 1000)) : '',
        ];
    }
}
```

`src/OpManager/AlarmSource.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

interface AlarmSource
{
    /** @return list<array<string, mixed>> alarms not yet cleared (alertType=ActiveAlarms) @throws ClientException */
    public function listActiveAlarms(): array;

    /** @return array<string, mixed>|null the alarm with this entity, whatever its severity @throws ClientException */
    public function alarmByEntity(string $entity): ?array;
}
```

`src/OpManager/Client.php` (replace the whole file):

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * The only place that talks HTTP to OpManager. The API key travels ONLY in the
 * `apiKey` header (query-parameter auth is deprecated since OpManager 12.8.100 and
 * listAlarms is header-only, spec §14). redact() still scrubs any message that
 * could embed a URL.
 */
final class Client implements DeviceSource, AlarmSource
{
    public const DEVICE_LIST_PATH = '/api/json/device/listDevices';
    public const ALARM_LIST_PATH  = '/api/json/alarm/listAlarms';

    /** @var callable(string, array<string, string>, array<string, string>): array{status: int, body: string} */
    private $transport;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private bool $verifySsl = true,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->guzzleTransport(...);
    }

    public function listDevices(): array
    {
        return DeviceParser::parse($this->get(self::DEVICE_LIST_PATH));
    }

    public function listActiveAlarms(): array
    {
        return AlarmParser::parse($this->get(self::ALARM_LIST_PATH, ['alertType' => 'ActiveAlarms']));
    }

    public function alarmByEntity(string $entity): ?array
    {
        foreach (AlarmParser::parse($this->get(self::ALARM_LIST_PATH, ['entity' => $entity])) as $alarm) {
            if ($alarm['entity_key'] === $entity) {
                return $alarm;
            }
        }
        return null;
    }

    public static function redact(string $text): string
    {
        return (string) preg_replace('/(apiKey=)[^&\s"\']+/i', '$1***', $text);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): string
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new ClientException('OpManager URL or API key is not configured');
        }
        try {
            $response = ($this->transport)(rtrim($this->baseUrl, '/') . $path, $query, ['apiKey' => $this->apiKey]);
        } catch (ClientException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ClientException(self::redact(sprintf('OpManager %s failed: %s', $path, $e->getMessage())));
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ClientException(sprintf('OpManager %s answered HTTP %d', $path, $response['status']));
        }
        return $response['body'];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function guzzleTransport(string $url, array $query, array $headers): array
    {
        $http = new \GuzzleHttp\Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => $this->verifySsl,
            'http_errors'     => false,
        ]);
        $response = $http->request('GET', $url, ['query' => $query, 'headers' => $headers]);
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/alarmparser_test.php && C:/php/php.exe tests/client_test.php && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (11 passed, 0 failed)`, then `ALL PASS` for the client suite (23 checks), then `SUITES OK`. The last command also runs `devicesync_test`, which requires only the interfaces.

- [ ] **Step 5: Prove the header test bites**

Temporarily change `['apiKey' => $this->apiKey]` in `get()` to `[]`, and move the key into the query array instead: `['apiKey' => $this->apiKey] + $query`. Confirm `FAIL api key sent as header` and `FAIL api key never in the query string`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/OpManager tests/alarmparser_test.php tests/client_test.php
git commit -m "feat: header-only apiKey and OpManager alarm parsing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Ticketing config keys and Setup section

**Files:**
- Modify: `src/Config.php`, `src/SuiteConfig.php`, `front/config.php`, `tests/config_test.php`, `tests/suiteconfig_test.php`

**Interfaces:**
- Produces:
  - `Config::SEVERITIES = [1 => 'Critical', 2 => 'Trouble', 3 => 'Attention', 4 => 'Service Down']` (the ticketable choices);
  - `Config::ALARM_SYNC_MINUTES = 10`;
  - `Config::DEFAULT_TITLE_PATTERN`, `Config::DEFAULT_CONTENT_PATTERN`;
  - new `DEFAULTS` keys: `ticket_severities` `'[1]'`, `ticket_template` `'0'`, `ticket_title_pattern`, `ticket_content_pattern`, `service_user` `'0'`, `ticket_entity_default` `'0'`, `ticket_use_ipnetwork_entity` `'1'`, `relink_window_hours` `'24'`, `storm_threshold` `'10'`, `storm_window_minutes` `'5'`, `last_alarm_sync` `''`, `last_alarm_sync_error` `''`;
  - `Config::sanitizeTicketing(array $post): array{values: array<string,string>, errors: list<string>}` (pure);
  - `Config::ticketingConfig(): array{ticket_severities: list<int>, relink_window_hours: int, storm_threshold: int, storm_window_minutes: int, service_user: int, template_id: int, title_pattern: string, content_pattern: string, entity_default: int, use_ipnetwork: bool}`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/config_test.php` before `done();`:

```php
// --- ticketing (Plan 2) ---
check('ticketable severities exclude Clear', array_keys(Config::SEVERITIES) === [1, 2, 3, 4]);
check('default ticket severity is Critical only (D11/D19)', json_decode(Config::DEFAULTS['ticket_severities'], true) === [1]);
check('alarm sync faster than device sync', Config::ALARM_SYNC_MINUTES < Config::DEVICE_SYNC_MINUTES);

$t = ['ticket_severities' => ['1', '2', '9'], 'ticket_template' => '3', 'ticket_title_pattern' => ' [OPM-{alarm_key}] {message} ',
      'ticket_content_pattern' => '{message}', 'service_user' => '42', 'ticket_entity_default' => '0',
      'ticket_use_ipnetwork_entity' => '1', 'relink_window_hours' => '24', 'storm_threshold' => '10', 'storm_window_minutes' => '5'];
$r = Config::sanitizeTicketing($t);
check('ticketing form valid', $r['errors'] === [], implode('; ', $r['errors']));
check('unknown severity dropped', json_decode($r['values']['ticket_severities'], true) === [1, 2]);
check('title trimmed', $r['values']['ticket_title_pattern'] === '[OPM-{alarm_key}] {message}');
check('ids stored as ints', $r['values']['service_user'] === '42' && $r['values']['ticket_template'] === '3');
$r = Config::sanitizeTicketing(['ticket_title_pattern' => '   '] + $t);
check('empty title rejected', !array_key_exists('ticket_title_pattern', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitizeTicketing(['storm_window_minutes' => '0'] + $t);
check('storm window 0 rejected', !array_key_exists('storm_window_minutes', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitizeTicketing(['storm_threshold' => '0'] + $t);
check('storm threshold 0 allowed (brake off)', $r['values']['storm_threshold'] === '0');
$r = Config::sanitizeTicketing(['ticket_severities' => []] + $t);
check('no severity = no tickets, allowed', $r['values']['ticket_severities'] === '[]');
$b = $t;
unset($b['ticket_use_ipnetwork_entity']);
check('unchecked ipnetwork box stores 0', Config::sanitizeTicketing($b)['values']['ticket_use_ipnetwork_entity'] === '0');
```

In `tests/suiteconfig_test.php`, add these stubs inside the first `namespace { }` block, after `class Dropdown { … }`:

```php
    class TicketTemplate { public static function dropdown($o = []) { echo "<select name='{$o['name']}'></select>"; } }
    class User { public static function dropdown($o = []) { echo "<select name='{$o['name']}'></select>"; } }
    class Entity { public static function dropdown($o = []) { echo "<select name='{$o['name']}'></select>"; } }
```

and before `done();` add:

```php
    foreach (['ticket_severities[]', 'ticket_template', 'service_user', 'ticket_entity_default', 'ticket_title_pattern', 'ticket_content_pattern', 'relink_window_hours', 'storm_threshold', 'storm_window_minutes'] as $field) {
        check("ticketing field {$field} rendered", str_contains($html, "name='{$field}'") || str_contains($html, 'name="' . $field . '"'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/php/php.exe tests/config_test.php; C:/php/php.exe tests/suiteconfig_test.php`
Expected: config fatals on `Undefined constant …Config::SEVERITIES`; suiteconfig prints `FAIL ticketing field … rendered` lines.

- [ ] **Step 3: Write the implementation**

In `src/Config.php`:

(a) Below `public const DEVICE_SYNC_MINUTES = 60;` add:

```php
    /** AlarmSync frequency (spec §3). */
    public const ALARM_SYNC_MINUTES = 10;

    /** OpManager numericSeverity values a ticket may be created for (D19); 5 = Clear never is. */
    public const SEVERITIES = [1 => 'Critical', 2 => 'Trouble', 3 => 'Attention', 4 => 'Service Down'];

    public const DEFAULT_TITLE_PATTERN = '[OPM-{alarm_key}] {severity}: {message} - {device}';

    public const DEFAULT_CONTENT_PATTERN = "OpManager alarm {alarm_key}\nDevice: {device} ({ip})\nSeverity: {severity}\nAlarm type: {alarm_type}\nTime: {time}\n\n{message}";
```

(b) In `DEFAULTS`, after `'last_device_sync_error' => '',` add:

```php
        'ticket_severities'           => '[1]',
        'ticket_template'             => '0',
        'ticket_title_pattern'        => self::DEFAULT_TITLE_PATTERN,
        'ticket_content_pattern'      => self::DEFAULT_CONTENT_PATTERN,
        'service_user'                => '0',
        'ticket_entity_default'       => '0',
        'ticket_use_ipnetwork_entity' => '1',
        'relink_window_hours'         => '24',
        'storm_threshold'             => '10',
        'storm_window_minutes'        => '5',
        'last_alarm_sync'             => '',
        'last_alarm_sync_error'       => '',
```

(c) Add before `filterItemtypes()`:

```php
    /**
     * Validate the ticketing part of the Setup form. Pure; a key missing from
     * `values` means "keep what is stored".
     *
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitizeTicketing(array $post): array
    {
        $values = [];
        $errors = [];

        $sev = array_values(array_intersect(
            array_map('intval', (array) ($post['ticket_severities'] ?? [])),
            array_keys(self::SEVERITIES)
        ));
        sort($sev);
        $values['ticket_severities'] = (string) json_encode($sev);

        foreach (['ticket_template', 'service_user', 'ticket_entity_default'] as $key) {
            $values[$key] = (string) max(0, (int) ($post[$key] ?? 0));
        }

        foreach (['ticket_title_pattern' => 250, 'ticket_content_pattern' => 5000] as $key => $max) {
            $v = trim((string) ($post[$key] ?? ''));
            if ($v === '' || strlen($v) > $max) {
                $errors[] = sprintf(__('%s must be 1 to %d characters', 'opmanager'), $key, $max);
            } else {
                $values[$key] = $v;
            }
        }

        $values['ticket_use_ipnetwork_entity'] = empty($post['ticket_use_ipnetwork_entity']) ? '0' : '1';

        foreach (['relink_window_hours' => [0, 720], 'storm_threshold' => [0, 1000], 'storm_window_minutes' => [1, 120]] as $key => [$min, $max]) {
            $n = (int) ($post[$key] ?? -1);
            if ($n < $min || $n > $max) {
                $errors[] = sprintf(__('%s must be between %d and %d', 'opmanager'), $key, $min, $max);
            } else {
                $values[$key] = (string) $n;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** @return array{ticket_severities: list<int>, relink_window_hours: int, storm_threshold: int, storm_window_minutes: int, service_user: int, template_id: int, title_pattern: string, content_pattern: string, entity_default: int, use_ipnetwork: bool} */
    public static function ticketingConfig(): array
    {
        $sev = json_decode(self::get('ticket_severities'), true);
        return [
            'ticket_severities'    => is_array($sev) ? array_values(array_map('intval', $sev)) : [1],
            'relink_window_hours'  => (int) self::get('relink_window_hours'),
            'storm_threshold'      => (int) self::get('storm_threshold'),
            'storm_window_minutes' => max(1, (int) self::get('storm_window_minutes')),
            'service_user'         => (int) self::get('service_user'),
            'template_id'          => (int) self::get('ticket_template'),
            'title_pattern'        => self::get('ticket_title_pattern'),
            'content_pattern'      => self::get('ticket_content_pattern'),
            'entity_default'       => (int) self::get('ticket_entity_default'),
            'use_ipnetwork'        => self::get('ticket_use_ipnetwork_entity') === '1',
        ];
    }
```

In `src/SuiteConfig.php`, directly before the line `$last  = Config::get('last_device_sync');`, insert:

```php
        echo '<fieldset class="mb-3"><legend class="fs-4">' . __s('Tickets from alarms', 'opmanager') . '</legend>';
        echo '<div>' . __s('Create a ticket for these OpManager severities', 'opmanager') . '</div>';
        $sev_selected = json_decode(Config::get('ticket_severities'), true) ?: [];
        foreach (Config::SEVERITIES as $num => $label) {
            $id = 'opm-sev-' . $num . $sfx;
            echo sprintf(
                '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="ticket_severities[]" value="%d" id="%s"%s%s><label class="form-check-label" for="%s">%s</label></div>',
                $num,
                htmlescape($id),
                in_array($num, $sev_selected, true) ? ' checked' : '',
                $disabled,
                htmlescape($id),
                htmlescape($label)
            );
        }
        echo '<div class="mt-2"><label class="form-label">' . __s('Ticket template', 'opmanager') . '</label>';
        \TicketTemplate::dropdown([
            'name'                => 'ticket_template',
            'value'               => (int) Config::get('ticket_template'),
            'display_emptychoice' => true,
            'emptylabel'          => __('Default GLPI template', 'opmanager'),
            'readonly'            => !$canedit,
        ]);
        echo '</div>';
        echo '<div class="mt-2"><label class="form-label">' . __s('GLPI user that creates the tickets (service account)', 'opmanager') . '</label>';
        \User::dropdown(['name' => 'service_user', 'value' => (int) Config::get('service_user'), 'right' => 'all', 'readonly' => !$canedit]);
        echo '</div>';
        echo '<div class="mt-2"><label class="form-label">' . __s('Default entity (no asset and no matching IP network)', 'opmanager') . '</label>';
        \Entity::dropdown(['name' => 'ticket_entity_default', 'value' => (int) Config::get('ticket_entity_default'), 'readonly' => !$canedit]);
        echo '</div>';
        echo '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="ticket_use_ipnetwork_entity" value="1" id="opm-ipnet' . $sfx . '"'
            . (Config::get('ticket_use_ipnetwork_entity') === '1' ? ' checked' : '') . $disabled . '>';
        echo '<label class="form-check-label" for="opm-ipnet' . $sfx . '">' . __s('Use the entity of the GLPI IP network containing the device IP', 'opmanager') . '</label></div>';
        echo self::textRow('ticket_title_pattern' . $sfx, 'ticket_title_pattern', __('Ticket title pattern', 'opmanager'), Config::get('ticket_title_pattern'), 'text', $disabled);
        echo '<div class="mb-2"><label class="form-label" for="ticket_content_pattern' . $sfx . '">' . __s('Ticket description pattern', 'opmanager') . '</label>'
            . '<textarea class="form-control" rows="6" id="ticket_content_pattern' . $sfx . '" name="ticket_content_pattern"' . $disabled . '>' . htmlescape(Config::get('ticket_content_pattern')) . '</textarea>'
            . '<div class="form-hint">' . __s('Variables: {alarm_key} {severity} {message} {device} {ip} {alarm_type} {time}', 'opmanager') . '</div></div>';
        echo self::textRow('relink_window_hours' . $sfx, 'relink_window_hours', __('Link a repeat of the same alarm type on the same device within (hours)', 'opmanager'), Config::get('relink_window_hours'), 'number', $disabled);
        echo self::textRow('storm_threshold' . $sfx, 'storm_threshold', __('Alarm storm: new tickets allowed per window before grouping (0 = off)', 'opmanager'), Config::get('storm_threshold'), 'number', $disabled);
        echo self::textRow('storm_window_minutes' . $sfx, 'storm_window_minutes', __('Alarm storm window (minutes)', 'opmanager'), Config::get('storm_window_minutes'), 'number', $disabled);
        $alast  = Config::get('last_alarm_sync');
        $aerror = Config::get('last_alarm_sync_error');
        echo '<div class="mt-2">' . __s('Last successful alarm sync', 'opmanager') . ': ' . htmlescape($alast !== '' ? $alast : __('never', 'opmanager')) . '</div>';
        if ($aerror !== '') {
            echo '<div class="alert alert-warning">' . __s('Last alarm sync error', 'opmanager') . ': ' . htmlescape($aerror) . '</div>';
        }
        echo '</fieldset>';
```

In `front/config.php`, replace:

```php
    $result = Config::sanitize($_POST, array_keys(Config::offeredItemtypes()));
```

with:

```php
    $result  = Config::sanitize($_POST, array_keys(Config::offeredItemtypes()));
    $tickets = Config::sanitizeTicketing($_POST);
    $result  = [
        'values' => $result['values'] + $tickets['values'],
        'errors' => array_merge($result['errors'], $tickets['errors']),
    ];
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/config_test.php && C:/php/php.exe tests/suiteconfig_test.php && C:/php/php.exe -l front/config.php`
Expected: `ALL PASS` for both suites (config 37 checks, suiteconfig 20), and `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Config.php src/SuiteConfig.php front/config.php tests/config_test.php tests/suiteconfig_test.php
git commit -m "feat: ticketing settings (severities, template, service user, entity, storm)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Pure entity resolution and ticket input builder

**Files:**
- Create: `src/Ticketing/EntityResolver.php`, `src/Ticketing/TicketBuilder.php`, `tests/ticketbuilder_test.php`

**Interfaces:**
- Produces:
  - `EntityResolver::resolve(?int $asset_entity, string $ip, array $networks, bool $use_networks, int $default): int`, where `$networks` is `list<array{entities_id: int|string, address: string, netmask: string}>` (IPv4 only).
  - `TicketBuilder::TYPE_INCIDENT = 1`, `TicketBuilder::URGENCY = [1 => 5, 2 => 4, 3 => 3, 4 => 4]`.
  - `TicketBuilder::build(array $alarm, array $asset, int $entities_id, array $predefined, int $template_id, array $cfg): array`, where `$asset` is `array{itemtype: string, items_id: int}` and `$cfg` has `title_pattern`, `content_pattern`, `service_user`.
  - `TicketBuilder::vars(array $alarm): array<string,string>`.
  - `TicketBuilder::render(string $pattern, array $vars, bool $html): string`.

- [ ] **Step 1: Write the failing test**

`tests/ticketbuilder_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/EntityResolver.php';
require OPM_ROOT . '/src/Ticketing/TicketBuilder.php';

use GlpiPlugin\Opmanager\Ticketing\EntityResolver;
use GlpiPlugin\Opmanager\Ticketing\TicketBuilder;

$nets = [
    ['entities_id' => 3, 'address' => '10.129.0.0', 'netmask' => '255.255.0.0'],
    ['entities_id' => 7, 'address' => '10.129.14.0', 'netmask' => '255.255.255.0'],
    ['entities_id' => 9, 'address' => 'bogus', 'netmask' => '255.255.255.0'],
];
check('asset entity wins', EntityResolver::resolve(5, '10.129.14.139', $nets, true, 1) === 5);
check('most specific IP network wins', EntityResolver::resolve(null, '10.129.14.139', $nets, true, 1) === 7);
check('wider network when only it contains the IP', EntityResolver::resolve(null, '10.129.99.1', $nets, true, 1) === 3);
check('no asset, no network → default entity', EntityResolver::resolve(null, '192.168.1.1', $nets, true, 1) === 1);
check('network step can be switched off', EntityResolver::resolve(null, '10.129.14.139', $nets, false, 1) === 1);
check('bad IP → default', EntityResolver::resolve(null, 'not-an-ip', $nets, true, 1) === 1);

$alarm = [
    'alarm_key' => '10000003244', 'entity_key' => 'E', 'device_name' => '10.129.3.139.10000000001', 'display_name' => 'VM-MSGIS-DBS',
    'ip_address' => '10.129.14.139', 'category' => 'Server', 'alarm_code' => 'THRESHOLD-DOWN_VMware-vmCPUUtilization',
    'event_type' => 'Threshold Down', 'severity' => 1, 'severity_label' => 'Critical',
    'message' => 'CPU <b>85</b> %', 'mod_time' => '2026-09-30 10:41:54',
];
$cfg = ['title_pattern' => '[OPM-{alarm_key}] {severity}: {message} - {device}', 'content_pattern' => "Device: {device}\n{message}", 'service_user' => 42];
$asset = ['itemtype' => 'Computer', 'items_id' => 1529];

$in = TicketBuilder::build($alarm, $asset, 7, [], 3, $cfg);
check('title from pattern', $in['name'] === '[OPM-10000003244] Critical: CPU <b>85</b> % - VM-MSGIS-DBS');
check('content escaped and line breaks kept', $in['content'] === "Device: VM-MSGIS-DBS<br />\nCPU &lt;b&gt;85&lt;/b&gt; %");
check('entity set', $in['entities_id'] === 7);
check('incident by default', $in['type'] === TicketBuilder::TYPE_INCIDENT);
check('urgency from severity when template has none', $in['urgency'] === 5);
check('service user is requester and recipient', $in['_users_id_requester'] === 42 && $in['users_id_recipient'] === 42);
check('asset linked', $in['items_id'] === ['Computer' => [1529]]);
check('template id passed for mandatory checks', $in['_tickettemplate'] === 3);

$pre = ['urgency' => 2, 'itilcategories_id' => 11, '_users_id_requester' => 99, 'name' => 'template title', 'type' => 2];
$in = TicketBuilder::build($alarm, ['itemtype' => '', 'items_id' => 0], 7, $pre, 3, $cfg);
check('template urgency kept', $in['urgency'] === 2);
check('template category kept', $in['itilcategories_id'] === 11);
check('template requester kept', $in['_users_id_requester'] === 99);
check('template type kept', $in['type'] === 2);
check('our title overrides template title', str_starts_with($in['name'], '[OPM-10000003244]'));
check('no asset → no items_id', !array_key_exists('items_id', $in));

$long = TicketBuilder::render(str_repeat('x', 300), [], false);
check('title capped at 250 chars', strlen($long) === 250);
check('device falls back to device_name', TicketBuilder::vars(['display_name' => ''] + $alarm)['{device}'] === '10.129.3.139.10000000001');

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/ticketbuilder_test.php`
Expected: fatal on the missing `src/Ticketing/EntityResolver.php`.

- [ ] **Step 3: Write the implementation**

`src/Ticketing/EntityResolver.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** Pure D14: asset entity -> most specific IPv4 GLPI IP network -> default. */
final class EntityResolver
{
    /** @param list<array{entities_id: int|string, address: string, netmask: string}> $networks */
    public static function resolve(?int $asset_entity, string $ip, array $networks, bool $use_networks, int $default): int
    {
        if ($asset_entity !== null) {
            return $asset_entity;
        }
        if ($use_networks && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $addr     = ip2long($ip);
            $best     = null;
            $best_len = -1;
            foreach ($networks as $net) {
                $n = ip2long((string) $net['address']);
                $m = ip2long((string) $net['netmask']);
                if ($n === false || $m === false || ($addr & $m) !== ($n & $m)) {
                    continue;
                }
                $len = substr_count(decbin($m & 0xFFFFFFFF), '1');
                if ($len > $best_len) {
                    $best_len = $len;
                    $best     = (int) $net['entities_id'];
                }
            }
            if ($best !== null) {
                return $best;
            }
        }
        return $default;
    }
}
```

`src/Ticketing/TicketBuilder.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Pure ticket input (D18). Template predefined fields are the base; the plugin's
 * title/content patterns, entity, asset link and template id are laid on top;
 * urgency and requester only fill what the template leaves open.
 */
final class TicketBuilder
{
    public const TYPE_INCIDENT = 1;

    /** OpManager numericSeverity => GLPI urgency (5 very high .. 1 very low), D19. */
    public const URGENCY = [1 => 5, 2 => 4, 3 => 3, 4 => 4];

    /**
     * @param array<string, mixed>              $alarm
     * @param array{itemtype: string, items_id: int} $asset
     * @param array<string, mixed>              $predefined
     * @param array{title_pattern: string, content_pattern: string, service_user: int} $cfg
     * @return array<string, mixed>
     */
    public static function build(array $alarm, array $asset, int $entities_id, array $predefined, int $template_id, array $cfg): array
    {
        $vars  = self::vars($alarm);
        $input = $predefined;

        $input['name']        = self::render($cfg['title_pattern'], $vars, false);
        $input['content']     = self::render($cfg['content_pattern'], $vars, true);
        $input['entities_id'] = $entities_id;
        $input['type']        = $predefined['type'] ?? self::TYPE_INCIDENT;
        if (!isset($predefined['urgency'])) {
            $input['urgency'] = self::URGENCY[(int) $alarm['severity']] ?? 3;
        }
        if ((int) $cfg['service_user'] > 0) {
            if (!isset($predefined['_users_id_requester'])) {
                $input['_users_id_requester'] = (int) $cfg['service_user'];
            }
            $input['users_id_recipient'] = (int) $cfg['service_user'];
        }
        if ($asset['items_id'] > 0 && $asset['itemtype'] !== '') {
            $input['items_id'] = [$asset['itemtype'] => [$asset['items_id']]];
        }
        $input['_tickettemplate'] = $template_id;

        return $input;
    }

    /** @return array<string, string> */
    public static function vars(array $alarm): array
    {
        return [
            '{alarm_key}'  => (string) ($alarm['alarm_key'] ?? ''),
            '{severity}'   => (string) ($alarm['severity_label'] ?? ''),
            '{message}'    => (string) ($alarm['message'] ?? ''),
            '{device}'     => (string) (($alarm['display_name'] ?? '') !== '' ? $alarm['display_name'] : ($alarm['device_name'] ?? '')),
            '{ip}'         => (string) ($alarm['ip_address'] ?? ''),
            '{alarm_type}' => (string) ($alarm['alarm_code'] ?? ''),
            '{time}'       => (string) ($alarm['mod_time'] ?? ''),
        ];
    }

    /** @param array<string, string> $vars */
    public static function render(string $pattern, array $vars, bool $html): string
    {
        $text = strtr($pattern, $vars);
        if ($html) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES));
        }
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 250) : substr($text, 0, 250);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/ticketbuilder_test.php`
Expected: `ALL PASS (22 passed, 0 failed)`.

- [ ] **Step 5: Commit**

```bash
git add src/Ticketing/EntityResolver.php src/Ticketing/TicketBuilder.php tests/ticketbuilder_test.php
git commit -m "feat: pure ticket entity resolution and template-based ticket input

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Alarm → asset resolution

**Files:**
- Create: `src/Ticketing/DeviceIndex.php`, `src/Ticketing/DbDeviceIndex.php`, `src/Ticketing/AssetResolver.php`, `src/Ticketing/AlarmAssetResolver.php`, `tests/alarmasset_test.php`

**Interfaces:**
- Consumes: `Matching\Matcher::match()` (Plan 1).
- Produces:
  - `interface DeviceIndex { public function find(string $device_key): ?array; }`. It returns `array{id: int, itemtype: string, items_id: int, match_state: string}`.
  - `interface AssetResolver { public function resolve(array $alarm): array; }`. It returns `array{devices_id: int, itemtype: string, items_id: int}`.
  - `AlarmAssetResolver::__construct(DeviceIndex $devices, Matcher $matcher, string $mode)`.

- [ ] **Step 1: Write the failing test**

`tests/alarmasset_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
foreach (['Matching/AssetLookup', 'Matching/Matcher', 'Ticketing/DeviceIndex', 'Ticketing/AssetResolver', 'Ticketing/AlarmAssetResolver'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\Matching\AssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Opmanager\Ticketing\DeviceIndex;

final class FakeIndex implements DeviceIndex
{
    public function __construct(private array $rows) {}
    public function find(string $device_key): ?array { return $this->rows[$device_key] ?? null; }
}
final class FakeLookup implements AssetLookup
{
    public function byName(string $name): array { return $name === 'VM-MSGIS-DBS' ? [['itemtype' => 'Computer', 'items_id' => 77]] : []; }
    public function byIp(string $ip): array { return []; }
}

$index = new FakeIndex([
    'core-sw' => ['id' => 5, 'itemtype' => 'NetworkEquipment', 'items_id' => 10, 'match_state' => 'matched'],
    'lonely'  => ['id' => 6, 'itemtype' => '', 'items_id' => 0, 'match_state' => 'unmatched'],
]);
$r = new AlarmAssetResolver($index, new Matcher(new FakeLookup()), 'hybrid_name');
$alarm = static fn (string $dev, string $disp, string $ip) => ['device_name' => $dev, 'display_name' => $disp, 'ip_address' => $ip];

check('matched device row wins', $r->resolve($alarm('core-sw', 'x', '1.1.1.1')) === ['devices_id' => 5, 'itemtype' => 'NetworkEquipment', 'items_id' => 10]);
check('alarm device not in list falls back to matcher', $r->resolve($alarm('10.129.3.139.10000000001', 'VM-MSGIS-DBS', '10.129.14.139'))
    === ['devices_id' => 0, 'itemtype' => 'Computer', 'items_id' => 77]);
check('unmatched device row still tries the matcher, keeps device id', $r->resolve($alarm('lonely', 'VM-MSGIS-DBS', ''))
    === ['devices_id' => 6, 'itemtype' => 'Computer', 'items_id' => 77]);
check('nothing found → no asset', $r->resolve($alarm('ghost', 'ghost', '')) === ['devices_id' => 0, 'itemtype' => '', 'items_id' => 0]);

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/alarmasset_test.php`
Expected: fatal on the missing `src/Ticketing/DeviceIndex.php`.

- [ ] **Step 3: Write the implementation**

`src/Ticketing/DeviceIndex.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface DeviceIndex
{
    /** @return array{id: int, itemtype: string, items_id: int, match_state: string}|null */
    public function find(string $device_key): ?array;
}
```

`src/Ticketing/DbDeviceIndex.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use GlpiPlugin\Opmanager\Device;

final class DbDeviceIndex implements DeviceIndex
{
    public function find(string $device_key): ?array
    {
        global $DB;

        if ($device_key === '') {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id', 'match_state'],
            'FROM'   => Device::getTable(),
            'WHERE'  => ['device_key' => $device_key],
            'LIMIT'  => 1,
        ])->current();

        return $row ? [
            'id'          => (int) $row['id'],
            'itemtype'    => (string) $row['itemtype'],
            'items_id'    => (int) $row['items_id'],
            'match_state' => (string) $row['match_state'],
        ] : null;
    }
}
```

`src/Ticketing/AssetResolver.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface AssetResolver
{
    /** @return array{devices_id: int, itemtype: string, items_id: int} */
    public function resolve(array $alarm): array;
}
```

`src/Ticketing/AlarmAssetResolver.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use GlpiPlugin\Opmanager\Matching\Matcher;

/**
 * An alarm's deviceName is not always in the device list (a VM under a VMware
 * host, spec §14). The paired device wins; otherwise the alarm's own display name
 * and IP go through the same Matcher rules as devices.
 */
final class AlarmAssetResolver implements AssetResolver
{
    public function __construct(private DeviceIndex $devices, private Matcher $matcher, private string $mode)
    {
    }

    public function resolve(array $alarm): array
    {
        $device = $this->devices->find((string) ($alarm['device_name'] ?? ''));
        if ($device !== null && $device['match_state'] === Matcher::MATCHED) {
            return ['devices_id' => $device['id'], 'itemtype' => $device['itemtype'], 'items_id' => $device['items_id']];
        }
        $match = $this->matcher->match((string) ($alarm['display_name'] ?? ''), (string) ($alarm['ip_address'] ?? ''), $this->mode);
        $ok    = $match['state'] === Matcher::MATCHED;
        return [
            'devices_id' => $device['id'] ?? 0,
            'itemtype'   => $ok ? $match['itemtype'] : '',
            'items_id'   => $ok ? $match['items_id'] : 0,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/alarmasset_test.php && C:/php/php.exe -l src/Ticketing/DbDeviceIndex.php`
Expected: `ALL PASS (4 passed, 0 failed)`; `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Ticketing/DeviceIndex.php src/Ticketing/DbDeviceIndex.php src/Ticketing/AssetResolver.php src/Ticketing/AlarmAssetResolver.php tests/alarmasset_test.php
git commit -m "feat: resolve an alarm to a GLPI asset via device pairing or matcher

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Pure decision and messages

**Files:**
- Create: `src/Ticketing/Decision.php`, `src/Ticketing/Messages.php`, `tests/decision_test.php`

**Interfaces:**
- Produces:
  - `Decision::decide(array $c): array{action: string, tickets_id: int}`. The context `$c` is `array{severity: int, message: string, is_clear: bool, existing: ?array{tickets_id: int, severity: int, message: string, state: string}, ticket_status: ?int, ticketable: bool, device_open_ticket: int, storm_ticket: int, storm_should_start: bool}`.
  - Actions: `Decision::NONE='none'`, `STORE='store'`, `FOLLOWUP='followup'`, `CLEAR='clear'`, `REOPEN='reopen'`, `JOIN_DEVICE='join_device'`, `JOIN_STORM='join_storm'`, `START_STORM='start_storm'`, `CREATE='create'`.
  - `Decision::shouldStartStorm(int $own_created_in_window, int $threshold): bool`.
  - `Messages::changed(array $alarm)`, `cleared(array $alarm)`, `solved()`, `reopened(array $alarm)`, `joined(array $alarm)`, `related(int $old_tickets_id)`, `stormTitle(string $now)`. Each returns a plain-text `string`.

- [ ] **Step 1: Write the failing test**

`tests/decision_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/Decision.php';
require OPM_ROOT . '/src/Ticketing/Messages.php';

use GlpiPlugin\Opmanager\Ticketing\Decision as D;
use GlpiPlugin\Opmanager\Ticketing\Messages;

$base = ['severity' => 1, 'message' => 'Device down', 'is_clear' => false, 'existing' => null, 'ticket_status' => null,
         'ticketable' => true, 'device_open_ticket' => 0, 'storm_ticket' => 0, 'storm_should_start' => false];
$ex = static fn (int $tid, string $state, int $sev = 1, string $msg = 'Device down') => ['tickets_id' => $tid, 'severity' => $sev, 'message' => $msg, 'state' => $state];
$act = static fn (array $c) => D::decide($c + $GLOBALS['base']);

check('new ticketable alarm creates', $act([]) === ['action' => 'create', 'tickets_id' => 0]);
check('not ticketable is stored only', $act(['ticketable' => false])['action'] === 'store');
check('new alarm already clear is stored only', $act(['is_clear' => true, 'severity' => 5])['action'] === 'store');
check('device open ticket → join', $act(['device_open_ticket' => 55]) === ['action' => 'join_device', 'tickets_id' => 55]);
check('active storm → join storm', $act(['storm_ticket' => 66]) === ['action' => 'join_storm', 'tickets_id' => 66]);
check('device join beats storm', $act(['device_open_ticket' => 55, 'storm_ticket' => 66])['action'] === 'join_device');
check('storm threshold reached → start storm', $act(['storm_should_start' => true])['action'] === 'start_storm');

check('unchanged alarm does nothing', $act(['existing' => $ex(9, 'active'), 'ticket_status' => 2]) === ['action' => 'none', 'tickets_id' => 9]);
check('message change is a followup', $act(['message' => 'Device down again', 'existing' => $ex(9, 'active'), 'ticket_status' => 2])['action'] === 'followup');
check('severity drop is a followup', $act(['severity' => 3, 'ticketable' => false, 'existing' => $ex(9, 'active'), 'ticket_status' => 2])['action'] === 'followup');
check('clear on ticketed alarm → clear', $act(['is_clear' => true, 'severity' => 5, 'existing' => $ex(9, 'active'), 'ticket_status' => 2]) === ['action' => 'clear', 'tickets_id' => 9]);
check('clear seen twice → nothing', $act(['is_clear' => true, 'severity' => 5, 'existing' => $ex(9, 'cleared'), 'ticket_status' => 5])['action'] === 'none');
check('same alarm id active again on solved ticket → reopen', $act(['existing' => $ex(9, 'cleared'), 'ticket_status' => 5])['action'] === 'reopen');
check('same alarm id active again on closed ticket → reopen', $act(['existing' => $ex(9, 'cleared'), 'ticket_status' => 6])['action'] === 'reopen');
check('active again, ticket still open → followup', $act(['existing' => $ex(9, 'cleared'), 'ticket_status' => 2])['action'] === 'followup');
check('technician solved while alarm active → no reopen', $act(['existing' => $ex(9, 'active'), 'ticket_status' => 5])['action'] === 'none');
check('severity rise creates ticket', $act(['existing' => $ex(0, 'active', 3)])['action'] === 'create');
check('pending (no ticket yet) is retried as create', $act(['existing' => $ex(0, 'active')])['action'] === 'create');

check('storm starts at threshold', D::shouldStartStorm(10, 10) && !D::shouldStartStorm(9, 10));
check('storm brake off at 0', !D::shouldStartStorm(500, 0));

$alarm = ['alarm_key' => '1', 'severity_label' => 'Critical', 'message' => 'Device down', 'display_name' => 'sw1', 'device_name' => 'sw1', 'mod_time' => '2026-09-30 10:00:00'];
check('messages mention the alarm id', str_contains(Messages::cleared($alarm), '1') && str_contains(Messages::reopened($alarm), '1'));
check('related message names the old ticket', str_contains(Messages::related(123), '#123'));

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/decision_test.php`
Expected: fatal on the missing `src/Ticketing/Decision.php`.

- [ ] **Step 3: Write the implementation**

`src/Ticketing/Decision.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Pure decision for one alarm (spec §7, D3/D6/D12/D20). Rules, first match wins:
 * an alarm that already has a ticket only ever talks to that ticket; an alarm
 * without one may create, join its device's open ticket, or join/start a storm.
 */
final class Decision
{
    public const NONE        = 'none';
    public const STORE       = 'store';
    public const FOLLOWUP    = 'followup';
    public const CLEAR       = 'clear';
    public const REOPEN      = 'reopen';
    public const JOIN_DEVICE = 'join_device';
    public const JOIN_STORM  = 'join_storm';
    public const START_STORM = 'start_storm';
    public const CREATE      = 'create';

    private const SOLVED = 5;
    private const CLOSED = 6;

    /** @return array{action: string, tickets_id: int} */
    public static function decide(array $c): array
    {
        $existing = $c['existing'];
        $tid      = (int) ($existing['tickets_id'] ?? 0);

        if ($tid > 0) {
            $was_cleared = $existing['state'] === 'cleared';
            if ($c['is_clear']) {
                return self::out($was_cleared ? self::NONE : self::CLEAR, $tid);
            }
            if ($was_cleared) {
                $closed = in_array($c['ticket_status'], [self::SOLVED, self::CLOSED], true);
                return self::out($closed ? self::REOPEN : self::FOLLOWUP, $tid);
            }
            $changed = $c['severity'] !== (int) $existing['severity'] || $c['message'] !== (string) $existing['message'];
            return self::out($changed ? self::FOLLOWUP : self::NONE, $tid);
        }

        if ($c['is_clear'] || !$c['ticketable']) {
            return self::out(self::STORE, 0);
        }
        if ($c['device_open_ticket'] > 0) {
            return self::out(self::JOIN_DEVICE, $c['device_open_ticket']);
        }
        if ($c['storm_ticket'] > 0) {
            return self::out(self::JOIN_STORM, $c['storm_ticket']);
        }
        if ($c['storm_should_start']) {
            return self::out(self::START_STORM, 0);
        }
        return self::out(self::CREATE, 0);
    }

    public static function shouldStartStorm(int $own_created_in_window, int $threshold): bool
    {
        return $threshold > 0 && $own_created_in_window >= $threshold;
    }

    /** @return array{action: string, tickets_id: int} */
    private static function out(string $action, int $tid): array
    {
        return ['action' => $action, 'tickets_id' => $tid];
    }
}
```

`src/Ticketing/Messages.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** Plain-text followup / solution texts. The gateway escapes them into HTML. */
final class Messages
{
    public static function changed(array $a): string
    {
        return sprintf("OpManager alarm %s changed: %s - %s", $a['alarm_key'], $a['severity_label'], $a['message']);
    }

    public static function cleared(array $a): string
    {
        return sprintf("OpManager alarm %s on %s is Clear.", $a['alarm_key'], self::device($a));
    }

    public static function solved(): string
    {
        return 'All OpManager alarms on this ticket are Clear.';
    }

    public static function reopened(array $a): string
    {
        return sprintf("OpManager alarm %s on %s is active again: %s - %s", $a['alarm_key'], self::device($a), $a['severity_label'], $a['message']);
    }

    public static function joined(array $a): string
    {
        return sprintf("Another OpManager alarm on %s: %s (alarm %s) - %s", self::device($a), $a['severity_label'], $a['alarm_key'], $a['message']);
    }

    public static function related(int $old): string
    {
        return sprintf('A similar alarm on this device was already handled in ticket #%d.', $old);
    }

    public static function stormTitle(string $now): string
    {
        return sprintf('[OPM] Alarm storm since %s', $now);
    }

    private static function device(array $a): string
    {
        return (string) (($a['display_name'] ?? '') !== '' ? $a['display_name'] : ($a['device_name'] ?? ''));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/decision_test.php`
Expected: `ALL PASS (22 passed, 0 failed)`.

- [ ] **Step 5: Prove the reopen and no-spam tests bite**

Temporarily change `if ($was_cleared) {` to `if (false) {`. Confirm that `FAIL same alarm id active again on solved ticket → reopen` fails. Revert. Then temporarily change `$changed = … ;` to `$changed = true;`. Confirm `FAIL unchanged alarm does nothing`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/Ticketing/Decision.php src/Ticketing/Messages.php tests/decision_test.php
git commit -m "feat: pure per-alarm ticket decision and followup texts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: AlarmSync orchestration (pure, with ports)

**Files:**
- Create: `src/Ticketing/AlarmStore.php`, `src/Ticketing/TicketGateway.php`, `src/Ticketing/AlarmSync.php`, `tests/alarmsync_test.php`

**Interfaces:**
- Consumes: `AlarmSource` (T1), `AssetResolver` (T4), `Decision`, `Messages` (T5), `ClientException`.
- Produces:
  - `interface AlarmStore`, with these methods:
    - `find(string $alarm_key): ?array{id: int, tickets_id: int, severity: int, message: string, state: string}`
    - `save(array $alarm, array $asset, string $state, string $now): int`. It returns the row id, keeps `tickets_id`, and sets `cleared_at` when the state becomes `cleared`.
    - `setTicket(int $id, int $tickets_id, string $role, string $now): void`, with role `'own'|'joined'|'storm'`. It clears `ticket_pending`.
    - `markPending(int $id): void`
    - `activeNotSeen(array $alarm_keys): list<array{id: int, alarm_key: string, entity_key: string}>`
    - `ticketsForDevice(string $device_name): list<int>`. It returns tickets with role own or joined, newest first.
    - `allCleared(int $tickets_id): bool`
    - `ownTicketsSince(string $since): int`
    - `recentRelatedTickets(string $device_name, string $alarm_code, int $exclude_tickets_id): list<int>`
    - `activeStorm(string $now, int $window_minutes): int`. It ends expired storms and returns 0 when there is none.
    - `startStorm(int $tickets_id, string $now): void`
    - `hitStorm(int $tickets_id, string $now): void`
  - `interface TicketGateway`, with these methods:
    - `status(int $tickets_id): ?int`. It returns null when the ticket is missing or deleted.
    - `create(array $alarm, array $asset): int`. It returns 0 on failure.
    - `createStorm(array $alarm, string $now): int`
    - `followup(int $tickets_id, string $text): void`
    - `reopen(int $tickets_id, string $text): void`
    - `solve(int $tickets_id, string $text): void`
    - `link(int $new_tickets_id, int $old_tickets_id): void`
    - `closedSince(int $tickets_id, string $since): bool`
  - `AlarmSync::__construct(AlarmSource $source, AlarmStore $store, TicketGateway $tickets, AssetResolver $assets, array $cfg)`, where `$cfg` has `ticket_severities`, `relink_window_hours`, `storm_threshold`, `storm_window_minutes`.
  - `AlarmSync::run(string $now): array<string,int>`, with stats keys `fetched`, `created`, `joined`, `storm`, `reopened`, `followups`, `cleared`, `solved`, `pending`, `stored`, `linked`.

- [ ] **Step 1: Write the failing test**

`tests/alarmsync_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
foreach (['OpManager/ClientException', 'OpManager/AlarmSource', 'Ticketing/AssetResolver', 'Ticketing/AlarmStore',
          'Ticketing/TicketGateway', 'Ticketing/Decision', 'Ticketing/Messages', 'Ticketing/AlarmSync'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\AlarmStore;
use GlpiPlugin\Opmanager\Ticketing\AlarmSync;
use GlpiPlugin\Opmanager\Ticketing\AssetResolver;
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
            'tickets_id' => 0, 'role' => '', 'pending' => 0, 'created_at' => ''];
        return $id;
    }
    public function setTicket(int $id, int $t, string $role, string $now): void { $this->rows[$id]['tickets_id'] = $t; $this->rows[$id]['role'] = $role; $this->rows[$id]['pending'] = 0; $this->rows[$id]['created_at'] = $now; }
    public function markPending(int $id): void { $this->rows[$id]['pending'] = 1; }
    public function activeNotSeen(array $keys): array { return array_values(array_filter($this->rows, fn ($r) => $r['state'] === 'active' && !in_array($r['alarm_key'], $keys, true))); }
    public function ticketsForDevice(string $d): array { return array_values(array_unique(array_map(fn ($r) => $r['tickets_id'], array_filter($this->rows, fn ($r) => $r['device_name'] === $d && $r['tickets_id'] > 0 && $r['role'] !== 'storm')))); }
    public function allCleared(int $t): bool { foreach ($this->rows as $r) { if ($r['tickets_id'] === $t && $r['state'] !== 'cleared') { return false; } } return true; }
    public function ownTicketsSince(string $since): int { return count(array_filter($this->rows, fn ($r) => $r['role'] === 'own' && $r['created_at'] >= $since)); }
    public function recentRelatedTickets(string $d, string $c, int $ex): array { return array_values(array_unique(array_map(fn ($r) => $r['tickets_id'], array_filter($this->rows, fn ($r) => $r['device_name'] === $d && $r['alarm_code'] === $c && $r['tickets_id'] > 0 && $r['tickets_id'] !== $ex)))); }
    public function activeStorm(string $now, int $w): int { return $this->storms['active'] ?? 0; }
    public function startStorm(int $t, string $now): void { $this->storms['active'] = $t; }
    public function hitStorm(int $t, string $now): void { $this->storms['hits'] = ($this->storms['hits'] ?? 0) + 1; }
}
final class Gw implements TicketGateway
{
    public array $status = []; public array $log = []; public bool $failCreate = false; private int $next = 100; public array $closedSince = [];
    public function status(int $t): ?int { return $this->status[$t] ?? null; }
    public function create(array $a, array $asset): int { if ($this->failCreate) { return 0; } $t = $this->next++; $this->status[$t] = 1; $this->log[] = "create {$t} {$a['alarm_key']}"; return $t; }
    public function createStorm(array $a, string $now): int { $t = $this->next++; $this->status[$t] = 1; $this->log[] = "storm {$t}"; return $t; }
    public function followup(int $t, string $x): void { $this->log[] = "followup {$t}"; }
    public function reopen(int $t, string $x): void { $this->status[$t] = 2; $this->log[] = "reopen {$t}"; }
    public function solve(int $t, string $x): void { $this->status[$t] = 5; $this->log[] = "solve {$t}"; }
    public function link(int $n, int $o): void { $this->log[] = "link {$n}->{$o}"; }
    public function closedSince(int $t, string $since): bool { return in_array($t, $this->closedSince, true); }
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
$cfg = ['ticket_severities' => [1], 'relink_window_hours' => 24, 'storm_threshold' => 10, 'storm_window_minutes' => 5];
$now = '2026-09-30 10:00:00';
$mk = static function (array $active) use ($cfg) {
    $s = new Src($active); $st = new Store(); $g = new Gw();
    return [new AlarmSync($s, $st, $g, new Assets(), $cfg), $s, $st, $g];
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

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/alarmsync_test.php`
Expected: fatal on the missing `src/Ticketing/AlarmStore.php`.

- [ ] **Step 3: Write the implementation**

`src/Ticketing/AlarmStore.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface AlarmStore
{
    /** @return array{id: int, tickets_id: int, severity: int, message: string, state: string}|null */
    public function find(string $alarm_key): ?array;

    /** Upsert by alarm_key; keeps tickets_id; returns the row id. */
    public function save(array $alarm, array $asset, string $state, string $now): int;

    public function setTicket(int $id, int $tickets_id, string $role, string $now): void;

    public function markPending(int $id): void;

    /** @param string[] $alarm_keys @return list<array{id: int, alarm_key: string, entity_key: string}> */
    public function activeNotSeen(array $alarm_keys): array;

    /** @return list<int> tickets of own/joined alarms of this device, newest first */
    public function ticketsForDevice(string $device_name): array;

    public function allCleared(int $tickets_id): bool;

    public function ownTicketsSince(string $since): int;

    /** @return list<int> */
    public function recentRelatedTickets(string $device_name, string $alarm_code, int $exclude_tickets_id): array;

    /** Ends storms idle for a whole window; returns the active storm ticket or 0. */
    public function activeStorm(string $now, int $window_minutes): int;

    public function startStorm(int $tickets_id, string $now): void;

    public function hitStorm(int $tickets_id, string $now): void;
}
```

`src/Ticketing/TicketGateway.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface TicketGateway
{
    /** Ticket status, or null when the ticket does not exist / is deleted. */
    public function status(int $tickets_id): ?int;

    /** @return int new ticket id, 0 on failure (e.g. a mandatory template field) */
    public function create(array $alarm, array $asset): int;

    public function createStorm(array $alarm, string $now): int;

    public function followup(int $tickets_id, string $text): void;

    public function reopen(int $tickets_id, string $text): void;

    /** No-op when the ticket is already Solved/Closed. */
    public function solve(int $tickets_id, string $text): void;

    public function link(int $new_tickets_id, int $old_tickets_id): void;

    /** Solved or Closed at or after $since. */
    public function closedSince(int $tickets_id, string $since): bool;
}
```

`src/Ticketing/AlarmSync.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;

/**
 * One alarm poll (spec §7, D20). The active list is fetched BEFORE any write, so
 * an OpManager failure changes nothing. An alarm that vanished from the active
 * list is only processed as Clear after OpManager confirms, by entity, that this
 * very alarm is severity 5.
 */
final class AlarmSync
{
    private const CLEAR = 5;

    /** @param array{ticket_severities: list<int>, relink_window_hours: int, storm_threshold: int, storm_window_minutes: int} $cfg */
    public function __construct(
        private AlarmSource $source,
        private AlarmStore $store,
        private TicketGateway $tickets,
        private AssetResolver $assets,
        private array $cfg,
    ) {
    }

    /** @return array<string, int> */
    public function run(string $now): array
    {
        $active = $this->source->listActiveAlarms();

        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked'], 0);
        $stats['fetched'] = count($active);

        $seen = [];
        foreach ($active as $alarm) {
            $seen[] = $alarm['alarm_key'];
            $this->process($alarm, $now, $stats);
        }

        foreach ($this->store->activeNotSeen($seen) as $row) {
            try {
                $current = $this->source->alarmByEntity($row['entity_key']);
            } catch (ClientException) {
                continue;
            }
            if ($current !== null && $current['alarm_key'] === $row['alarm_key'] && (int) $current['severity'] === self::CLEAR) {
                $this->process($current, $now, $stats);
            }
        }

        return $stats;
    }

    private function process(array $alarm, string $now, array &$stats): void
    {
        $existing = $this->store->find($alarm['alarm_key']);
        $status   = null;
        if ($existing !== null && $existing['tickets_id'] > 0) {
            $status = $this->tickets->status($existing['tickets_id']);
            if ($status === null) {
                // Ticket deleted in GLPI: treat the alarm as ticketless again.
                $existing['tickets_id'] = 0;
            }
        }

        $has_ticket = $existing !== null && $existing['tickets_id'] > 0;
        $is_clear   = (int) $alarm['severity'] === self::CLEAR;
        $window     = $this->since($now, $this->cfg['storm_window_minutes'] * 60);

        $decision = Decision::decide([
            'severity'           => (int) $alarm['severity'],
            'message'            => (string) $alarm['message'],
            'is_clear'           => $is_clear,
            'existing'           => $existing,
            'ticket_status'      => $status,
            'ticketable'         => in_array((int) $alarm['severity'], $this->cfg['ticket_severities'], true),
            'device_open_ticket' => $has_ticket || $is_clear ? 0 : $this->openTicketFor($alarm['device_name']),
            'storm_ticket'       => $has_ticket || $is_clear ? 0 : $this->store->activeStorm($now, $this->cfg['storm_window_minutes']),
            'storm_should_start' => !$has_ticket && !$is_clear
                && Decision::shouldStartStorm($this->store->ownTicketsSince($window), $this->cfg['storm_threshold']),
        ]);

        $id  = $this->store->save($alarm, $this->assets->resolve($alarm), $is_clear ? 'cleared' : 'active', $now);
        $tid = $decision['tickets_id'];

        switch ($decision['action']) {
            case Decision::NONE:
                return;
            case Decision::STORE:
                $stats['stored']++;
                return;
            case Decision::FOLLOWUP:
                $this->tickets->followup($tid, Messages::changed($alarm));
                $stats['followups']++;
                return;
            case Decision::CLEAR:
                $this->tickets->followup($tid, Messages::cleared($alarm));
                $stats['cleared']++;
                if ($this->store->allCleared($tid)) {
                    $this->tickets->solve($tid, Messages::solved());
                    $stats['solved']++;
                }
                return;
            case Decision::REOPEN:
                $this->tickets->reopen($tid, Messages::reopened($alarm));
                $stats['reopened']++;
                return;
            case Decision::JOIN_DEVICE:
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'joined', $now);
                $stats['joined']++;
                return;
            case Decision::JOIN_STORM:
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'storm', $now);
                $this->store->hitStorm($tid, $now);
                $stats['storm']++;
                return;
            case Decision::START_STORM:
                $tid = $this->tickets->createStorm($alarm, $now);
                if ($tid === 0) {
                    $this->store->markPending($id);
                    $stats['pending']++;
                    return;
                }
                $this->store->startStorm($tid, $now);
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'storm', $now);
                $stats['storm']++;
                return;
            case Decision::CREATE:
                $tid = $this->tickets->create($alarm, $this->assets->resolve($alarm));
                if ($tid === 0) {
                    $this->store->markPending($id);
                    $stats['pending']++;
                    return;
                }
                $this->store->setTicket($id, $tid, 'own', $now);
                $stats['created']++;
                $this->relink($tid, $alarm, $now, $stats);
                return;
        }
    }

    private function relink(int $tid, array $alarm, string $now, array &$stats): void
    {
        if ($this->cfg['relink_window_hours'] <= 0) {
            return;
        }
        $since = $this->since($now, $this->cfg['relink_window_hours'] * 3600);
        foreach ($this->store->recentRelatedTickets($alarm['device_name'], $alarm['alarm_code'], $tid) as $old) {
            if ($this->tickets->closedSince($old, $since)) {
                $this->tickets->link($tid, $old);
                $this->tickets->followup($tid, Messages::related($old));
                $stats['linked']++;
                return;
            }
        }
    }

    private function openTicketFor(string $device_name): int
    {
        if ($device_name === '') {
            return 0;
        }
        foreach ($this->store->ticketsForDevice($device_name) as $tid) {
            $status = $this->tickets->status($tid);
            if ($status !== null && $status < 5) {
                return $tid;
            }
        }
        return 0;
    }

    private function since(string $now, int $seconds): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($now) - $seconds);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/alarmsync_test.php`
Expected: `ALL PASS (18 passed, 0 failed)`.

Note on the storm case: the fake store stamps every `own` ticket with `$now`, so tickets 100–109 are own. The 11th alarm sees 10 own tickets and starts storm ticket 110; the 12th joins it. That gives `created 10`, `storm 2`.

- [ ] **Step 5: Prove the D20 tests bite**

Temporarily replace the confirmation condition in `run()` with `if ($current !== null) {`. Confirm `FAIL missing alarm without confirmed clear stays active`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/Ticketing/AlarmStore.php src/Ticketing/TicketGateway.php src/Ticketing/AlarmSync.php tests/alarmsync_test.php
git commit -m "feat: alarm sync orchestration with confirmed-clear rule and storm brake

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Schema, Alarm itemtype and DB alarm store

**Files:**
- Create: `src/Alarm.php`, `src/Ticketing/DbAlarmStore.php`
- Modify: `install/install.php`, `install/uninstall.php`, `tests/schema_test.php`

**Interfaces:**
- Consumes: `AlarmStore` (T6).
- Produces:
  - tables `glpi_plugin_opmanager_alarms` and `glpi_plugin_opmanager_storms`;
  - `Alarm` (CommonDBTM, `$rightname = RightDefinition::MONITORING`): `Alarm::forAsset(string $itemtype, int $items_id, int $limit): list<array>` and `Alarm::countForAsset(string $itemtype, int $items_id): int`, active alarms first, then newest `last_change`;
  - `DbAlarmStore implements AlarmStore`.

- [ ] **Step 1: Write the failing test**

In `tests/schema_test.php`, before the final `done();`, add:

```php
foreach (['glpi_plugin_opmanager_alarms', 'glpi_plugin_opmanager_storms'] as $t) {
    check("install creates {$t}", in_array($t, array_column($tables, 1), true));
}
check('alarms.alarm_key is UNIQUE (D3)', (bool) preg_match('/UNIQUE KEY `alarm_key` \(`alarm_key`\)/', $src));
check('alarms keep entity_key for notes/clear', str_contains($src, '`entity_key` VARCHAR(255)'));
check('uninstall drops the new tables', str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_opmanager_alarms')
    && str_contains((string) file_get_contents(OPM_ROOT . '/install/uninstall.php'), 'glpi_plugin_opmanager_storms'));
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/schema_test.php`
Expected: `FAIL install creates glpi_plugin_opmanager_alarms`, `…storms`, `FAIL alarms.alarm_key is UNIQUE`, and the others.

- [ ] **Step 3: Write the implementation**

In `install/install.php`, directly after the closing `}` of the devices-table `if`, add:

```php
    $table = 'glpi_plugin_opmanager_alarms';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `alarm_key` VARCHAR(255) NOT NULL DEFAULT '',
            `entity_key` VARCHAR(255) NOT NULL DEFAULT '',
            `device_name` VARCHAR(255) NOT NULL DEFAULT '',
            `display_name` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `category` VARCHAR(255) NOT NULL DEFAULT '',
            `alarm_code` VARCHAR(255) NOT NULL DEFAULT '',
            `event_type` VARCHAR(255) NOT NULL DEFAULT '',
            `severity` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `severity_label` VARCHAR(32) NOT NULL DEFAULT '',
            `message` TEXT NULL,
            `state` VARCHAR(16) NOT NULL DEFAULT 'active',
            `plugin_opmanager_devices_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `ticket_role` VARCHAR(16) NOT NULL DEFAULT '',
            `ticket_pending` TINYINT NOT NULL DEFAULT 0,
            `ticket_created_at` TIMESTAMP NULL DEFAULT NULL,
            `mod_time` TIMESTAMP NULL DEFAULT NULL,
            `first_seen` TIMESTAMP NULL DEFAULT NULL,
            `last_change` TIMESTAMP NULL DEFAULT NULL,
            `cleared_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `alarm_key` (`alarm_key`),
            KEY `state` (`state`),
            KEY `device_name` (`device_name`),
            KEY `tickets_id` (`tickets_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `plugin_opmanager_devices_id` (`plugin_opmanager_devices_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }

    $table = 'glpi_plugin_opmanager_storms';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `alarm_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `last_hit_at` TIMESTAMP NULL DEFAULT NULL,
            `ended_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }
```

In `install/uninstall.php`, change the table list to:

```php
    foreach (['glpi_plugin_opmanager_storms', 'glpi_plugin_opmanager_alarms', 'glpi_plugin_opmanager_devices'] as $table) {
```

`src/Alarm.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use CommonDBTM;

/** Local copy of one OpManager alarm (read-only for users). */
class Alarm extends CommonDBTM
{
    public static $rightname = RightDefinition::MONITORING;

    public static function getTypeName($nb = 0)
    {
        return _n('OpManager alarm', 'OpManager alarms', $nb, 'opmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-bell';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /** @return list<array<string, mixed>> active first, then newest change */
    public static function forAsset(string $itemtype, int $items_id, int $limit): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => ['state ASC', 'last_change DESC'],
            'LIMIT' => $limit,
        ]), false);
    }

    public static function countForAsset(string $itemtype, int $items_id): int
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ])->current()['cpt'];
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $table, 'field' => 'alarm_key', 'name' => __('Alarm ID', 'opmanager'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $table, 'field' => 'display_name', 'name' => __('Device', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 3, 'table' => $table, 'field' => 'severity_label', 'name' => __('Severity', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $table, 'field' => 'message', 'name' => __('Message', 'opmanager'), 'datatype' => 'text', 'massiveaction' => false],
            ['id' => 5, 'table' => $table, 'field' => 'state', 'name' => __('State', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 6, 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 7, 'table' => $table, 'field' => 'last_change', 'name' => __('Last change', 'opmanager'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8, 'table' => $table, 'field' => 'ip_address', 'name' => __('IP address'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 9, 'table' => $table, 'field' => 'ticket_pending', 'name' => __('Ticket pending', 'opmanager'), 'datatype' => 'bool', 'massiveaction' => false],
        ];
    }
}
```

`src/Ticketing/DbAlarmStore.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Opmanager\Alarm;

final class DbAlarmStore implements AlarmStore
{
    private const STORMS = 'glpi_plugin_opmanager_storms';

    public function find(string $alarm_key): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'tickets_id', 'severity', 'message', 'state'],
            'FROM'   => Alarm::getTable(),
            'WHERE'  => ['alarm_key' => $alarm_key],
            'LIMIT'  => 1,
        ])->current();

        return $row ? [
            'id'         => (int) $row['id'],
            'tickets_id' => (int) $row['tickets_id'],
            'severity'   => (int) $row['severity'],
            'message'    => (string) $row['message'],
            'state'      => (string) $row['state'],
        ] : null;
    }

    public function save(array $alarm, array $asset, string $state, string $now): int
    {
        global $DB;

        $table  = Alarm::getTable();
        $fields = [
            'entity_key'                  => $alarm['entity_key'],
            'device_name'                 => $alarm['device_name'],
            'display_name'                => $alarm['display_name'],
            'ip_address'                  => $alarm['ip_address'],
            'category'                    => $alarm['category'],
            'alarm_code'                  => $alarm['alarm_code'],
            'event_type'                  => $alarm['event_type'],
            'severity'                    => (int) $alarm['severity'],
            'severity_label'              => $alarm['severity_label'],
            'message'                     => $alarm['message'],
            'state'                       => $state,
            'plugin_opmanager_devices_id' => $asset['devices_id'],
            'itemtype'                    => $asset['itemtype'],
            'items_id'                    => $asset['items_id'],
            'mod_time'                    => $alarm['mod_time'] !== '' ? $alarm['mod_time'] : null,
            'date_mod'                    => $now,
        ];

        $existing = $DB->request([
            'SELECT' => ['id', 'severity', 'message', 'state'],
            'FROM'   => $table,
            'WHERE'  => ['alarm_key' => $alarm['alarm_key']],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            $changed = (int) $existing['severity'] !== (int) $alarm['severity']
                || (string) $existing['message'] !== (string) $alarm['message']
                || (string) $existing['state'] !== $state;
            if ($changed) {
                $fields['last_change'] = $now;
            }
            if ($state === 'cleared' && $existing['state'] !== 'cleared') {
                $fields['cleared_at'] = $now;
            }
            if ($state === 'active') {
                $fields['cleared_at'] = null;
            }
            $DB->update($table, $fields, ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        $DB->insert($table, $fields + [
            'alarm_key'     => $alarm['alarm_key'],
            'first_seen'    => $now,
            'last_change'   => $now,
            'cleared_at'    => $state === 'cleared' ? $now : null,
            'date_creation' => $now,
        ]);
        return (int) $DB->insertId();
    }

    public function setTicket(int $id, int $tickets_id, string $role, string $now): void
    {
        global $DB;
        $DB->update(Alarm::getTable(), [
            'tickets_id'        => $tickets_id,
            'ticket_role'       => $role,
            'ticket_pending'    => 0,
            'ticket_created_at' => $now,
        ], ['id' => $id]);
    }

    public function markPending(int $id): void
    {
        global $DB;
        $DB->update(Alarm::getTable(), ['ticket_pending' => 1], ['id' => $id]);
    }

    public function activeNotSeen(array $alarm_keys): array
    {
        global $DB;

        $where = ['state' => 'active'];
        if ($alarm_keys !== []) {
            $where['NOT'] = ['alarm_key' => $alarm_keys];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'alarm_key', 'entity_key'], 'FROM' => Alarm::getTable(), 'WHERE' => $where]) as $row) {
            $out[] = ['id' => (int) $row['id'], 'alarm_key' => (string) $row['alarm_key'], 'entity_key' => (string) $row['entity_key']];
        }
        return $out;
    }

    public function ticketsForDevice(string $device_name): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'   => ['tickets_id'],
            'DISTINCT' => true,
            'FROM'     => Alarm::getTable(),
            'WHERE'    => ['device_name' => $device_name, 'tickets_id' => ['>', 0], 'ticket_role' => ['own', 'joined']],
            'ORDER'    => 'tickets_id DESC',
        ]) as $row) {
            $out[] = (int) $row['tickets_id'];
        }
        return $out;
    }

    public function allCleared(int $tickets_id): bool
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => Alarm::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id, 'state' => ['<>', 'cleared']],
        ])->current()['cpt'] === 0;
    }

    public function ownTicketsSince(string $since): int
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => Alarm::getTable(),
            'WHERE' => ['ticket_role' => 'own', 'ticket_created_at' => ['>=', $since]],
        ])->current()['cpt'];
    }

    public function recentRelatedTickets(string $device_name, string $alarm_code, int $exclude_tickets_id): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'   => ['tickets_id'],
            'DISTINCT' => true,
            'FROM'     => Alarm::getTable(),
            'WHERE'    => [
                'device_name' => $device_name,
                'alarm_code'  => $alarm_code,
                'tickets_id'  => ['>', 0],
                'NOT'         => ['tickets_id' => $exclude_tickets_id],
            ],
            'ORDER'    => 'tickets_id DESC',
        ]) as $row) {
            $out[] = (int) $row['tickets_id'];
        }
        return $out;
    }

    public function activeStorm(string $now, int $window_minutes): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'tickets_id', 'last_hit_at'],
            'FROM'   => self::STORMS,
            'WHERE'  => ['ended_at' => null],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ])->current();
        if (!$row) {
            return 0;
        }
        $idle_since = date('Y-m-d H:i:s', (int) strtotime($now) - $window_minutes * 60);
        if ((string) $row['last_hit_at'] < $idle_since) {
            $DB->update(self::STORMS, ['ended_at' => $now], ['id' => (int) $row['id']]);
            return 0;
        }
        return (int) $row['tickets_id'];
    }

    public function startStorm(int $tickets_id, string $now): void
    {
        global $DB;
        $DB->insert(self::STORMS, ['tickets_id' => $tickets_id, 'alarm_count' => 1, 'started_at' => $now, 'last_hit_at' => $now]);
    }

    public function hitStorm(int $tickets_id, string $now): void
    {
        global $DB;
        $DB->update(self::STORMS, [
            'last_hit_at' => $now,
            'alarm_count' => new QueryExpression($DB->quoteName('alarm_count') . ' + 1'),
        ], ['tickets_id' => $tickets_id, 'ended_at' => null]);
    }
}
```

- [ ] **Step 4: Run tests and lint**

Run: `C:/php/php.exe tests/schema_test.php && for f in src/Alarm.php src/Ticketing/DbAlarmStore.php install/install.php install/uninstall.php; do C:/php/php.exe -l $f; done`
Expected: `ALL PASS` (all columns named `*_id` INT UNSIGNED, no DATETIME, new tables present), and four `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Alarm.php src/Ticketing/DbAlarmStore.php install tests/schema_test.php
git commit -m "feat: alarms and storms tables with DB alarm store

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: GLPI ticket gateway

**Files:**
- Create: `src/Ticketing/GlpiTicketGateway.php`, `tests/gateway_static_test.php`

**Interfaces:**
- Consumes: `TicketGateway` (T6), `TicketBuilder`, `EntityResolver` (T3), `Messages::stormTitle` (T5), `Config::ticketingConfig()` shape (T2).
- Produces:
  - `GlpiTicketGateway::__construct(array $cfg)`, where `$cfg` is the `Config::ticketingConfig()` array;
  - `GlpiTicketGateway::html(string $text): string` (pure).

- [ ] **Step 1: Write the failing test**

`tests/gateway_static_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';

// The gateway talks to GLPI core and is exercised on the VM; offline we pin the
// rules that are cheap to break and expensive to find later.
$src = (string) @file_get_contents(OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php');
check('followups never reopen by accident', substr_count($src, "'_no_reopen' => 1") >= 1);
check('reopen goes through GLPI add_reopen', str_contains($src, "'add_reopen' => 1"));
check('service user on followups and solutions', substr_count($src, "'users_id'") >= 2);
check('auto-assign on followup suppressed', str_contains($src, "glpiset_followup_tech"));
check('link uses Ticket_Ticket LINK_TO', str_contains($src, 'Ticket_Ticket::LINK_TO'));
check('solve skips solved/closed tickets', (bool) preg_match('/function solve.*?in_array\(\$status, \[5, 6\]/s', $src));
check('no raw writes to core ticket tables', !preg_match("/DB->(insert|update|delete)\(\s*'glpi_(tickets|itilfollowups|itilsolutions)/", $src));

require OPM_ROOT . '/src/Ticketing/TicketGateway.php';
require OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php';
check('html escapes and keeps lines', \GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway::html("a<b>\nc") === "a&lt;b&gt;<br />\nc");

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/gateway_static_test.php`
Expected: `FAIL` for the first seven checks (the source is empty), then a fatal on the missing file.

- [ ] **Step 3: Write the implementation**

`src/Ticketing/GlpiTicketGateway.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * TicketGateway on GLPI 11 core objects - every write through ->add(), never raw
 * SQL on core tables (hub §5). Runs under CLI cron with no logged-in user, so every
 * followup/solution carries the configured service user explicitly.
 */
final class GlpiTicketGateway implements TicketGateway
{
    private const SOLVED = 5;
    private const CLOSED = 6;

    /** @param array{service_user: int, template_id: int, title_pattern: string, content_pattern: string, entity_default: int, use_ipnetwork: bool} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function status(int $tickets_id): ?int
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id) || !empty($ticket->fields['is_deleted'])) {
            return null;
        }
        return (int) $ticket->fields['status'];
    }

    public function create(array $alarm, array $asset): int
    {
        $entity                  = $this->entityFor($alarm, $asset);
        [$template_id, $predefined] = $this->template($entity);
        $input = TicketBuilder::build($alarm, $asset, $entity, $predefined, $template_id, $this->cfg);

        $ticket = new \Ticket();
        $id     = $ticket->add($input);
        return $id ? (int) $id : 0;
    }

    public function createStorm(array $alarm, string $now): int
    {
        $entity                  = (int) $this->cfg['entity_default'];
        [$template_id, $predefined] = $this->template($entity);
        $cfg                     = ['title_pattern' => Messages::stormTitle($now), 'content_pattern' => Messages::stormTitle($now)] + $this->cfg;
        $input = TicketBuilder::build($alarm, ['itemtype' => '', 'items_id' => 0], $entity, $predefined, $template_id, $cfg);

        $ticket = new \Ticket();
        $id     = $ticket->add($input);
        return $id ? (int) $id : 0;
    }

    public function followup(int $tickets_id, string $text): void
    {
        $this->withoutAutoAssign(function () use ($tickets_id, $text) {
            (new \ITILFollowup())->add([
                'itemtype'   => \Ticket::class,
                'items_id'   => $tickets_id,
                'content'    => self::html($text),
                'is_private' => 0,
                'users_id'   => (int) $this->cfg['service_user'],
                '_no_reopen' => 1,
            ]);
        });
    }

    public function reopen(int $tickets_id, string $text): void
    {
        $this->withoutAutoAssign(function () use ($tickets_id, $text) {
            (new \ITILFollowup())->add([
                'itemtype'   => \Ticket::class,
                'items_id'   => $tickets_id,
                'content'    => self::html($text),
                'is_private' => 0,
                'users_id'   => (int) $this->cfg['service_user'],
                'add_reopen' => 1,
            ]);
        });
    }

    public function solve(int $tickets_id, string $text): void
    {
        $status = $this->status($tickets_id);
        if ($status === null || in_array($status, [5, 6], true)) {
            return;
        }
        (new \ITILSolution())->add([
            'itemtype' => \Ticket::class,
            'items_id' => $tickets_id,
            'content'  => self::html($text),
            'users_id' => (int) $this->cfg['service_user'],
        ]);
    }

    public function link(int $new_tickets_id, int $old_tickets_id): void
    {
        (new \Ticket_Ticket())->add([
            'tickets_id_1' => $new_tickets_id,
            'tickets_id_2' => $old_tickets_id,
            'link'         => \Ticket_Ticket::LINK_TO,
        ]);
    }

    public function closedSince(int $tickets_id, string $since): bool
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }
        if (!in_array((int) $ticket->fields['status'], [self::SOLVED, self::CLOSED], true)) {
            return false;
        }
        $when = max((string) ($ticket->fields['solvedate'] ?? ''), (string) ($ticket->fields['closedate'] ?? ''));
        return $when !== '' && $when >= $since;
    }

    public static function html(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES));
    }

    private function entityFor(array $alarm, array $asset): int
    {
        global $DB;

        $asset_entity = null;
        if ($asset['items_id'] > 0 && $asset['itemtype'] !== '') {
            $item = getItemForItemtype($asset['itemtype']);
            if ($item instanceof \CommonDBTM && $item->getFromDB($asset['items_id']) && isset($item->fields['entities_id'])) {
                $asset_entity = (int) $item->fields['entities_id'];
            }
        }

        $networks = [];
        if ($asset_entity === null && $this->cfg['use_ipnetwork']) {
            foreach ($DB->request([
                'SELECT' => ['entities_id', 'address', 'netmask'],
                'FROM'   => 'glpi_ipnetworks',
                'WHERE'  => ['version' => 4],
            ]) as $row) {
                $networks[] = $row;
            }
        }

        return EntityResolver::resolve($asset_entity, (string) $alarm['ip_address'], $networks, (bool) $this->cfg['use_ipnetwork'], (int) $this->cfg['entity_default']);
    }

    /** @return array{0: int, 1: array<string, mixed>} template id and its predefined fields */
    private function template(int $entity): array
    {
        if ((int) $this->cfg['template_id'] > 0) {
            $tt = new \TicketTemplate();
            if ($tt->getFromDBWithData((int) $this->cfg['template_id'])) {
                return [(int) $tt->getID(), (array) $tt->predefined];
            }
        }
        $tt = (new \Ticket())->getITILTemplateToUse(0, \Ticket::INCIDENT_TYPE, 0, $entity);
        return [(int) $tt->getID(), (array) $tt->predefined];
    }

    /**
     * ITILFollowup::prepareInputForAdd() assigns the author as technician when the
     * global "add me as technician on followup" option is on - that would assign
     * the service account to every alarm ticket. Switch it off around our own add.
     */
    private function withoutAutoAssign(callable $fn): void
    {
        $had   = array_key_exists('glpiset_followup_tech', $_SESSION);
        $saved = $_SESSION['glpiset_followup_tech'] ?? null;
        $_SESSION['glpiset_followup_tech'] = 0;
        try {
            $fn();
        } finally {
            if ($had) {
                $_SESSION['glpiset_followup_tech'] = $saved;
            } else {
                unset($_SESSION['glpiset_followup_tech']);
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/gateway_static_test.php && C:/php/php.exe -l src/Ticketing/GlpiTicketGateway.php`
Expected: `ALL PASS (8 passed, 0 failed)`; `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Ticketing/GlpiTicketGateway.php tests/gateway_static_test.php
git commit -m "feat: GLPI ticket gateway (template, entity, followup, reopen, solve, link)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: AlarmSync cron, alarm list page, menu entry

**Files:**
- Modify: `src/Cron.php`, `install/install.php`, `src/Menu.php`, `tests/device_test.php`
- Create: `front/alarm.php`, `front/alarm.form.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `Cron::cronAlarmSync(\CronTask $task): int`; cron registration `AlarmSync`; menu entry key `alarm`.

- [ ] **Step 1: Write the failing test**

In `tests/device_test.php`, before `done();`, add:

```php
$cron = (string) @file_get_contents(OPM_ROOT . '/src/Cron.php');
check('AlarmSync cron exists', str_contains($cron, 'public static function cronAlarmSync(\CronTask $task): int'));
check('AlarmSync keeps the error visible', str_contains($cron, "'last_alarm_sync_error'"));
$install = (string) file_get_contents(OPM_ROOT . '/install/install.php');
check('AlarmSync registered in CLI mode from the interval constant', str_contains($install, "'AlarmSync', Config::ALARM_SYNC_MINUTES * MINUTE_TIMESTAMP"));
check('menu has an alarm entry', str_contains((string) file_get_contents(OPM_ROOT . '/src/Menu.php'), "'alarm'"));
check('alarm list page exists', is_file(OPM_ROOT . '/front/alarm.php'));
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/device_test.php`
Expected: five new `FAIL` lines.

- [ ] **Step 3: Write the implementation**

In `src/Cron.php`:
- Add these `use` lines:

```php
use GlpiPlugin\Opmanager\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Opmanager\Ticketing\AlarmSync;
use GlpiPlugin\Opmanager\Ticketing\DbAlarmStore;
use GlpiPlugin\Opmanager\Ticketing\DbDeviceIndex;
use GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway;
```

- In `cronInfo()`, add the arm `'AlarmSync' => ['description' => __('Pull active OpManager alarms and create/update tickets', 'opmanager')],`.
- Add this method:

```php
    public static function cronAlarmSync(\CronTask $task): int
    {
        $now = (string) \Session::getCurrentTime();
        $cfg = Config::ticketingConfig();

        if ($cfg['service_user'] <= 0) {
            $msg = 'no service user configured in Setup; alarm tickets would have no author';
            Config::set(['last_alarm_sync_error' => $msg]);
            $task->log($msg);
            return 0;
        }

        try {
            $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
            $sync   = new AlarmSync(
                $client,
                new DbAlarmStore(),
                new GlpiTicketGateway($cfg),
                new AlarmAssetResolver(new DbDeviceIndex(), new Matcher(new DbAssetLookup(Config::assetItemtypes())), Config::get('match_mode')),
                $cfg
            );
            $stats = $sync->run($now);
        } catch (ClientException $e) {
            Config::set(['last_alarm_sync_error' => $e->getMessage()]);
            \Toolbox::logInFile('opmanager', 'AlarmSync: ' . $e->getMessage() . "\n");
            $task->log($e->getMessage());
            return 0;
        }

        Config::set(['last_alarm_sync' => $now, 'last_alarm_sync_error' => $stats['pending'] > 0
            ? sprintf('%d alarm ticket(s) could not be created (check template mandatory fields); retried next run', $stats['pending'])
            : '']);
        $task->addVolume($stats['fetched']);
        $task->log(implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($stats), $stats)));
        return 1;
    }
```

In `install/install.php`, directly after the `DeviceSync` `CronTask::register(...)` call, add:

```php
    CronTask::register(Cron::class, 'AlarmSync', Config::ALARM_SYNC_MINUTES * MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'OpManager alarm sync and tickets',
    ]);
```

In `src/Menu.php`, in `getMenuContent()`, add a second entry after `'device' => [...]`:

```php
            'alarm'            => [
                'title' => Alarm::getTypeName(2),
                'page'  => Alarm::getSearchURL(false),
                'icon'  => Alarm::getIcon(),
                'links' => ['search' => Alarm::getSearchURL(false)],
            ],
```

`front/alarm.php`:

```php
<?php

use GlpiPlugin\Opmanager\Alarm;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(Alarm::getTypeName(2), '', 'opmanager', 'alarm');
Search::show(Alarm::class);
Html::footer();
```

`front/alarm.form.php`:

```php
<?php

use GlpiPlugin\Opmanager\Alarm;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::redirect(Alarm::getSearchURL());
}
$menus = ['central' => ['opmanager', 'alarm'], 'helpdesk' => []];
Alarm::displayFullPageForItem($id, $menus, []);
```

Add to `src/Alarm.php` the read-only form used by that page:

```php
    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB((int) $ID)) {
            return false;
        }
        $f    = $this->fields;
        $rows = [
            __('Alarm ID', 'opmanager')   => htmlescape($f['alarm_key']),
            __('Device', 'opmanager')     => htmlescape($f['display_name'] ?: $f['device_name']),
            __('IP address')              => htmlescape($f['ip_address']),
            __('Severity', 'opmanager')   => htmlescape($f['severity_label']),
            __('Alarm type', 'opmanager') => htmlescape($f['alarm_code']),
            __('Message', 'opmanager')    => htmlescape((string) $f['message']),
            __('State', 'opmanager')      => htmlescape($f['state']),
            __('Ticket')                  => $f['tickets_id'] > 0
                ? '<a href="' . htmlescape(\Ticket::getFormURLWithID((int) $f['tickets_id'])) . '">#' . (int) $f['tickets_id'] . '</a>'
                : ($f['ticket_pending'] ? __s('pending', 'opmanager') : '-'),
            __('Last change', 'opmanager') => htmlescape((string) $f['last_change']),
        ];
        echo "<div class='card'><div class='card-body'><table class='table table-sm'>";
        foreach ($rows as $label => $value) {
            echo '<tr><th class="w-25">' . htmlescape($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table></div></div>';
        return true;
    }
```

Also add `use GlpiPlugin\Opmanager\Alarm;` at the top of `src/Menu.php`, or rely on the same namespace. `Alarm` and `Menu` share `GlpiPlugin\Opmanager`, so no `use` is needed.

- [ ] **Step 4: Run tests and lint**

Run: `C:/php/php.exe tests/device_test.php && for f in src/Cron.php src/Menu.php src/Alarm.php front/alarm.php front/alarm.form.php install/install.php; do C:/php/php.exe -l $f; done && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (12 passed, 0 failed)`, six `No syntax errors detected`, `SUITES OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Cron.php src/Menu.php src/Alarm.php front/alarm.php front/alarm.form.php install/install.php tests/device_test.php
git commit -m "feat: AlarmSync cron, alarm list page and menu entry

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Alarms in the Monitoring section

**Files:**
- Modify: `src/AssetSections.php`, `tests/assetsections_test.php`

**Interfaces:**
- Consumes: `Alarm::forAsset`, `Alarm::countForAsset` (T7).
- Produces:
  - `AssetSections::viewModel(array $devices, int $now, int $stale_minutes, string $last_sync, array $alarms = [], int $alarms_total = 0): array`, which adds the keys `alarms` and `alarms_total`;
  - `html()` renders an alarm table under the device table.

- [ ] **Step 1: Write the failing test**

In `tests/assetsections_test.php`:
- In the stub namespace `GlpiPlugin\Opmanager`, add:

```php
    final class Alarm
    {
        public static function forAsset(string $t, int $id, int $limit): array { return $GLOBALS['ALARMS'] ?? []; }
        public static function countForAsset(string $t, int $id): int { return count($GLOBALS['ALARMS'] ?? []); }
    }
```

- Add a stub in the global block: `class Ticket { public static function getFormURLWithID($id, $full = true) { return '/front/ticket.form.php?id=' . (int) $id; } }`.
- Before `done();`, add:

```php
    $GLOBALS['DEVICES'] = [];
    $GLOBALS['ALARMS'] = [['alarm_key' => '10000003244', 'severity_label' => 'Critical', 'message' => '<script>x</script>', 'state' => 'active', 'tickets_id' => 55, 'ticket_pending' => 0, 'last_change' => '2026-09-30 10:00:00']];
    check('alarms alone show the section', count(AssetSections::sections(new Computer())) === 1);
    $html = AssetSections::html(AssetSections::viewModel([], $now, 30, '2026-09-30 09:59:00', $GLOBALS['ALARMS'], 1));
    check('alarm message escaped', str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>x'));
    check('alarm links its ticket', str_contains($html, '/front/ticket.form.php?id=55'));
    $many = array_fill(0, 25, $GLOBALS['ALARMS'][0]);
    $html = AssetSections::html(AssetSections::viewModel([], $now, 30, '2026-09-30 09:59:00', array_slice($many, 0, 20), 25));
    check('alarm truncation visible', str_contains($html, 'Showing 20 of 25 alarms'));
    check('still no pager', !preg_match('/pagination|page-link/', $html));
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/assetsections_test.php`
Expected: `FAIL alarms alone show the section` plus the html checks.

- [ ] **Step 3: Write the implementation**

In `src/AssetSections.php`:

(a) In `sections()`, replace the device check:

```php
        if (Device::findForAsset($item::class, (int) $item->getID()) === []) {
            return [];
        }
```

with:

```php
        $id = (int) $item->getID();
        if (Device::findForAsset($item::class, $id) === [] && Alarm::countForAsset($item::class, $id) === 0) {
            return [];
        }
```

(b) In `render()`, replace the `echo self::html(self::viewModel(` call with:

```php
        $id = (int) $item->getID();
        echo self::html(self::viewModel(
            $devices,
            (int) strtotime((string) \Session::getCurrentTime()),
            (int) Config::get('stale_warning_minutes'),
            Config::get('last_device_sync'),
            Alarm::forAsset($item::class, $id, self::MAX_ROWS),
            Alarm::countForAsset($item::class, $id)
        ));
```

(c) Replace the `viewModel()` signature and return value:

```php
    public static function viewModel(array $devices, int $now, int $stale_minutes, string $last_sync, array $alarms = [], int $alarms_total = 0): array
    {
        $synced = $last_sync === '' ? false : strtotime($last_sync);
        return [
            'stale'         => $synced === false || $synced < $now - $stale_minutes * 60,
            'last_sync'     => $last_sync,
            'stale_minutes' => $stale_minutes,
            'total'         => count($devices),
            'rows'          => array_slice($devices, 0, self::MAX_ROWS),
            'alarms'        => array_slice($alarms, 0, self::MAX_ROWS),
            'alarms_total'  => max($alarms_total, count($alarms)),
        ];
    }
```

(d) In `html()`, directly before `return $out . '</div>';`, add:

```php
        $alarms = $model['alarms'] ?? [];
        if ($alarms !== []) {
            $out .= '<h4 class="mt-3">' . __s('OpManager alarms', 'opmanager') . '</h4>'
                . '<table class="table table-sm table-hover mb-2"><thead><tr>'
                . '<th>' . __s('Severity', 'opmanager') . '</th>'
                . '<th>' . __s('Message', 'opmanager') . '</th>'
                . '<th>' . __s('State', 'opmanager') . '</th>'
                . '<th>' . __s('Ticket') . '</th>'
                . '<th>' . __s('Last change', 'opmanager') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($alarms as $a) {
                $tid    = (int) ($a['tickets_id'] ?? 0);
                $ticket = $tid > 0
                    ? '<a href="' . htmlescape(\Ticket::getFormURLWithID($tid)) . '">#' . $tid . '</a>'
                    : (!empty($a['ticket_pending']) ? __s('pending', 'opmanager') : '-');
                $out .= '<tr data-opm-alarm="' . htmlescape((string) ($a['alarm_key'] ?? '')) . '">'
                    . '<td>' . htmlescape((string) ($a['severity_label'] ?? '')) . '</td>'
                    . '<td>' . htmlescape((string) ($a['message'] ?? '')) . '</td>'
                    . '<td>' . htmlescape((string) ($a['state'] ?? '')) . '</td>'
                    . '<td>' . $ticket . '</td>'
                    . '<td>' . htmlescape((string) ($a['last_change'] ?? '')) . '</td>'
                    . '</tr>';
            }
            $out .= '</tbody></table>';
            if ($model['alarms_total'] > count($alarms)) {
                $out .= '<div class="text-muted">' . htmlescape(sprintf(__('Showing %d of %d alarms', 'opmanager'), count($alarms), $model['alarms_total'])) . '</div>';
            }
        }
```

The device table is also rendered when `$model['rows']` is empty. The existing code renders an empty device table in that case; that is acceptable. With only alarms present, the section still shows the stale line and an empty device table, followed by the alarms.

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/assetsections_test.php && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (19 passed, 0 failed)`; `SUITES OK`.

- [ ] **Step 5: Commit**

```bash
git add src/AssetSections.php tests/assetsections_test.php
git commit -m "feat: OpManager alarms in the Monitoring section

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Selfcheck, runbook, release 0.2.0

**Files:**
- Modify: `src/SelfCheck.php`, `setup.php`, `CHANGELOG.md`
- Create: `docs/runbook-v0.2.0.md`

- [ ] **Step 1: Extend the selfcheck**

In `src/SelfCheck.php`, add these entries to the returned array (after `'cron/device-sync-cli'`):

```php
            'schema/alarm-tables' => static function (): void {
                global $DB;
                self::assert($DB->tableExists('glpi_plugin_opmanager_alarms') && $DB->tableExists('glpi_plugin_opmanager_storms'), 'alarm/storm tables missing');
            },
            'cron/alarm-sync-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'AlarmSync'), 'AlarmSync cron not registered');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'AlarmSync is not in CLI mode');
            },
            'tickets/service-user' => static function (): void {
                $uid  = (int) Config::get('service_user');
                $user = new \User();
                self::assert($uid > 0 && $user->getFromDB($uid) && !empty($user->fields['is_active']), 'Setup > OpManager: choose an active GLPI service user for alarm tickets');
            },
            'tickets/template-resolves' => static function (): void {
                $cfg = Config::ticketingConfig();
                if ($cfg['template_id'] > 0) {
                    self::assert((new \TicketTemplate())->getFromDBWithData($cfg['template_id']), "ticket template {$cfg['template_id']} not found");
                } else {
                    $tt = (new \Ticket())->getITILTemplateToUse(0, \Ticket::INCIDENT_TYPE, 0, $cfg['entity_default']);
                    self::assert($tt instanceof \TicketTemplate, 'no default ticket template resolved');
                }
            },
```

Run: `C:/php/php.exe -l src/SelfCheck.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2: Bump version and changelog**

In `setup.php`: `define('PLUGIN_OPMANAGER_VERSION', '0.2.0');`

In `CHANGELOG.md`, add at the top below `# Changelog`:

```markdown
## 0.2.0

- Alarm polling: CLI cron `AlarmSync` (every 10 min) pulls OpManager's active alarms
  (`alertType=ActiveAlarms`) into `glpi_plugin_opmanager_alarms`.
- Tickets from alarms:
  - one ticket per OpManager alarm id;
  - alarms of the same device join its open ticket;
  - the same alarm id back after a clear reopens its ticket;
  - Solved only when every alarm on the ticket is confirmed Clear by OpManager;
  - a repeat of the same device + alarm type within 24 h is linked to the earlier ticket;
  - alarm storm brake (10 new tickets / 5 min → one storm ticket).
- Ticket content through a GLPI ticket template, and title/description patterns in Setup.
- Ticket entity: asset entity → GLPI IP network entity → default.
- API key now sent only in the `apiKey` header.
- Monitoring section lists the asset's OpManager alarms; new "OpManager alarms" list page.
```

- [ ] **Step 3: Write the runbook**

`docs/runbook-v0.2.0.md`:

````markdown
---
date: 2026-09-30
tags: [opmanager, runbook]
status: active
---

# opmanager v0.2.0 — VM verification runbook

Tick a box only with the evidence named on it. Paste outputs back to the session.

## 1. Before installing

- [ ] Create or choose a GLPI **service user** for alarm tickets (e.g. `svc-opmanager`),
      with a central profile that can create tickets in the target entities.
- [ ] Decide the ticket template (or keep "Default GLPI template") and the default entity.

## 2. Install

```bash
cd /data/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.2.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /data/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] `DeviceSync` still works after the header-auth change:
      `sudo -u nginx php /data/www/glpi/front/cron.php DeviceSync` → "Last successful device sync" updates.
- [ ] Setup > PGN Suite > OpManager > "Tickets from alarms": service user, template and default
      entity set, and **only Critical** ticked. Save; then selfcheck prints `ALL PASS`
      (including `tickets/service-user` and `tickets/template-resolves`).

## 3. First alarm sync

```bash
sudo -u nginx php /data/www/glpi/front/cron.php AlarmSync
sudo tail -n 20 /data/www/glpi/files/_log/opmanager.log
```

- [ ] Automatic action log shows `fetched N, created …`. N should be about the number of active
      alarms in OpManager (about 40 on 2026-09-30).
- [ ] OpManager > OpManager alarms lists them; Critical ones have a ticket number.
- [ ] Open one created ticket:
  - title/description come from the patterns;
  - the entity is the asset's entity;
  - the asset is linked;
  - the service user is the requester;
  - the template's predefined fields are applied.
- [ ] Run AlarmSync again immediately: **no new tickets, no new followups** (log shows
      `created 0`, `followups 0`).
- [ ] If any alarm shows "pending": the template has a mandatory field the plugin does not
      fill. Write down which, and do not tick the previous box.

## 4. Behaviour that needs real events

For each item below, if the event did not happen during the test window, write
"case untested in production" instead of ticking.

- [ ] An alarm cleared in OpManager → next AlarmSync adds a followup; the ticket becomes
      **Solved** only when all its alarms are Clear.
- [ ] A second Critical alarm on a device with an open ticket → followup on that ticket, no new
      ticket.
- [ ] The service account was **not** added as technician on followups.
- [ ] A reopened ticket (same alarm id active again after clear), if it happens.
- [ ] A linked ticket for a repeat within 24 h, if it happens. This needs the Ticket_Ticket link
      to be created under cron; if it is missing, report it.
````

- [ ] **Step 4: Full suite, commit, tag, build, verify**

Run: `C:/php/php.exe tests/run_all.php`
Expected: `SUITES OK`.

```bash
git add src/SelfCheck.php setup.php CHANGELOG.md docs/runbook-v0.2.0.md
git commit -m "chore: release 0.2.0 (alarm polling + tickets)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git tag 0.2.0
git archive --format=zip --prefix=opmanager/ -o opmanagerv0.2.0.zip 0.2.0
python tools/release_check.py opmanagerv0.2.0.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins
```

Expected: `RELEASE OK`, and the installed file count equals the entries count. Do not push; 0.2.0 is a minor version.

- [ ] **Step 5: Hub**

In `…/PGN GLPI Suite/_pgn-glpi-suite.md`:
- update §2 row 7 status to "v0.2.0 built (tag, zip SHA), not deployed";
- append a §7 entry: opmanager now creates Tickets from alarms. It is the first suite plugin to do so, and it uses core `->add()` only. It adds followups with `_no_reopen`, reopens via `add_reopen`, and suppresses `glpiset_followup_tech` around its followups;
- bump "Last updated".
