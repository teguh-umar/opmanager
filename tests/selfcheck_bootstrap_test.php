<?php

require __DIR__ . '/bootstrap.php';

// Final review Important 1: in GLPI 11.0.5 inc/includes.php only emits deprecation
// notices - it neither autoloads nor boots the Kernel. The self-check must boot
// GLPI the way front/cron.php does, or the runbook's only runtime check fatals.
$src = (string) @file_get_contents(OPM_ROOT . '/tools/selfcheck.php');
check('selfcheck loads the composer autoloader', str_contains($src, "/vendor/autoload.php'"));
check('selfcheck boots the GLPI Kernel', (bool) preg_match('/new\s+\\\\?Glpi\\\\Kernel\\\\Kernel\(\)\)?->boot\(\)|->boot\(\);/', $src) && str_contains($src, 'Kernel'));
check('selfcheck no longer relies on inc/includes.php', !str_contains($src, "inc/includes.php'"));

done();
