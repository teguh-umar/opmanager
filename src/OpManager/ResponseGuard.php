<?php

namespace GlpiPlugin\Opmanager\OpManager;

/**
 * Shared handling of OpManager JSON responses. OpManager reports failures as
 * HTTP 200 with {"error":{"code","message"}} (spec §14), so every response goes
 * through decode() before anyone reads it.
 */
final class ResponseGuard
{
    private const WRAPPERS = ['rows', 'data', 'devices', 'alarms', 'result'];

    public static function decode(string $json, string $what): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ClientException("OpManager {$what} is not valid JSON");
        }
        if (isset($data['error'])) {
            throw new ClientException(self::errorMessage($data['error']));
        }
        return $data;
    }

    public static function unwrapList(array $data, string $what): array
    {
        foreach (self::WRAPPERS as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                $data = $data[$wrapper];
                break;
            }
        }
        if (!array_is_list($data)) {
            throw new ClientException("OpManager {$what} has an unexpected shape");
        }
        return $data;
    }

    /** @param string[] $keys */
    public static function first(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }

    public static function errorMessage(mixed $error): string
    {
        if (is_array($error)) {
            $code    = is_scalar($error['code'] ?? null) ? (string) $error['code'] : '?';
            $message = is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'no message';
            return sprintf('OpManager error %s: %s', $code, $message);
        }
        return 'OpManager error: ' . (is_scalar($error) ? (string) $error : 'unknown');
    }
}
