<?php

namespace GlpiPlugin\Opmanager;

/**
 * "Monitoring" section of the shared PGN Suite asset tab (hub §5, spec §9).
 * A summary, never a browser: no pager (the tab loads by AJAX, hub §4), newest
 * MAX_ROWS rows, and an explicit "Showing N of M" line when truncated.
 */
final class AssetSections
{
    public const KEY      = 'monitoring';
    public const ORDER    = 60;
    public const MAX_ROWS = 20;

    /** @return list<array{key: string, title: string, icon: string, order: int, render: string}> */
    public static function sections(\CommonDBTM $item): array
    {
        if (!\Session::haveRight(RightDefinition::MONITORING, READ)) {
            return [];
        }
        // Validate the itemtype HERE, at render time - custom asset classes do not
        // exist yet at plugin_init (hub §5).
        if (!in_array($item::class, Config::assetItemtypes(), true)) {
            return [];
        }
        if (Device::findForAsset($item::class, (int) $item->getID()) === []) {
            return [];
        }
        return [[
            'key'    => self::KEY,
            'title'  => __('Monitoring', 'opmanager'),
            'icon'   => 'ti ti-activity',
            'order'  => self::ORDER,
            'render' => self::class . '::render',
        ]];
    }

    public static function render(\CommonDBTM $item): void
    {
        $devices = Device::findForAsset($item::class, (int) $item->getID());
        foreach ($devices as &$device) {
            $device['url'] = Device::getFormURLWithID((int) $device['id']);
        }
        unset($device);

        echo self::html(self::viewModel(
            $devices,
            (int) strtotime((string) \Session::getCurrentTime()),
            (int) Config::get('stale_warning_minutes'),
            Config::get('last_device_sync')
        ));
    }

    /**
     * Pure.
     *
     * @param list<array<string, mixed>> $devices
     * @return array{stale: bool, last_sync: string, stale_minutes: int, total: int, rows: list<array<string, mixed>>}
     */
    public static function viewModel(array $devices, int $now, int $stale_minutes, string $last_sync): array
    {
        $synced = $last_sync === '' ? false : strtotime($last_sync);
        return [
            'stale'         => $synced === false || $synced < $now - $stale_minutes * 60,
            'last_sync'     => $last_sync,
            'stale_minutes' => $stale_minutes,
            'total'         => count($devices),
            'rows'          => array_slice($devices, 0, self::MAX_ROWS),
        ];
    }

    /** Pure. @param array{stale: bool, last_sync: string, stale_minutes: int, total: int, rows: list<array<string, mixed>>} $model */
    public static function html(array $model): string
    {
        $out = '<div class="p-3" id="opmanager-monitoring">';

        if ($model['stale']) {
            $out .= '<div class="alert alert-warning">'
                . htmlescape($model['last_sync'] === ''
                    ? __('OpManager data has never been synced.', 'opmanager')
                    : sprintf(__('OpManager data is older than %d minutes (last sync: %s).', 'opmanager'), $model['stale_minutes'], $model['last_sync']))
                . '</div>';
        }

        $sources = ['name' => __('by name', 'opmanager'), 'ip' => __('by IP', 'opmanager'), 'manual' => __('manual pin', 'opmanager')];
        $out .= '<table class="table table-sm table-hover mb-2"><thead><tr>'
            . '<th>' . __s('OpManager device', 'opmanager') . '</th>'
            . '<th>' . __s('IP address') . '</th>'
            . '<th>' . __s('Availability', 'opmanager') . '</th>'
            . '<th>' . __s('Last seen', 'opmanager') . '</th>'
            . '<th>' . __s('Matched', 'opmanager') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($model['rows'] as $row) {
            $name = (string) (($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['name'] ?? ''));
            $link = isset($row['url']) ? '<a href="' . htmlescape((string) $row['url']) . '">' . htmlescape($name) . '</a>' : htmlescape($name);
            $out .= '<tr data-opm-device="' . (int) ($row['id'] ?? 0) . '">'
                . '<td>' . $link . '</td>'
                . '<td>' . htmlescape((string) ($row['ip_address'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) ($row['availability'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) ($row['last_seen'] ?? '')) . '</td>'
                . '<td>' . htmlescape($sources[$row['match_source'] ?? ''] ?? '') . '</td>'
                . '</tr>';
        }
        $out .= '</tbody></table>';

        if ($model['total'] > count($model['rows'])) {
            $out .= '<div class="text-muted">' . htmlescape(sprintf(__('Showing %d of %d', 'opmanager'), count($model['rows']), $model['total'])) . '</div>';
        }

        return $out . '</div>';
    }
}
