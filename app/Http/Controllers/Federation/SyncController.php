<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\AuditCheckpoint;
use App\Models\FederationPeer;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\FoundationServeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Full Faith & Credit sync endpoints (Phase F, WF-JUR-06). Pinned peers only.
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly FederationSyncService $sync,
        private readonly FoundationServeService $foundation,
    ) {}

    /**
     * GET /api/federation/audit-tail?from_seq=&page_size=&to_seq= — a signed page
     * of our tail for a puller (cold sync). ALWAYS server-capped so the response
     * body is bounded — a fresh mirror pulls the whole corpus in pages, never one
     * multi-MB body (the fix for the live-demo body-size failure).
     */
    public function auditTail(Request $request): JsonResponse
    {
        $fromSeq = (int) $request->query('from_seq', 0);
        $max = (int) config('cga.federation_sync_page_max', 1000);
        $requested = (int) $request->query('page_size', 0);
        $pageSize = $requested > 0 ? min($requested, $max) : $max;
        $capTo = $request->query('to_seq') !== null ? (int) $request->query('to_seq') : null;

        return response()->json($this->sync->buildAuditTail($fromSeq, $pageSize, $capTo));
    }

    /**
     * GET /api/federation/foundation/page?table=&from_key=&page_size= — one signed KEYSET
     * page of a geodata-foundation table for a paginated drain (seed redesign). `from_key` is
     * the JSON-array watermark a joiner echoes from the prior page's `next_from_key` (omit for
     * the table head). Always server-capped per table so the body is bounded — the replacement
     * for the monolithic pg_restore seed.
     */
    public function foundationPage(Request $request): JsonResponse
    {
        $table = (string) $request->query('table', '');

        $fromKey = null;
        $raw = $request->query('from_key');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $fromKey = array_values($decoded);
            }
        }

        $pageSize = (int) $request->query('page_size', 0);

        try {
            return response()->json($this->foundation->buildFoundationPage($table, $fromKey, $pageSize));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** POST /api/federation/sync — a peer pushes its tail; we verify + apply. */
    public function receive(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        // Parse the tail from the RAW signed bytes (the peer signature is over
        // getContent()). NOT $request->json(): the global TrimStrings /
        // ConvertEmptyStringsToNull middleware mutate the parsed input (empty
        // strings → null, trimmed whitespace), which would break the tail's own
        // signature over its canonical form.
        $tail = json_decode((string) $request->getContent(), true) ?? [];

        $log = $this->sync->ingestTail($peer, $tail);

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
