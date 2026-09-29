<?php

require __DIR__ . '/bootstrap.php';

// The shared PGN Suite classes must be byte-identical to the canonical copies
// in …/PGN GLPI Suite/shared/pgnsuite/ (hub §5). No line-ending normalisation
// here on purpose: the file itself must be exact.
$expected = [
    'lib/pgnsuite/ConfigTab.php' => '008C4C817DB2E42040915136108CD41917C34760A8F4CDDC60E46BDB208F39D0',
    'lib/pgnsuite/AssetTab.php'  => 'C7F6C88B58BE9A7AAF172F3A2F2DBAAFBB07B0CE1898BA5A74A0EDB61EA143A9',
];
foreach ($expected as $rel => $sha) {
    $path   = OPM_ROOT . '/' . $rel;
    $actual = is_file($path) ? strtoupper(hash_file('sha256', $path)) : 'missing';
    check("{$rel} is canonical", $actual === $sha, "got {$actual}");
}

done();
