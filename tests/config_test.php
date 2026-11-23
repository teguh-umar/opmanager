<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Config.php';
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Hygiene/Cidr.php';

use GlpiPlugin\Opmanager\Config;
use GlpiPlugin\Opmanager\RightDefinition;

// --- single source of truth for match modes (integrasipaman v2.6.0 lesson) ---
check('five match modes, manual included', Config::MATCH_MODES === ['ip_only', 'name_only', 'hybrid', 'hybrid_name', 'manual']);
check('default match mode is a valid mode', in_array(Config::DEFAULTS['match_mode'], Config::MATCH_MODES, true));
check('dropdown labels cover exactly MATCH_MODES', array_keys(Config::matchModeLabels()) === Config::MATCH_MODES);
check('secured keys all have defaults', array_diff(Config::SECURED, array_keys(Config::DEFAULTS)) === []);

$allowed = ['Computer', 'NetworkEquipment', 'Glpi\\CustomAsset\\SecurityAsset'];
$base = [
    'opm_url'               => 'https://opm.example.com:8061/',
    'opm_verify_ssl'        => '1',
    'match_mode'            => 'hybrid_name',
    'asset_itemtypes'       => ['Computer', 'Glpi\\CustomAsset\\SecurityAsset', 'Evil'],
    'stale_warning_minutes' => '30',
];

// --- api key handling (Review Focus 2) ---
$r = Config::sanitize($base + ['opm_api_key' => ''], $allowed);
check('blank api key keeps stored key', !array_key_exists('opm_api_key', $r['values']));
$r = Config::sanitize($base + ['opm_api_key' => '  abc123  '], $allowed);
check('new api key is stored trimmed', ($r['values']['opm_api_key'] ?? null) === 'abc123');
$r = Config::sanitize($base + ['opm_api_key' => 'abc', 'opm_api_key_clear' => '1'], $allowed);
check('clear checkbox wipes the key', ($r['values']['opm_api_key'] ?? null) === '');

// --- url ---
$r = Config::sanitize($base, $allowed);
check('trailing slash trimmed from url', $r['values']['opm_url'] === 'https://opm.example.com:8061');
check('valid form has no errors', $r['errors'] === [], implode('; ', $r['errors']));
$r = Config::sanitize(['opm_url' => 'ftp://x'] + $base, $allowed);
check('non-http url rejected and not stored', !array_key_exists('opm_url', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitize(['opm_url' => ''] + $base, $allowed);
check('empty url allowed (not configured yet)', $r['values']['opm_url'] === '');

// --- mode, itemtypes, ssl, stale ---
$r = Config::sanitize(['match_mode' => 'hybrid_magic'] + $base, $allowed);
check('unknown mode rejected and not stored', !array_key_exists('match_mode', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitize(['match_mode' => 'manual'] + $base, $allowed);
check('manual mode accepted', $r['values']['match_mode'] === 'manual');
$r = Config::sanitize($base, $allowed);
check('only offered itemtypes stored', json_decode($r['values']['asset_itemtypes'], true) === ['Computer', 'Glpi\\CustomAsset\\SecurityAsset']);
$b2 = $base;
unset($b2['opm_verify_ssl']);
$r = Config::sanitize($b2, $allowed);
check('unchecked ssl box stores 0', $r['values']['opm_verify_ssl'] === '0');
$r = Config::sanitize(['stale_warning_minutes' => '2'] + $base, $allowed);
check('stale below 5 rejected', !array_key_exists('stale_warning_minutes', $r['values']) && count($r['errors']) === 1);

// --- itemtype filtering never needs the class to exist (hub §5) ---
check('bad json gives no itemtypes', Config::filterItemtypes('not json', $allowed) === []);
check('custom asset string kept without class_exists', Config::filterItemtypes('["Glpi\\\\CustomAsset\\\\SecurityAsset","Gone"]', $allowed) === ['Glpi\\CustomAsset\\SecurityAsset']);
check('Config.php never calls class_exists', !str_contains((string) file_get_contents(OPM_ROOT . '/src/Config.php'), 'class_exists'));

// Final review Important 4: the stale warning must not fire between two normal syncs.
check('stale default exceeds the device sync interval', (int) Config::DEFAULTS['stale_warning_minutes'] > Config::DEVICE_SYNC_MINUTES);
check('install registers DeviceSync from the same interval constant', str_contains((string) file_get_contents(OPM_ROOT . '/install/install.php'), 'Config::DEVICE_SYNC_MINUTES'));

// --- rights ---
check('three rights', RightDefinition::names() === ['plugin_opmanager_config', 'plugin_opmanager_monitoring', 'plugin_opmanager_hygiene']);
check('config right is READ|UPDATE', RightDefinition::ALL[RightDefinition::CONFIG] === (READ | UPDATE));
check('monitoring right is READ only', RightDefinition::ALL[RightDefinition::MONITORING] === READ);
check('matrix columns match masks', array_keys(RightDefinition::rightsMatrix(RightDefinition::MONITORING)) === [READ]);

// --- ticketing (Plan 2) ---
check('ticketable severities exclude Clear', array_keys(Config::SEVERITIES) === [1, 2, 3, 4]);
check('default ticket severity is Critical only (D11/D19)', json_decode(Config::DEFAULTS['ticket_severities'], true) === [1]);
check('alarm sync faster than device sync', Config::ALARM_SYNC_MINUTES < Config::DEVICE_SYNC_MINUTES);

$t = ['ticket_severities' => ['1', '2', '9'], 'ticket_template' => '3', 'ticket_title_pattern' => ' [OPM-{alarm_key}] {message} ',
      'ticket_content_pattern' => '{message}', 'service_user' => '42', 'ticket_entity_default' => '0',
      'ticket_use_ipnetwork_entity' => '1', 'relink_window_hours' => '24', 'storm_threshold' => '10', 'storm_window_minutes' => '5', 'outbound_max_attempts' => '10'];
$r = Config::sanitizeTicketing($t);
check('ticketing form valid', $r['errors'] === [], implode('; ', $r['errors']));
check('unknown severity dropped', json_decode($r['values']['ticket_severities'], true) === [1, 2]);
check('title trimmed', $r['values']['ticket_title_pattern'] === '[OPM-{alarm_key}] {message}');
check('ids stored as ints', $r['values']['service_user'] === '42' && $r['values']['ticket_template'] === '3');
$r = Config::sanitizeTicketing(['ticket_title_pattern' => '   '] + $t);
check('empty title rejected', !array_key_exists('ticket_title_pattern', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitizeTicketing(['storm_window_minutes' => '0'] + $t);
check('storm window 0 rejected', !array_key_exists('storm_window_minutes', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitizeTicketing(['storm_threshold' => '0'] + $t);
check('storm threshold 0 allowed (brake off)', $r['values']['storm_threshold'] === '0');
$r = Config::sanitizeTicketing(['ticket_severities' => []] + $t);
check('no severity = no tickets, allowed', $r['values']['ticket_severities'] === '[]');
$b = $t;
unset($b['ticket_use_ipnetwork_entity']);
check('unchecked ipnetwork box stores 0', Config::sanitizeTicketing($b)['values']['ticket_use_ipnetwork_entity'] === '0');

check('storm idle window never shorter than one poll', Config::stormIdleMinutes(5) === Config::ALARM_SYNC_MINUTES + 1 && Config::stormIdleMinutes(30) === 30);

// Production-only tickets (user decision 2026-09-30)
check('no production range selected by default', Config::DEFAULTS['production_addressings'] === '[]');
$r = Config::sanitizeTicketing(['production_addressings' => ['3', '7', 'x', '-2']] + $t);
check('production ranges stored as positive ids', json_decode($r['values']['production_addressings'], true) === [3, 7]);

// v0.3.0: alarm filter (user decision 2026-10-01): event types + message keywords, exclude/include.
require OPM_ROOT . '/src/Ticketing/AlarmFilter.php';
check('filter modes shared with AlarmFilter', Config::FILTER_MODES === \GlpiPlugin\Opmanager\Ticketing\AlarmFilter::MODES);
check('filter off by default', Config::DEFAULTS['ticket_filter_mode'] === 'exclude' && Config::DEFAULTS['ticket_filter_event_types'] === '[]' && Config::DEFAULTS['ticket_filter_keywords'] === '');
$r = Config::sanitizeTicketing(['ticket_filter_mode' => 'include', 'ticket_filter_event_types' => [' CPU Utilization ', '', 'CPU Utilization', 'Device Down'],
    'ticket_filter_keywords' => "threshold\r\n\r\n  utilization  \nthreshold"] + $t);
check('filter form valid', $r['errors'] === [], implode('; ', $r['errors']));
check('filter mode stored', $r['values']['ticket_filter_mode'] === 'include');
check('event types trimmed, blank and duplicates dropped', json_decode($r['values']['ticket_filter_event_types'], true) === ['CPU Utilization', 'Device Down']);
check('keywords one per line, trimmed, deduplicated', $r['values']['ticket_filter_keywords'] === "threshold\nutilization");
$r = Config::sanitizeTicketing(['ticket_filter_mode' => 'drop_all'] + $t);
check('unknown filter mode falls back to exclude', $r['values']['ticket_filter_mode'] === 'exclude');
check('no filter posted = filter off', $r['values']['ticket_filter_event_types'] === '[]' && $r['values']['ticket_filter_keywords'] === '');
$r = Config::sanitizeTicketing(['ticket_filter_keywords' => str_repeat('x', 2001)] + $t);
check('oversized keyword list rejected', !array_key_exists('ticket_filter_keywords', $r['values']) && count($r['errors']) === 1);
check('filter settings decoded for the engine', Config::filterSettings('include', '["Device Down"]', "a\nb") === ['filter_mode' => 'include', 'filter_event_types' => ['Device Down'], 'filter_keywords' => ['a', 'b']]);
check('broken stored filter decodes to off', Config::filterSettings('x', 'not json', '') === ['filter_mode' => 'exclude', 'filter_event_types' => [], 'filter_keywords' => []]);

// v0.2.1: OpManager category in the default description, so GLPI ticket rules can map it (user decision, option A).
check('default description carries the OpManager category line', str_contains(Config::DEFAULT_CONTENT_PATTERN, "Category: {category}\n"));
check('old default still known for the upgrade', Config::PREVIOUS_CONTENT_PATTERNS !== [] && !in_array(Config::DEFAULT_CONTENT_PATTERN, Config::PREVIOUS_CONTENT_PATTERNS, true));
check('upgrade replaces an untouched old default', Config::upgradedContentPattern(Config::PREVIOUS_CONTENT_PATTERNS[0]) === Config::DEFAULT_CONTENT_PATTERN);
check('upgrade keeps a customised pattern', Config::upgradedContentPattern("Dear Rekan ORG\n{message}") === null);
check('installer runs the pattern upgrade', str_contains((string) file_get_contents(OPM_ROOT . '/install/install.php'), 'Config::upgradedContentPattern('));

// v0.3.0: a textarea posts \r\n; stored patterns and the upgrade compare must not care.
$crlf = str_replace("\n", "\r\n", Config::PREVIOUS_CONTENT_PATTERNS[0]);
check('upgrade recognises an old default saved with CRLF', Config::upgradedContentPattern($crlf) === Config::DEFAULT_CONTENT_PATTERN);
$r = Config::sanitizeTicketing(['ticket_content_pattern' => "Line 1\r\nLine 2\rLine 3"] + $t);
check('patterns stored with \n only', $r['values']['ticket_content_pattern'] === "Line 1\nLine 2\nLine 3");
check('normaliseLines helper', Config::normaliseLines("a\r\nb\rc\n") === "a\nb\nc\n");

// v0.3.0 webhook token
check('webhook token is secured', in_array('webhook_token', Config::SECURED, true) && (Config::DEFAULTS['webhook_token'] ?? null) === '');
$a = Config::newWebhookToken();
$b = Config::newWebhookToken();
check('new token differs and is 48 hex', $a !== $b && preg_match('/^[0-9a-f]{48}$/', $a) === 1);
check('webhook header and path constants', Config::WEBHOOK_HEADER === 'X-OPM-Token' && Config::WEBHOOK_PATH === '/plugins/opmanager/front/webhook.php');

// Plan 3: outbound retries (D13)
check('outbound retries default 10', Config::DEFAULTS['outbound_max_attempts'] === '10');
$r = Config::sanitizeTicketing(['outbound_max_attempts' => '0'] + $t);
check('outbound retries 0 rejected', !array_key_exists('outbound_max_attempts', $r['values']) && count($r['errors']) === 1);
$r = Config::sanitizeTicketing(['outbound_max_attempts' => '7'] + $t);
check('outbound retries stored', $r['values']['outbound_max_attempts'] === '7');

// Plan 4: GLPI states counted by the Coverage page (empty = all)
check('coverage states default to all', Config::DEFAULTS['coverage_states'] === '[]');
$r = Config::sanitize(['coverage_states' => ['3', 'x', '-1', '7', '3']] + $base, $allowed);
check('coverage states stored as unique positive ids', json_decode($r['values']['coverage_states'], true) === [3, 7]);
$r = Config::sanitize($base, $allowed);
check('no coverage state posted = all', $r['values']['coverage_states'] === '[]');

// v1.1.0 (D32): ticket source.
check('ticket source defaults to webhook', Config::DEFAULTS['ticket_source'] === 'webhook' && Config::TICKET_SOURCES[0] === 'webhook');
check('ticket source rules accepted', Config::sanitizeTicketing(['ticket_source' => 'rules'] + $t)['values']['ticket_source'] === 'rules');
check('unknown ticket source falls back to webhook', Config::sanitizeTicketing(['ticket_source' => 'x'] + $t)['values']['ticket_source'] === 'webhook');


// --- v1.3.0 (D34) hygiene defaults, spec §8.4 ---
foreach (['hygiene_enabled', 'hygiene_frequency_days', 'hygiene_unmanaged_availabilities', 'hygiene_agent_stale_days', 'hygiene_mist_stale_days', 'hygiene_probe_cidrs', 'hygiene_probe_tcp_ports', 'hygiene_probe_timeout_ms', 'hygiene_ticket_template', 'hygiene_glpi_groups', 'hygiene_glpi_users', 'hygiene_itil_category', 'hygiene_nms_group', 'last_hygiene_run', 'last_hygiene_ticket_run', 'last_hygiene_error'] as $k) {
    check("default exists: {$k}", array_key_exists($k, Config::DEFAULTS));
}
check('unmanaged availabilities default', json_decode(Config::DEFAULTS['hygiene_unmanaged_availabilities'] ?? '', true) === ['UnManaged', 'Device Not Monitored.']);
check('hygiene tickets off by default', (Config::DEFAULTS['hygiene_enabled'] ?? null) === '0');
check('hygiene weekly by default', (Config::DEFAULTS['hygiene_frequency_days'] ?? null) === '7');
check('probe off by default (no CIDR)', (Config::DEFAULTS['hygiene_probe_cidrs'] ?? null) === '[]');
check('hygiene cron is daily', Config::HYGIENE_CRON_MINUTES === 1440);


// --- v1.3.0 (D34) hygiene settings ---
$h = [
    'hygiene_enabled' => '1', 'hygiene_frequency_days' => '7', 'hygiene_unmanaged_availabilities' => "UnManaged\r\nDevice Not Monitored.\r\n",
    'hygiene_agent_stale_days' => '7', 'hygiene_mist_stale_days' => '14', 'hygiene_probe_cidrs' => "10.20.0.0/16\n192.168.50.0/24",
    'hygiene_probe_tcp_ports' => '22, 443,22', 'hygiene_probe_timeout_ms' => '1000', 'hygiene_ticket_template' => '3',
    'hygiene_glpi_groups' => ['11', '12'], 'hygiene_glpi_users' => ['7', '0', '9', '7'], 'hygiene_itil_category' => '31', 'hygiene_nms_group' => '20',
];
$r = Config::sanitizeHygiene($h);
check('hygiene: valid post has no error', $r['errors'] === [], implode('; ', $r['errors']));
check('hygiene: enabled stored', ($r['values']['hygiene_enabled'] ?? null) === '1');
check('hygiene: CRLF lines normalised to a JSON list', ($r['values']['hygiene_unmanaged_availabilities'] ?? null) === '["UnManaged","Device Not Monitored."]');
check('hygiene: cidrs stored as JSON', ($r['values']['hygiene_probe_cidrs'] ?? null) === '["10.20.0.0/16","192.168.50.0/24"]');
check('hygiene: ports unique ints', ($r['values']['hygiene_probe_tcp_ports'] ?? null) === '[22,443]');
check('hygiene: groups as int JSON', ($r['values']['hygiene_glpi_groups'] ?? null) === '[11,12]' && ($r['values']['hygiene_nms_group'] ?? null) === '20');
check('hygiene: asset technicians as int JSON (rc4)', ($r['values']['hygiene_glpi_users'] ?? null) === '[7,9]');
check('hygiene: ITIL category stored (rc5)', ($r['values']['hygiene_itil_category'] ?? null) === '31');
check('hygiene: ITIL category default none', (Config::DEFAULTS['hygiene_itil_category'] ?? null) === '0');
check('hygiene: mist stale stored', ($r['values']['hygiene_mist_stale_days'] ?? null) === '14');
$r = Config::sanitizeHygiene(['hygiene_frequency_days' => '0'] + $h);
check('hygiene: frequency 0 refused, value kept', $r['errors'] !== [] && !array_key_exists('hygiene_frequency_days', $r['values']));
$r = Config::sanitizeHygiene(['hygiene_probe_cidrs' => "10.0.0.0/33"] + $h);
check('hygiene: bad CIDR named in the error', $r['errors'] !== [] && str_contains(implode(' ', $r['errors']), '10.0.0.0/33') && !array_key_exists('hygiene_probe_cidrs', $r['values']));
$r = Config::sanitizeHygiene(['hygiene_probe_tcp_ports' => '70000'] + $h);
check('hygiene: port out of range refused', $r['errors'] !== [] && !array_key_exists('hygiene_probe_tcp_ports', $r['values']));
$r = Config::sanitizeHygiene(['hygiene_probe_tcp_ports' => '1,2,3,4,5,6,7,8,9,10,11'] + $h);
check('hygiene: more than 10 ports refused', $r['errors'] !== []);
$r = Config::sanitizeHygiene(['hygiene_unmanaged_availabilities' => "  \n "] + $h);
check('hygiene: empty unmanaged list refused', $r['errors'] !== [] && !array_key_exists('hygiene_unmanaged_availabilities', $r['values']));
$r = Config::sanitizeHygiene(['hygiene_glpi_groups' => []] + $h);
check('hygiene: observer groups are optional (rc7)', $r['errors'] === [] && ($r['values']['hygiene_enabled'] ?? null) === '1');
$r = Config::sanitizeHygiene(['hygiene_glpi_users' => []] + $h);
check('hygiene: enabled without an asset technician is an error and stays off (rc7)', $r['errors'] !== [] && ($r['values']['hygiene_enabled'] ?? null) === '0');
$r = Config::sanitizeHygiene(['hygiene_nms_group' => '0'] + $h);
check('hygiene: enabled without NMS group is an error and stays off', $r['errors'] !== [] && ($r['values']['hygiene_enabled'] ?? null) === '0');
$r = Config::sanitizeHygiene(['hygiene_enabled' => '0', 'hygiene_glpi_groups' => [], 'hygiene_glpi_users' => [], 'hygiene_nms_group' => '0'] + $h);
check('hygiene: disabled without groups is fine', $r['errors'] === []);
$r = Config::sanitizeHygiene(['hygiene_probe_timeout_ms' => '50'] + $h);
check('hygiene: timeout below 100 ms refused', $r['errors'] !== []);
$r = Config::sanitizeHygiene(['hygiene_enabled' => '0'] + array_diff_key($h, ['hygiene_glpi_users' => 1]));
check('hygiene: no technician posted stores an empty list', ($r['values']['hygiene_glpi_users'] ?? null) === '[]' && $r['errors'] === []);
check('hygiene: technicians default empty', (Config::DEFAULTS['hygiene_glpi_users'] ?? null) === '[]');

done();
