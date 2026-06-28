<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Matrix\TravelingVoiceTokenService;
use App\Services\Matrix\VoiceTokenRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 — foci AV reach. The CAPABLE peer's S2S receiver: a light node's home authority forwards a
 * traveling player's voice-token request here; this box verifies the attested actor and mints a LiveKit
 * token with ITS OWN SFU secret, returning {token, sfu_url} so the player dials this box's SFU directly.
 * Reached only by a pinned trusted peer (federation.signed); all the security is in
 * TravelingVoiceTokenService. The SFU api_secret is never echoed.
 */
class VoiceTokenController extends Controller
{
    public function mint(Request $request, TravelingVoiceTokenService $voice): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer'); // the authenticated pinned requesting node

        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];

        try {
            $minted = $voice->mintForTravelingActor(
                is_array($body['actor'] ?? null) ? $body['actor'] : [],
                (string) ($body['room'] ?? ''),
            );
        } catch (VoiceTokenRefused $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }

        // The token + the externally-reachable SFU url — NEVER the api_secret.
        return response()->json([
            'token' => $minted['token'],
            'sfu_url' => $minted['url'],
            'room' => $minted['room'],
            'identity' => $minted['identity'],
        ]);
    }
}
