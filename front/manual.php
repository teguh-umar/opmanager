<?php

use GlpiPlugin\Opmanager\Manual;
use GlpiPlugin\Opmanager\RightDefinition;

include('../../../inc/includes.php');

// v1.3.0-rc4: the user manual (suite rule 2026-11-09). Anyone who may see the plugin may read it.
Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(__('Manual', 'opmanager'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'opmanager', 'manual');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('opmanager', 'manual');

echo '<div class="card"><div class="card-body">';
echo Manual::body(Manual::read(), PLUGIN_OPMANAGER_VERSION);
echo '</div></div>';

Html::footer();
