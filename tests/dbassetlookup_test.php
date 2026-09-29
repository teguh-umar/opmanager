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

done();
