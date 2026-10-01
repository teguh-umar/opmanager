<?php

require __DIR__ . '/bootstrap.php';
foreach (['ClientException', 'ResponseGuard', 'AlarmParser'] as $f) {
    require OPM_ROOT . "/src/OpManager/{$f}.php";
}

use GlpiPlugin\Opmanager\OpManager\AlarmParser;
use GlpiPlugin\Opmanager\OpManager\ClientException;

// Shape captured on production 2026-09-30 (spec §14).
$row = [
    'severity' => "<img src='/images/devices/status3.gif'>&nbsp;Attention",
    'numericSeverity' => 3, 'deviceType' => '-', 'severityString' => 'Attention', 'statusStr' => 'Attention',
    'displayName' => 'VM-APP-DB01', 'ipAddress' => '10.20.14.139', 'eventType' => 'Threshold Down',
    'message' => 'CPU Utilization is 85 %, threshold value for this monitor is 80 %',
    'deviceName' => '10.20.3.139.10000000001', 'statusNum' => 3, 'modTimeLong' => 1790739714008,
    'modTime' => '30 Sep 2026 10:41:54 AM ICT', 'alarmId' => '10000003244', 'category' => 'Server',
    'entity' => '10000024830', 'alarmcode' => 'THRESHOLD-DOWN_VMware-vmCPUUtilization', 'status' => 3, 'who' => 'Unacknowledged',
];
$rows = AlarmParser::parse(json_encode([$row, ['alarmId' => '', 'numericSeverity' => 1], ['alarmId' => '9', 'numericSeverity' => 0]]));
check('rows without alarmId or valid severity are skipped', count($rows) === 1);
$a = $rows[0];
check('keys mapped', $a['alarm_key'] === '10000003244' && $a['entity_key'] === '10000024830'
    && $a['device_name'] === '10.20.3.139.10000000001' && $a['display_name'] === 'VM-APP-DB01'
    && $a['ip_address'] === '10.20.14.139' && $a['alarm_code'] === 'THRESHOLD-DOWN_VMware-vmCPUUtilization');
check('numeric severity used', $a['severity'] === 3 && $a['severity_label'] === 'Attention');
check('HTML severity field never copied', !str_contains(json_encode($a), '<img'));
check('mod_time from epoch ms', $a['mod_time'] === date('Y-m-d H:i:s', 1790739714));

$b = AlarmParser::parse(json_encode([['alarmId' => '7', 'severityString' => 'Critical']]))[0];
check('severity falls back to the label', $b['severity'] === 1 && $b['severity_label'] === 'Critical');
check('missing mod time is empty', $b['mod_time'] === '');

$threw = static function (callable $fn, string $needle): bool {
    try { $fn(); } catch (ClientException $e) { return str_contains($e->getMessage(), $needle); }
    return false;
};
check('error object surfaced', $threw(fn () => AlarmParser::parse('{"error":{"code":5000,"message":"Specified apiKey is not valid."}}'), 'OpManager error 5000'));
check('non-JSON rejected', $threw(fn () => AlarmParser::parse('<html>'), 'alarm list is not valid JSON'));
check('object without list rejected', $threw(fn () => AlarmParser::parse('{"x":1}'), 'alarm list has an unexpected shape'));
check('empty list is fine', AlarmParser::parse('[]') === []);

// Production probe 2026-10-01: rights/method errors use {"message","errorcode"}.
check('errorcode shape surfaced', $threw(fn () => AlarmParser::parse('{"error":{"message":"You are not authorized to perform this operation","errorcode":"AUTHORIZATION_FAILED"}}'), 'OpManager error AUTHORIZATION_FAILED: You are not authorized'));

done();
