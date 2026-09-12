<?php

namespace App\Services\Rooms;

use App\Models\MatrixIdentity;
use App\Http\Presenters\CandidacyPanel;

/** Public pseudonyms only. Never resolve a Matrix handle to a legal name or email. */
class PublicRoomNames
{
    /** Resolve only the supplied room/message roster, not a global people directory. */
    public function forHandles(array $handles): array
    {
        $handles = array_values(array_unique(array_filter($handles)));
        if ($handles === []) {
            return [];
        }

        $identities = MatrixIdentity::query()->whereIn('matrix_user_id', $handles)
            ->get(['user_id', 'matrix_user_id']);
        $profiles = $this->forUsers($identities->pluck('user_id')->all());

        return $identities->mapWithKeys(fn ($identity) => [
            $identity->matrix_user_id => $profiles[(string) $identity->user_id] ?? null,
        ])->filter()->all();
    }

    public function forUsers(array $userIds): array
    {
        // Same chosen public pseudonym as the person's profile. This shared helper
        // selects id/display_name only; users.name and email are unreachable.
        return CandidacyPanel::displayNames($userIds);
    }
}
