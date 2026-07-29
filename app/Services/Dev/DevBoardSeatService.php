<?php

namespace App\Services\Dev;

use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use Illuminate\Support\Facades\DB;

/**
 * Seat / unseat a given user on a legislature's active election board — the ONE
 * code path behind both the /dev/board one-click control and the `dev:board-seat`
 * CLI verb, so the board resolution, idempotency and soft-delete are identical on
 * both surfaces (guards travel with the pair).
 *
 * WHY A DEV TOOL AT ALL. Seating yourself on a board derives R-08 and satisfies
 * the F-ELB-008 board provenance the now-auth-gated district-draw commits
 * require — the districting-walkthrough posture. A DIRECT row (no appointment) is
 * honest here: `appointment_id` is nullable and R-08/BoardProvenance key on the
 * seated row alone. This is NEVER a governance write on a real world — the caller
 * (the DevToolsEnabled gate, mirrored by the CLI command) admits it only on a
 * local sandbox. It is emphatically NOT what `dev:assume` does: assuming a role
 * FINDS a seat that an election produced; this MANUFACTURES a board seat, which
 * is exactly why it lives here and not there.
 */
class DevBoardSeatService
{
    /** @return array{ok: bool, board_id?: string, seated?: bool, error?: string} */
    public function seat(string $userId, string $legislatureId): array
    {
        $board = $this->activeBoardFor($legislatureId);

        if (is_string($board)) {
            return ['ok' => false, 'error' => $board];
        }

        // Idempotent: an existing seated row IS the desired state (the partial
        // unique index election_board_members_one_seat forbids a duplicate).
        $already = ElectionBoardMember::query()
            ->where('election_board_id', (string) $board->id)
            ->where('user_id', $userId)
            ->seated()
            ->exists();

        if (! $already) {
            ElectionBoardMember::create([
                'election_board_id' => (string) $board->id,
                'user_id' => $userId,
                'status' => ElectionBoardMember::STATUS_SEATED,
                'term_starts_on' => now()->toDateString(),
            ]);
        }

        return ['ok' => true, 'board_id' => (string) $board->id, 'seated' => true];
    }

    /** @return array{ok: bool, board_id?: string, seated?: bool, error?: string} */
    public function unseat(string $userId, string $legislatureId): array
    {
        $board = $this->activeBoardFor($legislatureId);

        if (is_string($board)) {
            return ['ok' => false, 'error' => $board];
        }

        ElectionBoardMember::query()
            ->where('election_board_id', (string) $board->id)
            ->where('user_id', $userId)
            ->seated()
            ->get()
            ->each
            ->delete(); // soft — deleted_at; R-08 and BoardProvenance both exclude it

        return ['ok' => true, 'board_id' => (string) $board->id, 'seated' => false];
    }

    /** The active board of the legislature's jurisdiction, or a plain error string. */
    private function activeBoardFor(string $legislatureId): ElectionBoard|string
    {
        $jurisdictionId = DB::table('legislatures')
            ->where('id', $legislatureId)
            ->whereNull('deleted_at')
            ->value('jurisdiction_id');

        if ($jurisdictionId === null) {
            return 'Unknown legislature.';
        }

        $board = ElectionBoard::query()
            ->where('jurisdiction_id', (string) $jurisdictionId)
            ->active()
            ->first();

        if ($board === null) {
            return "No active election board for this legislature's jurisdiction.";
        }

        return $board;
    }
}
