<?php

/**
 * Coverage page (v0.6.0, D28): OpManager devices vs GLPI assets, five follow-up lists,
 * CSV export of a whole list.
 */

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\Coverage\Csv;
use GlpiPlugin\Opmanager\Coverage\DbCoverageSource;
use GlpiPlugin\Opmanager\Coverage\Page;
use GlpiPlugin\Opmanager\Coverage\Report;
use GlpiPlugin\Opmanager\Coverage\View;
use GlpiPlugin\Opmanager\RightDefinition;
use GlpiPlugin\Opmanager\Ticketing\AddressingRanges;

Session::checkRight(RightDefinition::MONITORING, READ);

$list  = in_array($_GET['list'] ?? '', View::LISTS, true) ? (string) $_GET['list'] : View::LISTS[0];
$state = [
    'list'            => $list,
    'page'            => max(1, (int) ($_GET['page'] ?? 1)),
    'production_only' => !empty($_GET['production_only']),
    'category'        => (string) ($_GET['category'] ?? ''),
];
$cfg    = Config::ticketingConfig();
$source = new DbCoverageSource();
$report = Report::build($source->devices(), $source->assets(Config::assetItemtypes(), Config::coverageStates()), [
    'ranges'           => AddressingRanges::load($cfg['production_addressings']),
    'production_only'  => $state['production_only'],
    'category'         => $state['category'],
    'last_device_sync' => Config::get('last_device_sync'),
    'stale_minutes'    => max(1, (int) Config::get('stale_warning_minutes')),
]);

if (($_GET['export'] ?? '') === 'csv') {
    $csv = View::csvRows($list, $report['lists'][$list]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="opmanager-coverage-' . $list . '-' . date('Ymd') . '.csv"');
    echo Csv::render($csv['header'], $csv['rows']);
    return;
}

Html::header(Page::getTypeName(), '', 'opmanager', 'coverage');
echo View::render($report, $state, Page::getSearchURL(false));
Html::footer();
