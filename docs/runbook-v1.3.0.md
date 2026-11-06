---
date: 2026-11-06
tags: [opmanager, runbook, hygiene, D34]
status: active
---

# opmanager v1.3.0-rc2: NMS hygiene runbook

Spec: `docs/superpowers/specs/2026-11-06-opmanager-unmanaged-hygiene-design.md`.

## 1. Install

```bash
cd /data/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv1.3.0-rc2.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /data/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] Selfcheck prints `ALL PASS (21 passed, 0 failed)`. The new checks are `hygiene/schema`, `hygiene/cron-cli` and `hygiene/groups`.
- [ ] Setup > Automatic actions lists **HygieneCheck** (OpManager), daily, mode CLI.

## 2. Rights

- [ ] Administration > Profiles > profile of the **NMS team**: OpManager > **NMS hygiene** = Read + Update.
- [ ] Same for the profile of the **GLPI team**.
- [ ] Super-admin already has the full right. Technician profiles have Read.

## 3. Setup (Setup > General > suite tab > OpManager > NMS hygiene)

- [ ] **GLPI and asset team groups:** pick both groups.
- [ ] **NMS team group:** pick it.
- [ ] **Template:** leave it empty (default Request template), or pick a Request template without mandatory fields the plugin cannot fill.
- [ ] **Unmanaged availabilities:** `UnManaged` and `Device Not Monitored.` (default). Add any other value the device list shows for unmonitored devices.
- [ ] **Reachable segments (CIDR):** list only the segments the GLPI server can reach. A segment that is not listed is never probed, so its devices are never declared dead by a probe.
- [ ] **Probe ports:** keep the default `22,443,80,3389,161`, or adjust.
- [ ] **Create hygiene tickets:** leave **off** for the first week.
- [ ] Save.

## 4. First week: review only

- [ ] Run once by hand: `sudo -u nginx php /data/www/glpi/front/cron.php --force HygieneCheck`. The status badge "NMS hygiene" shows `tickets off` (yellow). No error.
- [ ] OpManager > Devices: add the columns **Hygiene condition**, **Liveness** and **Liveness evidence**.
- [ ] Filter **Hygiene condition = Unmanaged, dead**. Each row's evidence is plausible (agent silent for weeks, Mist disconnected, TCP no answer).
- [ ] Filter **Unmanaged, still alive**. Unmanaged devices that are still in GLPI appear here if their agent still reports.
- [ ] Filter **Monitored, not in GLPI**. Expect devices with "Not found in GLPI" that OpManager still monitors.
- [ ] Open a device such as 127.0.0.1. Exclude it with a reason. The condition becomes "Excluded from hygiene tickets" at once. Cancel the exclusion: the history row keeps who excluded it, when and why, and who cancelled it and when.

## 5. Turn tickets on

- [ ] Setup > NMS hygiene: tick **Create hygiene tickets** and Save.
- [ ] The first run after enabling creates the tickets at once (no ticket run was recorded while they were off).
- [ ] Run `front/cron.php --force HygieneCheck`. Two Request tickets appear:
  - `[OPM-HYGIENE] Monitored in OpManager, not in GLPI - <date>`: assigned to the GLPI and asset groups, with the NMS group as observer;
  - `[OPM-HYGIENE] Unmanaged in OpManager: remove or check - <date>`: assigned to the NMS group, in two sections, "Dead" and "Liveness unknown".
- [ ] Run it again: no new ticket and no followup (nothing changed, tickets still open).
- [ ] Solve the NMS ticket. The next weekly run creates a new one with the full current list.

## 6. Rollback

Install the 1.2.0 zip and run `plugin:install --force`. The hygiene tables and columns stay. 1.2.0 ignores them.
