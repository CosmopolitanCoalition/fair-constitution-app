<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Services\AuditService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WI-8 — GET /system/audit-chain: read-only viewer over the hash-chained
 * audit_log (WF-SYS-04), latest-first.
 *
 * verifyChain() walks every link and is NOT run per-request — the page
 * shows the chain head and lets an OPERATOR trigger a full verification
 * via POST (result flashed). Anyone authenticated can read the chain;
 * the chain is the public record.
 */
class AuditChainController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function show(Request $request): Response
    {
        $entries = AuditEntry::query()
            ->orderByDesc('seq')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditEntry $entry) => [
                'seq'            => $entry->seq,
                'occurred_at'    => $entry->occurred_at?->toIso8601String(),
                'module'         => $entry->module,
                'event'          => $entry->event,
                'ref'            => $entry->ref,
                'hash'           => $entry->hash,
                'prev_hash'      => $entry->prev_hash,
                'rejected'       => $entry->rejected,
                'blocked_reason' => $entry->blocked_reason,
            ]);

        return Inertia::render('System/AuditChain', [
            'surface' => SurfaceMeta::for('system/audit-chain'),
            'entries' => $entries,
            'chain'   => [
                'head_seq' => $this->audit->latestSeq(),
                'count'    => $this->audit->count(),
                'genesis'  => AuditService::GENESIS_PREV_HASH,
            ],
            // Full-chain verification is operator-triggered (expensive walk).
            'canVerify' => (bool) $request->user()?->is_operator,
        ]);
    }

    /** POST /system/audit-chain/verify — operators only; result flashed. */
    public function verify(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_operator === true, 403, 'Chain verification is operator-triggered.');

        $started = hrtime(true);
        $result  = $this->audit->verifyChain();
        $ms      = (int) round((hrtime(true) - $started) / 1e6);

        if ($result === true) {
            $head = $this->audit->latestSeq();

            return back()->with(
                'status',
                "Chain verified — every link recomputed through head #{$head} in {$ms} ms."
            );
        }

        return back()->withErrors([
            'chain' => "CHAIN BROKEN at seq #{$result} — the link does not recompute. Investigate immediately.",
        ]);
    }
}
