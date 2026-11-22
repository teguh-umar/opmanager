<?php

require __DIR__ . '/bootstrap.php';

// v1.2.0: the shared "ICT Tools" sidebar menu (hub §5 Menu.php, pilot 2026-10-03).
$setup = (string) file_get_contents(OPM_ROOT . '/setup.php');
check('shared Menu loaded only when no other plugin did', (bool) preg_match("/if \\(!class_exists\\(\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::class, false\\)\\) \\{\\s*require_once __DIR__ \\. '\\/lib\\/pgnsuite\\/Menu\\.php';/", $setup));
check('shared Menu registered for opmanager', str_contains($setup, "\\GlpiPlugin\\Pgnsuite\\Menu::register('opmanager');"));
check('opmanager entry provider hooked', str_contains($setup, "\$PLUGIN_HOOKS[\\GlpiPlugin\\Pgnsuite\\Menu::HOOK]['opmanager'] = \\GlpiPlugin\\Opmanager\\Menu::class . '::entry';"));
check('old own sector removed', !str_contains($setup, "['menu_toadd']['opmanager'] = ['opmanager'"));
check('author is Teguh DHAMAR', (bool) preg_match("/'author'\\s*=> 'Teguh DHAMAR',/", $setup) && !str_contains($setup, 'PGN ICT'));

$menu = (string) file_get_contents(OPM_ROOT . '/src/Menu.php');
check('Menu provides entry()', str_contains($menu, 'public static function entry(): ?array'));
check('entry hidden without the monitoring right', (bool) preg_match('/function entry\(\): \?array\s*\{\s*if \(!Session::haveRight\(RightDefinition::MONITORING, READ\)\) \{\s*return null;/', $menu));
check('entry order 60 (suite order)', str_contains($menu, "'order'   => 60"));
check('entry keeps option keys device, alarm, coverage', str_contains($menu, "'device'") && str_contains($menu, "'alarm'") && str_contains($menu, "'coverage'"));
check('old sector getMenuContent removed', !str_contains($menu, 'function getMenuContent'));

$pages = ['alarm.php' => 'alarm', 'config.php' => 'device', 'coverage.php' => 'coverage', 'device.php' => 'device'];
foreach ($pages as $file => $option) {
    $src = (string) file_get_contents(OPM_ROOT . '/front/' . $file);
    check("{$file}: header in the shared sector", str_contains($src, "\\GlpiPlugin\\Pgnsuite\\Menu::SECTOR, 'opmanager', '{$option}')"));
    check("{$file}: sub-nav right after the header", (bool) preg_match("/Html::header\\([^;]*Menu::SECTOR, 'opmanager', '{$option}'\\);\\s*\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::renderSubNav\\('opmanager', '{$option}'\\);/", $src));
}
foreach (['alarm.form.php' => 'alarm', 'device.form.php' => 'device'] as $file => $option) {
    $src = (string) file_get_contents(OPM_ROOT . '/front/' . $file);
    check("{$file}: form page in the shared sector", str_contains($src, "['central' => [\\GlpiPlugin\\Pgnsuite\\Menu::SECTOR, 'opmanager', '{$option}'], 'helpdesk' => []]"));
}
foreach (glob(OPM_ROOT . '/front/*.php') as $file) {
    check(basename($file) . ': no old sector left', !preg_match("/(Html::header\\([^;]*'', 'opmanager', '|'central' => \\['opmanager')/", (string) file_get_contents($file)));
}

$attr = (string) file_get_contents(OPM_ROOT . '/.gitattributes');
check('Menu.php kept byte-exact by git', str_contains($attr, 'lib/pgnsuite/Menu.php -text'));

done();
