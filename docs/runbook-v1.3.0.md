---
date: 2026-11-06
tags: [opmanager, runbook, hygiene, D34]
status: active
---

# opmanager v1.3.0: NMS hygiene runbook

Spec: `docs/superpowers/specs/2026-11-06-opmanager-unmanaged-hygiene-design.md`.

## 1. Install

```bash
cd /var/www/glpi/plugins && sudo mv opmanager /tmp/opmanager.bak-$(date +%F) && sudo -u nginx unzip -q /tmp/opmanagerv1.3.0.zip && sudo -u nginx php /var/www/glpi/bin/console glpi:plugin:install opmanager --force && sudo -u nginx php /var/www/glpi/bin/console glpi:plugin:activate opmanager && sudo -u nginx php /var/www/glpi/bin/console glpi:cache:clear && sudo systemctl reload php-fpm && sudo -u nginx php /var/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- The old folder goes to `/tmp` as a backup, never inside `plugins/` (GLPI would read it as another plugin). Rollback: move it back and run `plugin:install --force` and `plugin:activate` again.
- `plugin:install --force` leaves the plugin installed but **not activated**; without `plugin:activate`, selfcheck fails with `Attempted to load class "SelfCheck"`.

- [x] Selfcheck prints `ALL PASS (21 passed, 0 failed)`. The new checks are `hygiene/schema`, `hygiene/cron-cli` and `hygiene/groups`.
- [x] Setup > Automatic actions lists **HygieneCheck** (OpManager), daily, mode CLI.

## 2. Rights

- [x] Administration > Profiles > profile of the **NMS team**: OpManager > **NMS hygiene** = Read + Update.
- [x] Same for the profile of the **GLPI team**.
- [x] Super-admin already has the full right. Technician profiles have Read.

## 3. Setup (Setup > General > suite tab > OpManager > NMS hygiene)

- [x] **GLPI and asset team groups:** pick the groups that register assets, e.g. Infra > SysAdmin (server) and Infra > ITOC (network). Since rc7 they are **observers** on the "not in GLPI" ticket (Escalade keeps one assigned group only).
- [x] **Asset technician(s):** pick the technician(s) responsible for asset input and checks. Since rc7 they are the only assignees of the "not in GLPI" ticket, and at least one is required to turn tickets on. Pick one account per person (duplicate LDAP accounts show the same name twice).
- [ ] **ITIL category of the hygiene tickets** (rc5): optional. Hygiene tickets skip the ticket business rules (the alarm rules match their IP lists), so no rule sets a category. The Behaviors plugin needs one before Solve/Close.
- [x] **NMS team group:** pick the shared NMS group (members of both the server team and the network team, as direct members: GLPI does not notify sub-group members of a parent group).
- [x] **Template:** leave it empty (default Request template), or pick a Request template without mandatory fields the plugin cannot fill.
- [x] **Unmanaged availabilities:** `UnManaged` and `Device Not Monitored.` (default). Add any other value the device list shows for unmonitored devices.
- [x] **Reachable segments (CIDR):** list only the segments the GLPI server can reach. A segment that is not listed is never probed, so its devices are never declared dead by a probe.
- [x] **Probe ports:** keep the default `22,443,80,3389,161`, or adjust.
- [x] **Create hygiene tickets:** leave **off** for the first week.
- [x] Save.

## 3b. Manual

- [x] OpManager sub-navigation shows **Manual** (book icon). Setup > OpManager shows a **Manual** link above the tabs.
- [x] Both open the Manual page. Until `docs/manual.md` ships, it shows "The user manual is not installed with this copy of the plugin."

## 4. First week: review only

- [x] Run once by hand: `sudo -u nginx php /var/www/glpi/front/cron.php --force HygieneCheck`. The status badge "NMS hygiene" shows `tickets off` (yellow). No error.
- [x] OpManager > Devices: add the columns **Hygiene condition**, **Liveness** and **Liveness evidence**.
- [x] Filter **Hygiene condition = Unmanaged, dead**. Each row's evidence is plausible (agent silent for weeks, Mist disconnected, TCP no answer).
- [x] Filter **Unmanaged, still alive**. Unmanaged devices that are still in GLPI appear here if their agent still reports.
- [x] Filter **Monitored, not in GLPI**. Expect devices with "Not found in GLPI" that OpManager still monitors.
- [ ] Open a device such as 127.0.0.1. Exclude it with a reason. The condition becomes "Excluded from hygiene tickets" at once. Cancel the exclusion: the history row keeps who excluded it, when and why, and who cancelled it and when.

## 5. Turn tickets on

- [x] Only when re-testing after an earlier rc created tickets: put both old hygiene tickets in the trash, then reset the weekly gate so the next run creates new ones:
  `sudo -u nginx php /var/www/glpi/bin/console config:set --context=plugin:opmanager last_hygiene_ticket_run ""`
- [x] Setup > NMS hygiene: tick **Create hygiene tickets** and Save.
- [ ] The first run after enabling creates the tickets at once (no ticket run was recorded while they were off).
- [x] Run `front/cron.php --force HygieneCheck`. Two Request tickets appear:
  - `[OPM-HYGIENE] Monitored in OpManager, not in GLPI - <date>`: assigned to the asset technician(s) only (no assigned group), with the GLPI and asset groups and the NMS group as observers, split into "Server, VM and storage", "Network" and "Unknown category";
  - `[OPM-HYGIENE] Unmanaged in OpManager: remove or check - <date>`: assigned to the NMS group, in two sections, "Dead" and "Liveness unknown", each split into "Server, VM and storage", "Network" and "Unknown category".
- [x] Ticket history (Historical tab): the assigned and observer groups and users are exactly those in Setup. No business rule or Behaviors group replaced them (no "Delete a Link" line, no technician's own group added), and the category is the one chosen in Setup (or empty).
- [x] Run it again: no new ticket and no followup (nothing changed, tickets still open).
- [ ] Solve the NMS ticket. The next weekly run creates a new one with the full current list.

## 6. Rollback

Install the 1.2.0 zip and run `plugin:install --force`. The hygiene tables and columns stay. 1.2.0 ignores them.
