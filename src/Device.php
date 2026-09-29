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
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'match_state') {
            return htmlescape(self::matchStateLabels()[$values[$field]] ?? (string) $values[$field]);
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
        if ($field === 'match_state') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return Dropdown::showFromArray($name, self::matchStateLabels(), $options);
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
        echo '</div></div>';
        return true;
    }
}
