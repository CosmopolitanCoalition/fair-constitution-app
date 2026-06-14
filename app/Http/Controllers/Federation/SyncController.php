<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\AuditCheckpoint;
use App\Models\FederationPeer;
use App\Services\Federation\FederationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Full Faith & Credit sync endpoints (Phase F, WF-JUR-06). Pinned peers only.
 */
class SyncController extends Controller
{
    public function __construct(private readonly FederationSyncService $sync) {}

    /** GET /api/federation/audit-tail?from_seq= — our signed tail for a puller. */
    public function auditTail(Request $request): JsonResponse
    {
        $fromSeq = (int) $request->query('from_seq', 0);

        return response()->json($this->sync->buildAuditTail($fromSeq));
    }

    /** POST /api/federation/sync — a peer pushes its tail; we verify + apply. */
    public function receive(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        $log = $this->sync->ingestTail($peer, $request->json()->all());

        return response()->json([
            'result' => $log->result,
            'detail' => $log->detail,
        ]);
    }

    /** GET /api/federation/checkpoint — our latest signed head checkpoint. */
    public function checkpoint(): JsonResponse
    {
        $cp = AuditCheckpoint::query()->orderByDesc('seq')->first();

        return response()->json($cp ? [
            'audit_seq' => $cp->audit_seq,
            'head_hash' => $cp->head_hash,
            'signature' => $cp->signature,
        ] : ['audit_seq' => null]);
    }
}
