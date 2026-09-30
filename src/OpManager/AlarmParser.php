<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * OpManager listAlarms response -> normalised rows. Field names verified on
 * production 2026-09-30 (spec §14). `severity` in the response is HTML and is
 * never copied; numericSeverity/severityString are used instead (D19).
 */
final class AlarmParser
{
    public const SEVERITY_LABELS = [1 => 'Critical', 2 => 'Trouble', 3 => 'Attention', 4 => 'Service Down', 5 => 'Clear'];

    /** @return list<array<string, mixed>> */
    public static function parse(string $json): array
    {
        $data = ResponseGuard::unwrapList(ResponseGuard::decode($json, 'alarm list'), 'alarm list');

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $alarm = self::row($row);
            if ($alarm !== null) {
                $out[] = $alarm;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function row(array $row): ?array
    {
        $key = ResponseGuard::first($row, ['alarmId']);
        if ($key === '') {
            return null;
        }
        $label    = ResponseGuard::first($row, ['severityString']);
        $severity = (int) ResponseGuard::first($row, ['numericSeverity']);
        if ($severity === 0 && $label !== '') {
            $severity = (int) (array_search($label, self::SEVERITY_LABELS, true) ?: 0);
        }
        if (!isset(self::SEVERITY_LABELS[$severity])) {
            return null;
        }
        $ms = ResponseGuard::first($row, ['modTimeLong']);

        return [
            'alarm_key'      => $key,
            'entity_key'     => ResponseGuard::first($row, ['entity']),
            'device_name'    => ResponseGuard::first($row, ['deviceName']),
            'display_name'   => ResponseGuard::first($row, ['displayName', 'deviceName']),
            'ip_address'     => ResponseGuard::first($row, ['ipAddress', 'ipaddress']),
            'category'       => ResponseGuard::first($row, ['category']),
            'alarm_code'     => ResponseGuard::first($row, ['alarmcode', 'alarmCode']),
            'event_type'     => ResponseGuard::first($row, ['eventType']),
            'severity'       => $severity,
            'severity_label' => $label !== '' ? $label : self::SEVERITY_LABELS[$severity],
            'message'        => ResponseGuard::first($row, ['message']),
            'mod_time'       => ctype_digit($ms) ? date('Y-m-d H:i:s', intdiv((int) $ms, 1000)) : '',
        ];
    }
}
