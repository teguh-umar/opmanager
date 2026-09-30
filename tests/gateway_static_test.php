<?php

require __DIR__ . '/bootstrap.php';

// The gateway talks to GLPI core and is exercised on the VM; offline we pin the
// rules that are cheap to break and expensive to find later.
$src = (string) @file_get_contents(OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php');
check('followups never reopen by accident', substr_count($src, "'_no_reopen' => 1") >= 1);
check('reopen goes through GLPI add_reopen', str_contains($src, "'add_reopen' => 1"));
check('service user on followups and solutions', substr_count($src, "'users_id'") >= 2);
check('auto-assign on followup suppressed', str_contains($src, "glpiset_followup_tech"));
check('link uses Ticket_Ticket LINK_TO', str_contains($src, 'Ticket_Ticket::LINK_TO'));
check('solve skips solved/closed tickets', (bool) preg_match('/function solve.*?in_array\(\$status, \[5, 6\]/s', $src));
check('no raw writes to core ticket tables', !preg_match("/DB->(insert|update|delete)\(\s*'glpi_(tickets|itilfollowups|itilsolutions)/", $src));

check('create reuses a ticket already carrying the alarm externalid', (bool) preg_match('/function create\(.*?externalid.*?->add\(/s', $src));
check('create refuses when template mandatory fields are missing', (bool) preg_match('/function create\(.*?missingMandatory/s', $src));
check('trashed ticket reported as TRASHED, not missing', str_contains($src, 'self::TRASHED'));

require OPM_ROOT . '/src/Ticketing/TicketGateway.php';
require OPM_ROOT . '/src/Ticketing/GlpiTicketGateway.php';
check('html escapes and keeps lines', \GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway::html("a<b>\nc") === "a&lt;b&gt;<br />\nc");

done();
