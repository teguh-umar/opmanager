<?php

require __DIR__ . '/bootstrap.php';
foreach (['Matching/AssetLookup', 'Matching/Matcher', 'Ticketing/DeviceIndex', 'Ticketing/AssetResolver', 'Ticketing/AlarmAssetResolver'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\Matching\AssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Opmanager\Ticketing\DeviceIndex;

final class FakeIndex implements DeviceIndex
{
    public function __construct(private array $rows) {}
    public function find(string $device_key): ?array { return $this->rows[$device_key] ?? null; }
}
final class FakeLookup implements AssetLookup
{
    public function byName(string $name): array { return $name === 'VM-SERVER-03' ? [['itemtype' => 'Computer', 'items_id' => 77]] : []; }
    public function byIp(string $ip): array { return []; }
}

$index = new FakeIndex([
    'core-sw' => ['id' => 5, 'itemtype' => 'NetworkEquipment', 'items_id' => 10, 'match_state' => 'matched'],
    'lonely'  => ['id' => 6, 'itemtype' => '', 'items_id' => 0, 'match_state' => 'unmatched'],
]);
$r = new AlarmAssetResolver($index, new Matcher(new FakeLookup()), 'hybrid_name');
$alarm = static fn (string $dev, string $disp, string $ip) => ['device_name' => $dev, 'display_name' => $disp, 'ip_address' => $ip];

check('matched device row wins', $r->resolve($alarm('core-sw', 'x', '1.1.1.1')) === ['devices_id' => 5, 'itemtype' => 'NetworkEquipment', 'items_id' => 10]);
check('alarm device not in list falls back to matcher', $r->resolve($alarm('10.20.3.139.10000000001', 'VM-SERVER-03', '10.20.14.139'))
    === ['devices_id' => 0, 'itemtype' => 'Computer', 'items_id' => 77]);
check('unmatched device row still tries the matcher, keeps device id', $r->resolve($alarm('lonely', 'VM-SERVER-03', ''))
    === ['devices_id' => 6, 'itemtype' => 'Computer', 'items_id' => 77]);
check('nothing found → no asset', $r->resolve($alarm('ghost', 'ghost', '')) === ['devices_id' => 0, 'itemtype' => '', 'items_id' => 0]);

done();
