<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/** Built-in provider: TCP probe, only inside the configured reachable CIDRs (spec §3). */
final class ProbeProvider
{
    /**
     * @param list<string> $cidrs
     * @param list<int>    $ports
     */
    public function __construct(private TcpProber $prober, private array $cidrs, private array $ports)
    {
    }

    /** @return array{verdict: string, detail: string}|null */
    public function __invoke(array $device, string $now): ?array
    {
        $ip = (string) ($device['ip_address'] ?? '');
        if ($this->ports === [] || !Cidr::contains($ip, $this->cidrs)) {
            return null;
        }
        return Liveness::fromProbe($this->prober->probe($ip, $this->ports));
    }
}
