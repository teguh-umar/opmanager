# Changelog

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
