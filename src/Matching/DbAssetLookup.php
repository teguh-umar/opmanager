<?php

namespace GlpiPlugin\Opmanager\Matching;

/**
 * AssetLookup against the GLPI database, across every configured asset itemtype.
 * Deliberately NOT entity-scoped: it runs from cron, where core would fall back to
 * the root entity (hub §4).
 */
final class DbAssetLookup implements AssetLookup
{
    /** @var list<string> */
    private array $itemtypes;

    /** @var list<string> */
    private array $missing;

    /** @param string[] $itemtypes */
    public function __construct(array $itemtypes)
    {
        $resolved        = self::resolvable($itemtypes, static fn (string $t) => getItemForItemtype($t) ?: null);
        $this->itemtypes = $resolved['ok'];
        $this->missing   = $resolved['missing'];
    }

    /**
     * Pure split of configured itemtypes into resolvable and missing.
     *
     * @param string[]                  $itemtypes
     * @param callable(string): ?object $factory
     * @return array{ok: list<string>, missing: list<string>}
     */
    public static function resolvable(array $itemtypes, callable $factory): array
    {
        $ok = $missing = [];
        foreach ($itemtypes as $itemtype) {
            if ($factory($itemtype) === null) {
                $missing[] = $itemtype;
            } else {
                $ok[] = $itemtype;
            }
        }
        return ['ok' => $ok, 'missing' => $missing];
    }

    /** @return list<string> configured itemtypes this process could not load */
    public function missingItemtypes(): array
    {
        return $this->missing;
    }

    public function byName(string $name): array
    {
        global $DB;

        $hits = [];
        foreach ($this->itemtypes as $itemtype) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof \CommonDBTM) {
                continue;
            }
            $table = $itemtype::getTable();
            // find() does not add getSystemSQLCriteria(): without it a custom asset
            // query would search every custom asset definition's rows.
            $where = [$table . '.name' => $name] + self::liveCriteria($item, $table) + $itemtype::getSystemSQLCriteria();
            foreach ($DB->request(['SELECT' => [$table . '.id'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $hits[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['id']];
            }
        }
        return $hits;
    }

    public function byIp(string $ip): array
    {
        global $DB;

        if ($this->itemtypes === []) {
            return [];
        }
        $hits = [];
        foreach ($DB->request([
            'SELECT' => ['mainitemtype', 'mainitems_id'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => [
                'name'         => $ip,
                'is_deleted'   => 0,
                'mainitemtype' => $this->itemtypes,
                'mainitems_id' => ['>', 0],
            ],
        ]) as $row) {
            $itemtype = (string) $row['mainitemtype'];
            $item     = getItemForItemtype($itemtype);
            if (!$item instanceof \CommonDBTM || !$item->getFromDB((int) $row['mainitems_id'])) {
                continue;
            }
            if (!empty($item->fields['is_deleted']) || !empty($item->fields['is_template'])) {
                continue;
            }
            $hits[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['mainitems_id']];
        }
        return $hits;
    }

    /** @return array<string, int> */
    private static function liveCriteria(\CommonDBTM $item, string $table): array
    {
        $criteria = [];
        if ($item->maybeDeleted()) {
            $criteria[$table . '.is_deleted'] = 0;
        }
        if ($item->maybeTemplate()) {
            $criteria[$table . '.is_template'] = 0;
        }
        return $criteria;
    }
}
