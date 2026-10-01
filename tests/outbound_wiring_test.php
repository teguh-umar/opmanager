<?php

require __DIR__ . '/bootstrap.php';

$setup = (string) file_get_contents(OPM_ROOT . '/setup.php');
$gw    = (string) file_get_contents(OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php');
$hooks = (string) @file_get_contents(OPM_ROOT . '/src/Outbound/Hooks.php');
$store = (string) file_get_contents(OPM_ROOT . '/src/Ticketing/DbAlarmStore.php');

check('followup hook registered', str_contains($setup, "\$PLUGIN_HOOKS['item_add']['opmanager'] = [\\ITILFollowup::class => [\\GlpiPlugin\\Opmanager\\Outbound\\Hooks::class, 'followupAdded']];"));
check('ticket update hook registered', str_contains($setup, "\$PLUGIN_HOOKS['item_update']['opmanager'] = [\\Ticket::class => [\\GlpiPlugin\\Opmanager\\Outbound\\Hooks::class, 'ticketUpdated']];"));
foreach (['create', 'createStorm', 'followup', 'reopen', 'solve', 'link'] as $m) {
    check("gateway {$m} runs under the plugin-writing flag", (bool) preg_match('/public function ' . $m . '\(.*?\{\s*(return )?\$this->asPlugin\(/s', $gw));
}
check('hooks ask the planner who is human', substr_count($hooks, 'Planner::isHuman(') === 2 && substr_count($hooks, 'GlpiTicketGateway::isWriting()') === 2);
check('hooks never throw into GLPI', substr_count($hooks, 'catch (\Throwable $e)') >= 2);
check('hooks send with a short timeout', (bool) preg_match('/new Client\([^;]*SEND_TIMEOUT/s', $hooks));
check('solution text read from the newest solution by this user', (bool) preg_match("/ITILSolution.*?'users_id' => \\\$uid.*?'ORDER'\\s+=> 'id DESC'/s", $hooks));
check('active alarms of a ticket', (bool) preg_match("/function activeForTicket.*?'tickets_id' => \\\$tickets_id, 'state' => 'active'/s", $store));

done();
