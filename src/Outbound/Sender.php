<?php

namespace GlpiPlugin\Opmanager\Outbound;

use GlpiPlugin\Opmanager\OpManager\AlarmWriter;
use GlpiPlugin\Opmanager\OpManager\ClientException;

/**
 * Sends queued notes/clears oldest first. Per alarm the order is kept: once a row of an
 * alarm is not sent (not due yet, or failed now), later rows of that alarm wait, so a
 * clear never overtakes its note. Retries back off 1, 2, 4 ... 60 minutes (spec §8).
 */
final class Sender
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(private OutboxStore $store, private AlarmWriter $writer, private int $max_attempts)
    {
    }

    public static function backoffMinutes(int $attempts): int
    {
        return min(60, 2 ** max(0, $attempts - 1));
    }

    /**
     * $stop_on_failure: the hooks send inside a technician's save, so one unreachable
     * OpManager must cost one timeout, not one per row (final review I-3).
     *
     * @return array{sent: int, retried: int, failed: int}
     */
    public function run(string $now, int $limit = 50, bool $stop_on_failure = false): array
    {
        $this->errors = [];
        $stats   = ['sent' => 0, 'retried' => 0, 'failed' => 0];
        $blocked = [];
        foreach ($this->store->pending($limit) as $row) {
            $key = $row['alarm_key'];
            if (isset($blocked[$key])) {
                continue;
            }
            if ($row['next_try_at'] !== '' && $row['next_try_at'] > $now) {
                $blocked[$key] = true;
                continue;
            }
            $attempts = $row['attempts'] + 1;
            try {
                match ($row['action']) {
                    'note'  => $this->writer->addNote($row['entity_key'], $row['payload']),
                    'clear' => $this->writer->clearAlarm($row['entity_key']),
                    'ack'   => $this->writer->acknowledgeAlarm($row['entity_key']),
                    default => throw new \DomainException('unknown outbox action ' . $row['action']),
                };
                $this->store->markSent($row['id'], $now);
                $stats['sent']++;
            } catch (ClientException $e) {
                $blocked[$key] = true;
                $this->errors[] = sprintf('%s %s: %s', $row['action'], $key, $e->getMessage());
                if ($attempts >= $this->max_attempts) {
                    $this->store->markFailed($row['id'], $attempts, $e->getMessage(), $now);
                    $stats['failed']++;
                } else {
                    $next = date('Y-m-d H:i:s', strtotime($now) + self::backoffMinutes($attempts) * 60);
                    $this->store->markRetry($row['id'], $attempts, $next, $e->getMessage(), $now);
                    $stats['retried']++;
                }
                if ($stop_on_failure) {
                    break;
                }
            } catch (\DomainException $e) {
                $blocked[$key] = true;
                $this->store->markFailed($row['id'], $attempts, $e->getMessage(), $now);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /** @return list<string> */
    public function lastErrors(): array
    {
        return $this->errors;
    }
}
