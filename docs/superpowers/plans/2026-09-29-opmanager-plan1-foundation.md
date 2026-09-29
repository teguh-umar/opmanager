# opmanager Plan 1 — Foundation, Asset Matching, Monitoring Section — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `opmanager` v0.1.0. It installs cleanly on GLPI 11.0.5, syncs the OpManager device list, matches each device to an existing GLPI asset (it never writes to assets), lets an admin pin devices manually, and shows a "Monitoring" section in the shared PGN Suite asset tab.

**Architecture:** This is a PSR-4 GLPI plugin (`GlpiPlugin\Opmanager\` → `src/`). The decision logic lives in pure classes that the offline tests exercise: `Config::sanitize`, `RightSeeder`, `Matcher`, `DeviceParser`, `DeviceSync`, `AssetSections::viewModel/html`. Thin adapters touch GLPI: `DbAssetLookup`, `DbDeviceStore`, `Cron`, front controllers. The adapters are covered by `tools/selfcheck.php` on the VM. Setup UI goes in the shared PGN Suite config tab (`lib/pgnsuite/ConfigTab.php` r3). The monitoring view is a section of the shared PGN Suite asset tab (`lib/pgnsuite/AssetTab.php` r1).

**Tech Stack:** PHP 8.2+ (local CLI is PHP 8.4 without mbstring, and has no GLPI runtime), GLPI 11.0.5 core APIs, Guzzle (bundled in GLPI `vendor/`), Python 3 for the release checker.

**Spec:** `docs/superpowers/specs/2026-09-29-opmanager-design.md` (approved, commit `d4b7871`). This plan covers spec §4, §5, the `devices` table in §6, the device and monitoring parts of §9, the Plan 1 keys of §10, and the device-sync rows of §11/§12. **Plan 2** covers alarms, tickets and the webhook (spec §7, alarms/storms tables, D3–D14, D17–D18); it waits on spec §13 items 1–2. **Plan 3** covers the outbound queue and hooks (spec §8, D8, D13, D16).

## Global Constraints

- Plugin key `opmanager`; tables `glpi_plugin_opmanager_*`; config context `plugin:opmanager`; PSR-4 `GlpiPlugin\Opmanager\` → `src/`; cron `GlpiPlugin\Opmanager\Cron::*`.
- Rights: `plugin_opmanager_config` (READ|UPDATE), `plugin_opmanager_monitoring` (READ). The rights tab uses `\Profile::displayRightsChoiceMatrix()`. Seed **only** rights newly created this run. A profile with core `config` UPDATE gets the full mask on **every** run.
- Keys are `INT UNSIGNED`. `id`/`*_id` names are reserved for GLPI keys; OpManager identifiers are `VARCHAR` named `*_key`. Date columns are `TIMESTAMP`, never `DATETIME`.
- `lib/pgnsuite/ConfigTab.php` must be byte-identical to canonical r3, SHA-256 `008C4C817DB2E42040915136108CD41917C34760A8F4CDDC60E46BDB208F39D0`. `lib/pgnsuite/AssetTab.php` must be byte-identical to canonical r1, SHA-256 `C7F6C88B58BE9A7AAF172F3A2F2DBAAFBB07B0CE1898BA5A74A0EDB61EA143A9`. Both are `-text` in `.gitattributes`, and their SHAs are checked inside the zip.
- Plugin never creates or modifies GLPI assets (D2, D15).
- Match modes live only in `Config::MATCH_MODES = ['ip_only','name_only','hybrid','hybrid_name','manual']`, default `hybrid_name`. A manual pin always wins. An ambiguous hit (IP or name owned by >1 asset) is never used, so the device ends as `review` unless a later step finds exactly one.
- Security/Storage are GLPI 11 custom assets `Glpi\CustomAsset\<system_name>Asset`. They are **not loaded at `plugin_init`**. Read them from active `glpi_assets_assetdefinitions` rows, pass them as strings, and never filter with `class_exists()` at init.
- Never call `Session::checkCSRF()`; `$PLUGIN_HOOKS['csrf_compliant']['opmanager'] = true`. `echo Html::submit(...)` / `echo Html::hidden(...)`. Form actions via `Html::getPrefixedUrl()` / `getFormURL()`, never `PHP_SELF`.
- Single-item pages use `<Itemtype>::displayFullPageForItem($id, ['central' => ['opmanager', '<entry>'], 'helpdesk' => []], [])`.
- No count badges anywhere. Asset-tab sections carry no pager and show a visible "Showing N of M" line.
- Entity restriction applies only to display. Cron and sync never scope by entity.
- API key is secured via `$PLUGIN_HOOKS['secured_configs']['opmanager'] = ['opm_api_key']`. Core encrypts it on write; the plugin decrypts it with `(new GLPIKey())->decrypt()`. The key never appears in HTML, logs or exception messages.
- Cron tasks are registered in `CronTask::MODE_EXTERNAL` (CLI).
- Do not read, grep or copy `C:/Users/teguh.umar/Downloads/glpi/config/` or `…/files/`. Only `src/`, `inc/`, `templates/`, `plugins/`, `vendor/` may be read.
- Tag every version locally; push to GitHub only on a major version.
- Local PHP: `C:/php/php.exe` (no mbstring → do not use `mb_*` in code the offline tests load).

## Review Focus

1. **A device whose IP is owned by two GLPI assets** (the known duplicate-IP problem) must end in `review` and never pick one. Pinned in Task 5 (`ambiguous IP never guesses`).
2. **Saving Setup with the API key field left empty** must keep the stored key, and the key must never be echoed into the form HTML. Pinned in Task 2 (`blank api key keeps stored key`) and Task 4 (`api key never rendered`).
3. **An OpManager failure or timeout** must produce a log/exception message **without** the `apiKey` (Guzzle messages embed the full URL with query string). Pinned in Task 7 (`redact removes apiKey`).
4. **A manual pin survives every DeviceSync**, in every mode including `manual`. Pinned in Task 8 (`manual pin never rematched`).
5. **A configured custom asset whose class cannot be resolved at runtime** (e.g. under CLI cron) must be reported, not silently turned into "unmatched" devices. Pinned in Task 6 (`unresolvable itemtypes reported`) and the selfcheck in Task 11.

---

## File map

| File | Responsibility |
|---|---|
| `setup.php` | Version, requirements, `plugin_init_opmanager()` wiring |
| `composer.json` | PSR-4 declaration |
| `install/install.php`, `install/uninstall.php` | Schema, config defaults, rights seeding, cron registration |
| `lib/pgnsuite/ConfigTab.php`, `lib/pgnsuite/AssetTab.php` | Canonical shared suite classes (copied, never edited) |
| `src/Config.php` | Config keys, defaults, `MATCH_MODES`, sanitize, asset itemtype list |
| `src/CustomAssets.php` | Active custom asset definitions from the DB |
| `src/RightDefinition.php` | Right names and masks |
| `src/RightSeeder.php` | Pure rights-seeding plan |
| `src/Profile.php` | Rights tab on Administration → Profiles |
| `src/SuiteConfig.php` | Section in the PGN Suite config tab + the form |
| `src/Matching/AssetLookup.php` | Lookup interface |
| `src/Matching/Matcher.php` | Pure matching algorithm |
| `src/Matching/DbAssetLookup.php` | GLPI DB lookup by name / IP |
| `src/OpManager/ClientException.php`, `src/OpManager/DeviceSource.php`, `src/OpManager/Client.php`, `src/OpManager/DeviceParser.php` | OpManager HTTP + response parsing |
| `src/Device.php` | Itemtype for `glpi_plugin_opmanager_devices`: search options, form, pin/unpin |
| `src/DeviceStore.php`, `src/DbDeviceStore.php` | Persistence port + DB adapter |
| `src/DeviceSync.php` | Pure sync orchestration |
| `src/Cron.php` | `DeviceSync` automatic action |
| `src/Menu.php` | Own sidebar sector `opmanager` |
| `src/AssetSections.php` | Monitoring section provider for `AssetTab` |
| `src/SelfCheck.php`, `tools/selfcheck.php` | Runtime checks on the VM |
| `front/config.php`, `front/device.php`, `front/device.form.php` | Controllers |
| `tests/*` | Offline suites |
| `tools/release_check.py` | Zip verification + install into local GLPI copy |
| `docs/runbook-v0.1.0.md` | VM verification runbook |

---

### Task 1: Scaffold, shared suite files, test harness, hub claim

**Files:**
- Create: `setup.php`, `composer.json`, `.gitignore`, `lib/pgnsuite/ConfigTab.php`, `lib/pgnsuite/AssetTab.php`, `tests/bootstrap.php`, `tests/run_all.php`, `tests/lib_sha_test.php`
- Modify: `.gitattributes`, hub `…/PGN GLPI Suite/_pgn-glpi-suite.md` (§2 claim table, §7)

**Interfaces:**
- Produces: `tests/bootstrap.php` with `check(string $label, bool $ok, string $detail = ''): void`, `done(): void`, constant `OPM_ROOT`, stubs `__`, `__s`, `_n`, `_sx`, `htmlescape`, constants `READ=1 UPDATE=2 CREATE=4 DELETE=8 PURGE=16`.

- [ ] **Step 1: Write the failing test**

`tests/bootstrap.php`:

```php
<?php

// Offline test harness: no GLPI, no DB.
// Run one suite:  C:/php/php.exe tests/<name>_test.php
// Run all:        C:/php/php.exe tests/run_all.php
// Count results with an ANCHORED grep ('^PASS'), never a bare 'PASS' (hub §4).
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('OPM_ROOT', dirname(__DIR__));

foreach (['READ' => 1, 'UPDATE' => 2, 'CREATE' => 4, 'DELETE' => 8, 'PURGE' => 16] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

$GLOBALS['__opm_pass'] = 0;
$GLOBALS['__opm_fail'] = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['__opm_pass']++;
        echo "PASS {$label}\n";
        return;
    }
    $GLOBALS['__opm_fail']++;
    echo "FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function done(): void
{
    $pass = $GLOBALS['__opm_pass'];
    $fail = $GLOBALS['__opm_fail'];
    echo ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ({$pass} passed, {$fail} failed)\n";
    exit($fail === 0 ? 0 : 1);
}

if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('__s')) {
    function __s($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('_n')) {
    function _n($s, $p, $n, $d = null) { return $n == 1 ? $s : $p; }
}
if (!function_exists('_sx')) {
    function _sx($ctx, $s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('htmlescape')) {
    function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
```

`tests/run_all.php`:

```php
<?php

// Runs every tests/*_test.php in its own PHP process (stubs differ per suite).
$failed = 0;
foreach (glob(__DIR__ . '/*_test.php') as $file) {
    echo '== ' . basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $code);
    if ($code !== 0) {
        $failed++;
    }
}
echo $failed === 0 ? "SUITES OK\n" : "SUITES FAILED: {$failed}\n";
exit($failed === 0 ? 0 : 1);
```

`tests/lib_sha_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';

// The shared PGN Suite classes must be byte-identical to the canonical copies
// in …/PGN GLPI Suite/shared/pgnsuite/ (hub §5). No line-ending normalisation
// here on purpose: the file itself must be exact.
$expected = [
    'lib/pgnsuite/ConfigTab.php' => '008C4C817DB2E42040915136108CD41917C34760A8F4CDDC60E46BDB208F39D0',
    'lib/pgnsuite/AssetTab.php'  => 'C7F6C88B58BE9A7AAF172F3A2F2DBAAFBB07B0CE1898BA5A74A0EDB61EA143A9',
];
foreach ($expected as $rel => $sha) {
    $path   = OPM_ROOT . '/' . $rel;
    $actual = is_file($path) ? strtoupper(hash_file('sha256', $path)) : 'missing';
    check("{$rel} is canonical", $actual === $sha, "got {$actual}");
}

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/lib_sha_test.php`
Expected: `FAIL lib/pgnsuite/ConfigTab.php is canonical -- got missing` (twice), exit 1.

- [ ] **Step 3: Copy the canonical files and write the scaffold**

```bash
mkdir -p lib/pgnsuite
cp "/c/Users/teguh.umar/OneDrive - PT Pertamina (Persero)/Documents/PGN GLPI Suite/shared/pgnsuite/ConfigTab.php" lib/pgnsuite/ConfigTab.php
cp "/c/Users/teguh.umar/OneDrive - PT Pertamina (Persero)/Documents/PGN GLPI Suite/shared/pgnsuite/AssetTab.php"  lib/pgnsuite/AssetTab.php
```

`.gitattributes` (replace the whole file):

```
lib/pgnsuite/ConfigTab.php -text
lib/pgnsuite/AssetTab.php -text
tests export-ignore
docs export-ignore
tools/release_check.py export-ignore
.gitignore export-ignore
.gitattributes export-ignore
```

`.gitignore`:

```
*.zip
review_integrasi.md
*.code-workspace
```

`composer.json`:

```json
{
    "name": "pgn-ict/opmanager",
    "description": "ManageEngine OpManager integration for GLPI 11 (PGN GLPI Suite)",
    "type": "glpi-plugin",
    "license": "GPL-3.0-or-later",
    "autoload": {
        "psr-4": {
            "GlpiPlugin\\Opmanager\\": "src/"
        }
    }
}
```

`setup.php`:

```php
<?php

/**
 * OpManager - ManageEngine OpManager <-> GLPI 11 integration (PGN GLPI Suite plugin #7)
 *
 * @author  PGN ICT
 * @license GPL-3.0-or-later
 */

define('PLUGIN_OPMANAGER_VERSION', '0.1.0');
define('PLUGIN_OPMANAGER_MIN_GLPI', '11.0.0');
define('PLUGIN_OPMANAGER_MAX_GLPI', '11.99.99');

function plugin_version_opmanager()
{
    return [
        'name'         => 'OpManager',
        'version'      => PLUGIN_OPMANAGER_VERSION,
        'author'       => 'PGN ICT',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_OPMANAGER_MIN_GLPI,
                'max' => PLUGIN_OPMANAGER_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_opmanager_check_prerequisites()
{
    return true;
}

function plugin_opmanager_check_config()
{
    return true;
}

function plugin_init_opmanager()
{
    global $PLUGIN_HOOKS;

    // GLPI 11's CheckCsrfListener validates + consumes CSRF tokens before a
    // legacy front file runs - never call Session::checkCSRF() ourselves.
    $PLUGIN_HOOKS['csrf_compliant']['opmanager'] = true;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/lib_sha_test.php && C:/php/php.exe -l setup.php`
Expected: `ALL PASS (2 passed, 0 failed)` and `No syntax errors detected in setup.php`.

- [ ] **Step 5: Claim the second right and the menu sector in the hub**

In `…/PGN GLPI Suite/_pgn-glpi-suite.md`, in the §2 claim table's `opmanager` column:
- Profile rights row: replace `further \`plugin_opmanager_*\` names TBD at design` with `\`plugin_opmanager_monitoring\` (READ) — claimed <today>`.
- Menu node row: replace the cell with `own top-level sector \`opmanager\` (entry \`device\`; no Setup entry — settings in PGN Suite tab)`.

Append a dated §7 entry (newest first) recording both claims. Bump the header "Last updated" line with your session name.

- [ ] **Step 6: Commit**

```bash
git add .gitattributes .gitignore composer.json setup.php lib tests
git commit -m "feat: scaffold opmanager plugin with canonical PGN Suite classes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Config, rights, custom assets

**Files:**
- Create: `src/Config.php`, `src/RightDefinition.php`, `src/CustomAssets.php`, `tests/config_test.php`

**Interfaces:**
- Consumes: `tests/bootstrap.php`.
- Produces:
  - `Config::CONTEXT = 'plugin:opmanager'`, `Config::MATCH_MODES`, `Config::SECURED = ['opm_api_key']`, `Config::BASE_ASSET_ITEMTYPES = ['Computer','NetworkEquipment']`, `Config::DEFAULTS` (keys `opm_url`, `opm_api_key`, `opm_verify_ssl`, `match_mode`, `asset_itemtypes`, `stale_warning_minutes`, `last_device_sync`, `last_device_sync_error`)
  - `Config::get(string $key): string`, `Config::set(array $values): void`, `Config::apiKey(): string`, `Config::setDefaults(): void`
  - `Config::matchModeLabels(): array<string,string>`
  - `Config::sanitize(array $post, array $allowed_itemtypes): array{values: array<string,string>, errors: list<string>}`
  - `Config::filterItemtypes(string $json, array $offered): list<string>`, `Config::assetItemtypes(): list<string>`, `Config::offeredItemtypes(): array<string,string>`
  - `RightDefinition::CONFIG`, `RightDefinition::MONITORING`, `RightDefinition::ALL`, `RightDefinition::names(): list<string>`, `RightDefinition::rightsMatrix(string $name): array<int,string>`
  - `CustomAssets::active(): array<string,string>` (class name ⇒ label)

- [ ] **Step 1: Write the failing test**

`tests/config_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Config.php';
require OPM_ROOT . '/src/RightDefinition.php';

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;

// --- single source of truth for match modes (integrasipaman v2.6.0 lesson) ---
check('five match modes, manual included', Config::MATCH_MODES === ['ip_only', 'name_only', 'hybrid', 'hybrid_name', 'manual']);
check('default match mode is a valid mode', in_array(Config::DEFAULTS['match_mode'], Config::MATCH_MODES, true));
check('dropdown labels cover exactly MATCH_MODES', array_keys(Config::matchModeLabels()) === Config::MATCH_MODES);
check('secured keys all have defaults', array_diff(Config::SECURED, array_keys(Config::DEFAULTS)) === []);

$allowed = ['Computer', 'NetworkEquipment', 'Glpi\\CustomAsset\\SecurityAsset'];
$base = [
    'opm_url'               => 'https://opm.pgn.co.id:8061/',
    'opm_verify_ssl'        => '1',
    'match_mode'            => 'hybrid_name',
    'asset_itemtypes'       => ['Computer', 'Glpi\\CustomAsset\\SecurityAsset', 'Evil'],
    'stale_warning_minutes' => '30',
];

// --- api key handling (Review Focus 2) ---
$r = Config::sanitize($base + ['opm_api_key' => ''], $allowed);
check('blank api key keeps stored key', !array_key_exists('opm_api_key', $r['values']));
$r = Config::sanitize($base + ['opm_api_key' => '  abc123  '], $allowed);
check('new api key is stored trimmed', ($r['values']['opm_api_key'] ?? null) === 'abc123');
$r = Config::sanitize($base + ['opm_api_key' => 'abc', 'opm_api_key_clear' => '1'], $allowed);
check('clear checkbox wipes the key', ($r['values']['opm_api_key'] ?? null) === '');

// --- url ---
$r = Config::sanitize($base, $allowed);
check('trailing slash trimmed from url', $r['values']['opm_url'] === 'https://opm.pgn.co.id:8061');
check('valid form has no errors', $r['errors'] === [], implode('; ', $r['errors']));
$r = Config::sanitize(['opm_url' => 'ftp://x'] + $base, $allowed);
check('non-http url rejected and not stored', !array_key_exists('opm_url', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitize(['opm_url' => ''] + $base, $allowed);
check('empty url allowed (not configured yet)', $r['values']['opm_url'] === '');

// --- mode, itemtypes, ssl, stale ---
$r = Config::sanitize(['match_mode' => 'hybrid_magic'] + $base, $allowed);
check('unknown mode rejected and not stored', !array_key_exists('match_mode', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitize(['match_mode' => 'manual'] + $base, $allowed);
check('manual mode accepted', $r['values']['match_mode'] === 'manual');
$r = Config::sanitize($base, $allowed);
check('only offered itemtypes stored', json_decode($r['values']['asset_itemtypes'], true) === ['Computer', 'Glpi\\CustomAsset\\SecurityAsset']);
$b2 = $base;
unset($b2['opm_verify_ssl']);
$r = Config::sanitize($b2, $allowed);
check('unchecked ssl box stores 0', $r['values']['opm_verify_ssl'] === '0');
$r = Config::sanitize(['stale_warning_minutes' => '2'] + $base, $allowed);
check('stale below 5 rejected', !array_key_exists('stale_warning_minutes', $r['values']) && count($r['errors']) === 1);

// --- itemtype filtering never needs the class to exist (hub §5) ---
check('bad json gives no itemtypes', Config::filterItemtypes('not json', $allowed) === []);
check('custom asset string kept without class_exists', Config::filterItemtypes('["Glpi\\\\CustomAsset\\\\SecurityAsset","Gone"]', $allowed) === ['Glpi\\CustomAsset\\SecurityAsset']);
check('Config.php never calls class_exists', !str_contains((string) file_get_contents(OPM_ROOT . '/src/Config.php'), 'class_exists'));

// --- rights ---
check('two rights', RightDefinition::names() === ['plugin_opmanager_config', 'plugin_opmanager_monitoring']);
check('config right is READ|UPDATE', RightDefinition::ALL[RightDefinition::CONFIG] === (READ | UPDATE));
check('monitoring right is READ only', RightDefinition::ALL[RightDefinition::MONITORING] === READ);
check('matrix columns match masks', array_keys(RightDefinition::rightsMatrix(RightDefinition::MONITORING)) === [READ]);

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/config_test.php`
Expected: fatal `Failed opening required '…/src/Config.php'`, exit non-zero.

- [ ] **Step 3: Write the implementation**

`src/RightDefinition.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

/**
 * Single source of truth for the plugin's profile rights.
 *
 * | right                       | mask          | gates                                   |
 * |-----------------------------|---------------|-----------------------------------------|
 * | plugin_opmanager_config     | READ | UPDATE | Setup section, manual pin/unpin         |
 * | plugin_opmanager_monitoring | READ          | Monitoring section, Devices list/form   |
 */
final class RightDefinition
{
    public const CONFIG     = 'plugin_opmanager_config';
    public const MONITORING = 'plugin_opmanager_monitoring';

    /** right name => allowed mask */
    public const ALL = [
        self::CONFIG     => READ | UPDATE,
        self::MONITORING => READ,
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ALL);
    }

    /** @return array<int, string> column labels for Profile::displayRightsChoiceMatrix */
    public static function rightsMatrix(string $name): array
    {
        return match ($name) {
            self::CONFIG     => [READ => __('Read'), UPDATE => __('Update')],
            self::MONITORING => [READ => __('Read')],
            default          => [],
        };
    }
}
```

`src/CustomAssets.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use Glpi\Asset\AssetDefinition;

/**
 * Active GLPI 11 custom asset definitions (Setup > Asset definitions), read straight
 * from the definitions table. plugin_init runs before core boots custom objects
 * (InitializePlugins 110 > CustomObjectsBoot 100), so the classes are neither
 * autoloadable nor in $CFG_GLPI['asset_types'] at that point (hub §5). Never
 * class_exists() these names.
 */
final class CustomAssets
{
    /** @return array<string, string> class name => label */
    public static function active(): array
    {
        global $DB;

        $table = AssetDefinition::getTable();
        if (!$DB->tableExists($table)) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['system_name', 'label'],
            'FROM'   => $table,
            'WHERE'  => ['is_active' => 1],
        ]) as $row) {
            $class = AssetDefinition::getCustomObjectNamespace() . '\\' . $row['system_name']
                . AssetDefinition::getCustomObjectClassSuffix();
            $out[$class] = (string) ($row['label'] ?: $row['system_name']);
        }
        return $out;
    }
}
```

`src/Config.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

final class Config
{
    public const CONTEXT = 'plugin:opmanager';

    /**
     * Every accepted match mode, in dropdown order. The ONLY list: the dropdown,
     * Matcher and front/config.php all read it (integrasipaman v2.6.0 shipped a
     * mode its save controller silently rejected - hub §4).
     */
    public const MATCH_MODES = ['ip_only', 'name_only', 'hybrid', 'hybrid_name', 'manual'];

    /** Encrypted at rest by core via $PLUGIN_HOOKS['secured_configs'] (GLPIKey::getConfigs). */
    public const SECURED = ['opm_api_key'];

    public const BASE_ASSET_ITEMTYPES = ['Computer', 'NetworkEquipment'];

    public const DEFAULTS = [
        'opm_url'                => '',
        'opm_api_key'            => '',
        'opm_verify_ssl'         => '1',
        'match_mode'             => 'hybrid_name',
        'asset_itemtypes'        => '["Computer","NetworkEquipment"]',
        'stale_warning_minutes'  => '30',
        'last_device_sync'       => '',
        'last_device_sync_error' => '',
    ];

    public static function get(string $key): string
    {
        $values = \Config::getConfigurationValues(self::CONTEXT, [$key]);
        return (string) ($values[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    /** @param array<string, string> $values */
    public static function set(array $values): void
    {
        \Config::setConfigurationValues(self::CONTEXT, $values);
    }

    /** Core encrypts secured values on write but never decrypts on read. */
    public static function apiKey(): string
    {
        $raw = self::get('opm_api_key');
        return $raw === '' ? '' : (string) ((new \GLPIKey())->decrypt($raw) ?? '');
    }

    public static function setDefaults(): void
    {
        $current = \Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::DEFAULTS, $current);
        if ($missing !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /** @return array<string, string> mode => label, keys identical to MATCH_MODES */
    public static function matchModeLabels(): array
    {
        return [
            'ip_only'     => __('IP only', 'opmanager'),
            'name_only'   => __('Name only', 'opmanager'),
            'hybrid'      => __('Hybrid - IP first, then name', 'opmanager'),
            'hybrid_name' => __('Hybrid - name first, then IP', 'opmanager'),
            'manual'      => __('Manual only - no automatic matching', 'opmanager'),
        ];
    }

    /**
     * Validate a POST from the Setup form. Pure. A key missing from `values`
     * means "keep what is stored" (e.g. a blank API key field).
     *
     * @param array<string, mixed> $post
     * @param string[]             $allowed_itemtypes itemtypes the form offered
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitize(array $post, array $allowed_itemtypes): array
    {
        $values = [];
        $errors = [];

        $url = trim((string) ($post['opm_url'] ?? ''));
        if ($url === '' || preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $url) === 1) {
            $values['opm_url'] = rtrim($url, '/');
        } else {
            $errors[] = __('OpManager URL must start with http:// or https://', 'opmanager');
        }

        if (!empty($post['opm_api_key_clear'])) {
            $values['opm_api_key'] = '';
        } else {
            $key = trim((string) ($post['opm_api_key'] ?? ''));
            if ($key !== '') {
                $values['opm_api_key'] = $key;
            }
        }

        $values['opm_verify_ssl'] = empty($post['opm_verify_ssl']) ? '0' : '1';

        $mode = (string) ($post['match_mode'] ?? '');
        if (in_array($mode, self::MATCH_MODES, true)) {
            $values['match_mode'] = $mode;
        } else {
            $errors[] = __('Unknown match mode', 'opmanager');
        }

        $posted = array_filter((array) ($post['asset_itemtypes'] ?? []), 'is_string');
        $values['asset_itemtypes'] = (string) json_encode(array_values(array_intersect($posted, $allowed_itemtypes)));

        $stale = (int) ($post['stale_warning_minutes'] ?? 0);
        if ($stale >= 5 && $stale <= 1440) {
            $values['stale_warning_minutes'] = (string) $stale;
        } else {
            $errors[] = __('Stale warning must be between 5 and 1440 minutes', 'opmanager');
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param string[] $offered
     * @return list<string>
     */
    public static function filterItemtypes(string $json, array $offered): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_intersect(array_filter($decoded, 'is_string'), $offered));
    }

    /** @return list<string> configured asset itemtypes still on offer */
    public static function assetItemtypes(): array
    {
        return self::filterItemtypes(self::get('asset_itemtypes'), array_keys(self::offeredItemtypes()));
    }

    /** @return array<string, string> itemtype => label (base types labelled by class name) */
    public static function offeredItemtypes(): array
    {
        $out = [];
        foreach (self::BASE_ASSET_ITEMTYPES as $itemtype) {
            $out[$itemtype] = $itemtype;
        }
        return $out + CustomAssets::active();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/config_test.php && C:/php/php.exe -l src/CustomAssets.php`
Expected: `ALL PASS (23 passed, 0 failed)`; `No syntax errors detected`.

- [ ] **Step 5: Prove the single-source test bites**

Temporarily add `'hybrid_magic' => 'x',` to the array returned by `matchModeLabels()`. Run the suite and confirm `FAIL dropdown labels cover exactly MATCH_MODES`. Then revert and re-run to confirm green.

- [ ] **Step 6: Commit**

```bash
git add src/Config.php src/RightDefinition.php src/CustomAssets.php tests/config_test.php
git commit -m "feat: config keys, match modes and rights definitions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Install / uninstall, rights seeding, schema rules

**Files:**
- Create: `src/RightSeeder.php`, `install/install.php`, `install/uninstall.php`, `tests/rightseeder_test.php`, `tests/schema_test.php`
- Modify: `setup.php` (include install files)

**Interfaces:**
- Consumes: `RightDefinition` (Task 2), `Config::setDefaults`, `Config::CONTEXT`, `Config::DEFAULTS`.
- Produces: `RightSeeder::plan(array $new_rights, array $profiles): array<int, array<string,int>>`, where `$profiles` is `array<int, array{interface: string, config: int, computer: int}>`. Also produces the functions `plugin_opmanager_install(): bool` and `plugin_opmanager_uninstall(): bool`, and the table `glpi_plugin_opmanager_devices`.

- [ ] **Step 1: Write the failing tests**

`tests/rightseeder_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/RightSeeder.php';

use GlpiPlugin\Opmanager\RightDefinition as R;
use GlpiPlugin\Opmanager\RightSeeder;

$profiles = [
    4 => ['interface' => 'central',  'config' => READ | UPDATE, 'computer' => 31], // super-admin
    6 => ['interface' => 'central',  'config' => READ,          'computer' => READ], // technician
    7 => ['interface' => 'central',  'config' => 0,             'computer' => 0],
    1 => ['interface' => 'helpdesk', 'config' => 0,             'computer' => 0],
];

// Fresh install: both rights are new.
$plan = RightSeeder::plan(R::names(), $profiles);
check('fresh: super-admin gets full mask', ($plan[4] ?? null) === R::ALL);
check('fresh: technician config mirrors core config', ($plan[6][R::CONFIG] ?? null) === READ);
check('fresh: technician monitoring from computer READ', ($plan[6][R::MONITORING] ?? null) === READ);
check('fresh: profile without core rights gets zeros', ($plan[7] ?? null) === [R::CONFIG => 0, R::MONITORING => 0]);
check('fresh: helpdesk untouched', !isset($plan[1]));

// Upgrade: nothing new. Only super-admin is (re)written - hub §4 exception.
$plan = RightSeeder::plan([], $profiles);
check('upgrade: only super-admin written', array_keys($plan) === [4]);
check('upgrade: technician hand-tuned value survives', !isset($plan[6]));

// Upgrade adding only the monitoring right: config must not be re-derived.
$plan = RightSeeder::plan([R::MONITORING], $profiles);
check('partial: technician gets only the new right', ($plan[6] ?? null) === [R::MONITORING => READ]);

// Hardened instance: no profile holds config UPDATE -> nobody gets full.
$plan = RightSeeder::plan([], [6 => $profiles[6]]);
check('no super-admin: nothing granted', $plan === []);

done();
```

`tests/schema_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';

// Suite schema rules (hub §4 rules 1, 2c, 2d) checked against the literal DDL.
$src = (string) file_get_contents(OPM_ROOT . '/install/install.php');
preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\) ENGINE/s', $src, $tables, PREG_SET_ORDER);
check('install creates the devices table', in_array('glpi_plugin_opmanager_devices', array_column($tables, 1), true));

foreach ($tables as [, $table, $body]) {
    check("{$table}: no DATETIME column", stripos($body, 'DATETIME') === false);
    preg_match_all('/^\s*`(\w+)`\s+([^,\n]+)/m', $body, $cols, PREG_SET_ORDER);
    foreach ($cols as [, $col, $type]) {
        if ($col === 'id' || str_ends_with($col, '_id')) {
            check("{$table}.{$col} is INT UNSIGNED", (bool) preg_match('/^INT UNSIGNED\b/i', trim($type)), trim($type));
        }
        if (str_ends_with($col, '_key')) {
            check("{$table}.{$col} external id is VARCHAR", stripos($type, 'VARCHAR') === 0);
        }
    }
}
check('devices.device_key is UNIQUE', (bool) preg_match('/UNIQUE KEY `device_key` \(`device_key`\)/', $src));

done();
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:/php/php.exe tests/rightseeder_test.php; C:/php/php.exe tests/schema_test.php`
Expected: first fatals on the missing `src/RightSeeder.php`. The second prints `FAIL install creates the devices table` (file missing ⇒ empty source), exit 1.

- [ ] **Step 3: Write the implementation**

`src/RightSeeder.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

/**
 * Pure plan for which profile rights to write on install/upgrade (hub §4):
 * - `plugin:install --force` re-runs install on every upgrade, so only rights
 *   created in THIS run are seeded; existing values are never re-derived.
 * - A profile holding core `config` UPDATE is the super-admin and gets the full
 *   mask on every run (user directive 2026-09-16). Identified by right, never by name.
 */
final class RightSeeder
{
    /**
     * @param string[]                                                  $new_rights
     * @param array<int, array{interface: string, config: int, computer: int}> $profiles
     * @return array<int, array<string, int>> profile id => rights to write
     */
    public static function plan(array $new_rights, array $profiles): array
    {
        $out = [];
        foreach ($profiles as $id => $profile) {
            if (($profile['config'] & UPDATE) === UPDATE) {
                $out[$id] = RightDefinition::ALL;
                continue;
            }
            if ($profile['interface'] !== 'central' || $new_rights === []) {
                continue;
            }
            $rights = [];
            foreach ($new_rights as $name) {
                $rights[$name] = match ($name) {
                    RightDefinition::CONFIG     => $profile['config'] & (READ | UPDATE),
                    RightDefinition::MONITORING => $profile['computer'] & READ,
                    default                     => 0,
                };
            }
            $out[$id] = $rights;
        }
        return $out;
    }
}
```

`install/install.php`:

```php
<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;
use GlpiPlugin\Opmanager\RightSeeder;

function plugin_opmanager_install()
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    // Suite schema rules (hub §4): keys INT UNSIGNED; OpManager identifiers are
    // VARCHAR *_key (an INT *_id counts as a GLPI key); dates TIMESTAMP only.
    $table = 'glpi_plugin_opmanager_devices';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `device_key` VARCHAR(255) NOT NULL DEFAULT '',
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `display_name` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `device_type` VARCHAR(255) NOT NULL DEFAULT '',
            `device_category` VARCHAR(255) NOT NULL DEFAULT '',
            `availability` VARCHAR(32) NOT NULL DEFAULT '',
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `match_state` VARCHAR(16) NOT NULL DEFAULT 'unmatched',
            `match_source` VARCHAR(16) NOT NULL DEFAULT '',
            `is_manual` TINYINT NOT NULL DEFAULT 0,
            `last_seen` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `device_key` (`device_key`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `match_state` (`match_state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
    }

    Config::setDefaults();

    $new_rights = array_values(array_diff(
        RightDefinition::names(),
        array_keys(ProfileRight::getAllPossibleRights())
    ));
    if ($new_rights !== []) {
        ProfileRight::addProfileRights($new_rights);
    }

    // All profiles from the DB - never $_SESSION (a CLI install has none).
    $profiles = [];
    foreach ($DB->request(['SELECT' => ['id', 'interface'], 'FROM' => 'glpi_profiles']) as $row) {
        $profiles[(int) $row['id']] = ['interface' => (string) $row['interface'], 'config' => 0, 'computer' => 0];
    }
    foreach ($DB->request([
        'SELECT' => ['profiles_id', 'name', 'rights'],
        'FROM'   => 'glpi_profilerights',
        'WHERE'  => ['name' => ['config', 'computer']],
    ]) as $row) {
        $pid = (int) $row['profiles_id'];
        if (isset($profiles[$pid])) {
            $profiles[$pid][$row['name']] = (int) $row['rights'];
        }
    }
    foreach (RightSeeder::plan($new_rights, $profiles) as $pid => $rights) {
        ProfileRight::updateProfileRights($pid, $rights);
    }

    return true;
}
```

`install/uninstall.php`:

```php
<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;

function plugin_opmanager_uninstall()
{
    global $DB;

    foreach (['glpi_plugin_opmanager_devices'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    ProfileRight::deleteProfileRights(RightDefinition::names());
    \Config::deleteConfigurationValues(Config::CONTEXT, array_keys(Config::DEFAULTS));

    return true;
}
```

In `setup.php`, directly below the three `define(...)` lines, add:

```php
include_once(__DIR__ . '/install/install.php');
include_once(__DIR__ . '/install/uninstall.php');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/rightseeder_test.php && C:/php/php.exe tests/schema_test.php && C:/php/php.exe -l install/install.php && C:/php/php.exe -l install/uninstall.php`
Expected: `ALL PASS (9 passed, 0 failed)`, `ALL PASS` for schema, and `No syntax errors detected` twice.

- [ ] **Step 5: Prove the schema test bites**

Temporarily change `` `items_id` INT UNSIGNED `` to `` `items_id` INT `` in `install/install.php`. Run `tests/schema_test.php` and confirm `FAIL glpi_plugin_opmanager_devices.items_id is INT UNSIGNED`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/RightSeeder.php install tests/rightseeder_test.php tests/schema_test.php setup.php
git commit -m "feat: install/uninstall, devices table and rights seeding

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Rights tab, Setup section, config controller

**Files:**
- Create: `src/Profile.php`, `src/SuiteConfig.php`, `front/config.php`, `tests/suiteconfig_test.php`
- Modify: `setup.php` (`plugin_init_opmanager`)

**Interfaces:**
- Consumes: `Config::*`, `RightDefinition::*` (Task 2).
- Produces: `SuiteConfig::SECTION = 'opmanager'`, `SuiteConfig::FROM_SUITE_FIELD = '_from_pgnsuite'`, `SuiteConfig::section(): ?array`, `SuiteConfig::render(): void`, `SuiteConfig::showForm(bool $from_suite = false): void`, and the class `Profile` (rights tab).

- [ ] **Step 1: Write the failing test**

`tests/suiteconfig_test.php`:

```php
<?php

namespace {
    require __DIR__ . '/bootstrap.php';

    $GLOBALS['RIGHTS'] = [];
    $GLOBALS['CFG_VALUES'] = [];

    class CommonGLPI {}
    class Session
    {
        public static function haveRight($name, $mask)
        {
            return ((int) ($GLOBALS['RIGHTS'][$name] ?? 0) & $mask) === $mask;
        }
    }
    class Config
    {
        public static function getConfigurationValues($context, $names = [])
        {
            return $names === [] ? $GLOBALS['CFG_VALUES'] : array_intersect_key($GLOBALS['CFG_VALUES'], array_flip($names));
        }
    }
    class Html
    {
        public static function getPrefixedUrl($u) { return $u; }
        public static function hidden($n, $o = []) { return "<input type='hidden' name='{$n}' value='" . ($o['value'] ?? '') . "'>"; }
        public static function submit($l, $o = []) { return "<button name='" . ($o['name'] ?? '') . "'>{$l}</button>"; }
        public static function closeForm($display = true) { echo '</form>'; }
    }
    class Dropdown
    {
        public static function showFromArray($name, $elements, $options = [])
        {
            echo "<select name='{$name}'>";
            foreach ($elements as $k => $v) {
                echo "<option value='{$k}'" . (($options['value'] ?? '') === $k ? ' selected' : '') . ">{$v}</option>";
            }
            echo '</select>';
        }
    }
    class FakeDB { public function tableExists($t) { return false; } }
    $GLOBALS['DB'] = new FakeDB();
}

namespace Glpi\Asset {
    class AssetDefinition
    {
        public static function getTable() { return 'glpi_assets_assetdefinitions'; }
    }
}

namespace {
    require OPM_ROOT . '/src/Config.php';
    require OPM_ROOT . '/src/CustomAssets.php';
    require OPM_ROOT . '/src/RightDefinition.php';
    require OPM_ROOT . '/src/SuiteConfig.php';

    use GlpiPlugin\Opmanager\RightDefinition as R;
    use GlpiPlugin\Opmanager\SuiteConfig;

    check('no config right: section hidden', SuiteConfig::section() === null);

    $GLOBALS['RIGHTS'] = [R::CONFIG => READ];
    $section = SuiteConfig::section();
    check('config READ: section shown', is_array($section));
    check('render is a Class::method string', ($section['render'] ?? null) === 'GlpiPlugin\\Opmanager\\SuiteConfig::render');

    $GLOBALS['CFG_VALUES'] = ['opm_api_key' => 'ENCRYPTED-SECRET-VALUE', 'opm_url' => 'https://opm'];
    ob_start();
    SuiteConfig::showForm(true);
    $html = (string) ob_get_clean();
    check('api key never rendered', !str_contains($html, 'ENCRYPTED-SECRET-VALUE'));
    check('stored key shown as a placeholder only', str_contains($html, 'placeholder'));
    check('read-only user sees no save button', !str_contains($html, "name='update'"));
    check('suite marker present when rendered in the tab', str_contains($html, "name='_from_pgnsuite'"));
    check('posts to own controller', str_contains($html, '/plugins/opmanager/front/config.php'));

    $GLOBALS['RIGHTS'] = [R::CONFIG => READ | UPDATE];
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('editor sees save button', str_contains($html, "name='update'"));
    check('standalone page has no suite marker', !str_contains($html, '_from_pgnsuite'));
    check('dropdown offers every match mode', substr_count($html, '<option value=') >= 5);

    done();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/suiteconfig_test.php`
Expected: fatal on the missing `src/SuiteConfig.php`.

- [ ] **Step 3: Write the implementation**

`src/SuiteConfig.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

/**
 * OpManager's section in the shared "PGN Suite" tab on Setup > General (hub §5).
 * The same form renders on front/config.php for admins without core `config` READ.
 */
final class SuiteConfig
{
    public const SECTION = 'opmanager';

    /** Hidden field marking a POST that came from the PGN Suite tab. */
    public const FROM_SUITE_FIELD = '_from_pgnsuite';

    public static function section(): ?array
    {
        if (!\Session::haveRight(RightDefinition::CONFIG, READ)) {
            return null;
        }
        return [
            'title'  => 'OpManager',
            'icon'   => 'ti ti-activity',
            'order'  => 70,
            'render' => self::class . '::render',
        ];
    }

    public static function render(): void
    {
        self::showForm(true);
    }

    public static function showForm(bool $from_suite = false): void
    {
        $canedit  = \Session::haveRight(RightDefinition::CONFIG, UPDATE);
        $disabled = $canedit ? '' : ' disabled';
        $sfx      = $from_suite ? '-suite' : '';

        echo '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/config.php')) . '">';
        if ($from_suite) {
            echo \Html::hidden(self::FROM_SUITE_FIELD, ['value' => 1]);
        }

        echo '<fieldset class="mb-3"><legend class="fs-4">' . __s('OpManager connection', 'opmanager') . '</legend>';
        echo self::textRow('opm_url' . $sfx, 'opm_url', __('OpManager URL', 'opmanager'), Config::get('opm_url'), 'url', $disabled);

        // The stored key is NEVER echoed back (Review Focus 2): blank = keep.
        $has_key     = Config::get('opm_api_key') !== '';
        $placeholder = $has_key ? __('Stored - leave blank to keep', 'opmanager') : __('Not set', 'opmanager');
        echo '<div class="mb-2"><label class="form-label" for="opm_api_key' . $sfx . '">' . __s('API key', 'opmanager') . '</label>';
        echo '<input type="password" autocomplete="new-password" class="form-control" id="opm_api_key' . $sfx . '" name="opm_api_key" value="" placeholder="' . htmlescape($placeholder) . '"' . $disabled . '>';
        if ($has_key) {
            echo '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="opm_api_key_clear" value="1" id="opm_api_key_clear' . $sfx . '"' . $disabled . '>';
            echo '<label class="form-check-label" for="opm_api_key_clear' . $sfx . '">' . __s('Remove the stored API key', 'opmanager') . '</label></div>';
        }
        echo '</div>';

        echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="opm_verify_ssl" value="1" id="opm_verify_ssl' . $sfx . '"'
            . (Config::get('opm_verify_ssl') === '1' ? ' checked' : '') . $disabled . '>';
        echo '<label class="form-check-label" for="opm_verify_ssl' . $sfx . '">' . __s('Verify the OpManager TLS certificate', 'opmanager') . '</label></div>';
        echo '</fieldset>';

        echo '<fieldset class="mb-3"><legend class="fs-4">' . __s('Asset matching', 'opmanager') . '</legend>';
        echo '<label class="form-label" for="match_mode' . $sfx . '">' . __s('Match mode', 'opmanager') . '</label>';
        \Dropdown::showFromArray('match_mode', Config::matchModeLabels(), [
            'value'    => Config::get('match_mode'),
            'width'    => '100%',
            'readonly' => !$canedit,
            'rand'     => 'match_mode' . $sfx,
        ]);
        echo '<div class="form-hint">' . __s('A manual pin always wins. A name or IP owned by more than one GLPI asset is never used: the device goes to "Needs review".', 'opmanager') . '</div>';

        echo '<div class="mt-2">' . __s('Asset types to match and to show the Monitoring section on', 'opmanager') . '</div>';
        $selected = Config::assetItemtypes();
        foreach (Config::offeredItemtypes() as $itemtype => $label) {
            $id = 'opm-asset-' . md5($itemtype) . $sfx;
            if (in_array($itemtype, Config::BASE_ASSET_ITEMTYPES, true) && is_a($itemtype, \CommonGLPI::class, true)) {
                $label = $itemtype::getTypeName(2);
            }
            echo sprintf(
                '<div class="form-check"><input class="form-check-input" type="checkbox" name="asset_itemtypes[]" value="%s" id="%s"%s%s><label class="form-check-label" for="%s">%s</label></div>',
                htmlescape($itemtype),
                htmlescape($id),
                in_array($itemtype, $selected, true) ? ' checked' : '',
                $disabled,
                htmlescape($id),
                htmlescape($label)
            );
        }
        echo self::textRow('stale_warning_minutes' . $sfx, 'stale_warning_minutes', __('Warn when device data is older than (minutes)', 'opmanager'), Config::get('stale_warning_minutes'), 'number', $disabled);
        echo '</fieldset>';

        $last  = Config::get('last_device_sync');
        $error = Config::get('last_device_sync_error');
        echo '<div class="mb-3">' . __s('Last successful device sync', 'opmanager') . ': ' . htmlescape($last !== '' ? $last : __('never', 'opmanager')) . '</div>';
        if ($error !== '') {
            echo '<div class="alert alert-warning">' . __s('Last device sync error', 'opmanager') . ': ' . htmlescape($error) . '</div>';
        }

        if ($canedit) {
            echo '<div class="mt-2">' . \Html::submit(_sx('button', 'Save'), ['name' => 'update']) . '</div>';
        }
        \Html::closeForm();
    }

    private static function textRow(string $id, string $name, string $label, string $value, string $type, string $disabled): string
    {
        return '<div class="mb-2"><label class="form-label" for="' . htmlescape($id) . '">' . htmlescape($label) . '</label>'
            . '<input type="' . $type . '" class="form-control" id="' . htmlescape($id) . '" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '"' . $disabled . '></div>';
    }
}
```

`src/Profile.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use CommonGLPI;
use Html;

/**
 * "OpManager" rights tab on Administration > Profiles. Posts to core
 * front/profile.form.php; \Profile::prepareInputForUpdate() already handles every
 * right in ProfileRight::getAllPossibleRights() - no controller of our own (hub §4).
 */
class Profile extends \Profile
{
    public static function getTypeName($nb = 0)
    {
        return 'OpManager';
    }

    // Non-static: core \Profile::getTabNameForItem() is an instance method (hub §4).
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0 && $withtemplate === 0) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-activity');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            (new self())->showRightsForm((int) $item->getID());
        }
        return true;
    }

    public function showRightsForm(int $profiles_id): void
    {
        $profile = new \Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }
        $canedit = self::canUpdate();

        $rights = [];
        foreach (RightDefinition::names() as $name) {
            $rights[] = [
                'itemtype' => '',
                'rights'   => RightDefinition::rightsMatrix($name),
                'label'    => $name === RightDefinition::CONFIG ? __('Configuration') : __('Monitoring', 'opmanager'),
                'field'    => $name,
            ];
        }

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "' data-track-changes='true'>";
        }
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);
        if ($canedit) {
            echo "<div class='center mt-2'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }
}
```

`front/config.php`:

```php
<?php

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;
use GlpiPlugin\Opmanager\SuiteConfig;
use GlpiPlugin\Pgnsuite\ConfigTab;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

if (isset($_POST['update'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);

    $result = Config::sanitize($_POST, array_keys(Config::offeredItemtypes()));
    Config::set($result['values']);
    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    if ($result['errors'] === []) {
        Session::addMessageAfterRedirect(__s('Settings saved', 'opmanager'));
    }

    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
}

// Users who can open Setup > General get the PGN Suite tab instead (hub §5).
if (Session::haveRight('config', READ)) {
    Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
}

// Standalone page for OpManager admins without core `config` READ. No menu entry.
Html::header('OpManager', '', 'opmanager', 'device');
SuiteConfig::showForm();
Html::footer();
```

Replace `plugin_init_opmanager()` in `setup.php` with:

```php
function plugin_init_opmanager()
{
    global $PLUGIN_HOOKS;

    // GLPI 11's CheckCsrfListener validates + consumes CSRF tokens before a
    // legacy front file runs - never call Session::checkCSRF() ourselves.
    $PLUGIN_HOOKS['csrf_compliant']['opmanager'] = true;

    // Core encrypts these on write (GLPIKey::getConfigs maps them to plugin:opmanager).
    $PLUGIN_HOOKS['secured_configs']['opmanager'] = \GlpiPlugin\Opmanager\Config::SECURED;

    \Plugin::registerClass(\GlpiPlugin\Opmanager\Profile::class, ['addtabon' => ['Profile']]);

    // Shared "PGN Suite" tab on Setup > General (hub §5). class_exists(..., false)
    // skips the autoloader, which would look for a nonexistent plugins/pgnsuite/.
    if (!class_exists(\GlpiPlugin\Pgnsuite\ConfigTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
    }
    \GlpiPlugin\Pgnsuite\ConfigTab::register();
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\ConfigTab::HOOK]['opmanager'] =
        \GlpiPlugin\Opmanager\SuiteConfig::class . '::section';
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/suiteconfig_test.php && for f in src/Profile.php front/config.php setup.php; do C:/php/php.exe -l $f; done`
Expected: `ALL PASS (11 passed, 0 failed)`, and three `No syntax errors detected`.

- [ ] **Step 5: Prove the api-key test bites**

Temporarily change the password input's `value=""` to `value="' . htmlescape(Config::get('opm_api_key')) . '"`. Run the suite and confirm `FAIL api key never rendered`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/Profile.php src/SuiteConfig.php front/config.php setup.php tests
git commit -m "feat: rights tab and PGN Suite setup section

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Matcher (pure)

**Files:**
- Create: `src/Matching/AssetLookup.php`, `src/Matching/Matcher.php`, `tests/matcher_test.php`

**Interfaces:**
- Produces:
  - `interface AssetLookup { public function byName(string $name): array; public function byIp(string $ip): array; }`. Both return `list<array{itemtype: string, items_id: int}>`.
  - `Matcher::__construct(AssetLookup $lookup)`, `Matcher::match(string $name, string $ip, string $mode): array{state: string, itemtype: string, items_id: int, source: string}`.
  - Constants `Matcher::MATCHED = 'matched'`, `REVIEW = 'review'`, `UNMATCHED = 'unmatched'`. `source` is `'name'`, `'ip'` or `''`.

- [ ] **Step 1: Write the failing test**

`tests/matcher_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Matching/AssetLookup.php';
require OPM_ROOT . '/src/Matching/Matcher.php';

use GlpiPlugin\Opmanager\Matching\AssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;

final class FakeLookup implements AssetLookup
{
    public array $calls = [];
    public function __construct(private array $names, private array $ips) {}
    public function byName(string $name): array { $this->calls[] = "name:$name"; return $this->names[$name] ?? []; }
    public function byIp(string $ip): array { $this->calls[] = "ip:$ip"; return $this->ips[$ip] ?? []; }
}

$sw  = ['itemtype' => 'NetworkEquipment', 'items_id' => 10];
$vm  = ['itemtype' => 'Computer', 'items_id' => 1566];
$vm2 = ['itemtype' => 'Computer', 'items_id' => 1600];

$lookup = new FakeLookup(
    ['core-sw-01' => [$sw], 'vm-wm-tc-01' => [$vm], 'dup-name' => [$vm, $vm2], 'renamed-new' => []],
    ['10.129.1.1' => [$sw], '10.129.2.9' => [$vm, $vm2], '10.129.3.3' => [$vm], '10.129.4.4' => [$vm, $vm]]
);
$m = new Matcher($lookup);

$r = $m->match('core-sw-01', '10.129.1.1', 'hybrid_name');
check('hybrid_name: name hit', $r === ['state' => 'matched', 'itemtype' => 'NetworkEquipment', 'items_id' => 10, 'source' => 'name']);

$r = $m->match('renamed-new', '10.129.3.3', 'hybrid_name');
check('hybrid_name: name miss falls back to unique IP', $r['state'] === 'matched' && $r['items_id'] === 1566 && $r['source'] === 'ip');

$r = $m->match('renamed-new', '10.129.2.9', 'hybrid_name');
check('ambiguous IP never guesses', $r === ['state' => 'review', 'itemtype' => '', 'items_id' => 0, 'source' => '']);

$r = $m->match('vm-wm-tc-01', '10.129.2.9', 'hybrid');
check('hybrid: ambiguous IP then unique name matches by name', $r['state'] === 'matched' && $r['items_id'] === 1566 && $r['source'] === 'name');

$r = $m->match('dup-name', '', 'name_only');
check('ambiguous name goes to review', $r['state'] === 'review');

$r = $m->match('nothing', '10.9.9.9', 'hybrid_name');
check('no hit anywhere is unmatched', $r['state'] === 'unmatched');

$r = $m->match('core-sw-01', '10.129.1.1', 'ip_only');
check('ip_only uses IP', $r['source'] === 'ip');

$lookup->calls = [];
$r = $m->match('core-sw-01', '10.129.1.1', 'manual');
check('manual mode never looks up', $r['state'] === 'unmatched' && $lookup->calls === []);

$lookup->calls = [];
$m->match('core-sw-01', 'not-an-ip', 'ip_only');
check('invalid IP is not looked up', $lookup->calls === []);

$lookup->calls = [];
$m->match('   ', '10.129.1.1', 'name_only');
check('blank name is not looked up', $lookup->calls === []);

$r = $m->match('x', '10.129.4.4', 'ip_only');
check('duplicate rows of one asset still count as one', $r['state'] === 'matched' && $r['items_id'] === 1566);

$r = $m->match('core-sw-01', '10.129.1.1', 'bogus_mode');
check('unknown mode never guesses', $r['state'] === 'unmatched');

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/matcher_test.php`
Expected: fatal on the missing `src/Matching/AssetLookup.php`.

- [ ] **Step 3: Write the implementation**

`src/Matching/AssetLookup.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Matching;

interface AssetLookup
{
    /** @return list<array{itemtype: string, items_id: int}> live assets named exactly $name */
    public function byName(string $name): array;

    /** @return list<array{itemtype: string, items_id: int}> live assets owning IP $ip */
    public function byIp(string $ip): array;
}
```

`src/Matching/Matcher.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Matching;

/**
 * Pure matching of one OpManager device to one GLPI asset (spec §5).
 *
 * Each mode is an ordered list of steps. The first step that yields EXACTLY one
 * asset wins. A step yielding several assets is never used (duplicate IPs are a
 * known problem in this GLPI); if no later step settles it, the device ends in
 * "review" rather than "unmatched", so a human looks at it. Manual pins are
 * handled by the caller - this class never sees them.
 */
final class Matcher
{
    public const MATCHED   = 'matched';
    public const REVIEW    = 'review';
    public const UNMATCHED = 'unmatched';

    public function __construct(private AssetLookup $lookup)
    {
    }

    /** @return array{state: string, itemtype: string, items_id: int, source: string} */
    public function match(string $name, string $ip, string $mode): array
    {
        $steps = match ($mode) {
            'ip_only'     => ['ip'],
            'name_only'   => ['name'],
            'hybrid'      => ['ip', 'name'],
            'hybrid_name' => ['name', 'ip'],
            default       => [], // 'manual' and anything unknown: never guess
        };

        $ambiguous = false;
        foreach ($steps as $step) {
            $hits = $this->candidates($step, $name, $ip);
            if (count($hits) === 1) {
                return [
                    'state'    => self::MATCHED,
                    'itemtype' => $hits[0]['itemtype'],
                    'items_id' => $hits[0]['items_id'],
                    'source'   => $step,
                ];
            }
            if (count($hits) > 1) {
                $ambiguous = true;
            }
        }

        return [
            'state'    => $ambiguous ? self::REVIEW : self::UNMATCHED,
            'itemtype' => '',
            'items_id' => 0,
            'source'   => '',
        ];
    }

    /** @return list<array{itemtype: string, items_id: int}> */
    private function candidates(string $step, string $name, string $ip): array
    {
        if ($step === 'name') {
            $name = trim($name);
            return $name === '' ? [] : self::unique($this->lookup->byName($name));
        }
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? [] : self::unique($this->lookup->byIp($ip));
    }

    /**
     * @param list<array{itemtype: string, items_id: int}> $hits
     * @return list<array{itemtype: string, items_id: int}>
     */
    private static function unique(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $out[$hit['itemtype'] . '#' . $hit['items_id']] = $hit;
        }
        return array_values($out);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/matcher_test.php`
Expected: `ALL PASS (12 passed, 0 failed)`.

- [ ] **Step 5: Prove the ambiguity test bites**

Temporarily change `if (count($hits) === 1)` to `if (count($hits) >= 1)`. Confirm `FAIL ambiguous IP never guesses` and `FAIL ambiguous name goes to review`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/Matching tests/matcher_test.php
git commit -m "feat: pure asset matcher with five modes and review state

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: DB asset lookup

**Files:**
- Create: `src/Matching/DbAssetLookup.php`, `tests/dbassetlookup_test.php`

**Interfaces:**
- Consumes: `AssetLookup` (Task 5).
- Produces: `DbAssetLookup::__construct(array $itemtypes)`; `DbAssetLookup::resolvable(array $itemtypes, callable $factory): array{ok: list<string>, missing: list<string>}` (pure); `DbAssetLookup::missingItemtypes(): list<string>`.

- [ ] **Step 1: Write the failing test**

`tests/dbassetlookup_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Matching/AssetLookup.php';
require OPM_ROOT . '/src/Matching/DbAssetLookup.php';

use GlpiPlugin\Opmanager\Matching\DbAssetLookup;

// Review Focus 5: a configured custom asset that cannot be resolved at runtime
// must be REPORTED, not silently skipped.
$factory = static fn (string $t) => $t === 'Glpi\\CustomAsset\\StorageAsset' ? null : new stdClass();
$r = DbAssetLookup::resolvable(['Computer', 'Glpi\\CustomAsset\\StorageAsset'], $factory);
check('unresolvable itemtypes reported', $r === ['ok' => ['Computer'], 'missing' => ['Glpi\\CustomAsset\\StorageAsset']]);
check('empty list resolves to nothing', DbAssetLookup::resolvable([], $factory) === ['ok' => [], 'missing' => []]);

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/dbassetlookup_test.php`
Expected: fatal on the missing `src/Matching/DbAssetLookup.php`.

- [ ] **Step 3: Write the implementation**

`src/Matching/DbAssetLookup.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\Matching;

/**
 * AssetLookup against the GLPI database, across every configured asset itemtype.
 * Deliberately NOT entity-scoped: it runs from cron, where core would fall back to
 * the root entity (hub §4).
 */
final class DbAssetLookup implements AssetLookup
{
    /** @var list<string> */
    private array $itemtypes;

    /** @var list<string> */
    private array $missing;

    /** @param string[] $itemtypes */
    public function __construct(array $itemtypes)
    {
        $resolved        = self::resolvable($itemtypes, static fn (string $t) => getItemForItemtype($t) ?: null);
        $this->itemtypes = $resolved['ok'];
        $this->missing   = $resolved['missing'];
    }

    /**
     * Pure split of configured itemtypes into resolvable and missing.
     *
     * @param string[]                  $itemtypes
     * @param callable(string): ?object $factory
     * @return array{ok: list<string>, missing: list<string>}
     */
    public static function resolvable(array $itemtypes, callable $factory): array
    {
        $ok = $missing = [];
        foreach ($itemtypes as $itemtype) {
            if ($factory($itemtype) === null) {
                $missing[] = $itemtype;
            } else {
                $ok[] = $itemtype;
            }
        }
        return ['ok' => $ok, 'missing' => $missing];
    }

    /** @return list<string> configured itemtypes this process could not load */
    public function missingItemtypes(): array
    {
        return $this->missing;
    }

    public function byName(string $name): array
    {
        global $DB;

        $hits = [];
        foreach ($this->itemtypes as $itemtype) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof \CommonDBTM) {
                continue;
            }
            $table = $itemtype::getTable();
            // find() does not add getSystemSQLCriteria(): without it a custom asset
            // query would search every custom asset definition's rows.
            $where = [$table . '.name' => $name] + self::liveCriteria($item, $table) + $itemtype::getSystemSQLCriteria();
            foreach ($DB->request(['SELECT' => [$table . '.id'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $hits[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['id']];
            }
        }
        return $hits;
    }

    public function byIp(string $ip): array
    {
        global $DB;

        if ($this->itemtypes === []) {
            return [];
        }
        $hits = [];
        foreach ($DB->request([
            'SELECT' => ['mainitemtype', 'mainitems_id'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => [
                'name'         => $ip,
                'is_deleted'   => 0,
                'mainitemtype' => $this->itemtypes,
                'mainitems_id' => ['>', 0],
            ],
        ]) as $row) {
            $itemtype = (string) $row['mainitemtype'];
            $item     = getItemForItemtype($itemtype);
            if (!$item instanceof \CommonDBTM || !$item->getFromDB((int) $row['mainitems_id'])) {
                continue;
            }
            if (!empty($item->fields['is_deleted']) || !empty($item->fields['is_template'])) {
                continue;
            }
            $hits[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['mainitems_id']];
        }
        return $hits;
    }

    /** @return array<string, int> */
    private static function liveCriteria(\CommonDBTM $item, string $table): array
    {
        $criteria = [];
        if ($item->maybeDeleted()) {
            $criteria[$table . '.is_deleted'] = 0;
        }
        if ($item->maybeTemplate()) {
            $criteria[$table . '.is_template'] = 0;
        }
        return $criteria;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/dbassetlookup_test.php && C:/php/php.exe -l src/Matching/DbAssetLookup.php`
Expected: `ALL PASS (2 passed, 0 failed)`; `No syntax errors detected`. The DB paths are covered by the Task 11 selfcheck on the VM.

- [ ] **Step 5: Commit**

```bash
git add src/Matching/DbAssetLookup.php tests/dbassetlookup_test.php
git commit -m "feat: GLPI asset lookup by name and IP incl. custom assets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: OpManager client and device parser

**Files:**
- Create: `src/OpManager/ClientException.php`, `src/OpManager/DeviceSource.php`, `src/OpManager/DeviceParser.php`, `src/OpManager/Client.php`, `tests/client_test.php`

**Interfaces:**
- Produces:
  - `interface DeviceSource { public function listDevices(): array; }`. It returns `list<array{device_key: string, name: string, display_name: string, ip_address: string, device_type: string, device_category: string, availability: string}>`.
  - `final class ClientException extends \RuntimeException`.
  - `DeviceParser::parse(string $json): array` (the same shape as `listDevices`; it throws `ClientException` on invalid JSON or shape).
  - `Client::__construct(string $baseUrl, string $apiKey, bool $verifySsl = true, ?callable $transport = null)`, where the transport is `callable(string $url, array $query): array{status: int, body: string}`. `Client` implements `DeviceSource`.
  - `Client::DEVICE_LIST_PATH = '/api/json/device/listDevices'`.
  - `Client::redact(string $text): string`.

- [ ] **Step 1: Write the failing test**

`tests/client_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'DeviceSource', 'DeviceParser', 'Client'] as $f) {
    require OPM_ROOT . "/src/OpManager/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\OpManager\DeviceParser;

$list = '[{"deviceName":"10.129.1.1","displayName":"core-sw-01","ipaddress":"10.129.1.1","type":"Cisco 9300","category":"Switch","statusStr":"Clear"},'
      . '{"displayName":"no-key"},'
      . '{"deviceName":" vm-wm-tc-01 ","ipaddress":"10.129.3.3","status":"5"}]';

$rows = DeviceParser::parse($list);
check('rows without an identity are skipped', count($rows) === 2);
check('fields mapped', $rows[0] === [
    'device_key' => '10.129.1.1', 'name' => '10.129.1.1', 'display_name' => 'core-sw-01', 'ip_address' => '10.129.1.1',
    'device_type' => 'Cisco 9300', 'device_category' => 'Switch', 'availability' => 'Clear',
]);
check('values trimmed and fallbacks used', $rows[1]['device_key'] === 'vm-wm-tc-01' && $rows[1]['display_name'] === 'vm-wm-tc-01' && $rows[1]['availability'] === '5');
check('wrapped list accepted', count(DeviceParser::parse('{"rows":' . $list . '}')) === 2);
check('empty list is empty, not an error', DeviceParser::parse('[]') === []);

$threw = static function (callable $fn): bool {
    try { $fn(); } catch (ClientException) { return true; }
    return false;
};
check('non-JSON throws', $threw(fn () => DeviceParser::parse('<html>login</html>')));
check('object without a known list throws', $threw(fn () => DeviceParser::parse('{"error":{"code":5000}}')));

// Review Focus 3: the key must never reach a log or an exception message.
check('redact removes apiKey', Client::redact('GET https://opm/api?apiKey=S3CRET&x=1 failed') === 'GET https://opm/api?apiKey=***&x=1 failed');

$seen = [];
$transport = function (string $url, array $query) use (&$seen, $list) {
    $seen = [$url, $query];
    return ['status' => 200, 'body' => $list];
};
$client = new Client('https://opm:8061/', 'S3CRET', true, $transport);
check('listDevices parses response', count($client->listDevices()) === 2);
check('calls the device list endpoint', $seen[0] === 'https://opm:8061/api/json/device/listDevices');
check('api key sent as query param', ($seen[1]['apiKey'] ?? null) === 'S3CRET');

$client = new Client('https://opm', 'S3CRET', true, fn () => ['status' => 401, 'body' => 'nope']);
try {
    $client->listDevices();
    check('HTTP error throws', false);
} catch (ClientException $e) {
    check('HTTP error throws', str_contains($e->getMessage(), '401'));
    check('HTTP error message has no key', !str_contains($e->getMessage(), 'S3CRET'));
}

$client = new Client('https://opm', 'S3CRET', true, function () { throw new RuntimeException('cURL error 28 for https://opm/api?apiKey=S3CRET'); });
try {
    $client->listDevices();
    check('transport failure throws ClientException', false);
} catch (ClientException $e) {
    check('transport failure throws ClientException', true);
    check('transport failure message redacted', !str_contains($e->getMessage(), 'S3CRET'));
}

check('unconfigured client refuses', $threw(fn () => (new Client('', '', true, $transport))->listDevices()));

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/client_test.php`
Expected: fatal on the missing `src/OpManager/ClientException.php`.

- [ ] **Step 3: Write the implementation**

`src/OpManager/ClientException.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/** Any failure talking to OpManager. Messages are always redacted (no apiKey). */
final class ClientException extends \RuntimeException
{
}
```

`src/OpManager/DeviceSource.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

interface DeviceSource
{
    /**
     * @return list<array{device_key: string, name: string, display_name: string, ip_address: string,
     *                    device_type: string, device_category: string, availability: string}>
     * @throws ClientException
     */
    public function listDevices(): array;
}
```

`src/OpManager/DeviceParser.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * Turns an OpManager device-list response into plain rows. Field names are
 * tolerant on purpose: the exact response of the PGN OpManager build is still
 * to be verified (spec §13 item 1) - the runbook checks it against a real call.
 */
final class DeviceParser
{
    private const WRAPPERS = ['rows', 'data', 'devices', 'result'];

    /** @return list<array{device_key: string, name: string, display_name: string, ip_address: string, device_type: string, device_category: string, availability: string}> */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ClientException('OpManager device list is not valid JSON');
        }
        foreach (self::WRAPPERS as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                $data = $data[$wrapper];
                break;
            }
        }
        if (!array_is_list($data)) {
            throw new ClientException('OpManager device list has an unexpected shape');
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::first($row, ['deviceName', 'name', 'moid']);
            if ($key === '') {
                continue;
            }
            $out[] = [
                'device_key'      => $key,
                'name'            => self::first($row, ['deviceName', 'name']) ?: $key,
                'display_name'    => self::first($row, ['displayName', 'deviceName', 'name']),
                'ip_address'      => self::first($row, ['ipaddress', 'ipAddress', 'ip']),
                'device_type'     => self::first($row, ['type', 'deviceType']),
                'device_category' => self::first($row, ['category']),
                'availability'    => self::first($row, ['statusStr', 'status']),
            ];
        }
        return $out;
    }

    /** @param string[] $keys */
    private static function first(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }
}
```

`src/OpManager/Client.php`:

```php
<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * The only place that talks HTTP to OpManager. The API key travels as the
 * `apiKey` query parameter, so every message that could contain a URL is passed
 * through redact() before it leaves this class (Review Focus 3).
 */
final class Client implements DeviceSource
{
    public const DEVICE_LIST_PATH = '/api/json/device/listDevices';

    /** @var callable(string, array<string, string>): array{status: int, body: string} */
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
            $response = ($this->transport)(rtrim($this->baseUrl, '/') . $path, ['apiKey' => $this->apiKey] + $query);
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
     * @return array{status: int, body: string}
     */
    private function guzzleTransport(string $url, array $query): array
    {
        $http = new \GuzzleHttp\Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => $this->verifySsl,
            'http_errors'     => false,
        ]);
        $response = $http->request('GET', $url, ['query' => $query]);
        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `C:/php/php.exe tests/client_test.php`
Expected: `ALL PASS (16 passed, 0 failed)`.

- [ ] **Step 5: Prove the redaction test bites**

Temporarily replace the body of `redact()` with `return $text;`. Confirm `FAIL redact removes apiKey` and `FAIL transport failure message redacted`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/OpManager tests/client_test.php
git commit -m "feat: OpManager client and tolerant device list parser

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Device itemtype, store, sync and cron

**Files:**
- Create: `src/DeviceStore.php`, `src/DbDeviceStore.php`, `src/DeviceSync.php`, `src/Device.php`, `src/Cron.php`, `tests/devicesync_test.php`
- Modify: `install/install.php` (cron registration), `install/uninstall.php` (cron removal)

**Interfaces:**
- Consumes: `Matcher` (T5), `DbAssetLookup` (T6), `DeviceSource`, `Client`, `ClientException` (T7), `Config` (T2).
- Produces:
  - `interface DeviceStore { public function upsert(array $device, string $now): array; public function setMatch(int $id, array $result): void; }`. `upsert` returns `array{id: int, is_manual: bool}` and never touches the match columns.
  - `DeviceSync::run(DeviceSource $source, DeviceStore $store, Matcher $matcher, string $mode, string $now): array{fetched: int, matched: int, review: int, unmatched: int, manual: int}`.
  - `Device` (CommonDBTM on `glpi_plugin_opmanager_devices`): `Device::findForAsset(string $itemtype, int $items_id): list<array>`, `Device::matchStateLabels(): array<string,string>`, `Device::validatePin(string $itemtype, int $items_id, array $allowed): ?string` (pure), `Device::pin(int $id, string $itemtype, int $items_id): ?string` (error message or null), `Device::unpin(int $id): void`.
  - `Cron::cronDeviceSync(\CronTask $task): int`, `Cron::cronInfo($name): array`.

- [ ] **Step 1: Write the failing test**

`tests/devicesync_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';
foreach (['Matching/AssetLookup', 'Matching/Matcher', 'OpManager/ClientException', 'OpManager/DeviceSource', 'DeviceStore', 'DeviceSync'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\DeviceStore;
use GlpiPlugin\Opmanager\DeviceSync;
use GlpiPlugin\Opmanager\Matching\AssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\OpManager\DeviceSource;

final class FakeSource implements DeviceSource
{
    public function __construct(private array|ClientException $result) {}
    public function listDevices(): array
    {
        if ($this->result instanceof ClientException) {
            throw $this->result;
        }
        return $this->result;
    }
}
final class FakeStore implements DeviceStore
{
    public array $upserts = [];
    public array $matches = [];
    public function __construct(private array $manual_keys = []) {}
    public function upsert(array $device, string $now): array
    {
        $this->upserts[] = $device['device_key'];
        return ['id' => count($this->upserts), 'is_manual' => in_array($device['device_key'], $this->manual_keys, true)];
    }
    public function setMatch(int $id, array $result): void { $this->matches[$id] = $result; }
}
final class FakeLookup implements AssetLookup
{
    public function byName(string $name): array { return $name === 'core-sw-01' ? [['itemtype' => 'NetworkEquipment', 'items_id' => 10]] : []; }
    public function byIp(string $ip): array { return $ip === '10.0.0.9' ? [['itemtype' => 'Computer', 'items_id' => 1], ['itemtype' => 'Computer', 'items_id' => 2]] : []; }
}

$dev = static fn (string $key, string $display, string $ip) => [
    'device_key' => $key, 'name' => $key, 'display_name' => $display, 'ip_address' => $ip,
    'device_type' => '', 'device_category' => '', 'availability' => '',
];
$devices = [
    $dev('a', 'core-sw-01', '10.0.0.1'),  // matched by name
    $dev('b', 'pinned-box', '10.0.0.9'),  // manual pin
    $dev('c', 'shared-ip', '10.0.0.9'),   // ambiguous IP -> review
    $dev('d', '', '10.0.0.77'),           // display empty -> uses name 'd' -> unmatched
];
$now = '2026-09-29 10:00:00';

$store = new FakeStore(['b']);
$stats = DeviceSync::run(new FakeSource($devices), $store, new Matcher(new FakeLookup()), 'hybrid_name', $now);
check('every device upserted', $store->upserts === ['a', 'b', 'c', 'd']);
check('manual pin never rematched', !isset($store->matches[2]));
check('stats add up', $stats === ['fetched' => 4, 'matched' => 1, 'review' => 1, 'unmatched' => 1, 'manual' => 1]);
check('name match stored', ($store->matches[1]['items_id'] ?? null) === 10);

$store = new FakeStore(['b']);
DeviceSync::run(new FakeSource($devices), $store, new Matcher(new FakeLookup()), 'manual', $now);
check('manual mode: pin still untouched', !isset($store->matches[2]));
check('manual mode: others unmatched', ($store->matches[1]['state'] ?? null) === 'unmatched');

$store = new FakeStore();
try {
    DeviceSync::run(new FakeSource(new ClientException('down')), $store, new Matcher(new FakeLookup()), 'hybrid_name', $now);
    check('source failure propagates', false);
} catch (ClientException) {
    check('source failure propagates', true);
}
check('source failure writes nothing', $store->upserts === [] && $store->matches === []);

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/devicesync_test.php`
Expected: fatal on the missing `src/DeviceStore.php`.

- [ ] **Step 3: Write the implementation**

`src/DeviceStore.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

interface DeviceStore
{
    /**
     * Insert or refresh the OpManager-side columns of one device. Never touches
     * itemtype / items_id / match_state / match_source / is_manual.
     *
     * @param array{device_key: string, name: string, display_name: string, ip_address: string,
     *              device_type: string, device_category: string, availability: string} $device
     * @return array{id: int, is_manual: bool}
     */
    public function upsert(array $device, string $now): array;

    /** @param array{state: string, itemtype: string, items_id: int, source: string} $result */
    public function setMatch(int $id, array $result): void;
}
```

`src/DeviceSync.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\DeviceSource;

/**
 * One device-sync pass. Fetches the whole list BEFORE writing anything, so a
 * failed or garbled response never half-updates the table. Devices missing from
 * OpManager are never deleted - their last_seen simply ages.
 */
final class DeviceSync
{
    /** @return array{fetched: int, matched: int, review: int, unmatched: int, manual: int} */
    public static function run(DeviceSource $source, DeviceStore $store, Matcher $matcher, string $mode, string $now): array
    {
        $devices = $source->listDevices();

        $stats = ['fetched' => 0, 'matched' => 0, 'review' => 0, 'unmatched' => 0, 'manual' => 0];
        foreach ($devices as $device) {
            $stats['fetched']++;
            $saved = $store->upsert($device, $now);
            if ($saved['is_manual']) {
                $stats['manual']++;
                continue;
            }
            $name   = $device['display_name'] !== '' ? $device['display_name'] : $device['name'];
            $result = $matcher->match($name, $device['ip_address'], $mode);
            $store->setMatch($saved['id'], $result);
            $stats[$result['state']]++;
        }
        return $stats;
    }
}
```

`src/DbDeviceStore.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

final class DbDeviceStore implements DeviceStore
{
    public function upsert(array $device, string $now): array
    {
        global $DB;

        $table  = Device::getTable();
        $fields = [
            'name'            => $device['name'],
            'display_name'    => $device['display_name'],
            'ip_address'      => $device['ip_address'],
            'device_type'     => $device['device_type'],
            'device_category' => $device['device_category'],
            'availability'    => $device['availability'],
            'last_seen'       => $now,
            'date_mod'        => $now,
        ];

        $row = $DB->request([
            'SELECT' => ['id', 'is_manual'],
            'FROM'   => $table,
            'WHERE'  => ['device_key' => $device['device_key']],
            'LIMIT'  => 1,
        ])->current();

        if ($row) {
            $DB->update($table, $fields, ['id' => (int) $row['id']]);
            return ['id' => (int) $row['id'], 'is_manual' => (bool) $row['is_manual']];
        }

        $DB->insert($table, $fields + ['device_key' => $device['device_key'], 'date_creation' => $now]);
        return ['id' => (int) $DB->insertId(), 'is_manual' => false];
    }

    public function setMatch(int $id, array $result): void
    {
        global $DB;

        // is_manual = 0 in the WHERE as a second guard: a pin set between the
        // upsert and this call must still win.
        $DB->update(Device::getTable(), [
            'match_state'  => $result['state'],
            'match_source' => $result['source'],
            'itemtype'     => $result['itemtype'],
            'items_id'     => $result['items_id'],
        ], ['id' => $id, 'is_manual' => 0]);
    }
}
```

`src/Device.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use CommonDBTM;
use Dropdown;
use Html;
use Session;

/**
 * One OpManager device and its pairing with a GLPI asset. Read-only for users:
 * rows come from DeviceSync; the only user action is a manual pin/unpin.
 */
class Device extends CommonDBTM
{
    public static $rightname = RightDefinition::MONITORING;

    public static function getTypeName($nb = 0)
    {
        return _n('OpManager device', 'OpManager devices', $nb, 'opmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-router';
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

    /** @return array<string, string> */
    public static function matchStateLabels(): array
    {
        return [
            'matched'   => __('Matched', 'opmanager'),
            'review'    => __('Needs review', 'opmanager'),
            'unmatched' => __('Not found in GLPI', 'opmanager'),
        ];
    }

    /** @return list<array<string, mixed>> matched devices paired with this asset */
    public static function findForAsset(string $itemtype, int $items_id): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id, 'match_state' => 'matched'],
            'ORDER' => 'display_name',
        ]), false);
    }

    /** Pure validation of a manual pin. @param string[] $allowed */
    public static function validatePin(string $itemtype, int $items_id, array $allowed): ?string
    {
        if (!in_array($itemtype, $allowed, true)) {
            return __('This asset type is not enabled in OpManager setup', 'opmanager');
        }
        if ($items_id <= 0) {
            return __('Choose an asset', 'opmanager');
        }
        return null;
    }

    public function pin(int $id, string $itemtype, int $items_id): ?string
    {
        $error = self::validatePin($itemtype, $items_id, Config::assetItemtypes());
        if ($error !== null) {
            return $error;
        }
        $asset = getItemForItemtype($itemtype);
        if (!$asset instanceof CommonDBTM || !$asset->getFromDB($items_id)) {
            return __('That asset does not exist', 'opmanager');
        }
        $this->update([
            'id'           => $id,
            'itemtype'     => $itemtype,
            'items_id'     => $items_id,
            'match_state'  => 'matched',
            'match_source' => 'manual',
            'is_manual'    => 1,
        ]);
        return null;
    }

    /** Removes the pin; the next DeviceSync matches the device automatically again. */
    public function unpin(int $id): void
    {
        $this->update([
            'id'           => $id,
            'itemtype'     => '',
            'items_id'     => 0,
            'match_state'  => 'unmatched',
            'match_source' => '',
            'is_manual'    => 0,
        ]);
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $table, 'field' => 'display_name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $table, 'field' => 'ip_address', 'name' => __('IP address'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $table, 'field' => 'device_category', 'name' => __('Category'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 5, 'table' => $table, 'field' => 'availability', 'name' => __('Availability', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 6, 'table' => $table, 'field' => 'match_state', 'name' => __('Match state', 'opmanager'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 7, 'table' => $table, 'field' => 'items_id', 'name' => __('GLPI asset', 'opmanager'), 'datatype' => 'specific', 'additionalfields' => ['itemtype'], 'nosearch' => true, 'massiveaction' => false],
            ['id' => 8, 'table' => $table, 'field' => 'last_seen', 'name' => __('Last seen', 'opmanager'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 9, 'table' => $table, 'field' => 'is_manual', 'name' => __('Pinned manually', 'opmanager'), 'datatype' => 'bool', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'match_state') {
            return htmlescape(self::matchStateLabels()[$values[$field]] ?? (string) $values[$field]);
        }
        if ($field === 'items_id') {
            return self::assetLink((string) ($values['itemtype'] ?? ''), (int) $values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'match_state') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return Dropdown::showFromArray($name, self::matchStateLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function assetLink(string $itemtype, int $items_id): string
    {
        if ($itemtype === '' || $items_id <= 0) {
            return '-';
        }
        $asset = getItemForItemtype($itemtype);
        if (!$asset instanceof CommonDBTM || !$asset->getFromDB($items_id)) {
            return htmlescape($itemtype . ' #' . $items_id);
        }
        return $asset->getLink();
    }

    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB((int) $ID)) {
            return false;
        }
        $f      = $this->fields;
        $labels = self::matchStateLabels();
        $rows   = [
            __('Name')                               => htmlescape($f['display_name'] ?: $f['name']),
            __('OpManager identity', 'opmanager')    => htmlescape($f['device_key']),
            __('IP address')                         => htmlescape($f['ip_address']),
            __('Type')                               => htmlescape($f['device_type']),
            __('Category')                           => htmlescape($f['device_category']),
            __('Availability', 'opmanager')          => htmlescape($f['availability']),
            __('Last seen', 'opmanager')             => htmlescape((string) $f['last_seen']),
            __('Match state', 'opmanager')           => htmlescape($labels[$f['match_state']] ?? $f['match_state']),
            __('GLPI asset', 'opmanager')            => self::assetLink((string) $f['itemtype'], (int) $f['items_id']),
            __('Pinned manually', 'opmanager')       => $f['is_manual'] ? __s('Yes') : __s('No'),
        ];

        echo "<div class='card'><div class='card-body'><table class='table table-sm'>";
        foreach ($rows as $label => $value) {
            echo '<tr><th class="w-25">' . htmlescape($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table>';

        if (Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "' class='d-flex flex-wrap align-items-center gap-2'>";
            echo Html::hidden('id', ['value' => (int) $ID]);
            Dropdown::showSelectItemFromItemtypes([
                'itemtypes'        => Config::assetItemtypes(),
                'itemtype_name'    => 'itemtype',
                'items_id_name'    => 'items_id',
                'entity_restrict'  => -1,
                'default_itemtype' => $f['itemtype'] !== '' ? $f['itemtype'] : 0,
            ]);
            echo Html::submit(__('Pin to this asset', 'opmanager'), ['name' => 'pin', 'class' => 'btn btn-primary']);
            if ($f['is_manual']) {
                echo Html::submit(__('Remove manual pin', 'opmanager'), ['name' => 'unpin', 'class' => 'btn btn-outline-secondary']);
            }
            Html::closeForm();
        }
        echo '</div></div>';
        return true;
    }
}
```

`src/Cron.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use GlpiPlugin\Opmanager\Matching\DbAssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\OpManager\ClientException;

final class Cron extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'OpManager';
    }

    public static function cronInfo($name)
    {
        return match ($name) {
            'DeviceSync' => ['description' => __('Sync OpManager devices and match them to GLPI assets', 'opmanager')],
            default      => [],
        };
    }

    public static function cronDeviceSync(\CronTask $task): int
    {
        $now    = \Session::getCurrentTime();
        $lookup = new DbAssetLookup(Config::assetItemtypes());

        // Review Focus 5: an asset type we cannot load here would silently leave
        // its devices unmatched - say so in the task log and the plugin log.
        foreach ($lookup->missingItemtypes() as $itemtype) {
            $msg = sprintf('asset type %s is configured but cannot be loaded in this process; its devices will stay unmatched', $itemtype);
            $task->log($msg);
            \Toolbox::logInFile('opmanager', 'DeviceSync: ' . $msg . "\n");
        }

        try {
            $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
            $stats  = DeviceSync::run($client, new DbDeviceStore(), new Matcher($lookup), Config::get('match_mode'), $now);
        } catch (ClientException $e) {
            Config::set(['last_device_sync_error' => $e->getMessage()]);
            \Toolbox::logInFile('opmanager', 'DeviceSync: ' . $e->getMessage() . "\n");
            $task->log($e->getMessage());
            return 0;
        }

        Config::set(['last_device_sync' => $now, 'last_device_sync_error' => '']);
        $task->addVolume($stats['fetched']);
        $task->log(sprintf(
            'fetched %d, matched %d, needs review %d, not found %d, pinned %d',
            $stats['fetched'],
            $stats['matched'],
            $stats['review'],
            $stats['unmatched'],
            $stats['manual']
        ));
        return 1;
    }
}
```

In `install/install.php`, add `use GlpiPlugin\Opmanager\Cron;` below the other `use` lines. Directly before `return true;`, add:

```php
    // CLI mode on purpose: GLPI assigns run mode per task, and web mode would
    // make the sync depend on someone browsing GLPI (hub §4).
    CronTask::register(Cron::class, 'DeviceSync', HOUR_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'OpManager device sync and asset matching',
    ]);
```

In `install/uninstall.php`, directly before `return true;`, add:

```php
    CronTask::unregister('opmanager');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/devicesync_test.php && for f in src/DbDeviceStore.php src/Device.php src/Cron.php install/install.php install/uninstall.php; do C:/php/php.exe -l $f; done`
Expected: `ALL PASS (8 passed, 0 failed)`, and five `No syntax errors detected`.

- [ ] **Step 5: Prove the manual-pin test bites**

Temporarily delete the `if ($saved['is_manual']) { … continue; }` block in `DeviceSync::run`. Confirm `FAIL manual pin never rematched` and `FAIL manual mode: pin still untouched`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/DeviceStore.php src/DbDeviceStore.php src/DeviceSync.php src/Device.php src/Cron.php install tests/devicesync_test.php
git commit -m "feat: device sync cron with manual pins that always win

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Menu sector and Devices pages

**Files:**
- Create: `src/Menu.php`, `front/device.php`, `front/device.form.php`, `tests/device_test.php`
- Modify: `setup.php` (`menu_toadd`)

**Interfaces:**
- Consumes: `Device` (T8), `RightDefinition`, `Config`.
- Produces: `Menu::getMenuContent(): array|false` with entry key `device`; the pages `/plugins/opmanager/front/device.php` (search list) and `/plugins/opmanager/front/device.form.php` (detail + pin/unpin POST).

- [ ] **Step 1: Write the failing test**

`tests/device_test.php`:

```php
<?php

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI {}
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Device.php';

use GlpiPlugin\Opmanager\Device;

$allowed = ['Computer', 'NetworkEquipment', 'Glpi\\CustomAsset\\SecurityAsset'];
check('pin to an enabled custom asset type is valid', Device::validatePin('Glpi\\CustomAsset\\SecurityAsset', 5, $allowed) === null);
check('pin to a disabled type is refused', Device::validatePin('Printer', 5, $allowed) !== null);
check('pin without an asset is refused', Device::validatePin('Computer', 0, $allowed) !== null);
check('three match states labelled', array_keys(Device::matchStateLabels()) === ['matched', 'review', 'unmatched']);

$form = (string) file_get_contents(OPM_ROOT . '/front/device.form.php');
check('pin requires the config UPDATE right', (bool) preg_match('/isset\(\$_POST\[.pin.\]\).*?checkRight\(RightDefinition::CONFIG, UPDATE\)/s', $form));
check('unpin requires the config UPDATE right', (bool) preg_match('/isset\(\$_POST\[.unpin.\]\).*?checkRight\(RightDefinition::CONFIG, UPDATE\)/s', $form));
check('detail uses displayFullPageForItem', str_contains($form, 'Device::displayFullPageForItem('));

done();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/device_test.php`
Expected: the first four checks pass. The file checks print `FAIL` because `front/device.form.php` is missing. Exit 1.

- [ ] **Step 3: Write the implementation**

`src/Menu.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use Session;

/**
 * Own top-level sidebar sector `opmanager` (riskregister v1.2.0 pattern, hub §4).
 * The sidebar renders only `content` entries that have a `page`; `title` is the
 * only way a new sector gets its label. No Setup entry: settings live in
 * Setup > General > PGN Suite.
 */
class Menu extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'OpManager';
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-activity';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(RightDefinition::MONITORING, READ)) {
            return false;
        }
        return [
            'is_multi_entries' => true,
            'title'            => self::getTypeName(),
            'device'           => [
                'title' => Device::getTypeName(2),
                'page'  => Device::getSearchURL(false),
                'icon'  => Device::getIcon(),
                'links' => ['search' => Device::getSearchURL(false)],
            ],
        ];
    }
}
```

`front/device.php`:

```php
<?php

use GlpiPlugin\Opmanager\Device;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(Device::getTypeName(2), '', 'opmanager', 'device');
Search::show(Device::class);
Html::footer();
```

`front/device.form.php`:

```php
<?php

use GlpiPlugin\Opmanager\Device;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

$device = new Device();

if (isset($_POST['pin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $error = $device->pin((int) $_POST['id'], (string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
    if ($error !== null) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(__s('Device pinned to the asset', 'opmanager'));
    }
    Html::back();
}

if (isset($_POST['unpin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $device->unpin((int) $_POST['id']);
    Session::addMessageAfterRedirect(__s('Manual pin removed; the next device sync matches it automatically', 'opmanager'));
    Html::back();
}

Session::checkRight(RightDefinition::MONITORING, READ);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    // Devices are created by the sync only - there is no add form.
    Html::redirect(Device::getSearchURL());
}

$menus = ['central' => ['opmanager', 'device'], 'helpdesk' => []];
Device::displayFullPageForItem($id, $menus, []);
```

In `setup.php`'s `plugin_init_opmanager()`, directly after the `Profile` `registerClass` line, add:

```php
    // Array-of-classes value is mandatory for a NEW sector (hub §4).
    $PLUGIN_HOOKS['menu_toadd']['opmanager'] = ['opmanager' => [\GlpiPlugin\Opmanager\Menu::class]];
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/device_test.php && for f in src/Menu.php front/device.php front/device.form.php setup.php; do C:/php/php.exe -l $f; done`
Expected: `ALL PASS (7 passed, 0 failed)`, and four `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Menu.php front/device.php front/device.form.php setup.php tests/device_test.php
git commit -m "feat: OpManager sidebar sector with devices list and manual pin

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Monitoring section on the PGN Suite asset tab

**Files:**
- Create: `src/AssetSections.php`, `tests/assetsections_test.php`
- Modify: `setup.php` (`AssetTab` wiring)

**Interfaces:**
- Consumes: `Device::findForAsset`, `Device::matchStateLabels` (T8); `Config::assetItemtypes`, `Config::get` (T2); `RightDefinition::MONITORING`.
- Produces: `AssetSections::KEY = 'monitoring'`, `AssetSections::ORDER = 60`, `AssetSections::MAX_ROWS = 20`; `AssetSections::sections(\CommonDBTM $item): array`; `AssetSections::render(\CommonDBTM $item): void`; `AssetSections::viewModel(array $devices, int $now, int $stale_minutes, string $last_sync): array` (pure); `AssetSections::html(array $model): string` (pure). Plan 2 extends `viewModel`/`html` with alarms.

- [ ] **Step 1: Write the failing test**

`tests/assetsections_test.php`:

```php
<?php

namespace {
    require __DIR__ . '/bootstrap.php';

    $GLOBALS['RIGHTS'] = [];
    $GLOBALS['CFG_VALUES'] = ['asset_itemtypes' => '["Computer","Glpi\\\\CustomAsset\\\\SecurityAsset"]'];
    $GLOBALS['DEVICES'] = [];

    class Session
    {
        public static function haveRight($name, $mask) { return ((int) ($GLOBALS['RIGHTS'][$name] ?? 0) & $mask) === $mask; }
    }
    class Config
    {
        public static function getConfigurationValues($context, $names = [])
        {
            return array_intersect_key($GLOBALS['CFG_VALUES'], array_flip($names));
        }
    }
    class CommonGLPI {}
    class CommonDBTM extends CommonGLPI
    {
        public function __construct(private int $id = 1) {}
        public function getID() { return $this->id; }
    }
    class Computer extends CommonDBTM {}
    class Printer extends CommonDBTM {}
}

namespace Glpi\CustomAsset {
    // At plugin_init this class would NOT exist; the section still has to work at render time.
    class SecurityAsset extends \CommonDBTM {}
}

namespace GlpiPlugin\Opmanager {
    final class CustomAssets
    {
        public static function active(): array { return ['Glpi\\CustomAsset\\SecurityAsset' => 'Security']; }
    }
    final class Device
    {
        public static function findForAsset(string $itemtype, int $id): array { return $GLOBALS['DEVICES']; }
        public static function matchStateLabels(): array { return ['matched' => 'Matched']; }
        public static function getFormURLWithID($id, $full = true) { return '/plugins/opmanager/front/device.form.php?id=' . (int) $id; }
    }
}

namespace {
    require OPM_ROOT . '/src/Config.php';
    require OPM_ROOT . '/src/RightDefinition.php';
    require OPM_ROOT . '/src/AssetSections.php';

    use GlpiPlugin\Opmanager\AssetSections;
    use GlpiPlugin\Opmanager\RightDefinition as R;

    $row = ['id' => 7, 'name' => '10.129.3.3', 'display_name' => '<b>vm-wm-tc-01</b>', 'ip_address' => '10.129.3.3',
            'availability' => 'Clear', 'match_source' => 'name', 'last_seen' => '2026-09-29 09:55:00'];

    check('no monitoring right: no section', AssetSections::sections(new Computer()) === []);
    $GLOBALS['RIGHTS'] = [R::MONITORING => READ];
    check('no paired device: no section', AssetSections::sections(new Computer()) === []);
    $GLOBALS['DEVICES'] = [$row];
    check('type not enabled: no section', AssetSections::sections(new Printer()) === []);

    $s = AssetSections::sections(new Computer());
    check('section key/order/render per contract', ($s[0]['key'] ?? null) === 'monitoring' && $s[0]['order'] === 60
        && $s[0]['render'] === 'GlpiPlugin\\Opmanager\\AssetSections::render');
    check('custom asset gets the section', count(AssetSections::sections(new Glpi\CustomAsset\SecurityAsset())) === 1);

    $now = strtotime('2026-09-29 10:00:00');
    $m = AssetSections::viewModel([$row], $now, 30, '2026-09-29 09:50:00');
    check('fresh data is not stale', $m['stale'] === false);
    $m = AssetSections::viewModel([$row], $now, 30, '2026-09-29 08:00:00');
    check('old sync is stale', $m['stale'] === true);
    $m = AssetSections::viewModel([$row], $now, 30, '');
    check('never synced is stale', $m['stale'] === true && $m['last_sync'] === '');

    $html = AssetSections::html(AssetSections::viewModel([$row], $now, 30, '2026-09-29 08:00:00'));
    check('device name escaped', str_contains($html, '&lt;b&gt;vm-wm-tc-01') && !str_contains($html, '<b>vm-wm-tc-01'));
    check('stale warning shown', str_contains($html, 'alert-warning'));
    check('no pager markup (AJAX tab rule)', !preg_match('/pagination|[?&]start=|page-link/', $html));
    check('dom ids prefixed with plugin key', !preg_match('/\sid="(?!opmanager-)/', $html));

    $many = array_fill(0, 25, $row);
    $html = AssetSections::html(AssetSections::viewModel($many, $now, 30, '2026-09-29 09:59:00'));
    check('capped at MAX_ROWS rows', substr_count($html, '<tr data-opm-device') === AssetSections::MAX_ROWS);
    check('truncation is visible', str_contains($html, 'Showing 20 of 25'));

    done();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/php/php.exe tests/assetsections_test.php`
Expected: fatal on the missing `src/AssetSections.php`.

- [ ] **Step 3: Write the implementation**

`src/AssetSections.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

/**
 * "Monitoring" section of the shared PGN Suite asset tab (hub §5, spec §9).
 * A summary, never a browser: no pager (the tab loads by AJAX, hub §4), newest
 * MAX_ROWS rows, and an explicit "Showing N of M" line when truncated.
 */
final class AssetSections
{
    public const KEY      = 'monitoring';
    public const ORDER    = 60;
    public const MAX_ROWS = 20;

    /** @return list<array{key: string, title: string, icon: string, order: int, render: string}> */
    public static function sections(\CommonDBTM $item): array
    {
        if (!\Session::haveRight(RightDefinition::MONITORING, READ)) {
            return [];
        }
        // Validate the itemtype HERE, at render time - custom asset classes do not
        // exist yet at plugin_init (hub §5).
        if (!in_array($item::class, Config::assetItemtypes(), true)) {
            return [];
        }
        if (Device::findForAsset($item::class, (int) $item->getID()) === []) {
            return [];
        }
        return [[
            'key'    => self::KEY,
            'title'  => __('Monitoring', 'opmanager'),
            'icon'   => 'ti ti-activity',
            'order'  => self::ORDER,
            'render' => self::class . '::render',
        ]];
    }

    public static function render(\CommonDBTM $item): void
    {
        $devices = Device::findForAsset($item::class, (int) $item->getID());
        foreach ($devices as &$device) {
            $device['url'] = Device::getFormURLWithID((int) $device['id']);
        }
        unset($device);

        echo self::html(self::viewModel(
            $devices,
            (int) strtotime(\Session::getCurrentTime()),
            (int) Config::get('stale_warning_minutes'),
            Config::get('last_device_sync')
        ));
    }

    /**
     * Pure.
     *
     * @param list<array<string, mixed>> $devices
     * @return array{stale: bool, last_sync: string, stale_minutes: int, total: int, rows: list<array<string, mixed>>}
     */
    public static function viewModel(array $devices, int $now, int $stale_minutes, string $last_sync): array
    {
        $synced = $last_sync === '' ? false : strtotime($last_sync);
        return [
            'stale'         => $synced === false || $synced < $now - $stale_minutes * 60,
            'last_sync'     => $last_sync,
            'stale_minutes' => $stale_minutes,
            'total'         => count($devices),
            'rows'          => array_slice($devices, 0, self::MAX_ROWS),
        ];
    }

    /** Pure. @param array{stale: bool, last_sync: string, stale_minutes: int, total: int, rows: list<array<string, mixed>>} $model */
    public static function html(array $model): string
    {
        $out = '<div class="p-3" id="opmanager-monitoring">';

        if ($model['stale']) {
            $out .= '<div class="alert alert-warning">'
                . htmlescape($model['last_sync'] === ''
                    ? __('OpManager data has never been synced.', 'opmanager')
                    : sprintf(__('OpManager data is older than %d minutes (last sync: %s).', 'opmanager'), $model['stale_minutes'], $model['last_sync']))
                . '</div>';
        }

        $sources = ['name' => __('by name', 'opmanager'), 'ip' => __('by IP', 'opmanager'), 'manual' => __('manual pin', 'opmanager')];
        $out .= '<table class="table table-sm table-hover mb-2"><thead><tr>'
            . '<th>' . __s('OpManager device', 'opmanager') . '</th>'
            . '<th>' . __s('IP address') . '</th>'
            . '<th>' . __s('Availability', 'opmanager') . '</th>'
            . '<th>' . __s('Last seen', 'opmanager') . '</th>'
            . '<th>' . __s('Matched', 'opmanager') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($model['rows'] as $row) {
            $name = (string) (($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['name'] ?? ''));
            $link = isset($row['url']) ? '<a href="' . htmlescape((string) $row['url']) . '">' . htmlescape($name) . '</a>' : htmlescape($name);
            $out .= '<tr data-opm-device="' . (int) ($row['id'] ?? 0) . '">'
                . '<td>' . $link . '</td>'
                . '<td>' . htmlescape((string) ($row['ip_address'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) ($row['availability'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) ($row['last_seen'] ?? '')) . '</td>'
                . '<td>' . htmlescape($sources[$row['match_source'] ?? ''] ?? '') . '</td>'
                . '</tr>';
        }
        $out .= '</tbody></table>';

        if ($model['total'] > count($model['rows'])) {
            $out .= '<div class="text-muted">' . htmlescape(sprintf(__('Showing %d of %d', 'opmanager'), count($model['rows']), $model['total'])) . '</div>';
        }

        return $out . '</div>';
    }
}
```

In `setup.php`'s `plugin_init_opmanager()`, append at the end:

```php
    // Shared "PGN Suite" tab on asset forms (hub §5). The itemtype list is plain
    // strings read from config + glpi_assets_assetdefinitions: custom asset classes
    // are not loaded yet here, so NEVER filter it with class_exists().
    if (!class_exists(\GlpiPlugin\Pgnsuite\AssetTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/AssetTab.php';
    }
    \GlpiPlugin\Pgnsuite\AssetTab::register(\GlpiPlugin\Opmanager\Config::assetItemtypes());
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\AssetTab::HOOK]['opmanager'] =
        \GlpiPlugin\Opmanager\AssetSections::class . '::sections';
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `C:/php/php.exe tests/assetsections_test.php && C:/php/php.exe -l setup.php && C:/php/php.exe tests/run_all.php`
Expected: `ALL PASS (14 passed, 0 failed)`, `No syntax errors detected`, `SUITES OK`.

- [ ] **Step 5: Prove the no-pager and truncation tests bite**

Temporarily change `array_slice($devices, 0, self::MAX_ROWS)` to `$devices`. Confirm `FAIL capped at MAX_ROWS rows` and `FAIL truncation is visible`. Revert.

- [ ] **Step 6: Commit**

```bash
git add src/AssetSections.php setup.php tests/assetsections_test.php
git commit -m "feat: Monitoring section on the shared PGN Suite asset tab

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Selfcheck, release checker, runbook, tag v0.1.0

**Files:**
- Create: `src/SelfCheck.php`, `tools/selfcheck.php`, `tools/release_check.py`, `docs/runbook-v0.1.0.md`, `CHANGELOG.md`
- Modify: hub `_pgn-glpi-suite.md` (§2 row 7, §7)

**Interfaces:**
- Consumes: everything above.
- Produces: `SelfCheck::checks(): array<string, callable(): void>`, where each check throws `RuntimeException` on failure. Also produces `tools/selfcheck.php [filter]` (exit 0/1) and `python tools/release_check.py <zip> [--install <plugins dir>]` (exit 0/1).

- [ ] **Step 1: Write the selfcheck**

`src/SelfCheck.php`:

```php
<?php

namespace GlpiPlugin\Opmanager;

use GlpiPlugin\Opmanager\Matching\DbAssetLookup;

/** Runtime checks against the real GLPI + DB. Run on the VM via tools/selfcheck.php. */
final class SelfCheck
{
    /** @return array<string, callable(): void> */
    public static function checks(): array
    {
        return [
            'schema/devices-table' => static function (): void {
                global $DB;
                self::assert($DB->tableExists(Device::getTable()), 'glpi_plugin_opmanager_devices missing');
            },
            'rights/registered' => static function (): void {
                $all = array_keys(\ProfileRight::getAllPossibleRights());
                self::assert(array_diff(RightDefinition::names(), $all) === [], 'plugin rights not registered');
            },
            'rights/super-admin-full' => static function (): void {
                global $DB;
                foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $row) {
                    if (((int) $row['rights'] & UPDATE) !== UPDATE) {
                        continue;
                    }
                    $mine = \ProfileRight::getProfileRights((int) $row['profiles_id'], RightDefinition::names());
                    foreach (RightDefinition::ALL as $name => $mask) {
                        self::assert((int) ($mine[$name] ?? 0) === $mask, "profile {$row['profiles_id']} lacks full {$name}");
                    }
                }
            },
            'cron/device-sync-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'DeviceSync'), 'DeviceSync cron not registered');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'DeviceSync is not in CLI mode');
            },
            'config/api-key-secured' => static function (): void {
                self::assert((new \GLPIKey())->isConfigSecured(Config::CONTEXT, 'opm_api_key'), 'opm_api_key is not a secured config');
                $raw = Config::get('opm_api_key');
                if ($raw !== '') {
                    self::assert($raw !== Config::apiKey(), 'opm_api_key is stored in clear text');
                }
            },
            'suite/lib-shas' => static function (): void {
                $expected = [
                    'ConfigTab.php' => '008C4C817DB2E42040915136108CD41917C34760A8F4CDDC60E46BDB208F39D0',
                    'AssetTab.php'  => 'C7F6C88B58BE9A7AAF172F3A2F2DBAAFBB07B0CE1898BA5A74A0EDB61EA143A9',
                ];
                foreach ($expected as $file => $sha) {
                    $actual = strtoupper((string) hash_file('sha256', dirname(__DIR__) . '/lib/pgnsuite/' . $file));
                    self::assert($actual === $sha, "lib/pgnsuite/{$file} is not canonical ({$actual})");
                }
            },
            'matching/itemtypes-loadable' => static function (): void {
                $missing = (new DbAssetLookup(Config::assetItemtypes()))->missingItemtypes();
                self::assert($missing === [], 'configured asset types not loadable here: ' . implode(', ', $missing));
            },
            'matching/lookup-smoke' => static function (): void {
                $lookup = new DbAssetLookup(Config::assetItemtypes());
                self::assert($lookup->byName('__opmanager_selfcheck_no_such_asset__') === [], 'name lookup returned rows for a nonsense name');
                self::assert($lookup->byIp('192.0.2.254') === [], 'IP lookup returned rows for TEST-NET-1');
            },
        ];
    }

    private static function assert(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }
}
```

`tools/selfcheck.php`:

```php
<?php

/**
 * opmanager runtime self-check. On the VM:
 *   sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php [filter]
 * Prints PASS/FAIL per check; exit 1 on any failure.
 */

$glpiRoot = null;
foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4), dirname(__DIR__, 2)] as $candidate) {
    if (is_file($candidate . '/inc/includes.php')) {
        $glpiRoot = $candidate;
        break;
    }
}
if ($glpiRoot === null) {
    fwrite(STDERR, "selfcheck: cannot locate GLPI root from " . __DIR__ . "\n");
    exit(2);
}
require $glpiRoot . '/inc/includes.php';

$filter = $argv[1] ?? '';
$fail   = 0;
$pass   = 0;
foreach (\GlpiPlugin\Opmanager\SelfCheck::checks() as $name => $check) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $check();
        $pass++;
        echo "PASS {$name}\n";
    } catch (\Throwable $e) {
        $fail++;
        echo "FAIL {$name} -- " . $e->getMessage() . "\n";
    }
}
echo ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ({$pass} passed, {$fail} failed)\n";
exit($fail === 0 ? 0 : 1);
```

Run: `C:/php/php.exe -l src/SelfCheck.php && C:/php/php.exe -l tools/selfcheck.php`
Expected: two `No syntax errors detected`. (`ProfileRight::getProfileRights($profiles_id, array $rights)` exists in 11.0.5, `src/ProfileRight.php:131`.)

- [ ] **Step 2: Write the release checker**

`tools/release_check.py`:

```python
"""Verify an opmanager release zip and optionally install it into the local GLPI copy.

Usage:
  python tools/release_check.py opmanagerv0.1.0.zip
  python tools/release_check.py opmanagerv0.1.0.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins

Reads bytes straight from the archive (never from an extraction, hub §5) and never
uses a fallback that could print a passing value when a step cannot run.
"""
import hashlib
import re
import shutil
import sys
import zipfile
from pathlib import Path

CANON = {
    "opmanager/lib/pgnsuite/ConfigTab.php": "008C4C817DB2E42040915136108CD41917C34760A8F4CDDC60E46BDB208F39D0",
    "opmanager/lib/pgnsuite/AssetTab.php": "C7F6C88B58BE9A7AAF172F3A2F2DBAAFBB07B0CE1898BA5A74A0EDB61EA143A9",
}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest().upper()


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    zpath = Path(sys.argv[1])
    install_dir = Path(sys.argv[sys.argv.index("--install") + 1]) if "--install" in sys.argv else None
    ok = True

    with zipfile.ZipFile(zpath) as z:
        files = [n for n in z.namelist() if not n.endswith("/")]
        bad_root = [n for n in files if not n.startswith("opmanager/")]
        print(f"entries: {len(files)}")
        if bad_root:
            print(f"FAIL entries outside opmanager/: {bad_root[:5]}")
            ok = False
        for name, want in CANON.items():
            got = sha(z.read(name)) if name in files else "missing"
            print(("PASS " if got == want else "FAIL ") + f"{name} {got}")
            ok &= got == want
        leaked = [n for n in files if n.startswith(("opmanager/tests/", "opmanager/docs/")) or n.endswith(".zip")]
        if leaked:
            print(f"FAIL dev files in zip: {leaked[:5]}")
            ok = False
        m = re.search(rb"PLUGIN_OPMANAGER_VERSION',\s*'([^']+)'", z.read("opmanager/setup.php"))
        version = m.group(1).decode() if m else "missing"
        print(f"setup.php version: {version}")
        if f"v{version}.zip" not in zpath.name:
            print(f"FAIL zip name {zpath.name} does not carry version {version}")
            ok = False

        if install_dir is not None and ok:
            target = install_dir / "opmanager"
            if target.exists():
                shutil.rmtree(target)
            z.extractall(install_dir)
            on_disk = [p for p in target.rglob("*") if p.is_file()]
            print(f"installed: {len(on_disk)} files into {target}")
            if len(on_disk) != len(files):
                print("FAIL installed file count differs from zip")
                ok = False
            for name, want in CANON.items():
                got = sha((install_dir / name).read_bytes())
                print(("PASS " if got == want else "FAIL ") + f"installed {name}")
                ok &= got == want
            m2 = re.search(r"PLUGIN_OPMANAGER_VERSION',\s*'([^']+)'", (target / "setup.php").read_text(encoding="utf-8"))
            print(("PASS " if m2 and m2.group(1) == version else "FAIL ") + "installed setup.php version")
            ok &= bool(m2 and m2.group(1) == version)

    print("RELEASE OK" if ok else "RELEASE FAILED")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 3: Write the VM runbook and changelog**

`CHANGELOG.md`:

```markdown
# Changelog

## 0.1.0

- First release: OpManager device sync (CLI cron `DeviceSync`, hourly) with matching to
  existing GLPI assets (Computer, NetworkEquipment, custom assets). Five match modes;
  manual pins always win; a name or IP owned by more than one asset goes to "Needs review".
- OpManager sidebar sector with the device list and manual pin/unpin.
- "Monitoring" section in the shared PGN Suite asset tab.
- Setup in Setup > General > PGN Suite > OpManager; API key encrypted at rest.
- Alarms and tickets are NOT in this release (Plan 2).
```

`docs/runbook-v0.1.0.md`:

````markdown
---
date: 2026-09-29
tags: [opmanager, runbook]
status: active
---

# opmanager v0.1.0 — VM verification runbook

Run on VM-HQ-GLPI (`/data/www/glpi`). Paste every command's output back to the session.
Tick a box only with the evidence named on it.

## 1. Install

```bash
cd /data/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.1.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /data/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /data/www/glpi/bin/console plugin:activate opmanager
sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] selfcheck prints `ALL PASS`. If `matching/itemtypes-loadable` fails, the configured
      Security/Storage types cannot be loaded from CLI: write down the exact message and do
      not tick §4 below.
- [ ] Setup > Automatic actions: `DeviceSync` shows RUN MODE = CLI.

## 2. Setup

- [ ] Setup > General > PGN Suite shows an **OpManager** sub-tab, and there is exactly one
      "PGN Suite" tab.
- [ ] Enter URL + API key, save, reload: the key field is empty with "Stored" placeholder.
- [ ] Tick Computer, Network devices, **Security**, **Storage**; save; reload - still ticked.
      Write down the two custom class names shown in the page source (`value="Glpi\CustomAsset\…Asset"`).

## 3. First device sync

```bash
sudo -u nginx php /data/www/glpi/front/cron.php DeviceSync
sudo tail -n 20 /data/www/glpi/files/_log/opmanager.log
```

- [ ] Automatic action log shows `fetched N, matched …` with N > 0.
      **If it says "not valid JSON" or "unexpected shape":** the OpManager response differs
      from the assumed `/api/json/device/listDevices` format (spec §13 item 1). Capture one
      raw response with the key redacted, and stop here - do not tick further boxes.
- [ ] OpManager > OpManager devices lists the devices; filter Match state = Needs review works.

## 4. Matching and the Monitoring section

- [ ] Open one matched Computer: a **PGN Suite** tab with a **Monitoring** sub-tab shows the
      device, its IP and "by name"/"by IP".
- [ ] Open one matched **NetworkEquipment**: same.
- [ ] Open one matched **Security** or **Storage** asset: same.
      **If no Security/Storage device matched at all:** do not tick. Write "case untested in
      production" and run
      `SELECT itemtype, match_state, COUNT(*) FROM glpi_plugin_opmanager_devices GROUP BY 1,2;`
- [ ] Duplicate-IP case: if the query above shows any `review` rows, open one and confirm the
      two GLPI assets that share its IP really exist. **If there are no `review` rows:** write
      "case untested in production" - do not tick.
- [ ] Pin a `review` device manually, run DeviceSync again, and confirm it stays pinned.

## 5. Rights

- [ ] A profile without `plugin_opmanager_monitoring` sees no OpManager sector and no Monitoring
      sub-tab.
- [ ] A profile with monitoring READ but without config UPDATE sees devices but no pin form.
````

- [ ] **Step 4: Run the full offline suite, then build and check the zip**

Run: `C:/php/php.exe tests/run_all.php`
Expected: `SUITES OK`.

Then:

```bash
git add src/SelfCheck.php tools CHANGELOG.md docs/runbook-v0.1.0.md
git commit -m "chore: selfcheck, release checker and v0.1.0 runbook

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git tag 0.1.0
git archive --format=zip --prefix=opmanager/ -o opmanagerv0.1.0.zip 0.1.0
python tools/release_check.py opmanagerv0.1.0.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins
```

Expected: every line `PASS`, and the final line reads `RELEASE OK`. Also `installed: N files` equals `entries: N`. If any line reads `FAIL`, fix the cause, delete the tag (`git tag -d 0.1.0`), and rebuild. Never ship a zip that failed this check.

- [ ] **Step 5: Update the hub**

In `_pgn-glpi-suite.md`:
- §2 row 7 status: `**v0.1.0 built** (tag \`0.1.0\`, \`opmanagerv0.1.0.zip\`, lib SHAs verified in zip, installed into local GLPI copy); NOT deployed. Plan 2 (alarms/tickets) waits on spec §13 items 1-2.`
- Append a §7 entry recording the release (tag, zip SHA check result, local install result).
- Bump "Last updated".

- [ ] **Step 6: Commit the hub-independent leftovers (if any) and stop**

Run: `git status --short`
Expected: no output (the zip is git-ignored). Do **not** push (push happens only on a major version).
