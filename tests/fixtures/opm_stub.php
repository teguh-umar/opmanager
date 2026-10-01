<?php

// Router for `php -S` in curltransport_test.php: records the request, answers like OpManager.
file_put_contents(getenv('OPM_STUB_LOG'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path'   => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'query'  => $_GET,
    'apikey' => $_SERVER['HTTP_APIKEY'] ?? null,
    'form'   => $_POST,
]));

if (str_starts_with($_SERVER['REQUEST_URI'], '/fail')) {
    http_response_code(500);
    echo 'boom';
    return true;
}
header('Content-Type: application/json');
echo '[{"deviceName":"10.0.0.1","displayName":"sw-01","ipaddress":"10.0.0.1","type":"Cisco","category":"Switch","statusStr":"Clear"}]';
return true;
