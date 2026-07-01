<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\ClusterAdoptionRequest;
use App\Models\ClusterMembership;
use App\Models\InstanceSettings;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Mirror\AdoptionRejected;
use App\Services\Mirror\MirrorService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adoption (Phase G, G2 + G3). `POST /api/federation/adopt` — a would-be mirror
 * (never handshaked, so the `tofu` middleware verifies its signature against the
 * public_key carried in the body) is admitted. Two paths share the endpoint:
 *   - WITH a join key (G2): immediate admission.
 *   - WITHOUT a key (G3): a pending request the host operator vouches (202 until
 *     approved, then 200 admitted on the next poll).
 * Either way the admitted mirror is authoritative for nothing; we host it.
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
        $key = (string) ($body['key'] ?? '');

        // G3c — the join-wizard negotiation rides the same raw signed body. Advisory
        // at adoption (co_member never auto-grants R/W); persisted for the operator.
        $negotiation = [
            'requested_relation' => isset($body['requested_relation']) ? (string) $body['requested_relation'] : null,
            'requested_scope_jurisdiction_id' => isset($body['requested_scope_jurisdiction_id']) ? (string) $body['requested_scope_jurisdiction_id'] : null,
            'applicant_name' => isset($body['applicant_name']) ? (string) $body['applicant_name'] : null,
            'note' => isset($body['note']) ? (string) $body['note'] : null,
        ];

        // ── Keyless path (G3) — an operator-vouched request queue ─────────────
        if ($key === '') {
            try {
                $req = $this->mirror->requestAdoption(
                    $applicantServerId,
                    (string) ($body['public_key'] ?? ''),
                    isset($body['url']) ? (string) $body['url'] : null,
                    $negotiation,
                );
            } catch (AdoptionRejected $e) {
                return response()->json(['error' => $e->reason], $e->status);
            }

            if ($req->status !== ClusterAdoptionRequest::STATUS_ADMITTED) {
                return response()->json(['status' => 'pending', 'request_id' => $req->id], 202);
            }

            $membership = ClusterMembership::find($req->cluster_membership_id);

            return $this->admittedResponse($membership?->scope_jurisdiction_id, $membership?->id);
        }

        // ── Keyed path (G2) — immediate admission ─────────────────────────────
        try {
            $membership = $this->mirror->admitMirror(
                $applicantServerId,
                (string) ($body['public_key'] ?? ''),
                (string) ($body['nonce'] ?? ''),
                $key,
                isset($body['url']) ? (string) $body['url'] : null,
                $negotiation,
            );
        } catch (AdoptionRejected $e) {
            return response()->json(['error' => $e->reason], $e->status);
        } catch (QueryException) {
            // The (applicant_server_id, nonce) unique index — a replay.
            return response()->json(['error' => 'replay_detected'], 409);
        }

        return $this->admittedResponse($membership->scope_jurisdiction_id, $membership->id);
    }

    private function admittedResponse(?string $scope, ?string $membershipId): JsonResponse
    {
        return response()->json([
            'admitted' => true,
            'host_server_id' => $this->identity->serverId(),
            'host_public_key' => $this->identity->publicKey(),
            // Public display name (already published by the well-known descriptor) — the mirror pins it
            // on the host peer and, if it was never deliberately named itself, adopts it on going live
            // (one mesh = one game; a citizen should see the game's name, not "Unnamed Instance").
            'host_name' => (string) InstanceSettings::current()->instance_name,
            'scope_jurisdiction_id' => $scope,
            'membership_id' => $membershipId,
        ]);
    }
}
