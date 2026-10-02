<?php

require __DIR__ . '/bootstrap.php';

// v1.1.0 (D32): Setup wiring of the ticket source, checked on the source text.
$src = (string) file_get_contents(OPM_ROOT . '/src/SuiteConfig.php');
check('Setup offers the ticket source', str_contains($src, 'name="ticket_source"') && str_contains($src, 'Config::TICKET_SOURCES'));
check('status strip receives the ticket source', (bool) preg_match("/function statusStrip\(.*?'ticket_source' => Config::get\('ticket_source'\)/s", $src));
// Review Focus 1: a disabled input is not posted, so Save would wipe severities/filter.
check('ticket source never disables severity or filter inputs', !preg_match("/'webhook'\s*\?\s*' disabled'/", $src) && !str_contains($src, '$by_webhook ? \' disabled\''));
check('webhook mode shows the "Plugin rules only" hint', substr_count($src, 'Used only when the ticket source is') >= 2);

done();
