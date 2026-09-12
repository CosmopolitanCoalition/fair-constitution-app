<?php

namespace App\Services\Rooms;

use App\Models\Board;
use App\Models\BoardSeat;
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

    public function assertMayJoin(?User $viewer, Board $board): void
    {
        abort_unless($this->allows($viewer, $board), 403, 'This boardroom is for its current seated members.');
    }
}
