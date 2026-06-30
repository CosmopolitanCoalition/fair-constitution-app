<?php

namespace App\Http\Controllers\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\MatrixPostingGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A LiveKit join token for a USER-OWNED PRIVATE room/call. The private counterpart to
 * CallTokenController: the gate is MEMBERSHIP (assertMayAccessPrivateRoom), never residency. The token
 * is room-scoped, short-lived, pseudonymous (@u-<handle>), signed by this node's appservice, and minted
 * LOCALLY (mintAccessToken — no commons gate, the membership check is the boundary). A non-member is 403.
 */
class PrivateCallTokenController extends Controller
{
    public function __invoke(Request $request, MatrixPostingGateService $gate, LiveKitTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            $gate->assertMayAccessPrivateRoom($request->user(), $data['room_id']);
        } catch (ConstitutionalViolation $e) {
            return response()->json(['error' => $e->getMessage(), 'citation' => $e->citation], 403);
        }

        $identity = $gate->matrixUserId($request->user());          // pseudonym — never the legal name
        $minted = $tokens->mintAccessToken($identity, $data['room_id']);

        // The browser client reads `sfu_url`; mintAccessToken returns the browser-reachable url under `url`.
        return response()->json(array_merge($minted, ['sfu_url' => $minted['url']]));
    }
}
