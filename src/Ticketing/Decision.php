<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Pure decision for one alarm (spec §7, D3/D6/D12/D20). Rules, first match wins:
 * an alarm that already has a ticket only ever talks to that ticket; an alarm
 * without one may create, join its device's open ticket, or join/start a storm.
 */
final class Decision
{
    public const NONE        = 'none';
    public const STORE       = 'store';
    public const FOLLOWUP    = 'followup';
    public const CLEAR       = 'clear';
    public const REOPEN      = 'reopen';
    public const JOIN_DEVICE = 'join_device';
    public const JOIN_STORM  = 'join_storm';
    public const START_STORM = 'start_storm';
    public const CREATE      = 'create';

    /** Ticket status value meaning "in the GLPI trash / merged away" (same as TicketGateway::TRASHED). */
    public const TRASHED = -1;

    /** A message-only change (e.g. a live CPU value) is reported at most once per this many minutes. */
    public const MESSAGE_FOLLOWUP_MINUTES = 60;

    private const SOLVED = 5;
    private const CLOSED = 6;

    /** @return array{action: string, tickets_id: int} */
    public static function decide(array $c): array
    {
        $existing = $c['existing'];
        $tid      = (int) ($existing['tickets_id'] ?? 0);

        if ($tid > 0) {
            // Trashed or merged away by a technician: leave it alone, never re-ticket (D3).
            if ($c['ticket_status'] === self::TRASHED) {
                return self::out(self::NONE, $tid);
            }
            $was_cleared = $existing['state'] === 'cleared';
            if ($c['is_clear']) {
                return self::out($was_cleared ? self::NONE : self::CLEAR, $tid);
            }
            if ($was_cleared) {
                $closed = in_array($c['ticket_status'], [self::SOLVED, self::CLOSED], true);
                return self::out($closed ? self::REOPEN : self::FOLLOWUP, $tid);
            }
            $severity_changed = $c['severity'] !== (int) $existing['severity'];
            $message_changed  = $c['message'] !== (string) $existing['message'];
            $report           = $severity_changed || ($message_changed && ($c['message_followup_due'] ?? true));
            return self::out($report ? self::FOLLOWUP : self::NONE, $tid);
        }

        if ($c['is_clear'] || !$c['ticketable']) {
            return self::out(self::STORE, 0);
        }
        if ($c['device_open_ticket'] > 0) {
            return self::out(self::JOIN_DEVICE, $c['device_open_ticket']);
        }
        if ($c['storm_ticket'] > 0) {
            return self::out(self::JOIN_STORM, $c['storm_ticket']);
        }
        if ($c['storm_should_start']) {
            return self::out(self::START_STORM, 0);
        }
        return self::out(self::CREATE, 0);
    }

    public static function shouldStartStorm(int $own_created_in_window, int $threshold): bool
    {
        return $threshold > 0 && $own_created_in_window >= $threshold;
    }

    /** @return array{action: string, tickets_id: int} */
    private static function out(string $action, int $tid): array
    {
        return ['action' => $action, 'tickets_id' => $tid];
    }
}
