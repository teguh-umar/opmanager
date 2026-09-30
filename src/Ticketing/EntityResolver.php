<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** Pure D14: asset entity -> most specific IPv4 GLPI IP network -> default. */
final class EntityResolver
{
    /** @param list<array{entities_id: int|string, address: string, netmask: string}> $networks */
    public static function resolve(?int $asset_entity, string $ip, array $networks, bool $use_networks, int $default): int
    {
        if ($asset_entity !== null) {
            return $asset_entity;
        }
        if ($use_networks && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $addr     = ip2long($ip);
            $best     = null;
            $best_len = -1;
            foreach ($networks as $net) {
                $n = ip2long((string) $net['address']);
                $m = ip2long((string) $net['netmask']);
                if ($n === false || $m === false || ($addr & $m) !== ($n & $m)) {
                    continue;
                }
                $len = substr_count(decbin($m & 0xFFFFFFFF), '1');
                if ($len > $best_len) {
                    $best_len = $len;
                    $best     = (int) $net['entities_id'];
                }
            }
            if ($best !== null) {
                return $best;
            }
        }
        return $default;
    }
}
