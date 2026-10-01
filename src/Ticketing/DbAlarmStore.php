<?php

namespace GlpiPlugin\Opmanager\Ticketing;

use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Opmanager\Alarm;

final class DbAlarmStore implements AlarmStore
{
    private const STORMS = 'glpi_plugin_opmanager_storms';

    public function find(string $alarm_key): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'tickets_id', 'severity', 'message', 'state', 'last_followup_at'],
            'FROM'   => Alarm::getTable(),
            'WHERE'  => ['alarm_key' => $alarm_key],
            'LIMIT'  => 1,
        ])->current();

        return $row ? [
            'id'         => (int) $row['id'],
            'tickets_id' => (int) $row['tickets_id'],
            'severity'   => (int) $row['severity'],
            'message'    => (string) $row['message'],
            'state'      => (string) $row['state'],
            'last_followup_at' => (string) ($row['last_followup_at'] ?? ''),
        ] : null;
    }

    public function save(array $alarm, array $asset, string $state, string $now): int
    {
        global $DB;

        $table  = Alarm::getTable();
        $fields = [
            'entity_key'                  => $alarm['entity_key'],
            'device_name'                 => $alarm['device_name'],
            'display_name'                => $alarm['display_name'],
            'ip_address'                  => $alarm['ip_address'],
            'category'                    => $alarm['category'],
            'alarm_code'                  => $alarm['alarm_code'],
            'event_type'                  => $alarm['event_type'],
            'severity'                    => (int) $alarm['severity'],
            'severity_label'              => $alarm['severity_label'],
            'message'                     => $alarm['message'],
            'state'                       => $state,
            'plugin_opmanager_devices_id' => $asset['devices_id'],
            'itemtype'                    => $asset['itemtype'],
            'items_id'                    => $asset['items_id'],
            'mod_time'                    => $alarm['mod_time'] !== '' ? $alarm['mod_time'] : null,
            'date_mod'                    => $now,
        ];

        $existing = $DB->request([
            'SELECT' => ['id', 'severity', 'message', 'state'],
            'FROM'   => $table,
            'WHERE'  => ['alarm_key' => $alarm['alarm_key']],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            $changed = (int) $existing['severity'] !== (int) $alarm['severity']
                || (string) $existing['message'] !== (string) $alarm['message']
                || (string) $existing['state'] !== $state;
            if ($changed) {
                $fields['last_change'] = $now;
            }
            if ($state === 'cleared' && $existing['state'] !== 'cleared') {
                $fields['cleared_at'] = $now;
            }
            if ($state === 'active') {
                $fields['cleared_at'] = null;
            }
            $DB->update($table, $fields, ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        $DB->insert($table, $fields + [
            'alarm_key'     => $alarm['alarm_key'],
            'first_seen'    => $now,
            'last_change'   => $now,
            'cleared_at'    => $state === 'cleared' ? $now : null,
            'date_creation' => $now,
        ]);
        return (int) $DB->insertId();
    }

    public function setTicket(int $id, int $tickets_id, string $role, string $now): void
    {
        global $DB;
        $DB->update(Alarm::getTable(), [
            'tickets_id'        => $tickets_id,
            'ticket_role'       => $role,
            'ticket_pending'    => 0,
            'ticket_created_at' => $now,
        ], ['id' => $id]);
    }

    public function markPending(int $id): void
    {
        global $DB;
        $DB->update(Alarm::getTable(), ['ticket_pending' => 1], ['id' => $id]);
    }

    public function activeNotSeen(array $alarm_keys): array
    {
        global $DB;

        $where = ['state' => 'active'];
        if ($alarm_keys !== []) {
            $where['NOT'] = ['alarm_key' => $alarm_keys];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'alarm_key', 'entity_key'], 'FROM' => Alarm::getTable(), 'WHERE' => $where]) as $row) {
            $out[] = ['id' => (int) $row['id'], 'alarm_key' => (string) $row['alarm_key'], 'entity_key' => (string) $row['entity_key']];
        }
        return $out;
    }

    public function ticketsForDevice(string $device_name): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'   => ['tickets_id'],
            'DISTINCT' => true,
            'FROM'     => Alarm::getTable(),
            'WHERE'    => ['device_name' => $device_name, 'tickets_id' => ['>', 0], 'ticket_role' => ['own', 'joined']],
            'ORDER'    => 'tickets_id DESC',
        ]) as $row) {
            $out[] = (int) $row['tickets_id'];
        }
        return $out;
    }

    public function allCleared(int $tickets_id): bool
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => Alarm::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id, 'state' => ['<>', 'cleared']],
        ])->current()['cpt'] === 0;
    }

    /**
     * Still-active alarms of a ticket, for the outbound notes/clears (Plan 3).
     *
     * @return list<array{alarm_key: string, entity_key: string}>
     */
    public static function activeForTicket(int $tickets_id): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['alarm_key', 'entity_key'],
            'FROM'   => Alarm::getTable(),
            'WHERE'  => ['tickets_id' => $tickets_id, 'state' => 'active', 'NOT' => ['entity_key' => '']],
            'ORDER'  => 'id',
        ]) as $r) {
            $out[] = ['alarm_key' => (string) $r['alarm_key'], 'entity_key' => (string) $r['entity_key']];
        }
        return $out;
    }

    public function ownTicketsSince(string $since): int
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => Alarm::getTable(),
            'WHERE' => ['ticket_role' => 'own', 'ticket_created_at' => ['>=', $since]],
        ])->current()['cpt'];
    }

    public function recentRelatedTickets(string $device_name, string $alarm_code, int $exclude_tickets_id): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT'   => ['tickets_id'],
            'DISTINCT' => true,
            'FROM'     => Alarm::getTable(),
            'WHERE'    => [
                'device_name' => $device_name,
                'alarm_code'  => $alarm_code,
                'tickets_id'  => ['>', 0],
                'NOT'         => ['tickets_id' => $exclude_tickets_id],
            ],
            'ORDER'    => 'tickets_id DESC',
        ]) as $row) {
            $out[] = (int) $row['tickets_id'];
        }
        return $out;
    }

    public function activeStorm(string $now, int $window_minutes): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'tickets_id', 'last_hit_at'],
            'FROM'   => self::STORMS,
            'WHERE'  => ['ended_at' => null],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ])->current();
        if (!$row) {
            return 0;
        }
        $idle_since = date('Y-m-d H:i:s', (int) strtotime($now) - $window_minutes * 60);
        if ((string) $row['last_hit_at'] < $idle_since) {
            $DB->update(self::STORMS, ['ended_at' => $now], ['id' => (int) $row['id']]);
            return 0;
        }
        return (int) $row['tickets_id'];
    }

    public function startStorm(int $tickets_id, string $now): void
    {
        global $DB;
        $DB->insert(self::STORMS, ['tickets_id' => $tickets_id, 'alarm_count' => 1, 'started_at' => $now, 'last_hit_at' => $now]);
    }

    public function endStorm(int $tickets_id, string $now): void
    {
        global $DB;
        $DB->update(self::STORMS, ['ended_at' => $now], ['tickets_id' => $tickets_id, 'ended_at' => null]);
    }

    public function markFollowup(int $id, string $now): void
    {
        global $DB;
        $DB->update(Alarm::getTable(), ['last_followup_at' => $now], ['id' => $id]);
    }

    public function hitStorm(int $tickets_id, string $now): void
    {
        global $DB;
        $DB->update(self::STORMS, [
            'last_hit_at' => $now,
            'alarm_count' => new QueryExpression($DB->quoteName('alarm_count') . ' + 1'),
        ], ['tickets_id' => $tickets_id, 'ended_at' => null]);
    }
}
