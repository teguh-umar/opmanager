---
date: 2026-09-29
tags: [opmanager, runbook]
status: active
---

# opmanager v0.1.0 — VM verification runbook

Run on VM-SERVER-18 (`/var/www/glpi`). Paste every command's output back to the session.
Tick a box only with the evidence named on it.

## 1. Install

```bash
cd /var/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.1.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /var/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /var/www/glpi/bin/console plugin:activate opmanager
sudo -u nginx php /var/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] selfcheck prints `ALL PASS`. If `matching/itemtypes-loadable` fails, the configured
      Security/Storage types cannot be loaded from CLI: write down the exact message and do
      not tick §4 below.
- [ ] Setup > Automatic actions: `DeviceSync` shows RUN MODE = CLI.

## 2. Setup

- [ ] Setup > General > ICT Tools shows an **OpManager** sub-tab, and there is exactly one
      "ICT Tools" tab.
- [ ] Enter URL + API key, save, reload: the key field is empty with "Stored" placeholder.
- [ ] Tick Computer, Network devices, **Security**, **Storage**; save; reload - still ticked.
      Write down the two custom class names shown in the page source (`value="Glpi\CustomAsset\…Asset"`).

## 3. First device sync

```bash
sudo -u nginx php /var/www/glpi/front/cron.php DeviceSync
sudo tail -n 20 /var/www/glpi/files/_log/opmanager.log
```

- [ ] Automatic action log shows `fetched N, matched …` with N > 0.
      **If it says "not valid JSON" or "unexpected shape":** the OpManager response differs
      from the assumed `/api/json/device/listDevices` format (spec §13 item 1). Capture one
      raw response with the key redacted, and stop here - do not tick further boxes.
- [ ] OpManager > OpManager devices lists the devices; filter Match state = Needs review works.

## 4. Matching and the Monitoring section

- [ ] Open one matched Computer: a **ICT Tools** tab with a **Monitoring** sub-tab shows the
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
