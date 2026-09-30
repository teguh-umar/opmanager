<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use GlpiPlugin\Opmanager\Matching\Matcher;

/**
 * An alarm's deviceName is not always in the device list (a VM under a VMware
 * host, spec §14). The paired device wins; otherwise the alarm's own display name
 * and IP go through the same Matcher rules as devices.
 */
final class AlarmAssetResolver implements AssetResolver
{
    public function __construct(private DeviceIndex $devices, private Matcher $matcher, private string $mode)
    {
    }

    public function resolve(array $alarm): array
    {
        $device = $this->devices->find((string) ($alarm['device_name'] ?? ''));
        if ($device !== null && $device['match_state'] === Matcher::MATCHED) {
            return ['devices_id' => $device['id'], 'itemtype' => $device['itemtype'], 'items_id' => $device['items_id']];
        }
        $match = $this->matcher->match((string) ($alarm['display_name'] ?? ''), (string) ($alarm['ip_address'] ?? ''), $this->mode);
        $ok    = $match['state'] === Matcher::MATCHED;
        return [
            'devices_id' => $device['id'] ?? 0,
            'itemtype'   => $ok ? $match['itemtype'] : '',
            'items_id'   => $ok ? $match['items_id'] : 0,
        ];
    }
}
