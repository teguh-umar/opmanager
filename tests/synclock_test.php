<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/SyncLock.php';

$src = (string) @file_get_contents(OPM_ROOT . '/src/Ticketing/DbSyncLock.php');
check('lock uses MySQL GET_LOCK with the shared name', str_contains($src, 'GET_LOCK') && str_contains($src, 'RELEASE_LOCK') && str_contains($src, 'self::NAME'));
check('shared lock name', \GlpiPlugin\Opmanager\Ticketing\SyncLock::NAME === 'opmanager_alarm_sync');
$cron = (string) file_get_contents(OPM_ROOT . '/src/Cron.php');
check('cron AlarmSync takes the lock and releases it in finally', (bool) preg_match('/cronAlarmSync.*?->acquire\(60\).*?finally.*?->release\(\)/s', $cron));

done();
