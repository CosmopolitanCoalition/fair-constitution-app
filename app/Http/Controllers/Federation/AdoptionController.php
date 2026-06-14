<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Mirror\AdoptionRejected;
use App\Services\Mirror\MirrorService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Join-key adoption (Phase G, G2). `POST /api/federation/adopt` — a would-be
 * mirror (never handshaked, so the `tofu` middleware verifies its signature
 * against the public_key carried in the body) presents a join key and is admitted
 * in one step. The admitted mirror is authoritative for nothing; we host it.
 */
class AdoptionController extends Controller
{
    public function __construct(
        private readonly MirrorService $mirror,
        private readonly InstanceIdentityService $identity,
    ) {}

    public function adopt(Request $request): JsonResponse
    {
        // Parse the RAW signed bytes — NOT $request->json(). The global
        // TrimStrings / ConvertEmptyStringsToNull middleware mutate the parsed
        // input; the peer signature is over getContent() (the SyncController
        // discipline, 299dcee).
        $body = json_decode((string) $request->getContent(), true) ?? [];

        $applicantServerId = (string) $request->header('X-Federation-Server-Id');

        try {
            $membership = $this->mirror->admitMirror(
                $applicantServerId,
                (string) ($body['public_key'] ?? ''),
                (string) ($body['nonce'] ?? ''),
                (string) ($body['key'] ?? ''),
                isset($body['url']) ? (string) $body['url'] : null,
            );
        } catch (AdoptionRejected $e) {
            return response()->json(['error' => $e->reason], $e->status);
        } catch (QueryException) {
            // The (applicant_server_id, nonce) unique index — a replay.
            return response()->json(['error' => 'replay_detected'], 409);
        }

        return response()->json([
            'admitted' => true,
            'host_server_id' => $this->identity->serverId(),
            'host_public_key' => $this->identity->publicKey(),
            'scope_jurisdiction_id' => $membership->scope_jurisdiction_id,
            'membership_id' => $membership->id,
        ]);
    }
}
