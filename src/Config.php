<?php

namespace GlpiPlugin\Opmanager;

final class Config
{
    public const CONTEXT = 'plugin:opmanager';

    /**
     * Every accepted match mode, in dropdown order. The ONLY list: the dropdown,
     * Matcher and front/config.php all read it (integrasipaman v2.6.0 shipped a
     * mode its save controller silently rejected - hub §4).
     */
    public const MATCH_MODES = ['ip_only', 'name_only', 'hybrid', 'hybrid_name', 'manual'];

    /** Encrypted at rest by core via $PLUGIN_HOOKS['secured_configs'] (GLPIKey::getConfigs). */
    public const SECURED = ['opm_api_key', 'webhook_token'];

    public const WEBHOOK_HEADER = 'X-OPM-Token';

    public const WEBHOOK_PATH = '/plugins/opmanager/front/webhook.php';

    public const BASE_ASSET_ITEMTYPES = ['Computer', 'NetworkEquipment'];

    /**
     * DeviceSync frequency. The stale warning default must stay above it, or every
     * Monitoring section warns for part of each cycle (final review, 2026-09-29).
     */
    public const DEVICE_SYNC_MINUTES = 60;

    /** AlarmSync frequency (spec §3). */
    public const ALARM_SYNC_MINUTES = 10;

    /** OpManager numericSeverity values a ticket may be created for (D19); 5 = Clear never is. */
    public const SEVERITIES = [1 => 'Critical', 2 => 'Trouble', 3 => 'Attention', 4 => 'Service Down'];

    public const DEFAULT_TITLE_PATTERN = '[OPM-{alarm_key}] {severity}: {message} - {device}';

    /**
     * "Category: {category}" carries OpManager's device category (Server, Switch,
     * Firewall, ...) so GLPI's own ticket business rules can map it to an ITIL
     * category (user decision 2026-10-01, rule criterion: Description contains
     * "Category: Server").
     */
    public const DEFAULT_CONTENT_PATTERN = "OpManager alarm {alarm_key}\nDevice: {device} ({ip})\nCategory: {category}\nSeverity: {severity}\nAlarm type: {alarm_type}\nTime: {time}\n\n{message}";

    /** Earlier defaults; an install still using one of them is upgraded, a customised pattern never is. */
    public const PREVIOUS_CONTENT_PATTERNS = [
        "OpManager alarm {alarm_key}\nDevice: {device} ({ip})\nSeverity: {severity}\nAlarm type: {alarm_type}\nTime: {time}\n\n{message}",
    ];

    /** Alarm filter modes; same list as Ticketing\AlarmFilter::MODES (first = default). */
    public const FILTER_MODES = ['exclude', 'include'];

    /** D32: who decides that an alarm becomes a ticket (first = default, also on upgrade). */
    public const TICKET_SOURCES = ['webhook', 'rules'];

    public const FILTER_KEYWORDS_MAX = 2000;

    public const DEFAULTS = [
        'opm_url'                => '',
        'opm_api_key'            => '',
        'webhook_token'          => '',
        'opm_verify_ssl'         => '1',
        'match_mode'             => 'hybrid_name',
        'asset_itemtypes'        => '["Computer","NetworkEquipment"]',
        'stale_warning_minutes'  => '90',
        'coverage_states'        => '[]',
        'last_device_sync'       => '',
        'last_device_sync_error' => '',
        'ticket_severities'           => '[1]',
        'ticket_source'               => 'webhook',
        'ticket_template'             => '0',
        'ticket_title_pattern'        => self::DEFAULT_TITLE_PATTERN,
        'ticket_content_pattern'      => self::DEFAULT_CONTENT_PATTERN,
        'service_user'                => '0',
        'ticket_entity_default'       => '0',
        'ticket_use_ipnetwork_entity' => '1',
        'relink_window_hours'         => '24',
        'storm_threshold'             => '10',
        'storm_window_minutes'        => '5',
        'outbound_max_attempts'       => '10',
        'production_addressings'      => '[]',
        'ticket_filter_mode'          => 'exclude',
        'ticket_filter_event_types'   => '[]',
        'ticket_filter_keywords'      => '',
        'last_alarm_sync'             => '',
        'last_alarm_sync_error'       => '',
        // v1.3.0 (D34) NMS hygiene, spec §8.4.
        'hygiene_enabled'                  => '0',
        'hygiene_frequency_days'           => '7',
        'hygiene_unmanaged_availabilities' => '["UnManaged","Device Not Monitored."]',
        'hygiene_agent_stale_days'         => '7',
        'hygiene_mist_stale_days'          => '7',
        'hygiene_probe_cidrs'              => '[]',
        'hygiene_probe_tcp_ports'          => '[22,443,80,3389,161]',
        'hygiene_probe_timeout_ms'         => '1000',
        'hygiene_ticket_template'          => '0',
        'hygiene_glpi_groups'              => '[]',
        'hygiene_glpi_users'               => '[]',
        'hygiene_itil_category'            => '0',
        'hygiene_nms_group'                => '0',
        'last_hygiene_run'                 => '',
        'last_hygiene_ticket_run'          => '',
        'last_hygiene_error'               => '',
    ];

    /** HygieneCheck frequency: daily; tickets follow hygiene_frequency_days (spec §7). */
    public const HYGIENE_CRON_MINUTES = 1440;

    public static function get(string $key): string
    {
        $values = \Config::getConfigurationValues(self::CONTEXT, [$key]);
        return (string) ($values[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    /** @param array<string, string> $values */
    public static function set(array $values): void
    {
        \Config::setConfigurationValues(self::CONTEXT, $values);
    }

    /** Core encrypts secured values on write but never decrypts on read. */
    public static function apiKey(): string
    {
        $raw = self::get('opm_api_key');
        return $raw === '' ? '' : (string) ((new \GLPIKey())->decrypt($raw) ?? '');
    }

    /** Decrypted webhook token; '' = webhook disabled. */
    public static function webhookToken(): string
    {
        $raw = self::get('webhook_token');
        return $raw === '' ? '' : (string) ((new \GLPIKey())->decrypt($raw) ?? '');
    }

    public static function newWebhookToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function setDefaults(): void
    {
        $current = \Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::DEFAULTS, $current);
        if ($missing !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /** Pure: CRLF / CR -> LF (a browser textarea posts CRLF). */
    public static function normaliseLines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** Pure: the pattern to store on upgrade, or null to keep the current one. */
    public static function upgradedContentPattern(string $current): ?string
    {
        return in_array(self::normaliseLines($current), self::PREVIOUS_CONTENT_PATTERNS, true) ? self::DEFAULT_CONTENT_PATTERN : null;
    }

    /** @return array<string, string> mode => label, keys identical to MATCH_MODES */
    public static function matchModeLabels(): array
    {
        return [
            'ip_only'     => __('IP only', 'opmanager'),
            'name_only'   => __('Name only', 'opmanager'),
            'hybrid'      => __('Hybrid - IP first, then name', 'opmanager'),
            'hybrid_name' => __('Hybrid - name first, then IP', 'opmanager'),
            'manual'      => __('Manual only - no automatic matching', 'opmanager'),
        ];
    }

    /**
     * Validate a POST from the Setup form. Pure. A key missing from `values`
     * means "keep what is stored" (e.g. a blank API key field).
     *
     * @param array<string, mixed> $post
     * @param string[]             $allowed_itemtypes itemtypes the form offered
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitize(array $post, array $allowed_itemtypes): array
    {
        $values = [];
        $errors = [];

        $url = trim((string) ($post['opm_url'] ?? ''));
        if ($url === '' || preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $url) === 1) {
            $values['opm_url'] = rtrim($url, '/');
        } else {
            $errors[] = __('OpManager URL must start with http:// or https://', 'opmanager');
        }

        if (!empty($post['opm_api_key_clear'])) {
            $values['opm_api_key'] = '';
        } else {
            $key = trim((string) ($post['opm_api_key'] ?? ''));
            if ($key !== '') {
                $values['opm_api_key'] = $key;
            }
        }

        $values['opm_verify_ssl'] = empty($post['opm_verify_ssl']) ? '0' : '1';

        $mode = (string) ($post['match_mode'] ?? '');
        if (in_array($mode, self::MATCH_MODES, true)) {
            $values['match_mode'] = $mode;
        } else {
            $errors[] = __('Unknown match mode', 'opmanager');
        }

        $posted = array_filter((array) ($post['asset_itemtypes'] ?? []), 'is_string');
        $values['asset_itemtypes'] = (string) json_encode(array_values(array_intersect($posted, $allowed_itemtypes)));

        $stale = (int) ($post['stale_warning_minutes'] ?? 0);
        if ($stale >= 5 && $stale <= 1440) {
            $values['stale_warning_minutes'] = (string) $stale;
        } else {
            $errors[] = __('Stale warning must be between 5 and 1440 minutes', 'opmanager');
        }

        // Coverage page (D28): GLPI states counted as "in use"; [] = every state.
        $states = array_values(array_unique(array_filter(array_map('intval', (array) ($post['coverage_states'] ?? [])), static fn (int $id) => $id > 0)));
        $values['coverage_states'] = (string) json_encode($states);

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Validate the ticketing part of the Setup form. Pure; a key missing from
     * `values` means "keep what is stored".
     *
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitizeTicketing(array $post): array
    {
        $values = [];
        $errors = [];

        $source = (string) ($post['ticket_source'] ?? '');
        $values['ticket_source'] = in_array($source, self::TICKET_SOURCES, true) ? $source : self::TICKET_SOURCES[0];

        $sev = array_values(array_intersect(
            array_map('intval', (array) ($post['ticket_severities'] ?? [])),
            array_keys(self::SEVERITIES)
        ));
        sort($sev);
        $values['ticket_severities'] = (string) json_encode($sev);

        foreach (['ticket_template', 'service_user', 'ticket_entity_default'] as $key) {
            $values[$key] = (string) max(0, (int) ($post[$key] ?? 0));
        }

        foreach (['ticket_title_pattern' => 250, 'ticket_content_pattern' => 5000] as $key => $max) {
            $v = trim(self::normaliseLines((string) ($post[$key] ?? '')));
            if ($v === '' || strlen($v) > $max) {
                $errors[] = sprintf(__('%s must be 1 to %d characters', 'opmanager'), $key, $max);
            } else {
                $values[$key] = $v;
            }
        }

        $values['ticket_use_ipnetwork_entity'] = empty($post['ticket_use_ipnetwork_entity']) ? '0' : '1';

        $ranges = array_values(array_filter(array_map('intval', (array) ($post['production_addressings'] ?? [])), static fn (int $id) => $id > 0));
        $values['production_addressings'] = (string) json_encode($ranges);

        $mode = (string) ($post['ticket_filter_mode'] ?? '');
        $values['ticket_filter_mode'] = in_array($mode, self::FILTER_MODES, true) ? $mode : self::FILTER_MODES[0];
        $types = [];
        foreach ((array) ($post['ticket_filter_event_types'] ?? []) as $type) {
            $type = trim((string) $type);
            if ($type !== '' && strlen($type) <= 255) {
                $types[$type] = true;
            }
        }
        $values['ticket_filter_event_types'] = (string) json_encode(array_keys($types));
        $keywords = implode("\n", self::lines((string) ($post['ticket_filter_keywords'] ?? '')));
        if (strlen($keywords) > self::FILTER_KEYWORDS_MAX) {
            $errors[] = sprintf(__('%s must be at most %d characters', 'opmanager'), 'ticket_filter_keywords', self::FILTER_KEYWORDS_MAX);
        } else {
            $values['ticket_filter_keywords'] = $keywords;
        }

        foreach (['relink_window_hours' => [0, 720], 'storm_threshold' => [0, 1000], 'storm_window_minutes' => [1, 120], 'outbound_max_attempts' => [1, 50]] as $key => [$min, $max]) {
            $n = (int) ($post[$key] ?? -1);
            if ($n < $min || $n > $max) {
                $errors[] = sprintf(__('%s must be between %d and %d', 'opmanager'), $key, $min, $max);
            } else {
                $values[$key] = (string) $n;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Validate the NMS hygiene part of the Setup form (D34, spec §8.4). Pure; an
     * invalid field is an error and keeps its stored value.
     *
     * @return array{values: array<string, string>, errors: list<string>}
     */
    public static function sanitizeHygiene(array $post): array
    {
        $values = [];
        $errors = [];

        foreach (['hygiene_frequency_days' => [1, 90], 'hygiene_agent_stale_days' => [1, 365], 'hygiene_mist_stale_days' => [1, 365], 'hygiene_probe_timeout_ms' => [100, 5000]] as $key => [$min, $max]) {
            $n = (int) ($post[$key] ?? -1);
            if ($n < $min || $n > $max) {
                $errors[] = sprintf(__('%s must be between %d and %d', 'opmanager'), $key, $min, $max);
            } else {
                $values[$key] = (string) $n;
            }
        }

        $unmanaged = self::lines((string) ($post['hygiene_unmanaged_availabilities'] ?? ''));
        if ($unmanaged === []) {
            $errors[] = __('List at least one OpManager availability that means "not monitored"', 'opmanager');
        } else {
            $values['hygiene_unmanaged_availabilities'] = (string) json_encode($unmanaged);
        }

        $cidrs = self::lines((string) ($post['hygiene_probe_cidrs'] ?? ''));
        $bad   = array_values(array_filter($cidrs, static fn (string $c): bool => !Hygiene\Cidr::isValid($c)));
        if ($bad !== []) {
            $errors[] = sprintf(__('Invalid IPv4 CIDR: %s', 'opmanager'), implode(', ', $bad));
        } else {
            $values['hygiene_probe_cidrs'] = (string) json_encode($cidrs, JSON_UNESCAPED_SLASHES);
        }

        $ports = [];
        $ok    = true;
        foreach (preg_split('/[\s,;]+/', (string) ($post['hygiene_probe_tcp_ports'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if (!ctype_digit($p) || (int) $p < 1 || (int) $p > 65535) {
                $ok = false;
                break;
            }
            $ports[(int) $p] = true;
        }
        if (!$ok || count($ports) > 10) {
            $errors[] = __('Probe ports: up to 10 TCP ports between 1 and 65535', 'opmanager');
        } else {
            $values['hygiene_probe_tcp_ports'] = (string) json_encode(array_keys($ports));
        }

        $values['hygiene_ticket_template'] = (string) max(0, (int) ($post['hygiene_ticket_template'] ?? 0));
        $groups = array_values(array_unique(array_filter(array_map('intval', (array) ($post['hygiene_glpi_groups'] ?? [])), static fn (int $id) => $id > 0)));
        $values['hygiene_glpi_groups'] = (string) json_encode($groups);
        // rc4: the technician(s) responsible for asset input and checks, assigned with the groups.
        $users = array_values(array_unique(array_filter(array_map('intval', (array) ($post['hygiene_glpi_users'] ?? [])), static fn (int $id) => $id > 0)));
        $values['hygiene_glpi_users'] = (string) json_encode($users);
        // rc5: tickets skip the business rules, so the ITIL category comes from here (0 = none).
        $values['hygiene_itil_category'] = (string) max(0, (int) ($post['hygiene_itil_category'] ?? 0));
        $values['hygiene_nms_group']   = (string) max(0, (int) ($post['hygiene_nms_group'] ?? 0));

        $enabled = !empty($post['hygiene_enabled']);
        if ($enabled && ($groups === [] || $values['hygiene_nms_group'] === '0')) {
            $errors[] = __('NMS hygiene tickets need the GLPI/asset team group(s) and the NMS team group; tickets stay off', 'opmanager');
            $enabled  = false;
        }
        $values['hygiene_enabled'] = $enabled ? '1' : '0';

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @return array{enabled: bool, frequency_days: int, unmanaged_values: list<string>, agent_stale_days: int, mist_stale_days: int,
     *   probe_cidrs: list<string>, probe_ports: list<int>, probe_timeout_ms: int, template_id: int, glpi_groups: list<int>, glpi_users: list<int>, itil_category: int, nms_group: int}
     */
    public static function hygieneConfig(): array
    {
        $list = static fn (string $key): array => array_values((array) (json_decode(self::get($key), true) ?: []));
        return [
            'enabled'          => self::get('hygiene_enabled') === '1',
            'frequency_days'   => max(1, (int) self::get('hygiene_frequency_days')),
            'unmanaged_values' => array_map('strval', $list('hygiene_unmanaged_availabilities')),
            'agent_stale_days' => max(1, (int) self::get('hygiene_agent_stale_days')),
            'mist_stale_days'  => max(1, (int) self::get('hygiene_mist_stale_days')),
            'probe_cidrs'      => array_map('strval', $list('hygiene_probe_cidrs')),
            'probe_ports'      => array_map('intval', $list('hygiene_probe_tcp_ports')),
            'probe_timeout_ms' => max(100, (int) self::get('hygiene_probe_timeout_ms')),
            'template_id'      => (int) self::get('hygiene_ticket_template'),
            'glpi_groups'      => array_map('intval', $list('hygiene_glpi_groups')),
            'glpi_users'       => array_map('intval', $list('hygiene_glpi_users')),
            'itil_category'    => max(0, (int) self::get('hygiene_itil_category')),
            'nms_group'        => (int) self::get('hygiene_nms_group'),
        ];
    }

    /**
     * A storm must survive the gap between two polls: every alarm of one poll shares
     * one timestamp, so a window shorter than the poll interval ends the storm at
     * every run (final review, 2026-09-30).
     */
    public static function stormIdleMinutes(int $window): int
    {
        return max($window, self::ALARM_SYNC_MINUTES + 1);
    }

    /** @return array{ticket_severities: list<int>, ticket_source: string, relink_window_hours: int, storm_threshold: int, storm_window_minutes: int, storm_idle_minutes: int, service_user: int, template_id: int, title_pattern: string, content_pattern: string, entity_default: int, use_ipnetwork: bool} */
    public static function ticketingConfig(): array
    {
        $sev = json_decode(self::get('ticket_severities'), true);
        return [
            'ticket_severities'    => is_array($sev) ? array_values(array_map('intval', $sev)) : [1],
            'ticket_source'        => in_array(self::get('ticket_source'), self::TICKET_SOURCES, true) ? self::get('ticket_source') : self::TICKET_SOURCES[0],
            'relink_window_hours'  => (int) self::get('relink_window_hours'),
            'storm_threshold'      => (int) self::get('storm_threshold'),
            'storm_window_minutes' => max(1, (int) self::get('storm_window_minutes')),
            'storm_idle_minutes'   => self::stormIdleMinutes(max(1, (int) self::get('storm_window_minutes'))),
            'service_user'         => (int) self::get('service_user'),
            'template_id'          => (int) self::get('ticket_template'),
            'title_pattern'        => self::get('ticket_title_pattern'),
            'content_pattern'      => self::get('ticket_content_pattern'),
            'entity_default'       => (int) self::get('ticket_entity_default'),
            'use_ipnetwork'        => self::get('ticket_use_ipnetwork_entity') === '1',
            'outbound_max_attempts' => max(1, (int) self::get('outbound_max_attempts')),
            'production_addressings' => array_values(array_map('intval', (array) (json_decode(self::get('production_addressings'), true) ?: []))),
        ] + self::filterSettings(self::get('ticket_filter_mode'), self::get('ticket_filter_event_types'), self::get('ticket_filter_keywords'));
    }

    /** @return array{filter_mode: string, filter_event_types: list<string>, filter_keywords: list<string>} */
    public static function filterSettings(string $mode, string $types_json, string $keywords): array
    {
        $types = json_decode($types_json, true);
        return [
            'filter_mode'        => in_array($mode, self::FILTER_MODES, true) ? $mode : self::FILTER_MODES[0],
            'filter_event_types' => is_array($types) ? array_values(array_map('strval', $types)) : [],
            'filter_keywords'    => self::lines($keywords),
        ];
    }

    /** @return list<string> trimmed, non-empty, unique lines */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (explode("\n", self::normaliseLines($text)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[$line] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * @param string[] $offered
     * @return list<string>
     */
    public static function filterItemtypes(string $json, array $offered): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_intersect(array_filter($decoded, 'is_string'), $offered));
    }

    /** @return list<int> GLPI states counted by the Coverage page; [] = every state (D28). */
    public static function coverageStates(): array
    {
        return array_values(array_map('intval', (array) (json_decode(self::get('coverage_states'), true) ?: [])));
    }

    /** @return list<string> configured asset itemtypes still on offer */
    public static function assetItemtypes(): array
    {
        return self::filterItemtypes(self::get('asset_itemtypes'), array_keys(self::offeredItemtypes()));
    }

    /** @return array<string, string> itemtype => label (base types labelled by class name) */
    public static function offeredItemtypes(): array
    {
        $out = [];
        foreach (self::BASE_ASSET_ITEMTYPES as $itemtype) {
            $out[$itemtype] = $itemtype;
        }
        return $out + CustomAssets::active();
    }
}
