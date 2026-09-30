<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * Turns an OpManager device-list response into plain rows. Format verified on
 * production 2026-09-30 (933 devices, spec §14).
 */
final class DeviceParser
{
    /** @return list<array{device_key: string, name: string, display_name: string, ip_address: string, device_type: string, device_category: string, availability: string}> */
    public static function parse(string $json): array
    {
        $data = ResponseGuard::unwrapList(ResponseGuard::decode($json, 'device list'), 'device list');

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = ResponseGuard::first($row, ['deviceName', 'name', 'moid']);
            if ($key === '') {
                continue;
            }
            $out[] = [
                'device_key'      => $key,
                'name'            => ResponseGuard::first($row, ['deviceName', 'name']) ?: $key,
                'display_name'    => ResponseGuard::first($row, ['displayName', 'deviceName', 'name']),
                'ip_address'      => ResponseGuard::first($row, ['ipaddress', 'ipAddress', 'ip']),
                'device_type'     => ResponseGuard::first($row, ['type', 'deviceType']),
                'device_category' => ResponseGuard::first($row, ['category']),
                'availability'    => ResponseGuard::first($row, ['statusStr', 'status']),
            ];
        }
        return $out;
    }
}
