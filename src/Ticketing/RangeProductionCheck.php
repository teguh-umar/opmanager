<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Production = the alarm IP, or any IP of the matched GLPI asset, lies in one of
 * the IP Addressing ranges selected in Setup. No range selected means nothing is
 * production, so no ticket is created - the plugin never guesses.
 */
final class RangeProductionCheck implements ProductionCheck
{
    /** @var callable(string, int): list<string> */
    private $assetIps;

    /**
     * @param list<array{id: int, begin: int, end: int}> $ranges
     * @param callable(string, int): list<string>        $asset_ips itemtype, items_id => IPs
     */
    public function __construct(private array $ranges, callable $asset_ips)
    {
        $this->assetIps = $asset_ips;
    }

    /**
     * @param list<array{id: int|string, begin_ip: string, end_ip: string}> $rows glpi_plugin_addressing_addressings
     * @return list<array{id: int, begin: int, end: int}>
     */
    public static function rangesFromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $begin = self::ipv4((string) $row['begin_ip']);
            $end   = self::ipv4((string) $row['end_ip']);
            if ($begin === null || $end === null || $begin > $end) {
                continue;
            }
            $out[] = ['id' => (int) $row['id'], 'begin' => $begin, 'end' => $end];
        }
        return $out;
    }

    public function isProduction(array $alarm, array $asset): bool
    {
        if ($this->ranges === []) {
            return false;
        }
        $ips = [(string) ($alarm['ip_address'] ?? '')];
        if (($asset['items_id'] ?? 0) > 0 && ($asset['itemtype'] ?? '') !== '') {
            $ips = array_merge($ips, ($this->assetIps)($asset['itemtype'], (int) $asset['items_id']));
        }
        foreach ($ips as $ip) {
            $long = self::ipv4($ip);
            if ($long === null) {
                continue;
            }
            foreach ($this->ranges as $range) {
                if ($long >= $range['begin'] && $long <= $range['end']) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function ipv4(string $ip): ?int
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $long = ip2long($ip);
        return $long === false ? null : $long;
    }
}
