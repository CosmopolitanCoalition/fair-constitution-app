<?php

namespace App\Services\Matrix;

use App\Models\CommitteeMeeting;
use App\Models\MatrixRoom;
use App\Models\User;

/** Authorize the exact public room before either local minting or forwarding to a peer. */
class PublicVoiceRoomAccess
{
    public function __construct(private readonly MatrixPostingGateService $posting) {}

    public function assertMayJoin(User $viewer, string $jurisdictionId, string $matrixRoomId): void
    {
        $room = MatrixRoom::query()->where('matrix_room_id', $matrixRoomId)
            ->whereNull('tombstoned_at')->first();

        if ($viewer->getKey() === null || $room === null || ! $room->is_public || $room->is_encrypted) {
            throw new VoiceReachFailed('room_not_accessible', 403);
        }

        if ($room->room_type === MatrixRoom::ROOM_COMMONS
            && $room->entity_type === MatrixRoom::ENTITY_JURISDICTION
            && (string) $room->entity_id === $jurisdictionId
            && in_array($room->space_type, [MatrixRoom::SPACE_PUBLIC_SQUARE, MatrixRoom::SPACE_HALLS], true)) {
            $this->posting->assertMayAccessCommons($viewer, $jurisdictionId, $matrixRoomId);

            return;
        }

        // A public hearing is open to observers and speakers. Its committee's legislature
        // supplies the jurisdiction; a caller cannot move a room by supplying another ID.
        if ($room->room_type === MatrixRoom::ROOM_INSTITUTION
            && $room->entity_type === MatrixRoom::ENTITY_COMMITTEE_MEETING
            && CommitteeMeeting::query()->whereKey($room->entity_id)
                ->whereHas('committee.legislature', fn ($query) => $query->where('jurisdiction_id', $jurisdictionId))
                ->exists()) {
            return;
        }

        // Private calls use their existing membership-gated endpoint. Unknown institution
        // kinds need their own verified mapping before this public endpoint can admit them.
        throw new VoiceReachFailed('room_not_accessible', 403);
    }
}
