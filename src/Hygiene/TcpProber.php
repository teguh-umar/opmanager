<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/**
 * TCP connect probe with a per-connection timeout and a total time budget per run
 * (spec §3, Review Focus 1). No ICMP, no exec.
 */
final class TcpProber
{
    /** @var callable(): float */
    private $clock;

    private ?float $started = null;

    public function __construct(private int $timeout_ms, private float $budget_seconds = 120.0, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param list<int> $ports tried in order; stops at the first open one
     * @return array{open: ?int, tried: list<int>}|null null when the run's budget is spent
     */
    public function probe(string $ip, array $ports): ?array
    {
        $now = ($this->clock)();
        $this->started ??= $now;
        if ($now - $this->started >= $this->budget_seconds) {
            return null;
        }
        $tried = [];
        foreach ($ports as $port) {
            $tried[] = (int) $port;
            $socket  = @stream_socket_client(
                'tcp://' . $ip . ':' . (int) $port,
                $errno,
                $errstr,
                max(0.1, $this->timeout_ms / 1000),
                STREAM_CLIENT_CONNECT
            );
            if ($socket !== false) {
                fclose($socket);
                return ['open' => (int) $port, 'tried' => $tried];
            }
        }
        return ['open' => null, 'tried' => $tried];
    }
}
