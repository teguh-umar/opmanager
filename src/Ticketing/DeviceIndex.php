<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface DeviceIndex
{
    /** @return array{id: int, itemtype: string, items_id: int, match_state: string}|null */
    public function find(string $device_key): ?array;
}
