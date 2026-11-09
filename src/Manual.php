<?php

namespace GlpiPlugin\Opmanager;

/**
 * The user manual, linked from inside the plugin (suite rule 2026-11-09, hub §4; v1.3.0-rc4).
 * Source: docs/manual/opmanager.md at the suite root (written by Copilot). Before a build it is
 * copied to docs/manual.md here, the one docs file that ships in the zip. It is rendered with
 * GLPI's bundled league/commonmark. Raw HTML is escaped and unsafe links are dropped, so the
 * manual cannot inject markup or script into a GLPI page. Reference: integrasipaman v4.15.0-rc1.
 */
final class Manual
{
    /** league/commonmark settings: no raw HTML, no javascript:/data: links. */
    public const CONVERTER_OPTIONS = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    public static function path(): string
    {
        return dirname(__DIR__) . '/docs/manual.md';
    }

    /** The manual's text, or null when the file is not installed. */
    public static function read(): ?string
    {
        $path = self::path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $text = file_get_contents($path);
        return $text === false ? null : $text;
    }

    /** The version the manual documents ("Versi didokumentasikan: 1.3.0" or "Version documented: 1.3.0"). */
    public static function versionOf(string $markdown): string
    {
        return preg_match('/(?:Versi didokumentasikan|Version documented)\s*:\s*v?(\d+(?:\.\d+){1,2})/i', $markdown, $m) === 1 ? $m[1] : '';
    }

    /** True when the manual documents an older release than the installed plugin (rc suffix ignored). */
    public static function lags(string $manual_version, string $plugin_version): bool
    {
        if ($manual_version === '') {
            return false;
        }
        return version_compare($manual_version, (string) preg_replace('/-.*$/', '', $plugin_version), '<');
    }

    /** Markdown to safe HTML. Without commonmark (never on GLPI 11) the text is shown escaped. */
    public static function render(string $markdown): string
    {
        if (class_exists(\League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            return (string) (new \League\CommonMark\GithubFlavoredMarkdownConverter(self::CONVERTER_OPTIONS))->convert($markdown);
        }
        return '<pre class="text-wrap">' . htmlescape($markdown) . '</pre>';
    }

    /** The page body: a notice when missing, the version line or a lag warning, the manual. */
    public static function body(?string $markdown, string $plugin_version): string
    {
        if ($markdown === null) {
            return '<div class="alert alert-info">'
                . htmlescape(__('The user manual is not installed with this copy of the plugin.', 'opmanager'))
                . '</div>';
        }
        $version = self::versionOf($markdown);
        $out     = '';
        if (self::lags($version, $plugin_version)) {
            $out .= '<div class="alert alert-warning">' . htmlescape(sprintf(
                __('This manual documents version %1$s; the installed plugin is %2$s. Some screens may differ.', 'opmanager'),
                $version,
                $plugin_version
            )) . '</div>';
        } elseif ($version !== '') {
            $out .= '<p class="text-muted small">' . htmlescape(sprintf(__('Manual for version %s', 'opmanager'), $version)) . '</p>';
        }
        return $out . '<div class="markdown">' . self::render($markdown) . '</div>';
    }
}
