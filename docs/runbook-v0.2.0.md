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
- [ ] In OpManager, **disable the "GLPI_Create_Ticket" (Invoke a Webhook) action** in every
      Notification Profile that uses it, or one alarm becomes two tickets. Keep the action
      itself: v0.3.0 points it at the plugin's receiver instead.
- [ ] Move its fixed values into the chosen GLPI ticket template: category (`1`) and assigned
      technician (`5`) as predefined fields. Use user `17` as the plugin's service user if it is
      a dedicated account.

## 2. Install

```bash
cd /var/www/glpi/plugins && sudo rm -rf opmanager && sudo unzip -q /tmp/opmanagerv0.2.0.zip && sudo chown -R nginx: opmanager
sudo -u nginx php /var/www/glpi/bin/console plugin:install opmanager --username=<admin> --force
sudo -u nginx php /var/www/glpi/plugins/opmanager/tools/selfcheck.php
```

- [ ] `DeviceSync` still works after the header-auth change:
      `sudo -u nginx php /var/www/glpi/front/cron.php DeviceSync` → "Last successful device sync" updates.
- [ ] Setup > ICT Tools > OpManager > "Tickets from alarms": service user, template and default
      entity set, **only Critical** ticked, and the **production IP ranges** ticked (e.g.
      "Prodcution IT", "Production OT"). Save; then selfcheck prints `ALL PASS`
      (including `tickets/service-user`, `tickets/production-ranges` and
      `tickets/template-resolves`).
- [ ] Optional: paste the old middleware's Indonesian text into the title/description patterns
      (variables `{device}`, `{ip}`, `{category}`, `{severity}`, `{time}`, `{alarm_key}`, `{message}`).

## 3. First alarm sync

```bash
sudo -u nginx php /var/www/glpi/front/cron.php AlarmSync
sudo tail -n 20 /var/www/glpi/files/_log/opmanager.log
```

- [ ] Automatic action log shows `fetched N, created …`. N should be about the number of active
      alarms in OpManager (about 40 on 2026-09-30).
- [ ] OpManager > OpManager alarms lists them; Critical alarms **on production IPs** have a ticket
      number, and a Critical alarm outside the production ranges has none.
- [ ] Open one created ticket:
  - title/description come from the patterns;
  - the entity is the asset's entity;
  - the asset is linked;
  - the service user is the requester;
  - the template's predefined fields are applied.
- [ ] Run AlarmSync again immediately: **no new tickets, no new followups** (log shows
      `created 0`, `followups 0`).
- [ ] If any alarm shows "pending": `files/_log/opmanager.log` names the template's mandatory
      field(s) the plugin could not fill (e.g. `locations_id`). Write them down, and do not tick
      the previous box; predefine them in the template or pick another template.
- [ ] A live-value alarm (e.g. CPU %) produces **at most one** message followup per hour; a
      severity change still produces one immediately.

## 3b. Ticket category through GLPI business rules (v0.2.1)

The Behaviors plugin requires a category before a ticket can be Solved/Closed. Tickets get
one from GLPI's own rules, which run on every ticket the plugin creates
(`Ticket::prepareInputForAdd` → `processRules(ONADD)`).

- [ ] Setup > ICT Tools > OpManager: the ticket description contains the line
      `Category: {category}`. That is the default since v0.2.1; if the description was
      customised, add the line by hand.
- [ ] Administration > Rules > **Business rules for tickets**: one rule per OpManager
      category, condition "On add", criterion **Description contains** `Category: Server`,
      action **Category** = the ITIL category for servers. Repeat for `Category: Switch`,
      `Category: Firewall`, `Category: Storage`, `Category: Wireless`, and so on. The
      categories that exist are in the OpManager devices list, column Category. The same
      rule can also assign the technician group if Behaviors requires one.
- [ ] Keep one category **predefined in the ticket template** as a fallback for categories
      without a rule. A matching rule overrides it.
- [ ] Run AlarmSync, open a new alarm ticket: its category is the mapped one, and a
      technician can Solve it without the "Category is mandatory" error.
- [ ] Tickets created before the rules existed have no category. Set it by hand, or with
      a massive action on the ticket list, before they are closed.

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
