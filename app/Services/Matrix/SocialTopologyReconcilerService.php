<?php

namespace App\Services\Matrix;

use App\Models\Board;
use App\Models\CommitteeMeeting;
use App\Models\CourtCase;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\MatrixRoom;

/**
 * Phase K-3 (K3-F) — reconcile a jurisdiction's Matrix topology from the jurisdictions tree (design
 * §6.1). One Space (m.space) per jurisdiction; a #square ALWAYS (open resident discourse, world_
 * readable even when chartered-but-empty); a #halls ONLY when a government is seated (no seated body
 * ⇒ no authoritative testimony — the FLIP-ON-SEATEDNESS gate, halls half). Children bind under the
 * Space via m.space.child. Idempotent: the matrix_rooms (entity, space_type) partial-unique means a
 * re-run is a no-op. Every room is created by MatrixRoomCreationService — so the v12 sole-creator
 * power-clamp (K3-E) holds for the whole tree.
 */
class SocialTopologyReconcilerService
{
    public function __construct(
        private MatrixRoomCreationService $rooms,
        private MatrixClientService $client,
    ) {}

    /** isActivated is the Phase-I activation-tier seam (below tier ⇒ #square but no #halls); default true. */
    public function reconcileJurisdiction(string $jurisdictionId, bool $isSeated, bool $isActivated = true): void
    {
        $jur = Jurisdiction::query()->find($jurisdictionId);
        if ($jur === null) {
            return;
        }

        $name = (string) $jur->name;
        $short = substr(str_replace('-', '', $jurisdictionId), 0, 12);

        $space = $this->ensureSpace($jur);

        $square = $this->rooms->createPublicCommonsRoom(
            'jurisdiction', $jurisdictionId, MatrixRoom::SPACE_PUBLIC_SQUARE, MatrixRoom::ROOM_COMMONS,
            $name.' — Public Square', 'square-'.$short
        );
        $this->bindChild($space, $square);

        // #halls ONLY when a government is seated AND the jurisdiction is activated (Phase-I tier).
        if ($isSeated && $isActivated) {
            $halls = $this->rooms->createPublicCommonsRoom(
                'jurisdiction', $jurisdictionId, MatrixRoom::SPACE_HALLS, MatrixRoom::ROOM_COMMONS,
                $name.' — Halls of Governance', 'halls-'.$short
            );
            $this->bindChild($space, $halls);
        }
    }

    /**
     * Per-institution live rooms (Slice 6). Rooms exist today only for the
     * jurisdiction Space + #square/#halls; a legislature floor, a committee
     * hearing, a court case, and a board meeting each need their OWN room for
     * the Live Civic Room. Government proceedings (session/committee/case) are
     * PUBLIC commons rooms bound under the jurisdiction Space (world_readable —
     * Art. II §2, and public trials Art. IV); a private organization's board is
     * a PRIVATE entity room whose visibility follows the org's choice (§10-1),
     * defaulting private. All idempotent (the matrix_rooms partial-unique makes
     * a re-run a no-op) — safe to call lazily on first view of the room.
     */
    public function reconcileInstitutionRoom(
        string $entityType,
        string $entityId,
        ?string $jurisdictionId,
        string $title,
        bool $isPublic = true,
    ): ?MatrixRoom {
        if (! $isPublic) {
            // A private org proceeding: no public Space binding — board
            // membership gates access off-Matrix (game layer), like a group.
            return $this->rooms->createEntityPrivateRoom($entityType, $entityId, $title);
        }

        // A public civic room lives UNDER its jurisdiction's Space; without a
        // resolvable jurisdiction there is no Space to parent it, so no room.
        if ($jurisdictionId === null) {
            return null;
        }
        $jur = Jurisdiction::query()->find($jurisdictionId);
        if ($jur === null) {
            return null;
        }

        $space = $this->ensureSpace($jur);
        $room = $this->rooms->createPublicCommonsRoom(
            $entityType, $entityId, null, MatrixRoom::ROOM_INSTITUTION, $title, null
        );
        $this->bindChild($space, $room);

        return $room;
    }

    /** A legislature's floor-session room (public commons). */
    public function reconcileLegislature(Legislature $legislature): ?MatrixRoom
    {
        return $this->reconcileInstitutionRoom(
            MatrixRoom::ENTITY_LEGISLATURE,
            (string) $legislature->id,
            $legislature->jurisdiction_id !== null ? (string) $legislature->jurisdiction_id : null,
            'Floor session',
            true,
        );
    }

    /** A committee hearing's room (public commons) — the exit-test path. */
    public function reconcileCommitteeMeeting(CommitteeMeeting $meeting): ?MatrixRoom
    {
        $committee   = $meeting->committee()->first();
        $legislature = $committee?->legislature()->first();

        return $this->reconcileInstitutionRoom(
            MatrixRoom::ENTITY_COMMITTEE_MEETING,
            (string) $meeting->id,
            $legislature?->jurisdiction_id !== null ? (string) $legislature->jurisdiction_id : null,
            'Committee hearing'.($committee?->name !== null ? ' — '.$committee->name : ''),
            true,
        );
    }

    /** A court case's hearing room (public commons — public trials, Art. IV). */
    public function reconcileCase(CourtCase $case): ?MatrixRoom
    {
        return $this->reconcileInstitutionRoom(
            MatrixRoom::ENTITY_CASE,
            (string) $case->id,
            $case->jurisdiction_id !== null ? (string) $case->jurisdiction_id : null,
            'Hearing'.($case->title !== null ? ' — '.$case->title : ''),
            true,
        );
    }

    /** A board meeting's room — PRIVATE (a private org decides its own visibility, §10-1). */
    public function reconcileBoard(Board $board): ?MatrixRoom
    {
        return $this->reconcileInstitutionRoom(
            MatrixRoom::ENTITY_BOARD,
            (string) $board->id,
            $board->jurisdictionId(),
            'Board meeting',
            false,
        );
    }

    /** The jurisdiction's m.space (idempotent) — the parent for every civic room in it. */
    private function ensureSpace(Jurisdiction $jur): MatrixRoom
    {
        $short = substr(str_replace('-', '', (string) $jur->id), 0, 12);

        return $this->rooms->createPublicCommonsRoom(
            'jurisdiction', (string) $jur->id, null, MatrixRoom::ROOM_SPACE,
            'Space: '.$jur->name, 'space-'.$short
        );
    }

    /** m.space.child on the Space + m.space.parent on the child (idempotent room state; appservice-only). */
    private function bindChild(MatrixRoom $space, MatrixRoom $child): void
    {
        if ($space->matrix_room_id === null || $child->matrix_room_id === null || $space->id === $child->id) {
            return;
        }

        $via = [(string) config('matrix.server_name')];
        $this->client->sendStateEvent($space->matrix_room_id, 'm.space.child', $child->matrix_room_id, ['via' => $via]);
        $this->client->sendStateEvent($child->matrix_room_id, 'm.space.parent', $space->matrix_room_id, ['via' => $via, 'canonical' => true]);
    }
}
