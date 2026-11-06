<?php

namespace GlpiPlugin\Opmanager\Hygiene;

/**
 * Pure: the NMS hygiene condition of one OpManager device (spec §2, D34).
 * GLPI is the asset source of truth; only "monitored but not in GLPI" and
 * "unmanaged and not proven alive" lead to a ticket.
 */
final class Classifier
{
    public const GLPI_MISSING    = 'glpi_missing';
    public const GLPI_REVIEW     = 'glpi_review';
    public const NMS_DEAD        = 'nms_dead';
    public const NMS_UNKNOWN     = 'nms_unknown';
    public const UNMANAGED_ALIVE = 'unmanaged_alive';
    public const OK              = 'ok';
    public const EXCEPTED        = 'excepted';
    public const NONE            = '';

    /** Ticketed states and the ticket flow that carries them. */
    public const FLOW_OF = [
        self::GLPI_MISSING => 'glpi',
        self::GLPI_REVIEW  => 'glpi',
        self::NMS_DEAD     => 'nms',
        self::NMS_UNKNOWN  => 'nms',
    ];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::GLPI_MISSING    => __('Monitored, not in GLPI', 'opmanager'),
            self::GLPI_REVIEW     => __('Monitored, needs manual pairing', 'opmanager'),
            self::NMS_DEAD        => __('Unmanaged, dead: remove from OpManager', 'opmanager'),
            self::NMS_UNKNOWN     => __('Unmanaged, liveness unknown: check manually', 'opmanager'),
            self::UNMANAGED_ALIVE => __('Unmanaged, still alive', 'opmanager'),
            self::OK              => __('Monitored and in GLPI', 'opmanager'),
            self::EXCEPTED        => __('Excluded from hygiene tickets', 'opmanager'),
            self::NONE            => __('Not classified', 'opmanager'),
        ];
    }

    /** @param list<string> $values availabilities that mean "not monitored" */
    public static function isUnmanaged(string $availability, array $values): bool
    {
        $availability = trim($availability);
        if ($availability === '') {
            return false;
        }
        foreach ($values as $value) {
            if (strcasecmp($availability, trim((string) $value)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{availability: string, match_state: string, last_seen: ?string} $device
     * @param string       $liveness         'alive' | 'dead' | 'unknown' | '' (not checked)
     * @param string       $stale_before     a device last seen before this left OpManager
     * @param list<string> $unmanaged_values
     */
    public static function classify(array $device, string $liveness, bool $excepted, string $stale_before, array $unmanaged_values): string
    {
        $seen = (string) ($device['last_seen'] ?? '');
        if (trim((string) $device['availability']) === '' || $seen === '' || $seen < $stale_before) {
            return self::NONE;
        }
        if ($excepted) {
            return self::EXCEPTED;
        }
        if (self::isUnmanaged((string) $device['availability'], $unmanaged_values)) {
            return match ($liveness) {
                'alive' => self::UNMANAGED_ALIVE,
                'dead'  => self::NMS_DEAD,
                default => self::NMS_UNKNOWN,
            };
        }
        return match ((string) $device['match_state']) {
            'matched' => self::OK,
            'review'  => self::GLPI_REVIEW,
            default   => self::GLPI_MISSING,
        };
    }
}
