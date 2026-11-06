<?php

namespace GlpiPlugin\Opmanager\Hygiene;

use GlpiPlugin\Opmanager\Ticketing\TicketGateway;

/**
 * Pure: what one hygiene flow does this run (spec §4). One ticket per flow; while it
 * is open only the differences go into a followup; a solved, closed, trashed or
 * purged ticket means a new ticket with the full list.
 */
final class TicketPlanner
{
    /**
     * @param array<string, string>                              $current  device_key => state, this flow only
     * @param array<string, array{state: string, resolved: bool}> $reported items of the flow's latest ticket
     * @param int|null                                           $ticket_status GLPI status, TicketGateway::TRASHED, or null
     * @return array{action: string, new: array<string, string>, changed: array<string, array{0: string, 1: string}>, resolved: list<string>}
     */
    public static function plan(array $current, array $reported, ?int $ticket_status): array
    {
        $out = ['action' => 'none', 'new' => [], 'changed' => [], 'resolved' => []];

        if (self::isClosed($ticket_status)) {
            if ($current !== []) {
                $out['action'] = 'create';
                $out['new']    = $current;
            }
            return $out;
        }

        foreach ($current as $key => $state) {
            $item = $reported[$key] ?? null;
            if ($item === null || $item['resolved']) {
                $out['new'][$key] = $state;
            } elseif ($item['state'] !== $state) {
                $out['changed'][$key] = [$item['state'], $state];
            }
        }
        foreach ($reported as $key => $item) {
            if (!$item['resolved'] && !array_key_exists($key, $current)) {
                $out['resolved'][] = (string) $key;
            }
        }
        if ($out['new'] !== [] || $out['changed'] !== [] || $out['resolved'] !== []) {
            $out['action'] = 'followup';
        }
        return $out;
    }

    public static function isClosed(?int $status): bool
    {
        return $status === null || in_array($status, [5, 6, TicketGateway::TRASHED], true);
    }
}
