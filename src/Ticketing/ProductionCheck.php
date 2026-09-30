<?php

namespace GlpiPlugin\Opmanager\Ticketing;

interface ProductionCheck
{
    /**
     * Whether an alarm concerns a production asset (user decision 2026-09-30:
     * tickets only for Critical alarms on production assets).
     *
     * @param array{devices_id: int, itemtype: string, items_id: int} $asset
     */
    public function isProduction(array $alarm, array $asset): bool;
}
