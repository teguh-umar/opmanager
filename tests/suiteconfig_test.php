<?php

namespace {
    require __DIR__ . '/bootstrap.php';

    $GLOBALS['RIGHTS'] = [];
    $GLOBALS['CFG_VALUES'] = [];
    $GLOBALS['CFG_GLPI'] = ['url_base' => 'https://comit.pgn.co.id'];

    class CommonGLPI {}
    class Session
    {
        public static function haveRight($name, $mask)
        {
            return ((int) ($GLOBALS['RIGHTS'][$name] ?? 0) & $mask) === $mask;
        }
    }
    class Config
    {
        public static function getConfigurationValues($context, $names = [])
        {
            return $names === [] ? $GLOBALS['CFG_VALUES'] : array_intersect_key($GLOBALS['CFG_VALUES'], array_flip($names));
        }
    }
    class Html
    {
        public static function getPrefixedUrl($u) { return $u; }
        public static function hidden($n, $o = []) { return "<input type='hidden' name='{$n}' value='" . ($o['value'] ?? '') . "'>"; }
        public static function submit($l, $o = []) { return "<button name='" . ($o['name'] ?? '') . "'>{$l}</button>"; }
        public static function closeForm($display = true) { echo '</form>'; }
        public static function scriptBlock($s) { return "<script>{$s}</script>"; }
    }
    class Dropdown
    {
        public static function showFromArray($name, $elements, $options = [])
        {
            echo "<select name='{$name}'>";
            foreach ($elements as $k => $v) {
                echo "<option value='{$k}'" . (($options['value'] ?? '') === $k ? ' selected' : '') . ">{$v}</option>";
            }
            echo '</select>';
        }
    }
    class TicketTemplate { public static function dropdown($o = []) { echo "<select name='{$o['name']}'></select>"; } }
    class User { public static function dropdown($o = []) { echo "<select name='{$o['name']}'></select>"; } }
    class Entity { public static function dropdown($o = []) { echo "<select name='{$o['name']}'></select>"; } }
    class State { public static function dropdown($o = []) { echo "<select name='{$o['name']}' multiple data-value='" . json_encode($o['value'] ?? null) . "'></select>"; } }
    class FakeDB { public function tableExists($t) { return false; } }
    $GLOBALS['DB'] = new FakeDB();
}

namespace Glpi\Asset {
    class AssetDefinition
    {
        public static function getTable() { return 'glpi_assets_assetdefinitions'; }
    }
}

namespace {
    require OPM_ROOT . '/src/Config.php';
    require OPM_ROOT . '/src/CustomAssets.php';
    require OPM_ROOT . '/src/RightDefinition.php';
    require OPM_ROOT . '/src/Ticketing/ProductionCheck.php';
    require OPM_ROOT . '/src/Ticketing/RangeProductionCheck.php';
    require OPM_ROOT . '/src/Ticketing/AddressingRanges.php';
    require OPM_ROOT . '/src/Ticketing/AlarmFilter.php';
    require OPM_ROOT . '/src/Outbound/OutboxStore.php';
    require OPM_ROOT . '/src/Outbound/DbOutboxStore.php';
    require OPM_ROOT . '/src/SetupStatus.php';
    require OPM_ROOT . '/src/SuiteConfig.php';

    use GlpiPlugin\Opmanager\RightDefinition as R;
    use GlpiPlugin\Opmanager\SuiteConfig;

    check('no config right: section hidden', SuiteConfig::section() === null);

    $GLOBALS['RIGHTS'] = [R::CONFIG => READ];
    $section = SuiteConfig::section();
    check('config READ: section shown', is_array($section));
    check('render is a Class::method string', ($section['render'] ?? null) === 'GlpiPlugin\\Opmanager\\SuiteConfig::render');

    $GLOBALS['CFG_VALUES'] = ['opm_api_key' => 'ENCRYPTED-SECRET-VALUE', 'opm_url' => 'https://opm'];
    ob_start();
    SuiteConfig::showForm(true);
    $html = (string) ob_get_clean();
    check('api key never rendered', !str_contains($html, 'ENCRYPTED-SECRET-VALUE'));
    check('stored key shown as a placeholder only', str_contains($html, 'placeholder'));
    check('read-only user sees no save button', !str_contains($html, "name='update'"));
    check('suite marker present when rendered in the tab', str_contains($html, "name='_from_pgnsuite'"));
    check('posts to own controller', str_contains($html, '/plugins/opmanager/front/config.php'));
    check('tabs render for read-only', str_contains($html, 'nav-tabs') && str_contains($html, 'id="opm-tab-tickets-suite"'));

    $GLOBALS['RIGHTS'] = [R::CONFIG => READ | UPDATE];
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('editor sees save button', str_contains($html, "name='update'"));
    check('standalone page has no suite marker', !str_contains($html, '_from_pgnsuite'));
    check('dropdown offers every match mode', substr_count($html, '<option value=') >= 5);

    // Plan 4: status strip + tabs (D29)
    foreach (SuiteConfig::TABS as $tab) {
        check("tab {$tab} has a nav link and a pane", str_contains($html, 'href="#opm-tab-' . $tab . '"') && str_contains($html, 'id="opm-tab-' . $tab . '"'));
    }
    check('status strip shown above the tabs', strpos($html, 'opm-status') !== false && strpos($html, 'opm-status') < strpos($html, 'nav-tabs'));
    check('one save button for all tabs', substr_count($html, "name='update'") === 1);
    check('coverage states picker in the matching tab', (bool) preg_match('/id="opm-tab-matching".*?coverage_states\[\].*?id="opm-tab-tickets"/s', $html));
    check('last open tab remembered per browser', str_contains($html, 'sessionStorage'));
    $GLOBALS['CFG_VALUES']['coverage_states'] = '[3,7]';
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('stored coverage states shown selected (GLPI multiple reads value, not values)', str_contains($html, "data-value='[3,7]'"));

    foreach (['ticket_severities[]', 'ticket_template', 'service_user', 'ticket_entity_default', 'ticket_title_pattern', 'ticket_content_pattern', 'relink_window_hours', 'storm_threshold', 'storm_window_minutes'] as $field) {
        check("ticketing field {$field} rendered", str_contains($html, "name='{$field}'") || str_contains($html, 'name="' . $field . '"'));
    }

    check('warning when no IP Addressing range is available', str_contains($html, 'No ticket will be created'));

    // v0.3.0 alarm filter
    // Plan 3 outbound
    check('outbound retry field rendered', str_contains($html, 'name="outbound_max_attempts"'));
    check('outbound queue status shown', str_contains($html, 'Sent to OpManager'));

    check('filter mode selector rendered', str_contains($html, "name='ticket_filter_mode'") || str_contains($html, 'name="ticket_filter_mode"'));
    check('filter keywords textarea rendered', str_contains($html, 'name="ticket_filter_keywords"'));
    check('filter off is stated', str_contains($html, 'Alarm filter is off'));
    $GLOBALS['CFG_VALUES']['ticket_filter_event_types'] = '["CPU Utilization"]';
    $GLOBALS['CFG_VALUES']['ticket_filter_keywords']    = "threshold\n<b>x</b>";
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('saved event type stays selectable though not seen yet', (bool) preg_match('/name="ticket_filter_event_types\[\]" value="CPU Utilization"[^>]* checked/', $html));
    check('keywords shown escaped', str_contains($html, "threshold\n&lt;b&gt;x&lt;/b&gt;"));
    check('filter on: no off notice', !str_contains($html, 'Alarm filter is off'));

    check('webhook URL shown', str_contains($html, 'https://comit.pgn.co.id/plugins/opmanager/front/webhook.php'));
    check('webhook header name shown', str_contains($html, 'X-OPM-Token'));
    check('generate token button shown to editors', str_contains($html, "name='generate_webhook_token'"));
    $GLOBALS['CFG_VALUES']['webhook_token'] = 'ENCRYPTED-WEBHOOK-TOKEN';
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('token never rendered in setup', !str_contains($html, 'ENCRYPTED-WEBHOOK-TOKEN'));

    $_SESSION[SuiteConfig::NEW_TOKEN_KEY] = 'abc123newtoken';
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('new token shown inline in a copyable field', str_contains($html, "value='abc123newtoken'") || str_contains($html, 'value="abc123newtoken"'));
    check('new token is consumed after one display', !isset($_SESSION[SuiteConfig::NEW_TOKEN_KEY]));
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('new token not shown a second time', !str_contains($html, 'abc123newtoken'));

    done();
}
