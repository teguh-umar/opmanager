<?php

namespace GlpiPlugin\Opmanager;

/** Pure: the health badges at the top of the Setup section (spec §15.2). */
final class SetupStatus
{
    /**
     * @param array{api_key_set: bool, last_device_sync: string, last_device_sync_error: string, stale_minutes: int,
     *   last_alarm_sync: string, last_alarm_sync_error: string, alarm_minutes: int, webhook_token_set: bool,
     *   outbox_pending: int, outbox_failed: int} $f
     * @return list<array{key: string, label: string, level: string, text: string}>
     */
    public static function items(array $f, string $now): array
    {
        $age = static function (string $when) use ($now): ?int {
            return $when === '' ? null : intdiv(max(0, strtotime($now) - strtotime($when)), 60);
        };
        $sync = static function (string $key, string $label, string $last, string $error, int $limit) use ($age): array {
            $mins = $age($last);
            if ($error !== '') {
                return ['key' => $key, 'label' => $label, 'level' => 'error', 'text' => $error];
            }
            if ($mins === null) {
                return ['key' => $key, 'label' => $label, 'level' => 'warn', 'text' => __('never ran', 'opmanager')];
            }
            return ['key' => $key, 'label' => $label, 'level' => $mins > $limit ? 'warn' : 'ok', 'text' => sprintf(__('%d min ago', 'opmanager'), $mins)];
        };

        $outbox = $f['outbox_failed'] > 0 ? 'error' : ($f['outbox_pending'] > 0 ? 'warn' : 'ok');
        return [
            ['key' => 'api', 'label' => __('API key', 'opmanager'), 'level' => $f['api_key_set'] ? 'ok' : 'error',
             'text' => $f['api_key_set'] ? __('stored', 'opmanager') : __('not set', 'opmanager')],
            $sync('devices', __('Device sync', 'opmanager'), $f['last_device_sync'], $f['last_device_sync_error'], $f['stale_minutes']),
            $sync('alarms', __('Alarm sync', 'opmanager'), $f['last_alarm_sync'], $f['last_alarm_sync_error'], 3 * $f['alarm_minutes']),
            ['key' => 'webhook', 'label' => __('Webhook', 'opmanager'), 'level' => $f['webhook_token_set'] ? 'ok' : 'warn',
             'text' => $f['webhook_token_set'] ? __('token stored', 'opmanager') : __('no token - disabled', 'opmanager')],
            ['key' => 'outbox', 'label' => __('To OpManager', 'opmanager'), 'level' => $outbox,
             'text' => sprintf(__('%d waiting, %d given up', 'opmanager'), $f['outbox_pending'], $f['outbox_failed'])],
        ];
    }
}
