---
date: 2026-10-01
tags: [opmanager, runbook]
status: active
---

# opmanager v0.3.0 — VM verification runbook

## 1. Install

```bash
cd /data/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.3.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /data/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
```

- [ ] Setup > PGN Suite > OpManager > "Webhook from OpManager": click **Generate new token**,
      and copy the token from the message (it is shown once).
- [ ] `sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php` prints `ALL PASS`
      (including `webhook/stateless`, `webhook/token`, `webhook/lock`).

## 2. Endpoint checks from the VM

```bash
URL=https://glpi.example.com/plugins/opmanager/front/webhook.php
read -rs TOKEN; echo
curl -s -o /dev/null -w "%{http_code}\n" -X POST "$URL" -H 'Content-Type: application/json' -d '{"alarm_id":"1","entity":"1"}'                        # expect 401
curl -s -o /dev/null -w "%{http_code}\n" -X POST "$URL" -H "X-OPM-Token: wrong" -d '{"alarm_id":"1","entity":"1"}'                                 # expect 401
curl -s -w "\n%{http_code}\n" -X POST "$URL" -H "X-OPM-Token: $TOKEN" -d '{"alarm_id":"1","entity":"1"}'                                          # expect 202 (no such alarm)
curl -s -w "\n%{http_code}\n" -X POST "$URL" -H "X-OPM-Token: $TOKEN" -d 'not json'                                                                # expect 400
unset TOKEN
```

- [ ] All four codes match. If the 202 case answers 302/login HTML instead, the stateless
      path is not active: report the selfcheck output.

## 3. OpManager action

- [ ] Edit the disabled "GLPI_Create_Ticket" action (Invoke a Webhook):
  - URL = the plugin URL above, method POST, raw JSON;
  - headers: `Content-Type: application/json` and `X-OPM-Token: <token>` (remove App-Token / User-Token);
  - body: `{"alarm_id":"$alarmid","entity":"$entity"}`.
- [ ] Use it in a Notification Profile for **Critical and Clear** on the devices that matter.
      The plugin still filters on the production ranges.
- [ ] Re-enable the profile. Use "Test" in OpManager, or wait for a real alarm; the plugin log shows
      no `Webhook: HTTP 4xx` lines.

## 4. Real event

- [ ] A new Critical alarm on a production IP gets its ticket **within a minute** (not up to 10).
- [ ] When it clears, the ticket is Solved within a minute (if all its alarms are Clear).
- [ ] Still no duplicate ticket (cron + webhook): one ticket per alarm id.
- [ ] If no alarm happened during the test window, write "case untested in production".
