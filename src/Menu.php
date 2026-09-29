<?php

namespace GlpiPlugin\Opmanager;

use Session;

/**
 * Own top-level sidebar sector `opmanager` (riskregister v1.2.0 pattern, hub §4).
 * The sidebar renders only `content` entries that have a `page`; `title` is the
 * only way a new sector gets its label. No Setup entry: settings live in
 * Setup > General > PGN Suite.
 */
class Menu extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'OpManager';
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-activity';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(RightDefinition::MONITORING, READ)) {
            return false;
        }
        return [
            'is_multi_entries' => true,
            'title'            => self::getTypeName(),
            'device'           => [
                'title' => Device::getTypeName(2),
                'page'  => Device::getSearchURL(false),
                'icon'  => Device::getIcon(),
                'links' => ['search' => Device::getSearchURL(false)],
            ],
        ];
    }
}
