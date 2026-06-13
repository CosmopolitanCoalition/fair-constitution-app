<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Services\AuditService;
use App\Services\ConstituentResolver;
use App\Services\ConstitutionalValidator;
use App\Services\ElectionLifecycleService;
use App\Services\EnactmentService;
use App\Services\MultiJurisdictionVoteService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * Judiciary formation (PHASE_E_DESIGN_judiciary §B) — appointed creation
 * (F-LEG-017, WF-JUD-01), the seat-pool allocation under the two Art. IV §2
 * nomination paths (mode DERIVED from constituent presence), and conversion
 * to an elected court (F-LEG-018, WF-JUD-02 — mirroring the executive
 * F-LEG-015 conversion EXACTLY, reusing the generic constituent-consent
 * substrate). The ONLY judiciary-specific conversion work is the
 * subject-effect branch in onProcessEvaluated keyed on subject_type
 * 'judiciaries'.
 *
 * Sibling of ExecutiveFormationService, same package shape.
 */
class JudiciaryFormationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly MultiJurisdictionVoteService $processes,
        private readonly RoleService $roles,
    ) {}

    // =========================================================================
    // Pure constitutional asserts (DB-free — pinned by the test suite)
    // =========================================================================

    /**
     * Art. IV §1/§2 — the creation-act seat-pool shape. The nomination mode
     * is DERIVED ($hasConstituents), never an input. The resulting
     * judge_count must be ≥ min_judges (the seat-pool floor).
     */
    public static function assertCreationShape(
        bool $hasConstituents,
        int $minJudges,
        ?int $judgesPerConstituent,
        ?int $committeeJudgeCount,
    ): void {
        if ($hasConstituents) {
            // Constituent path: equal number per constituent (Art. IV §2).
            if ($judgesPerConstituent !== null && $judgesPerConstituent < 1) {
                throw new ConstitutionalViolation(
                    'Each constituent nominates at least one judge (Art. IV §2 — an equal number by each).',
                    'Art. IV §2'
                );
            }

            return; // judge_count = per × constituents, resolved at adoption
        }

        // Committee path: the act states the bench size (≥ min_judges).
        if ($committeeJudgeCount === null || $committeeJudgeCount < $minJudges) {
            throw new ConstitutionalViolation(
                sprintf(
                    'A committee-nominated court states a bench of at least %d judges (Art. IV §1 floor).',
                    $minJudges
                ),
                'Art. IV §1'
            );
        }
    }

    /**
     * Art. IV §1 — the conversion target: an elected judicial race floors at
     * judiciary_min_judges_per_race (default 5), no ceiling.
     */
    public static function assertConversionTarget(int $judgeCount, int $minJudges): void
    {
        if ($judgeCount < $minJudges) {
            throw new ConstitutionalViolation(
                sprintf(
                    'An elected judiciary elects at least %d judges per race (Art. IV §1 floors the '
                    .'judicial race at the minimum; there is no ceiling).',
                    $minJudges
                ),
                'Art. IV §1'
            );
        }
    }

    /** Constituents = direct child jurisdictions holding a legislature. */
    public static function constituentJurisdictionIds(Legislature $legislature): array
    {
        return ConstituentResolver::ids($legislature);
    }

    // =========================================================================
    // F-LEG-017 — creation adoption (WF-JUD-01)
    // =========================================================================

    /**
     * Adoption effect (votable-effect dispatch, same txn): the charter law,
     * the DERIVED nomination mode, the vacant seat pool, judiciary
     * forming → creating. It stays `creating` until the bench seats
     * (§B.4 — appointed only when all seats consent).
     *
     * @return array{0: string, 1: string} [result_type, result_id]
     */
    public function applyCreation(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload = (array) $proposal->payload;

        $judiciary = Judiciary::query()->whereKey((string) $payload['judiciary_id'])->firstOrFail();
        $minJudges = (int) $judiciary->min_judges;

        $constituents = ConstituentResolver::ids($legislature);

        $judgesPerConstituent = isset($payload['judges_per_constituent']) && $payload['judges_per_constituent'] !== null
            ? (int) $payload['judges_per_constituent']
            : 1;
        $committeeJudgeCount = isset($payload['committee_judge_count']) && $payload['committee_judge_count'] !== null
            ? (int) $payload['committee_judge_count']
            : null;

        // Re-assert the shape at adoption (the validator stage already ran;
        // the enactment-time re-run is the F-LEG-026 precedent).
        self::assertCreationShape($constituents !== [], $minJudges, $judgesPerConstituent, $committeeJudgeCount);

        $law = $this->enactments->enactDirect(
            $legislature,
            'charter',
            'Judiciary Creation Act',
            (string) $payload['function_text'],
            $vote,
        );

        // enactDirect writes scope_judiciary_id=null; scope it to the new
        // court so judicial-remedy versioning can later attach (§F).
        $law->forceFill(['scope_judiciary_id' => (string) $judiciary->id])->save();

        // ── Nomination mode resolution (Art. IV §2 — the constitution
        //    decides, not the act) ───────────────────────────────────────────
        if ($constituents !== []) {
            $mode = Judiciary::NOMINATION_CONSTITUENT;
            $seatClass = JudicialSeat::CLASS_CONSTITUENT_NOMINATED;
            $judgeCount = $judgesPerConstituent * count($constituents);
        } else {
            $mode = Judiciary::NOMINATION_COMMITTEE;
            $seatClass = JudicialSeat::CLASS_COMMITTEE_NOMINATED;
            $judgeCount = (int) $committeeJudgeCount;
        }

        if ($judgeCount < $minJudges) {
            throw new ConstitutionalViolation(
                sprintf('The chartered bench (%d) is below the minimum of %d judges.', $judgeCount, $minJudges),
                'Art. IV §1'
            );
        }

        // ── Allocate the vacant seat pool. Constituent path: round-robin so
        //    the count is PROVABLY equal per constituent (the §B.2 invariant) ─
        $seatNo = 1;
        for ($i = 0; $i < $judgeCount; $i++) {
            $nominatingJurisdiction = $mode === Judiciary::NOMINATION_CONSTITUENT
                ? $constituents[$i % count($constituents)]
                : null;

            JudicialSeat::create([
                'judiciary_id' => (string) $judiciary->id,
                'seat_number' => $seatNo++,
                'seat_class' => $seatClass,
                'nominating_jurisdiction_id' => $nominatingJurisdiction,
                'status' => JudicialSeat::STATUS_VACANT,
            ]);
        }

        // The equal-constituent invariant must hold at allocation (Art. IV §2).
        if ($mode === Judiciary::NOMINATION_CONSTITUENT) {
            ConstitutionalValidator::assertEqualConstituentNomination(
                $this->seatCountsByConstituent($judiciary)
            );
        }

        $judiciary->forceFill([
            'status' => Judiciary::STATUS_CREATING,
            'type' => Judiciary::TYPE_APPOINTED,
            'nomination_mode' => $mode,
            'judge_count' => $judgeCount,
            'court_name' => (string) $payload['court_name'],
            'creation_law_id' => (string) $law->id,
            'source_legislature_id' => (string) $legislature->id,
        ])->save();

        $this->audit->append(
            module: 'judiciary',
            event: 'judiciary.created',
            payload: [
                'judiciary_id' => (string) $judiciary->id,
                'law_id' => (string) $law->id,
                'nomination_mode' => $mode,
                'judge_count' => $judgeCount,
                'constituents' => $constituents,
            ],
            ref: 'F-LEG-017',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );

        $this->records->publish(
            kind: 'act',
            title: 'Appointed judiciary created (creating — bench awaiting consent)',
            body: sprintf(
                'Judiciary %s created by supermajority act %s (Art. IV §1). %d %s seat(s) allocated; '
                .'mode: %s (Art. IV §2). The court is appointed once every seat is consented.',
                (string) $judiciary->id,
                $law->act_number,
                $judgeCount,
                $mode,
                $mode === Judiciary::NOMINATION_CONSTITUENT
                    ? 'equal per constituent'
                    : 'judicial committee',
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-017',
                'subject_type' => 'judiciaries',
                'subject_id' => (string) $judiciary->id,
            ],
        );

        $this->roles->flush();

        return ['judiciaries', (string) $judiciary->id];
    }

    /**
     * Seat-count by constituent jurisdiction (the equal-count audit input).
     *
     * @return array<string, int> nominating_jurisdiction_id => seat count
     */
    public function seatCountsByConstituent(Judiciary $judiciary): array
    {
        return JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('seat_class', JudicialSeat::CLASS_CONSTITUENT_NOMINATED)
            ->whereIn('status', [
                JudicialSeat::STATUS_VACANT,
                JudicialSeat::STATUS_NOMINATED,
                JudicialSeat::STATUS_SEATED,
            ])
            ->whereNotNull('nominating_jurisdiction_id')
            ->selectRaw('nominating_jurisdiction_id, count(*) as n')
            ->groupBy('nominating_jurisdiction_id')
            ->pluck('n', 'nominating_jurisdiction_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    // =========================================================================
    // F-LEG-018 — conversion adoption + the constituent dual leg (WF-JUD-02)
    // =========================================================================

    /**
     * Adoption effect: the conversion charter law; constituents resolved
     * (the shared ConstituentResolver); none ⇒ the process is decided
     * immediately (Art. IV §3 "if composed of constituent jurisdictions")
     * and the judicial election schedules; else the MultiJurisdictionVote
     * process opens. Byte-for-byte the executive applyConversionAdoption.
     *
     * @return array{0: string, 1: string} [result_type, result_id]
     */
    public function applyConversionAdoption(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload = (array) $proposal->payload;

        $judiciary = Judiciary::query()->whereKey((string) $payload['judiciary_id'])->firstOrFail();

        self::assertConversionTarget((int) $payload['judge_count'], (int) $judiciary->min_judges);

        $law = $this->enactments->enactDirect(
            $legislature,
            'charter',
            'Judiciary Conversion Act',
            (string) $payload['charter_text'],
            $vote,
        );

        $law->forceFill(['scope_judiciary_id' => (string) $judiciary->id])->save();

        $judiciary->forceFill(['conversion_law_id' => (string) $law->id])->save();

        $constituents = ConstituentResolver::ids($legislature);

        if ($constituents === []) {
            // No constituents: the chamber supermajority alone decides
            // (Art. IV §3) — straight to the judicial election.
            $judiciary->forceFill(['status' => Judiciary::STATUS_CONVERSION_VOTED])->save();

            $this->records->publish(
                kind: 'act',
                title: 'Judiciary conversion adopted — no constituents to consent',
                body: sprintf(
                    'Act %s: no direct constituent jurisdiction holds a legislature able to vote; '
                    .'the conversion completes on the chamber supermajority alone (Art. IV §3).',
                    $law->act_number
                ),
                attrs: [
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'legislature_id' => (string) $legislature->id,
                    'via_form' => 'F-LEG-018',
                    'subject_type' => 'judiciaries',
                    'subject_id' => (string) $judiciary->id,
                ],
            );

            $this->scheduleConversionElection($judiciary, $legislature, $payload);

            return ['judiciaries', (string) $judiciary->id];
        }

        $process = $this->processes->open(
            'judiciary_convert',
            $legislature,
            $constituents,
            MultiJurisdictionVote::BASIS_SUPERMAJORITY,
            $vote,
            'judiciaries',
            (string) $judiciary->id,
        );

        $judiciary->forceFill([
            'status' => Judiciary::STATUS_CONVERSION_VOTED,
            'conversion_process_id' => (string) $process->id,
        ])->save();

        $childless = ConstituentResolver::childlessNames($legislature);

        $this->records->publish(
            kind: 'act',
            title: 'Judiciary conversion adopted — constituent consent requested',
            body: sprintf(
                'Act %s: dual-supermajority process %s opened across %d constituent legislature(s); '
                .'required: %d (Art. IV §3 · Art. VII).%s',
                $law->act_number,
                (string) $process->id,
                count($constituents),
                (int) $process->required,
                $childless === [] ? '' : sprintf(
                    ' %d direct child jurisdiction(s) hold no legislature and cannot consent: %s.',
                    count($childless),
                    implode(', ', $childless)
                )
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-018',
                'subject_type' => 'multi_jurisdiction_votes',
                'subject_id' => (string) $process->id,
            ],
        );

        return ['multi_jurisdiction_votes', (string) $process->id];
    }

    /**
     * Process decided → subject effect (subject 'judiciaries'). passed ⇒
     * schedule the judicial election; failed/expired ⇒ the court reverts to
     * its appointed footing (the appointed bench keeps sitting — the act
     * stands as a record, nothing re-seats). The executive falls back to
     * delegated/forming; the judiciary falls back to appointed.
     */
    public function onProcessEvaluated(MultiJurisdictionVote $process): void
    {
        if ($process->subject_type !== 'judiciaries' || $process->kind !== 'judiciary_convert') {
            return;
        }

        if ($process->status === MultiJurisdictionVote::STATUS_OPEN) {
            return;
        }

        $judiciary = Judiciary::query()->findOrFail((string) $process->subject_id);
        $legislature = Legislature::query()->findOrFail((string) $process->initiating_legislature_id);

        if ($process->status === MultiJurisdictionVote::STATUS_PASSED) {
            $payload = $this->conversionPayloadFor($process);

            $this->scheduleConversionElection($judiciary, $legislature, $payload);

            return;
        }

        // Failed/expired: revert to the appointed footing (the resting prior
        // state — the appointed bench is untouched, the act stands as record).
        $judiciary->forceFill(['status' => Judiciary::STATUS_REVERTED])->save();

        $this->records->publish(
            kind: 'act',
            title: 'Judiciary conversion failed at constituent consent',
            body: sprintf(
                'Process %s closed %s (%d yes / %d no of %d; required %d) — the court keeps its '
                .'appointed footing; the seated bench is untouched (Art. IV §3 · Art. VII).',
                (string) $process->id,
                $process->status,
                (int) $process->yes_count,
                (int) $process->no_count,
                (int) $process->constituent_total,
                (int) $process->required
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-018',
                'subject_type' => 'judiciaries',
                'subject_id' => (string) $judiciary->id,
            ],
        );
    }

    // =========================================================================
    // Election scheduling
    // =========================================================================

    /**
     * Process passed → the judicial election (§B.5): kind `judicial`,
     * lockstep-anchored to the legislature, ONE race seat_kind
     * `judicial_group`, seats = the act's judge_count (≥ min_judges),
     * counted by the UNTOUCHED countStv.
     */
    public function scheduleConversionElection(Judiciary $judiciary, Legislature $legislature, array $payload): void
    {
        $election = app(ElectionLifecycleService::class)->scheduleJudicial(
            $judiciary,
            $legislature,
            (int) $payload['judge_count'],
        );

        $this->audit->append(
            module: 'judiciary',
            event: 'judiciary.election_scheduled',
            payload: [
                'judiciary_id' => (string) $judiciary->id,
                'election_id' => (string) $election->id,
                'seats' => (int) $payload['judge_count'],
            ],
            ref: 'F-LEG-018',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );
    }

    /**
     * Resolve the adopted F-LEG-018 payload through the process's initiating
     * chamber vote (the proposal stores judge_count / charter_text).
     */
    private function conversionPayloadFor(MultiJurisdictionVote $process): array
    {
        $proposal = $process->initiating_vote_id !== null
            ? ChamberVoteProposal::query()->where('vote_id', (string) $process->initiating_vote_id)->first()
            : null;

        if ($proposal === null) {
            throw new ConstitutionalViolation(
                'The conversion process has no resolvable F-LEG-018 act payload.',
                'Art. IV §3 · as implemented'
            );
        }

        return (array) $proposal->payload;
    }
}
