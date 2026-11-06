<?php

namespace GlpiPlugin\Opmanager;

use CommonDBTM;
use Dropdown;
use Html;
use Session;

/**
 * One OpManager device and its pairing with a GLPI asset. Read-only for users:
 * rows come from DeviceSync; the only user action is a manual pin/unpin.
 */
class Device extends CommonDBTM
{
    public static $rightname = RightDefinition::MONITORING;

    public static function getTypeName($nb = 0)
    {
        return _n('OpManager device', 'OpManager devices', $nb, 'opmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-router';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function matchStateLabels(): array
    {
        return [
            'matched'   => __('Matched', 'opmanager'),
            'review'    => __('Needs review', 'opmanager'),
            'unmatched' => __('Not found in GLPI', 'opmanager'),
        ];
    }

    /** @return list<array<string, mixed>> matched devices paired with this asset */
    public static function findForAsset(string $itemtype, int $items_id): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id, 'match_state' => 'matched'],
            'ORDER' => 'display_name',
        ]), false);
    }

    /** Pure validation of a manual pin. @param string[] $allowed */
    public static function validatePin(string $itemtype, int $items_id, array $allowed): ?string
    {
        if (!in_array($itemtype, $allowed, true)) {
            return __('This asset type is not enabled in OpManager setup', 'opmanager');
        }
        if ($items_id <= 0) {
            return __('Choose an asset', 'opmanager');
        }
        return null;
    }

    public function pin(int $id, string $itemtype, int $items_id): ?string
    {
        $error = self::validatePin($itemtype, $items_id, Config::assetItemtypes());
        if ($error !== null) {
            return $error;
        }
        $asset = getItemForItemtype($itemtype);
        if (!$asset instanceof CommonDBTM || !$asset->getFromDB($items_id)) {
            return __('That asset does not exist', 'opmanager');
        }
        $this->update([
            'id'           => $id,
            'itemtype'     => $itemtype,
            'items_id'     => $items_id,
            'match_state'  => 'matched',
            'match_source' => 'manual',
            'is_manual'    => 1,
        ]);
        return null;
    }

    /** Removes the pin; the next DeviceSync matches the device automatically again. */
    public function unpin(int $id): void
    {
        $this->update([
            'id'           => $id,
            'itemtype'     => '',
            'items_id'     => 0,
            'match_state'  => 'unmatched',
            'match_source' => '',
            'is_manual'    => 0,
        ]);
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $table, 'field' => 'display_name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $table, 'field' => 'ip_address', 'name' => __('IP address'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $table, 'field' => 'device_category', 'name' => __('Category'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 5, 'table' => $table, 'field' => 'availability', 'name' => __('Availability', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 6, 'table' => $table, 'field' => 'match_state', 'name' => __('Match state', 'opmanager'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 7, 'table' => $table, 'field' => 'items_id', 'name' => __('GLPI asset', 'opmanager'), 'datatype' => 'specific', 'additionalfields' => ['itemtype'], 'nosearch' => true, 'massiveaction' => false],
            ['id' => 8, 'table' => $table, 'field' => 'last_seen', 'name' => __('Last seen', 'opmanager'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 9, 'table' => $table, 'field' => 'is_manual', 'name' => __('Pinned manually', 'opmanager'), 'datatype' => 'bool', 'massiveaction' => false],
            // D34 NMS hygiene (spec §6).
            ['id' => 10, 'table' => $table, 'field' => 'hygiene_state', 'name' => __('Hygiene condition', 'opmanager'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 11, 'table' => $table, 'field' => 'liveness_state', 'name' => __('Liveness', 'opmanager'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 12, 'table' => $table, 'field' => 'liveness_detail', 'name' => __('Liveness evidence', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 13, 'table' => $table, 'field' => 'liveness_checked_at', 'name' => __('Liveness checked', 'opmanager'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    /** @return array<string, string> */
    public static function livenessLabels(): array
    {
        return [
            'alive'   => __('Alive', 'opmanager'),
            'dead'    => __('Dead', 'opmanager'),
            'unknown' => __('Unknown', 'opmanager'),
            ''        => __('Not checked', 'opmanager'),
        ];
    }

    /** @return array<string, string>|null labels of a specific field, null for other fields */
    private static function specificLabels(string $field): ?array
    {
        return match ($field) {
            'match_state'    => self::matchStateLabels(),
            'hygiene_state'  => Hygiene\Classifier::labels(),
            'liveness_state' => self::livenessLabels(),
            default          => null,
        };
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $labels = self::specificLabels((string) $field);
        if ($labels !== null) {
            return htmlescape($labels[(string) $values[$field]] ?? (string) $values[$field]);
        }
        if ($field === 'items_id') {
            return self::assetLink((string) ($values['itemtype'] ?? ''), (int) $values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $labels = self::specificLabels((string) $field);
        if ($labels !== null) {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return Dropdown::showFromArray($name, $labels, $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function assetLink(string $itemtype, int $items_id): string
    {
        if ($itemtype === '' || $items_id <= 0) {
            return '-';
        }
        $asset = getItemForItemtype($itemtype);
        if (!$asset instanceof CommonDBTM || !$asset->getFromDB($items_id)) {
            return htmlescape($itemtype . ' #' . $items_id);
        }
        return $asset->getLink();
    }

    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB((int) $ID)) {
            return false;
        }
        $f      = $this->fields;
        $labels = self::matchStateLabels();
        $rows   = [
            __('Name')                            => htmlescape($f['display_name'] ?: $f['name']),
            __('OpManager identity', 'opmanager') => htmlescape($f['device_key']),
            __('IP address')                      => htmlescape($f['ip_address']),
            __('Type')                            => htmlescape($f['device_type']),
            __('Category')                        => htmlescape($f['device_category']),
            __('Availability', 'opmanager')       => htmlescape($f['availability']),
            __('Last seen', 'opmanager')          => htmlescape((string) $f['last_seen']),
            __('Match state', 'opmanager')        => htmlescape($labels[$f['match_state']] ?? $f['match_state']),
            __('GLPI asset', 'opmanager')         => self::assetLink((string) $f['itemtype'], (int) $f['items_id']),
            __('Pinned manually', 'opmanager')    => $f['is_manual'] ? __s('Yes') : __s('No'),
        ];

        echo "<div class='card'><div class='card-body'><table class='table table-sm'>";
        foreach ($rows as $label => $value) {
            echo '<tr><th class="w-25">' . htmlescape($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table>';

        if (Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "' class='d-flex flex-wrap align-items-center gap-2'>";
            echo Html::hidden('id', ['value' => (int) $ID]);
            Dropdown::showSelectItemFromItemtypes([
                'itemtypes'        => Config::assetItemtypes(),
                'itemtype_name'    => 'itemtype',
                'items_id_name'    => 'items_id',
                'entity_restrict'  => -1,
                'default_itemtype' => $f['itemtype'] !== '' ? $f['itemtype'] : 0,
            ]);
            echo Html::submit(__('Pin to this asset', 'opmanager'), ['name' => 'pin', 'class' => 'btn btn-primary']);
            if ($f['is_manual']) {
                echo Html::submit(__('Remove manual pin', 'opmanager'), ['name' => 'unpin', 'class' => 'btn btn-outline-secondary']);
            }
            Html::closeForm();
        }
        if (Session::haveRight(RightDefinition::HYGIENE, READ)) {
            $this->showHygiene($f);
        }
        echo '</div></div>';
        return true;
    }

    /** D34: NMS hygiene block - condition, liveness, exception and its history (spec §5, §6). */
    private function showHygiene(array $f): void
    {
        $key       = (string) $f['device_key'];
        $history   = Hygiene\DeviceException::history($key);
        $active    = array_values(array_filter($history, static fn (array $h): bool => (int) $h['is_active'] === 1))[0] ?? null;
        $live      = self::livenessLabels()[(string) ($f['liveness_state'] ?? '')] ?? (string) $f['liveness_state'];
        $condition = Hygiene\Classifier::labels()[(string) ($f['hygiene_state'] ?? '')] ?? (string) $f['hygiene_state'];
        $evidence  = trim(($f['liveness_source'] ?? '') !== '' ? $f['liveness_source'] . ': ' . ($f['liveness_detail'] ?? '') : (string) ($f['liveness_detail'] ?? ''));

        echo '<h4 class="mt-3">' . __s('NMS hygiene', 'opmanager') . '</h4><table class="table table-sm">';
        $rows = [
            __('Hygiene condition', 'opmanager') => htmlescape($condition),
            __('Liveness', 'opmanager')          => htmlescape($live . ($evidence !== '' ? ' (' . $evidence . ')' : '')),
            __('Liveness checked', 'opmanager')  => htmlescape((string) ($f['liveness_checked_at'] ?? '') ?: '-'),
        ];
        $tickets = Hygiene\DbHygieneStore::openTicketsFor($key);
        if ($tickets !== []) {
            $rows[__('Open hygiene tickets', 'opmanager')] = implode(', ', array_map(
                static fn (int $id): string => '<a href="' . htmlescape(\Ticket::getFormURLWithID($id)) . '">#' . $id . '</a>',
                $tickets
            ));
        }
        foreach ($rows as $label => $value) {
            echo '<tr><th class="w-25">' . htmlescape($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table>';

        if ($active !== null) {
            echo '<div class="alert alert-info">' . htmlescape(sprintf(
                __('Excluded from hygiene tickets by %1$s on %2$s: %3$s', 'opmanager'),
                getUserName((int) $active['users_id']),
                (string) $active['date_creation'],
                (string) $active['reason']
            )) . '</div>';
        }

        if (Session::haveRight(RightDefinition::HYGIENE, UPDATE)) {
            echo "<form method='post' action='" . htmlescape(self::getFormURL()) . "' class='mb-2'>";
            echo Html::hidden('id', ['value' => (int) $f['id']]);
            if ($active === null) {
                echo '<textarea class="form-control mb-2" name="reason" rows="2" required minlength="5" maxlength="1000" placeholder="'
                    . __s('Reason, e.g. ISP-owned router, unmanaged on purpose (licence limit)', 'opmanager') . '"></textarea>';
                echo Html::submit(__('Exclude from hygiene tickets', 'opmanager'), ['name' => 'except', 'class' => 'btn btn-outline-warning']);
            } else {
                echo Html::submit(__('Cancel exclusion', 'opmanager'), ['name' => 'unexcept', 'class' => 'btn btn-outline-secondary']);
            }
            Html::closeForm();
        }

        if ($history !== []) {
            echo '<details><summary>' . __s('Exception history', 'opmanager') . '</summary><table class="table table-sm"><tr><th>'
                . __s('Excluded', 'opmanager') . '</th><th>' . __s('By', 'opmanager') . '</th><th>' . __s('Reason', 'opmanager') . '</th><th>' . __s('Cancelled', 'opmanager') . '</th></tr>';
            foreach ($history as $h) {
                $cancel = (int) $h['is_active'] === 1 ? '-' : (string) $h['revoked_at'] . ' (' . getUserName((int) $h['revoked_by']) . ')';
                echo '<tr><td>' . htmlescape((string) $h['date_creation']) . '</td><td>' . htmlescape(getUserName((int) $h['users_id'])) . '</td><td>'
                    . htmlescape((string) $h['reason']) . '</td><td>' . htmlescape($cancel) . '</td></tr>';
            }
            echo '</table></details>';
        }
    }
}
