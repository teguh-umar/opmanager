<?php

require __DIR__ . '/bootstrap.php';

$setup = (string) file_get_contents(OPM_ROOT . '/setup.php');
check('stateless path registered in plugin_opmanager_boot', (bool) preg_match('/function plugin_opmanager_boot\(\).*?registerPluginStatelessPath\(\'opmanager\', \'#\^\/front\/webhook\\\\\.php\$#\'\)/s', $setup));
check('not registered in plugin_init (too late)', !preg_match('/function plugin_init_opmanager\(\).*?registerPluginStatelessPath/s', $setup));

$hook = (string) @file_get_contents(OPM_ROOT . '/front/webhook.php');
$cfg  = (string) file_get_contents(OPM_ROOT . '/front/config.php');
check('endpoint exists', $hook !== '');
check('endpoint reads the token from the header only', str_contains($hook, "HTTP_X_OPM_TOKEN") && !str_contains($hook, '$_GET') && !str_contains($hook, '$_REQUEST'));
check('endpoint sets status and JSON', str_contains($hook, 'http_response_code(') && str_contains($hook, 'application/json'));
check('endpoint has no session or CSRF calls', !preg_match('/checkCSRF|checkRight|includes\.php/', $hook));
check('endpoint uses the shared engine', str_contains($hook, '->processOne('));
check('engine runs with system rights and all entities, like cron', str_contains($hook, 'Session::callAsSystem(') && str_contains($hook, 'Session::loadEntity(0, true)'));
check('every answer is logged, 200 included', str_contains($hook, 'Webhook: HTTP %d') && !str_contains($hook, "\$result['status'] !== 200") && !str_contains($hook, "\$result['status'] >= 400"));
check('refused body is logged for diagnosis', str_contains($hook, "\$result['body']['received']"));
check('token is not put in a toast message', !preg_match('/addMessageAfterRedirect\([^;]*\$token/s', $cfg) && str_contains($cfg, '$_SESSION[SuiteConfig::NEW_TOKEN_KEY]'));

check('token regeneration needs config UPDATE', (bool) preg_match('/generate_webhook_token.*?checkRight\(RightDefinition::CONFIG, UPDATE\)/s', $cfg));

$cron = (string) file_get_contents(OPM_ROOT . '/src/Cron.php');
check('webhook passes the request store to the receiver', str_contains($hook, 'new DbTicketRequestStore()'));
check('cron AlarmSync consumes deferred requests', str_contains($cron, 'new DbTicketRequestStore()'));

done();
