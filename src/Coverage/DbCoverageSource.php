<?php

namespace GlpiPlugin\Opmanager\Coverage;

use GlpiPlugin\Opmanager\Device;

/** Reads what the Coverage page compares (spec §15.1). Runs in a user request: entity-scoped. */
final class DbCoverageSource
{
    /** @var array<string, string> */
    private array $names = [];

    /** @return list<array<string, mixed>> */
    public function devices(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => Device::getTable(), 'ORDER' => 'display_name']) as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'device_key'   => (string) $r['device_key'],
                'name'         => (string) $r['name'],
                'display_name' => (string) $r['display_name'],
                'ip'           => (string) $r['ip_address'],
                'category'     => (string) $r['device_category'],
                'type'         => (string) $r['device_type'],
                'itemtype'     => (string) $r['itemtype'],
                'items_id'     => (int) $r['items_id'],
                'match_state'  => (string) $r['match_state'],
                'match_source' => (string) $r['match_source'],
                'last_seen'    => (string) ($r['last_seen'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $itemtypes
     * @param list<int>    $states    [] = every state
     * @return list<array<string, mixed>>
     */
    public function assets(array $itemtypes, array $states): array
    {
        global $DB;

        $out = [];
        foreach ($itemtypes as $itemtype) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof \CommonDBTM) {
                continue;
            }
            $table      = $itemtype::getTable();
            $has_states = $DB->fieldExists($table, 'states_id');
            // find()/request() do not add getSystemSQLCriteria(): without it a custom asset
            // query would read every custom asset definition's rows.
            $where = $itemtype::getSystemSQLCriteria() + getEntitiesRestrictCriteria($table, '', '', $item->maybeRecursive());
            if ($item->maybeDeleted()) {
                $where[$table . '.is_deleted'] = 0;
            }
            if ($item->maybeTemplate()) {
                $where[$table . '.is_template'] = 0;
            }
            // Custom asset tables may lack states_id: then the states filter cannot apply.
            if ($states !== [] && $has_states) {
                $where[$table . '.states_id'] = $states;
            }
            $select = [$table . '.id', $table . '.name', $table . '.entities_id'];
            if ($has_states) {
                $select[] = $table . '.states_id';
            }
            $rows = [];
            foreach ($DB->request(['SELECT' => $select, 'FROM' => $table, 'WHERE' => $where]) as $r) {
                $rows[(int) $r['id']] = $r;
            }
            if ($rows === []) {
                continue;
            }
            $ids = array_keys($rows);
            $ips = [];
            foreach ($DB->request([
                'SELECT' => ['mainitems_id', 'name'],
                'FROM'   => 'glpi_ipaddresses',
                'WHERE'  => ['mainitemtype' => $itemtype, 'mainitems_id' => $ids, 'is_deleted' => 0, 'NOT' => ['name' => '']],
            ]) as $ip) {
                $ips[(int) $ip['mainitems_id']][] = (string) $ip['name'];
            }
            foreach ($rows as $id => $r) {
                if (!isset($ips[$id])) {
                    continue; // D28: only assets with an IP are in scope
                }
                $out[] = [
                    'itemtype' => $itemtype,
                    'items_id' => $id,
                    'name'     => (string) $r['name'],
                    'entity'   => $this->name('glpi_entities', (int) $r['entities_id']),
                    'state'    => isset($r['states_id']) ? $this->name('glpi_states', (int) $r['states_id']) : '',
                    'ips'      => array_values(array_unique($ips[$id])),
                ];
            }
        }
        return $out;
    }

    /** Dropdown names, memoised: one lookup per distinct id, not per asset. */
    private function name(string $table, int $id): string
    {
        if ($id <= 0 && $table !== 'glpi_entities') {
            return '';
        }
        return $this->names[$table . '#' . $id] ??= (string) \Dropdown::getDropdownName($table, $id, false, true, false);
    }
}
