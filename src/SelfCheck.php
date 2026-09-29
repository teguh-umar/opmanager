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
            'config/api-key-secured' => static function (): void {
                self::assert((new \GLPIKey())->isConfigSecured(Config::CONTEXT, 'opm_api_key'), 'opm_api_key is not a secured config');
                $raw = Config::get('opm_api_key');
                if ($raw !== '') {
                    self::assert($raw !== Config::apiKey(), 'opm_api_key is stored in clear text');
                }
            },
            'suite/lib-shas' => static function (): void {
                $expected = [
                    'ConfigTab.php' => '008C4C817DB2E42040915136108CD41917C34760A8F4CDDC60E46BDB208F39D0',
                    'AssetTab.php'  => 'C7F6C88B58BE9A7AAF172F3A2F2DBAAFBB07B0CE1898BA5A74A0EDB61EA143A9',
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
