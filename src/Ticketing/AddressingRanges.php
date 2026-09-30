<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Reads the IP ranges of the "IP Addressing" plugin (Tools > IP Addressing,
 * glpi_plugin_addressing_addressings) - the same source integrasipaman uses for
 * its production test. Read-only; never writes another plugin's table (hub §5).
 */
final class AddressingRanges
{
    private const TABLE = 'glpi_plugin_addressing_addressings';

    /** @return array<int, string> id => "name (begin - end)" for the Setup checkboxes */
    public static function options(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'begin_ip', 'end_ip'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['is_deleted' => 0],
            'ORDER'  => 'name',
        ]) as $row) {
            $out[(int) $row['id']] = sprintf('%s (%s - %s)', $row['name'], $row['begin_ip'], $row['end_ip']);
        }
        return $out;
    }

    /**
     * @param int[] $ids
     * @return list<array{id: int, begin: int, end: int}>
     */
    public static function load(array $ids): array
    {
        global $DB;

        if ($ids === [] || !$DB->tableExists(self::TABLE)) {
            return [];
        }
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['id', 'begin_ip', 'end_ip'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['id' => $ids, 'is_deleted' => 0],
        ]), false);
        return RangeProductionCheck::rangesFromRows($rows);
    }

    /** @return list<string> live IPs of one GLPI asset */
    public static function assetIps(string $itemtype, int $items_id): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => ['mainitemtype' => $itemtype, 'mainitems_id' => $items_id, 'is_deleted' => 0],
        ]) as $row) {
            $out[] = (string) $row['name'];
        }
        return $out;
    }
}
