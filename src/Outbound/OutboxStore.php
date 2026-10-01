<?php

namespace GlpiPlugin\Opmanager\Outbound;

/** Queue of notes/clears waiting to be sent to OpManager (Plan 3, spec §8). */
interface OutboxStore
{
    /** @param array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string} $row */
    public function enqueue(array $row, string $now): void;

    /** A clear for this alarm is still waiting: do not queue a second one. */
    public function hasClear(string $alarm_key): bool;

    /** @return list<array{id: int, alarm_key: string, entity_key: string, action: string, payload: string, attempts: int, next_try_at: string}> oldest first */
    public function pending(int $limit): array;

    public function markSent(int $id, string $now): void;

    public function markRetry(int $id, int $attempts, string $next_try_at, string $error, string $now): void;

    public function markFailed(int $id, int $attempts, string $error, string $now): void;

    /** @return array{pending: int, failed: int, last_error: string} */
    public function summary(): array;
}
