<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface TicketGateway
{
    /** status() value for a ticket in the GLPI trash (also what a merge leaves behind). */
    public const TRASHED = -1;

    /** Ticket status, TRASHED when it is in the trash, or null when the row is gone (purged). */
    public function status(int $tickets_id): ?int;

    /** @return int new ticket id, 0 on failure (e.g. a mandatory template field) */
    public function create(array $alarm, array $asset): int;

    public function createStorm(array $alarm, string $now): int;

    public function followup(int $tickets_id, string $text): void;

    public function reopen(int $tickets_id, string $text): void;

    /** No-op when the ticket is already Solved/Closed. */
    public function solve(int $tickets_id, string $text): void;

    public function link(int $new_tickets_id, int $old_tickets_id): void;

    /** Takes the ticket out of the GLPI trash. */
    public function restore(int $tickets_id): void;

    /** The ticket a merge moved this one into (Ticket_Ticket SON_OF), 0 when not merged. */
    public function mergedInto(int $tickets_id): int;

    /** Solved or Closed at or after $since. */
    public function closedSince(int $tickets_id, string $since): bool;
}
