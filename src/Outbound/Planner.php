<?php

namespace GlpiPlugin\Opmanager\Outbound;

/**
 * Decides what goes to OpManager for a GLPI action (spec §8, D24-D26). Pure: the hooks
 * gather the facts, this class turns them into outbox rows.
 */
final class Planner
{
    public const NOTE_MAX = 2000;

    private const SOLVED = 5;
    private const CLOSED = 6;

    /** D24: only actions typed in a logged-in GLPI session, never the plugin's own writes. */
    public static function isHuman(mixed $login_user_id, bool $is_cron, int $service_user, bool $plugin_writing): bool
    {
        $uid = is_numeric($login_user_id) ? (int) $login_user_id : 0;
        return $uid > 0 && !$is_cron && !$plugin_writing && $uid !== $service_user;
    }

    /**
     * D25: public followups only.
     *
     * @param list<array{alarm_key: string, entity_key: string}> $active
     * @return list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}>
     */
    public static function forFollowup(int $tickets_id, bool $is_private, string $text, string $user, array $active, int $users_id): array
    {
        $text = trim($text);
        if ($is_private || $text === '') {
            return [];
        }
        $note = self::cap(sprintf('GLPI tiket #%d - followup oleh %s: %s', $tickets_id, $user, $text));
        $rows = [];
        foreach ($active as $alarm) {
            $rows[] = self::row($alarm, $tickets_id, $users_id, 'note', $note);
        }
        return $rows;
    }

    /**
     * Ticket goes from open to Solved/Closed: note (typed solution, else D26 short note),
     * then clear, for every still-active alarm without a clear already queued.
     *
     * @param list<array{alarm_key: string, entity_key: string}> $active
     * @param callable(string): bool $has_clear
     * @return list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}>
     */
    public static function forStatus(int $tickets_id, int $old_status, int $new_status, ?string $solution, string $user, array $active, callable $has_clear, int $users_id): array
    {
        $done = [self::SOLVED, self::CLOSED];
        if (!in_array($new_status, $done, true) || in_array($old_status, $done, true)) {
            return [];
        }
        $solution = trim((string) $solution);
        if ($solution !== '') {
            $note = self::cap(sprintf('GLPI tiket #%d di-Solve oleh %s: %s', $tickets_id, $user, $solution));
        } elseif ($new_status === self::SOLVED) {
            $note = sprintf('GLPI tiket #%d di-Solve oleh %s', $tickets_id, $user);
        } else {
            $note = sprintf('Tiket #%d ditutup oleh %s di GLPI', $tickets_id, $user);
        }
        $rows = [];
        foreach ($active as $alarm) {
            if ($has_clear($alarm['alarm_key'])) {
                continue;
            }
            $rows[] = self::row($alarm, $tickets_id, $users_id, 'note', $note);
            $rows[] = self::row($alarm, $tickets_id, $users_id, 'clear', '');
        }
        return $rows;
    }

    /**
     * @param array{alarm_key: string, entity_key: string} $alarm
     * @return array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}
     */
    private static function row(array $alarm, int $tickets_id, int $users_id, string $action, string $payload): array
    {
        return [
            'alarm_key'  => $alarm['alarm_key'],
            'entity_key' => $alarm['entity_key'],
            'tickets_id' => $tickets_id,
            'users_id'   => $users_id,
            'action'     => $action,
            'payload'    => $payload,
        ];
    }

    private static function cap(string $text): string
    {
        return strlen($text) <= self::NOTE_MAX ? $text : substr($text, 0, self::NOTE_MAX - 3) . '...';
    }
}
