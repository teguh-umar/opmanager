<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use GlpiPlugin\Opmanager\Device;

final class DbDeviceIndex implements DeviceIndex
{
    public function find(string $device_key): ?array
    {
        global $DB;

        if ($device_key === '') {
            return null;
        }
        $row = $DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id', 'match_state'],
            'FROM'   => Device::getTable(),
            'WHERE'  => ['device_key' => $device_key],
            'LIMIT'  => 1,
        ])->current();

        return $row ? [
            'id'          => (int) $row['id'],
            'itemtype'    => (string) $row['itemtype'],
            'items_id'    => (int) $row['items_id'],
            'match_state' => (string) $row['match_state'],
        ] : null;
    }
}
