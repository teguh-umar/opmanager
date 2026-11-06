<?php

require __DIR__ . '/bootstrap.php';

// The shared PGN Suite classes must be byte-identical to the canonical copies
// in …/PGN GLPI Suite/shared/pgnsuite/ (hub §5). No line-ending normalisation
// here on purpose: the file itself must be exact.
$expected = [
    'lib/pgnsuite/ConfigTab.php' => 'F94A80C1C813A951919A20D2521DE35B431F9C836BBCCE89BAC70E544D0B80FA',
    'lib/pgnsuite/AssetTab.php'  => 'F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466',
    'lib/pgnsuite/Menu.php'      => 'FC0B96219DD489639DEECD720197BA6B9810F6303A28CE7DB891139E7913CBFE',
];
foreach ($expected as $rel => $sha) {
    $path   = OPM_ROOT . '/' . $rel;
    $actual = is_file($path) ? strtoupper(hash_file('sha256', $path)) : 'missing';
    check("{$rel} is canonical", $actual === $sha, "got {$actual}");
}

// SelfCheck on the VM must expect the same SHAs as the shipped files (1.2.0 kept r3/r2 there).
$selfcheck = (string) file_get_contents(OPM_ROOT . '/src/SelfCheck.php');
foreach ($expected as $rel => $sha) {
    check('SelfCheck expects ' . basename($rel) . ' canonical SHA', str_contains($selfcheck, "'" . basename($rel) . "' => '{$sha}'") || str_contains($selfcheck, "'" . basename($rel) . "'  => '{$sha}'") || str_contains($selfcheck, "'" . basename($rel) . "'      => '{$sha}'"));
}

done();
