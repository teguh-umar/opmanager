# OpManager for GLPI (`opmanager`)

> **Deprecated: this plugin will be archived.** It is replaced by
> **NMS Integration** (`nms`), which starts from opmanager 1.3.2 and behaves
> the same for OpManager, and which can drive other NMS brands in later
> releases. opmanager gets no new features. Fixes are made only until the
> production cutover to `nms` is done. After that, this repository is archived
> (read-only). See [Moving to NMS Integration](#moving-to-nms-integration).

A GLPI 11 plugin that connects ManageEngine OpManager to GLPI:

- **Inventory sync.** OpManager devices are listed in GLPI and matched to GLPI
  assets.
- **Monitoring on the asset.** Each matched asset shows its OpManager status,
  availability and active alarms.
- **Tickets from alarms.** These come from the OpManager webhook or the alarm
  sync, and an alarm filter (event types, message keywords) decides which alarms
  open a ticket. Recurrences are linked to the existing ticket, and alarm storms
  are grouped.
- **Write-back to OpManager.** A technician's public followup, Solve or Close
  sends a note to each active alarm of the ticket, and a clear on Solve/Close.
  Followups also acknowledge the alarm. Actions taken by the plugin itself, cron
  jobs and GLPI automation are never sent back.
- **Coverage page.** OpManager devices are compared with GLPI assets, with KPI
  cards, follow-up lists and CSV export. The plugin also adds native GLPI
  dashboard cards.
- **NMS hygiene.** Weekly Request tickets list devices that are monitored but not
  in GLPI, or unmanaged in OpManager. Per-device exceptions with a reason are
  supported.
- **Manual page** (`docs/manual.md`), linked from the plugin pages and Setup.

## Requirements

- GLPI 11.0.x (`11.0.0` to `11.99.99`).
- PHP with `ext-curl`. The OpManager client uses curl, not Guzzle.
- An OpManager API key with read access. Write-back (notes, acknowledge, clear)
  also needs write access.

## Rights (Administration > Profiles > OpManager)

| Right | READ | UPDATE |
|---|---|---|
| Setup (`plugin_opmanager_config`) | view Setup | change Setup |
| Monitoring (`plugin_opmanager_monitoring`) | devices, alarms, coverage, asset tab | — |
| NMS hygiene (`plugin_opmanager_hygiene`) | hygiene view | per-device exceptions |

## Automatic actions

| Cron | Purpose |
|---|---|
| `DeviceSync` | pulls OpManager devices and matches them to GLPI assets |
| `AlarmSync` | pulls alarms and opens or updates tickets (fallback for the webhook) |
| `OutboxSend` | sends queued notes, acknowledges and clears to OpManager, with retries |
| `HygieneCheck` | builds the weekly NMS hygiene tickets |

The webhook receiver is `<glpi>/plugins/opmanager/front/webhook.php`. It needs
the header `X-OPM-Token`.

## Install

```sh
cd <glpi>/plugins
unzip opmanagerv<version>.zip
php <glpi>/bin/console glpi:plugin:install opmanager
php <glpi>/bin/console glpi:plugin:activate opmanager
php <glpi>/bin/console glpi:cache:clear
```

Then configure the plugin in Setup > ICT Tools > OpManager, and run
`tools/selfcheck.php`.

**New installs should use NMS Integration instead.**

## Moving to NMS Integration

`nms` installs next to opmanager and copies everything over. opmanager's tables
and settings are never changed, so a rollback is possible until opmanager is
uninstalled.

1. **Install `nms`.** Its installer creates a **disabled** connection
   "OpManager (migrated)". The connection receives a copy of opmanager's
   settings, devices, alarms with their tickets, outbox, storms, ticket requests,
   hygiene exceptions, hygiene tickets and profile rights. The copy is idempotent.
2. **Check the copy.** The row counts in `glpi_plugin_opmanager_*` and
   `glpi_plugin_nms_*` must match. The connection's settings and the Profile
   rights must match too.
3. **Cut over.** Do these steps in this order:
   1. **Deactivate opmanager.** Deactivate the plugin itself, not only its crons.
      Its ticket hooks would otherwise send every note to OpManager twice.
   2. **Copy again** from nms Setup ("Copy from opmanager again"). This brings
      over what opmanager wrote in the meantime.
   3. **Change the webhook URL in OpManager.** In the webhook notification
      profile, change `/plugins/opmanager/front/webhook.php` to
      `/plugins/nms/front/webhook.php`. The header and token stay the same.
   4. **Activate the nms connection.**
4. Keep opmanager **deactivated, not uninstalled**, until nms has run cleanly for
   a while. Uninstalling opmanager drops its tables.

The step-by-step runbook ships with nms as `docs/runbook-1.0.0.md`.

## Support status

| Version | Status |
|---|---|
| 1.3.x | last line. Fixes only until the cutover to nms, then archived |
| < 1.3 | unsupported |

## Data it stores

`glpi_plugin_opmanager_alarms`, `glpi_plugin_opmanager_deviceexceptions`, `glpi_plugin_opmanager_devices`, `glpi_plugin_opmanager_hygieneitems`, `glpi_plugin_opmanager_hygienetickets`, `glpi_plugin_opmanager_outbox`, `glpi_plugin_opmanager_storms`, `glpi_plugin_opmanager_ticketrequests`.

Uninstall removes these tables, with the plugin's rights, automatic actions and settings.

## Security

- Every form uses GLPI's CSRF token, and every page checks the plugin's rights and the user's entities.
- Secrets such as API keys are stored with GLPI's secured configuration, sent once and never shown again.
- Calls to outside services run only from automatic actions or an explicit button, with a timeout.
- Report a vulnerability privately as described in [SECURITY.md](SECURITY.md).

## Development

- Offline tests: `php tests/run_all.php`
- Release check: `python tools/release_check.py`
- Rules for contributors: [CHARTER.md](CHARTER.md) and [AGENTS.md](AGENTS.md).
- Changes: [CHANGELOG.md](CHANGELOG.md).

## Licence

GPL-3.0-or-later, author Teguh DHAMAR. See [LICENSE](LICENSE) and [NOTICE](NOTICE). To cite this plugin, see
[CITATION.cff](CITATION.cff).
