<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * Pure ticket input (D18). Template predefined fields are the base; the plugin's
 * title/content patterns, entity, asset link and template id are laid on top;
 * urgency and requester only fill what the template leaves open.
 */
final class TicketBuilder
{
    public const TYPE_INCIDENT = 1;

    /** OpManager numericSeverity => GLPI urgency (5 very high .. 1 very low), D19. */
    public const URGENCY = [1 => 5, 2 => 4, 3 => 3, 4 => 4];

    /**
     * @param array<string, mixed>                   $alarm
     * @param array{itemtype: string, items_id: int} $asset
     * @param array<string, mixed>                   $predefined
     * @param array{title_pattern: string, content_pattern: string, service_user: int} $cfg
     * @return array<string, mixed>
     */
    public static function build(array $alarm, array $asset, int $entities_id, array $predefined, int $template_id, array $cfg): array
    {
        $vars  = self::vars($alarm);
        $input = $predefined;

        $input['name']        = self::render($cfg['title_pattern'], $vars, false);
        $input['content']     = self::render($cfg['content_pattern'], $vars, true);
        $input['entities_id'] = $entities_id;
        $input['type']        = $predefined['type'] ?? self::TYPE_INCIDENT;
        if (!isset($predefined['urgency'])) {
            $input['urgency'] = self::URGENCY[(int) $alarm['severity']] ?? 3;
        }
        if ((int) $cfg['service_user'] > 0) {
            if (!isset($predefined['_users_id_requester'])) {
                $input['_users_id_requester'] = (int) $cfg['service_user'];
            }
            $input['users_id_recipient'] = (int) $cfg['service_user'];
        }
        if ($asset['items_id'] > 0 && $asset['itemtype'] !== '') {
            $input['items_id'] = [$asset['itemtype'] => [$asset['items_id']]];
        }
        $input['_tickettemplate'] = $template_id;

        return $input;
    }

    /** @return array<string, string> */
    public static function vars(array $alarm): array
    {
        return [
            '{alarm_key}'  => (string) ($alarm['alarm_key'] ?? ''),
            '{severity}'   => (string) ($alarm['severity_label'] ?? ''),
            '{message}'    => (string) ($alarm['message'] ?? ''),
            '{device}'     => (string) (($alarm['display_name'] ?? '') !== '' ? $alarm['display_name'] : ($alarm['device_name'] ?? '')),
            '{ip}'         => (string) ($alarm['ip_address'] ?? ''),
            '{alarm_type}' => (string) ($alarm['alarm_code'] ?? ''),
            '{time}'       => (string) ($alarm['mod_time'] ?? ''),
        ];
    }

    /** @param array<string, string> $vars */
    public static function render(string $pattern, array $vars, bool $html): string
    {
        $text = strtr($pattern, $vars);
        if ($html) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES));
        }
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 250) : substr($text, 0, 250);
    }
}
