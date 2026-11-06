<?php

namespace GlpiPlugin\Opmanager;

/**
 * Pure plan for which profile rights to write on install/upgrade (hub §4):
 * - `plugin:install --force` re-runs install on every upgrade, so only rights
 *   created in THIS run are seeded; existing values are never re-derived.
 * - A profile holding core `config` UPDATE is the super-admin and gets the full
 *   mask on every run (user directive 2026-09-16). Identified by right, never by name.
 */
final class RightSeeder
{
    /**
     * @param string[]                                                          $new_rights
     * @param array<int, array{interface: string, config: int, computer: int}> $profiles
     * @return array<int, array<string, int>> profile id => rights to write
     */
    public static function plan(array $new_rights, array $profiles): array
    {
        $out = [];
        foreach ($profiles as $id => $profile) {
            if (($profile['config'] & UPDATE) === UPDATE) {
                $out[$id] = RightDefinition::ALL;
                continue;
            }
            if ($profile['interface'] !== 'central' || $new_rights === []) {
                continue;
            }
            $rights = [];
            foreach ($new_rights as $name) {
                $rights[$name] = match ($name) {
                    RightDefinition::CONFIG     => $profile['config'] & (READ | UPDATE),
                    RightDefinition::MONITORING => $profile['computer'] & READ,
                    // UPDATE (exceptions) is granted by hand to the NMS and GLPI team profiles.
                    RightDefinition::HYGIENE    => $profile['computer'] & READ,
                    default                     => 0,
                };
            }
            $out[$id] = $rights;
        }
        return $out;
    }
}
