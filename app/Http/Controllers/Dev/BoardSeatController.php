<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dev board seat — POST /dev/board/seat + /dev/board/unseat {legislature_id}.
 *
 * LOCAL-ONLY (the same WI-4 gate as the rest of /dev: routes registered only
 * in the local environment, DevToolsEnabled 404s when config('cga.
 * impersonation') is off). Seats the CURRENT user on the ACTIVE election
 * board of a legislature's jurisdiction so they derive R-08 and pass the
 * F-ELB-008 board provenance — the one-click posture for districting
 * walkthroughs now that the mutating draw endpoints are auth-gated.
 *
 * A direct row (no appointment) is honest for a dev tool: the schema keeps
 * appointment_id nullable, and R-08/BoardProvenance key on the seated row
 * alone. Unseat soft-deletes the seat (the table supports deleted_at and
 * carries no append-only trigger), which both derivations respect.
 */
class BoardSeatController extends Controller
{
    /** POST /dev/board/seat {legislature_id} → {ok, board_id, seated: true} */
    public function seat(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['ok' => false, 'error' => 'Sign in first — the seat lands on YOUR user.'], 403);
        }

        $board = $this->activeBoardFor($request);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        // Idempotent: an existing seated row IS the desired state (the partial
        // unique index election_board_members_one_seat forbids a duplicate).
        $already = ElectionBoardMember::query()
            ->where('election_board_id', (string) $board->id)
            ->where('user_id', (string) $user->getKey())
            ->seated()
            ->exists();

        if (! $already) {
            ElectionBoardMember::create([
                'election_board_id' => (string) $board->id,
                'user_id'           => (string) $user->getKey(),
                'status'            => ElectionBoardMember::STATUS_SEATED,
                'term_starts_on'    => now()->toDateString(),
            ]);
        }

        return response()->json(['ok' => true, 'board_id' => (string) $board->id, 'seated' => true]);
    }

    /** POST /dev/board/unseat {legislature_id} → {ok, board_id, seated: false} */
    public function unseat(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['ok' => false, 'error' => 'Sign in first — only YOUR seat can be vacated here.'], 403);
        }

        $board = $this->activeBoardFor($request);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        ElectionBoardMember::query()
            ->where('election_board_id', (string) $board->id)
            ->where('user_id', (string) $user->getKey())
            ->seated()
            ->get()
            ->each
            ->delete();   // soft — deleted_at; R-08 and BoardProvenance both exclude it

        return response()->json(['ok' => true, 'board_id' => (string) $board->id, 'seated' => false]);
    }

    /** The active board of the legislature's jurisdiction, or a plain 422. */
    private function activeBoardFor(Request $request): ElectionBoard|JsonResponse
    {
        $validated = $request->validate([
            'legislature_id' => ['required', 'uuid'],
        ]);

        $jurisdictionId = DB::table('legislatures')
            ->where('id', $validated['legislature_id'])
            ->whereNull('deleted_at')
            ->value('jurisdiction_id');

        if ($jurisdictionId === null) {
            return response()->json(['ok' => false, 'error' => 'Unknown legislature.'], 422);
        }

        $board = ElectionBoard::query()
            ->where('jurisdiction_id', (string) $jurisdictionId)
            ->active()
            ->first();

        if ($board === null) {
            return response()->json(['ok' => false, 'error' => "No active election board for this legislature's jurisdiction."], 422);
        }

        return $board;
    }
}
