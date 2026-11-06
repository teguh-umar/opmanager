<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/**
 * Built-in provider: the suite plugin `mist` (Juniper Mist) for wireless devices
 * (spec §3). Read-only, by table name, behind isPluginActive - the plugin may be absent.
 */
final class MistProvider
{
    private const TABLE = 'glpi_plugin_mist_devices';

    private ?bool $available = null;

    public function __construct(private int $stale_days)
    {
    }

    /** @return array{verdict: string, detail: string}|null */
    public function __invoke(array $device, string $now): ?array
    {
        global $DB;
        $this->available ??= \Plugin::isPluginActive('mist') && $DB->tableExists('glpi_plugin_mist_devices');
        if (!$this->available) {
            return null;
        }

        $row  = null;
        $type = (string) ($device['itemtype'] ?? '');
        $id   = (int) ($device['items_id'] ?? 0);
        if (($device['match_state'] ?? '') === 'matched' && $type !== '' && $id > 0) {
            $row = $DB->request([
                'SELECT' => ['status', 'mist_last_seen'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['itemtype' => $type, 'items_id' => $id],
                'ORDER'  => 'mist_last_seen DESC',
                'LIMIT'  => 1,
            ])->current();
        }
        $ip = (string) ($device['ip_address'] ?? '');
        if (!$row && $ip !== '') {
            $row = $DB->request([
                'SELECT' => ['status', 'mist_last_seen'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['ip_address' => $ip],
                'ORDER'  => 'mist_last_seen DESC',
                'LIMIT'  => 1,
            ])->current();
        }
        if (!$row) {
            return null;
        }
        return Liveness::fromMist(['status' => (string) $row['status'], 'mist_last_seen' => $row['mist_last_seen'] !== null ? (string) $row['mist_last_seen'] : null], $now, $this->stale_days);
    }
}
