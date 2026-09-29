<?php

namespace {
    require __DIR__ . '/bootstrap.php';

    $GLOBALS['RIGHTS'] = [];
    $GLOBALS['CFG_VALUES'] = [];

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

    $GLOBALS['RIGHTS'] = [R::CONFIG => READ | UPDATE];
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('editor sees save button', str_contains($html, "name='update'"));
    check('standalone page has no suite marker', !str_contains($html, '_from_pgnsuite'));
    check('dropdown offers every match mode', substr_count($html, '<option value=') >= 5);

    done();
}
