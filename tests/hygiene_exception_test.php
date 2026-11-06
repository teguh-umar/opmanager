<?php

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI {}
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Hygiene/DeviceException.php';

use GlpiPlugin\Opmanager\Hygiene\DeviceException;

check('empty reason refused', DeviceException::validateReason('') !== null);
check('blank reason refused', DeviceException::validateReason("   \n ") !== null);
check('too short reason refused', DeviceException::validateReason('   ok  ') !== null);
check('real reason accepted', DeviceException::validateReason('ISP-owned router') === null);
check('reason over 1000 chars refused', DeviceException::validateReason(str_repeat('x', 1001)) !== null);
check('exception right is the hygiene right', DeviceException::$rightname === 'plugin_opmanager_hygiene');
check('exceptions are never deleted from the UI', !DeviceException::canDelete() && !DeviceException::canPurge());

$src = (string) file_get_contents(OPM_ROOT . '/src/Hygiene/DeviceException.php');
check('revoke keeps the row (no delete)', !preg_match('/DB->delete\(|->delete\(\[/', $src) && str_contains($src, "'is_active'  => 0"));
check('one active exception per device', (bool) preg_match('/function except\(.*?is_active.*?1/s', $src));
check('device hygiene_state updated at once', str_contains($src, "'hygiene_state'"));

done();
