<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\AuthorityFlipService;
use App\Services\Federation\BallotRewrapFailed;
use App\Services\Federation\OperationalBundleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authority-flip ingest (Phase F, WF-JUR-08). A trusted peer hands us a signed
 * partition manifest; we verify it and assume authority for the subtree.
 */
class FlipController extends Controller
{
    public function __construct(private readonly AuthorityFlipService $flips) {}

    /** POST /api/federation/flip (pinned). */
    public function receive(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        // Parse from the RAW signed bytes (the manifest signature is over its
        // canonical form) — NOT validated input, which TrimStrings /
        // ConvertEmptyStringsToNull would mutate and invalidate.
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $manifest = $data['manifest'] ?? null;
        $signature = (string) ($data['signature'] ?? '');

        if (! is_array($manifest) || ($manifest['root_jurisdiction_id'] ?? null) === null || $signature === '') {
            return response()->json(['error' => 'malformed partition bundle'], 422);
        }

        $export = $this->flips->importFlip($manifest, $signature, $peer);

        return response()->json([
            'status' => $export->status,
            'jurisdiction_id' => $export->jurisdiction_id,
        ]);
    }

    /**
     * POST /api/federation/flip/operational (pinned) — the G5/G5a DATA half of a
     * governed authority flip. A pinned peer delivers the flipping subtree's
     * per-election keys SEALED to us (libsodium sealed box; only we can open it).
     * We open it and re-wrap each key under OUR KEK, FAIL-CLOSED: if any election's
     * re-wrap cannot reproduce its certified record_hash, the whole apply rolls back
     * and we report the refusal so the sender can revert its half. This is what
     * keeps a subtree's sealed elections re-countable once authority has moved —
     * the keys travel WITH authority, never the routine sync tail.
     */
    public function receiveOperational(Request $request, OperationalBundleService $bundles): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        // Parse from the RAW bytes — the sealed bundle is opaque base64 and must not
        // be mutated by TrimStrings / ConvertEmptyStringsToNull.
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $sealed = (string) ($data['sealed'] ?? '');

        if ($sealed === '') {
            return response()->json(['error' => 'missing sealed operational bundle'], 422);
        }

        try {
            $export = $bundles->openAndApply($sealed, (string) $peer->server_id, null);
        } catch (BallotRewrapFailed $e) {
            // FAIL CLOSED — nothing was committed on our side; the election keys did
            // not move. Tell the sender so it can revert its authority half.
            return response()->json([
                'status' => 'rewrap_failed',
                'election_id' => $e->electionId,
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json([
            'status' => $export->status,
            'applied_count' => $export->applied_count,
        ]);
    }
}
