<?php

// The default transport talks real HTTP (ext-curl) to a local stub server. ext-curl is a GLPI
// requirement; the offline PHP has it unloaded, so this suite re-runs itself with it loaded.
if (!extension_loaded('curl')) {
    $ext = dirname(PHP_BINARY) . '/ext';
    passthru(escapeshellarg(PHP_BINARY) . ' -d extension_dir=' . escapeshellarg($ext) . ' -d extension=curl ' . escapeshellarg(__FILE__), $code);
    exit($code);
}

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'ResponseGuard', 'DeviceSource', 'AlarmSource', 'DeviceParser', 'AlarmParser', 'Client'] as $f) {
    require OPM_ROOT . "/src/OpManager/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\OpManager\ClientException;

$src = (string) file_get_contents(OPM_ROOT . '/src/OpManager/Client.php');
check('client does not depend on Guzzle (another plugin may ship an old copy)', !str_contains($src, 'GuzzleHttp'));

$port = 18000 + random_int(0, 999);
$log  = tempnam(sys_get_temp_dir(), 'opm');
$env  = array_merge(getenv(), ['OPM_STUB_LOG' => $log]);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/opm_stub.php'],
    [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
    $pipes,
    null,
    $env
);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}

try {
    $client = new Client("http://127.0.0.1:{$port}", 'S3CRET', true);
    $rows   = $client->listDevices();
    $seen   = json_decode((string) file_get_contents($log), true);
    check('default transport fetches and parses', count($rows) === 1 && $rows[0]['display_name'] === 'sw-01');
    check('GET to the device list path', ($seen['method'] ?? '') === 'GET' && ($seen['path'] ?? '') === Client::DEVICE_LIST_PATH);
    check('api key sent in the apiKey header', ($seen['apikey'] ?? null) === 'S3CRET' && !isset($seen['query']['apiKey']));

    $client->alarmByEntity('E 1&x');
    $seen = json_decode((string) file_get_contents($log), true);
    check('query parameters encoded', ($seen['query'] ?? null) === ['entity' => 'E 1&x']);

    try {
        (new Client("http://127.0.0.1:{$port}/fail", 'S3CRET', true))->listDevices();
        check('HTTP 500 throws', false);
    } catch (ClientException $e) {
        check('HTTP 500 throws', str_contains($e->getMessage(), '500'));
    }
} finally {
    proc_terminate($server);
    proc_close($server);
    @unlink($log);
}

try {
    (new Client("http://127.0.0.1:{$port}", 'S3CRET', true))->listDevices();
    check('connection failure throws ClientException', false);
} catch (ClientException $e) {
    check('connection failure throws ClientException', !str_contains($e->getMessage(), 'S3CRET'));
}

done();
