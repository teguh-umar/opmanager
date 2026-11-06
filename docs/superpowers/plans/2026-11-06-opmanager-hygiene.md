# OpManager NMS Hygiene Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Classify every OpManager device by monitoring state and liveness, and raise weekly Request tickets. The GLPI team and the asset team get the monitored devices that are missing from GLPI. The NMS team gets the unmanaged devices that are dead or of unknown status. Per-device exceptions keep a device out of both tickets.

**Architecture:** New namespace `GlpiPlugin\Opmanager\Hygiene\`. Pure classes hold the decisions: `Classifier`, `Liveness`, `Cidr`, `TicketPlanner`, `Report`. Thin DB/GLPI adapters sit behind interfaces: `HygieneStore`, `EvidenceSource`, `Prober`, `HygieneGateway`. An orchestrator, `HygieneRun`, drives them and is tested with fakes, as `AlarmSync` is. A new daily CLI cron `HygieneCheck` runs it.

**Tech Stack:** PHP 8.2, GLPI 11.0.5 core objects. Offline harness `tests/*_test.php` (`check()` / `done()`), run with `C:/Users/teguh.umar/Downloads/php/php.exe tests/run_all.php`.

**Spec:** `docs/superpowers/specs/2026-11-06-opmanager-unmanaged-hygiene-design.md` (D34). Section numbers below (§n) refer to it.

## Global Constraints

- Release `1.3.0-rc1` first (hub rule 8). `PLUGIN_OPMANAGER_VERSION = '1.3.0-rc1'`.
- Every new table, column, right, and config key is exactly as in spec §8. `nms` migrates from that list, so the names are a contract.
- Suite schema rules (hub §4): keys `INT UNSIGNED`, external ids `VARCHAR *_key`, dates `TIMESTAMP` only. `tests/schema_test.php` enforces them.
- Never write to OpManager from this feature. Never raw-SQL-write core tables: tickets, followups, and groups go through `->add()`.
- Reading `glpi_plugin_mist_devices` is guarded by `Plugin::isPluginActive('mist')` and `$DB->tableExists()`. The feature works without `mist`.
- No `exec()` / `shell_exec()` / ICMP. The probe is a TCP connect only, and only to IPs inside `hygiene_probe_cidrs`.
- Hygiene tickets are `type = 2` (Request), always, even when the template predefines another type.
- Cron mode `CronTask::MODE_EXTERNAL`.
- A run that half fails stays visible in Setup through `last_hygiene_error`.
- UI strings use the `opmanager` text domain, in English, like the rest of the plugin.

## Review Focus

1. **A probe on a long CIDR list must not hang the cron.** The total probe time per run is capped (`Prober` budget 120 s). Devices left unprobed when the budget runs out keep their previous liveness, or `unknown`, and are never marked dead. Pinned in Task 6.
2. **A device that left OpManager is never ticketed.** `last_seen` older than `2 × DEVICE_SYNC_MINUTES` gives state `''`. Pinned in Task 2.
3. **Availability with odd case or spacing** (`"unmanaged "`, `"Device not monitored."`) still counts as unmanaged. Pinned in Task 2.
4. **A ticket that failed to create must not wait a week to retry.** `last_hygiene_ticket_run` advances only when every flow succeeded. Pinned in Task 8.
5. **A hygiene ticket merged or trashed by a technician** counts as closed, so a new ticket is created and followups do not go to a trashed ticket. Pinned in Task 4.

---

### Task 1: Schema, rights and config defaults

**Files:**
- Modify: `install/install.php` (devices CREATE + ALTER for upgrades, 3 new tables, cron registration)
- Modify: `install/uninstall.php` (drop the 3 new tables)
- Modify: `src/RightDefinition.php`, `src/RightSeeder.php`, `src/Config.php` (`DEFAULTS` only)
- Test: `tests/schema_test.php`, `tests/rightseeder_test.php`, `tests/config_test.php`

**Interfaces:**
- Produces: `RightDefinition::HYGIENE = 'plugin_opmanager_hygiene'` with mask `READ | UPDATE`. `Config::DEFAULTS` gains the 14 keys of spec §8.4 with the defaults listed there, as strings (JSON for lists). `Config::HYGIENE_CRON_MINUTES = 1440`.

- [ ] **Step 1: Write the failing tests**
  - `schema_test.php`:
    - Check that install creates `glpi_plugin_opmanager_deviceexceptions`, `glpi_plugin_opmanager_hygienetickets` and `glpi_plugin_opmanager_hygieneitems`.
    - Check that the devices CREATE contains `liveness_state`, `liveness_source`, `liveness_detail`, `liveness_checked_at` and `hygiene_state`.
    - Check that `install.php` contains an `ALTER TABLE` guarded by `fieldExists` for each of those 5 columns.
    - Check that `uninstall.php` names all 3 new tables.
    - The existing generic loop already checks types.
  - `rightseeder_test.php`:
    - `R::ALL[R::HYGIENE] === (READ | UPDATE)`.
    - Fresh plan: the super-admin gets the full mask.
    - The technician (`computer` READ) gets `R::HYGIENE => READ`.
    - A profile with `computer` 0 gets 0.
  - `config_test.php`:
    - Every key from spec §8.4 is in `Config::DEFAULTS`.
    - `hygiene_unmanaged_availabilities` decodes to `['UnManaged', 'Device Not Monitored.']`.
    - `hygiene_enabled === '0'`.
    - `hygiene_frequency_days === '7'`.
    - `hygiene_probe_cidrs === '[]'`.
- [ ] **Step 2: Run** `php tests/run_all.php` and confirm the new checks FAIL.
- [ ] **Step 3: Implement the schema, rights, seeder and defaults**
  - Columns and types are copied from spec §8.1–8.2.
  - `hygieneitems`: `UNIQUE KEY (plugin_opmanager_hygienetickets_id, device_key)`.
  - `deviceexceptions`: `KEY device_key`.
  - `hygienetickets`: `KEY tickets_id` and `KEY flow`.
  - Seeder rule: `RightDefinition::HYGIENE => $profile['computer'] & READ`.
  - Register cron `HygieneCheck` at `Config::HYGIENE_CRON_MINUTES * MINUTE_TIMESTAMP`, `MODE_EXTERNAL`, comment `'OpManager NMS hygiene: liveness, classification, tickets'`.
- [ ] **Step 4: Run** `php tests/run_all.php` and confirm `SUITES OK`.
- [ ] **Step 5: Commit** with `feat: hygiene schema, right and config defaults (D34)`.

### Task 2: Classifier and Cidr (pure)

**Files:**
- Create: `src/Hygiene/Classifier.php`, `src/Hygiene/Cidr.php`
- Test: `tests/hygiene_classifier_test.php`

**Interfaces:**
- Produces:
  - `Classifier` constants: `GLPI_MISSING='glpi_missing'`, `GLPI_REVIEW='glpi_review'`, `NMS_DEAD='nms_dead'`, `NMS_UNKNOWN='nms_unknown'`, `UNMANAGED_ALIVE='unmanaged_alive'`, `OK='ok'`, `EXCEPTED='excepted'`, `NONE=''`.
  - `Classifier::FLOW_OF = [GLPI_MISSING=>'glpi', GLPI_REVIEW=>'glpi', NMS_DEAD=>'nms', NMS_UNKNOWN=>'nms']`.
  - `Classifier::labels(): array<string,string>`.
  - `Classifier::isUnmanaged(string $availability, list<string> $values): bool`.
  - `Classifier::classify(array{availability:string, match_state:string, last_seen:?string} $device, string $liveness, bool $excepted, string $stale_before, list<string> $unmanaged_values): string`. `$liveness` is `'alive'|'dead'|'unknown'|''`.
  - `Cidr::contains(string $ip, list<string> $cidrs): bool`. IPv4 only; invalid entries are ignored.
  - `Cidr::isValid(string $cidr): bool`.

- [ ] **Step 1: Write the failing test** `tests/hygiene_classifier_test.php`. Use `$u = ['UnManaged','Device Not Monitored.']` and `$stale = '2026-11-06 10:00:00'`, and check:
  - `isUnmanaged(' unmanaged ', $u) === true`
  - `isUnmanaged('Device not monitored.', $u) === true`
  - `isUnmanaged('Clear', $u) === false`
  - Monitored + `unmatched` gives `GLPI_MISSING`.
  - Monitored + `review` gives `GLPI_REVIEW`.
  - Monitored + `matched` gives `OK`.
  - Unmanaged + `dead` gives `NMS_DEAD`.
  - Unmanaged + `unknown` gives `NMS_UNKNOWN`.
  - Unmanaged + `''` (not yet checked) gives `NMS_UNKNOWN`.
  - Unmanaged + `alive` gives `UNMANAGED_ALIVE`, whether matched or unmatched.
  - `excepted = true` gives `EXCEPTED` for both a monitored-unmatched and an unmanaged-dead device.
  - Availability `''` gives `NONE`.
  - `last_seen = '2026-11-06 09:59:59'` (older than `$stale`) gives `NONE`, even when excepted.
  - `last_seen = null` gives `NONE`.
  - `Cidr::contains('10.129.12.60', ['10.129.0.0/16']) === true`
  - `Cidr::contains('10.94.8.6', ['10.129.0.0/16']) === false`
  - `Cidr::contains('10.0.0.1', ['garbage', '10.0.0.0/8']) === true`
  - `Cidr::contains('10.0.0.1', ['10.0.0.1/32']) === true`
  - `Cidr::isValid('10.0.0.0/33') === false`
- [ ] **Step 2: Run** `php tests/hygiene_classifier_test.php` and confirm it FAILs (class not found).
- [ ] **Step 3: Implement.** Order inside `classify`:
  1. stale or empty availability gives `NONE`;
  2. excepted gives `EXCEPTED`;
  3. unmanaged branches on liveness;
  4. monitored branches on `match_state`.

  `isUnmanaged` compares with `strcasecmp(trim(...))`.
- [ ] **Step 4: Run** the test and confirm `ALL PASS`.
- [ ] **Step 5: Commit** with `feat: hygiene classifier and CIDR helper (D34)`.

### Task 3: Liveness evidence and combination (pure)

**Files:**
- Create: `src/Hygiene/Liveness.php`
- Test: `tests/hygiene_liveness_test.php`

**Interfaces:**
- Produces:
  - Evidence type `array{verdict: 'alive'|'dead', source: string, detail: string}`. `source` is the provider name, at most 32 characters (spec §3).
  - `Liveness::fromAgent(?string $last_contact, string $now, int $stale_days): ?array`
  - `Liveness::fromMist(?array{status:string, mist_last_seen:?string} $row, string $now, int $stale_days): ?array`
  - `Liveness::fromProbe(?array{open:?int, tried:list<int>} $result): ?array`. Here `null` means not probed; `open` is the first open port or null.
  - `Liveness::combine(list<array> $evidence): array{state:string, source:string, detail:string}`

- [ ] **Step 1: Write the failing test.** With `$now = '2026-11-06 12:00:00'` and `stale_days = 7`:
  - **Agent:**
    - `fromAgent('2026-11-01 00:00:00', …)` gives verdict `alive`, and its detail contains `2026-11-01`.
    - `fromAgent('2026-10-01 00:00:00', …)` gives verdict `dead`.
    - `fromAgent(null, …) === null`.
  - **Mist:**
    - `status 'connected'` gives `alive`, whatever the last seen.
    - `status 'disconnected'` + last seen `2026-11-05` gives `alive`, because it was seen recently.
    - `status 'disconnected'` + last seen `2026-10-01` gives `dead`.
    - `status 'disconnected'` + `null` gives `dead`.
    - A `null` row gives `null`.
  - **Probe:**
    - `['open'=>443,'tried'=>[22,443]]` gives `alive`, with detail `TCP 443 open`.
    - `['open'=>null,'tried'=>[22,443]]` gives `dead`, with detail `TCP 22,443 no answer`.
    - `null` gives `null`.
  - **Combine:**
    - `[]` gives `['state'=>'unknown','source'=>'','detail'=>'no evidence source applies']`.
    - `[dead agent, alive probe]` gives state `alive`, source `probe`.
    - `[dead agent, dead mist]` gives state `dead`, source `agent`, and the detail joins both details with `; `.
- [ ] **Step 2: Run** the test and confirm it FAILs.
- [ ] **Step 3: Implement.** Compare dates as strings: `$cutoff = date('Y-m-d H:i:s', strtotime($now) - $days*86400)`. Combine: the first alive item wins; otherwise the first dead item gives the source and all dead details are joined.
- [ ] **Step 4: Run** the test and confirm `ALL PASS`.
- [ ] **Step 5: Commit** with `feat: hygiene liveness evidence (D34)`.

### Task 4: TicketPlanner (pure diff and ticket decision)

**Files:**
- Create: `src/Hygiene/TicketPlanner.php`
- Test: `tests/hygiene_planner_test.php`

**Interfaces:**
- Produces: `TicketPlanner::plan(array<string,string> $current, array<string,array{state:string, resolved:bool}> $reported, ?int $ticket_status): array{action:'none'|'create'|'followup', new:array<string,string>, changed:array<string,array{0:string,1:string}>, resolved:list<string>}`.
  - `$current` maps device_key to state, for this flow only.
  - `$reported` holds the items of this flow's latest hygiene ticket.
  - `$ticket_status` is `null` when there is no ticket. It uses the gateway's status values: GLPI status 1–6, and `TicketGateway::TRASHED` for a trashed ticket.
- Rules (spec §4):
  - Closed, meaning status 5, status 6, `TRASHED` or `null`: `create` when `$current` is not empty, else `none`. `new` = all of `$current`.
  - Open: `new` = keys in current and not in reported, plus keys reported with `resolved=true` (they come back). `changed` = keys in both, not resolved, where the state differs (`[old, new]`). `resolved` = keys reported, not resolved, and not in current. `action` = `followup` if any of the three is non-empty, else `none`.

- [ ] **Step 1: Write the failing test.** Cover each rule:
  - no ticket + empty current gives `none`;
  - no ticket + 2 devices gives `create` with both in `new`;
  - status 5 gives `create`;
  - `TRASHED` gives `create` (Review Focus 5);
  - open (status 2) + same set and same states gives `none`;
  - open + one new key gives `followup` with only it in `new`;
  - open + `nms_dead` changed to `nms_unknown` gives it in `changed` as `['nms_dead','nms_unknown']`;
  - open + a reported key missing from current gives it in `resolved`;
  - open + a key reported with `resolved=true` and present again gives it in `new`.
- [ ] **Step 2: Run** the test and confirm it FAILs.
- [ ] **Step 3: Implement** as specified above.
- [ ] **Step 4: Run** the test and confirm `ALL PASS`.
- [ ] **Step 5: Commit** with `feat: hygiene ticket planner (D34)`.

### Task 5: Report text (pure)

**Files:**
- Create: `src/Hygiene/Report.php`
- Test: `tests/hygiene_report_test.php`

**Interfaces:**
- Consumes: `Classifier` constants (Task 2).
- Produces:
  - `Report::title(string $flow, string $now): string`. For `glpi`: `[OPM-HYGIENE] Monitored in OpManager, not in GLPI - {date}`. For `nms`: `[OPM-HYGIENE] Unmanaged in OpManager: remove or check - {date}`. `{date}` is the `Y-m-d` part of `$now`.
  - `Report::body(string $flow, array $devices, string $list_url): string`. Each device is `array{device_key, name, ip, category, availability, asset:string, state, liveness, detail, last_seen}`. Output is HTML grouped by state:
    - `glpi`: section "Not found in GLPI" (`glpi_missing`), then "Needs manual pairing" (`glpi_review`).
    - `nms`: section "Dead - candidate for removal from OpManager" (`nms_dead`), then "Liveness unknown - check manually" (`nms_unknown`).
    - The body ends with the `$list_url` link and the line "Exclude a device from these tickets on its OpManager device page (reason required)."
  - `Report::followup(array $plan, array $devices_by_key): string`. Sections: "New", "Changed condition (old → new)", "No longer applies". An empty section is omitted.
  - Every value goes through `htmlspecialchars`.

- [ ] **Step 1: Write the failing test.**
  - The `nms` body with one dead and one unknown device contains both section headings in that order.
  - A device name `<b>x</b>` is escaped.
  - A `glpi` body with no review devices has no "Needs manual pairing" heading.
  - The followup with only `resolved` has neither the "New" nor the "Changed condition" heading.
  - The title for `nms` with `$now = '2026-11-06 12:00:00'` ends with `2026-11-06`.
- [ ] **Step 2: Run** the test and confirm it FAILs.
- [ ] **Step 3: Implement** an HTML `<table class="table">` per section, with the columns Name, IP, Category, Availability, GLPI asset, Liveness, Evidence and Last seen.
- [ ] **Step 4: Run** the test and confirm `ALL PASS`.
- [ ] **Step 5: Commit** with `feat: hygiene ticket text (D34)`.

### Task 6: Liveness providers: registry, agent, Mist, TCP probe, extension hook

**Files:**
- Create: `src/Hygiene/Providers.php` (registry), `src/Hygiene/AgentProvider.php`, `src/Hygiene/MistProvider.php`, `src/Hygiene/ProbeProvider.php`, `src/Hygiene/TcpProber.php`
- Test: `tests/hygiene_providers_test.php`, `tests/hygiene_prober_test.php`, `tests/hygiene_evidence_static_test.php`

**Interfaces:**
- Consumes: `Liveness::fromAgent/fromMist/fromProbe` (Task 3), `Cidr::contains` (Task 2).
- Produces:
  - Provider contract (spec §3): `callable(array $device, string $now): ?array{verdict:'alive'|'dead', detail:string}`.
  - `Providers::HOOK = 'opmanager_liveness'`.
  - `Providers::normalise(array $hook_entries): array<string, callable>`. Accepts `[plugin => callable]` or `[plugin => [name => callable]]`. Names are cut to 32 characters, and non-callables are dropped.
  - `Providers::collect(array $device, string $now, array<string,callable> $providers, list<string> &$errors): list<evidence>`. It adds `source` = the provider name, drops results whose verdict is not `alive`/`dead`, and catches `\Throwable` into `$errors` as `"provider {name}: {message}"`.
  - `Providers::builtIn(array $cfg): array<string,callable>`, in the order `agent`, `mist`, `probe`.
  - `Providers::all(array $cfg): array<string,callable>`. It returns `builtIn` plus `normalise($PLUGIN_HOOKS[self::HOOK] ?? [])`. An external entry never overrides a built-in name.
  - `AgentProvider::__invoke`: only when `match_state === 'matched'`. It reads `glpi_agents.last_contact` by `itemtype`/`items_id` and passes it through `Liveness::fromAgent`.
  - `MistProvider::__invoke`: only when `Plugin::isPluginActive('mist')` and `tableExists('glpi_plugin_mist_devices')`. It matches the asset first, then `ip_address` (`ORDER mist_last_seen DESC LIMIT 1`), and passes the row through `Liveness::fromMist`.
  - `ProbeProvider::__construct(TcpProber $prober, list<string> $cidrs, list<int> $ports)`. It returns `null` outside the CIDRs or when the budget is spent; otherwise it returns `Liveness::fromProbe`.
  - `TcpProber::__construct(int $timeout_ms, float $budget_seconds = 120.0, ?callable $clock = null)`.
  - `TcpProber::probe(string $ip, list<int> $ports): ?array{open:?int, tried:list<int>}`. Uses `@stream_socket_client`, and returns `null` when the budget is spent.

- [ ] **Step 1: Write the failing tests.**
  - `hygiene_providers_test.php`:
    - `normalise(['forti' => $fn])` gives the key `forti`.
    - `normalise(['x' => ['fortimanager' => $fn, 'bad' => 'nope']])` gives only `fortimanager`.
    - `collect` adds `source`.
    - A provider that throws adds an error mentioning its name, and the other providers still run.
    - A provider that returns `['verdict' => 'maybe']` is dropped.
    - A provider that returns `null` adds nothing.
    - `all()` with a hook entry named `agent` keeps the built-in one.
  - `hygiene_prober_test.php`:
    - With a local `stream_socket_server('tcp://127.0.0.1:0')`, `probe('127.0.0.1', [1, $port])` gives `open === $port`.
    - `probe(…, [1])` gives `open === null`.
    - A fake clock already past the budget gives `null` (Review Focus 1).
  - `hygiene_evidence_static_test.php`:
    - `MistProvider.php` contains `isPluginActive('mist')` and `tableExists('glpi_plugin_mist_devices')`.
    - `AgentProvider.php` reads `glpi_agents` / `last_contact`.
    - No provider file contains `DB->insert`, `DB->update` or `DB->delete`.
- [ ] **Step 2: Run** the three tests and confirm they FAIL.
- [ ] **Step 3: Implement** as specified above.
- [ ] **Step 4: Run** the three tests and confirm `ALL PASS`.
- [ ] **Step 5: Commit** with `feat: pluggable liveness providers (agent, mist, probe, hook) (D34)`.

### Task 7: Hygiene config: sanitizer, accessor, Setup tab

**Files:**
- Modify: `src/Config.php` (add `sanitizeHygiene`, `hygieneConfig`)
- Modify: `front/config.php` (merge `sanitizeHygiene` like `sanitizeTicketing`)
- Modify: `src/SuiteConfig.php` (new tab `hygiene` in `TABS`, label "NMS hygiene")
- Modify: `src/SetupStatus.php` (badge "Hygiene")
- Test: `tests/config_test.php`, `tests/setupstatus_test.php`, `tests/suiteconfig_test.php`

**Interfaces:**
- Consumes: `Cidr::isValid` (Task 2).
- Produces:
  - `Config::sanitizeHygiene(array $post): array{values: array<string,string>, errors: list<string>}`
  - `Config::hygieneConfig(): array{enabled: bool, frequency_days: int, unmanaged_values: list<string>, agent_stale_days: int, mist_stale_days: int, probe_cidrs: list<string>, probe_ports: list<int>, probe_timeout_ms: int, template_id: int, glpi_groups: list<int>, nms_group: int}`
- Validation (an error keeps the stored value):
  - `hygiene_frequency_days` 1–90; `hygiene_agent_stale_days` and `hygiene_mist_stale_days` 1–365; `hygiene_probe_timeout_ms` 100–5000.
  - Each CIDR line must be valid, or it is an error naming the line.
  - Ports must be in 1–65535, unique, and at most 10.
  - The unmanaged values list must be non-empty (trimmed lines).
  - `hygiene_enabled = 1` with an empty `hygiene_glpi_groups` or `hygiene_nms_group = 0` is an error, and `hygiene_enabled` is stored as `'0'`.

- [ ] **Step 1: Write the failing tests.**
  - `config_test.php`:
    - A valid post round-trips.
    - `frequency 0` is an error.
    - CIDR `10.0.0.0/33` is an error that mentions `10.0.0.0/33`.
    - Port `70000` is an error.
    - Enabled with no groups gives an error and `values['hygiene_enabled'] === '0'`.
    - CRLF in the unmanaged textarea is normalised.
  - `setupstatus_test.php`:
    - Given `last_hygiene_run` set and `last_hygiene_error` set, the result includes an item labelled `Hygiene` with level `error`.
    - Given `hygiene_enabled` `'0'`, the item has level `warn` and text `tickets off`.
  - `suiteconfig_test.php`:
    - `SuiteConfig::TABS` contains `'hygiene'`.
- [ ] **Step 2: Run** `php tests/run_all.php` and confirm the new checks FAIL.
- [ ] **Step 3: Implement.**
  - The tab shows fields for every key except `last_*`.
  - Groups use `Group::dropdown`, multiple for `hygiene_glpi_groups`.
  - The template uses a `TicketTemplate` dropdown.
  - Underneath, show the per-state counts from `HygieneStore::counts()` (Task 8). Until Task 8 exists, the tab renders without counts; wire the counts in Task 8.
- [ ] **Step 4: Run** `php tests/run_all.php` and confirm `SUITES OK`.
- [ ] **Step 5: Commit** with `feat: hygiene settings in Setup (D34)`.

### Task 8: HygieneRun orchestrator, stores, gateway, cron

**Files:**
- Create: `src/Hygiene/HygieneStore.php` (interface), `src/Hygiene/DbHygieneStore.php`, `src/Hygiene/HygieneGateway.php` (interface), `src/Hygiene/GlpiHygieneGateway.php`, `src/Hygiene/HygieneRun.php`
- Modify: `src/Cron.php` (`cronInfo`, `cronHygieneCheck`)
- Test: `tests/hygiene_run_test.php` (fakes), `tests/hygiene_gateway_static_test.php`

**Interfaces:**
- Consumes: Tasks 2–7.
- Produces:
  - `HygieneStore`:
    - `devices(): list<array{id, device_key, name, display_name, ip_address, device_category, availability, itemtype, items_id, match_state, last_seen, liveness_state}>`
    - `activeExceptionKeys(): list<string>`
    - `saveDevice(int $id, array{liveness_state, liveness_source, liveness_detail, liveness_checked_at, hygiene_state} $f): void`
    - `latestTicket(string $flow): ?array{id:int, tickets_id:int}`
    - `reportedItems(int $hygienetickets_id): array<string,array{state:string,resolved:bool}>`
    - `recordTicket(string $flow, int $tickets_id, string $now): int`
    - `recordItems(int $hygienetickets_id, array $plan, string $now): void`. It upserts `new` (sets `reported_at`, clears `resolved_at`), sets `changed_at` and the state for `changed`, and sets `resolved_at` for `resolved`.
    - `counts(): array<string,int>`
  - `HygieneGateway`:
    - `status(int $tickets_id): ?int`. Same contract as `TicketGateway::status`, including `TRASHED`.
    - `create(string $title, string $html, int $template_id, list<int> $assign_groups, list<int> $observer_groups): int`. Returns 0 on failure.
    - `followup(int $tickets_id, string $html): bool`
  - `HygieneRun::__construct(HygieneStore, array<string,callable> $providers, HygieneGateway, array $cfg)`, where `$cfg` is `Config::hygieneConfig()` plus `service_user`, `entity_default` and `list_url`. The cron passes `Providers::all($cfg)`.
  - `HygieneRun::run(string $now, bool $tickets_due): array{classified:int, probed:int, tickets_created:int, followups:int, errors:list<string>}`
- Behaviour of `run`:
  1. For each device that is not excepted and is unmanaged (`Classifier::isUnmanaged`), call `Providers::collect`, then `Liveness::combine`. Provider errors are appended to the run errors. If no provider gave evidence and the stored `liveness_state` is `alive` or `dead`, keep the stored liveness, because the probe budget may have run out. Otherwise use `unknown`. A missing result never turns a device dead.
  2. Classify every device with `$stale_before = now − 2 × DEVICE_SYNC_MINUTES`, then `saveDevice`. Monitored devices get the liveness fields cleared.
  3. If `$tickets_due` and `enabled`: for each flow (`glpi`, then `nms`), call `TicketPlanner::plan`.
     - `create`: `gateway->create` with the GLPI flow (assign `glpi_groups`, observer `[nms_group]`) or the NMS flow (assign `[nms_group]`, observer `[]`), then `recordTicket` and `recordItems`.
     - `followup`: `gateway->followup`, then `recordItems`.
     - A `0` or `false` result adds an error and skips `recordItems`.
- `GlpiHygieneGateway::create` builds the template:
  - Template: `getITILTemplateToUse(0, \Ticket::DEMAND_TYPE, 0, $entity)` when `template_id` is 0, else `getFromDBWithData`. Check mandatory fields with `TicketBuilder::missingMandatory`.
  - Input: `type => \Ticket::DEMAND_TYPE` (set last, so that it overrides the predefined type), `_groups_id_assign`, `_groups_id_observer`, `_users_id_requester => service_user`, `entities_id => entity_default`, `externalid => 'opmanager:hygiene:{flow}:{now}'`.
  - Followups go through `withoutAutoAssign`. Make that helper `public static` on `GlpiTicketGateway` and call it from there; do not copy it.
  - Do not use `asPlugin`: its static `$writing` counter is what the outbound hooks read, and hygiene tickets carry no alarm link, so the hooks already ignore them.
- `cronHygieneCheck`:
  - If `service_user` is `<= 0`, log the error and return 0, as `cronAlarmSync` does.
  - `$tickets_due = last_hygiene_ticket_run === '' || now ≥ last + frequency_days`.
  - Set `last_hygiene_run` = now and `last_hygiene_error` = joined errors.
  - Set `last_hygiene_ticket_run` = now **only** if `$tickets_due && enabled && errors === []` (Review Focus 4).

- [ ] **Step 1: Write the failing tests.** `hygiene_run_test.php` uses an in-memory store, fake gateway and fake provider closures. It checks:
  - An unmanaged device with an `agent` closure returning dead gets `nms_dead` and `liveness_source 'agent'`.
  - An unmanaged device whose providers all return `null` gets `nms_unknown`.
  - A `forticloud` closure returning alive beats an `agent` closure returning dead, giving `unmanaged_alive` with source `forticloud`.
  - A throwing provider adds a run error and the device is still classified.
  - When all providers return `null` and the stored liveness is `alive`, the device stays `unmanaged_alive`.
  - Monitored devices never call any provider.
  - An excepted device gets `excepted` and appears in no ticket.
  - `tickets_due=false` creates no tickets.
  - `tickets_due=true` and `enabled=true` create one GLPI ticket, assigned `[11,12]` with observer `[20]`, and one NMS ticket, assigned `[20]`.
  - A second run with the same state and an open ticket makes no gateway call.
  - A gateway create returning 0 adds an error and records no items.
  - `enabled=false` with `tickets_due=true` creates no ticket and still classifies.

  `hygiene_gateway_static_test.php` checks:
  - `GlpiHygieneGateway.php` contains `\Ticket::DEMAND_TYPE`, `_groups_id_observer`, `missingMandatory` and `withoutAutoAssign`;
  - it has no raw writes to `glpi_tickets`;
  - `Cron.php` contains `cronHygieneCheck` and `'last_hygiene_ticket_run'`;
  - the existing `gateway_static_test.php` still passes after `withoutAutoAssign` becomes public.
- [ ] **Step 2: Run** `php tests/run_all.php` and confirm the new checks FAIL.
- [ ] **Step 3: Implement.** Then wire `HygieneStore::counts()` into the Setup tab from Task 7.
- [ ] **Step 4: Run** `php tests/run_all.php` and confirm `SUITES OK`.
- [ ] **Step 5: Commit** with `feat: hygiene run, tickets and cron (D34)`.

### Task 9: Exceptions and device UI

**Files:**
- Create: `src/Hygiene/DeviceException.php` (`CommonDBTM`, table `glpi_plugin_opmanager_deviceexceptions`)
- Modify: `src/Device.php` (search options 10–13, `getSpecificValueToDisplay/Select` for `hygiene_state` and `liveness_state`, a "NMS hygiene" block in `showForm`)
- Modify: `front/device.form.php` (`except` / `unexcept` actions)
- Test: `tests/device_test.php`, `tests/hygiene_exception_test.php`

**Interfaces:**
- Consumes: `Classifier::labels()` (Task 2), `RightDefinition::HYGIENE` (Task 1).
- Produces:
  - `DeviceException::validateReason(string $reason): ?string`. The reason is trimmed and must be 5–1000 characters.
  - `DeviceException::except(string $device_key, string $reason, int $users_id): ?string`. Refuses when an active exception already exists.
  - `DeviceException::revoke(string $device_key, int $users_id): bool`
  - `DeviceException::history(string $device_key): list<array>`
- Search options on Device:
  - 10 `hygiene_state` (specific, labels);
  - 11 `liveness_state` (specific: alive / dead / unknown / not checked);
  - 12 `liveness_detail` (string);
  - 13 "Monitored in OpManager": computed from `availability`. Shown with `Classifier::isUnmanaged` and the configured values, and not searchable (`nosearch`), because `hygiene_state` already filters.
- Form block, visible with `HYGIENE` READ:
  - shows the condition, the liveness with source, detail and checked time, the active exception (who, when, reason), the exception history, and links to open hygiene tickets that contain this device;
  - with `HYGIENE` UPDATE: a reason textarea with the "Exclude from hygiene tickets" button, or "Cancel exclusion" when an exception is active.

- [ ] **Step 1: Write the failing tests.**
  - `hygiene_exception_test.php`: `validateReason('')` is not null; `validateReason('   ok  ')` is not null (too short); `validateReason('ISP-owned router')` is null.
  - `device_test.php`:
    - `device.form.php` checks `checkRight(RightDefinition::HYGIENE, UPDATE)` for `except` and `unexcept`;
    - `rawSearchOptions` ids include 10 and 11;
    - `Classifier::labels()` keys equal the 8 constants.
- [ ] **Step 2: Run** the tests and confirm they FAIL.
- [ ] **Step 3: Implement.**
  - Record `users_id = Session::getLoginUserID()`.
  - `revoke` sets `is_active=0`, `revoked_by` and `revoked_at`. It never deletes.
  - After `except` and `revoke`, set the device's `hygiene_state` right away (`excepted`, or `''` until the next run), so the list reflects the change before the cron runs.
- [ ] **Step 4: Run** `php tests/run_all.php` and confirm `SUITES OK`.
- [ ] **Step 5: Commit** with `feat: hygiene exceptions and device view (D34)`.

### Task 10: Release 1.3.0-rc1

**Files:**
- Modify: `setup.php` (version `1.3.0-rc1`), `CHANGELOG.md` (section `1.3.0-rc1`)
- Create: `docs/runbook-v1.3.0.md`. It covers:
  - granting `plugin_opmanager_hygiene` UPDATE to the NMS and GLPI team profiles;
  - setting the groups, CIDRs and template;
  - leaving `hygiene_enabled` off for one week and reviewing the Devices list filtered by hygiene condition;
  - then turning it on.

- [ ] **Step 1: Update** the version, the CHANGELOG (user-facing bullets, in the style of 1.1.0) and the runbook.
- [ ] **Step 2: Full suite.** Run `php tests/run_all.php` and confirm `SUITES OK`. Run `graphify update .`.
- [ ] **Step 3: Commit.** Use `chore: release 1.3.0-rc1 (D34 NMS hygiene)`. Tagging, zipping (`git archive --prefix=opmanager/ -o opmanagerv1.3.0-rc1.zip 1.3.0-rc1`), `python tools/release_check.py opmanagerv1.3.0-rc1.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins`, and pushing happen **only after the user says OK** (memory: push/release by Claude after user OK).
- [ ] **Step 4: Report to the orchestrator.** Send the spec path, the rc zip name and spec §8 (tables, columns, right, config keys, cron) so that `nms` can carry it.
