<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/**
 * Pure: title and HTML of the hygiene tickets and followups (spec §4). Every value
 * is escaped; the device row is
 * array{device_key, name, ip, category, availability, asset, state, liveness, detail, last_seen}.
 */
final class Report
{
    /** flow => [state => section heading], in display order */
    private const SECTIONS = [
        'glpi' => [
            Classifier::GLPI_MISSING => 'Not found in GLPI',
            Classifier::GLPI_REVIEW  => 'Needs manual pairing',
        ],
        'nms' => [
            Classifier::NMS_DEAD    => 'Dead - candidate for removal from OpManager',
            Classifier::NMS_UNKNOWN => 'Liveness unknown - check manually',
        ],
    ];

    public static function title(string $flow, string $now): string
    {
        $date = substr($now, 0, 10);
        return $flow === 'glpi'
            ? '[OPM-HYGIENE] Monitored in OpManager, not in GLPI - ' . $date
            : '[OPM-HYGIENE] Unmanaged in OpManager: remove or check - ' . $date;
    }

    /** @param list<array<string, string>> $devices */
    public static function body(string $flow, array $devices, string $list_url): string
    {
        $intro = $flow === 'glpi'
            ? 'These devices are monitored by OpManager but are not registered as assets in GLPI. GLPI is the asset source of truth: register them, or pair them manually on their OpManager device page.'
            : 'These devices are unmanaged or not monitored in OpManager. Agreed rule: a device that no longer exists must be deleted from OpManager. Devices proven alive are not listed.';
        $html = '<p>' . self::e($intro) . '</p>';
        foreach (self::SECTIONS[$flow] ?? [] as $state => $heading) {
            $rows = array_values(array_filter($devices, static fn (array $d): bool => $d['state'] === $state));
            if ($rows !== []) {
                $html .= '<h3>' . self::e($heading) . ' (' . count($rows) . ')</h3>' . self::table($rows);
            }
        }
        $html .= '<p><a href="' . self::e($list_url) . '">' . self::e('Open the OpManager device list') . '</a></p>';
        $html .= '<p>' . self::e('Exclude a device from these tickets on its OpManager device page (reason required).') . '</p>';
        return $html;
    }

    /**
     * @param array{new: array<string, string>, changed: array<string, array{0: string, 1: string}>, resolved: list<string>} $plan
     * @param array<string, array<string, string>> $devices_by_key
     */
    public static function followup(array $plan, array $devices_by_key): string
    {
        $labels = Classifier::labels();
        $html   = '';
        if ($plan['new'] !== []) {
            $rows = [];
            foreach ($plan['new'] as $key => $state) {
                $rows[] = ($devices_by_key[$key] ?? self::bare($key)) + ['state' => $state];
            }
            $html .= '<h3>New</h3>' . self::table($rows);
        }
        if ($plan['changed'] !== []) {
            $html .= '<h3>' . self::e('Changed condition (old → new)') . '</h3><ul>';
            foreach ($plan['changed'] as $key => [$old, $new]) {
                $html .= '<li>' . self::e(self::name($devices_by_key, $key)) . ': '
                    . self::e($labels[$old] ?? $old) . ' → ' . self::e($labels[$new] ?? $new) . '</li>';
            }
            $html .= '</ul>';
        }
        if ($plan['resolved'] !== []) {
            $html .= '<h3>No longer applies</h3><ul>';
            foreach ($plan['resolved'] as $key) {
                $html .= '<li>' . self::e(self::name($devices_by_key, $key)) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    /** @param list<array<string, string>> $rows */
    private static function table(array $rows): string
    {
        $cols = ['name' => 'Name', 'ip' => 'IP', 'category' => 'Category', 'availability' => 'Availability', 'asset' => 'GLPI asset', 'liveness' => 'Liveness', 'detail' => 'Evidence', 'last_seen' => 'Last seen'];
        $html = '<table class="table"><tr>';
        foreach ($cols as $label) {
            $html .= '<th>' . self::e($label) . '</th>';
        }
        $html .= '</tr>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys($cols) as $col) {
                $value = (string) ($row[$col] ?? '');
                $html .= '<td>' . self::e($value !== '' ? $value : '-') . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** @param array<string, array<string, string>> $by */
    private static function name(array $by, string $key): string
    {
        $d = $by[$key] ?? null;
        return $d === null ? $key : ($d['name'] . ($d['ip'] !== '' ? ' (' . $d['ip'] . ')' : ''));
    }

    /** @return array<string, string> */
    private static function bare(string $key): array
    {
        return ['device_key' => $key, 'name' => $key, 'ip' => ''];
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}
