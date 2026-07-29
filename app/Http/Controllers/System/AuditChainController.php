<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\AuditChainReconciliation;
use App\Models\AuditEntry;
use App\Models\OperatorAccount;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ChainReconciliationService;
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

    /**
     * POST /system/audit-chain/reconcile — the UI twin of `audit:reconcile`
     * (ruling 10, UI<->CLI parity). Operators only, exactly like verify(): the
     * chain is tamper-EVIDENT, so a genuine break is never silently rewritten —
     * a de-facto operator (an operator-plane account, or the founder on a lone
     * box) signs an acknowledgement WITH A REASON, recorded on the chain, and
     * verifyChain then treats the break as grounded. The reason is required by
     * the service; the signer is resolved identically to the command. Idempotent
     * per break, so re-running is safe.
     */
    public function reconcile(Request $request, ChainReconciliationService $recon): RedirectResponse
    {
        abort_unless($request->user()?->is_operator === true, 403, 'Reconciliation is operator-triggered.');

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return back()->withErrors(['reason' => 'A reason is required — the record must say WHY the break is grounded.']);
        }

        $from = $request->input('from') !== null ? (int) $request->input('from') : null;

        $unacked = array_values(array_filter($recon->detectBreaks($from), static fn ($b) => ! $b['acknowledged']));

        if ($unacked === []) {
            return back()->with('status', 'No unacknowledged chain breaks — the chain is grounded.');
        }

        [$operator, $founder] = $this->resolveSigner();
        if ($operator === null && $founder === null) {
            return back()->withErrors([
                'reason' => 'No operator account or founder (is_operator) user to sign the acknowledgement.',
            ]);
        }

        $consent = $operator !== null
            ? ['operators' => [$operator->id], 'threshold' => 'de-facto operator collective']
            : ['founder_user' => $founder->id, 'threshold' => 'de-facto operator (founder; operator plane not yet bootstrapped)'];

        foreach ($unacked as $b) {
            $recon->acknowledge(
                $b['break_seq'],
                $reason,
                AuditChainReconciliation::AUTHORITY_OPERATOR_COLLECTIVE,
                $operator,
                $founder,
                $consent,
            );
        }

        $signer = $operator !== null ? "operator {$operator->username}" : 'the founder (de-facto operator)';
        $n = count($unacked);

        return back()->with(
            'status',
            "Re-grounded {$n} chain break".($n === 1 ? '' : 's')." — signed by {$signer}. Run 'Verify the full chain' to confirm.",
        );
    }

    /**
     * The de-facto operator signer: an active operator-plane account if one
     * exists, else the founder (is_operator) on an instance founded before the
     * operator plane. Mirrors AuditReconcileCommand::resolveSigner.
     *
     * @return array{0:?OperatorAccount,1:?User}
     */
    private function resolveSigner(): array
    {
        $operator = OperatorAccount::query()
            ->where('status', OperatorAccount::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();

        if ($operator !== null) {
            return [$operator, null];
        }

        $founder = User::query()
            ->where('is_operator', true)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();

        return [null, $founder];
    }
}
