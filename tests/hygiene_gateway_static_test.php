<?php

require __DIR__ . '/bootstrap.php';

// GLPI-side adapters run on the VM; offline we pin the rules that are cheap to break.
$gw = (string) @file_get_contents(OPM_ROOT . '/src/Hygiene/GlpiHygieneGateway.php');
check('hygiene tickets are Requests', str_contains($gw, '\Ticket::DEMAND_TYPE'));
check('type set after the template predefined fields', (bool) preg_match('/\$input = \$predefined;.*?\$input\[\'type\'\]\s*=\s*\\\\Ticket::DEMAND_TYPE;/s', $gw));
check('assigned and observer groups', str_contains($gw, "'_groups_id_assign'") && str_contains($gw, "'_groups_id_observer'"));
check('requester is the service user', str_contains($gw, "'_users_id_requester'"));
check('template mandatory fields checked', str_contains($gw, 'TicketBuilder::missingMandatory('));
check('Request template when none configured', str_contains($gw, 'getITILTemplateToUse(0, \Ticket::DEMAND_TYPE'));
check('followup without auto-assign', str_contains($gw, 'GlpiTicketGateway::withoutAutoAssign('));
check('followup never reopens', str_contains($gw, "'_no_reopen' => 1"));
check('trashed ticket reported as TRASHED', str_contains($gw, 'TicketGateway::TRASHED'));
check('no raw writes to core ticket tables', !preg_match("/DB->(insert|update|delete)\(\s*'glpi_(tickets|itilfollowups|groups_tickets)/", $gw));

$store = (string) @file_get_contents(OPM_ROOT . '/src/Hygiene/DbHygieneStore.php');
check('store writes only plugin tables', !preg_match("/DB->(insert|update|delete)\(\s*'glpi_(?!plugin_opmanager_)/", $store));
check('store keeps stored liveness when null is passed', str_contains($store, '!== null'));

$cron = (string) @file_get_contents(OPM_ROOT . '/src/Cron.php');
check('HygieneCheck cron exists', str_contains($cron, 'public static function cronHygieneCheck(\CronTask $task): int'));
check('cron info for HygieneCheck', str_contains($cron, "'HygieneCheck' =>"));
check('ticket run advances only on a clean run (Review Focus 4)', (bool) preg_match("/errors'\] === \[\].*?'last_hygiene_ticket_run'/s", $cron));
check('hygiene error kept visible', str_contains($cron, "'last_hygiene_error'"));
check('cron uses the provider registry', str_contains($cron, 'Providers::all('));

$tg = (string) file_get_contents(OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php');
check('withoutAutoAssign shared, not copied', str_contains($tg, 'public static function withoutAutoAssign(') && !str_contains($gw, 'glpiset_followup_tech'));

done();
