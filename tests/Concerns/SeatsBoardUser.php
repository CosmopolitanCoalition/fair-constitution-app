<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seats a FRESH user on a jurisdiction's ACTIVE election board, inside the
 * caller's live-pg transaction (rolled back with everything else). This is
 * the R-08 + board-provenance posture the mutating draw endpoints require
 * now that guest commits are closed — commit pins file as this user.
 */
trait SeatsBoardUser
{
    protected function seatedBoardUser(string $jurisdictionId): User
    {
        $boardId = DB::table('election_boards')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->value('id');

        if ($boardId === null) {
            $this->markTestSkipped('No active election board on the fixture jurisdiction.');
        }

        $userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id'                => $userId,
            'name'              => 'Board Pin User',
            'email'             => "board-pin-{$userId}@example.test",
            'password'          => password_hash('board-pin-password', PASSWORD_BCRYPT),
            'terms_accepted_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        DB::table('election_board_members')->insert([
            'id'                => (string) Str::uuid(),
            'election_board_id' => $boardId,
            'user_id'           => $userId,
            'status'            => 'seated',
            'term_starts_on'    => now()->toDateString(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return User::query()->findOrFail($userId);
    }
}
