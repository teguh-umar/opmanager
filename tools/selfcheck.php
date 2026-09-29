<?php

/**
 * opmanager runtime self-check. On the VM:
 *   sudo -u nginx php /data/www/glpi/plugins/opmanager/tools/selfcheck.php [filter]
 * Prints PASS/FAIL per check; exit 1 on any failure.
 */

$glpiRoot = null;
foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4), dirname(__DIR__, 2)] as $candidate) {
    if (is_file($candidate . '/inc/includes.php')) {
        $glpiRoot = $candidate;
        break;
    }
}
if ($glpiRoot === null) {
    fwrite(STDERR, "selfcheck: cannot locate GLPI root from " . __DIR__ . "\n");
    exit(2);
}
require $glpiRoot . '/inc/includes.php';

$filter = $argv[1] ?? '';
$fail   = 0;
$pass   = 0;
foreach (\GlpiPlugin\Opmanager\SelfCheck::checks() as $name => $check) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $check();
        $pass++;
        echo "PASS {$name}\n";
    } catch (\Throwable $e) {
        $fail++;
        echo "FAIL {$name} -- " . $e->getMessage() . "\n";
    }
}
echo ($fail === 0 ? 'ALL PASS' : 'FAILED') . " ({$pass} passed, {$fail} failed)\n";
exit($fail === 0 ? 0 : 1);
