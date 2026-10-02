<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Deferred webhook calls (spec §16.4).
 *
 * Tickets created by webhooks are answered with 202 and stored here;
 * the next AlarmSync cron processes them as if they had come from OpManager.
 */
interface TicketRequestStore
{
    /**
     * Upsert by alarm_key; refreshes the time.
     *
     * @param string $alarm_key The OpManager alarm key (external id)
     * @param string $entity_key The entity key (for context in ticket creation)
     */
    public function record(string $alarm_key, string $entity_key): void;

    /**
     * Get all alarm keys with an open request.
     *
     * @return list<string> The alarm keys
     */
    public function keys(): array;

    /**
     * Delete a request by alarm key.
     */
    public function delete(string $alarm_key): void;

    /**
     * Deletes requests last recorded before $before ('Y-m-d H:i:s').
     *
     * Used by cron to clean up stale webhook requests.
     */
    public function purgeBefore(string $before): void;
}
