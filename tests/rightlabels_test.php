<?php

require __DIR__ . '/bootstrap.php';
require_once OPM_ROOT . '/src/RightDefinition.php';

use GlpiPlugin\Opmanager\RightDefinition;

// Profile rights matrix: each right needs its own row label (prod showed two "Monitoring" rows).
$labels = array_map([RightDefinition::class, 'label'], RightDefinition::names());
check('every right has a label', !in_array('', $labels, true));
check('right labels are unique', count(array_unique($labels)) === count($labels), implode(' / ', $labels));
check('hygiene right label', RightDefinition::label(RightDefinition::HYGIENE) === 'NMS hygiene');
$profile = (string) file_get_contents(OPM_ROOT . '/src/Profile.php');
check('Profile form uses RightDefinition::label()', str_contains($profile, "'label'    => RightDefinition::label(\$name)"));

done();
