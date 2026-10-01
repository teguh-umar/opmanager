<?php

namespace GlpiPlugin\Opmanager\Webhook;

use GlpiPlugin\Opmanager\OpManager\AlarmSource;
use GlpiPlugin\Opmanager\OpManager\ClientException;
use GlpiPlugin\Opmanager\Ticketing\SyncLock;

/**
 * OpManager "Invoke a Webhook" receiver. The payload is only a trigger: the alarm itself is
 * re-read from the OpManager API, so a forged body can at worst make us look up a real alarm.
 * Anything that cannot be processed now is answered 202 - the AlarmSync cron picks it up.
 */
final class Receiver
{
    public const MAX_BODY = 8192;

    public const ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    private const LOCK_WAIT_SECONDS = 10;

    /** @var callable(array<string, mixed>): array<string, int> */
    private $runner;

    public function __construct(
        private string $token,
        private AlarmSource $source,
        private SyncLock $lock,
        callable $runner
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
        if (preg_match(self::ID_PATTERN, $alarm_id) !== 1 || preg_match(self::ID_PATTERN, $entity) !== 1) {
            return self::reply(400, 'alarm_id and entity are required');
        }

        try {
            $alarm = $this->source->alarmByEntity($entity);
        } catch (ClientException $e) {
            return self::reply(202, 'OpManager API unavailable; deferred to AlarmSync cron');
        }
        if ($alarm === null || (string) ($alarm['alarm_key'] ?? '') !== $alarm_id) {
            return self::reply(202, 'alarm not current on this entity; deferred to AlarmSync cron');
        }

        if (!$this->lock->acquire(self::LOCK_WAIT_SECONDS)) {
            return self::reply(202, 'alarm sync busy; deferred to AlarmSync cron');
        }
        try {
            $stats = ($this->runner)($alarm);
        } catch (\Throwable $e) {
            return self::reply(500, 'processing failed');
        } finally {
            $this->lock->release();
        }

        return ['status' => 200, 'body' => ['status' => 'ok', 'alarm_id' => $alarm_id, 'stats' => $stats]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function reply(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['status' => $status < 300 ? 'deferred' : 'error', 'message' => $message]];
    }
}
