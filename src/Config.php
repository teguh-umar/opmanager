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
    public const SECURED = ['opm_api_key'];

    public const BASE_ASSET_ITEMTYPES = ['Computer', 'NetworkEquipment'];

    /**
     * DeviceSync frequency. The stale warning default must stay above it, or every
     * Monitoring section warns for part of each cycle (final review, 2026-09-29).
     */
    public const DEVICE_SYNC_MINUTES = 60;

    public const DEFAULTS = [
        'opm_url'                => '',
        'opm_api_key'            => '',
        'opm_verify_ssl'         => '1',
        'match_mode'             => 'hybrid_name',
        'asset_itemtypes'        => '["Computer","NetworkEquipment"]',
        'stale_warning_minutes'  => '90',
        'last_device_sync'       => '',
        'last_device_sync_error' => '',
    ];

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

    public static function setDefaults(): void
    {
        $current = \Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::DEFAULTS, $current);
        if ($missing !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $missing);
        }
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

        return ['values' => $values, 'errors' => $errors];
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
