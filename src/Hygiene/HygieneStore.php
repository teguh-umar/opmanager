<?php

namespace GlpiPlugin\Opmanager\Hygiene;

interface HygieneStore
{
    /**
     * @return list<array{id: int, device_key: string, name: string, display_name: string, ip_address: string,
     *   device_category: string, availability: string, itemtype: string, items_id: int, match_state: string,
     *   last_seen: ?string, liveness_state: string, asset_label?: string}>
     */
    public function devices(): array;

    /** @return list<string> device_key of every active exception */
    public function activeExceptionKeys(): array;

    /** @param array{liveness_state: string, liveness_source: ?string, liveness_detail: ?string, liveness_checked_at: ?string, hygiene_state: string} $f null source/detail/checked_at = keep the stored value */
    public function saveDevice(int $id, array $f): void;

    /** @return array{id: int, tickets_id: int}|null latest hygiene ticket row of the flow */
    public function latestTicket(string $flow): ?array;

    /** @return array<string, array{state: string, resolved: bool}> */
    public function reportedItems(int $hygienetickets_id): array;

    /** @return int new hygienetickets row id */
    public function recordTicket(string $flow, int $tickets_id, string $now): int;

    /** @param array{new: array<string, string>, changed: array<string, array{0: string, 1: string}>, resolved: list<string>} $plan */
    public function recordItems(int $hygienetickets_id, array $plan, string $now): void;

    /** @return array<string, int> hygiene_state => device count */
    public function counts(): array;
}
