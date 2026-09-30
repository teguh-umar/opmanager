<?php

namespace {
    require __DIR__ . '/bootstrap.php';

    $GLOBALS['RIGHTS'] = [];
    $GLOBALS['CFG_VALUES'] = ['asset_itemtypes' => '["Computer","Glpi\\\\CustomAsset\\\\SecurityAsset"]'];
    $GLOBALS['DEVICES'] = [];

    class Session
    {
        public static function haveRight($name, $mask) { return ((int) ($GLOBALS['RIGHTS'][$name] ?? 0) & $mask) === $mask; }
    }
    class Config
    {
        public static function getConfigurationValues($context, $names = [])
        {
            return array_intersect_key($GLOBALS['CFG_VALUES'], array_flip($names));
        }
    }
    class CommonGLPI {}
    class CommonDBTM extends CommonGLPI
    {
        public function __construct(private int $id = 1) {}
        public function getID() { return $this->id; }
    }
    class Computer extends CommonDBTM {}
    class Printer extends CommonDBTM {}
    class Ticket { public static function getFormURLWithID($id, $full = true) { return '/front/ticket.form.php?id=' . (int) $id; } }
}

namespace Glpi\CustomAsset {
    // At plugin_init this class would NOT exist; the section still has to work at render time.
    class SecurityAsset extends \CommonDBTM {}
}

namespace GlpiPlugin\Opmanager {
    final class CustomAssets
    {
        public static function active(): array { return ['Glpi\\CustomAsset\\SecurityAsset' => 'Security']; }
    }
    final class Alarm
    {
        public static function forAsset(string $t, int $id, int $limit): array { return $GLOBALS['ALARMS'] ?? []; }
        public static function countForAsset(string $t, int $id): int { return count($GLOBALS['ALARMS'] ?? []); }
    }
    final class Device
    {
        public static function findForAsset(string $itemtype, int $id): array { return $GLOBALS['DEVICES']; }
        public static function matchStateLabels(): array { return ['matched' => 'Matched']; }
        public static function getFormURLWithID($id, $full = true) { return '/plugins/opmanager/front/device.form.php?id=' . (int) $id; }
    }
}

namespace {
    require OPM_ROOT . '/src/Config.php';
    require OPM_ROOT . '/src/RightDefinition.php';
    require OPM_ROOT . '/src/AssetSections.php';

    use GlpiPlugin\Opmanager\AssetSections;
    use GlpiPlugin\Opmanager\RightDefinition as R;

    $row = ['id' => 7, 'name' => '10.20.3.3', 'display_name' => '<b>vm-contoh-01</b>', 'ip_address' => '10.20.3.3',
            'availability' => 'Clear', 'match_source' => 'name', 'last_seen' => '2026-09-29 09:55:00'];

    check('no monitoring right: no section', AssetSections::sections(new Computer()) === []);
    $GLOBALS['RIGHTS'] = [R::MONITORING => READ];
    check('no paired device: no section', AssetSections::sections(new Computer()) === []);
    $GLOBALS['DEVICES'] = [$row];
    check('type not enabled: no section', AssetSections::sections(new Printer()) === []);

    $s = AssetSections::sections(new Computer());
    check('section key/order/render per contract', ($s[0]['key'] ?? null) === 'monitoring' && $s[0]['order'] === 60
        && $s[0]['render'] === 'GlpiPlugin\\Opmanager\\AssetSections::render');
    check('custom asset gets the section', count(AssetSections::sections(new Glpi\CustomAsset\SecurityAsset())) === 1);

    $now = strtotime('2026-09-29 10:00:00');
    $m = AssetSections::viewModel([$row], $now, 30, '2026-09-29 09:50:00');
    check('fresh data is not stale', $m['stale'] === false);
    $m = AssetSections::viewModel([$row], $now, 30, '2026-09-29 08:00:00');
    check('old sync is stale', $m['stale'] === true);
    $m = AssetSections::viewModel([$row], $now, 30, '');
    check('never synced is stale', $m['stale'] === true && $m['last_sync'] === '');

    $html = AssetSections::html(AssetSections::viewModel([$row], $now, 30, '2026-09-29 08:00:00'));
    check('device name escaped', str_contains($html, '&lt;b&gt;vm-contoh-01') && !str_contains($html, '<b>vm-contoh-01'));
    check('stale warning shown', str_contains($html, 'alert-warning'));
    check('no pager markup (AJAX tab rule)', !preg_match('/pagination|[?&]start=|page-link/', $html));
    check('dom ids prefixed with plugin key', !preg_match('/\sid="(?!opmanager-)/', $html));

    $many = array_fill(0, 25, $row);
    $html = AssetSections::html(AssetSections::viewModel($many, $now, 30, '2026-09-29 09:59:00'));
    check('capped at MAX_ROWS rows', substr_count($html, '<tr data-opm-device') === AssetSections::MAX_ROWS);
    check('truncation is visible', str_contains($html, 'Showing 20 of 25'));

    $GLOBALS['DEVICES'] = [];
    $GLOBALS['ALARMS'] = [['alarm_key' => '10000003244', 'severity_label' => 'Critical', 'message' => '<script>x</script>', 'state' => 'active', 'tickets_id' => 55, 'ticket_pending' => 0, 'last_change' => '2026-09-30 10:00:00']];
    check('alarms alone show the section', count(AssetSections::sections(new Computer())) === 1);
    $html = AssetSections::html(AssetSections::viewModel([], $now, 30, '2026-09-30 09:59:00', $GLOBALS['ALARMS'], 1));
    check('alarm message escaped', str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>x'));
    check('alarm links its ticket', str_contains($html, '/front/ticket.form.php?id=55'));
    $many = array_fill(0, 25, $GLOBALS['ALARMS'][0]);
    $html = AssetSections::html(AssetSections::viewModel([], $now, 30, '2026-09-30 09:59:00', array_slice($many, 0, 20), 25));
    check('alarm truncation visible', str_contains($html, 'Showing 20 of 25 alarms'));
    check('still no pager', !preg_match('/pagination|page-link/', $html));

    done();
}
