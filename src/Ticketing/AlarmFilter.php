<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Which alarms may open a NEW ticket (user decision 2026-10-01): an alarm matches when its
 * OpManager event type is listed or its message contains a keyword. Mode "exclude" blocks
 * matching alarms, "include" tickets only matching alarms. Empty lists = filter off.
 * Applied on top of the severity and production-range rules; never to alarms that already
 * have a ticket, so their followups and clear/solve keep working.
 */
final class AlarmFilter
{
    public const MODES = ['exclude', 'include'];

    private string $mode;

    /** @var list<string> */
    private array $event_types;

    /** @var list<string> */
    private array $keywords;

    /**
     * @param list<string> $event_types
     * @param list<string> $keywords
     */
    public function __construct(string $mode, array $event_types, array $keywords)
    {
        $this->mode        = in_array($mode, self::MODES, true) ? $mode : 'exclude';
        $this->event_types = self::clean($event_types);
        $this->keywords    = self::clean($keywords);
    }

    /** @param array<string, mixed> $cfg */
    public static function fromConfig(array $cfg): self
    {
        return new self(
            (string) ($cfg['filter_mode'] ?? 'exclude'),
            (array) ($cfg['filter_event_types'] ?? []),
            (array) ($cfg['filter_keywords'] ?? [])
        );
    }

    public function isActive(): bool
    {
        return $this->event_types !== [] || $this->keywords !== [];
    }

    /** @param array<string, mixed> $alarm */
    public function allows(array $alarm): bool
    {
        if (!$this->isActive()) {
            return true;
        }
        return $this->mode === 'include' ? $this->matches($alarm) : !$this->matches($alarm);
    }

    /** @return list<string> event types seen so far, for the Setup picker */
    public static function knownEventTypes(): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_opmanager_alarms')) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT'   => ['event_type'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_plugin_opmanager_alarms',
            'WHERE'    => ['NOT' => ['event_type' => '']],
            'ORDER'    => 'event_type',
        ]) as $row) {
            $out[] = (string) $row['event_type'];
        }
        return $out;
    }

    /** @param array<string, mixed> $alarm */
    private function matches(array $alarm): bool
    {
        if (in_array(strtolower(trim((string) ($alarm['event_type'] ?? ''))), $this->event_types, true)) {
            return true;
        }
        $message = strtolower((string) ($alarm['message'] ?? ''));
        foreach ($this->keywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $values
     * @return list<string> lower-cased, trimmed, non-empty, unique
     */
    private static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $v = strtolower(trim((string) $v));
            if ($v !== '') {
                $out[$v] = true;
            }
        }
        return array_keys($out);
    }
}
