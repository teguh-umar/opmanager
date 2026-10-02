# Changelog

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
