<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/** Built-in provider: last contact of the GLPI agent of the matched asset (spec §3). */
final class AgentProvider
{
    public function __construct(private int $stale_days)
    {
    }

    /** @return array{verdict: string, detail: string}|null */
    public function __invoke(array $device, string $now): ?array
    {
        if (($device['match_state'] ?? '') !== 'matched' || (string) ($device['itemtype'] ?? '') === '' || (int) ($device['items_id'] ?? 0) <= 0) {
            return null;
        }
        global $DB;
        $row = $DB->request([
            'SELECT' => ['last_contact'],
            'FROM'   => 'glpi_agents',
            'WHERE'  => ['itemtype' => (string) $device['itemtype'], 'items_id' => (int) $device['items_id']],
            'ORDER'  => 'last_contact DESC',
            'LIMIT'  => 1,
        ])->current();
        return Liveness::fromAgent($row ? (string) ($row['last_contact'] ?? '') : null, $now, $this->stale_days);
    }
}
