<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\AuthorityFlipService;
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
}
