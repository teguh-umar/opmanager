<?php

namespace GlpiPlugin\Opmanager\Webhook;

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\SyncLock;
use GlpiPlugin\Opmanager\Ticketing\TicketRequestStore;

/**
 * OpManager "Invoke a Webhook" receiver. The payload is only a trigger: the alarm itself is
 * re-read from the OpManager API, so a forged body can at worst make us look up a real alarm.
 * Anything that cannot be processed now is answered 202 - the AlarmSync cron picks it up.
 */
final class Receiver
{
    public const MAX_BODY = 8192;

    /**
     * Any printable text up to 255 (the column size): real OpManager entities can hold '/',
     * spaces and exceed 64 chars (interfaces). The values only reach the OpManager API as an
     * encoded query parameter and a string comparison, never SQL.
     */
    public const ID_PATTERN = '/^[^\x00-\x1F\x7F]{1,255}$/';

    private const RECEIVED_MAX = 200;

    private const LOCK_WAIT_SECONDS = 10;

    /** @var callable(array<string, mixed>): array<string, int> */
    private $runner;

    public function __construct(
        private string $token,
        private AlarmSource $source,
        private SyncLock $lock,
        callable $runner,
        private ?TicketRequestStore $requests = null
    ) {
        $this->runner = $runner;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function handle(string $method, string $header_token, string $raw_body): array
    {
        if (strtoupper($method) !== 'POST') {
            return self::reply(405, 'method not allowed');
        }
        if ($this->token === '' || $header_token === '' || !hash_equals($this->token, $header_token)) {
            return self::reply(401, 'unauthorized');
        }
        if (strlen($raw_body) > self::MAX_BODY) {
            return self::reply(413, 'payload too large');
        }

        $data     = json_decode($raw_body, true);
        $alarm_id = is_array($data) && is_scalar($data['alarm_id'] ?? null) ? (string) $data['alarm_id'] : '';
        $entity   = is_array($data) && is_scalar($data['entity'] ?? null) ? (string) $data['entity'] : '';
        // OpManager "Test Action" sends $alarmid/$entity unfilled: acknowledge, process nothing.
        if (str_starts_with($alarm_id, '$') || str_starts_with($entity, '$')) {
            return ['status' => 200, 'body' => ['status' => 'test', 'message' => 'test received; OpManager did not fill $alarmid/$entity, no alarm processed']];
        }
        if (preg_match(self::ID_PATTERN, $alarm_id) !== 1 || preg_match(self::ID_PATTERN, $entity) !== 1) {
            $reply = self::reply(400, 'alarm_id and entity are required');
            $reply['body']['received'] = substr((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw_body), 0, self::RECEIVED_MAX);
            return $reply;
        }

        // Fetch under the lock: a snapshot read before waiting could be older than one
        // another request processes meanwhile (flapping alarm -> spurious reopen).
        if (!$this->lock->acquire(self::LOCK_WAIT_SECONDS)) {
            return $this->defer('alarm sync busy; deferred to AlarmSync cron', $alarm_id, $entity);
        }
        try {
            try {
                $alarm = $this->source->alarmByEntity($entity);
            } catch (ClientException $e) {
                return $this->defer('OpManager API unavailable; deferred to AlarmSync cron', $alarm_id, $entity);
            }
            if ($alarm === null || (string) ($alarm['alarm_key'] ?? '') !== $alarm_id) {
                return $this->defer('alarm not current on this entity; deferred to AlarmSync cron', $alarm_id, $entity);
            }
            $stats = ($this->runner)($alarm);
        } catch (\Throwable $e) {
            // The next AlarmSync run retries it as a webhook (spec §16.4); the status stays 500.
            return $this->defer('processing failed', $alarm_id, $entity, 500);
        } finally {
            $this->lock->release();
        }

        return ['status' => 200, 'body' => ['status' => 'ok', 'alarm_id' => $alarm_id, 'stats' => $stats]];
    }

    /**
     * D32: a deferred webhook is still a webhook - the next AlarmSync run handles it as one.
     * A failing store must not change the status: the alarm is still polled (flagged request_not_saved).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function defer(string $message, string $alarm_id, string $entity, int $status = 202): array
    {
        $saved = true;
        try {
            $this->requests?->record($alarm_id, $entity);
        } catch (\Throwable) {
            $saved = false;     // swallowed, but visible in the reply (and so in the log)
        }
        $reply = self::reply($status, $message, $alarm_id);
        if (!$saved) {
            $reply['body']['request_not_saved'] = true;
        }
        return $reply;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function reply(int $status, string $message, string $alarm_id = ''): array
    {
        $body = ['status' => $status < 300 ? 'deferred' : 'error', 'message' => $message];
        if ($alarm_id !== '') {
            $body['alarm_id'] = $alarm_id;
        }
        return ['status' => $status, 'body' => $body];
    }
}
