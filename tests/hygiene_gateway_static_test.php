<?php

require __DIR__ . '/bootstrap.php';

// GLPI-side adapters run on the VM; offline we pin the rules that are cheap to break.
$gw = (string) @file_get_contents(OPM_ROOT . '/src/Hygiene/GlpiHygieneGateway.php');
check('hygiene tickets are Requests', str_contains($gw, '\Ticket::DEMAND_TYPE'));
check('type set after the template predefined fields', (bool) preg_match('/\$input = \$predefined;.*?\$input\[\'type\'\]\s*=\s*\\\\Ticket::DEMAND_TYPE;/s', $gw));
check('assigned and observer groups', str_contains($gw, "'_groups_id_assign'") && str_contains($gw, "'_groups_id_observer'"));
// rc6: Behaviors "Use the technician's group = First" (on add) replaced the hygiene groups with the
// technician's first group. The ticket is added with groups only; technicians follow as Ticket_User.
check('no technician in the add input (Behaviors would replace the groups) (rc6)', !str_contains($gw, "'_users_id_assign'"));
$tu = strpos($gw, "(new \\Ticket_User())->add(['tickets_id' => \$id, 'users_id' => \$user, 'type' => \\CommonITILActor::ASSIGN])");
check('technicians assigned after the add through Ticket_User (rc6)', $tu !== false && $tu > strpos($gw, '(new \Ticket())->add('));
check('business rules skipped: alarm rules on IP/description must not rewrite the hygiene assignees (rc5)', str_contains($gw, "\$input['_skip_rules']         = true;"));
check('optional ITIL category from Setup (rc5)', str_contains($gw, "\$input['itilcategories_id'] = \$category;") && str_contains($gw, "(int) (\$this->cfg['itil_category'] ?? 0)"));
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
check('ticket run advances only when the ticket flows succeeded (Review Focus 4, final #2)', (bool) preg_match("/ticket_errors'\] === \[\].*?'last_hygiene_ticket_run'/s", $cron));
check('cron passes device data freshness (final #3)', str_contains($cron, "'device_data_fresh'"));
$adopt = strpos($gw, "->find(['externalid' => \$externalid, 'is_deleted' => 0, 'NOT' => ['status' => [5, 6]]]");
check('gateway adopts an open ticket with the same externalid before adding (final #5)', $adopt !== false && $adopt < strpos($gw, '(new \Ticket())->add('));
check('oldest liveness check first (final #4b)', str_contains($store, "'ORDER' => ['liveness_checked_at ASC', 'id']"));
check('hygiene error kept visible', str_contains($cron, "'last_hygiene_error'"));
check('cron passes the ITIL category to the gateway (rc5)', str_contains($cron, "'itil_category'  => \$cfg['itil_category']"));
$sc = (string) file_get_contents(OPM_ROOT . '/src/SelfCheck.php');
check('selfcheck: asset technicians exist (rc7)', str_contains($sc, "(new \\User())->getFromDB(\$uid)"));
check('cron uses the provider registry', str_contains($cron, 'Providers::all('));

$tg = (string) file_get_contents(OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php');
check('withoutAutoAssign shared, not copied', str_contains($tg, 'public static function withoutAutoAssign(') && !str_contains($gw, 'glpiset_followup_tech'));

done();
