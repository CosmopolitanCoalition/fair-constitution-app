<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\ForwardedWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mockups-v3-wiring Phase 4 — the traveling-write RECEIPT
 * (PHASE_4_DESIGN_peerage.md §4). `GET /api/federation/write-status/{origin}/{key}`
 * lets a signed-in user poll the outcome of a forwarded write they filed: the
 * page that received `status: forwarded` from WriteRouterService::dispatch()
 * shows "Filed — carried to {jurisdiction}'s home node" with the idempotency key
 * as the reference, and polls this endpoint on the executing (home-copy) node.
 *
 * No new semantics — it READS the existing forwarded_writes ledger. Own-writes
 * only: the outcome is returned ONLY when the write's actor is determinable AND
 * is the requesting user. The actor is determined through the audit row the
 * execution sealed (forwarded_writes.audit_seq → audit_log.actor_user_id);
 * a row whose actor cannot be determined that way (pending — not yet executed —
 * or rejected, which records a citation but seals no referenced audit row, or a
 * system filing with no actor) answers 404, indistinguishable from an unknown
 * key. Fail-closed: the receipt never becomes an enumeration oracle over other
 * people's filings.
 */
class WriteStatusController extends Controller
{
    public function __invoke(Request $request, string $origin, string $key): JsonResponse
    {
        $row = ForwardedWrite::query()
            ->where('origin_server_id', $origin)
            ->where('idempotency_key', $key)
            ->first();

        // Determinable actor = the actor_user_id on the audit row this write sealed.
        $actorId = ($row !== null && $row->audit_seq !== null)
            ? DB::table('audit_log')->where('seq', $row->audit_seq)->value('actor_user_id')
            : null;

        if ($row === null || $actorId === null || (string) $actorId !== (string) $request->user()->getKey()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'origin_server_id' => (string) $row->origin_server_id,
            'idempotency_key' => (string) $row->idempotency_key,
            'form_id' => (string) $row->form_id,
            'jurisdiction_id' => $row->jurisdiction_id !== null ? (string) $row->jurisdiction_id : null,
        ] + $row->outcome());
    }
}
