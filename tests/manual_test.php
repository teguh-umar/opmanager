<?php

require __DIR__ . '/bootstrap.php';
require_once OPM_ROOT . '/src/Manual.php';

use GlpiPlugin\Opmanager\Manual;

preg_match("/PLUGIN_OPMANAGER_VERSION',\s*'([^']+)'/", (string) file_get_contents(OPM_ROOT . '/setup.php'), $vm);
define('PLUGIN_OPMANAGER_VERSION_UNDER_TEST', $vm[1] ?? '');

// rc4, suite rule 2026-11-09 (hub §4 "links its user manual"); reference integrasipaman v4.15.0-rc1.
check('manual path is docs/manual.md', str_ends_with(str_replace('\\', '/', Manual::path()), '/docs/manual.md'));
check('commonmark: raw HTML escaped, unsafe links dropped', Manual::CONVERTER_OPTIONS === ['html_input' => 'escape', 'allow_unsafe_links' => false]);

check('version from the Indonesian header', Manual::versionOf("# x\n\nVersi didokumentasikan: 1.3.0\n") === '1.3.0');
check('version from the English header', Manual::versionOf("Version documented: v1.2\n") === '1.2');
check('no header, no version', Manual::versionOf("# Manual\n") === '');
// Hub §4 lesson (integrasipaman 4.15.1-rc1): Copilot writes the version in bold.
check('Copilot-style bold version', Manual::versionOf("> Versi didokumentasikan: **1.3.0** · Disusun: 2026-11-09\n") === '1.3.0');
check('italic / code version', Manual::versionOf("Version documented: _1.3_\n") === '1.3' && Manual::versionOf("Versi didokumentasikan: `v1.3.0`\n") === '1.3.0');
check('bold label and value', Manual::versionOf("**Versi didokumentasikan:** **1.3.0**\n") === '1.3.0');
// Lag by major.minor only: a 1.3.0 manual must not warn on plugin 1.3.1.
check('patch release does not lag', !Manual::lags('1.3.0', '1.3.1'));
check('minor release lags', Manual::lags('1.3.0', '1.4.0'));
check('major release lags', Manual::lags('1.9', '2.0.0-rc1'));

check('older manual lags', Manual::lags('1.2.0', '1.3.0'));
check('rc suffix ignored', !Manual::lags('1.3.0', '1.3.0-rc4'));
check('same version does not lag', !Manual::lags('1.3.0', '1.3.0'));
check('unknown manual version never lags', !Manual::lags('', '1.3.0'));

// 1.3.1: the shipped manual (Copilot, suite docs/manual/opmanager.md) documents 1.3.0 and must not warn.
$shipped = Manual::read();
check('docs/manual.md ships (1.3.1)', $shipped !== null);
check('shipped manual documents 1.3.0', Manual::versionOf((string) $shipped) === '1.3.0');
check('shipped manual does not lag the plugin version', !Manual::lags(Manual::versionOf((string) $shipped), PLUGIN_OPMANAGER_VERSION_UNDER_TEST));
check('shipped manual is Copilot\'s suite copy, byte for byte', is_file(dirname(OPM_ROOT, 2) . '/docs/manual/opmanager.md') === false
    || hash_file('sha256', dirname(OPM_ROOT, 2) . '/docs/manual/opmanager.md') === hash_file('sha256', Manual::path()));

$missing = Manual::body(null, '1.3.0-rc4');
check('missing manual: info notice', str_contains($missing, 'alert-info') && str_contains($missing, 'The user manual is not installed with this copy of the plugin.'));

$lag = Manual::body("Versi didokumentasikan: 1.2.0\n\n# Hi", '1.3.0');
check('lagging manual: warning names both versions', str_contains($lag, 'alert-warning') && str_contains($lag, '1.2.0') && str_contains($lag, '1.3.0'));
$cur = Manual::body("Versi didokumentasikan: 1.3.0\n\n# Hi", '1.3.0');
check('current manual: version line, no warning', !str_contains($cur, 'alert-warning') && str_contains($cur, 'Manual for version 1.3.0'));

// Offline there is no league/commonmark: the fallback must still escape.
$x = Manual::render("<script>alert(1)</script>");
check('fallback render escapes HTML', !str_contains($x, '<script>') && str_contains($x, '&lt;script&gt;'));

$page = (string) file_get_contents(OPM_ROOT . '/front/manual.php');
check('page needs the monitoring (view) right', str_contains($page, 'Session::checkRight(RightDefinition::MONITORING, READ);'));
check('page in the shared sector with its sub-nav', str_contains($page, "\\GlpiPlugin\\Pgnsuite\\Menu::SECTOR, 'opmanager', 'manual');")
    && str_contains($page, "\\GlpiPlugin\\Pgnsuite\\Menu::renderSubNav('opmanager', 'manual');"));
check('page renders Manual::body with the plugin version', str_contains($page, 'Manual::body(Manual::read(), PLUGIN_OPMANAGER_VERSION)'));

$menu = (string) file_get_contents(OPM_ROOT . '/src/Menu.php');
check('menu option manual with ti ti-book', (bool) preg_match("/'manual'\s*=> \[[^\]]*'icon'\s*=> 'ti ti-book'/s", $menu) && str_contains($menu, "'/plugins/opmanager/front/manual.php'"));

$setup = (string) file_get_contents(OPM_ROOT . '/src/SuiteConfig.php');
check('Setup links the manual', str_contains($setup, "/plugins/opmanager/front/manual.php") && str_contains($setup, 'ti ti-book'));

$attr = (string) file_get_contents(OPM_ROOT . '/.gitattributes');
check('docs still kept out of the zip', str_contains($attr, 'docs/** export-ignore'));
check('docs/manual.md ships in the zip', str_contains($attr, 'docs/manual.md -export-ignore'));
$rc = (string) file_get_contents(OPM_ROOT . '/tools/release_check.py');
check('release check allows docs/manual.md', str_contains($rc, 'opmanager/docs/manual.md'));

done();
