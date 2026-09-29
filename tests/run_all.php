<?php

// Runs every tests/*_test.php in its own PHP process (stubs differ per suite).
$failed = 0;
foreach (glob(__DIR__ . '/*_test.php') as $file) {
    echo '== ' . basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $code);
    if ($code !== 0) {
        $failed++;
    }
}
echo $failed === 0 ? "SUITES OK\n" : "SUITES FAILED: {$failed}\n";
exit($failed === 0 ? 0 : 1);
