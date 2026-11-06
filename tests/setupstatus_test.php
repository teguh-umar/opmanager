<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/SetupStatus.php';

use GlpiPlugin\Opmanager\SetupStatus;

$now  = '2026-10-02 12:00:00';
$good = ['api_key_set' => true, 'last_device_sync' => '2026-10-02 11:30:00', 'last_device_sync_error' => '', 'stale_minutes' => 90,
         'last_alarm_sync' => '2026-10-02 11:55:00', 'last_alarm_sync_error' => '', 'alarm_minutes' => 10,
         'webhook_token_set' => true, 'outbox_pending' => 0, 'outbox_failed' => 0];
$lv = static fn (array $items) => array_column($items, 'level', 'key');

check('all good: five ok badges', $lv(SetupStatus::items($good, $now)) === ['api' => 'ok', 'devices' => 'ok', 'alarms' => 'ok', 'webhook' => 'ok', 'outbox' => 'ok']);
check('no api key is an error', $lv(SetupStatus::items(['api_key_set' => false] + $good, $now))['api'] === 'error');
check('device sync error wins', $lv(SetupStatus::items(['last_device_sync_error' => 'boom'] + $good, $now))['devices'] === 'error');
check('device data older than the stale threshold warns', $lv(SetupStatus::items(['last_device_sync' => '2026-10-02 10:00:00'] + $good, $now))['devices'] === 'warn');
check('never synced warns', $lv(SetupStatus::items(['last_alarm_sync' => ''] + $good, $now))['alarms'] === 'warn');
check('alarm sync older than 3 intervals warns', $lv(SetupStatus::items(['last_alarm_sync' => '2026-10-02 11:25:00'] + $good, $now))['alarms'] === 'warn');
check('alarm sync error', $lv(SetupStatus::items(['last_alarm_sync_error' => 'x'] + $good, $now))['alarms'] === 'error');
check('no webhook token warns', $lv(SetupStatus::items(['webhook_token_set' => false] + $good, $now))['webhook'] === 'warn');
check('pending outbox warns, failed is an error', $lv(SetupStatus::items(['outbox_pending' => 2] + $good, $now))['outbox'] === 'warn'
    && $lv(SetupStatus::items(['outbox_failed' => 1, 'outbox_pending' => 2] + $good, $now))['outbox'] === 'error');
check('texts never empty', !in_array('', array_column(SetupStatus::items($good, $now), 'text'), true));

// v1.1.0 (D32): without a token the webhook mode creates no ticket at all.
check('webhook mode without token is an error', $lv(SetupStatus::items(['webhook_token_set' => false, 'ticket_source' => 'webhook'] + $good, $now))['webhook'] === 'error');
check('rules mode without token stays a warning', $lv(SetupStatus::items(['webhook_token_set' => false, 'ticket_source' => 'rules'] + $good, $now))['webhook'] === 'warn');


// v1.3.0 (D34): hygiene badge, only when the caller passes hygiene fields.
check('no hygiene fields: no hygiene badge', !array_key_exists('hygiene', $lv(SetupStatus::items($good, $now))));
$hy = ['hygiene_enabled' => true, 'last_hygiene_run' => '2026-10-02 06:00:00', 'last_hygiene_error' => ''];
check('hygiene ran today: ok', ($lv(SetupStatus::items($hy + $good, $now))['hygiene'] ?? null) === 'ok');
check('hygiene error: error', ($lv(SetupStatus::items(['last_hygiene_error' => 'provider mist: x'] + $hy + $good, $now))['hygiene'] ?? null) === 'error');
check('hygiene never ran: warn', ($lv(SetupStatus::items(['last_hygiene_run' => ''] + $hy + $good, $now))['hygiene'] ?? null) === 'warn');
$off = array_column(SetupStatus::items(['hygiene_enabled' => false] + $hy + $good, $now), null, 'key')['hygiene'] ?? [];
check('hygiene tickets off: warn with text', ($off['level'] ?? null) === 'warn' && ($off['text'] ?? null) === 'tickets off');
check('hygiene older than two days: warn', ($lv(SetupStatus::items(['last_hygiene_run' => '2026-09-29 06:00:00'] + $hy + $good, $now))['hygiene'] ?? null) === 'warn');

done();
