<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ConstituentConsent;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use Illuminate\Support\Facades\DB;

/**
 * C-5 wiring (PHASE_C_DESIGN_votes_laws §A) — the MINIMAL Phase C surface
 * of the dual-supermajority substrate: open / recordConsent / evaluate.
 * Consent rows update counters; evaluate flips status. No form consumes
 * this until F-LEG-015 (Phase D) — the schema is the expensive-to-retrofit
 * part; Phase D conversions and Art. VII additional-articles ride it.
 *
 * `required` snapshots through the PROTECTED functions only:
 * supermajority ⇒ ConstitutionalValidator::supermajority(total);
 * unanimity ⇒ total.
 */
class MultiJurisdictionVoteService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param  list<string>  $constituentJurisdictionIds
     */
    public function open(
        string $kind,
        Legislature $initiating,
        array $constituentJurisdictionIds,
        string $basis = MultiJurisdictionVote::BASIS_SUPERMAJORITY,
        ?ChamberVote $initiatingVote = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): MultiJurisdictionVote {
        if (! in_array($kind, MultiJurisdictionVote::KINDS, true)) {
            throw new ConstitutionalViolation("Unknown multi-jurisdiction process kind [{$kind}].", 'Art. VII · as implemented');
        }

        $total = count($constituentJurisdictionIds);

        if ($total < 1) {
            throw new ConstitutionalViolation('A constituent process needs at least one constituent.', 'Art. VII · as implemented');
        }

        $required = $basis === MultiJurisdictionVote::BASIS_UNANIMITY
            ? $total
            : ConstitutionalValidator::supermajority($total);

        return DB::transaction(function () use ($kind, $initiating, $constituentJurisdictionIds, $basis, $initiatingVote, $subjectType, $subjectId, $total, $required) {
            $process = MultiJurisdictionVote::create([
                'kind'                      => $kind,
                'subject_type'              => $subjectType,
                'subject_id'                => $subjectId,
                'initiating_legislature_id' => $initiating->id,
                'initiating_vote_id'        => $initiatingVote?->id,
                'basis'                     => $basis,
                'constituent_total'         => $total,
                'required'                  => $required,
                'status'                    => MultiJurisdictionVote::STATUS_OPEN,
                'opens_at'                  => now(),
            ]);

            foreach ($constituentJurisdictionIds as $jurisdictionId) {
                ConstituentConsent::create([
                    'process_id'      => $process->id,
                    'jurisdiction_id' => $jurisdictionId,
                    'result'          => ConstituentConsent::RESULT_PENDING,
                ]);
            }

            $this->audit->append(
                module: 'legislature',
                event: 'multi_jurisdiction.opened',
                payload: [
                    'process_id'        => $process->id,
                    'kind'              => $kind,
                    'basis'             => $basis,
                    'constituent_total' => $total,
                    'required'          => $required,
                ],
                ref: 'WF-JUR-02',
                jurisdictionId: (string) $initiating->jurisdiction_id,
            );

            return $process;
        });
    }

    /** Record one constituent's decision (their own peg-quorum chamber vote, when held). */
    public function recordConsent(
        MultiJurisdictionVote $process,
        string $jurisdictionId,
        bool $consented,
        ?ChamberVote $chamberVote = null,
        ?string $legislatureId = null,
    ): ConstituentConsent {
        return DB::transaction(function () use ($process, $jurisdictionId, $consented, $chamberVote, $legislatureId) {
            $fresh = MultiJurisdictionVote::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== MultiJurisdictionVote::STATUS_OPEN) {
                throw new ConstitutionalViolation('The constituent process is not open.', 'Art. VII · as implemented');
            }

            $consent = ConstituentConsent::query()
                ->where('process_id', $fresh->id)
                ->where('jurisdiction_id', $jurisdictionId)
                ->firstOrFail();

            if ($consent->result !== ConstituentConsent::RESULT_PENDING) {
                throw new ConstitutionalViolation('This constituent has already decided.', 'Art. VII · as implemented');
            }

            $consent->forceFill([
                'result'          => $consented ? ConstituentConsent::RESULT_YES : ConstituentConsent::RESULT_NO,
                'chamber_vote_id' => $chamberVote?->id,
                'legislature_id'  => $legislatureId,
                'decided_at'      => now(),
            ])->save();

            $fresh->increment($consented ? 'yes_count' : 'no_count');

            $this->evaluate($fresh);

            return $consent;
        });
    }

    /** Flip status when the arithmetic is decided (or stay open). */
    public function evaluate(MultiJurisdictionVote $process): string
    {
        $fresh = MultiJurisdictionVote::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== MultiJurisdictionVote::STATUS_OPEN) {
            return $fresh->status;
        }

        $status = $fresh->status;

        if ($fresh->yes_count >= $fresh->required) {
            $status = MultiJurisdictionVote::STATUS_PASSED;
        } elseif ($fresh->constituent_total - $fresh->no_count < $fresh->required) {
            $status = MultiJurisdictionVote::STATUS_FAILED; // can no longer reach required
        }

        if ($status !== $fresh->status) {
            $fresh->forceFill(['status' => $status, 'closes_at' => now()])->save();

            $this->audit->append(
                module: 'legislature',
                event: 'multi_jurisdiction.' . $status,
                payload: [
                    'process_id' => $fresh->id,
                    'yes'        => $fresh->yes_count,
                    'no'         => $fresh->no_count,
                    'required'   => $fresh->required,
                    'total'      => $fresh->constituent_total,
                ],
                ref: 'WF-JUR-02',
            );
        }

        return $status;
    }
}
