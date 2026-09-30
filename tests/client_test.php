<?php

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'DeviceSource', 'DeviceParser', 'Client'] as $f) {
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
$transport = function (string $url, array $query) use (&$seen, $list) {
    $seen = [$url, $query];
    return ['status' => 200, 'body' => $list];
};
$client = new Client('https://opm:8061/', 'S3CRET', true, $transport);
check('listDevices parses response', count($client->listDevices()) === 2);
check('calls the device list endpoint', $seen[0] === 'https://opm:8061/api/json/device/listDevices');
check('api key sent as query param', ($seen[1]['apiKey'] ?? null) === 'S3CRET');

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

check('unconfigured client refuses', $threw(fn () => (new Client('', '', true, $transport))->listDevices()));

done();
