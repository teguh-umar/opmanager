<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;

/**
 * One alarm poll (spec §7, D20). The active list is fetched BEFORE any write, so
 * an OpManager failure changes nothing. An alarm that vanished from the active
 * list is only processed as Clear after OpManager confirms, by entity, that this
 * very alarm is severity 5. One alarm failing never stops the others.
 */
final class AlarmSync
{
    private const CLEAR = 5;

    /** @var list<string> */
    private array $errors = [];

    private AlarmFilter $filter;

    /** @param array{ticket_severities: list<int>, relink_window_hours: int, storm_threshold: int, storm_window_minutes: int, storm_idle_minutes: int, filter_mode?: string, filter_event_types?: list<string>, filter_keywords?: list<string>} $cfg */
    public function __construct(
        private AlarmSource $source,
        private AlarmStore $store,
        private TicketGateway $tickets,
        private AssetResolver $assets,
        private ProductionCheck $production,
        private array $cfg,
    ) {
        $this->filter = AlarmFilter::fromConfig($cfg);
    }

    /** @return array<string, int> */
    public function run(string $now): array
    {
        $active = $this->source->listActiveAlarms();

        $this->errors = [];
        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked', 'errors'], 0);
        $stats['fetched'] = count($active);

        $seen = [];
        foreach ($active as $alarm) {
            $seen[] = $alarm['alarm_key'];
            $this->guarded($alarm, $now, $stats);
        }

        foreach ($this->store->activeNotSeen($seen) as $row) {
            try {
                $current = $this->source->alarmByEntity($row['entity_key']);
            } catch (ClientException) {
                continue;
            }
            if ($current !== null && $current['alarm_key'] === $row['alarm_key'] && (int) $current['severity'] === self::CLEAR) {
                $this->guarded($current, $now, $stats);
            }
        }

        return $stats;
    }

    /**
     * One alarm, e.g. from the webhook (v0.3.0). Same engine and guard as run();
     * the caller holds the SyncLock.
     *
     * @return array<string, int>
     */
    public function processOne(array $alarm, string $now): array
    {
        $this->errors = [];
        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked', 'errors'], 0);
        $stats['fetched'] = 1;
        $this->guarded($alarm, $now, $stats);
        return $stats;
    }

    /** @return list<string> "alarm <key>: <message>" for every alarm that failed in the last run */
    public function lastErrors(): array
    {
        return $this->errors;
    }

    /**
     * Any failure inside GLPI (a hook of another plugin, notifications, rules)
     * must not stop the poll or hide the problem (final review, 2026-09-30).
     */
    private function guarded(array $alarm, string $now, array &$stats): void
    {
        try {
            $this->process($alarm, $now, $stats);
        } catch (\Throwable $e) {
            $stats['errors']++;
            $this->errors[] = sprintf('alarm %s: %s', $alarm['alarm_key'], $e->getMessage());
        }
    }

    private function process(array $alarm, string $now, array &$stats): void
    {
        $existing = $this->store->find($alarm['alarm_key']);
        $status   = null;
        if ($existing !== null && $existing['tickets_id'] > 0) {
            $status = $this->tickets->status($existing['tickets_id']);
            if ($status === null) {
                // Ticket purged from GLPI: treat the alarm as ticketless again.
                // A trashed/merged ticket (TRASHED) stays bound - Decision leaves it alone.
                $existing['tickets_id'] = 0;
            }
        }

        $has_ticket = $existing !== null && $existing['tickets_id'] > 0;
        $is_clear   = (int) $alarm['severity'] === self::CLEAR;
        $window     = $this->since($now, $this->cfg['storm_window_minutes'] * 60);
        $candidate  = !$has_ticket && !$is_clear;

        // A backlog alarm (older than the storm window, e.g. on the first poll after
        // install) never starts a storm: it is not part of a burst (final review).
        $fresh = (string) $alarm['mod_time'] === '' || (string) $alarm['mod_time'] >= $window;

        // Tickets only for the configured severities on production assets
        // (user decision 2026-09-30), minus the alarm filter (2026-10-01) for new tickets;
        // everything else is stored and shown only.
        $asset      = $this->assets->resolve($alarm);
        $ticketable = in_array((int) $alarm['severity'], $this->cfg['ticket_severities'], true)
            && ($has_ticket || $is_clear || ($this->filter->allows($alarm) && $this->production->isProduction($alarm, $asset)));

        $decision = Decision::decide([
            'severity'             => (int) $alarm['severity'],
            'message'              => (string) $alarm['message'],
            'is_clear'             => $is_clear,
            'existing'             => $existing,
            'ticket_status'        => $status,
            'ticketable'           => $ticketable,
            'device_open_ticket'   => $candidate ? $this->openTicketFor($alarm['device_name']) : 0,
            'storm_ticket'         => $candidate ? $this->liveStorm($now) : 0,
            'storm_should_start'   => $candidate && $fresh
                && Decision::shouldStartStorm($this->store->ownTicketsSince($window), $this->cfg['storm_threshold']),
            'message_followup_due' => $this->followupDue($existing, $now),
        ]);

        $id  = $this->store->save($alarm, $asset, $is_clear ? 'cleared' : 'active', $now);
        $tid = $decision['tickets_id'];

        switch ($decision['action']) {
            case Decision::NONE:
                return;
            case Decision::STORE:
                $stats['stored']++;
                return;
            case Decision::FOLLOWUP:
                $this->tickets->followup($tid, Messages::changed($alarm));
                $this->store->markFollowup($id, $now);
                $stats['followups']++;
                return;
            case Decision::CLEAR:
                $this->tickets->followup($tid, Messages::cleared($alarm));
                $this->store->markFollowup($id, $now);
                $stats['cleared']++;
                if ($this->store->allCleared($tid)) {
                    $this->tickets->solve($tid, Messages::solved());
                    $stats['solved']++;
                }
                return;
            case Decision::REOPEN:
                $this->tickets->reopen($tid, Messages::reopened($alarm));
                $this->store->markFollowup($id, $now);
                $stats['reopened']++;
                return;
            case Decision::JOIN_DEVICE:
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'joined', $now);
                $this->store->markFollowup($id, $now);
                $stats['joined']++;
                return;
            case Decision::JOIN_STORM:
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'storm', $now);
                $this->store->markFollowup($id, $now);
                $this->store->hitStorm($tid, $now);
                $stats['storm']++;
                return;
            case Decision::START_STORM:
                $tid = $this->tickets->createStorm($alarm, $now);
                if ($tid === 0) {
                    $this->store->markPending($id);
                    $stats['pending']++;
                    return;
                }
                $this->store->startStorm($tid, $now);
                $this->store->setTicket($id, $tid, 'storm', $now);
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->markFollowup($id, $now);
                $stats['storm']++;
                return;
            case Decision::CREATE:
                $tid = $this->tickets->create($alarm, $asset);
                if ($tid === 0) {
                    $this->store->markPending($id);
                    $stats['pending']++;
                    return;
                }
                $this->store->setTicket($id, $tid, 'own', $now);
                $stats['created']++;
                $this->relink($tid, $alarm, $now, $stats);
                return;
        }
    }

    private function relink(int $tid, array $alarm, string $now, array &$stats): void
    {
        if ($this->cfg['relink_window_hours'] <= 0) {
            return;
        }
        $since = $this->since($now, $this->cfg['relink_window_hours'] * 3600);
        foreach ($this->store->recentRelatedTickets($alarm['device_name'], $alarm['alarm_code'], $tid) as $old) {
            if ($this->tickets->closedSince($old, $since)) {
                $this->tickets->link($tid, $old);
                $this->tickets->followup($tid, Messages::related($old));
                $stats['linked']++;
                return;
            }
        }
    }

    private function openTicketFor(string $device_name): int
    {
        if ($device_name === '') {
            return 0;
        }
        foreach ($this->store->ticketsForDevice($device_name) as $tid) {
            if (self::isOpen($this->tickets->status($tid))) {
                return $tid;
            }
        }
        return 0;
    }

    /** The active storm ticket, or 0; a storm whose ticket a technician closed or trashed ends here. */
    private function liveStorm(string $now): int
    {
        $storm = $this->store->activeStorm($now, $this->cfg['storm_idle_minutes']);
        if ($storm > 0 && !self::isOpen($this->tickets->status($storm))) {
            $this->store->endStorm($storm, $now);
            return 0;
        }
        return $storm;
    }

    private function followupDue(?array $existing, string $now): bool
    {
        $last = (string) ($existing['last_followup_at'] ?? '');
        return $last === '' || $last <= $this->since($now, Decision::MESSAGE_FOLLOWUP_MINUTES * 60);
    }

    private static function isOpen(?int $status): bool
    {
        return $status !== null && $status >= 1 && $status < 5;
    }

    private function since(string $now, int $seconds): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($now) - $seconds);
    }
}
