<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/RightSeeder.php';

use GlpiPlugin\Opmanager\RightDefinition as R;
use GlpiPlugin\Opmanager\RightSeeder;

$profiles = [
    4 => ['interface' => 'central',  'config' => READ | UPDATE, 'computer' => 31], // super-admin
    6 => ['interface' => 'central',  'config' => READ,          'computer' => READ], // technician
    7 => ['interface' => 'central',  'config' => 0,             'computer' => 0],
    1 => ['interface' => 'helpdesk', 'config' => 0,             'computer' => 0],
];

// Fresh install: both rights are new.
$plan = RightSeeder::plan(R::names(), $profiles);
check('fresh: super-admin gets full mask', ($plan[4] ?? null) === R::ALL);
check('fresh: technician config mirrors core config', ($plan[6][R::CONFIG] ?? null) === READ);
check('fresh: technician monitoring from computer READ', ($plan[6][R::MONITORING] ?? null) === READ);
check('fresh: profile without core rights gets zeros', ($plan[7] ?? null) === [R::CONFIG => 0, R::MONITORING => 0]);
check('fresh: helpdesk untouched', !isset($plan[1]));

// Upgrade: nothing new. Only super-admin is (re)written - hub §4 exception.
$plan = RightSeeder::plan([], $profiles);
check('upgrade: only super-admin written', array_keys($plan) === [4]);
check('upgrade: technician hand-tuned value survives', !isset($plan[6]));

// Upgrade adding only the monitoring right: config must not be re-derived.
$plan = RightSeeder::plan([R::MONITORING], $profiles);
check('partial: technician gets only the new right', ($plan[6] ?? null) === [R::MONITORING => READ]);

// Hardened instance: no profile holds config UPDATE -> nobody gets full.
$plan = RightSeeder::plan([], [6 => $profiles[6]]);
check('no super-admin: nothing granted', $plan === []);

done();
