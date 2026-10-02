<?php

/**
 * OpManager "Invoke a Webhook" receiver (v0.3.0). Stateless (see plugin_opmanager_boot):
 * no session, no CSRF; authenticated by the X-OPM-Token header only. The body is a
 * trigger {"alarm_id":"$alarmid","entity":"$entity"}; the alarm itself is fetched from
 * the OpManager API and run through the same engine as the AlarmSync poll.
 */

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\Matching\DbAssetLookup;
use GlpiPlugin\Opmanager\Matching\Matcher;
use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\Ticketing\AddressingRanges;
use GlpiPlugin\Opmanager\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Opmanager\Ticketing\AlarmSync;
use GlpiPlugin\Opmanager\Ticketing\DbAlarmStore;
use GlpiPlugin\Opmanager\Ticketing\DbDeviceIndex;
use GlpiPlugin\Opmanager\Ticketing\DbSyncLock;
use GlpiPlugin\Opmanager\Ticketing\DbTicketRequestStore;
use GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway;
use GlpiPlugin\Opmanager\Ticketing\RangeProductionCheck;
use GlpiPlugin\Opmanager\Webhook\Receiver;

$client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1');
$cfg    = Config::ticketingConfig();

$receiver = new Receiver(
    Config::webhookToken(),
    $client,
    new DbSyncLock(),
    static function (array $alarm) use ($client, $cfg): array {
        if ($cfg['service_user'] <= 0) {
            // Same guard as the AlarmSync cron: a ticket without an author is never created.
            \Toolbox::logInFile('opmanager', "Webhook: no service user configured in Setup; alarm left to AlarmSync\n");
            throw new \RuntimeException('no service user configured');
        }
        $sync = new AlarmSync(
            $client,
            new DbAlarmStore(),
            new GlpiTicketGateway($cfg),
            new AlarmAssetResolver(new DbDeviceIndex(), new Matcher(new DbAssetLookup(Config::assetItemtypes())), Config::get('match_mode')),
            new RangeProductionCheck(AddressingRanges::load($cfg['production_addressings']), [AddressingRanges::class, 'assetIps']),
            $cfg
        );
        // Same context as the cron: no profile here, so without this every haveRight() is
        // false and Ticket::add() drops template SLA/OLA and recomputes the priority.
        \Session::loadEntity(0, true);
        $stats = \Session::callAsSystem(static fn () => $sync->processOne($alarm, (string) \Session::getCurrentTime()));
        foreach ($sync->lastErrors() as $error) {
            \Toolbox::logInFile('opmanager', 'Webhook: ' . $error . "\n");
        }
        return $stats;
    },
    new DbTicketRequestStore()
);

$result = $receiver->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['HTTP_X_OPM_TOKEN'] ?? ''),
    (string) file_get_contents('php://input', false, null, 0, Receiver::MAX_BODY + 1)
);

// Every answer is logged (one line each), so a working webhook is visible too (0.4.1).
$summary = $result['body']['message'] ?? '';
if ($summary === '' && isset($result['body']['stats']) && is_array($result['body']['stats'])) {
    $done    = array_filter($result['body']['stats']);
    $summary = 'processed: ' . ($done === [] ? 'no change' : implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($done), $done)));
}
if (isset($result['body']['received'])) {
    $summary .= ' | received: ' . $result['body']['received'];
}
\Toolbox::logInFile('opmanager', sprintf(
    "Webhook: HTTP %d %s (alarm %s) from %s\n",
    $result['status'],
    $summary,
    $result['body']['alarm_id'] ?? '-',
    $_SERVER['REMOTE_ADDR'] ?? '?'
));
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
