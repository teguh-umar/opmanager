<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;

/**
 * One alarm poll (spec §7, D20). The active list is fetched BEFORE any write, so
 * an OpManager failure changes nothing. An alarm that vanished from the active
 * list is only processed as Clear after OpManager confirms, by entity, that this
 * very alarm is severity 5.
 */
final class AlarmSync
{
    private const CLEAR = 5;

    /** @param array{ticket_severities: list<int>, relink_window_hours: int, storm_threshold: int, storm_window_minutes: int} $cfg */
    public function __construct(
        private AlarmSource $source,
        private AlarmStore $store,
        private TicketGateway $tickets,
        private AssetResolver $assets,
        private array $cfg,
    ) {
    }

    /** @return array<string, int> */
    public function run(string $now): array
    {
        $active = $this->source->listActiveAlarms();

        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked'], 0);
        $stats['fetched'] = count($active);

        $seen = [];
        foreach ($active as $alarm) {
            $seen[] = $alarm['alarm_key'];
            $this->process($alarm, $now, $stats);
        }

        foreach ($this->store->activeNotSeen($seen) as $row) {
            try {
                $current = $this->source->alarmByEntity($row['entity_key']);
            } catch (ClientException) {
                continue;
            }
            if ($current !== null && $current['alarm_key'] === $row['alarm_key'] && (int) $current['severity'] === self::CLEAR) {
                $this->process($current, $now, $stats);
            }
        }

        return $stats;
    }

    private function process(array $alarm, string $now, array &$stats): void
    {
        $existing = $this->store->find($alarm['alarm_key']);
        $status   = null;
        if ($existing !== null && $existing['tickets_id'] > 0) {
            $status = $this->tickets->status($existing['tickets_id']);
            if ($status === null) {
                // Ticket deleted in GLPI: treat the alarm as ticketless again.
                $existing['tickets_id'] = 0;
            }
        }

        $has_ticket = $existing !== null && $existing['tickets_id'] > 0;
        $is_clear   = (int) $alarm['severity'] === self::CLEAR;
        $window     = $this->since($now, $this->cfg['storm_window_minutes'] * 60);

        $decision = Decision::decide([
            'severity'           => (int) $alarm['severity'],
            'message'            => (string) $alarm['message'],
            'is_clear'           => $is_clear,
            'existing'           => $existing,
            'ticket_status'      => $status,
            'ticketable'         => in_array((int) $alarm['severity'], $this->cfg['ticket_severities'], true),
            'device_open_ticket' => $has_ticket || $is_clear ? 0 : $this->openTicketFor($alarm['device_name']),
            'storm_ticket'       => $has_ticket || $is_clear ? 0 : $this->store->activeStorm($now, $this->cfg['storm_window_minutes']),
            'storm_should_start' => !$has_ticket && !$is_clear
                && Decision::shouldStartStorm($this->store->ownTicketsSince($window), $this->cfg['storm_threshold']),
        ]);

        $id  = $this->store->save($alarm, $this->assets->resolve($alarm), $is_clear ? 'cleared' : 'active', $now);
        $tid = $decision['tickets_id'];

        switch ($decision['action']) {
            case Decision::NONE:
                return;
            case Decision::STORE:
                $stats['stored']++;
                return;
            case Decision::FOLLOWUP:
                $this->tickets->followup($tid, Messages::changed($alarm));
                $stats['followups']++;
                return;
            case Decision::CLEAR:
                $this->tickets->followup($tid, Messages::cleared($alarm));
                $stats['cleared']++;
                if ($this->store->allCleared($tid)) {
                    $this->tickets->solve($tid, Messages::solved());
                    $stats['solved']++;
                }
                return;
            case Decision::REOPEN:
                $this->tickets->reopen($tid, Messages::reopened($alarm));
                $stats['reopened']++;
                return;
            case Decision::JOIN_DEVICE:
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'joined', $now);
                $stats['joined']++;
                return;
            case Decision::JOIN_STORM:
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'storm', $now);
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
                $this->tickets->followup($tid, Messages::joined($alarm));
                $this->store->setTicket($id, $tid, 'storm', $now);
                $stats['storm']++;
                return;
            case Decision::CREATE:
                $tid = $this->tickets->create($alarm, $this->assets->resolve($alarm));
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
            $status = $this->tickets->status($tid);
            if ($status !== null && $status < 5) {
                return $tid;
            }
        }
        return 0;
    }

    private function since(string $now, int $seconds): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($now) - $seconds);
    }
}
