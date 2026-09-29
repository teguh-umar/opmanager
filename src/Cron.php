<?php

namespace GlpiPlugin\Opmanager;

use GlpiPlugin\Opmanager\Matching\DbAssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\OpManager\ClientException;

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
            default      => [],
        };
    }

    public static function cronDeviceSync(\CronTask $task): int
    {
        $now    = \Session::getCurrentTime();
        $lookup = new DbAssetLookup(Config::assetItemtypes());

        // Review Focus 5: an asset type we cannot load here would silently leave
        // its devices unmatched - say so in the task log and the plugin log.
        foreach ($lookup->missingItemtypes() as $itemtype) {
            $msg = sprintf('asset type %s is configured but cannot be loaded in this process; its devices will stay unmatched', $itemtype);
            $task->log($msg);
            \Toolbox::logInFile('opmanager', 'DeviceSync: ' . $msg . "\n");
        }

        try {
            $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
            $stats  = DeviceSync::run($client, new DbDeviceStore(), new Matcher($lookup), Config::get('match_mode'), $now);
        } catch (ClientException $e) {
            Config::set(['last_device_sync_error' => $e->getMessage()]);
            \Toolbox::logInFile('opmanager', 'DeviceSync: ' . $e->getMessage() . "\n");
            $task->log($e->getMessage());
            return 0;
        }

        Config::set(['last_device_sync' => $now, 'last_device_sync_error' => '']);
        $task->addVolume($stats['fetched']);
        $task->log(sprintf(
            'fetched %d, matched %d, needs review %d, not found %d, pinned %d',
            $stats['fetched'],
            $stats['matched'],
            $stats['review'],
            $stats['unmatched'],
            $stats['manual']
        ));
        return 1;
    }
}
