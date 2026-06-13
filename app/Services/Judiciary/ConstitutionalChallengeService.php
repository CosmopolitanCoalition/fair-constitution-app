<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\ConstitutionalChallenge;
use App\Models\ConstitutionalFinding;
use App\Models\CourtCase;
use App\Models\Judiciary;
use App\Models\Law;
use App\Models\RemedyRecommendation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ClockService;
use App\Services\PublicRecordService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ConstitutionalChallengeService (PHASE_E_DESIGN_challenge_law §B) — owns the
 * Art. IV §5 challenge lifecycle and is the ONLY writer of the CLK-11/CLK-12
 * per-case timers.
 *
 *   filed → under_review → finding_issued → remedy_recommended →
 *           legislative_window_open
 *               ├→ amended_by_legislature  (Path 1, onRemedialEnactment)
 *               ├→ overridden              (Path 2, JudiciaryOverrideService)
 *               └→ judicial_remedy_applied (Path 3, JudicialRemedyService)
 *           → closed
 *
 * The §5 windows run for weeks-to-months AFTER the hearing closes, so they live
 * on the challenge row, not the case. ConstitutionalChallengeService.file()
 * runs inside the engine transaction; the clock arming (B.7) is the
 * load-bearing detail — CLK-11 is armed to max(veto_closes_at, remedy_due_at)
 * so a single CLK-11 fire occurs only once BOTH §5.5 conditions are met.
 */
class ConstitutionalChallengeService
{
    public function __construct(
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
        private readonly ClockService $clocks,
    ) {}

    // =========================================================================
    // F-IND-016 — challenge filing (§5.1, the absolute-right surface)
    // =========================================================================

    /**
     * Resolve the court for a challenged law / jurisdiction: the law's
     * scope_judiciary_id if it names an operating court, else the active
     * judiciaries row for the jurisdiction, walking ancestors until a
     * non-forming court (or null — the challenge parks at `filed`).
     */
    public function courtFor(Law $law, string $jurisdictionId): ?Judiciary
    {
        if ($law->scope_judiciary_id !== null) {
            $scoped = Judiciary::query()->find((string) $law->scope_judiciary_id);

            if ($scoped !== null && in_array($scoped->status, Judiciary::OPERATING_STATUSES, true)) {
                return $scoped;
            }
        }

        $current = $jurisdictionId;

        for ($depth = 0; $depth < 32 && $current !== null; $depth++) {
            $court = Judiciary::query()
                ->where('jurisdiction_id', $current)
                ->whereNull('deleted_at')
                ->whereIn('status', Judiciary::OPERATING_STATUSES)
                ->first();

            if ($court !== null) {
                return $court;
            }

            $current = DB::table('jurisdictions')->where('id', $current)->value('parent_id');
            $current = $current !== null ? (string) $current : null;
        }

        return null;
    }

    /**
     * F-IND-016 (§5.1): create the challenge in `filed`. Standing is
     * association-only (R-03 gated upstream by the engine); no merits test,
     * no fee, no eligibility ground (Art. I). When no court is seated the
     * filing is ACCEPTED and parks at `filed` — the right to file is absolute
     * even when the judiciary is forming; the challenge waits, never rejected.
     *
     * @param  array{challenged_law_id:string, claim_text:string,
     *     claimed_basis:string, cited_authority_law_id?:?string,
     *     constitutional_citation?:?string, jurisdiction_id:string}  $attrs
     */
    public function file(User $filer, array $attrs): ConstitutionalChallenge
    {
        $law = Law::query()->find((string) ($attrs['challenged_law_id'] ?? ''));

        if ($law === null) {
            throw new ConstitutionalViolation(
                'F-IND-016 names the law it challenges (challenged_law_id).',
                'Art. IV §5'
            );
        }

        // A repealed / struck / superseded law cannot be challenged — there is
        // nothing left to remedy (the law no longer binds).
        if (! in_array($law->status, [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED], true)) {
            throw new ConstitutionalViolation(
                "Act {$law->act_number} is {$law->status} — only an in-force or amended law can be challenged "
                .'(there is nothing to remedy in a repealed or struck law).',
                'Art. IV §5'
            );
        }

        $jurisdictionId = (string) ($attrs['jurisdiction_id'] ?? $law->jurisdiction_id);

        // The challenge jurisdiction must be the law's binding jurisdiction or
        // a descendant under it.
        if (! $this->jurisdictionInSubtree($jurisdictionId, (string) $law->jurisdiction_id)) {
            throw new ConstitutionalViolation(
                'A challenge is filed in the law\'s binding jurisdiction or a descendant under it.',
                'Art. IV §5'
            );
        }

        $claimText = trim((string) ($attrs['claim_text'] ?? ''));

        if ($claimText === '') {
            throw new ConstitutionalViolation(
                'F-IND-016 records the asserted contradiction (claim_text).',
                'Art. IV §5'
            );
        }

        $basis = (string) ($attrs['claimed_basis'] ?? '');

        if (! in_array($basis, [ConstitutionalChallenge::BASIS_CONSTITUTION, ConstitutionalChallenge::BASIS_OTHER_LAW], true)) {
            throw new ConstitutionalViolation(
                'A challenge alleges contradiction against the Constitution or another law (claimed_basis).',
                'Art. IV §5'
            );
        }

        $court = $this->courtFor($law, $jurisdictionId);

        $challenge = ConstitutionalChallenge::create([
            'jurisdiction_id' => $jurisdictionId,
            // No court yet → park against the law's scope court placeholder is
            // impossible (judiciary_id is NOT NULL); a parked challenge still
            // needs a court row. When no operating court exists we still record
            // the nearest forming court if any, else reject only the SEATING,
            // never the filing — resolved below.
            'judiciary_id' => $court !== null
                ? (string) $court->id
                : (string) $this->nearestAnyCourt($law, $jurisdictionId)->id,
            'challenged_law_id' => (string) $law->id,
            'challenged_version_no' => (int) $law->current_version_no,
            'filed_by_user_id' => (string) $filer->getKey(),
            'claim_text' => $claimText,
            'claimed_basis' => $basis,
            'cited_authority_law_id' => isset($attrs['cited_authority_law_id']) ? (string) $attrs['cited_authority_law_id'] : null,
            'constitutional_citation' => isset($attrs['constitutional_citation']) ? (string) $attrs['constitutional_citation'] : null,
            'status' => ConstitutionalChallenge::STATUS_FILED,
            'filed_at' => now(),
        ]);

        $record = $this->records->publish(
            kind: 'testimony', // a citizen claim on the record (§5.1)
            title: sprintf('Constitutional challenge filed — Act %s', $law->act_number),
            body: $claimText,
            attrs: [
                'jurisdiction_id' => $jurisdictionId,
                'via_form' => 'F-IND-016',
                'subject_type' => 'constitutional_challenges',
                'subject_id' => (string) $challenge->id,
            ],
        );

        $challenge->forceFill(['record_id' => (string) $record->id])->save();

        $this->audit->append(
            module: 'judiciary',
            event: 'challenge.filed',
            payload: [
                'challenge_id' => (string) $challenge->id,
                'challenged_law' => $law->act_number,
                'claimed_basis' => $basis,
                'court_seated' => $court !== null,
            ],
            ref: 'F-IND-016',
            actorId: (string) $filer->getKey(),
            jurisdictionId: $jurisdictionId,
        );

        return $challenge;
    }

    /**
     * The hearing seam (B.1 step 5): open a `cases` row for the challenge and
     * move it to `under_review`. The court accepts/panels via F-JDG-001 (the
     * cases lifecycle) — I consume the case_id. Idempotent.
     */
    public function openHearing(ConstitutionalChallenge $challenge): CourtCase
    {
        $fresh = ConstitutionalChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();

        if ($fresh->case_id !== null) {
            return CourtCase::query()->findOrFail((string) $fresh->case_id);
        }

        if ($fresh->status !== ConstitutionalChallenge::STATUS_FILED) {
            throw new ConstitutionalViolation(
                "Only a filed challenge opens a hearing (status: {$fresh->status}).",
                'Art. IV §5'
            );
        }

        $law = $fresh->challengedLaw()->firstOrFail();

        $case = app(CaseService::class)->open([
            'judiciary_id' => (string) $fresh->judiciary_id,
            'jurisdiction_id' => (string) $fresh->jurisdiction_id,
            'kind' => CourtCase::KIND_CONSTITUTIONAL,
            'title' => sprintf('Constitutional challenge — Act %s', $law->act_number),
            'statement_of_claim' => $fresh->claim_text,
            'filed_via_form' => 'F-IND-016',
            'filed_by_user_id' => (string) $fresh->filed_by_user_id,
        ]);

        $fresh->forceFill([
            'case_id' => (string) $case->id,
            'status' => ConstitutionalChallenge::STATUS_UNDER_REVIEW,
            'heard_at' => now(),
        ])->save();

        $this->audit->append(
            module: 'judiciary',
            event: 'challenge.under_review',
            payload: ['challenge_id' => (string) $fresh->id, 'case_id' => (string) $case->id],
            ref: 'F-IND-016',
            jurisdictionId: (string) $fresh->jurisdiction_id,
        );

        return $case;
    }

    // =========================================================================
    // F-JDG-004 — the finding (§5.2 first half)
    // =========================================================================

    /**
     * Write the finding row. finds_contradiction=false ⇒ dismiss the challenge
     * (terminal; no remedy, no clocks). true ⇒ finding_issued (awaits F-JDG-005).
     *
     * @param  array{finds_contradiction:bool, contradiction_against:string,
     *     superior_authority_law_id?:?string, constitutional_citation?:?string,
     *     offending_law_id:string, offending_version_no?:?int, opinion_text:string,
     *     full_court?:bool, panel_snapshot?:array}  $attrs
     */
    public function recordFinding(ConstitutionalChallenge $challenge, array $attrs): ConstitutionalFinding
    {
        $fresh = ConstitutionalChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== ConstitutionalChallenge::STATUS_UNDER_REVIEW) {
            throw new ConstitutionalViolation(
                "A finding is recorded on a challenge under review (status: {$fresh->status}).",
                'Art. IV §5'
            );
        }

        $offendingLaw = Law::query()->find((string) ($attrs['offending_law_id'] ?? $fresh->challenged_law_id));

        if ($offendingLaw === null) {
            throw new ConstitutionalViolation('A finding names the offending law (Art. IV §5.2).', 'Art. IV §5');
        }

        $findsContradiction = (bool) ($attrs['finds_contradiction'] ?? false);
        $opinionText = trim((string) ($attrs['opinion_text'] ?? ''));

        if ($opinionText === '') {
            throw new ConstitutionalViolation('A finding carries its reasoning (opinion_text).', 'Art. IV §5');
        }

        $against = (string) ($attrs['contradiction_against'] ?? $fresh->claimed_basis);

        $finding = ConstitutionalFinding::create([
            'challenge_id' => (string) $fresh->id,
            'judiciary_id' => (string) $fresh->judiciary_id,
            'case_id' => $fresh->case_id !== null ? (string) $fresh->case_id : null,
            'full_court' => (bool) ($attrs['full_court'] ?? false),
            'finds_contradiction' => $findsContradiction,
            'contradiction_against' => $against,
            'superior_authority_law_id' => isset($attrs['superior_authority_law_id']) ? (string) $attrs['superior_authority_law_id'] : null,
            'constitutional_citation' => isset($attrs['constitutional_citation']) ? (string) $attrs['constitutional_citation'] : null,
            'offending_law_id' => (string) $offendingLaw->id,
            'offending_version_no' => (int) ($attrs['offending_version_no'] ?? $offendingLaw->current_version_no),
            'opinion_text' => $opinionText,
            'panel_snapshot' => $attrs['panel_snapshot'] ?? [],
            'issued_at' => now(),
        ]);

        $record = $this->records->publish(
            kind: 'opinion',
            title: sprintf(
                'Constitutional finding — Act %s: %s',
                $offendingLaw->act_number,
                $findsContradiction ? 'contradiction found' : 'no contradiction (dismissed)'
            ),
            body: $opinionText,
            attrs: [
                'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                'via_form' => 'F-JDG-004',
                'subject_type' => 'constitutional_challenges',
                'subject_id' => (string) $fresh->id,
            ],
        );

        $finding->forceFill(['record_id' => (string) $record->id])->save();

        if (! $findsContradiction) {
            $fresh->forceFill([
                'finding_id' => (string) $finding->id,
                'status' => ConstitutionalChallenge::STATUS_DISMISSED,
                'finding_at' => now(),
                'resolution_path' => ConstitutionalChallenge::PATH_DISMISSED,
                'resolution_ref_type' => 'public_records',
                'resolution_ref_id' => (string) $record->id,
                'closed_at' => now(),
            ])->save();

            $this->maybeCloseCase($fresh);

            $this->audit->append(
                module: 'judiciary',
                event: 'challenge.dismissed',
                payload: ['challenge_id' => (string) $fresh->id, 'finding_id' => (string) $finding->id],
                ref: 'F-JDG-004',
                jurisdictionId: (string) $fresh->jurisdiction_id,
            );

            return $finding;
        }

        $fresh->forceFill([
            'finding_id' => (string) $finding->id,
            'status' => ConstitutionalChallenge::STATUS_FINDING_ISSUED,
            'finding_at' => now(),
        ])->save();

        $this->audit->append(
            module: 'judiciary',
            event: 'challenge.finding_issued',
            payload: [
                'challenge_id' => (string) $fresh->id,
                'finding_id' => (string) $finding->id,
                'offending_law' => $offendingLaw->act_number,
                'against' => $against,
            ],
            ref: 'F-JDG-004',
            jurisdictionId: (string) $fresh->jurisdiction_id,
        );

        return $finding;
    }

    // =========================================================================
    // F-JDG-005 — remedy recommendation + the per-case clock arming (§5.2/3/4)
    // =========================================================================

    /**
     * Write the recommendation and ARM CLK-11 + CLK-12 (B.7). The judge sets
     * both windows; the engine arms the clocks. CLK-12 fires at remedy_due_at;
     * CLK-11 fires at max(veto_closes_at, remedy_due_at) so Path 3 fires
     * exactly once, only once BOTH §5.5 conditions are met.
     *
     * @param  array{remedy_kind:string, recommended_text?:?string,
     *     rationale_text:string, remedy_timeframe_days:int, veto_window_days:int}  $attrs
     */
    public function recommendRemedy(ConstitutionalChallenge $challenge, array $attrs): RemedyRecommendation
    {
        $fresh = ConstitutionalChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== ConstitutionalChallenge::STATUS_FINDING_ISSUED) {
            throw new ConstitutionalViolation(
                "A remedy is recommended after a contradiction finding (status: {$fresh->status}).",
                'Art. IV §5'
            );
        }

        $finding = ConstitutionalFinding::query()->findOrFail((string) $fresh->finding_id);

        if (! $finding->finds_contradiction) {
            throw new ConstitutionalViolation('No remedy attaches to a finding of no contradiction.', 'Art. IV §5');
        }

        $kind = (string) ($attrs['remedy_kind'] ?? '');

        if (! in_array($kind, [RemedyRecommendation::KIND_MODIFY, RemedyRecommendation::KIND_REMOVE], true)) {
            throw new ConstitutionalViolation('A remedy modifies or removes the offending law (Art. IV §5.3).', 'Art. IV §5');
        }

        $recommendedText = isset($attrs['recommended_text']) ? trim((string) $attrs['recommended_text']) : '';

        if ($kind === RemedyRecommendation::KIND_MODIFY && $recommendedText === '') {
            throw new ConstitutionalViolation('A modify remedy carries the proposed replacement text.', 'Art. IV §5');
        }

        if ($kind === RemedyRecommendation::KIND_REMOVE && $recommendedText !== '') {
            throw new ConstitutionalViolation('A remove remedy carries no replacement text (it repeals).', 'Art. IV §5');
        }

        $rationale = trim((string) ($attrs['rationale_text'] ?? ''));

        if ($rationale === '') {
            throw new ConstitutionalViolation('A remedy records why it makes the law non-contradictory.', 'Art. IV §5');
        }

        $timeframeDays = (int) ($attrs['remedy_timeframe_days'] ?? 0);
        $vetoDays = (int) ($attrs['veto_window_days'] ?? 0);

        if ($timeframeDays <= 0 || $vetoDays <= 0) {
            throw new ConstitutionalViolation(
                'The judge-set remedy timeframe and veto window are both positive durations (Art. IV §5.3/§5.4).',
                'Art. IV §5'
            );
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $remedyDueAt = $issuedAt->addDays($timeframeDays);
        $vetoClosesAt = $issuedAt->addDays($vetoDays);
        // CLK-11 fires at the LATER of the two deadlines (B.7) so a single fire
        // means BOTH §5.5 conditions are met.
        $clk11FiresAt = $remedyDueAt->greaterThan($vetoClosesAt) ? $remedyDueAt : $vetoClosesAt;

        $recommendation = RemedyRecommendation::create([
            'finding_id' => (string) $finding->id,
            'challenge_id' => (string) $fresh->id,
            'judiciary_id' => (string) $fresh->judiciary_id,
            'remedy_kind' => $kind,
            'recommended_text' => $kind === RemedyRecommendation::KIND_MODIFY ? $recommendedText : null,
            'rationale_text' => $rationale,
            'remedy_timeframe_days' => $timeframeDays,
            'veto_window_days' => $vetoDays,
            'remedy_due_at' => $remedyDueAt,
            'veto_closes_at' => $vetoClosesAt,
            'issued_at' => $issuedAt,
        ]);

        // ── ARM CLK-12 (Legislative Remedy Timeframe) ────────────────────────
        $clk12 = $this->clocks->arm(
            clockId: 'CLK-12',
            jurisdictionId: (string) $fresh->jurisdiction_id,
            subjectType: 'constitutional_challenges',
            subjectId: (string) $fresh->id,
            firesAt: $remedyDueAt,
            payload: ['remedy_id' => (string) $recommendation->id, 'step' => 'remedy_timeframe', 'challenge_id' => (string) $fresh->id],
        );
        $clk12->forceFill(['override_value' => [
            'days' => $timeframeDays,
            'set_by_finding' => (string) $finding->id,
            'fires_workflow' => 'auto-remedy trigger',
        ]])->save();

        // ── ARM CLK-11 (Judicial Veto Window), to max(veto, remedy) ──────────
        $clk11 = $this->clocks->arm(
            clockId: 'CLK-11',
            jurisdictionId: (string) $fresh->jurisdiction_id,
            subjectType: 'constitutional_challenges',
            subjectId: (string) $fresh->id,
            firesAt: $clk11FiresAt,
            payload: ['remedy_id' => (string) $recommendation->id, 'step' => 'veto_window', 'challenge_id' => (string) $fresh->id],
        );
        $clk11->forceFill(['override_value' => [
            'days' => $vetoDays,
            'set_by_finding' => (string) $finding->id,
            'fires_workflow' => 'override deadline',
        ]])->save();

        $recommendation->forceFill([
            'clk11_timer_id' => (string) $clk11->id,
            'clk12_timer_id' => (string) $clk12->id,
        ])->save();

        $offendingLaw = $finding->offendingLaw()->firstOrFail();

        $record = $this->records->publish(
            kind: 'act',
            title: sprintf(
                'Remedy recommended — Act %s: %s within %d days; override window %d days',
                $offendingLaw->act_number,
                $kind,
                $timeframeDays,
                $vetoDays
            ),
            body: $rationale,
            attrs: [
                'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                'via_form' => 'F-JDG-005',
                'subject_type' => 'constitutional_challenges',
                'subject_id' => (string) $fresh->id,
            ],
        );

        $recommendation->forceFill(['record_id' => (string) $record->id])->save();

        // The challenge lands directly at legislative_window_open; the
        // remedy_recommended stamp is the audit transient.
        $fresh->forceFill([
            'remedy_id' => (string) $recommendation->id,
            'status' => ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN,
        ])->save();

        $this->maybeCloseCase($fresh);

        $this->audit->append(
            module: 'judiciary',
            event: 'challenge.remedy_recommended',
            payload: [
                'challenge_id' => (string) $fresh->id,
                'remedy_id' => (string) $recommendation->id,
                'remedy_kind' => $kind,
                'clk11_override' => $clk11->override_value,
                'clk12_override' => $clk12->override_value,
                'remedy_due_at' => $remedyDueAt->toIso8601String(),
                'veto_closes_at' => $vetoClosesAt->toIso8601String(),
                'clk11_fires_at' => $clk11FiresAt->toIso8601String(),
            ],
            ref: 'F-JDG-005',
            jurisdictionId: (string) $fresh->jurisdiction_id,
        );

        return $recommendation;
    }

    // =========================================================================
    // Path 1 — legislature MODIFIES / REMOVES (§5.3)
    // =========================================================================

    /**
     * Post-enactment hook (B.4): a remedial F-LEG-003 bill tagged to this
     * challenge enacted INSIDE the CLK-12 window cancels both timers and closes
     * the challenge `amended_by_legislature`. A bill enacting after CLK-12 fired
     * stands as ordinary legislation but does not re-close an already-remedied
     * challenge (Path 3 already did).
     */
    public function onRemedialEnactment(Bill $bill, Law $law): void
    {
        if ($bill->targets_challenge_id === null) {
            return;
        }

        $challenge = ConstitutionalChallenge::query()
            ->whereKey((string) $bill->targets_challenge_id)
            ->lockForUpdate()
            ->first();

        if ($challenge === null || $challenge->status !== ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN) {
            return; // already resolved (remedied / overridden) or unknown
        }

        $recommendation = RemedyRecommendation::query()->find((string) $challenge->remedy_id);

        if ($recommendation === null) {
            return;
        }

        // §5.3 — the legislature may craft ANY compliant remedy (modify or
        // remove the offending law, or enact a curing act), not only the
        // judge's recommended text. The challenge linkage is the bill's
        // `targets_challenge_id`; the engine closes the challenge on the tagged
        // bill's enactment within the CLK-12 window. (A bill that enacts a
        // fresh curing act and one that amends the offending law in place are
        // both legitimate legislative remedies.)
        if ($recommendation->remedy_due_at !== null && now()->greaterThan($recommendation->remedy_due_at)) {
            return; // too late — the auto-remedy window has closed
        }

        $this->cancelTimers($recommendation, 'subject resolved — legislative amendment (Path 1)');

        $newVersion = $law->current_version_no;

        $record = $this->records->publish(
            kind: 'act',
            title: sprintf('Constitutional challenge resolved by legislative amendment — Act %s v%d', $law->act_number, $newVersion),
            body: 'The legislature modified or removed the offending law within the judiciary\'s remedy '
                .'timeframe (Art. IV §5.3). The challenge is closed.',
            attrs: [
                'jurisdiction_id' => (string) $challenge->jurisdiction_id,
                'via_form' => 'WF-JUD-05',
                'subject_type' => 'constitutional_challenges',
                'subject_id' => (string) $challenge->id,
            ],
        );

        $challenge->forceFill([
            'status' => ConstitutionalChallenge::STATUS_AMENDED_BY_LEGISLATURE,
            'resolution_path' => ConstitutionalChallenge::PATH_LEGISLATIVE_AMENDMENT,
            'resolution_ref_type' => 'law_versions',
            'resolution_ref_id' => $this->latestVersionId($law),
            'closed_at' => now(),
        ])->save();

        // amended_by_legislature → closed (the terminal carries the path).
        $challenge->forceFill(['status' => ConstitutionalChallenge::STATUS_CLOSED])->save();

        $this->audit->append(
            module: 'judiciary',
            event: 'challenge.resolved_legislative',
            payload: [
                'challenge_id' => (string) $challenge->id,
                'law' => $law->act_number,
                'version_no' => $newVersion,
                'bill_id' => (string) $bill->id,
            ],
            ref: 'WF-JUD-05',
            jurisdictionId: (string) $challenge->jurisdiction_id,
        );
    }

    // =========================================================================
    // Path 2 — supermajority OVERRULES (§5.4) — called by JudiciaryOverrideService
    // =========================================================================

    /**
     * Close the challenge `overridden` (Path 2): the finding is overruled, the
     * law stands UNCHANGED (no law_version appended), both timers cancelled.
     */
    public function closeOverridden(ConstitutionalChallenge $challenge, ChamberVote $vote, int $yes, int $required): void
    {
        $fresh = ConstitutionalChallenge::query()->whereKey($challenge->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN) {
            return; // idempotent
        }

        $recommendation = RemedyRecommendation::query()->find((string) $fresh->remedy_id);

        if ($recommendation !== null) {
            $this->cancelTimers($recommendation, 'subject resolved — legislature override (Path 2)');
        }

        $offendingLaw = ConstitutionalFinding::query()->find((string) $fresh->finding_id)?->offendingLaw()->first();

        $record = $this->records->publish(
            kind: 'act',
            title: sprintf(
                'Legislature overruled constitutional finding — Act %s, supermajority %d/%d',
                $offendingLaw?->act_number ?? '?',
                $yes,
                $required
            ),
            body: 'A supermajority of the legislature disagreed with the judiciary and overruled its '
                .'judgement within the judicial veto window (Art. IV §5.4). The offending law stands unchanged.',
            attrs: [
                'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                'via_form' => 'F-LEG-035',
                'subject_type' => 'constitutional_challenges',
                'subject_id' => (string) $fresh->id,
            ],
        );

        $fresh->forceFill([
            'status' => ConstitutionalChallenge::STATUS_OVERRIDDEN,
            'resolution_path' => ConstitutionalChallenge::PATH_LEGISLATURE_OVERRIDE,
            'resolution_ref_type' => 'chamber_votes',
            'resolution_ref_id' => (string) $vote->id,
            'closed_at' => now(),
        ])->save();

        $fresh->forceFill(['status' => ConstitutionalChallenge::STATUS_CLOSED])->save();

        $this->audit->append(
            module: 'judiciary',
            event: 'challenge.overridden',
            payload: ['challenge_id' => (string) $fresh->id, 'vote_id' => (string) $vote->id, 'yes' => $yes, 'required' => $required],
            ref: 'F-LEG-035',
            jurisdictionId: (string) $fresh->jurisdiction_id,
        );
    }

    // =========================================================================
    // Shared internals (consumed by JudicialRemedyService too)
    // =========================================================================

    /** Cancel both armed timers on a recommendation (Path 1/2 resolve early). */
    public function cancelTimers(RemedyRecommendation $recommendation, ?string $reason = null): void
    {
        foreach (['clk11_timer_id', 'clk12_timer_id'] as $field) {
            $timerId = $recommendation->{$field};

            if ($timerId === null) {
                continue;
            }

            $timer = \App\Models\ClockTimer::query()->find((string) $timerId);

            if ($timer !== null && $timer->state === \App\Models\ClockTimer::STATE_ARMED) {
                $this->clocks->cancel($timer, $reason);
            }
        }
    }

    /** Close the challenge's hearing case if it is still open (best-effort). */
    public function maybeCloseCase(ConstitutionalChallenge $challenge): void
    {
        if ($challenge->case_id === null) {
            return;
        }

        $case = CourtCase::query()->find((string) $challenge->case_id);

        if ($case === null || in_array($case->status, CourtCase::TERMINAL_STATUSES, true)) {
            return;
        }

        // The hearing reached an opinion; drive it to closed where the ESM
        // permits (decided → closed). Earlier stages are left to the cases
        // agent; a finding may issue before the formal decided transition.
        if ($case->status === CourtCase::STATUS_DECIDED) {
            app(CaseService::class)->close($case);
        }
    }

    private function latestVersionId(Law $law): ?string
    {
        return DB::table('law_versions')
            ->where('law_id', (string) $law->id)
            ->orderByDesc('version_no')
            ->value('id');
    }

    /** Whether $candidate is $root or a descendant of it (bounded parent walk). */
    private function jurisdictionInSubtree(string $candidate, string $root): bool
    {
        if ($candidate === $root) {
            return true;
        }

        $current = $candidate;

        for ($depth = 0; $depth < 32; $depth++) {
            $parent = DB::table('jurisdictions')->where('id', $current)->value('parent_id');

            if ($parent === null) {
                return false;
            }

            if ((string) $parent === $root) {
                return true;
            }

            $current = (string) $parent;
        }

        return false;
    }

    /**
     * The nearest court row of ANY status (used only to satisfy the NOT NULL
     * judiciary_id when no OPERATING court is seated — the challenge parks at
     * `filed` against the forming court; the right to file is absolute).
     */
    private function nearestAnyCourt(Law $law, string $jurisdictionId): Judiciary
    {
        if ($law->scope_judiciary_id !== null) {
            $scoped = Judiciary::query()->find((string) $law->scope_judiciary_id);

            if ($scoped !== null) {
                return $scoped;
            }
        }

        $current = $jurisdictionId;

        for ($depth = 0; $depth < 32 && $current !== null; $depth++) {
            $court = Judiciary::query()
                ->where('jurisdiction_id', $current)
                ->whereNull('deleted_at')
                ->first();

            if ($court !== null) {
                return $court;
            }

            $parent = DB::table('jurisdictions')->where('id', $current)->value('parent_id');
            $current = $parent !== null ? (string) $parent : null;
        }

        throw new ConstitutionalViolation(
            'No judiciary exists in the law\'s footprint yet — a challenge waits for a court to form, '
            .'but a court row must exist to park against (the jurisdiction has no judiciaries row).',
            'Art. IV §5'
        );
    }
}
