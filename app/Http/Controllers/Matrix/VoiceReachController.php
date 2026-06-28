<?php

namespace App\Http\Controllers\Matrix;

use App\Http\Controllers\Controller;
use App\Services\Federation\NoReachableHolder;
use App\Services\Matrix\VoiceReachFailed;
use App\Services\Matrix\VoiceReachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 — foci AV reach. The player's home-node endpoint for mixed-environment voice: hand back a LiveKit
 * {token, sfu_url} for a room, whether this node hosts the SFU or a capable peer does. The client supplies
 * its device-signed proof (the device secret is un-escrowed and never reaches the server); the home node
 * issues the short-TTL attestation and forwards. No reachable SFU degrades to a clean 503 (no voice), never
 * a 500. The commons is OPEN — access is not residency-gated (the game gates powers, not presence).
 */
class VoiceReachController extends Controller
{
    public function __invoke(Request $request, VoiceReachService $voice): JsonResponse
    {
        $data = $request->validate([
            'jurisdiction_id'   => ['required', 'string', 'max:64'],
            'room'              => ['required', 'string', 'max:255'],
            'device_public_key' => ['required', 'string', 'max:255'],
            'action_signature'  => ['required', 'string', 'max:512'],
            'timestamp'         => ['required', 'integer'],
        ]);

        try {
            $result = $voice->tokenFor($request->user(), $data['jurisdiction_id'], $data['room'], [
                'device_public_key' => $data['device_public_key'],
                'action_signature'  => $data['action_signature'],
                'timestamp'         => $data['timestamp'],
            ]);
        } catch (NoReachableHolder) {
            // The mixed environment's safe degrade: no peer hosts an SFU → no voice here, not a failure.
            return response()->json(['error' => 'voice_unavailable_here', 'degrade' => true], 503);
        } catch (VoiceReachFailed $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        return response()->json($result);
    }
}
