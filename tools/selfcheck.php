<?php

/**
 * opmanager runtime self-check. On the VM:
 *   sudo -u nginx php /var/www/glpi/plugins/opmanager/tools/selfcheck.php [filter]
 * Prints PASS/FAIL per check; exit 1 on any failure.
 */

global $DB, $CFG_GLPI;

$glpiRoot = null;
foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4), dirname(__DIR__, 2)] as $candidate) {
    if (is_file($candidate . '/vendor/autoload.php') && is_file($candidate . '/src/Glpi/Kernel/Kernel.php')) {
        $glpiRoot = $candidate;
        break;
    }
}
if ($glpiRoot === null) {
    fwrite(STDERR, "selfcheck: cannot locate GLPI root from " . __DIR__ . "\n");
    exit(2);
}

// Same bootstrap as core front/cron.php (11.0.5): in GLPI 11 inc/includes.php only
// emits deprecation notices; the autoloader + Kernel boot is what loads plugins,
// $DB and the custom asset classes.
require_once $glpiRoot . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel())->boot();

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
