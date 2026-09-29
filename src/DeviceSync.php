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
    /**
     * @param bool $can_match false when a configured asset type could not be loaded
     *                        in this process: device data is still refreshed, but no
     *                        pairing is rewritten, so earlier matches to that type
     *                        are kept instead of being downgraded to "not found".
     * @return array{fetched: int, matched: int, review: int, unmatched: int, manual: int, skipped: int}
     */
    public static function run(DeviceSource $source, DeviceStore $store, Matcher $matcher, string $mode, string $now, bool $can_match = true): array
    {
        $devices = $source->listDevices();

        $stats = ['fetched' => 0, 'matched' => 0, 'review' => 0, 'unmatched' => 0, 'manual' => 0, 'skipped' => 0];
        foreach ($devices as $device) {
            $stats['fetched']++;
            $saved = $store->upsert($device, $now);
            if ($saved['is_manual']) {
                $stats['manual']++;
                continue;
            }
            if (!$can_match) {
                $stats['skipped']++;
                continue;
            }
            $name   = $device['display_name'] !== '' ? $device['display_name'] : $device['name'];
            $result = $matcher->match($name, $device['ip_address'], $mode);
            $store->setMatch($saved['id'], $result);
            $stats[$result['state']]++;
        }
        return $stats;
    }

    /**
     * The sync error to show in Setup; '' when nothing is wrong.
     *
     * @param string[] $missing configured asset itemtypes that could not be loaded
     */
    public static function statusMessage(array $missing): string
    {
        if ($missing === []) {
            return '';
        }
        return sprintf(
            'asset type(s) %s are configured but cannot be loaded in this process; device data was refreshed but matching was skipped and earlier pairings were kept',
            implode(', ', $missing)
        );
    }
}
