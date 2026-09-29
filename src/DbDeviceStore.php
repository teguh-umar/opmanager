<?php

namespace GlpiPlugin\Opmanager;

final class DbDeviceStore implements DeviceStore
{
    public function upsert(array $device, string $now): array
    {
        global $DB;

        $table  = Device::getTable();
        $fields = [
            'name'            => $device['name'],
            'display_name'    => $device['display_name'],
            'ip_address'      => $device['ip_address'],
            'device_type'     => $device['device_type'],
            'device_category' => $device['device_category'],
            'availability'    => $device['availability'],
            'last_seen'       => $now,
            'date_mod'        => $now,
        ];

        $row = $DB->request([
            'SELECT' => ['id', 'is_manual'],
            'FROM'   => $table,
            'WHERE'  => ['device_key' => $device['device_key']],
            'LIMIT'  => 1,
        ])->current();

        if ($row) {
            $DB->update($table, $fields, ['id' => (int) $row['id']]);
            return ['id' => (int) $row['id'], 'is_manual' => (bool) $row['is_manual']];
        }

        $DB->insert($table, $fields + ['device_key' => $device['device_key'], 'date_creation' => $now]);
        return ['id' => (int) $DB->insertId(), 'is_manual' => false];
    }

    public function setMatch(int $id, array $result): void
    {
        global $DB;

        // is_manual = 0 in the WHERE as a second guard: a pin set between the
        // upsert and this call must still win.
        $DB->update(Device::getTable(), [
            'match_state'  => $result['state'],
            'match_source' => $result['source'],
            'itemtype'     => $result['itemtype'],
            'items_id'     => $result['items_id'],
        ], ['id' => $id, 'is_manual' => 0]);
    }
}
