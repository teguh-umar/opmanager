<?php

namespace GlpiPlugin\Opmanager\Matching;

/**
 * Pure matching of one OpManager device to one GLPI asset (spec §5).
 *
 * Each mode is an ordered list of steps. The first step that yields EXACTLY one
 * asset wins. A step yielding several assets is never used (duplicate IPs are a
 * known problem in this GLPI); if no later step settles it, the device ends in
 * "review" rather than "unmatched", so a human looks at it. Manual pins are
 * handled by the caller - this class never sees them.
 */
final class Matcher
{
    public const MATCHED   = 'matched';
    public const REVIEW    = 'review';
    public const UNMATCHED = 'unmatched';

    public function __construct(private AssetLookup $lookup)
    {
    }

    /** @return array{state: string, itemtype: string, items_id: int, source: string} */
    public function match(string $name, string $ip, string $mode): array
    {
        $steps = match ($mode) {
            'ip_only'     => ['ip'],
            'name_only'   => ['name'],
            'hybrid'      => ['ip', 'name'],
            'hybrid_name' => ['name', 'ip'],
            default       => [], // 'manual' and anything unknown: never guess
        };

        $ambiguous = false;
        foreach ($steps as $step) {
            $hits = $this->candidates($step, $name, $ip);
            if (count($hits) === 1) {
                return [
                    'state'    => self::MATCHED,
                    'itemtype' => $hits[0]['itemtype'],
                    'items_id' => $hits[0]['items_id'],
                    'source'   => $step,
                ];
            }
            if (count($hits) > 1) {
                $ambiguous = true;
            }
        }

        return [
            'state'    => $ambiguous ? self::REVIEW : self::UNMATCHED,
            'itemtype' => '',
            'items_id' => 0,
            'source'   => '',
        ];
    }

    /** @return list<array{itemtype: string, items_id: int}> */
    private function candidates(string $step, string $name, string $ip): array
    {
        if ($step === 'name') {
            $name = trim($name);
            return $name === '' ? [] : self::unique($this->lookup->byName($name));
        }
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? [] : self::unique($this->lookup->byIp($ip));
    }

    /**
     * @param list<array{itemtype: string, items_id: int}> $hits
     * @return list<array{itemtype: string, items_id: int}>
     */
    private static function unique(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $out[$hit['itemtype'] . '#' . $hit['items_id']] = $hit;
        }
        return array_values($out);
    }
}
