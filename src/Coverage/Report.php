<?php

namespace GlpiPlugin\Opmanager\Coverage;

/** Pure: OpManager devices vs GLPI assets (spec §15.1). */
final class Report
{
    /**
     * @param list<array<string, mixed>> $devices
     * @param list<array<string, mixed>> $assets
     * @param array{ranges: list<array{begin: int, end: int}>, production_only: bool, category: string, last_device_sync: string, stale_minutes: int} $opt
     * @return array{kpi: array<string, int>, lists: array<string, list<array<string, mixed>>>, categories: list<string>}
     */
    public static function build(array $devices, array $assets, array $opt): array
    {
        $categories = array_values(array_unique(array_filter(array_map(static fn ($d) => (string) $d['category'], $devices))));
        sort($categories);

        if ($opt['production_only']) {
            $devices = array_values(array_filter($devices, static fn ($d) => self::inRanges((string) $d['ip'], $opt['ranges'])));
            $assets  = array_values(array_filter($assets, static function ($a) use ($opt) {
                foreach ($a['ips'] as $ip) {
                    if (self::inRanges((string) $ip, $opt['ranges'])) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if ($opt['category'] !== '') {
            $devices = array_values(array_filter($devices, static fn ($d) => $d['category'] === $opt['category']));
        }

        $by_state = static fn (string $s) => array_values(array_filter($devices, static fn ($d) => $d['match_state'] === $s));
        $matched  = $by_state('matched');
        $linked   = [];
        foreach ($matched as $d) {
            $linked[$d['itemtype'] . '#' . $d['items_id']] = true;
        }
        $names = [];
        foreach ($assets as $a) {
            $names[$a['itemtype'] . '#' . $a['items_id']] = (string) $a['name'];
        }

        $not_monitored = array_values(array_filter($assets, static fn ($a) => !isset($linked[$a['itemtype'] . '#' . $a['items_id']])));
        $name_differs  = [];
        foreach ($matched as $d) {
            $key = $d['itemtype'] . '#' . $d['items_id'];
            $dev = $d['display_name'] !== '' ? $d['display_name'] : $d['name'];
            if ($d['match_source'] === 'ip' && isset($names[$key]) && self::normaliseName((string) $dev) !== self::normaliseName($names[$key])) {
                $name_differs[] = $d + ['asset_name' => $names[$key]];
            }
        }
        $limit = $opt['last_device_sync'] === '' ? null : strtotime($opt['last_device_sync']) - 60 * $opt['stale_minutes'];
        $stale = $limit === null ? [] : array_values(array_filter($devices, static fn ($d) => $d['last_seen'] !== '' && strtotime((string) $d['last_seen']) < $limit));

        $in_scope  = count($assets);
        $monitored = $in_scope - count($not_monitored);
        return [
            'kpi' => [
                'devices'       => count($devices),
                'matched'       => count($matched),
                'not_in_glpi'   => count($by_state('unmatched')),
                'ambiguous'     => count($by_state('review')),
                'assets'        => $in_scope,
                'monitored'     => $monitored,
                'not_monitored' => count($not_monitored),
                'coverage_pct'  => $in_scope === 0 ? 0 : (int) round(100 * $monitored / $in_scope),
            ],
            'lists' => [
                'not_in_glpi'   => $by_state('unmatched'),
                'not_monitored' => $not_monitored,
                'ambiguous'     => $by_state('review'),
                'name_differs'  => $name_differs,
                'stale'         => $stale,
            ],
            'categories' => $categories,
        ];
    }

    public static function normaliseName(string $name): string
    {
        $name = strtolower(trim($name));
        $dot  = strpos($name, '.');
        // A dotted IP is not a domain name: keep it whole.
        if ($dot !== false && filter_var($name, FILTER_VALIDATE_IP) === false) {
            $name = substr($name, 0, $dot);
        }
        return $name;
    }

    /** @param list<array{begin: int, end: int}> $ranges */
    public static function inRanges(string $ip, array $ranges): bool
    {
        $n = ip2long($ip);
        if ($n === false) {
            return false;
        }
        foreach ($ranges as $r) {
            if ($n >= $r['begin'] && $n <= $r['end']) {
                return true;
            }
        }
        return false;
    }
}
