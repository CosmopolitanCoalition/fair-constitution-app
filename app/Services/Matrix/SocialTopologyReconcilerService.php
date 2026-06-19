<?php

namespace App\Services\Matrix;

use App\Models\Jurisdiction;
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

        $space = $this->rooms->createPublicCommonsRoom(
            'jurisdiction', $jurisdictionId, null, MatrixRoom::ROOM_SPACE,
            'Space: '.$name, 'space-'.$short
        );

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
