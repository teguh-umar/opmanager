<?php

namespace GlpiPlugin\Opmanager;

/**
 * OpManager's section in the shared "PGN Suite" tab on Setup > General (hub §5).
 * The same form renders on front/config.php for admins without core `config` READ.
 */
final class SuiteConfig
{
    public const SECTION = 'opmanager';

    /** Hidden field marking a POST that came from the PGN Suite tab. */
    public const FROM_SUITE_FIELD = '_from_pgnsuite';

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

        echo '<fieldset class="mb-3"><legend class="fs-4">' . __s('OpManager connection', 'opmanager') . '</legend>';
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
        echo '</fieldset>';

        echo '<fieldset class="mb-3"><legend class="fs-4">' . __s('Asset matching', 'opmanager') . '</legend>';
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
        echo '</fieldset>';

        $last  = Config::get('last_device_sync');
        $error = Config::get('last_device_sync_error');
        echo '<div class="mb-3">' . __s('Last successful device sync', 'opmanager') . ': ' . htmlescape($last !== '' ? $last : __('never', 'opmanager')) . '</div>';
        if ($error !== '') {
            echo '<div class="alert alert-warning">' . __s('Last device sync error', 'opmanager') . ': ' . htmlescape($error) . '</div>';
        }

        if ($canedit) {
            echo '<div class="mt-2">' . \Html::submit(_sx('button', 'Save'), ['name' => 'update']) . '</div>';
        }
        \Html::closeForm();
    }

    private static function textRow(string $id, string $name, string $label, string $value, string $type, string $disabled): string
    {
        return '<div class="mb-2"><label class="form-label" for="' . htmlescape($id) . '">' . htmlescape($label) . '</label>'
            . '<input type="' . $type . '" class="form-control" id="' . htmlescape($id) . '" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '"' . $disabled . '></div>';
    }
}
