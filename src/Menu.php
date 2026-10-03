<?php

namespace GlpiPlugin\Opmanager;

use Session;

/**
 * OpManager's entry in the shared suite sidebar menu (hub §5, lib/pgnsuite/Menu.php;
 * v1.2.0). Before, the plugin had its own top-level sector `opmanager`. No Setup entry:
 * settings live in Setup > General > (suite tab).
 */
class Menu
{
    /** Provider for \GlpiPlugin\Pgnsuite\Menu::HOOK; null hides the entry. */
    public static function entry(): ?array
    {
        if (!Session::haveRight(RightDefinition::MONITORING, READ)) {
            return null;
        }
        return [
            'title'   => 'OpManager',
            'icon'    => 'ti ti-activity',
            'page'    => Device::getSearchURL(false),
            'order'   => 60,
            'options' => [
                'device'   => [
                    'title'          => Device::getTypeName(2),
                    'page'           => Device::getSearchURL(false),
                    'icon'           => Device::getIcon(),
                    'links'          => ['search' => Device::getSearchURL(false)],
                    'lists_itemtype' => Device::class,
                ],
                'alarm'    => [
                    'title'          => Alarm::getTypeName(2),
                    'page'           => Alarm::getSearchURL(false),
                    'icon'           => Alarm::getIcon(),
                    'links'          => ['search' => Alarm::getSearchURL(false)],
                    'lists_itemtype' => Alarm::class,
                ],
                'coverage' => [
                    'title' => \GlpiPlugin\Opmanager\Coverage\Page::getTypeName(),
                    'page'  => \GlpiPlugin\Opmanager\Coverage\Page::getSearchURL(false),
                    'icon'  => \GlpiPlugin\Opmanager\Coverage\Page::getIcon(),
                ],
            ],
        ];
    }
}
