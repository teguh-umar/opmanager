<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Matching/AssetLookup.php';
require OPM_ROOT . '/src/Matching/DbAssetLookup.php';

use GlpiPlugin\Opmanager\Matching\DbAssetLookup;

// Review Focus 5: a configured custom asset that cannot be resolved at runtime
// must be REPORTED, not silently skipped.
$factory = static fn (string $t) => $t === 'Glpi\\CustomAsset\\StorageAsset' ? null : new stdClass();
$r = DbAssetLookup::resolvable(['Computer', 'Glpi\\CustomAsset\\StorageAsset'], $factory);
check('unresolvable itemtypes reported', $r === ['ok' => ['Computer'], 'missing' => ['Glpi\\CustomAsset\\StorageAsset']]);
check('empty list resolves to nothing', DbAssetLookup::resolvable([], $factory) === ['ok' => [], 'missing' => []]);

// Final review Important 3: IP ambiguity counts EVERY live owner of the IP, not only
// the asset types ticked in Setup (spec §5: an IP owned by >1 asset is never used).
$configured = ['Computer'];
$pc   = ['itemtype' => 'Computer', 'items_id' => 1];
$prn  = ['itemtype' => 'Printer', 'items_id' => 9];
check('IP shared with an unconfigured type stays ambiguous', count(DbAssetLookup::ipHits([$pc, $prn], $configured)) === 2);
check('single configured owner is a hit', DbAssetLookup::ipHits([$pc], $configured) === [$pc]);
check('single unconfigured owner is no hit', DbAssetLookup::ipHits([$prn], $configured) === []);
check('same owner twice counts once', DbAssetLookup::ipHits([$pc, $pc], $configured) === [$pc]);

done();
