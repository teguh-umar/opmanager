<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/** MySQL named lock - held per connection, released explicitly or when the connection ends. */
final class DbSyncLock implements SyncLock
{
    public function acquire(int $timeout_seconds): bool
    {
        global $DB;

        $result = $DB->doQuery(sprintf("SELECT GET_LOCK('%s', %d) AS l", self::NAME, max(0, $timeout_seconds)));
        $row    = $result ? $result->fetch_assoc() : null;
        return (int) ($row['l'] ?? 0) === 1;
    }

    public function release(): void
    {
        global $DB;

        $DB->doQuery(sprintf("SELECT RELEASE_LOCK('%s')", self::NAME));
    }
}
