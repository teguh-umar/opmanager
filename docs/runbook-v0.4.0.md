---
date: 2026-10-01
tags: [opmanager, runbook]
status: active
---

# opmanager v0.4.0 — VM verification runbook

v0.4.0 includes everything in 0.3.0 (webhook, alarm filter) and 0.2.2 (curl client). If
0.3.0 is not deployed yet, also follow `runbook-v0.3.0.md` §1b–§4 after the install below.

## 1. Install

```bash
cd /data/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.4.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /data/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] Selfcheck prints `ALL PASS`, including `outbound/table` and `outbound/cron`.
- [ ] Setup > Automatic actions: `OutboxSend` exists in CLI mode and runs every minute.
- [ ] Setup > PGN Suite > OpManager > Tickets from alarms: "Sent to OpManager" shows
      `Waiting: 0, given up: 0`.
- [ ] An alarm page (OpManager > Alarms > any alarm) has a title like
      `CORE-SW-02 - 10000004729` (no longer "N/A") and an **OpManager entity** row.

## 2. Real flow (on one alarm ticket with an active alarm)

Use a ticket whose alarm is still active in OpManager, preferably on a test device.

- [ ] Add a **public** followup → within a minute the alarm in OpManager shows the note
      `GLPI tiket #N - followup oleh <you>: ...`.
- [ ] Add a **private** followup → nothing appears in OpManager.
- [ ] **Solve** the ticket with a solution → note `GLPI tiket #N di-Solve oleh <you>: <solution>`,
      then the alarm becomes **Clear** in OpManager.
- [ ] On another ticket, **Close** without typing a solution → note
      `Tiket #N ditutup oleh <you> di GLPI`, then Clear.
- [ ] An alarm that clears by itself in OpManager → the plugin solves the ticket, and **no** new
      note appears in OpManager (the plugin never echoes its own actions).
- [ ] Setup still shows `given up: 0`.

clearAlarm was verified on production on 2026-10-01 (POST, `ackMessage` answer). If it is
refused later, Setup shows the reason under "Last OpManager write error".

## 3. Failure behaviour

- [ ] If OpManager is unreachable when a technician saves, the save still succeeds. Setup shows
      `Waiting: n`, and `opmanager.log` has `Outbound: ... (will retry)`.
- [ ] Once OpManager is reachable again, the OutboxSend cron sends the rows (`Waiting: 0`).
- [ ] If no failure could be tested, write "case untested in production".
