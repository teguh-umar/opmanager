<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Spec §16.4: one row per alarm whose webhook was answered 202.
 */
final class DbTicketRequestStore implements TicketRequestStore
{
    public const TABLE = 'glpi_plugin_opmanager_ticketrequests';

    public function record(string $alarm_key, string $entity_key): void
    {
        global $DB;
        $now = (string) \Session::getCurrentTime();
        if ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['alarm_key' => $alarm_key], 'LIMIT' => 1])->count() > 0) {
            $DB->update(self::TABLE, ['entity_key' => $entity_key, 'date_mod' => $now], ['alarm_key' => $alarm_key]);
            return;
        }
        $DB->insert(self::TABLE, ['alarm_key' => $alarm_key, 'entity_key' => $entity_key, 'date_creation' => $now, 'date_mod' => $now]);
    }

    public function keys(): array
    {
        global $DB;
        $keys = [];
        foreach ($DB->request(['SELECT' => ['alarm_key'], 'FROM' => self::TABLE]) as $row) {
            $keys[] = (string) $row['alarm_key'];
        }
        return $keys;
    }

    public function delete(string $alarm_key): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['alarm_key' => $alarm_key]);
    }

    public function purgeBefore(string $before): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['date_mod' => ['<', $before]]);
    }
}
