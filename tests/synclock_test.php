<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/SyncLock.php';

$src = (string) @file_get_contents(OPM_ROOT . '/src/Ticketing/DbSyncLock.php');
check('lock uses MySQL GET_LOCK, named, default the shared alarm-sync name', str_contains($src, 'GET_LOCK') && str_contains($src, 'RELEASE_LOCK') && (bool) preg_match('/__construct\\(private string \\$name = self::NAME\\)/', $src) && substr_count($src, '$this->name') === 2);
check('shared lock name', \GlpiPlugin\Opmanager\Ticketing\SyncLock::NAME === 'opmanager_alarm_sync');
$cron = (string) file_get_contents(OPM_ROOT . '/src/Cron.php');
check('cron AlarmSync takes the lock and releases it in finally', (bool) preg_match('/cronAlarmSync.*?->acquire\(60\).*?finally.*?->release\(\)/s', $cron));

// Plan 3
check('outbox cron sends with the configured retry limit', (bool) preg_match('/function cronOutboxSend.*?new Sender\(new DbOutboxStore\(\), \$client, \$cfg\[\'outbound_max_attempts\'\]\)/s', $cron));
check('outbox cron described', str_contains($cron, "'OutboxSend' =>"));
$alarm = (string) file_get_contents(OPM_ROOT . '/src/Alarm.php');
check('alarm page title is device + alarm id, not N/A', (bool) preg_match("/function getName\(.*?display_name.*?alarm_key/s", $alarm));
check('alarm page shows the entity', str_contains($alarm, "'entity_key'"));

done();
