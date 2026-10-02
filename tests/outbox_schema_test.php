<?php

require __DIR__ . '/bootstrap.php';

$install   = (string) file_get_contents(OPM_ROOT . '/install/install.php');
$uninstall = (string) file_get_contents(OPM_ROOT . '/install/uninstall.php');
$store     = (string) @file_get_contents(OPM_ROOT . '/src/Outbound/DbOutboxStore.php');

check('outbox table created', (bool) preg_match('/glpi_plugin_opmanager_outbox.*?`action` VARCHAR\(8\).*?`state` VARCHAR\(8\).*?`next_try_at` TIMESTAMP.*?KEY `due` \(`state`, `next_try_at`\)/s', $install));
check('outbox send cron registered every minute', (bool) preg_match("/CronTask::register\(Cron::class, 'OutboxSend', MINUTE_TIMESTAMP/", $install));
check('outbox dropped on uninstall', str_contains($uninstall, 'glpi_plugin_opmanager_outbox'));
check('store: pending oldest first', (bool) preg_match("/function pending.*?'state' => 'pending'.*?'ORDER'\\s+=> 'id',/s", $store));
check('store: clear dedupe counts only pending clears (reopened alarm can be cleared again)', (bool) preg_match("/function hasClear.*?'action' => 'clear', 'state' => 'pending'\]/s", $store));

check('store: pending acknowledge check', (bool) preg_match("/function hasPendingAck.*?'action' => 'ack', 'state' => 'pending'\]/s", $store));

done();
