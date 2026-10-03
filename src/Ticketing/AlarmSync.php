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

    /** GLPI Ticket::CLOSED. */
    private const CLOSED_STATUS = 6;

    /** GLPI Ticket::SOLVED. */
    private const SOLVED_STATUS = 5;

    /** Deferred webhook requests older than this are dropped (spec §16.4). */
    private const REQUEST_TTL_HOURS = 24;

    /** Merge chains are followed at most this far (spec §16.5). */
    private const MERGE_HOPS = 5;

    /** @var list<string> */
    private array $errors = [];

    private AlarmFilter $filter;

    /** @param array{ticket_severities: list<int>, relink_window_hours: int, storm_threshold: int, storm_window_minutes: int, storm_idle_minutes: int, filter_mode?: string, filter_event_types?: list<string>, filter_keywords?: list<string>, ticket_source?: string} $cfg */
    public function __construct(
        private AlarmSource $source,
        private AlarmStore $store,
        private TicketGateway $tickets,
        private AssetResolver $assets,
        private ProductionCheck $production,
        private array $cfg,
        private ?TicketRequestStore $requests = null,
    ) {
        $this->filter = AlarmFilter::fromConfig($cfg);
    }

    /** @return array<string, int> */
    public function run(string $now): array
    {
        $active = $this->source->listActiveAlarms();

        $this->errors = [];
        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'restored', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked', 'errors'], 0);
        $stats['fetched'] = count($active);

        // D32: webhooks answered 202 are processed now as if the webhook had come through.
        $requested = array_fill_keys($this->requests?->keys() ?? [], true);

        $seen = [];
        foreach ($active as $alarm) {
            $seen[]   = $alarm['alarm_key'];
            $is_asked = isset($requested[$alarm['alarm_key']]);
            if ($is_asked) {
                $this->processRequested($alarm, $now, $stats);
            } else {
                $this->guarded($alarm, $now, $stats, false);
            }
        }

        foreach ($this->store->activeNotSeen($seen) as $row) {
            try {
                $current = $this->source->alarmByEntity($row['entity_key']);
            } catch (ClientException) {
                continue;
            }
            if ($current !== null && $current['alarm_key'] === $row['alarm_key'] && (int) $current['severity'] === self::CLEAR) {
                if ($this->guarded($current, $now, $stats, false)) {
                    // M1: a deferred Clear must not leave a request that a later activation would inherit.
                    $this->requests?->delete($current['alarm_key']);
                }
            }
        }

        $this->requests?->purgeBefore($this->since($now, self::REQUEST_TTL_HOURS * 3600));

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
        $stats = array_fill_keys(['fetched', 'created', 'joined', 'storm', 'reopened', 'restored', 'followups', 'cleared', 'solved', 'pending', 'stored', 'linked', 'errors'], 0);
        $stats['fetched'] = 1;
        $this->processRequested($alarm, $now, $stats);
        return $stats;
    }

    /**
     * A requested alarm (webhook or deferred request, §16.2): a failure or a pending ticket
     * keeps/records the request so the next run retries; only a clean result consumes it (§16.4).
     */
    private function processRequested(array $alarm, string $now, array &$stats): void
    {
        $pending = $stats['pending'];
        $ok      = $this->guarded($alarm, $now, $stats, true);
        if (!$ok || $stats['pending'] > $pending) {
            $this->requests?->record($alarm['alarm_key'], (string) ($alarm['entity_key'] ?? ''));
        } else {
            $this->requests?->delete($alarm['alarm_key']);
        }
    }

    /** @return list<string> "alarm <key>: <message>" for every alarm that failed in the last run */
    public function lastErrors(): array
    {
        return $this->errors;
    }

    /**
     * Any failure inside GLPI (a hook of another plugin, notifications, rules)
     * must not stop the poll or hide the problem (final review, 2026-09-30).
     *
     * @return bool true when the alarm was processed without error
     */
    private function guarded(array $alarm, string $now, array &$stats, bool $requested): bool
    {
        try {
            $this->process($alarm, $now, $stats, $requested);
            return true;
        } catch (\Throwable $e) {
            $stats['errors']++;
            $this->errors[] = sprintf('alarm %s: %s', $alarm['alarm_key'], $e->getMessage());
            return false;
        }
    }

    /** @param bool $requested the webhook asked for this alarm (directly or deferred, spec §16.2) */
    private function process(array $alarm, string $now, array &$stats, bool $requested): void
    {
        $by_webhook = ($this->cfg['ticket_source'] ?? 'rules') === 'webhook';
        $is_clear   = (int) $alarm['severity'] === self::CLEAR;

        $existing = $this->store->find($alarm['alarm_key']);
        $status   = null;
        if ($existing !== null && $existing['tickets_id'] > 0) {
            // §16.5: a merged-away ticket hands its alarms to the merge target.
            $target = $this->followMerge($existing['tickets_id']);
            if ($target !== $existing['tickets_id']) {
                $this->store->moveTicket($existing['id'], $target);
                $existing['tickets_id'] = $target;
            }
            $status = $this->tickets->status($existing['tickets_id']);
            // §16.3: a webhook for the active alarm is a recurrence: a trashed ticket is restored
            // first, and a Solved one reopens whatever the polls saw (Solved -> reopen,
            // Closed -> D30, open -> followup). Only a webhook does this; polls never do.
            $recurrence = $by_webhook && $requested && !$is_clear;
            $restored   = false;
            if ($recurrence && $status === TicketGateway::TRASHED
                && $this->tickets->mergedInto($existing['tickets_id']) === 0) {
                $this->tickets->restore($existing['tickets_id']);
                $stats['restored']++;
                $status    = $this->tickets->status($existing['tickets_id']);
                $restored  = true;
            }
            // A restored ticket that is still open gets its followup (§16.3, D31) like a recurrence.
            if ($recurrence && ($restored || $status === self::SOLVED_STATUS || $status === self::CLOSED_STATUS)) {
                $existing['state'] = 'cleared';
            }
            if ($status === null) {
                // Ticket purged from GLPI: treat the alarm as ticketless again.
                // A trashed/merged ticket (TRASHED) stays bound - Decision leaves it alone.
                $existing['tickets_id'] = 0;
            }
        }

        // D30 (v1.0.1): the same alarm id active again after its ticket was Closed is a new
        // alarm: severity, production and filter apply again, and a new ticket is linked
        // to the closed one. Before, the closed ticket was reopened on every flap.
        $reborn_from = 0;
        if ($existing !== null && $existing['tickets_id'] > 0 && $status === self::CLOSED_STATUS
            && !$is_clear && $existing['state'] === 'cleared') {
            $reborn_from            = $existing['tickets_id'];
            $existing['tickets_id'] = 0;
        }

        $has_ticket = $existing !== null && $existing['tickets_id'] > 0;
        $window     = $this->since($now, $this->cfg['storm_window_minutes'] * 60);
        $candidate  = !$has_ticket && !$is_clear;

        // A backlog alarm (older than the storm window, e.g. on the first poll after
        // install) never starts a storm: it is not part of a burst (final review).
        $fresh = (string) $alarm['mod_time'] === '' || (string) $alarm['mod_time'] >= $window;

        // Tickets only for the configured severities on production assets
        // (user decision 2026-09-30), minus the alarm filter (2026-10-01) for new tickets;
        // everything else is stored and shown only.
        $asset      = $this->assets->resolve($alarm);
        // D32: in webhook mode OpManager's webhook criteria pick the alarms; the plugin only
        // checks the production ranges (D21). Mode rules: severities + filter + production.
        $ticketable = $by_webhook
            ? ($has_ticket || $is_clear || ($requested && $this->production->isProduction($alarm, $asset)))
            : in_array((int) $alarm['severity'], $this->cfg['ticket_severities'], true)
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
            'reopen_allowed'       => $by_webhook ? $requested : $this->filter->allows($alarm),
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
                if ($this->followupDue($existing, $now)) {
                    $this->tickets->followup($tid, Messages::cleared($alarm));
                    $this->store->markFollowup($id, $now);
                }
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
                if ($reborn_from > 0) {
                    // Its own closed ticket: the store row no longer holds that id, link it directly.
                    $this->tickets->link($tid, $reborn_from);
                    $this->tickets->followup($tid, Messages::related($reborn_from));
                    $stats['linked']++;
                } else {
                    $this->relink($tid, $alarm, $now, $stats);
                }
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

    /** The live end of a merge chain, or $tid itself when the chain is too long or loops. */
    private function followMerge(int $tid): int
    {
        $current = $tid;
        for ($hop = 0; $hop <= self::MERGE_HOPS; $hop++) {
            $status = $this->tickets->status($current);
            if ($status === null) {
                return $tid;        // §16.5: the target is gone, leave the alarm alone
            }
            if ($status !== TicketGateway::TRASHED) {
                return $current;
            }
            $next = $this->tickets->mergedInto($current);
            if ($next <= 0) {
                return $current;
            }
            $current = $next;
        }
        return $tid;
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
