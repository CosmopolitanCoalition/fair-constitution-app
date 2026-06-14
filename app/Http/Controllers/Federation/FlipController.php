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

        $data = $request->validate([
            'manifest' => ['required', 'array'],
            'manifest.root_jurisdiction_id' => ['required', 'uuid'],
            'signature' => ['required', 'string'],
        ]);

        $export = $this->flips->importFlip($data['manifest'], $data['signature'], $peer);

        return response()->json([
            'status' => $export->status,
            'jurisdiction_id' => $export->jurisdiction_id,
        ]);
    }
}
