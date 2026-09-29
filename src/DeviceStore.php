<?php

namespace GlpiPlugin\Opmanager;

interface DeviceStore
{
    /**
     * Insert or refresh the OpManager-side columns of one device. Never touches
     * itemtype / items_id / match_state / match_source / is_manual.
     *
     * @param array{device_key: string, name: string, display_name: string, ip_address: string,
     *              device_type: string, device_category: string, availability: string} $device
     * @return array{id: int, is_manual: bool}
     */
    public function upsert(array $device, string $now): array;

    /** @param array{state: string, itemtype: string, items_id: int, source: string} $result */
    public function setMatch(int $id, array $result): void;
}
