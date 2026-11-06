<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Ticketing/TicketGateway.php';
require OPM_ROOT . '/src/Hygiene/TicketPlanner.php';

use GlpiPlugin\Opmanager\Hygiene\TicketPlanner as P;
use GlpiPlugin\Opmanager\Ticketing\TicketGateway;

$two = ['a' => 'nms_dead', 'b' => 'nms_unknown'];
$rep = static function (array $states, array $resolved = []): array {
    $out = [];
    foreach ($states as $key => $state) {
        $out[$key] = ['state' => $state, 'resolved' => in_array($key, $resolved, true)];
    }
    return $out;
};

$p = P::plan([], [], null);
check('no ticket, nothing to report: none', $p['action'] === 'none');

$p = P::plan($two, [], null);
check('no ticket: create', $p['action'] === 'create');
check('create lists every device as new', $p['new'] === $two && $p['changed'] === [] && $p['resolved'] === []);

check('solved ticket: create', P::plan($two, $rep($two), 5)['action'] === 'create');
check('closed ticket: create', P::plan($two, $rep($two), 6)['action'] === 'create');
check('trashed ticket: create (Review Focus 5)', P::plan($two, $rep($two), TicketGateway::TRASHED)['action'] === 'create');
check('closed ticket, nothing to report: none', P::plan([], $rep($two), 6)['action'] === 'none');

$p = P::plan($two, $rep($two), 2);
check('open ticket, same states: none', $p['action'] === 'none' && $p['new'] === [] && $p['changed'] === [] && $p['resolved'] === []);

$p = P::plan($two + ['c' => 'nms_dead'], $rep($two), 2);
check('open + one new: followup', $p['action'] === 'followup');
check('only the new device is listed as new', $p['new'] === ['c' => 'nms_dead']);

$p = P::plan(['a' => 'nms_unknown', 'b' => 'nms_unknown'], $rep($two), 2);
check('state change in the same flow: changed', $p['action'] === 'followup' && $p['changed'] === ['a' => ['nms_dead', 'nms_unknown']] && $p['new'] === []);

$p = P::plan(['a' => 'nms_dead'], $rep($two), 2);
check('reported device gone: resolved', $p['action'] === 'followup' && $p['resolved'] === ['b']);

$p = P::plan($two, $rep($two, ['b']), 2);
check('resolved device back: new again', $p['action'] === 'followup' && $p['new'] === ['b' => 'nms_unknown']);

$p = P::plan(['a' => 'nms_dead'], $rep($two, ['b']), 2);
check('already resolved device is not resolved twice', $p['action'] === 'none' && $p['resolved'] === []);

done();
