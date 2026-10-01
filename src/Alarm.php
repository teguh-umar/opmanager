<?php

namespace GlpiPlugin\Opmanager;

use CommonDBTM;

/** Local copy of one OpManager alarm (read-only for users). */
class Alarm extends CommonDBTM
{
    public static $rightname = RightDefinition::MONITORING;

    public static function getTypeName($nb = 0)
    {
        return _n('OpManager alarm', 'OpManager alarms', $nb, 'opmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-bell';
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

    /** @return list<array<string, mixed>> active first, then newest change */
    public static function forAsset(string $itemtype, int $items_id, int $limit): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => ['state ASC', 'last_change DESC'],
            'LIMIT' => $limit,
        ]), false);
    }

    public static function countForAsset(string $itemtype, int $items_id): int
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ])->current()['cpt'];
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $table, 'field' => 'alarm_key', 'name' => __('Alarm ID', 'opmanager'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $table, 'field' => 'display_name', 'name' => __('Device', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 3, 'table' => $table, 'field' => 'severity_label', 'name' => __('Severity', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $table, 'field' => 'message', 'name' => __('Message', 'opmanager'), 'datatype' => 'text', 'massiveaction' => false],
            ['id' => 5, 'table' => $table, 'field' => 'state', 'name' => __('State', 'opmanager'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 6, 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 7, 'table' => $table, 'field' => 'last_change', 'name' => __('Last change', 'opmanager'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8, 'table' => $table, 'field' => 'ip_address', 'name' => __('IP address'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 9, 'table' => $table, 'field' => 'ticket_pending', 'name' => __('Ticket pending', 'opmanager'), 'datatype' => 'bool', 'massiveaction' => false],
        ];
    }

    /** The table has no `name` column: without this GLPI titles the page "N/A". */
    public function getName($options = [])
    {
        $name = trim(($this->fields['display_name'] ?? '') . ' - ' . ($this->fields['alarm_key'] ?? ''), ' -');
        return $name !== '' ? $name : parent::getName($options);
    }

    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB((int) $ID)) {
            return false;
        }
        $f    = $this->fields;
        $rows = [
            __('Alarm ID', 'opmanager')    => htmlescape($f['alarm_key']),
            __('OpManager entity', 'opmanager') => htmlescape($f['entity_key']),
            __('Device', 'opmanager')      => htmlescape($f['display_name'] ?: $f['device_name']),
            __('IP address')               => htmlescape($f['ip_address']),
            __('Severity', 'opmanager')    => htmlescape($f['severity_label']),
            __('Alarm type', 'opmanager')  => htmlescape($f['alarm_code']),
            __('Message', 'opmanager')     => htmlescape((string) $f['message']),
            __('State', 'opmanager')       => htmlescape($f['state']),
            __('Ticket')                   => $f['tickets_id'] > 0
                ? '<a href="' . htmlescape(\Ticket::getFormURLWithID((int) $f['tickets_id'])) . '">#' . (int) $f['tickets_id'] . '</a>'
                : ($f['ticket_pending'] ? __s('pending', 'opmanager') : '-'),
            __('Last change', 'opmanager') => htmlescape((string) $f['last_change']),
        ];
        echo "<div class='card'><div class='card-body'><table class='table table-sm'>";
        foreach ($rows as $label => $value) {
            echo '<tr><th class="w-25">' . htmlescape($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table></div></div>';
        return true;
    }
}
