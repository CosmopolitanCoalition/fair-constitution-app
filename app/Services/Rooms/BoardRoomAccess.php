<?php

namespace App\Services\Rooms;

use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Organization;
use App\Models\User;

/** Private board calls belong only to the current holders of that board's seats. */
class BoardRoomAccess
{
    public function allows(?User $viewer, Board $board): bool
    {
        return $viewer !== null && $viewer->getKey() !== null
            && ! $board->trashed()
            && $board->status !== Board::STATUS_DISSOLVED
            && BoardSeat::query()->where('board_id', $board->id)->seated()
                ->where('holder_user_id', $viewer->getKey())->exists();
    }

    /**
     * A public body's board reads for every resident; a private organization's
     * board does not. Public = a department Board of Governors, or an
     * organization board whose organization is a common good corporation
     * (Art. III §5 public enterprise). Everything else is private.
     */
    public function isPublic(Board $board): bool
    {
        if ($board->boardable_type === Board::BOARDABLE_DEPARTMENTS) {
            return true;
        }
        if ($board->boardable_type === Board::BOARDABLE_ORGANIZATIONS) {
            $organization = $board->organization();

            return $organization instanceof Organization && (bool) $organization->is_cgc;
        }

        return false;
    }

    public function assertMayJoin(?User $viewer, Board $board): void
    {
        abort_unless($this->allows($viewer, $board), 403, __('This boardroom is for its current seated members.'));
    }
}
