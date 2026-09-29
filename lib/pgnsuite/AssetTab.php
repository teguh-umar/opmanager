<?php

/**
 * PGN Suite — shared "PGN Suite" tab on asset forms (Computer, NetworkEquipment,
 * custom assets …), with one horizontal sub-tab per plugin section.
 *
 * CANONICAL COPY: …/Documents/PGN GLPI Suite/shared/pgnsuite/AssetTab.php
 * Every participating plugin ships this file byte-identical at lib/pgnsuite/AssetTab.php.
 * Never edit a plugin's copy on its own — change the canonical copy, bump
 * CONTRACT_VERSION if the API changes, and redistribute (see hub file §5).
 */

namespace GlpiPlugin\Pgnsuite;

use CommonDBTM;
use CommonGLPI;
use Plugin;

final class AssetTab extends CommonGLPI
{
    public const CONTRACT_VERSION = 1;

    public const HOOK = 'pgnsuite_asset_section';

    private const SESSION_KEY = 'pgnsuite_asset_active_section';

    private const KEY_PATTERN = '/^[a-z0-9_-]+$/';

    /**
     * Put the shared tab on each given itemtype. Several plugins may call this with
     * different lists: registerStandardTab() keys by [itemtype][class], so the result
     * is the union, with exactly one tab per itemtype.
     *
     * @param string[] $itemtypes
     */
    public static function register(array $itemtypes): void
    {
        $itemtypes = array_values(array_filter($itemtypes, 'is_string'));
        if ($itemtypes !== []) {
            Plugin::registerClass(self::class, ['addtabon' => $itemtypes]);
        }
    }

    public static function getTypeName($nb = 0)
    {
        return 'PGN Suite';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem() || self::sections($item) === []) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-stack-2');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || $item->isNewItem()) {
            return false;
        }
        $sections = self::sections($item);
        if ($sections === []) {
            return false;
        }

        $active = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!isset($sections[$active])) {
            $active = array_key_first($sections);
        }

        // Same look as integrasipaman's tabbed report card: card-header nav-tabs.
        // All panes render in this one AJAX load and Bootstrap switches between them,
        // so no sub-tab navigation ever leaves the asset form (hub §4).
        echo '<div class="card pgnsuite-asset">';
        echo '<div class="card-header"><ul class="nav nav-tabs card-header-tabs" role="tablist">';
        foreach ($sections as $key => $section) {
            $is_active = $key === $active;
            echo sprintf(
                '<li class="nav-item" role="presentation"><button type="button" class="nav-link%s" data-bs-toggle="tab" data-bs-target="#pgnsuite-asset-%s" data-pgnsuite-section="%s" role="tab" aria-selected="%s"><i class="%s me-2"></i>%s</button></li>',
                $is_active ? ' active' : '',
                htmlescape($key),
                htmlescape($key),
                $is_active ? 'true' : 'false',
                htmlescape($section['icon']),
                htmlescape($section['title'])
            );
        }
        echo '</ul></div>';

        echo '<div class="card-body p-0"><div class="tab-content">';
        foreach ($sections as $key => $section) {
            echo sprintf(
                '<div class="tab-pane fade%s" id="pgnsuite-asset-%s" role="tabpanel">',
                $key === $active ? ' show active' : '',
                htmlescape($key)
            );
            call_user_func($section['render'], $item);
            echo '</div>';
        }
        echo '</div></div></div>';

        // Deep link: ?pgnsuite_section=<key> on the asset URL (see getItemUrl()) opens that
        // sub-tab. Progressive enhancement only — without it the first/remembered pane shows.
        echo '<script>(function(){var k=new URLSearchParams(window.location.search).get("pgnsuite_section");'
            . 'if(!k||!window.bootstrap){return;}'
            . 'var b=document.querySelector(\'.pgnsuite-asset [data-pgnsuite-section="\'+k.replace(/[^a-z0-9_-]/g,"")+\'"]\');'
            . 'if(b){bootstrap.Tab.getOrCreateInstance(b).show();}})();</script>';

        return true;
    }

    /**
     * Call before redirecting back to the asset form (e.g. after a POST from inside a
     * section), so the form reopens on this tab with that section selected. GLPI itself
     * remembers the main tab; this remembers the sub-tab. One-shot.
     */
    public static function rememberSection(string $section): void
    {
        $_SESSION[self::SESSION_KEY] = $section;
    }

    /**
     * Link to an asset form opened on this tab with the given section selected.
     */
    public static function getItemUrl(CommonDBTM $item, string $section): string
    {
        return $item::getFormURLWithID($item->getID())
            . '&forcetab=' . urlencode(self::class . '$1')
            . '&pgnsuite_section=' . urlencode($section);
    }

    /**
     * @return array<string, array{title: string, icon: string, order: int, render: string}>
     *         keyed "<plugin>-<section key>"
     */
    private static function sections(CommonDBTM $item): array
    {
        global $PLUGIN_HOOKS;

        $sections = [];
        foreach ($PLUGIN_HOOKS[self::HOOK] ?? [] as $plugin => $provider) {
            if (
                !is_string($plugin)
                || !preg_match('/^[a-z0-9]+$/', $plugin)
                || !is_string($provider)
                || !Plugin::isPluginActive($plugin)
                || !is_callable($provider)
            ) {
                continue;
            }
            // A provider returns the sections this user may see on this item: [] for none.
            $provided = call_user_func($provider, $item);
            if (!is_array($provided)) {
                continue;
            }
            foreach ($provided as $section) {
                if (
                    !is_array($section)
                    || !is_string($section['key'] ?? null)
                    || !preg_match(self::KEY_PATTERN, $section['key'])
                    || !is_string($section['title'] ?? null)
                    || !is_string($section['render'] ?? null)
                    || !is_callable($section['render'])
                ) {
                    continue;
                }
                $sections[$plugin . '-' . $section['key']] = [
                    'title'  => $section['title'],
                    'icon'   => is_string($section['icon'] ?? null) ? $section['icon'] : 'ti ti-puzzle',
                    'order'  => (int) ($section['order'] ?? 500),
                    'render' => $section['render'],
                ];
            }
        }

        uasort(
            $sections,
            static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]
        );

        return $sections;
    }
}
