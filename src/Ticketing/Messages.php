<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** Plain-text followup / solution texts. The gateway escapes them into HTML. */
final class Messages
{
    public static function changed(array $a): string
    {
        return sprintf("OpManager alarm %s changed: %s - %s", $a['alarm_key'], $a['severity_label'], $a['message']);
    }

    public static function cleared(array $a): string
    {
        return sprintf("OpManager alarm %s on %s is Clear.", $a['alarm_key'], self::device($a));
    }

    public static function solved(): string
    {
        return 'All OpManager alarms on this ticket are Clear.';
    }

    public static function reopened(array $a): string
    {
        return sprintf("OpManager alarm %s on %s is active again: %s - %s", $a['alarm_key'], self::device($a), $a['severity_label'], $a['message']);
    }

    public static function joined(array $a): string
    {
        return sprintf("Another OpManager alarm on %s: %s (alarm %s) - %s", self::device($a), $a['severity_label'], $a['alarm_key'], $a['message']);
    }

    public static function related(int $old): string
    {
        return sprintf('A similar alarm on this device was already handled in ticket #%d.', $old);
    }

    public static function stormTitle(string $now): string
    {
        return sprintf('[OPM] Alarm storm since %s', $now);
    }

    private static function device(array $a): string
    {
        return (string) (($a['display_name'] ?? '') !== '' ? $a['display_name'] : ($a['device_name'] ?? ''));
    }
}
