<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface AlarmStore
{
    /** @return array{id: int, tickets_id: int, severity: int, message: string, state: string}|null */
    public function find(string $alarm_key): ?array;

    /** Upsert by alarm_key; keeps tickets_id; returns the row id. */
    public function save(array $alarm, array $asset, string $state, string $now): int;

    public function setTicket(int $id, int $tickets_id, string $role, string $now): void;

    public function markPending(int $id): void;

    /** @param string[] $alarm_keys @return list<array{id: int, alarm_key: string, entity_key: string}> */
    public function activeNotSeen(array $alarm_keys): array;

    /** @return list<int> tickets of own/joined alarms of this device, newest first */
    public function ticketsForDevice(string $device_name): array;

    public function allCleared(int $tickets_id): bool;

    public function ownTicketsSince(string $since): int;

    /** @return list<int> */
    public function recentRelatedTickets(string $device_name, string $alarm_code, int $exclude_tickets_id): array;

    /** Ends storms idle for a whole window; returns the active storm ticket or 0. */
    public function activeStorm(string $now, int $window_minutes): int;

    public function startStorm(int $tickets_id, string $now): void;

    public function hitStorm(int $tickets_id, string $now): void;
}
