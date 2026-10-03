<?php

use GlpiPlugin\Opmanager\Device;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(Device::getTypeName(2), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'opmanager', 'device');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('opmanager', 'device');
Search::show(Device::class);
Html::footer();
