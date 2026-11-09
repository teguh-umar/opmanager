<?php

namespace GlpiPlugin\Opmanager\Hygiene;

use GlpiPlugin\Opmanager\Config;

/**
 * One NMS hygiene pass (spec §2-§4, D34): liveness for unmanaged devices,
 * classification of every device, then - when due and enabled - one Request ticket
 * per flow, or a followup with the differences while that ticket is open.
 * Never writes to OpManager.
 */
final class HygieneRun
{
    public const FLOWS = ['glpi', 'nms'];

    /** Ticket externalid = prefix + flow; lets the gateway adopt a ticket a crashed run created (final review #5). */
    public const EXTERNAL_ID_PREFIX = 'opmanager:hygiene:';

    /**
     * @param array<string, callable> $providers name => provider (Providers::all())
     * @param array<string, mixed>    $cfg Config::hygieneConfig() + service_user, entity_default, list_url
     */
    public function __construct(private HygieneStore $store, private array $providers, private HygieneGateway $gateway, private array $cfg)
    {
    }

    /**
     * `errors` holds every problem (shown in Setup); `ticket_errors` only those of the
     * ticket flows - the weekly gate advances on ticket_errors alone (final review #2).
     *
     * @return array{classified: int, probed: int, tickets_created: int, followups: int, errors: list<string>, ticket_errors: list<string>}
     */
    public function run(string $now, bool $tickets_due): array
    {
        $stats      = ['classified' => 0, 'probed' => 0, 'tickets_created' => 0, 'followups' => 0, 'errors' => [], 'ticket_errors' => []];
        $excepted   = array_flip($this->store->activeExceptionKeys());
        $stale      = date('Y-m-d H:i:s', (int) strtotime($now) - 2 * Config::DEVICE_SYNC_MINUTES * 60);
        $unmanaged  = $this->cfg['unmanaged_values'];
        $flows      = array_fill_keys(self::FLOWS, []);
        $report_row = [];

        foreach ($this->store->devices() as $d) {
            $key        = (string) $d['device_key'];
            $is_except  = isset($excepted[$key]);
            $is_stale   = (string) ($d['last_seen'] ?? '') === '' || (string) $d['last_seen'] < $stale;
            $live       = ['state' => (string) ($d['liveness_state'] ?? ''), 'source' => null, 'detail' => null];
            $checked_at = null;

            if (Classifier::isUnmanaged((string) $d['availability'], $unmanaged)) {
                if (!$is_except && !$is_stale) {
                    $live = $this->liveness($d, $now, $stats);
                    if ($live['source'] !== null) {
                        $checked_at = $now;
                    }
                }
            } else {
                $live = ['state' => '', 'source' => '', 'detail' => ''];
            }

            $state = Classifier::classify($d, $live['state'], $is_except, $stale, $unmanaged);
            $this->store->saveDevice((int) $d['id'], [
                'liveness_state'      => $live['state'],
                'liveness_source'     => $live['source'] ?? null,
                'liveness_detail'     => $live['detail'] ?? null,
                'liveness_checked_at' => $checked_at,
                'hygiene_state'       => $state,
            ]);
            $stats['classified']++;

            if (isset(Classifier::FLOW_OF[$state])) {
                $flows[Classifier::FLOW_OF[$state]][$key] = $state;
            }
            $report_row[$key] = [
                'device_key'   => $key,
                'name'         => (string) ($d['display_name'] !== '' ? $d['display_name'] : $d['name']),
                'ip'           => (string) $d['ip_address'],
                'category'     => (string) $d['device_category'],
                'itemtype'     => (string) ($d['itemtype'] ?? ''),
                'availability' => (string) $d['availability'],
                'asset'        => (string) ($d['asset_label'] ?? ((string) $d['itemtype'] !== '' ? $d['itemtype'] . ' #' . $d['items_id'] : '')),
                'state'        => $state,
                'liveness'     => $live['state'],
                'detail'       => (string) ($live['detail'] ?? ''),
                'last_seen'    => (string) ($d['last_seen'] ?? ''),
            ];
        }

        if ($tickets_due && $this->cfg['enabled']) {
            if (!($this->cfg['device_data_fresh'] ?? true)) {
                // Final review #3: stale OpManager data must not read as "no longer applies".
                $msg = 'OpManager device data is stale (device sync failing); hygiene tickets postponed';
                $stats['errors'][]        = $msg;
                $stats['ticket_errors'][] = $msg;
                return $stats;
            }
            foreach (self::FLOWS as $flow) {
                $this->ticketFlow($flow, $flows[$flow], $report_row, $now, $stats);
            }
        }
        return $stats;
    }

    /**
     * Evidence from every provider. With no usable evidence the stored alive/dead is
     * kept; a provider that was skipped (probe budget spent) never turns a stored
     * alive into dead (final review #4a). `source` null = nothing new learned.
     *
     * @return array{state: string, source: ?string, detail: ?string}
     */
    private function liveness(array $d, string $now, array &$stats): array
    {
        $stored   = (string) ($d['liveness_state'] ?? '');
        $evidence = Providers::collect($d, $now, $this->providers, $stats['errors']);
        $stats['probed']++;
        $skipped  = array_filter($evidence, static fn (array $e): bool => $e['verdict'] === 'skipped') !== [];
        $real     = array_values(array_filter($evidence, static fn (array $e): bool => $e['verdict'] !== 'skipped'));

        if ($real === []) {
            $keep = in_array($stored, ['alive', 'dead'], true);
            return ['state' => $keep ? $stored : 'unknown', 'source' => null, 'detail' => null];
        }
        $live = Liveness::combine($real);
        if ($live['state'] === 'dead' && $skipped && $stored === 'alive') {
            return ['state' => 'alive', 'source' => null, 'detail' => null];
        }
        return $live;
    }

    /** @param array<string, string> $current */
    private function ticketFlow(string $flow, array $current, array $rows, string $now, array &$stats): void
    {
        $latest   = $this->store->latestTicket($flow);
        $status   = $latest !== null ? $this->gateway->status($latest['tickets_id']) : null;
        $reported = $latest !== null ? $this->store->reportedItems($latest['id']) : [];
        $plan     = TicketPlanner::plan($current, $reported, $status);

        if ($plan['action'] === 'create') {
            $devices = array_values(array_intersect_key($rows, $current));
            [$assign, $observer] = $flow === 'glpi'
                ? [$this->cfg['glpi_groups'], [$this->cfg['nms_group']]]
                : [[$this->cfg['nms_group']], []];
            $tickets_id = $this->gateway->create(
                Report::title($flow, $now),
                Report::body($flow, $devices, (string) $this->cfg['list_url']),
                (int) $this->cfg['template_id'],
                $assign,
                $observer,
                self::EXTERNAL_ID_PREFIX . $flow
            );
            if ($tickets_id <= 0) {
                $this->ticketError($stats, sprintf('%s hygiene ticket could not be created (see files/_log/opmanager.log)', $flow));
                return;
            }
            $this->store->recordItems($this->store->recordTicket($flow, $tickets_id, $now), $plan, $now);
            $stats['tickets_created']++;
            return;
        }

        if ($plan['action'] === 'followup' && $latest !== null) {
            if (!$this->gateway->followup($latest['tickets_id'], Report::followup($plan, $rows))) {
                $this->ticketError($stats, sprintf('%s hygiene followup on ticket %d failed', $flow, $latest['tickets_id']));
                return;
            }
            $this->store->recordItems($latest['id'], $plan, $now);
            $stats['followups']++;
        }
    }

    private function ticketError(array &$stats, string $msg): void
    {
        $stats['errors'][]        = $msg;
        $stats['ticket_errors'][] = $msg;
    }
}
