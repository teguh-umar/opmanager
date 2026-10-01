<?php

namespace GlpiPlugin\Opmanager\Outbound;

use Glpi\RichText\RichText;
use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\OpManager\Client;
use GlpiPlugin\Opmanager\Ticketing\DbAlarmStore;
use GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway;

/**
 * GLPI -> OpManager (Plan 3). Runs inside the technician's save: decide (Planner), queue,
 * try to send once with a short timeout, and never let an error reach GLPI. What is not
 * sent here is retried by the OutboxSend cron.
 */
final class Hooks
{
    public const SEND_TIMEOUT = 5;

    public static function followupAdded(\ITILFollowup $followup): void
    {
        try {
            if (($followup->fields['itemtype'] ?? '') !== \Ticket::class) {
                return;
            }
            $cfg = Config::ticketingConfig();
            $uid = \Session::getLoginUserID();
            if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                return;
            }
            $tickets_id = (int) $followup->fields['items_id'];
            $rows = Planner::forFollowup(
                $tickets_id,
                (int) ($followup->fields['is_private'] ?? 0) === 1,
                RichText::getTextFromHtml((string) ($followup->fields['content'] ?? '')),
                (string) getUserName((int) $uid),
                DbAlarmStore::activeForTicket($tickets_id),
                (int) $uid
            );
            self::queueAndSend($rows, $cfg);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('opmanager', 'Outbound followup hook: ' . $e->getMessage() . "\n");
        }
    }

    public static function ticketUpdated(\Ticket $ticket): void
    {
        try {
            if (!in_array('status', $ticket->updates ?? [], true)) {
                return;
            }
            $cfg = Config::ticketingConfig();
            $uid = \Session::getLoginUserID();
            if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                return;
            }
            global $DB;
            $tickets_id = (int) $ticket->getID();
            // The solution row is inserted before ITILSolution::post_addItem updates the
            // ticket status, so it is already readable here (CommonDBTM::add order).
            $sol = $DB->request([
                'SELECT' => ['content'],
                'FROM'   => \ITILSolution::getTable(),
                'WHERE'  => ['itemtype' => \Ticket::class, 'items_id' => $tickets_id, 'users_id' => $uid],
                'ORDER'  => 'id DESC',
                'LIMIT'  => 1,
            ])->current();
            $store = new DbOutboxStore();
            $rows  = Planner::forStatus(
                $tickets_id,
                (int) ($ticket->oldvalues['status'] ?? 0),
                (int) $ticket->fields['status'],
                $sol ? RichText::getTextFromHtml((string) $sol['content']) : null,
                (string) getUserName((int) $uid),
                DbAlarmStore::activeForTicket($tickets_id),
                [$store, 'hasClear'],
                (int) $uid
            );
            self::queueAndSend($rows, $cfg);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('opmanager', 'Outbound ticket hook: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * @param list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}> $rows
     * @param array{outbound_max_attempts: int} $cfg
     */
    private static function queueAndSend(array $rows, array $cfg): void
    {
        if ($rows === []) {
            return;
        }
        $now   = (string) \Session::getCurrentTime();
        $store = new DbOutboxStore();
        foreach ($rows as $row) {
            $store->enqueue($row, $now);
        }
        try {
            $client = new Client(Config::get('opm_url'), Config::apiKey(), Config::get('opm_verify_ssl') === '1', null, self::SEND_TIMEOUT);
            $sender = new Sender($store, $client, $cfg['outbound_max_attempts']);
            $sender->run($now, 20);
            foreach ($sender->lastErrors() as $error) {
                \Toolbox::logInFile('opmanager', 'Outbound: ' . $error . " (will retry)\n");
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('opmanager', 'Outbound send: ' . $e->getMessage() . " (queued for cron)\n");
        }
    }
}
