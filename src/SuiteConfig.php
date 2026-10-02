<?php

namespace GlpiPlugin\Opmanager;

/**
 * OpManager's section in the shared "PGN Suite" tab on Setup > General (hub §5).
 * The same form renders on front/config.php for admins without core `config` READ.
 * Layout (D29): a status strip, then one tab per area; one form, one Save button, and
 * field names unchanged so front/config.php needs no change.
 */
final class SuiteConfig
{
    public const SECTION = 'opmanager';

    /** Hidden field marking a POST that came from the PGN Suite tab. */
    public const FROM_SUITE_FIELD = '_from_pgnsuite';

    /** Session key holding a freshly generated webhook token until the form shows it once. */
    public const NEW_TOKEN_KEY = 'plugin_opmanager_new_webhook_token';

    public const TABS = ['connection', 'matching', 'tickets', 'filter', 'webhook', 'outbound'];

    public static function section(): ?array
    {
        if (!\Session::haveRight(RightDefinition::CONFIG, READ)) {
            return null;
        }
        return [
            'title'  => 'OpManager',
            'icon'   => 'ti ti-activity',
            'order'  => 70,
            'render' => self::class . '::render',
        ];
    }

    public static function render(): void
    {
        self::showForm(true);
    }

    public static function showForm(bool $from_suite = false): void
    {
        $canedit  = \Session::haveRight(RightDefinition::CONFIG, UPDATE);
        $disabled = $canedit ? '' : ' disabled';
        $sfx      = $from_suite ? '-suite' : '';

        echo '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/config.php')) . '">';
        if ($from_suite) {
            echo \Html::hidden(self::FROM_SUITE_FIELD, ['value' => 1]);
        }

        self::statusStrip();

        $labels = [
            'connection' => __('Connection', 'opmanager'),
            'matching'   => __('Matching', 'opmanager'),
            'tickets'    => __('Tickets', 'opmanager'),
            'filter'     => __('Alarm filter', 'opmanager'),
            'webhook'    => __('Webhook', 'opmanager'),
            'outbound'   => __('To OpManager', 'opmanager'),
        ];
        echo '<ul class="nav nav-tabs" role="tablist">';
        foreach (self::TABS as $i => $tab) {
            echo '<li class="nav-item" role="presentation"><a class="nav-link' . ($i === 0 ? ' active' : '') . '" data-bs-toggle="tab" role="tab" href="#opm-tab-' . $tab . $sfx . '">'
                . htmlescape($labels[$tab]) . '</a></li>';
        }
        echo '</ul><div class="tab-content border border-top-0 rounded-bottom p-3 mb-3">';
        foreach (self::TABS as $i => $tab) {
            echo '<div class="tab-pane' . ($i === 0 ? ' active show' : '') . '" role="tabpanel" id="opm-tab-' . $tab . $sfx . '">';
            match ($tab) {
                'connection' => self::tabConnection($sfx, $disabled),
                'matching'   => self::tabMatching($sfx, $disabled, $canedit),
                'tickets'    => self::tabTickets($sfx, $disabled, $canedit),
                'filter'     => self::showAlarmFilter($sfx, $disabled),
                'webhook'    => self::tabWebhook($canedit),
                'outbound'   => self::tabOutbound($sfx, $disabled),
            };
            echo '</div>';
        }
        echo '</div>';

        if ($canedit) {
            echo '<div class="mt-2">' . \Html::submit(_sx('button', 'Save'), ['name' => 'update']) . '</div>';
        }
        // Reopen the last tab after Save/reload; per browser tab, harmless if storage is blocked.
        echo \Html::scriptBlock("(function(){var k='opm-setup-tab';try{var h=sessionStorage.getItem(k);if(h){var a=document.querySelector('a[href=\"'+h+'\"]');if(a&&window.bootstrap){bootstrap.Tab.getOrCreateInstance(a).show();}}}catch(e){}document.querySelectorAll('a[href^=\"#opm-tab-\"]').forEach(function(a){a.addEventListener('shown.bs.tab',function(){try{sessionStorage.setItem(k,a.getAttribute('href'));}catch(e){}});});})();");
        \Html::closeForm();
    }

    private static function statusStrip(): void
    {
        $box   = (new \GlpiPlugin\Opmanager\Outbound\DbOutboxStore())->summary();
        $items = SetupStatus::items([
            'api_key_set'            => Config::get('opm_api_key') !== '',
            'last_device_sync'       => Config::get('last_device_sync'),
            'last_device_sync_error' => Config::get('last_device_sync_error'),
            'stale_minutes'          => max(1, (int) Config::get('stale_warning_minutes')),
            'last_alarm_sync'        => Config::get('last_alarm_sync'),
            'last_alarm_sync_error'  => Config::get('last_alarm_sync_error'),
            'alarm_minutes'          => Config::ALARM_SYNC_MINUTES,
            'webhook_token_set'      => Config::get('webhook_token') !== '',
            'outbox_pending'         => $box['pending'],
            'outbox_failed'          => $box['failed'],
        ], (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')));
        $color = ['ok' => 'success', 'warn' => 'warning', 'error' => 'danger'];
        $icon  = ['ok' => 'ti-circle-check', 'warn' => 'ti-alert-triangle', 'error' => 'ti-circle-x'];
        echo '<div class="opm-status d-flex flex-wrap gap-2 mb-3">';
        foreach ($items as $it) {
            $text = strlen($it['text']) > 60 ? substr($it['text'], 0, 57) . '...' : $it['text'];
            echo '<span class="badge bg-' . $color[$it['level']] . '-lt fs-5 py-2 px-3" title="' . htmlescape($it['text']) . '">'
                . '<i class="ti ' . $icon[$it['level']] . ' me-1"></i>' . htmlescape($it['label']) . ': ' . htmlescape($text) . '</span>';
        }
        echo '</div>';
    }

    private static function tabConnection(string $sfx, string $disabled): void
    {
        echo self::textRow('opm_url' . $sfx, 'opm_url', __('OpManager URL', 'opmanager'), Config::get('opm_url'), 'url', $disabled);

        // The stored key is NEVER echoed back (Review Focus 2): blank = keep.
        $has_key     = Config::get('opm_api_key') !== '';
        $placeholder = $has_key ? __('Stored - leave blank to keep', 'opmanager') : __('Not set', 'opmanager');
        echo '<div class="mb-2"><label class="form-label" for="opm_api_key' . $sfx . '">' . __s('API key', 'opmanager') . '</label>';
        echo '<input type="password" autocomplete="new-password" class="form-control" id="opm_api_key' . $sfx . '" name="opm_api_key" value="" placeholder="' . htmlescape($placeholder) . '"' . $disabled . '>';
        if ($has_key) {
            echo '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="opm_api_key_clear" value="1" id="opm_api_key_clear' . $sfx . '"' . $disabled . '>';
            echo '<label class="form-check-label" for="opm_api_key_clear' . $sfx . '">' . __s('Remove the stored API key', 'opmanager') . '</label></div>';
        }
        echo '</div>';

        echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="opm_verify_ssl" value="1" id="opm_verify_ssl' . $sfx . '"'
            . (Config::get('opm_verify_ssl') === '1' ? ' checked' : '') . $disabled . '>';
        echo '<label class="form-check-label" for="opm_verify_ssl' . $sfx . '">' . __s('Verify the OpManager TLS certificate', 'opmanager') . '</label></div>';
    }

    private static function tabMatching(string $sfx, string $disabled, bool $canedit): void
    {
        echo '<label class="form-label" for="match_mode' . $sfx . '">' . __s('Match mode', 'opmanager') . '</label>';
        \Dropdown::showFromArray('match_mode', Config::matchModeLabels(), [
            'value'    => Config::get('match_mode'),
            'width'    => '100%',
            'readonly' => !$canedit,
            'rand'     => 'match_mode' . $sfx,
        ]);
        echo '<div class="form-hint">' . __s('A manual pin always wins. A name or IP owned by more than one GLPI asset is never used: the device goes to "Needs review".', 'opmanager') . '</div>';

        echo '<div class="mt-2">' . __s('Asset types to match and to show the Monitoring section on', 'opmanager') . '</div>';
        $selected = Config::assetItemtypes();
        foreach (Config::offeredItemtypes() as $itemtype => $label) {
            $id = 'opm-asset-' . md5($itemtype) . $sfx;
            if (in_array($itemtype, Config::BASE_ASSET_ITEMTYPES, true) && is_a($itemtype, \CommonGLPI::class, true)) {
                $label = $itemtype::getTypeName(2);
            }
            echo sprintf(
                '<div class="form-check"><input class="form-check-input" type="checkbox" name="asset_itemtypes[]" value="%s" id="%s"%s%s><label class="form-check-label" for="%s">%s</label></div>',
                htmlescape($itemtype),
                htmlescape($id),
                in_array($itemtype, $selected, true) ? ' checked' : '',
                $disabled,
                htmlescape($id),
                htmlescape($label)
            );
        }
        echo self::textRow('stale_warning_minutes' . $sfx, 'stale_warning_minutes', __('Warn when device data is older than (minutes)', 'opmanager'), Config::get('stale_warning_minutes'), 'number', $disabled);

        echo '<div class="mt-2"><label class="form-label">' . __s('Coverage page: GLPI statuses counted as in use (empty = all)', 'opmanager') . '</label>';
        // 'value', not 'values': for multiple, Dropdown::show() copies value into values (11.0.5).
        \State::dropdown(['name' => 'coverage_states[]', 'multiple' => true, 'value' => Config::coverageStates(), 'width' => '100%', 'readonly' => !$canedit]);
        echo '</div>';

        $last  = Config::get('last_device_sync');
        $error = Config::get('last_device_sync_error');
        echo '<div class="mt-3">' . __s('Last successful device sync', 'opmanager') . ': ' . htmlescape($last !== '' ? $last : __('never', 'opmanager')) . '</div>';
        if ($error !== '') {
            echo '<div class="alert alert-warning">' . __s('Last device sync error', 'opmanager') . ': ' . htmlescape($error) . '</div>';
        }
    }

    private static function tabTickets(string $sfx, string $disabled, bool $canedit): void
    {
        echo '<div>' . __s('Create a ticket for these OpManager severities', 'opmanager') . '</div>';
        $sev_selected = json_decode(Config::get('ticket_severities'), true) ?: [];
        foreach (Config::SEVERITIES as $num => $label) {
            $id = 'opm-sev-' . $num . $sfx;
            echo sprintf(
                '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="ticket_severities[]" value="%d" id="%s"%s%s><label class="form-check-label" for="%s">%s</label></div>',
                $num,
                htmlescape($id),
                in_array($num, $sev_selected, true) ? ' checked' : '',
                $disabled,
                htmlescape($id),
                htmlescape($label)
            );
        }
        echo '<div class="mt-2">' . __s('Production IP ranges (Tools > IP Addressing): tickets are created only for alarms on these', 'opmanager') . '</div>';
        $prod_selected = array_map('intval', (array) (json_decode(Config::get('production_addressings'), true) ?: []));
        $prod_options  = \GlpiPlugin\Opmanager\Ticketing\AddressingRanges::options();
        foreach ($prod_options as $rid => $rlabel) {
            $id = 'opm-prod-' . $rid . $sfx;
            echo sprintf(
                '<div class="form-check"><input class="form-check-input" type="checkbox" name="production_addressings[]" value="%d" id="%s"%s%s><label class="form-check-label" for="%s">%s</label></div>',
                $rid,
                htmlescape($id),
                in_array($rid, $prod_selected, true) ? ' checked' : '',
                $disabled,
                htmlescape($id),
                htmlescape($rlabel)
            );
        }
        if ($prod_options === []) {
            echo '<div class="alert alert-warning">' . __s('No IP Addressing range found (is the IP Addressing plugin active?). No ticket will be created.', 'opmanager') . '</div>';
        } elseif (array_intersect($prod_selected, array_keys($prod_options)) === []) {
            echo '<div class="alert alert-warning">' . __s('No production range selected: alarms are stored but no ticket is created.', 'opmanager') . '</div>';
        }
        echo '<div class="mt-2"><label class="form-label">' . __s('Ticket template', 'opmanager') . '</label>';
        \TicketTemplate::dropdown([
            'name'                => 'ticket_template',
            'value'               => (int) Config::get('ticket_template'),
            'display_emptychoice' => true,
            'emptylabel'          => __('Default GLPI template', 'opmanager'),
            'readonly'            => !$canedit,
        ]);
        echo '</div>';
        echo '<div class="mt-2"><label class="form-label">' . __s('GLPI user that creates the tickets (service account)', 'opmanager') . '</label>';
        \User::dropdown(['name' => 'service_user', 'value' => (int) Config::get('service_user'), 'right' => 'all', 'readonly' => !$canedit]);
        echo '</div>';
        echo '<div class="mt-2"><label class="form-label">' . __s('Default entity (no asset and no matching IP network)', 'opmanager') . '</label>';
        \Entity::dropdown(['name' => 'ticket_entity_default', 'value' => (int) Config::get('ticket_entity_default'), 'readonly' => !$canedit]);
        echo '</div>';
        echo '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="ticket_use_ipnetwork_entity" value="1" id="opm-ipnet' . $sfx . '"'
            . (Config::get('ticket_use_ipnetwork_entity') === '1' ? ' checked' : '') . $disabled . '>';
        echo '<label class="form-check-label" for="opm-ipnet' . $sfx . '">' . __s('Use the entity of the GLPI IP network containing the device IP', 'opmanager') . '</label></div>';
        echo self::textRow('ticket_title_pattern' . $sfx, 'ticket_title_pattern', __('Ticket title pattern', 'opmanager'), Config::get('ticket_title_pattern'), 'text', $disabled);
        echo '<div class="mb-2"><label class="form-label" for="ticket_content_pattern' . $sfx . '">' . __s('Ticket description pattern', 'opmanager') . '</label>'
            . '<textarea class="form-control" rows="6" id="ticket_content_pattern' . $sfx . '" name="ticket_content_pattern"' . $disabled . '>' . htmlescape(Config::get('ticket_content_pattern')) . '</textarea>'
            . '<div class="form-hint">' . __s('Variables: {alarm_key} {severity} {message} {device} {ip} {alarm_type} {time} {category}', 'opmanager') . '</div></div>';
        echo self::textRow('relink_window_hours' . $sfx, 'relink_window_hours', __('Link a repeat of the same alarm type on the same device within (hours)', 'opmanager'), Config::get('relink_window_hours'), 'number', $disabled);
        echo self::textRow('storm_threshold' . $sfx, 'storm_threshold', __('Alarm storm: new tickets allowed per window before grouping (0 = off)', 'opmanager'), Config::get('storm_threshold'), 'number', $disabled);
        echo self::textRow('storm_window_minutes' . $sfx, 'storm_window_minutes', __('Alarm storm window (minutes)', 'opmanager'), Config::get('storm_window_minutes'), 'number', $disabled);
        $alast  = Config::get('last_alarm_sync');
        $aerror = Config::get('last_alarm_sync_error');
        echo '<div class="mt-2">' . __s('Last successful alarm sync', 'opmanager') . ': ' . htmlescape($alast !== '' ? $alast : __('never', 'opmanager')) . '</div>';
        if ($aerror !== '') {
            echo '<div class="alert alert-warning">' . __s('Last alarm sync error', 'opmanager') . ': ' . htmlescape($aerror) . '</div>';
        }
    }

    private static function tabWebhook(bool $canedit): void
    {
        global $CFG_GLPI;
        $webhook_url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . Config::WEBHOOK_PATH;
        echo '<div>' . __s('OpManager > Notification Profile > Invoke a Webhook: POST, raw JSON', 'opmanager') . '</div>';
        echo '<div class="mb-1">URL: <code>' . htmlescape($webhook_url) . '</code></div>';
        echo '<div class="mb-1">' . __s('Header', 'opmanager') . ': <code>' . htmlescape(Config::WEBHOOK_HEADER) . '</code> = '
            . (Config::get('webhook_token') !== '' ? __s('stored token (shown once when generated)', 'opmanager') : '<strong>' . __s('not set - webhook disabled', 'opmanager') . '</strong>') . '</div>';
        echo '<div class="mb-1">' . __s('Body', 'opmanager') . ': <code>{"alarm_id":"$alarmid","entity":"$entity"}</code></div>';
        $new_token = (string) ($_SESSION[self::NEW_TOKEN_KEY] ?? '');
        unset($_SESSION[self::NEW_TOKEN_KEY]);
        if ($new_token !== '') {
            echo '<div class="alert alert-warning mt-2">' . __s('New webhook token - copy it into OpManager now, it is not shown again:', 'opmanager')
                . '<input type="text" class="form-control font-monospace mt-1" readonly onclick="this.select()" value="' . htmlescape($new_token) . '"></div>';
        }
        if ($canedit) {
            echo '<div class="mt-1">' . \Html::submit(__('Generate new token', 'opmanager'), ['name' => 'generate_webhook_token', 'class' => 'btn btn-outline-secondary']) . '</div>';
        }
    }

    private static function tabOutbound(string $sfx, string $disabled): void
    {
        // Plan 3: GLPI -> OpManager queue (spec §8).
        echo '<div class="mb-2"><strong>' . __s('Sent to OpManager (notes, acknowledges and clears)', 'opmanager') . '</strong></div>';
        echo self::textRow('outbound_max_attempts' . $sfx, 'outbound_max_attempts', __('Retries before a note/clear is given up (1-50)', 'opmanager'), Config::get('outbound_max_attempts'), 'number', $disabled);
        $box = (new \GlpiPlugin\Opmanager\Outbound\DbOutboxStore())->summary();
        echo '<div>' . sprintf(__s('Waiting: %d, given up: %d', 'opmanager'), $box['pending'], $box['failed']) . '</div>';
        if ($box['last_error'] !== '') {
            echo '<div class="alert alert-warning">' . __s('Last OpManager write error', 'opmanager') . ': ' . htmlescape($box['last_error']) . '</div>';
        }
    }

    /** Alarm filter (v0.3.0): event types seen so far plus saved ones, and message keywords. */
    private static function showAlarmFilter(string $sfx, string $disabled): void
    {
        $filter = Config::filterSettings(Config::get('ticket_filter_mode'), Config::get('ticket_filter_event_types'), Config::get('ticket_filter_keywords'));
        echo '<div><label class="form-label" for="opm-filter-mode' . $sfx . '">' . __s('Alarm filter (new tickets only)', 'opmanager') . '</label>';
        echo '<select class="form-select" id="opm-filter-mode' . $sfx . '" name="ticket_filter_mode"' . $disabled . '>';
        $labels = [
            'exclude' => __('Exclude: matching alarms get no ticket', 'opmanager'),
            'include' => __('Only: only matching alarms get a ticket', 'opmanager'),
        ];
        foreach (Config::FILTER_MODES as $mode) {
            echo '<option value="' . htmlescape($mode) . '"' . ($filter['filter_mode'] === $mode ? ' selected' : '') . '>' . htmlescape($labels[$mode]) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="mt-2">' . __s('OpManager event types', 'opmanager') . '</div>';
        $types = array_values(array_unique(array_merge(\GlpiPlugin\Opmanager\Ticketing\AlarmFilter::knownEventTypes(), $filter['filter_event_types'])));
        sort($types);
        foreach ($types as $i => $type) {
            $id = 'opm-evt-' . $i . $sfx;
            echo '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="ticket_filter_event_types[]" value="' . htmlescape($type) . '" id="' . htmlescape($id) . '"'
                . (in_array($type, $filter['filter_event_types'], true) ? ' checked' : '') . $disabled . '>'
                . '<label class="form-check-label" for="' . htmlescape($id) . '">' . htmlescape($type) . '</label></div>';
        }
        if ($types === []) {
            echo '<div class="form-hint">' . __s('No alarm received yet; event types appear here after the first alarm sync.', 'opmanager') . '</div>';
        }
        echo '<div class="mt-2"><label class="form-label" for="opm-filter-kw' . $sfx . '">' . __s('Message keywords (one per line, not case sensitive)', 'opmanager') . '</label>'
            . '<textarea class="form-control" rows="3" id="opm-filter-kw' . $sfx . '" name="ticket_filter_keywords"' . $disabled . '>' . htmlescape(implode("\n", $filter['filter_keywords'])) . '</textarea>'
            . '<div class="form-hint">' . __s('An alarm matches when its event type is ticked or its message contains a keyword. Alarms that already have a ticket are not affected.', 'opmanager') . '</div></div>';
        if ($filter['filter_event_types'] === [] && $filter['filter_keywords'] === []) {
            echo '<div class="alert alert-info mt-1">' . __s('Alarm filter is off: every alarm that passes the severity and production rules gets a ticket.', 'opmanager') . '</div>';
        }
    }

    private static function textRow(string $id, string $name, string $label, string $value, string $type, string $disabled): string
    {
        return '<div class="mb-2"><label class="form-label" for="' . htmlescape($id) . '">' . htmlescape($label) . '</label>'
            . '<input type="' . $type . '" class="form-control" id="' . htmlescape($id) . '" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '"' . $disabled . '></div>';
    }
}
