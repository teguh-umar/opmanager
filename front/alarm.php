<?php

use GlpiPlugin\Opmanager\Alarm;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(Alarm::getTypeName(2), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'opmanager', 'alarm');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('opmanager', 'alarm');
Search::show(Alarm::class);
Html::footer();
