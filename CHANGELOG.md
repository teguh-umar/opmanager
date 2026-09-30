# Changelog

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
