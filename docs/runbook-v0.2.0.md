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
