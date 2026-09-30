<?php

namespace GlpiPlugin\Opmanager\OpManager;

interface AlarmSource
{
    /** @return list<array<string, mixed>> alarms not yet cleared (alertType=ActiveAlarms) @throws ClientException */
    public function listActiveAlarms(): array;

    /** @return array<string, mixed>|null the alarm with this entity, whatever its severity @throws ClientException */
    public function alarmByEntity(string $entity): ?array;
}
