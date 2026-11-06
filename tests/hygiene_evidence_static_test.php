<?php

require __DIR__ . '/bootstrap.php';

// Providers read core and suite tables on the VM; offline we pin the guards.
$mist  = (string) @file_get_contents(OPM_ROOT . '/src/Hygiene/MistProvider.php');
$agent = (string) @file_get_contents(OPM_ROOT . '/src/Hygiene/AgentProvider.php');
check('mist guarded by isPluginActive', str_contains($mist, "isPluginActive('mist')"));
check('mist guarded by tableExists', str_contains($mist, "tableExists('glpi_plugin_mist_devices')"));
check('mist matches the asset first, then the IP', (bool) preg_match("/'itemtype'.*?'ip_address'/s", $mist));
check('agent reads glpi_agents.last_contact', str_contains($agent, "'glpi_agents'") && str_contains($agent, 'last_contact'));
foreach (glob(OPM_ROOT . '/src/Hygiene/*Provider*.php') as $file) {
    check(basename($file) . ' never writes', !preg_match('/DB->(insert|update|delete|doQuery)\(/', (string) file_get_contents($file)));
}
check('no exec / shell in the hygiene code', !preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/', implode('', array_map('file_get_contents', glob(OPM_ROOT . '/src/Hygiene/*.php')))));

done();
