<?php

namespace GlpiPlugin\Opmanager\Hygiene;

use GlpiPlugin\Opmanager\Ticketing\GlpiTicketGateway;
use GlpiPlugin\Opmanager\Ticketing\TicketBuilder;
use GlpiPlugin\Opmanager\Ticketing\TicketGateway;

/**
 * Hygiene Request tickets on GLPI core objects (hub §5: every write through ->add()).
 * Hygiene tickets carry no alarm link, so the outbound hooks ignore them by design.
 */
final class GlpiHygieneGateway implements HygieneGateway
{
    /** @param array{service_user: int, entity_default: int} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function status(int $tickets_id): ?int
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return null;
        }
        return !empty($ticket->fields['is_deleted']) ? TicketGateway::TRASHED : (int) $ticket->fields['status'];
    }

    public function create(string $title, string $html, int $template_id, array $assign_groups, array $observer_groups, string $externalid = ''): int
    {
        // A run that died after Ticket::add but before recording it: adopt that ticket (final review #5).
        if ($externalid !== '') {
            $found = (new \Ticket())->find(['externalid' => $externalid, 'is_deleted' => 0, 'NOT' => ['status' => [5, 6]]], ['id DESC'], 1);
            if ($found !== []) {
                return (int) array_key_first($found);
            }
        }
        $entity = (int) $this->cfg['entity_default'];
        [$tt_id, $predefined, $mandatory] = $this->template($template_id, $entity);

        $input = $predefined;
        $input['name']                = $title;
        $input['content']             = $html;
        $input['entities_id']         = $entity;
        $input['_users_id_requester'] = (int) $this->cfg['service_user'];
        $input['users_id_recipient']  = (int) $this->cfg['service_user'];
        $input['_groups_id_assign']   = array_values(array_filter(array_map('intval', $assign_groups)));
        $input['_groups_id_observer'] = array_values(array_filter(array_map('intval', $observer_groups)));
        $input['_tickettemplate']     = $tt_id;
        $input['externalid']          = $externalid;
        // Spec §4: always a Request, even when the template predefines another type.
        $input['type']                = \Ticket::DEMAND_TYPE;

        $missing = TicketBuilder::missingMandatory($input, $mandatory);
        if ($missing !== []) {
            \Toolbox::logInFile('opmanager', sprintf("HygieneCheck: ticket \"%s\" not created, template %d mandatory field(s) not filled: %s\n", $title, $tt_id, implode(', ', $missing)));
            return 0;
        }
        $id = (new \Ticket())->add($input);
        if (!$id) {
            \Toolbox::logInFile('opmanager', sprintf("HygieneCheck: ticket \"%s\" not created by GLPI\n", $title));
        }
        return $id ? (int) $id : 0;
    }

    public function followup(int $tickets_id, string $html): bool
    {
        $ok = false;
        GlpiTicketGateway::withoutAutoAssign(function () use ($tickets_id, $html, &$ok): void {
            $ok = (bool) (new \ITILFollowup())->add([
                'itemtype'   => \Ticket::class,
                'items_id'   => $tickets_id,
                'content'    => $html,
                'is_private' => 0,
                'users_id'   => (int) $this->cfg['service_user'],
                '_no_reopen' => 1,
            ]);
        });
        return $ok;
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function template(int $template_id, int $entity): array
    {
        if ($template_id > 0) {
            $tt = new \TicketTemplate();
            if ($tt->getFromDBWithData($template_id)) {
                return [(int) $tt->getID(), (array) $tt->predefined, (array) $tt->mandatory];
            }
        }
        $tt = (new \Ticket())->getITILTemplateToUse(0, \Ticket::DEMAND_TYPE, 0, $entity);
        return [(int) $tt->getID(), (array) $tt->predefined, (array) $tt->mandatory];
    }
}
