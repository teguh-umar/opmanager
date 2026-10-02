<?php

namespace {
    require __DIR__ . '/bootstrap.php';
    $GLOBALS['RIGHTS'] = [];
    class Session
    {
        public static function haveRight($n, $m) { return ((int) ($GLOBALS['RIGHTS'][$n] ?? 0) & $m) === $m; }
    }
    class Html { public static function getPrefixedUrl($u) { return $u; } }
    class CommonGLPI {}
    require OPM_ROOT . '/src/RightDefinition.php';
    require OPM_ROOT . '/src/Coverage/Page.php';
    require OPM_ROOT . '/src/Coverage/Report.php';
    require OPM_ROOT . '/src/Coverage/Cards.php';

    use GlpiPlugin\Opmanager\Coverage\Cards;
    use GlpiPlugin\Opmanager\RightDefinition as R;

    check('no monitoring right: no card offered', Cards::cards(['core' => []]) === ['core' => []]);
    check('no monitoring right: provider returns zero, never data', Cards::coverage()['number'] === 0 && Cards::matching()['data'] === []);

    $GLOBALS['RIGHTS'] = [R::MONITORING => READ];
    $cards = Cards::cards(['core' => []]);
    $mine  = array_filter($cards, fn ($k) => str_starts_with($k, 'plugin_opmanager_'), ARRAY_FILTER_USE_KEY);
    check('eight cards registered, existing kept', count($mine) === 8 && isset($cards['core']));
    $ok = true;
    foreach ($mine as $card) {
        [$class, $method] = explode('::', $card['provider']);
        $ok = $ok && is_string($card['provider']) && method_exists($class, $method) && $card['group'] === 'OpManager' && $card['widgettype'] !== [];
    }
    check('providers are Class::method strings that exist (GLPI explodes them)', $ok);

    $bars = Cards::categoryBars(['Server' => ['matched' => 2, 'unmatched' => 1, 'review' => 1], 'Switch' => ['matched' => 0, 'unmatched' => 1, 'review' => 0]]);
    check('stacked bars shape', $bars['labels'] === ['Server', 'Switch'] && count($bars['series']) === 3 && $bars['series'][0]['data'] === [2, 0]);

    $store = []; $calls = 0;
    $f = function () use (&$calls) { return ++$calls; };
    Cards::cached($store, 'k', 300, 1000, $f);
    Cards::cached($store, 'k', 300, 1200, $f);
    check('cache reused within ttl', $calls === 1);
    Cards::cached($store, 'k', 300, 1301, $f);
    check('cache recomputed after ttl', $calls === 2);
    Cards::cached($store, 'other', 300, 1301, $f);
    check('cache keyed (per entity set)', $calls === 3);

    $setup = (string) file_get_contents(OPM_ROOT . '/setup.php');
    check('dashboard hook registered with a Class::method string', str_contains($setup, "\$PLUGIN_HOOKS['dashboard_cards']['opmanager'] = 'GlpiPlugin\\\\Opmanager\\\\Coverage\\\\Cards::cards';"));

    done();
}
