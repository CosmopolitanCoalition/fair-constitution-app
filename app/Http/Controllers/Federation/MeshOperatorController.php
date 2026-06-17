<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Identity\MeshOperatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase G (G-OP-2) — server-to-server operator-identity gossip. A pinned peer
 * announces a mesh operator identity + its signed device-key bindings; we ingest
 * the ones we can authenticate against EACH binding's bound-by server's pinned
 * key (MeshOperatorService::ingestAnnounce — a relay cannot forge a binding).
 *
 * Mounted under /api/federation OUTSIDE the web group; authenticated by the
 * Ed25519 peer signature (federation.signed:pinned), not a user session. The
 * federation private/operator credentials never travel — only public keys +
 * instance signatures + a non-secret handle. Cross-instance; real cert is
 * rig-gated like G-V2.
 */
class MeshOperatorController extends Controller
{
    public function __construct(private readonly MeshOperatorService $mesh) {}

    public function announce(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        // Parse the wire from the RAW signed bytes (the peer signature is over
        // getContent()), NOT $request->json() — the global TrimStrings /
        // ConvertEmptyStringsToNull middleware would mutate the parsed input and
        // break each binding's own signature over its canonical form.
        $wire = json_decode((string) $request->getContent(), true) ?? [];

        $identity = $this->mesh->ingestAnnounce($wire, $peer);

        return response()->json([
            'ingested'         => $identity !== null,
            'mesh_operator_id' => $identity?->id,
        ]);
    }
}
