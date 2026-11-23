---
date: 2026-10-02
tags: [opmanager, runbook]
status: active
---

# opmanager v0.5.0 — VM verification runbook

## 1. Install

```bash
cd /var/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.5.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /var/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /var/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] Selfcheck prints `ALL PASS (18 passed, 0 failed)`.

## 2. Acknowledge on a public followup

Use an alarm ticket whose alarm is active and **Unacknowledged** in OpManager.

- [ ] As a technician, add a **public** followup → within a minute the OpManager alarm has the
      note `GLPI tiket #N - followup oleh <you>: ...` and is **Acknowledged** (the thumbs-up
      count goes up, and `who` is the API key owner).
- [ ] Add a second public followup → a second note appears; the alarm stays Acknowledged.
- [ ] Add a **private** followup → nothing changes in OpManager.
- [ ] The outbox shows `note`, `ack` (and the second `note`, `ack`) as `sent`:

```sql
SELECT id, tickets_id, alarm_key, action, state, attempts, LEFT(last_error,80) AS err
FROM glpi_plugin_opmanager_outbox ORDER BY id DESC LIMIT 6;
```
