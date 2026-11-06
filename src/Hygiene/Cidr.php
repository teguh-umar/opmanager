<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/** Pure IPv4 CIDR helpers for the probe allow-list (spec §3). */
final class Cidr
{
    public static function isValid(string $cidr): bool
    {
        return self::parse($cidr) !== null;
    }

    /** @param list<string> $cidrs invalid entries are ignored */
    public static function contains(string $ip, array $cidrs): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $addr = ip2long($ip);
        foreach ($cidrs as $cidr) {
            $parsed = self::parse((string) $cidr);
            if ($parsed === null) {
                continue;
            }
            [$net, $bits] = $parsed;
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
            if (($addr & $mask) === ($net & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0: int, 1: int}|null network as int, prefix length */
    private static function parse(string $cidr): ?array
    {
        $parts = explode('/', trim($cidr));
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || (int) $parts[1] > 32) {
            return null;
        }
        if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        return [ip2long($parts[0]), (int) $parts[1]];
    }
}
