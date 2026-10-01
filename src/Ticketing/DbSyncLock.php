<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * MySQL named lock - held per connection, released explicitly or when the connection ends.
 * The name is always a plugin constant (alarm sync, outbox), never user input.
 */
final class DbSyncLock implements SyncLock
{
    public function __construct(private string $name = self::NAME)
    {
    }

    public function acquire(int $timeout_seconds): bool
    {
        global $DB;

        $result = $DB->doQuery(sprintf("SELECT GET_LOCK('%s', %d) AS l", $this->name, max(0, $timeout_seconds)));
        $row    = $result ? $result->fetch_assoc() : null;
        return (int) ($row['l'] ?? 0) === 1;
    }

    public function release(): void
    {
        global $DB;

        $DB->doQuery(sprintf("SELECT RELEASE_LOCK('%s')", $this->name));
    }
}
