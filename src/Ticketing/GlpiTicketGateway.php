<?php

namespace GlpiPlugin\Opmanager\Ticketing;

/**
 * TicketGateway on GLPI 11 core objects - every write through ->add(), never raw
 * SQL on core tables (hub §5). Runs under CLI cron with no logged-in user, so every
 * followup/solution carries the configured service user explicitly.
 */
final class GlpiTicketGateway implements TicketGateway
{
    private const SOLVED = 5;
    private const CLOSED = 6;

    /** @param array{service_user: int, template_id: int, title_pattern: string, content_pattern: string, entity_default: int, use_ipnetwork: bool} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function status(int $tickets_id): ?int
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id) || !empty($ticket->fields['is_deleted'])) {
            return null;
        }
        return (int) $ticket->fields['status'];
    }

    public function create(array $alarm, array $asset): int
    {
        $entity                     = $this->entityFor($alarm, $asset);
        [$template_id, $predefined] = $this->template($entity);
        $input = TicketBuilder::build($alarm, $asset, $entity, $predefined, $template_id, $this->cfg);

        $ticket = new \Ticket();
        $id     = $ticket->add($input);
        return $id ? (int) $id : 0;
    }

    public function createStorm(array $alarm, string $now): int
    {
        $entity                     = (int) $this->cfg['entity_default'];
        [$template_id, $predefined] = $this->template($entity);
        $cfg   = ['title_pattern' => Messages::stormTitle($now), 'content_pattern' => Messages::stormTitle($now)] + $this->cfg;
        $input = TicketBuilder::build($alarm, ['itemtype' => '', 'items_id' => 0], $entity, $predefined, $template_id, $cfg);

        $ticket = new \Ticket();
        $id     = $ticket->add($input);
        return $id ? (int) $id : 0;
    }

    public function followup(int $tickets_id, string $text): void
    {
        $this->withoutAutoAssign(function () use ($tickets_id, $text) {
            (new \ITILFollowup())->add([
                'itemtype'   => \Ticket::class,
                'items_id'   => $tickets_id,
                'content'    => self::html($text),
                'is_private' => 0,
                'users_id'   => (int) $this->cfg['service_user'],
                '_no_reopen' => 1,
            ]);
        });
    }

    public function reopen(int $tickets_id, string $text): void
    {
        $this->withoutAutoAssign(function () use ($tickets_id, $text) {
            (new \ITILFollowup())->add([
                'itemtype'   => \Ticket::class,
                'items_id'   => $tickets_id,
                'content'    => self::html($text),
                'is_private' => 0,
                'users_id'   => (int) $this->cfg['service_user'],
                'add_reopen' => 1,
            ]);
        });
    }

    public function solve(int $tickets_id, string $text): void
    {
        $status = $this->status($tickets_id);
        if ($status === null || in_array($status, [5, 6], true)) {
            return;
        }
        (new \ITILSolution())->add([
            'itemtype' => \Ticket::class,
            'items_id' => $tickets_id,
            'content'  => self::html($text),
            'users_id' => (int) $this->cfg['service_user'],
        ]);
    }

    public function link(int $new_tickets_id, int $old_tickets_id): void
    {
        (new \Ticket_Ticket())->add([
            'tickets_id_1' => $new_tickets_id,
            'tickets_id_2' => $old_tickets_id,
            'link'         => \Ticket_Ticket::LINK_TO,
        ]);
    }

    public function closedSince(int $tickets_id, string $since): bool
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }
        if (!in_array((int) $ticket->fields['status'], [self::SOLVED, self::CLOSED], true)) {
            return false;
        }
        $when = max((string) ($ticket->fields['solvedate'] ?? ''), (string) ($ticket->fields['closedate'] ?? ''));
        return $when !== '' && $when >= $since;
    }

    public static function html(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES));
    }

    private function entityFor(array $alarm, array $asset): int
    {
        global $DB;

        $asset_entity = null;
        if ($asset['items_id'] > 0 && $asset['itemtype'] !== '') {
            $item = getItemForItemtype($asset['itemtype']);
            if ($item instanceof \CommonDBTM && $item->getFromDB($asset['items_id']) && isset($item->fields['entities_id'])) {
                $asset_entity = (int) $item->fields['entities_id'];
            }
        }

        $networks = [];
        if ($asset_entity === null && $this->cfg['use_ipnetwork']) {
            foreach ($DB->request([
                'SELECT' => ['entities_id', 'address', 'netmask'],
                'FROM'   => 'glpi_ipnetworks',
                'WHERE'  => ['version' => 4],
            ]) as $row) {
                $networks[] = $row;
            }
        }

        return EntityResolver::resolve($asset_entity, (string) $alarm['ip_address'], $networks, (bool) $this->cfg['use_ipnetwork'], (int) $this->cfg['entity_default']);
    }

    /** @return array{0: int, 1: array<string, mixed>} template id and its predefined fields */
    private function template(int $entity): array
    {
        if ((int) $this->cfg['template_id'] > 0) {
            $tt = new \TicketTemplate();
            if ($tt->getFromDBWithData((int) $this->cfg['template_id'])) {
                return [(int) $tt->getID(), (array) $tt->predefined];
            }
        }
        $tt = (new \Ticket())->getITILTemplateToUse(0, \Ticket::INCIDENT_TYPE, 0, $entity);
        return [(int) $tt->getID(), (array) $tt->predefined];
    }

    /**
     * ITILFollowup::prepareInputForAdd() assigns the author as technician when the
     * global "add me as technician on followup" option is on - that would assign
     * the service account to every alarm ticket. Switch it off around our own add.
     */
    private function withoutAutoAssign(callable $fn): void
    {
        $had   = array_key_exists('glpiset_followup_tech', $_SESSION);
        $saved = $_SESSION['glpiset_followup_tech'] ?? null;
        $_SESSION['glpiset_followup_tech'] = 0;
        try {
            $fn();
        } finally {
            if ($had) {
                $_SESSION['glpiset_followup_tech'] = $saved;
            } else {
                unset($_SESSION['glpiset_followup_tech']);
            }
        }
    }
}
