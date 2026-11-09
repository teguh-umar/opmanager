<?php

namespace GlpiPlugin\Opmanager\Hygiene;

interface HygieneGateway
{
    /** GLPI ticket status, TicketGateway::TRASHED in the trash, or null when purged. */
    public function status(int $tickets_id): ?int;

    /**
     * A Request ticket (type 2, always).
     *
     * @param list<int> $assign_groups
     * @param list<int> $observer_groups
     * @param string    $externalid an open ticket already carrying it is returned instead of adding one
     * @param list<int> $assign_users technicians assigned besides the groups (rc4)
     * @return int ticket id, 0 on failure (logged)
     */
    public function create(string $title, string $html, int $template_id, array $assign_groups, array $observer_groups, string $externalid = '', array $assign_users = []): int;

    public function followup(int $tickets_id, string $html): bool;
}
