<?php

namespace GlpiPlugin\Opmanager\Outbound;

final class DbOutboxStore implements OutboxStore
{
    public const TABLE = 'glpi_plugin_opmanager_outbox';

    public function enqueue(array $row, string $now): void
    {
        global $DB;

        $DB->insert(self::TABLE, $row + [
            'state'         => 'pending',
            'attempts'      => 0,
            'next_try_at'   => $now,
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
    }

    public function hasClear(string $alarm_key): bool
    {
        global $DB;

        return count($DB->request([
            'FROM'  => self::TABLE,
            // Only a waiting clear blocks a new one: after a sent clear the same alarmId can
            // come back (AlarmSync REOPEN) and must be clearable again (final review I-1).
            'WHERE' => ['alarm_key' => $alarm_key, 'action' => 'clear', 'state' => 'pending'],
            'LIMIT' => 1,
        ])) > 0;
    }

    public function hasPendingAck(string $alarm_key): bool
    {
        global $DB;

        return count($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['alarm_key' => $alarm_key, 'action' => 'ack', 'state' => 'pending'],
            'LIMIT' => 1,
        ])) > 0;
    }

    public function pending(int $limit): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'alarm_key', 'entity_key', 'action', 'payload', 'attempts', 'next_try_at'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['state' => 'pending'],
            'ORDER'  => 'id',
            'LIMIT'  => $limit,
        ]) as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'alarm_key'   => (string) $r['alarm_key'],
                'entity_key'  => (string) $r['entity_key'],
                'action'      => (string) $r['action'],
                'payload'     => (string) $r['payload'],
                'attempts'    => (int) $r['attempts'],
                'next_try_at' => (string) ($r['next_try_at'] ?? ''),
            ];
        }
        return $out;
    }

    public function markSent(int $id, string $now): void
    {
        global $DB;

        $DB->update(self::TABLE, ['state' => 'sent', 'last_error' => '', 'date_mod' => $now], ['id' => $id]);
    }

    public function markRetry(int $id, int $attempts, string $next_try_at, string $error, string $now): void
    {
        global $DB;

        $DB->update(self::TABLE, ['attempts' => $attempts, 'next_try_at' => $next_try_at, 'last_error' => $error, 'date_mod' => $now], ['id' => $id]);
    }

    public function markFailed(int $id, int $attempts, string $error, string $now): void
    {
        global $DB;

        $DB->update(self::TABLE, ['state' => 'failed', 'attempts' => $attempts, 'last_error' => $error, 'date_mod' => $now], ['id' => $id]);
    }

    public function summary(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return ['pending' => 0, 'failed' => 0, 'last_error' => ''];
        }
        $count = static fn (string $state): int => count($DB->request(['FROM' => self::TABLE, 'WHERE' => ['state' => $state]]));
        $last  = $DB->request([
            'SELECT' => ['last_error'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['state' => ['pending', 'failed'], 'NOT' => ['last_error' => '']],
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => 1,
        ])->current();
        return ['pending' => $count('pending'), 'failed' => $count('failed'), 'last_error' => (string) ($last['last_error'] ?? '')];
    }
}
