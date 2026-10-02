<?php

namespace GlpiPlugin\Opmanager\OpManager;

/** Writes back to OpManager (Plan 3). Both throw ClientException on any failure. */
interface AlarmWriter
{
    public function addNote(string $entity, string $notes): void;

    public function clearAlarm(string $entity): void;

    /** Repeating it on an acknowledged alarm is harmless: not a toggle (spec §14, D27). */
    public function acknowledgeAlarm(string $entity): void;
}
