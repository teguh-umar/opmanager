<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Coverage/View.php';

use GlpiPlugin\Opmanager\Coverage\View;

$report = ['kpi' => ['devices' => 5, 'matched' => 2, 'not_in_glpi' => 2, 'ambiguous' => 1, 'assets' => 4, 'monitored' => 2, 'not_monitored' => 2, 'coverage_pct' => 50],
    'lists' => [
        'not_in_glpi' => [['id' => 3, 'name' => '<b>mystery</b>', 'display_name' => '<b>mystery</b>', 'ip' => '10.1.1.3', 'category' => 'Server', 'type' => 'Linux', 'last_seen' => 'x']],
        'not_monitored' => array_map(fn ($i) => ['itemtype' => 'Computer', 'items_id' => $i, 'name' => "pc{$i}", 'entity' => 'Root', 'state' => 'In use', 'ips' => ['10.0.0.' . $i]], range(1, 60)),
        'ambiguous' => [], 'name_differs' => [], 'stale' => [],
    ], 'categories' => ['Server']];
$state = ['list' => 'not_monitored', 'page' => 2, 'production_only' => true, 'category' => ''];
$html = View::render($report, $state, '/plugins/opmanager/front/coverage.php');

check('kpi cards show the coverage percentage', str_contains($html, '50%') && str_contains($html, '>4<'));
check('one tab per list with its count', count(array_filter(View::LISTS, fn ($l) => str_contains($html, 'list=' . $l))) === 5 && str_contains($html, '(60)'));
check('page 2 shows rows 51-60', str_contains($html, '>pc51<') && !str_contains($html, '>pc50<'));
check('csv link keeps the filters', str_contains($html, 'export=csv') && str_contains($html, 'production_only=1'));
check('values escaped', !str_contains(View::render($report, ['list' => 'not_in_glpi'] + $state, '/x'), '<b>mystery</b>'));
check('production toggle rendered checked', (bool) preg_match('/name="production_only"[^>]*checked/', $html));
$csv = View::csvRows('not_monitored', $report['lists']['not_monitored']);
check('csv rows for every item, not only one page', count($csv['rows']) === 60 && $csv['header'][0] !== '');
check('csv joins several IPs', View::csvRows('not_monitored', [['itemtype' => 'Computer', 'items_id' => 1, 'name' => 'a', 'entity' => 'R', 'state' => '', 'ips' => ['1.1.1.1', '2.2.2.2']]])['rows'][0][3] === '1.1.1.1, 2.2.2.2');

$menu = (string) file_get_contents(OPM_ROOT . '/src/Menu.php');
check('menu has a coverage entry', str_contains($menu, "'coverage'") && str_contains($menu, 'Coverage\\Page'));
$front = (string) @file_get_contents(OPM_ROOT . '/front/coverage.php');
check('front checks the monitoring right', str_contains($front, 'Session::checkRight(RightDefinition::MONITORING, READ)'));
check('front sends CSV with a download name', str_contains($front, 'Content-Disposition: attachment') && str_contains($front, 'Csv::render('));

done();
