<?php

use GlpiPlugin\Opmanager\Alarm;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(Alarm::getTypeName(2), '', 'opmanager', 'alarm');
Search::show(Alarm::class);
Html::footer();
