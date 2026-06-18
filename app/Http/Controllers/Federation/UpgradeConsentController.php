<?php

namespace App\Http\Controllers\Federation;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Models\PeerUpgradeProposal;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase G (G-VER / A2) — a co-affected pinned peer delivers its Meter C mesh consent
 * for one of OUR open upgrade proposals (S2S). The request is already authenticated by
 * the peer's Ed25519 signature (federation.signed:pinned); standing is then enforced by
 * the service — only a trust-established peer authoritative for a jurisdiction in the
 * affected subtree may record consent. Records nothing else: ratification stays the
 * governed PeerUpgradeAgreementService::ratify flow, never this endpoint.
 */
class UpgradeConsentController extends Controller
{
    public function __construct(private readonly PeerUpgradeAgreementService $upgrades) {}

    public function store(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $proposalId = (string) ($body['proposal_id'] ?? '');

        if ($proposalId === '') {
            return response()->json(['error' => 'proposal_id is required'], 422);
        }

        $proposal = PeerUpgradeProposal::query()->find($proposalId);

        if ($proposal === null) {
            return response()->json(['error' => 'unknown_proposal'], 404);
        }

        try {
            $consent = $this->upgrades->recordPeerConsent(
                $proposal,
                (string) $peer->server_id,
                (bool) ($body['consented'] ?? false),
                isset($body['signature']) ? (string) $body['signature'] : null,
            );
        } catch (ConstitutionalViolation $e) {
            // Not open, not co-affected, or already recorded — a refusal with citation.
            return response()->json(['error' => $e->getMessage(), 'citation' => $e->citation], 409);
        }

        return response()->json([
            'status' => 'recorded',
            'result' => $consent->result,
            'meter' => $consent->meter,
        ]);
    }
}
