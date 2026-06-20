<?php

namespace App\Http\Controllers\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Services\Matrix\LiveKitTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase K-3 (K3-J) — request a LiveKit (Element Call) join token for a jurisdiction's call room.
 * RESIDENCY is the only gate (Art. I): a non-resident is refused (403). The minted token is room-scoped,
 * short-lived, pseudonymous, and signed by the appservice — see LiveKitTokenService.
 */
class CallTokenController extends Controller
{
    public function __invoke(Request $request, LiveKitTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'jurisdiction_id' => ['required', 'string', 'max:64'],
            'room'            => ['required', 'string', 'max:255'],
        ]);

        try {
            $minted = $tokens->mintFor($request->user(), $data['jurisdiction_id'], $data['room']);
        } catch (ConstitutionalViolation $e) {
            return response()->json(['error' => $e->getMessage(), 'citation' => $e->citation], 403);
        }

        return response()->json($minted);
    }
}
