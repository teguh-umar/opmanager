<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Matching/AssetLookup.php';
require OPM_ROOT . '/src/Matching/Matcher.php';

use GlpiPlugin\Opmanager\Matching\AssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;

final class FakeLookup implements AssetLookup
{
    public array $calls = [];
    public function __construct(private array $names, private array $ips) {}
    public function byName(string $name): array { $this->calls[] = "name:$name"; return $this->names[$name] ?? []; }
    public function byIp(string $ip): array { $this->calls[] = "ip:$ip"; return $this->ips[$ip] ?? []; }
}

$sw  = ['itemtype' => 'NetworkEquipment', 'items_id' => 10];
$vm  = ['itemtype' => 'Computer', 'items_id' => 1566];
$vm2 = ['itemtype' => 'Computer', 'items_id' => 1600];

$lookup = new FakeLookup(
    ['core-sw-01' => [$sw], 'vm-wm-tc-01' => [$vm], 'dup-name' => [$vm, $vm2], 'renamed-new' => []],
    ['10.129.1.1' => [$sw], '10.129.2.9' => [$vm, $vm2], '10.129.3.3' => [$vm], '10.129.4.4' => [$vm, $vm]]
);
$m = new Matcher($lookup);

$r = $m->match('core-sw-01', '10.129.1.1', 'hybrid_name');
check('hybrid_name: name hit', $r === ['state' => 'matched', 'itemtype' => 'NetworkEquipment', 'items_id' => 10, 'source' => 'name']);

$r = $m->match('renamed-new', '10.129.3.3', 'hybrid_name');
check('hybrid_name: name miss falls back to unique IP', $r['state'] === 'matched' && $r['items_id'] === 1566 && $r['source'] === 'ip');

$r = $m->match('renamed-new', '10.129.2.9', 'hybrid_name');
check('ambiguous IP never guesses', $r === ['state' => 'review', 'itemtype' => '', 'items_id' => 0, 'source' => '']);

$r = $m->match('vm-wm-tc-01', '10.129.2.9', 'hybrid');
check('hybrid: ambiguous IP then unique name matches by name', $r['state'] === 'matched' && $r['items_id'] === 1566 && $r['source'] === 'name');

$r = $m->match('dup-name', '', 'name_only');
check('ambiguous name goes to review', $r['state'] === 'review');

$r = $m->match('nothing', '10.9.9.9', 'hybrid_name');
check('no hit anywhere is unmatched', $r['state'] === 'unmatched');

$r = $m->match('core-sw-01', '10.129.1.1', 'ip_only');
check('ip_only uses IP', $r['source'] === 'ip');

$lookup->calls = [];
$r = $m->match('core-sw-01', '10.129.1.1', 'manual');
check('manual mode never looks up', $r['state'] === 'unmatched' && $lookup->calls === []);

$lookup->calls = [];
$m->match('core-sw-01', 'not-an-ip', 'ip_only');
check('invalid IP is not looked up', $lookup->calls === []);

$lookup->calls = [];
$m->match('   ', '10.129.1.1', 'name_only');
check('blank name is not looked up', $lookup->calls === []);

$r = $m->match('x', '10.129.4.4', 'ip_only');
check('duplicate rows of one asset still count as one', $r['state'] === 'matched' && $r['items_id'] === 1566);

$r = $m->match('core-sw-01', '10.129.1.1', 'bogus_mode');
check('unknown mode never guesses', $r['state'] === 'unmatched');

done();
