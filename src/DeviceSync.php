<?php

namespace GlpiPlugin\Opmanager;

use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\DeviceSource;

/**
 * One device-sync pass. Fetches the whole list BEFORE writing anything, so a
 * failed or garbled response never half-updates the table. Devices missing from
 * OpManager are never deleted - their last_seen simply ages.
 */
final class DeviceSync
{
    /** @return array{fetched: int, matched: int, review: int, unmatched: int, manual: int} */
    public static function run(DeviceSource $source, DeviceStore $store, Matcher $matcher, string $mode, string $now): array
    {
        $devices = $source->listDevices();

        $stats = ['fetched' => 0, 'matched' => 0, 'review' => 0, 'unmatched' => 0, 'manual' => 0];
        foreach ($devices as $device) {
            $stats['fetched']++;
            $saved = $store->upsert($device, $now);
            if ($saved['is_manual']) {
                $stats['manual']++;
                continue;
            }
            $name   = $device['display_name'] !== '' ? $device['display_name'] : $device['name'];
            $result = $matcher->match($name, $device['ip_address'], $mode);
            $store->setMatch($saved['id'], $result);
            $stats[$result['state']]++;
        }
        return $stats;
    }
}
