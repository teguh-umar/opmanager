<?php

namespace GlpiPlugin\Opmanager\OpManager;

/** Writes back to OpManager (Plan 3). Both throw ClientException on any failure. */
interface AlarmWriter
{
    public function addNote(string $entity, string $notes): void;

    public function clearAlarm(string $entity): void;
}
