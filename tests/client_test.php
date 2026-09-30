<?php

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'ResponseGuard', 'DeviceSource', 'AlarmSource', 'DeviceParser', 'AlarmParser', 'Client'] as $f) {
    require OPM_ROOT . "/src/OpManager/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\OpManager\DeviceParser;

$list = '[{"deviceName":"10.20.1.1","displayName":"core-sw-01","ipaddress":"10.20.1.1","type":"Cisco 9300","category":"Switch","statusStr":"Clear"},'
      . '{"displayName":"no-key"},'
      . '{"deviceName":" vm-contoh-01 ","ipaddress":"10.20.3.3","status":"5"}]';

$rows = DeviceParser::parse($list);
check('rows without an identity are skipped', count($rows) === 2);
check('fields mapped', $rows[0] === [
    'device_key' => '10.20.1.1', 'name' => '10.20.1.1', 'display_name' => 'core-sw-01', 'ip_address' => '10.20.1.1',
    'device_type' => 'Cisco 9300', 'device_category' => 'Switch', 'availability' => 'Clear',
]);
check('values trimmed and fallbacks used', $rows[1]['device_key'] === 'vm-contoh-01' && $rows[1]['display_name'] === 'vm-contoh-01' && $rows[1]['availability'] === '5');
check('wrapped list accepted', count(DeviceParser::parse('{"rows":' . $list . '}')) === 2);
check('empty list is empty, not an error', DeviceParser::parse('[]') === []);

$threw = static function (callable $fn): bool {
    try { $fn(); } catch (ClientException) { return true; }
    return false;
};
check('non-JSON throws', $threw(fn () => DeviceParser::parse('<html>login</html>')));
check('object without a known list throws', $threw(fn () => DeviceParser::parse('{"error":{"code":5000}}')));

// Review Focus 3: the key must never reach a log or an exception message.
check('redact removes apiKey', Client::redact('GET https://opm/api?apiKey=S3CRET&x=1 failed') === 'GET https://opm/api?apiKey=***&x=1 failed');

$seen = [];
$transport = function (string $url, array $query, array $headers) use (&$seen, $list) {
    $seen = [$url, $query, $headers];
    return ['status' => 200, 'body' => $list];
};
$client = new Client('https://opm:8061/', 'S3CRET', true, $transport);
check('listDevices parses response', count($client->listDevices()) === 2);
check('calls the device list endpoint', $seen[0] === 'https://opm:8061/api/json/device/listDevices');
check('api key sent as header', ($seen[2]['apiKey'] ?? null) === 'S3CRET');
check('api key never in the query string', !array_key_exists('apiKey', $seen[1]));

$client = new Client('https://opm', 'S3CRET', true, fn () => ['status' => 401, 'body' => 'nope']);
try {
    $client->listDevices();
    check('HTTP error throws', false);
} catch (ClientException $e) {
    check('HTTP error throws', str_contains($e->getMessage(), '401'));
    check('HTTP error message has no key', !str_contains($e->getMessage(), 'S3CRET'));
}

$client = new Client('https://opm', 'S3CRET', true, function () { throw new RuntimeException('cURL error 28 for https://opm/api?apiKey=S3CRET'); });
try {
    $client->listDevices();
    check('transport failure throws ClientException', false);
} catch (ClientException $e) {
    check('transport failure throws ClientException', true);
    check('transport failure message redacted', !str_contains($e->getMessage(), 'S3CRET'));
}

// Production 2026-09-30: OpManager answers HTTP 200 with an error object when the key
// is rejected. The message must reach Setup, not a generic "unexpected shape".
$prod = '{"error":{"code":5000,"message":"Specified apiKey is not valid. Authentication failed."}}';
try {
    DeviceParser::parse($prod);
    check('OpManager error object throws', false);
} catch (ClientException $e) {
    check('OpManager error object throws', true);
    check('OpManager error message surfaced', str_contains($e->getMessage(), 'Specified apiKey is not valid') && str_contains($e->getMessage(), '5000'));
}
try {
    DeviceParser::parse('{"error":"plain text failure"}');
    check('string error surfaced', false);
} catch (ClientException $e) {
    check('string error surfaced', str_contains($e->getMessage(), 'plain text failure'));
}

require_once OPM_ROOT . '/src/OpManager/AlarmSource.php';
require_once OPM_ROOT . '/src/OpManager/AlarmParser.php';
$alarmJson = json_encode([
    ['alarmId' => '1', 'entity' => 'E1', 'numericSeverity' => 1, 'severityString' => 'Critical'],
    ['alarmId' => '2', 'entity' => 'E2', 'numericSeverity' => 5, 'severityString' => 'Clear'],
]);
$calls = [];
$client = new Client('https://opm', 'S3CRET', true, function (string $url, array $query, array $headers) use (&$calls, $alarmJson) {
    $calls[] = [$url, $query];
    return ['status' => 200, 'body' => $alarmJson];
});
check('active alarms use the ActiveAlarms filter', count($client->listActiveAlarms()) === 2
    && $calls[0] === ['https://opm/api/json/alarm/listAlarms', ['alertType' => 'ActiveAlarms']]);
$one = $client->alarmByEntity('E2');
check('alarm by entity filters on entity', $calls[1][1] === ['entity' => 'E2'] && ($one['alarm_key'] ?? null) === '2');
check('alarm by entity returns null when absent', $client->alarmByEntity('E9') === null);

check('unconfigured client refuses', $threw(fn () => (new Client('', '', true, $transport))->listDevices()));

done();
