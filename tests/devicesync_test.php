<?php

require __DIR__ . '/bootstrap.php';
foreach (['Matching/AssetLookup', 'Matching/Matcher', 'OpManager/ClientException', 'OpManager/DeviceSource', 'DeviceStore', 'DeviceSync'] as $f) {
    require OPM_ROOT . "/src/{$f}.php";
}

use GlpiPlugin\Opmanager\DeviceStore;
use GlpiPlugin\Opmanager\DeviceSync;
use GlpiPlugin\Opmanager\Matching\AssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\OpManager\DeviceSource;

final class FakeSource implements DeviceSource
{
    public function __construct(private array|ClientException $result) {}
    public function listDevices(): array
    {
        if ($this->result instanceof ClientException) {
            throw $this->result;
        }
        return $this->result;
    }
}
final class FakeStore implements DeviceStore
{
    public array $upserts = [];
    public array $matches = [];
    public function __construct(private array $manual_keys = []) {}
    public function upsert(array $device, string $now): array
    {
        $this->upserts[] = $device['device_key'];
        return ['id' => count($this->upserts), 'is_manual' => in_array($device['device_key'], $this->manual_keys, true)];
    }
    public function setMatch(int $id, array $result): void { $this->matches[$id] = $result; }
}
final class FakeLookup implements AssetLookup
{
    public function byName(string $name): array { return $name === 'core-sw-01' ? [['itemtype' => 'NetworkEquipment', 'items_id' => 10]] : []; }
    public function byIp(string $ip): array { return $ip === '10.0.0.9' ? [['itemtype' => 'Computer', 'items_id' => 1], ['itemtype' => 'Computer', 'items_id' => 2]] : []; }
}

$dev = static fn (string $key, string $display, string $ip) => [
    'device_key' => $key, 'name' => $key, 'display_name' => $display, 'ip_address' => $ip,
    'device_type' => '', 'device_category' => '', 'availability' => '',
];
$devices = [
    $dev('a', 'core-sw-01', '10.0.0.1'),  // matched by name
    $dev('b', 'pinned-box', '10.0.0.9'),  // manual pin
    $dev('c', 'shared-ip', '10.0.0.9'),   // ambiguous IP -> review
    $dev('d', '', '10.0.0.77'),           // display empty -> uses name 'd' -> unmatched
];
$now = '2026-09-29 10:00:00';

$store = new FakeStore(['b']);
$stats = DeviceSync::run(new FakeSource($devices), $store, new Matcher(new FakeLookup()), 'hybrid_name', $now);
check('every device upserted', $store->upserts === ['a', 'b', 'c', 'd']);
check('manual pin never rematched', !isset($store->matches[2]));
check('stats add up', $stats === ['fetched' => 4, 'matched' => 1, 'review' => 1, 'unmatched' => 1, 'manual' => 1, 'skipped' => 0]);
check('name match stored', ($store->matches[1]['items_id'] ?? null) === 10);

$store = new FakeStore(['b']);
DeviceSync::run(new FakeSource($devices), $store, new Matcher(new FakeLookup()), 'manual', $now);
check('manual mode: pin still untouched', !isset($store->matches[2]));
check('manual mode: others unmatched', ($store->matches[1]['state'] ?? null) === 'unmatched');

$store = new FakeStore();
try {
    DeviceSync::run(new FakeSource(new ClientException('down')), $store, new Matcher(new FakeLookup()), 'hybrid_name', $now);
    check('source failure propagates', false);
} catch (ClientException) {
    check('source failure propagates', true);
}
check('source failure writes nothing', $store->upserts === [] && $store->matches === []);

// Final review Important 2: when a configured asset type cannot be loaded, device
// data is refreshed but existing pairings are KEPT (no setMatch), and the problem
// stays visible as the sync error instead of being cleared.
$store = new FakeStore(['b']);
$stats = DeviceSync::run(new FakeSource($devices), $store, new Matcher(new FakeLookup()), 'hybrid_name', $now, false);
check('incomplete matching: devices still refreshed', $store->upserts === ['a', 'b', 'c', 'd']);
check('incomplete matching: no pairing rewritten', $store->matches === []);
check('incomplete matching: counted as skipped', ($stats['skipped'] ?? null) === 3);
check('status message names the unloadable type', str_contains(DeviceSync::statusMessage(['Glpi\\CustomAsset\\StorageAsset']), 'Glpi\\CustomAsset\\StorageAsset'));
check('status message empty when nothing missing', DeviceSync::statusMessage([]) === '');

done();
