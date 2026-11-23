<?php

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'ResponseGuard', 'DeviceSource', 'AlarmSource', 'DeviceParser', 'AlarmParser', 'AlarmWriter', 'Client'] as $f) {
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

// Plan 3: writes are POST with a form body; the key stays in the header.
$posted = [];
$writer = new Client('https://opm', 'S3CRET', true, function (string $url, array $query, array $headers, ?array $form = null) use (&$posted) {
    $posted[] = [$url, $query, $headers, $form];
    return ['status' => 200, 'body' => '{"result":{"message":"Successfully Added"}}'];
});
$writer->addNote('IF-1.2_Poll', 'hello');
check('addNote posts entity and notes', $posted[0][0] === 'https://opm/api/json/alarm/addNotes' && $posted[0][3] === ['entity' => 'IF-1.2_Poll', 'notes' => 'hello']);
check('addNote key only in header', ($posted[0][2]['apiKey'] ?? null) === 'S3CRET' && $posted[0][1] === [] && !isset($posted[0][3]['apiKey']));
$writer->clearAlarm('IF-1.2_Poll');
check('clearAlarm posts entity', $posted[1][0] === 'https://opm/api/json/alarm/clearAlarm' && $posted[1][3] === ['entity' => 'IF-1.2_Poll']);
// Real clearAlarm success shape (production probe 2026-10-01): no "result", no "error".
$cleared = new Client('https://opm', 'S3CRET', true, fn () => ['status' => 200, 'body' => '{"ackMessage":"Alarm has been successfully Cleared!","AlarmProperty":{"Details":{"displayName":"VM-SERVER-01"}}}']);
$ok = true;
try { $cleared->clearAlarm('E'); } catch (ClientException $e) { $ok = false; }
check('clearAlarm ackMessage answer is success', $ok);
$denied = new Client('https://opm', 'S3CRET', true, fn () => ['status' => 200, 'body' => '{"error":{"message":"You are not authorized to perform this operation","errorcode":"AUTHORIZATION_FAILED"}}']);
try {
    $denied->addNote('E', 'x');
    check('write error object throws', false);
} catch (ClientException $e) {
    check('write error object throws', str_contains($e->getMessage(), 'AUTHORIZATION_FAILED'));
}
$writer->acknowledgeAlarm('IF-1.2_Poll');
check('acknowledgeAlarm posts entity', $posted[2][0] === 'https://opm/api/json/alarm/acknowledgeAlarm' && $posted[2][3] === ['entity' => 'IF-1.2_Poll'] && ($posted[2][2]['apiKey'] ?? null) === 'S3CRET');
// Already acknowledged (production probe 2026-10-02): ackMessage, no error -> not a failure.
$again = new Client('https://opm', 'S3CRET', true, fn () => ['status' => 200, 'body' => '{"ackMessage":"The selected Alarm is not Acknowledged","AlarmProperty":{}}']);
$ok = true;
try { $again->acknowledgeAlarm('E'); } catch (ClientException $e) { $ok = false; }
check('repeat acknowledge is not a failure', $ok);
check('client is an AlarmWriter', $writer instanceof \GlpiPlugin\Opmanager\OpManager\AlarmWriter);

done();
