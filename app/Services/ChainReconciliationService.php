<?php

namespace App\Services;

use App\Models\AuditChainReconciliation;
use App\Models\OperatorAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The constitutional repair path for the audit chain. The chain is tamper-EVIDENT,
 * not tamper-PROOF: a genuine discontinuity is never silently rewritten (that would
 * be undetectable tampering) — instead a legitimate authority ACKNOWLEDGES it on
 * the record, with a reason, and the chain is re-grounded forward.
 *
 * Authority is the constitutional human element: a standing government office (the
 * R-08 election board, etc.) where one exists, or — where none does yet — the
 * de-facto collective of operators (the operator plane; the threshold/consent
 * scaling is the G-VER de-facto-board design, recorded here in `consent`). The
 * acknowledgement is ALSO appended as a `chain.reconciled` audit entry, so grounding
 * a broken record is itself on the tamper-evident record and federates.
 */
class ChainReconciliationService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Every continuity break in the chain (acknowledged or not), from $fromSeq.
     * Walks like verifyChain but re-anchors at each break so it finds them ALL.
     *
     * @return array<int,array{break_seq:int,expected_prev_hash:string,observed_prev_hash:string,acknowledged:bool}>
     */
    public function detectBreaks(?int $fromSeq = null): array
    {
        $expectedPrev = AuditService::GENESIS_PREV_HASH;

        $query = DB::table('audit_log')->select(['seq', 'prev_hash', 'hash'])->orderBy('seq');

        if ($fromSeq !== null && $fromSeq > 1) {
            $anchor = DB::table('audit_log')->where('seq', '<', $fromSeq)->orderByDesc('seq')->value('hash');
            if ($anchor === null) {
                throw new InvalidArgumentException("No audit entry precedes seq {$fromSeq}.");
            }
            $expectedPrev = (string) $anchor;
            $query->where('seq', '>=', $fromSeq);
        }

        $blessed = AuditChainReconciliation::blessedMap();
        $breaks = [];

        foreach ($query->cursor() as $row) {
            if ((string) $row->prev_hash !== $expectedPrev) {
                $breaks[] = [
                    'break_seq' => (int) $row->seq,
                    'expected_prev_hash' => $expectedPrev,
                    'observed_prev_hash' => (string) $row->prev_hash,
                    'acknowledged' => (($blessed[(int) $row->seq] ?? null) === (string) $row->prev_hash),
                ];
            }
            $expectedPrev = (string) $row->hash;
        }

        return $breaks;
    }

    /**
     * Acknowledge a break ON the record and re-ground the chain. Appends a
     * `chain.reconciled` audit entry + writes the reconciliation row. Idempotent on
     * (break_seq, observed_prev_hash). Refuses if the seq is not actually a break,
     * if no reason is given, or if the named authority is absent.
     */
    public function acknowledge(
        int $breakSeq,
        string $reason,
        string $authorityKind = AuditChainReconciliation::AUTHORITY_OPERATOR_COLLECTIVE,
        ?OperatorAccount $operator = null,
        ?User $officer = null,
        array $consent = [],
    ): AuditChainReconciliation {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to acknowledge a chain break — the record must say WHY.');
        }

        $row = DB::table('audit_log')->where('seq', $breakSeq)->first(['seq', 'prev_hash']);
        if ($row === null) {
            throw new InvalidArgumentException("No audit row at seq {$breakSeq}.");
        }

        $precedingHash = DB::table('audit_log')->where('seq', '<', $breakSeq)->orderByDesc('seq')->value('hash');
        $expected = (string) ($precedingHash ?? AuditService::GENESIS_PREV_HASH);
        $observed = (string) $row->prev_hash;

        if ($observed === $expected) {
            throw new InvalidArgumentException("Seq {$breakSeq} chains cleanly — there is nothing to reconcile.");
        }

        // The de-facto operator collective signs with an operator-plane account
        // where one exists, or — on an instance founded before the operator plane —
        // the founder (is_operator) user standing in as the lone de-facto operator.
        if ($authorityKind === AuditChainReconciliation::AUTHORITY_OPERATOR_COLLECTIVE && $operator === null && $officer === null) {
            throw new InvalidArgumentException('A de-facto operator (an operator account or the founder) must sign an operator-collective reconciliation.');
        }
        if ($authorityKind === AuditChainReconciliation::AUTHORITY_GOVERNMENT && $officer === null) {
            throw new InvalidArgumentException('An officeholder must sign a government reconciliation.');
        }

        $existing = AuditChainReconciliation::query()
            ->where('break_seq', $breakSeq)
            ->where('observed_prev_hash', $observed)
            ->whereNull('deleted_at')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($breakSeq, $observed, $expected, $reason, $authorityKind, $operator, $officer, $consent) {
            // The acknowledgement is itself on the tamper-evident record (and federates).
            $entry = $this->audit->append('audit_chain', 'chain.reconciled', [
                'break_seq' => $breakSeq,
                'observed_prev_hash' => $observed,
                'expected_prev_hash' => $expected,
                'reason' => $reason,
                'authority_kind' => $authorityKind,
                'by_operator' => $operator?->id,
                'by_user' => $officer?->id,
            ], 'WF-SYS-04');

            return AuditChainReconciliation::create([
                'break_seq' => $breakSeq,
                'observed_prev_hash' => $observed,
                'expected_prev_hash' => $expected,
                'reason' => $reason,
                'authority_kind' => $authorityKind,
                'acknowledged_by_user_id' => $officer?->id,
                'acknowledged_by_operator_id' => $operator?->id,
                'consent' => $consent !== [] ? $consent : null,
                'audit_seq' => $entry->seq,
                'acknowledged_at' => now(),
            ]);
        });
    }
}
