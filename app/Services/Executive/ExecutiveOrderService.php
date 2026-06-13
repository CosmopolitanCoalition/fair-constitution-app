<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Department;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\ExecutiveOrder;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * F-EXE-005 — executive orders with PRE-ISSUANCE scope validation
 * (PHASE_D_DESIGN_executive §D). Three rules, run at FILING time through
 * ConstitutionalValidator::check('F-EXE-005') → preflight():
 *
 *   1. order.enabling_instrument — a live cited instrument (in-force law
 *      binding the executive's jurisdiction / active emergency power
 *      covering it / a charter of an overseen department). Art. III §2.
 *   2. order.scope_containment — the named department is overseen by THIS
 *      executive; emergency widening lives and dies with the power's
 *      declared area and duration. Art. III §2 · Art. II §7.
 *   3. order.civic_process_protection (HARDENED) — electoral / judicial /
 *      legislative process domains are rejected unconditionally.
 *      Art. II §7.
 *
 * REJECTION-ON-RECORD (the Phase D exit criterion): a scope failure is a
 * DOMAIN OUTCOME, not only an exception. preflight() runs OUTSIDE the
 * engine transaction (the validator stage), so on violation it commits
 * the `rejected_pre_issuance` order row + its public_records entry in its
 * own transaction, THEN throws — the engine appends the rejected=true
 * chain entry with the citation and surfaces 422. All four artifacts
 * exist (row, record, chain entry, 422); pinned by
 * OrderScopeValidationTest + the live E2E.
 */
class ExecutiveOrderService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PublicRecordService $records,
    ) {
    }

    // =========================================================================
    // Pre-issuance validation (validator stage — outside the engine txn)
    // =========================================================================

    /**
     * Run the three scope rules against an F-EXE-005 payload. Throws
     * ConstitutionalViolation after persisting the rejection artifacts.
     */
    public function preflight(array $payload): void
    {
        try {
            $this->resolveAndAssert($payload);
        } catch (ConstitutionalViolation $violation) {
            $this->persistRejection($payload, $violation);

            throw $violation;
        }
    }

    /**
     * Resolve the acting context and run every rule (shared by
     * preflight() and the in-transaction issue() re-run — TOCTOU guard).
     *
     * @return array{executive: Executive, member: ExecutiveMember, department: ?Department, instrument: array}
     */
    public function resolveAndAssert(array $payload): array
    {
        // Rule 3 first — the hardened civic-process shield needs no
        // resolution at all (pure; Art. II §7).
        ConstitutionalValidator::assertOrderCivicProcessProtection(
            (string) ($payload['target_domain'] ?? '')
        );

        $executive = Executive::query()->find((string) ($payload['executive_id'] ?? ''));

        if ($executive === null
            || ! in_array($executive->status, [Executive::STATUS_DELEGATED, Executive::STATUS_ELECTED], true)) {
            throw new ConstitutionalViolation(
                'Executive orders issue from a DELEGATED or ELECTED executive only.',
                'Art. III §1'
            );
        }

        $member = ExecutiveMember::query()->find((string) ($payload['issued_by_member_id'] ?? ''));

        if ($member === null
            || (string) $member->executive_id !== (string) $executive->id
            || $member->status !== ExecutiveMember::STATUS_SEATED
            || $member->role !== ExecutiveMember::ROLE_PRINCIPAL) {
            throw new ConstitutionalViolation(
                'F-EXE-005 is issued by a seated PRINCIPAL of this executive (advisors advise).',
                'Art. III §3'
            );
        }

        // Rule 2 — scope containment: the named department is overseen by
        // THIS executive.
        $department = null;

        if (! empty($payload['department_id'])) {
            $department = Department::query()->find((string) $payload['department_id']);

            if ($department === null || (string) $department->executive_id !== (string) $executive->id) {
                throw new ConstitutionalViolation(
                    'The order names a department this executive does not oversee — an order cannot '
                    . 'reach outside the executive\'s jurisdiction and delegated scope.',
                    'Art. III §2'
                );
            }
        }

        // Rule 1 — the enabling instrument exists, is live, and covers
        // the executive's jurisdiction (emergency powers widen scope only
        // within their declared area and duration — checked inside).
        $instrument = EnablingInstruments::assertLive(
            (string) ($payload['enabling_type'] ?? ''),
            (string) ($payload['enabling_id'] ?? ''),
            $executive,
            $department,
        );

        return [
            'executive'  => $executive,
            'member'     => $member,
            'department' => $department,
            'instrument' => $instrument,
        ];
    }

    /**
     * The rejection artifacts (a, b) — committed in their OWN transaction
     * before the violation rethrows (the engine appends artifact (c), the
     * rejected=true chain entry, and (d) the 422 follows).
     */
    public function persistRejection(array $payload, ConstitutionalViolation $violation): ?ExecutiveOrder
    {
        $executive = Executive::query()->find((string) ($payload['executive_id'] ?? ''));
        $member    = ExecutiveMember::query()->find((string) ($payload['issued_by_member_id'] ?? ''));

        // Without a resolvable executive + member the attempt has no
        // institutional anchor — the engine's rejected chain entry is the
        // complete record (no orphan rows).
        if ($executive === null || $member === null || (string) $member->executive_id !== (string) $executive->id) {
            return null;
        }

        $domain = (string) ($payload['target_domain'] ?? 'other');

        $run = function () use ($payload, $violation, $executive, $member, $domain): ExecutiveOrder {
            $order = ExecutiveOrder::create([
                'executive_id'        => (string) $executive->id,
                'issued_by_member_id' => (string) $member->id,
                'department_id'       => ! empty($payload['department_id']) ? (string) $payload['department_id'] : null,
                'title'               => (string) ($payload['title'] ?? 'Untitled order'),
                'body'                => (string) ($payload['body'] ?? ''),
                'enabling_type'       => (string) ($payload['enabling_type'] ?? 'law'),
                'enabling_id'         => (string) ($payload['enabling_id'] ?? $executive->id),
                'target_domain'       => $domain !== '' ? $domain : 'other',
                'status'              => ExecutiveOrder::STATUS_REJECTED_PRE_ISSUANCE,
                'rejection_citation'  => $violation->citation,
                'rejection_reason'    => $violation->getMessage(),
            ]);

            $record = $this->records->publish(
                kind: 'other',
                title: "Executive order rejected pre-issuance — {$violation->citation}",
                body: sprintf(
                    "Order \"%s\" was rejected before issuance: %s (%s). The attempt is the record.",
                    (string) ($payload['title'] ?? 'Untitled order'),
                    $violation->getMessage(),
                    $violation->citation
                ),
                attrs: [
                    'actor_user_id'   => $member->user_id !== null ? (string) $member->user_id : null,
                    'jurisdiction_id' => (string) $executive->jurisdiction_id,
                    'via_form'        => 'F-EXE-005',
                    'subject_type'    => 'executive_orders',
                    'subject_id'      => (string) $order->id,
                ],
            );

            $order->forceFill(['record_id' => (string) $record->id])->save();

            return $order;
        };

        // The validator stage runs OUTSIDE the engine transaction; commit
        // immediately so the artifacts survive the rethrow. (Inside a
        // caller-supplied transaction the caller owns the commit.)
        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    // =========================================================================
    // Issuance (inside the engine transaction)
    // =========================================================================

    /**
     * Issue the order: re-run every rule (TOCTOU guard — the validator
     * stage already passed), allocate EO-YYYY-NN, persist + publish.
     */
    public function issue(array $payload): ExecutiveOrder
    {
        $context = $this->resolveAndAssert($payload);

        $executive  = $context['executive'];
        $member     = $context['member'];
        $department = $context['department'];

        $orderNo = $this->allocateOrderNo((string) $executive->id);

        $order = ExecutiveOrder::create([
            'executive_id'        => (string) $executive->id,
            'issued_by_member_id' => (string) $member->id,
            'department_id'       => $department?->id,
            'order_no'            => $orderNo,
            'title'               => (string) $payload['title'],
            'body'                => (string) $payload['body'],
            'enabling_type'       => (string) $payload['enabling_type'],
            'enabling_id'         => (string) $payload['enabling_id'],
            'target_domain'       => (string) $payload['target_domain'],
            'status'              => ExecutiveOrder::STATUS_ISSUED,
            'issued_at'           => now(),
        ]);

        $record = $this->records->publish(
            kind: 'act',
            title: "{$orderNo} — {$order->title}",
            body: $order->body,
            attrs: [
                'actor_user_id'   => $member->user_id !== null ? (string) $member->user_id : null,
                'jurisdiction_id' => (string) $executive->jurisdiction_id,
                'via_form'        => 'F-EXE-005',
                'subject_type'    => 'executive_orders',
                'subject_id'      => (string) $order->id,
            ],
        );

        $order->forceFill(['record_id' => (string) $record->id])->save();

        $this->audit->append(
            module: 'executive',
            event: 'order.issued',
            payload: [
                'order_id'      => (string) $order->id,
                'order_no'      => $orderNo,
                'executive_id'  => (string) $executive->id,
                'department_id' => $department?->id !== null ? (string) $department->id : null,
                'target_domain' => $order->target_domain,
                'enabling'      => $context['instrument'],
            ],
            ref: 'F-EXE-005',
            actorId: $member->user_id !== null ? (string) $member->user_id : null,
            jurisdictionId: (string) $executive->jurisdiction_id,
        );

        return $order;
    }

    /**
     * Revoke an issued order (by the issuing executive — scope-checked
     * like issuance: a seated principal of the SAME executive).
     */
    public function revoke(ExecutiveOrder $order, ExecutiveMember $member, ?string $reason = null): ExecutiveOrder
    {
        if ($order->status !== ExecutiveOrder::STATUS_ISSUED) {
            throw new ConstitutionalViolation(
                "Only an issued order can be revoked (status: {$order->status}).",
                'Art. III §2'
            );
        }

        if ((string) $member->executive_id !== (string) $order->executive_id
            || $member->status !== ExecutiveMember::STATUS_SEATED
            || $member->role !== ExecutiveMember::ROLE_PRINCIPAL) {
            throw new ConstitutionalViolation(
                'Revocation is an act of the ISSUING executive\'s seated principals.',
                'Art. III §2'
            );
        }

        $order->forceFill(['status' => ExecutiveOrder::STATUS_REVOKED])->save();

        $this->records->publish(
            kind: 'act',
            title: "{$order->order_no} revoked",
            body: $reason,
            attrs: [
                'actor_user_id'   => $member->user_id !== null ? (string) $member->user_id : null,
                'jurisdiction_id' => (string) $order->executive()->value('jurisdiction_id'),
                'via_form'        => 'F-EXE-005',
                'subject_type'    => 'executive_orders',
                'subject_id'      => (string) $order->id,
            ],
        );

        return $order;
    }

    /** EO-YYYY-NN per executive per year (advisory-locked, B-pattern). */
    private function allocateOrderNo(string $executiveId): string
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('executive_order_no:' || ?))", [$executiveId]);

        $year = now()->year;

        $taken = ExecutiveOrder::query()
            ->where('executive_id', $executiveId)
            ->where('order_no', 'like', "EO-{$year}-%")
            ->withTrashed()
            ->count();

        return sprintf('EO-%d-%02d', $year, $taken + 1);
    }
}
