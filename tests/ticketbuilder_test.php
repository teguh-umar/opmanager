<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/EntityResolver.php';
require OPM_ROOT . '/src/Ticketing/TicketBuilder.php';

use GlpiPlugin\Opmanager\Ticketing\EntityResolver;
use GlpiPlugin\Opmanager\Ticketing\TicketBuilder;

$nets = [
    ['entities_id' => 3, 'address' => '10.129.0.0', 'netmask' => '255.255.0.0'],
    ['entities_id' => 7, 'address' => '10.129.14.0', 'netmask' => '255.255.255.0'],
    ['entities_id' => 9, 'address' => 'bogus', 'netmask' => '255.255.255.0'],
];
check('asset entity wins', EntityResolver::resolve(5, '10.129.14.139', $nets, true, 1) === 5);
check('most specific IP network wins', EntityResolver::resolve(null, '10.129.14.139', $nets, true, 1) === 7);
check('wider network when only it contains the IP', EntityResolver::resolve(null, '10.129.99.1', $nets, true, 1) === 3);
check('no asset, no network → default entity', EntityResolver::resolve(null, '192.168.1.1', $nets, true, 1) === 1);
check('network step can be switched off', EntityResolver::resolve(null, '10.129.14.139', $nets, false, 1) === 1);
check('bad IP → default', EntityResolver::resolve(null, 'not-an-ip', $nets, true, 1) === 1);

$alarm = [
    'alarm_key' => '10000003244', 'entity_key' => 'E', 'device_name' => '10.129.3.139.10000000001', 'display_name' => 'VM-MSGIS-DBS',
    'ip_address' => '10.129.14.139', 'category' => 'Server', 'alarm_code' => 'THRESHOLD-DOWN_VMware-vmCPUUtilization',
    'event_type' => 'Threshold Down', 'severity' => 1, 'severity_label' => 'Critical',
    'message' => 'CPU <b>85</b> %', 'mod_time' => '2026-09-30 10:41:54',
];
$cfg = ['title_pattern' => '[OPM-{alarm_key}] {severity}: {message} - {device}', 'content_pattern' => "Device: {device}\n{message}", 'service_user' => 42];
$asset = ['itemtype' => 'Computer', 'items_id' => 1529];

$in = TicketBuilder::build($alarm, $asset, 7, [], 3, $cfg);
check('title from pattern', $in['name'] === '[OPM-10000003244] Critical: CPU <b>85</b> % - VM-MSGIS-DBS');
check('content escaped and line breaks kept', $in['content'] === "Device: VM-MSGIS-DBS<br />\nCPU &lt;b&gt;85&lt;/b&gt; %");
check('entity set', $in['entities_id'] === 7);
check('incident by default', $in['type'] === TicketBuilder::TYPE_INCIDENT);
check('urgency from severity when template has none', $in['urgency'] === 5);
check('service user is requester and recipient', $in['_users_id_requester'] === 42 && $in['users_id_recipient'] === 42);
check('asset linked', $in['items_id'] === ['Computer' => [1529]]);
check('template id passed for mandatory checks', $in['_tickettemplate'] === 3);

$pre = ['urgency' => 2, 'itilcategories_id' => 11, '_users_id_requester' => 99, 'name' => 'template title', 'type' => 2];
$in = TicketBuilder::build($alarm, ['itemtype' => '', 'items_id' => 0], 7, $pre, 3, $cfg);
check('template urgency kept', $in['urgency'] === 2);
check('template category kept', $in['itilcategories_id'] === 11);
check('template requester kept', $in['_users_id_requester'] === 99);
check('template type kept', $in['type'] === 2);
check('our title overrides template title', str_starts_with($in['name'], '[OPM-10000003244]'));
check('no asset → no items_id', !array_key_exists('items_id', $in));

$long = TicketBuilder::render(str_repeat('x', 300), [], false);
check('title capped at 250 chars', strlen($long) === 250);
check('device falls back to device_name', TicketBuilder::vars(['display_name' => ''] + $alarm)['{device}'] === '10.129.3.139.10000000001');

done();
