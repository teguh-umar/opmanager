<?php

namespace GlpiPlugin\Opmanager;

use GlpiPlugin\Opmanager\Matching\DbAssetLookup;

/** Runtime checks against the real GLPI + DB. Run on the VM via tools/selfcheck.php. */
final class SelfCheck
{
    /** @return array<string, callable(): void> */
    public static function checks(): array
    {
        return [
            'schema/devices-table' => static function (): void {
                global $DB;
                self::assert($DB->tableExists(Device::getTable()), 'glpi_plugin_opmanager_devices missing');
            },
            'rights/registered' => static function (): void {
                $all = array_keys(\ProfileRight::getAllPossibleRights());
                self::assert(array_diff(RightDefinition::names(), $all) === [], 'plugin rights not registered');
            },
            'rights/super-admin-full' => static function (): void {
                global $DB;
                foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $row) {
                    if (((int) $row['rights'] & UPDATE) !== UPDATE) {
                        continue;
                    }
                    $mine = \ProfileRight::getProfileRights((int) $row['profiles_id'], RightDefinition::names());
                    foreach (RightDefinition::ALL as $name => $mask) {
                        self::assert((int) ($mine[$name] ?? 0) === $mask, "profile {$row['profiles_id']} lacks full {$name}");
                    }
                }
            },
            'cron/device-sync-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'DeviceSync'), 'DeviceSync cron not registered');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'DeviceSync is not in CLI mode');
            },
            'schema/alarm-tables' => static function (): void {
                global $DB;
                self::assert($DB->tableExists('glpi_plugin_opmanager_alarms') && $DB->tableExists('glpi_plugin_opmanager_storms'), 'alarm/storm tables missing');
            },
            'cron/alarm-sync-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'AlarmSync'), 'AlarmSync cron not registered');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'AlarmSync is not in CLI mode');
            },
            'tickets/service-user' => static function (): void {
                $uid  = (int) Config::get('service_user');
                $user = new \User();
                self::assert($uid > 0 && $user->getFromDB($uid) && !empty($user->fields['is_active']), 'Setup > OpManager: choose an active GLPI service user for alarm tickets');
            },
            'tickets/production-ranges' => static function (): void {
                $ids = Config::ticketingConfig()['production_addressings'];
                self::assert(\GlpiPlugin\Opmanager\Ticketing\AddressingRanges::load($ids) !== [], 'Setup > OpManager: select at least one valid production IP range, otherwise no ticket is created');
            },
            'webhook/stateless' => static function (): void {
                $request = \Symfony\Component\HttpFoundation\Request::create(Config::WEBHOOK_PATH, 'POST');
                self::assert((new \Glpi\Http\SessionManager())->isResourceStateless($request), 'front/webhook.php is not registered as stateless (plugin_opmanager_boot)');
            },
            'webhook/token' => static function (): void {
                self::assert(Config::webhookToken() !== '', 'Setup > OpManager: generate a webhook token (webhook disabled until then)');
                self::assert(Config::get('webhook_token') !== Config::webhookToken(), 'webhook_token is stored in clear text');
            },
            'webhook/lock' => static function (): void {
                $lock = new \GlpiPlugin\Opmanager\Ticketing\DbSyncLock();
                self::assert($lock->acquire(1), 'GET_LOCK opmanager_alarm_sync not obtainable');
                $lock->release();
            },
            'outbound/table' => static function (): void {
                global $DB;
                self::assert($DB->tableExists('glpi_plugin_opmanager_outbox'), 'outbox table missing - re-run plugin:install');
            },
            'outbound/cron' => static function (): void {
                self::assert((new \CronTask())->getFromDBbyName(Cron::class, 'OutboxSend'), 'OutboxSend cron not registered - re-run plugin:install');
            },
            'tickets/template-resolves' => static function (): void {
                $cfg = Config::ticketingConfig();
                if ($cfg['template_id'] > 0) {
                    self::assert((new \TicketTemplate())->getFromDBWithData($cfg['template_id']), "ticket template {$cfg['template_id']} not found");
                } else {
                    $tt = (new \Ticket())->getITILTemplateToUse(0, \Ticket::INCIDENT_TYPE, 0, $cfg['entity_default']);
                    self::assert($tt instanceof \TicketTemplate, 'no default ticket template resolved');
                }
            },
            'config/api-key-secured' => static function (): void {
                self::assert((new \GLPIKey())->isConfigSecured(Config::CONTEXT, 'opm_api_key'), 'opm_api_key is not a secured config');
                $raw = Config::get('opm_api_key');
                if ($raw !== '') {
                    self::assert($raw !== Config::apiKey(), 'opm_api_key is stored in clear text');
                }
            },
            'suite/lib-shas' => static function (): void {
                $expected = [
                    'ConfigTab.php' => 'F94A80C1C813A951919A20D2521DE35B431F9C836BBCCE89BAC70E544D0B80FA',
                    'AssetTab.php'  => 'F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466',
                    'Menu.php'      => 'FC0B96219DD489639DEECD720197BA6B9810F6303A28CE7DB891139E7913CBFE',
                ];
                foreach ($expected as $file => $sha) {
                    $actual = strtoupper((string) hash_file('sha256', dirname(__DIR__) . '/lib/pgnsuite/' . $file));
                    self::assert($actual === $sha, "lib/pgnsuite/{$file} is not canonical ({$actual})");
                }
            },
            'matching/itemtypes-loadable' => static function (): void {
                $missing = (new DbAssetLookup(Config::assetItemtypes()))->missingItemtypes();
                self::assert($missing === [], 'configured asset types not loadable here: ' . implode(', ', $missing));
            },
            'hygiene/schema' => static function (): void {
                global $DB;
                foreach (['glpi_plugin_opmanager_deviceexceptions', 'glpi_plugin_opmanager_hygienetickets', 'glpi_plugin_opmanager_hygieneitems'] as $t) {
                    self::assert($DB->tableExists($t), "{$t} missing - re-run plugin:install");
                }
                foreach (['liveness_state', 'liveness_source', 'liveness_detail', 'liveness_checked_at', 'hygiene_state'] as $col) {
                    self::assert($DB->fieldExists(Device::getTable(), $col), "devices.{$col} missing - re-run plugin:install");
                }
            },
            'hygiene/cron-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'HygieneCheck'), 'HygieneCheck cron not registered - re-run plugin:install');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'HygieneCheck is not in CLI mode');
            },
            'hygiene/groups' => static function (): void {
                $cfg = Config::hygieneConfig();
                if (!$cfg['enabled']) {
                    return;
                }
                foreach (array_merge($cfg['glpi_groups'], [$cfg['nms_group']]) as $gid) {
                    self::assert((new \Group())->getFromDB($gid), "hygiene group {$gid} not found");
                }
            },
            'matching/lookup-smoke' => static function (): void {
                $lookup = new DbAssetLookup(Config::assetItemtypes());
                self::assert($lookup->byName('__opmanager_selfcheck_no_such_asset__') === [], 'name lookup returned rows for a nonsense name');
                self::assert($lookup->byIp('192.0.2.254') === [], 'IP lookup returned rows for TEST-NET-1');
            },
        ];
    }

    private static function assert(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }
}
