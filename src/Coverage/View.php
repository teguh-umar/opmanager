<?php

namespace GlpiPlugin\Opmanager\Coverage;

/**
 * Pure HTML for the Coverage page (spec §15.1): KPI cards, filters, one tab per follow-up
 * list, a paged table and a CSV link. Every value goes through htmlescape(); links are
 * built only from a class name and an int id.
 */
final class View
{
    public const LISTS = ['not_in_glpi', 'not_monitored', 'ambiguous', 'name_differs', 'stale'];

    public const PER_PAGE = 50;

    /**
     * @param array{kpi: array<string, int>, lists: array<string, list<array<string, mixed>>>, categories: list<string>} $report
     * @param array{list: string, page: int, production_only: bool, category: string} $state
     */
    public static function render(array $report, array $state, string $base_url): string
    {
        $k    = $report['kpi'];
        $html = '<div class="container-fluid">';

        $cards = [
            [__('Coverage', 'opmanager'), $k['coverage_pct'] . '%', sprintf(__('%1$d of %2$d GLPI assets monitored', 'opmanager'), $k['monitored'], $k['assets'])],
            [__('GLPI assets in scope', 'opmanager'), (string) $k['assets'], __('selected types, status, with an IP', 'opmanager')],
            [__('OpManager devices', 'opmanager'), (string) $k['devices'], sprintf(__('%d matched to a GLPI asset', 'opmanager'), $k['matched'])],
            [__('Not in GLPI', 'opmanager'), (string) $k['not_in_glpi'], __('monitored but not registered', 'opmanager')],
            [__('Not monitored', 'opmanager'), (string) $k['not_monitored'], __('registered but not in OpManager', 'opmanager')],
            [__('Ambiguous', 'opmanager'), (string) $k['ambiguous'], __('needs a manual pairing', 'opmanager')],
        ];
        $html .= '<div class="row row-cards mb-3">';
        foreach ($cards as [$label, $value, $hint]) {
            $html .= '<div class="col-6 col-md-4 col-xl-2"><div class="card"><div class="card-body">'
                . '<div class="subheader">' . htmlescape($label) . '</div>'
                . '<div class="h1 mb-0">' . htmlescape($value) . '</div>'
                . '<div class="text-muted small">' . htmlescape($hint) . '</div>'
                . '</div></div></div>';
        }
        $html .= '</div>';

        // Filters (GET, keeps the current list)
        $html .= '<form method="get" action="' . htmlescape($base_url) . '" class="d-flex flex-wrap align-items-center gap-3 mb-3">'
            . '<input type="hidden" name="list" value="' . htmlescape($state['list']) . '">'
            . '<label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="production_only" value="1"' . ($state['production_only'] ? ' checked' : '') . '>'
            . '<span class="form-check-label">' . htmlescape(__('Production IP ranges only', 'opmanager')) . '</span></label>'
            . '<select class="form-select w-auto" name="category"><option value="">' . htmlescape(__('All categories', 'opmanager')) . '</option>';
        foreach ($report['categories'] as $cat) {
            $html .= '<option value="' . htmlescape($cat) . '"' . ($cat === $state['category'] ? ' selected' : '') . '>' . htmlescape($cat) . '</option>';
        }
        $html .= '</select><button type="submit" class="btn btn-primary">' . htmlescape(__('Apply', 'opmanager')) . '</button></form>';

        // Tabs
        $titles = self::titles();
        $html .= '<ul class="nav nav-tabs">';
        foreach (self::LISTS as $list) {
            $html .= '<li class="nav-item"><a class="nav-link' . ($list === $state['list'] ? ' active' : '') . '" href="'
                . htmlescape(self::url($base_url, ['list' => $list, 'page' => 1] + $state)) . '">'
                . htmlescape($titles[$list]) . ' (' . count($report['lists'][$list]) . ')</a></li>';
        }
        $html .= '</ul>';

        // Table of the current list, one page
        $rows  = $report['lists'][$state['list']] ?? [];
        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page  = min(max(1, $state['page']), $pages);
        $cols  = self::columns($state['list']);
        $html .= '<div class="card border-top-0"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>';
        foreach ($cols as $c) {
            $html .= '<th>' . htmlescape($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE) as $row) {
            $html .= '<tr>';
            foreach (self::cells($state['list'], $row, true) as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="' . count($cols) . '" class="text-muted">' . htmlescape(__('Nothing to follow up', 'opmanager')) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div><div class="card-footer d-flex justify-content-between align-items-center">';
        $html .= '<div>';
        if ($page > 1) {
            $html .= '<a class="btn btn-sm btn-outline-secondary me-1" href="' . htmlescape(self::url($base_url, ['page' => $page - 1] + $state)) . '">&lsaquo;</a>';
        }
        $html .= '<span class="mx-2">' . htmlescape(sprintf(__('Page %1$d of %2$d', 'opmanager'), $page, $pages)) . '</span>';
        if ($page < $pages) {
            $html .= '<a class="btn btn-sm btn-outline-secondary ms-1" href="' . htmlescape(self::url($base_url, ['page' => $page + 1] + $state)) . '">&rsaquo;</a>';
        }
        $html .= '</div><a class="btn btn-sm btn-outline-primary" href="' . htmlescape(self::url($base_url, ['export' => 'csv'] + $state)) . '">'
            . '<i class="ti ti-file-spreadsheet me-1"></i>' . htmlescape(__('Export CSV', 'opmanager')) . '</a></div></div></div>';

        return $html;
    }

    /**
     * Same columns as the table, without links: for the CSV export of the whole list.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public static function csvRows(string $list, array $rows): array
    {
        return [
            'header' => self::columns($list),
            'rows'   => array_map(static fn (array $r) => self::cells($list, $r, false), $rows),
        ];
    }

    /** @return array<string, string> */
    private static function titles(): array
    {
        return [
            'not_in_glpi'   => __('Not in GLPI', 'opmanager'),
            'not_monitored' => __('Not monitored', 'opmanager'),
            'ambiguous'     => __('Ambiguous', 'opmanager'),
            'name_differs'  => __('Name differs', 'opmanager'),
            'stale'         => __('Stale', 'opmanager'),
        ];
    }

    /** @return list<string> */
    private static function columns(string $list): array
    {
        return match ($list) {
            'not_monitored' => [__('GLPI asset', 'opmanager'), __('Type', 'opmanager'), __('Entity', 'opmanager'), __('IP addresses', 'opmanager'), __('Status', 'opmanager')],
            'ambiguous'     => [__('Device', 'opmanager'), __('IP address', 'opmanager'), __('Category', 'opmanager')],
            'name_differs'  => [__('Device name', 'opmanager'), __('GLPI asset name', 'opmanager'), __('IP address', 'opmanager')],
            'stale'         => [__('Device', 'opmanager'), __('IP address', 'opmanager'), __('Last seen', 'opmanager')],
            default         => [__('Device', 'opmanager'), __('IP address', 'opmanager'), __('Category', 'opmanager'), __('Type', 'opmanager'), __('Last seen', 'opmanager')],
        };
    }

    /**
     * @param array<string, mixed> $r
     * @return list<string> HTML-escaped cells when $html, plain text otherwise
     */
    private static function cells(string $list, array $r, bool $html): array
    {
        $esc    = static fn ($v) => $html ? htmlescape((string) $v) : (string) $v;
        $device = (string) (($r['display_name'] ?? '') !== '' ? $r['display_name'] : ($r['name'] ?? ''));
        $dev    = $html ? self::link('GlpiPlugin\\Opmanager\\Device', (int) ($r['id'] ?? 0), $device) : $device;
        return match ($list) {
            'not_monitored' => [
                $html ? self::link((string) $r['itemtype'], (int) $r['items_id'], (string) $r['name']) : (string) $r['name'],
                $esc(self::typeName((string) $r['itemtype'])),
                $esc($r['entity']),
                $esc(implode(', ', (array) $r['ips'])),
                $esc($r['state']),
            ],
            'ambiguous'    => [$dev, $esc($r['ip']), $esc($r['category'])],
            'name_differs' => [$dev, $esc($r['asset_name'] ?? ''), $esc($r['ip'])],
            'stale'        => [$dev, $esc($r['ip']), $esc($r['last_seen'])],
            default        => [$dev, $esc($r['ip']), $esc($r['category']), $esc($r['type']), $esc($r['last_seen'])],
        };
    }

    private static function link(string $class, int $id, string $label): string
    {
        if ($id > 0 && class_exists($class) && method_exists($class, 'getFormURLWithID')) {
            return '<a href="' . htmlescape($class::getFormURLWithID($id)) . '">' . htmlescape($label) . '</a>';
        }
        return htmlescape($label);
    }

    private static function typeName(string $itemtype): string
    {
        return class_exists($itemtype) && method_exists($itemtype, 'getTypeName') ? (string) $itemtype::getTypeName(1) : $itemtype;
    }

    /** @param array<string, mixed> $state */
    private static function url(string $base, array $state): string
    {
        $q = [
            'list'            => $state['list'],
            'page'            => (int) ($state['page'] ?? 1),
            'production_only' => !empty($state['production_only']) ? 1 : 0,
            'category'        => (string) ($state['category'] ?? ''),
        ];
        if (isset($state['export'])) {
            $q['export'] = $state['export'];
            unset($q['page']);
        }
        return $base . '?' . http_build_query($q);
    }
}
