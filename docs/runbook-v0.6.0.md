---
date: 2026-10-02
tags: [opmanager, runbook]
status: active
---

# opmanager v0.6.0 — VM verification runbook

## 1. Install

```bash
cd /data/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.6.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /data/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] Selfcheck prints `ALL PASS (18 passed, 0 failed)`.

## 2. Setup (Setup > General > PGN Suite > OpManager)

- [ ] The status strip shows five badges: API key, Device sync, Alarm sync, Webhook, To OpManager. Their colours match reality (green = fine).
- [ ] The tabs Connection, Matching, Tickets, Alarm filter, Webhook and To OpManager all open. The existing values are still there.
- [ ] Change one value on one tab and Save. The value is kept, and the page reopens on the same tab.
- [ ] Matching tab: in "Coverage page: GLPI statuses counted as in use", pick the status(es) meaning "in use". Save.
- [ ] A profile with the config right READ but not UPDATE sees the tabs and no Save button.

## 3. Coverage page (OpManager > Coverage)

- [ ] The KPI cards are plausible. "OpManager devices" ≈ the device list count (933 at last check); "matched" ≈ the matched count.
- [ ] Each of the five tabs lists rows, or "Nothing to follow up". The links open the GLPI asset or OpManager device.
- [ ] Ticking "Production IP ranges only" and pressing Apply narrows the numbers.
- [ ] "Export CSV" downloads a file that opens in Excel with the right columns, covering the whole list, not just one page.
- [ ] A user restricted to one entity sees only that entity's assets in "Not monitored".

## 4. GLPI dashboard cards

- [ ] Home > Dashboard > Edit > Add widget: a group **OpManager** offers 8 cards.
- [ ] Add "OpManager coverage of GLPI assets (%)" and "OpManager device matching". They show the same figures as the Coverage page.
- [ ] Clicking a big number opens the matching Coverage tab.
- [ ] A user without the OpManager monitoring right does not see the OpManager group in Add widget. If such a user views a dashboard that already holds the cards, the cards show 0 or empty.
- [ ] After a device sync the cards may need up to 5 minutes to change (cache).
