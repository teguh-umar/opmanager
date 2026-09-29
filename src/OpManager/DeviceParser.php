<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * Turns an OpManager device-list response into plain rows. Field names are
 * tolerant on purpose: the exact response of the PGN OpManager build is still
 * to be verified (spec §13 item 1) - the runbook checks it against a real call.
 */
final class DeviceParser
{
    private const WRAPPERS = ['rows', 'data', 'devices', 'result'];

    /** @return list<array{device_key: string, name: string, display_name: string, ip_address: string, device_type: string, device_category: string, availability: string}> */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ClientException('OpManager device list is not valid JSON');
        }
        foreach (self::WRAPPERS as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                $data = $data[$wrapper];
                break;
            }
        }
        if (!array_is_list($data)) {
            throw new ClientException('OpManager device list has an unexpected shape');
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::first($row, ['deviceName', 'name', 'moid']);
            if ($key === '') {
                continue;
            }
            $out[] = [
                'device_key'      => $key,
                'name'            => self::first($row, ['deviceName', 'name']) ?: $key,
                'display_name'    => self::first($row, ['displayName', 'deviceName', 'name']),
                'ip_address'      => self::first($row, ['ipaddress', 'ipAddress', 'ip']),
                'device_type'     => self::first($row, ['type', 'deviceType']),
                'device_category' => self::first($row, ['category']),
                'availability'    => self::first($row, ['statusStr', 'status']),
            ];
        }
        return $out;
    }

    /** @param string[] $keys */
    private static function first(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }
}
