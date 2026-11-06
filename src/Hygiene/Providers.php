<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/**
 * Liveness provider registry (spec §3, user 2026-11-06: not closed to Mist).
 * A provider is callable(array $device, string $now): ?array{verdict, detail}.
 * Other plugins add theirs with $PLUGIN_HOOKS['opmanager_liveness'][<plugin>] =
 * callable | [name => callable]; plain arrays only, no opmanager class to import.
 */
final class Providers
{
    public const HOOK = 'opmanager_liveness';

    public const NAME_MAX = 32;

    /** @return array<string, callable> */
    public static function normalise(array $hook_entries): array
    {
        $out = [];
        foreach ($hook_entries as $plugin => $entry) {
            $named = is_callable($entry) ? [(string) $plugin => $entry] : (is_array($entry) ? $entry : []);
            foreach ($named as $name => $fn) {
                if (is_callable($fn)) {
                    $out[substr((string) $name, 0, self::NAME_MAX)] = $fn;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, callable> $providers
     * @param list<string>            $errors appended to
     * @return list<array{verdict: string, source: string, detail: string}>
     */
    public static function collect(array $device, string $now, array $providers, array &$errors): array
    {
        $out = [];
        foreach ($providers as $name => $fn) {
            try {
                $e = $fn($device, $now);
            } catch (\Throwable $t) {
                $errors[] = 'provider ' . $name . ': ' . $t->getMessage();
                continue;
            }
            if (is_array($e) && in_array($e['verdict'] ?? null, ['alive', 'dead'], true)) {
                $out[] = ['verdict' => $e['verdict'], 'source' => (string) $name, 'detail' => (string) ($e['detail'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * @param array{agent_stale_days: int, mist_stale_days: int, probe_cidrs: list<string>, probe_ports: list<int>, probe_timeout_ms: int} $cfg
     * @return array<string, callable>
     */
    public static function builtIn(array $cfg): array
    {
        return [
            'agent' => new AgentProvider((int) $cfg['agent_stale_days']),
            'mist'  => new MistProvider((int) $cfg['mist_stale_days']),
            'probe' => new ProbeProvider(new TcpProber((int) $cfg['probe_timeout_ms']), $cfg['probe_cidrs'], $cfg['probe_ports']),
        ];
    }

    /** Built-ins first; a hook entry never replaces a built-in name. @return array<string, callable> */
    public static function all(array $cfg): array
    {
        $hooks = $GLOBALS['PLUGIN_HOOKS'][self::HOOK] ?? [];
        return self::builtIn($cfg) + self::normalise(is_array($hooks) ? $hooks : []);
    }
}
