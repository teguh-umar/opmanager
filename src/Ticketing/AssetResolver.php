<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface AssetResolver
{
    /** @return array{devices_id: int, itemtype: string, items_id: int} */
    public function resolve(array $alarm): array;
}
