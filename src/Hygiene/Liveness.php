<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/**
 * Pure: turns raw facts into liveness evidence and combines evidence from every
 * provider (spec §3). Evidence = array{verdict: 'alive'|'dead', source: string, detail: string};
 * the from*() helpers leave `source` to the provider registry.
 */
final class Liveness
{
    public const DETAIL_MAX = 255;

    /** @return array{verdict: string, detail: string}|null */
    public static function fromAgent(?string $last_contact, string $now, int $stale_days): ?array
    {
        if ($last_contact === null || $last_contact === '') {
            return null;
        }
        $alive = $last_contact >= self::cutoff($now, $stale_days);
        return ['verdict' => $alive ? 'alive' : 'dead', 'detail' => 'GLPI agent last contact ' . $last_contact];
    }

    /**
     * @param array{status: string, mist_last_seen: ?string}|null $row
     * @return array{verdict: string, detail: string}|null
     */
    public static function fromMist(?array $row, string $now, int $stale_days): ?array
    {
        if ($row === null) {
            return null;
        }
        $status = (string) ($row['status'] ?? '');
        $seen   = (string) ($row['mist_last_seen'] ?? '');
        $alive  = $status === 'connected' || ($seen !== '' && $seen >= self::cutoff($now, $stale_days));
        $detail = 'Mist ' . ($status !== '' ? $status : 'status unknown') . ($seen !== '' ? ', last seen ' . $seen : ', never seen');
        return ['verdict' => $alive ? 'alive' : 'dead', 'detail' => $detail];
    }

    /**
     * @param array{open: ?int, tried: list<int>}|null $result null = not probed
     * @return array{verdict: string, detail: string}|null
     */
    public static function fromProbe(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }
        if ($result['open'] !== null) {
            return ['verdict' => 'alive', 'detail' => 'TCP ' . $result['open'] . ' open'];
        }
        return ['verdict' => 'dead', 'detail' => 'TCP ' . implode(',', $result['tried']) . ' no answer'];
    }

    /**
     * Any alive wins; else any dead; else unknown.
     *
     * @param list<array{verdict: string, source: string, detail: string}> $evidence
     * @return array{state: string, source: string, detail: string}
     */
    public static function combine(array $evidence): array
    {
        foreach ($evidence as $e) {
            if ($e['verdict'] === 'alive') {
                return ['state' => 'alive', 'source' => $e['source'], 'detail' => self::cut($e['detail'])];
            }
        }
        $dead = array_values(array_filter($evidence, static fn (array $e): bool => $e['verdict'] === 'dead'));
        if ($dead !== []) {
            return [
                'state'  => 'dead',
                'source' => $dead[0]['source'],
                'detail' => self::cut(implode('; ', array_column($dead, 'detail'))),
            ];
        }
        return ['state' => 'unknown', 'source' => '', 'detail' => 'no evidence source applies'];
    }

    private static function cutoff(string $now, int $days): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($now) - $days * 86400);
    }

    private static function cut(string $text): string
    {
        return substr($text, 0, self::DETAIL_MAX);
    }
}
