<?php

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI {}
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Device.php';

use GlpiPlugin\Opmanager\Device;

$allowed = ['Computer', 'NetworkEquipment', 'Glpi\\CustomAsset\\SecurityAsset'];
check('pin to an enabled custom asset type is valid', Device::validatePin('Glpi\\CustomAsset\\SecurityAsset', 5, $allowed) === null);
check('pin to a disabled type is refused', Device::validatePin('Printer', 5, $allowed) !== null);
check('pin without an asset is refused', Device::validatePin('Computer', 0, $allowed) !== null);
check('three match states labelled', array_keys(Device::matchStateLabels()) === ['matched', 'review', 'unmatched']);

$form = (string) @file_get_contents(OPM_ROOT . '/front/device.form.php');
check('pin requires the config UPDATE right', (bool) preg_match('/isset\(\$_POST\[.pin.\]\).*?checkRight\(RightDefinition::CONFIG, UPDATE\)/s', $form));
check('unpin requires the config UPDATE right', (bool) preg_match('/isset\(\$_POST\[.unpin.\]\).*?checkRight\(RightDefinition::CONFIG, UPDATE\)/s', $form));
check('detail uses displayFullPageForItem', str_contains($form, 'Device::displayFullPageForItem('));

$cron = (string) @file_get_contents(OPM_ROOT . '/src/Cron.php');
check('AlarmSync cron exists', str_contains($cron, 'public static function cronAlarmSync(\CronTask $task): int'));
check('AlarmSync keeps the error visible', str_contains($cron, "'last_alarm_sync_error'"));
$install = (string) file_get_contents(OPM_ROOT . '/install/install.php');
check('AlarmSync registered in CLI mode from the interval constant', str_contains($install, "'AlarmSync', Config::ALARM_SYNC_MINUTES * MINUTE_TIMESTAMP"));
check('menu has an alarm entry', str_contains((string) file_get_contents(OPM_ROOT . '/src/Menu.php'), "'alarm'"));
check('alarm list page exists', is_file(OPM_ROOT . '/front/alarm.php'));

done();
