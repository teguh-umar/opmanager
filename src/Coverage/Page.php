<?php

namespace GlpiPlugin\Opmanager\Coverage;

use GlpiPlugin\Opmanager\RightDefinition;

/** Menu anchor for the Coverage page (D28). */
final class Page extends \CommonGLPI
{
    public static $rightname = RightDefinition::MONITORING;

    public static function getTypeName($nb = 0)
    {
        return __('Coverage', 'opmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-pie';
    }

    public static function getSearchURL($full = true)
    {
        return \Html::getPrefixedUrl('/plugins/opmanager/front/coverage.php');
    }
}
