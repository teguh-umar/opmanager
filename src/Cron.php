<?php

namespace GlpiPlugin\Opmanager;

use GlpiPlugin\Opmanager\Matching\DbAssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\AddressingRanges;
use GlpiPlugin\Opmanager\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Opmanager\Ticketing\AlarmSync;
use GlpiPlugin\Opmanager\Ticketing\DbAlarmStore;
use GlpiPlugin\Opmanager\Ticketing\DbDeviceIndex;
use GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway;
use GlpiPlugin\Opmanager\Ticketing\RangeProductionCheck;

final class Cron extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'OpManager';
    }

    public static function cronInfo($name)
    {
        return match ($name) {
            'DeviceSync' => ['description' => __('Sync OpManager devices and match them to GLPI assets', 'opmanager')],
            'AlarmSync'  => ['description' => __('Pull active OpManager alarms and create/update tickets', 'opmanager')],
            default      => [],
        };
    }

    public static function cronDeviceSync(\CronTask $task): int
    {
        $now     = (string) \Session::getCurrentTime();
        $lookup  = new DbAssetLookup(Config::assetItemtypes());
        $missing = $lookup->missingItemtypes();

        // Review Focus 5: an asset type we cannot load here must not silently turn
        // its devices into "not found". Refresh device data, keep earlier pairings,
        // and keep the problem visible in Setup (never cleared below).
        $status = DeviceSync::statusMessage($missing);
        if ($status !== '') {
            $task->log($status);
            \Toolbox::logInFile('opmanager', 'DeviceSync: ' . $status . "\n");
        }

        try {
            $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
            $stats  = DeviceSync::run($client, new DbDeviceStore(), new Matcher($lookup), Config::get('match_mode'), $now, $missing === []);
        } catch (ClientException $e) {
            Config::set(['last_device_sync_error' => $e->getMessage()]);
            \Toolbox::logInFile('opmanager', 'DeviceSync: ' . $e->getMessage() . "\n");
            $task->log($e->getMessage());
            return 0;
        }

        Config::set(['last_device_sync' => $now, 'last_device_sync_error' => $status]);
        $task->addVolume($stats['fetched']);
        $task->log(sprintf(
            'fetched %d, matched %d, needs review %d, not found %d, pinned %d, matching skipped %d',
            $stats['fetched'],
            $stats['matched'],
            $stats['review'],
            $stats['unmatched'],
            $stats['manual'],
            $stats['skipped']
        ));
        return 1;
    }

    public static function cronAlarmSync(\CronTask $task): int
    {
        $now = (string) \Session::getCurrentTime();
        $cfg = Config::ticketingConfig();

        if ($cfg['service_user'] <= 0) {
            $msg = 'no service user configured in Setup; alarm tickets would have no author';
            Config::set(['last_alarm_sync_error' => $msg]);
            $task->log($msg);
            return 0;
        }

        $ranges = AddressingRanges::load($cfg['production_addressings']);

        try {
            $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
            $sync   = new AlarmSync(
                $client,
                new DbAlarmStore(),
                new GlpiTicketGateway($cfg),
                new AlarmAssetResolver(new DbDeviceIndex(), new Matcher(new DbAssetLookup(Config::assetItemtypes())), Config::get('match_mode')),
                new RangeProductionCheck($ranges, [AddressingRanges::class, 'assetIps']),
                $cfg
            );
            $stats = $sync->run($now);
        } catch (ClientException $e) {
            Config::set(['last_alarm_sync_error' => $e->getMessage()]);
            \Toolbox::logInFile('opmanager', 'AlarmSync: ' . $e->getMessage() . "\n");
            $task->log($e->getMessage());
            return 0;
        }

        // Keep every problem of this run visible in Setup (hub §4: a run that half
        // failed must not look successful).
        $problems = [];
        if ($ranges === [] && $cfg['ticket_severities'] !== []) {
            $problems[] = 'no production IP range selected in Setup: alarms are stored but no ticket is created';
        }
        if ($stats['pending'] > 0) {
            $problems[] = sprintf('%d alarm ticket(s) could not be created (see files/_log/opmanager.log); retried next run', $stats['pending']);
        }
        foreach ($sync->lastErrors() as $error) {
            \Toolbox::logInFile('opmanager', 'AlarmSync: ' . $error . "\n");
        }
        if ($stats['errors'] > 0) {
            $problems[] = sprintf('%d alarm(s) failed: %s', $stats['errors'], implode('; ', array_slice($sync->lastErrors(), 0, 3)));
        }
        Config::set(['last_alarm_sync' => $now, 'last_alarm_sync_error' => substr(implode(' | ', $problems), 0, 1000)]);
        $task->addVolume($stats['fetched']);
        $task->log(implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($stats), $stats)));
        return 1;
    }
}
