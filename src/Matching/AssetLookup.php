<?php

namespace GlpiPlugin\Opmanager\Matching;

interface AssetLookup
{
    /** @return list<array{itemtype: string, items_id: int}> live assets named exactly $name */
    public function byName(string $name): array;

    /** @return list<array{itemtype: string, items_id: int}> live assets owning IP $ip */
    public function byIp(string $ip): array;
}
