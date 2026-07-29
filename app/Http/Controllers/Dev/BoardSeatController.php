<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Dev\DevBoardSeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dev board seat — POST /dev/board/seat + /dev/board/unseat {legislature_id}.
 *
 * LOCAL-ONLY (the WI-4 gate: routes registered only in the local environment,
 * DevToolsEnabled 404s when the toggle is off or the world is not sandbox).
 * Seats the CURRENT request user on the ACTIVE election board of a legislature's
 * jurisdiction so they derive R-08 and pass the F-ELB-008 board provenance — the
 * one-click posture for districting walkthroughs now that the mutating draw
 * endpoints are auth-gated.
 *
 * A thin HTTP adapter: the board resolution, idempotency and soft-delete all live
 * in DevBoardSeatService, shared with the `dev:board-seat` CLI verb so the pair
 * cannot drift. This controller only resolves "which user" (the request's) and
 * shapes the JSON; the service is the capability.
 */
class BoardSeatController extends Controller
{
    public function __construct(private readonly DevBoardSeatService $service) {}

    /** POST /dev/board/seat {legislature_id} → {ok, board_id, seated: true} */
    public function seat(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['ok' => false, 'error' => 'Sign in first — the seat lands on YOUR user.'], 403);
        }

        $validated = $request->validate(['legislature_id' => ['required', 'uuid']]);
        $result = $this->service->seat((string) $user->getKey(), $validated['legislature_id']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** POST /dev/board/unseat {legislature_id} → {ok, board_id, seated: false} */
    public function unseat(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['ok' => false, 'error' => 'Sign in first — only YOUR seat can be vacated here.'], 403);
        }

        $validated = $request->validate(['legislature_id' => ['required', 'uuid']]);
        $result = $this->service->unseat((string) $user->getKey(), $validated['legislature_id']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
