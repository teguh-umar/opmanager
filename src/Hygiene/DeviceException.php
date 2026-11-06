<?php

namespace GlpiPlugin\Opmanager\Hygiene;

use GlpiPlugin\Opmanager\RightDefinition;

/**
 * Exclusion of one OpManager device from both hygiene tickets (spec §5). Reason
 * required; who and when are recorded; revoking keeps the row as history.
 */
class DeviceException extends \CommonDBTM
{
    public const TABLE = 'glpi_plugin_opmanager_deviceexceptions';

    public static $rightname = RightDefinition::HYGIENE;

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Hygiene exception', 'Hygiene exceptions', $nb, 'opmanager');
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function validateReason(string $reason): ?string
    {
        $len = strlen(trim($reason));
        if ($len < 5 || $len > 1000) {
            return __('Give a reason of 5 to 1000 characters', 'opmanager');
        }
        return null;
    }

    /** @return string|null error, null when the exception was recorded */
    public static function except(string $device_key, string $reason, int $users_id): ?string
    {
        global $DB;
        $error = self::validateReason($reason);
        if ($error !== null) {
            return $error;
        }
        if ($device_key === '') {
            return __('Unknown device', 'opmanager');
        }
        if ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['device_key' => $device_key, 'is_active' => 1]])->current()) {
            return __('This device is already excluded', 'opmanager');
        }
        $id = (new self())->add([
            'device_key' => $device_key,
            'reason'     => trim($reason),
            'users_id'   => $users_id,
            'is_active'  => 1,
        ]);
        if (!$id) {
            return __('The exception could not be saved', 'opmanager');
        }
        $DB->update(DbHygieneStore::DEVICES, ['hygiene_state' => Classifier::EXCEPTED], ['device_key' => $device_key]);
        return null;
    }

    public static function revoke(string $device_key, int $users_id): bool
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['device_key' => $device_key, 'is_active' => 1]])->current();
        if (!$row) {
            return false;
        }
        (new self())->update([
            'id'         => (int) $row['id'],
            'is_active'  => 0,
            'revoked_by' => $users_id,
            'revoked_at' => (string) \Session::getCurrentTime(),
        ]);
        // Classified again by the next HygieneCheck run.
        $DB->update(DbHygieneStore::DEVICES, ['hygiene_state' => Classifier::NONE], ['device_key' => $device_key]);
        return true;
    }

    /** @return list<array<string, mixed>> newest first */
    public static function history(string $device_key): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['device_key' => $device_key], 'ORDER' => 'id DESC']), false);
    }
}
