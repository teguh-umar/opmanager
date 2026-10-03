# Changelog

## 1.1.0

- New Setup option **Ticket source** (tab Tickets), default **OpManager webhook criteria** (D32):
  - only alarms that the OpManager "Invoke a Webhook" profile sends get a ticket, if their
    IP is in a production range; the severities and the alarm filter are not used;
  - a Solved ticket is reopened, and a ticket in the trash is restored, only when the
    webhook sends that alarm again; the poll alone never creates, reopens or restores;
  - the poll still stores every alarm and keeps followups, Clear and Solve of existing tickets;
  - a webhook answered 202 (sync busy, API down) is remembered and handled by the next poll.
- A webhook alarm whose ticket could not be created (error, or a template mandatory field missing) is retried by the next poll until it succeeds.
- Do not enable repeat notifications on the OpManager webhook profile: every repeat counts as a new webhook and reopens a Solved ticket.
- Known limitation: a ticket that a technician trashes while it is linked as a child (Linked tickets) of another ticket is treated like a merged ticket; its alarm moves to the parent.
- **Plugin rules** keeps the 1.0.2 behaviour.
- An alarm whose ticket was merged now follows the ticket it was merged into.
- Setup: the Webhook badge is red when the webhook mode has no token (no ticket would be created).
- Upgrade adds the table `glpi_plugin_opmanager_ticketrequests`. Check in OpManager that the
  webhook profile has the criteria you want (e.g. Device Down, Critical) and "Notify when the
  alarm is cleared".
## 1.0.2

- Fix (D31): a flapping alarm (for example a CPU threshold going Clear / Critical every
  poll) no longer adds a followup to a **Solved** ticket on every Clear. A Solved ticket
  gets no further "is Clear" followups.
- Stricter followups on open tickets: one alarm adds at most **one followup per hour**,
  whatever changed (severity, message, active again, Clear). Before, only message changes
  were limited; severity changes and Clear / active-again were posted every time.
- A Clear that is held back by this limit still solves the ticket when all its alarms are Clear.

## 1.0.1

- Fix (D30): a **Closed** ticket is no longer reopened, or followed up, when its OpManager
  alarm comes back with the same alarm ID. Before, a flapping CPU threshold alarm reopened
  closed tickets again and again.
- A recurrence after Close is now treated as a **new alarm**: severity, production ranges
  and the alarm filter apply. If it passes, it gets a new ticket linked to the closed one.
  If it does not pass (for example CPU, when the filter is "Only: Device Down"), it is only
  recorded.
- A **Solved** ticket is reopened only if its alarm still passes the alarm filter.
- Tip: to have GLPI close solved tickets at once, set the entity's *Automatic closing of
  solved tickets after* to *Immediately*.

## 1.0.0

First major release. It contains no new features; it is the production-verified state of 0.6.0
on VM-GLPI-01 (user, 2026-10-02):
- OpManager device sync and matching to GLPI assets, with a Monitoring section on the assets;
- tickets from Critical alarms on production IP ranges, with an alarm filter;
- an OpManager webhook receiver;
- GLPI → OpManager: notes, acknowledge and clear for actions typed by technicians;
- the Coverage page and native dashboard cards;
- the Setup page as a status strip plus tabs.

A `/code-review low` pass over the whole history (`d4b7871..0.6.0`) reported no findings.

## 0.6.0

- **Coverage page** (OpManager → Coverage) compares OpManager devices with GLPI assets:
  - KPI cards: coverage %, GLPI assets in scope, OpManager devices, Not in GLPI, Not monitored, Ambiguous;
  - five follow-up lists: Not in GLPI, Not monitored, Ambiguous, Name differs, Stale;
  - each list is paged, has links to the asset or device, and exports to CSV (Excel-ready);
  - filters: production IP ranges only, and category.
- **Native GLPI dashboard cards** (group "OpManager" under *Add widget*):
  - coverage %, devices, not monitored and not in GLPI as big numbers;
  - matching as a pie, devices by category as stacked bars, active alarms by severity, open alarm tickets;
  - every card checks the monitoring right; figures are cached for 5 minutes per entity set.
- **Setup redesigned:** a status strip (API key, device sync, alarm sync, webhook, queue to
  OpManager), then tabs (Connection, Matching, Tickets, Alarm filter, Webhook, To OpManager).
  The last tab used is remembered. All existing settings are unchanged.
- New setting (Matching tab): the GLPI statuses counted as "in use" by the Coverage page and
  the cards (empty = all).

## 0.5.0

- A public followup by a GLPI technician now also **acknowledges** each still-active alarm of
  the ticket in OpManager, after the note (D27). This shows in OpManager that someone is on
  it. Repeating an acknowledge is harmless, and no second one is queued while one is waiting.
- The same rules apply as for notes: human actions only; private followups, merged copies and
  the plugin's own followups are never sent.

## 0.4.1

- Webhook: `alarm_id` and `entity` accept any printable text up to 255 characters. Before, the
  limit was letters, digits and `._:-` up to 64, which can refuse real interface entities
  that contain `/`, spaces, or are longer.
- Webhook: OpManager "Test Action" sends `$alarmid`/`$entity` unfilled. The plugin now answers
  200 "test received" instead of 400, so the test passes, and no alarm is processed.
- Webhook: every answer is logged, including successful ones (`Webhook: HTTP 200 processed: ...`).
  A refused body is logged in part, so the log shows what OpManager actually sent.

## 0.4.0

- GLPI → OpManager:
  - when a GLPI user adds a public followup to an alarm ticket, each still-active alarm of
    the ticket gets a note;
  - when a GLPI user solves or closes the ticket, each still-active alarm gets a note and is
    then cleared in OpManager;
  - actions made by the plugin itself, by cron or by GLPI automation are never sent back.
- Queue with retries (1, 2, 4 … 60 min; the Setup limit defaults to 10). Setup shows the queue
  status and the last error.
- Alarm page: the title shows the device and the alarm ID (it was "N/A"), and the OpManager
  entity is shown.

## 0.3.0

- OpManager webhook receiver `/plugins/opmanager/front/webhook.php`:
  - OpManager "Invoke a Webhook" POSTs `{"alarm_id":"$alarmid","entity":"$entity"}` with header
    `X-OPM-Token`; the alarm is fetched from the API and processed by the same engine as
    AlarmSync, so tickets appear and are solved within seconds;
  - the 10-minute poll stays as the safety net.
- The token is generated in Setup, stored encrypted, and shown once.
- Cron and webhook share a MySQL lock, so one alarm is never ticketed twice.
- Alarm filter for new tickets (Setup > Tickets from alarms): tick OpManager event types
  and/or list message keywords; mode Exclude (e.g. no ticket for CPU Utilization) or Only.
  Filtered alarms are still stored and shown; alarms that already have a ticket are unaffected.
- The webhook creates tickets with the same rights and entities as the cron, re-reads the
  alarm only after taking the lock, shows a new token once in the Setup form, and logs
  every non-200 answer.
- Fix: ticket patterns saved from the Setup textarea (CRLF) are normalised, and the
  0.2.1 description upgrade now also recognises them.

## 0.2.2

- Fix: alarm and device sync failed with "Undefined constant
  GuzzleHttp\ClientInterface::MAJOR_VERSION" when another plugin (telegrambot) ships its own
  Guzzle 6. The OpManager client now uses ext-curl directly.

## 0.2.1

- The default ticket description now carries `Category: {category}` (OpManager's device
  category: Server, Switch, Firewall, Storage, Wireless, ...), so GLPI's own
  **Business rules for tickets** can map it to an ITIL category. A category is needed
  before a ticket can be Solved/Closed when the Behaviors plugin requires one.
- Upgrade: an installation still using the 0.2.0 default description is moved to the new
  one; a customised description is left untouched (add `Category: {category}` yourself).

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
- Tickets only for the ticketed severities (default Critical) **on production assets**: the alarm IP
  or an IP of the matched GLPI asset lies in an IP Addressing range selected in Setup.
- Ticket content through a GLPI ticket template, and title/description patterns in Setup
  (variables include `{category}`).
- Ticket entity: asset entity → GLPI IP network entity → default.
- API key now sent only in the `apiKey` header.
- Monitoring section lists the asset's OpManager alarms; new "OpManager alarms" list page.

## 0.1.1

- OpManager error responses (HTTP 200 with `{"error":{"code","message"}}`, e.g. a rejected
  API key) are now shown as `OpManager error <code>: <message>` in Setup and in
  `files/_log/opmanager.log`, instead of "device list has an unexpected shape".
- Verified on production 2026-09-30: with a valid key the device list parses as assumed
  (933 devices synced).

## 0.1.0

- First release: OpManager device sync (CLI cron `DeviceSync`, hourly) with matching to
  existing GLPI assets (Computer, NetworkEquipment, custom assets). Five match modes;
  manual pins always win; a name or IP owned by more than one asset goes to "Needs review".
- OpManager sidebar sector with the device list and manual pin/unpin.
- "Monitoring" section in the shared PGN Suite asset tab.
- Setup in Setup > General > PGN Suite > OpManager; API key encrypted at rest.
- Alarms and tickets are NOT in this release (Plan 2).
