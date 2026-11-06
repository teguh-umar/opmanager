<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/** HygieneStore on the plugin's own tables (spec §8). Writes plugin tables only. */
final class DbHygieneStore implements HygieneStore
{
    public const DEVICES    = 'glpi_plugin_opmanager_devices';
    public const EXCEPTIONS = 'glpi_plugin_opmanager_deviceexceptions';
    public const TICKETS    = 'glpi_plugin_opmanager_hygienetickets';
    public const ITEMS      = 'glpi_plugin_opmanager_hygieneitems';

    public function devices(): array
    {
        global $DB;
        $out = [];
        // Oldest liveness check first, so a spent probe budget skips different devices each day (final review #4b).
        foreach ($DB->request(['FROM' => self::DEVICES, 'ORDER' => ['liveness_checked_at ASC', 'id']]) as $row) {
            $row['id']       = (int) $row['id'];
            $row['items_id'] = (int) $row['items_id'];
            if ($row['match_state'] === 'matched' && $row['itemtype'] !== '' && $row['items_id'] > 0) {
                $asset = getItemForItemtype($row['itemtype']);
                if ($asset instanceof \CommonDBTM && $asset->getFromDB($row['items_id'])) {
                    $row['asset_label'] = (string) $asset->getName();
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    public function activeExceptionKeys(): array
    {
        global $DB;
        $keys = [];
        foreach ($DB->request(['SELECT' => ['device_key'], 'FROM' => self::EXCEPTIONS, 'WHERE' => ['is_active' => 1]]) as $row) {
            $keys[] = (string) $row['device_key'];
        }
        return $keys;
    }

    public function saveDevice(int $id, array $f): void
    {
        global $DB;
        $fields = ['liveness_state' => $f['liveness_state'], 'hygiene_state' => $f['hygiene_state']];
        foreach (['liveness_source', 'liveness_detail', 'liveness_checked_at'] as $key) {
            if ($f[$key] !== null) {
                $fields[$key] = $f[$key];
            }
        }
        $DB->update(self::DEVICES, $fields, ['id' => $id]);
    }

    public function latestTicket(string $flow): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TICKETS, 'WHERE' => ['flow' => $flow], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        return $row ? ['id' => (int) $row['id'], 'tickets_id' => (int) $row['tickets_id']] : null;
    }

    public function reportedItems(int $hygienetickets_id): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::ITEMS, 'WHERE' => ['plugin_opmanager_hygienetickets_id' => $hygienetickets_id]]) as $row) {
            $out[(string) $row['device_key']] = ['state' => (string) $row['hygiene_state'], 'resolved' => $row['resolved_at'] !== null];
        }
        return $out;
    }

    public function recordTicket(string $flow, int $tickets_id, string $now): int
    {
        global $DB;
        $DB->insert(self::TICKETS, ['flow' => $flow, 'tickets_id' => $tickets_id, 'last_report_at' => $now, 'date_creation' => $now, 'date_mod' => $now]);
        return (int) $DB->insertId();
    }

    public function recordItems(int $hygienetickets_id, array $plan, string $now): void
    {
        global $DB;
        $where = static fn (string $key): array => ['plugin_opmanager_hygienetickets_id' => $hygienetickets_id, 'device_key' => $key];
        foreach ($plan['new'] as $key => $state) {
            $key = (string) $key;
            if ($DB->request(['SELECT' => ['id'], 'FROM' => self::ITEMS, 'WHERE' => $where($key)])->current()) {
                $DB->update(self::ITEMS, ['hygiene_state' => $state, 'reported_at' => $now, 'changed_at' => null, 'resolved_at' => null], $where($key));
            } else {
                $DB->insert(self::ITEMS, $where($key) + ['hygiene_state' => $state, 'reported_at' => $now]);
            }
        }
        foreach ($plan['changed'] as $key => [, $state]) {
            $DB->update(self::ITEMS, ['hygiene_state' => $state, 'changed_at' => $now], $where((string) $key));
        }
        foreach ($plan['resolved'] as $key) {
            $DB->update(self::ITEMS, ['resolved_at' => $now], $where((string) $key));
        }
        $DB->update(self::TICKETS, ['last_report_at' => $now, 'date_mod' => $now], ['id' => $hygienetickets_id]);
    }

    public function counts(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['hygiene_state', 'COUNT' => 'id AS n'],
            'FROM'   => self::DEVICES,
            'WHERE'  => ['NOT' => ['hygiene_state' => '']],
            'GROUPBY' => 'hygiene_state',
        ]) as $row) {
            $out[(string) $row['hygiene_state']] = (int) $row['n'];
        }
        return $out;
    }

    /** Open hygiene tickets whose latest report lists this device (spec §6 form block). @return list<int> */
    public static function openTicketsFor(string $device_key): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request([
            'SELECT'     => ['t.tickets_id'],
            'FROM'       => self::ITEMS . ' AS i',
            'INNER JOIN' => [self::TICKETS . ' AS t' => ['ON' => ['i' => 'plugin_opmanager_hygienetickets_id', 't' => 'id']]],
            'WHERE'      => ['i.device_key' => $device_key, 'i.resolved_at' => null],
        ]) as $row) {
            $ticket = new \Ticket();
            if ($ticket->getFromDB((int) $row['tickets_id']) && empty($ticket->fields['is_deleted']) && !in_array((int) $ticket->fields['status'], [5, 6], true)) {
                $ids[] = (int) $row['tickets_id'];
            }
        }
        return $ids;
    }
}
