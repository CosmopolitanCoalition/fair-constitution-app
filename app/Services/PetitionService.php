<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Petition;
use App\Models\PetitionSignature;
use App\Models\User;
use App\Support\CivicPopulation;
use Illuminate\Support\Facades\DB;

/**
 * C-P1 (PHASE_C_DESIGN_votes_laws §E) — petitions (ESM-10, Art. II §6
 * "Creation of Laws by Petition").
 *
 * Pipeline: F-IND-009 create (snapshot: civic population basis, resolved
 * initiative_petition_threshold_pct, threshold_count = ceil(basis×pct/100)
 * — CLK-17) → F-IND-010 signatures (revocable; the live count is the
 * event-driven threshold check, the CLK-17 sweep is the safety net) →
 * threshold_reached → F-ELB-005 board signature audit (point-in-time
 * association verification; kill-path → invalidated) →
 * constitutional_review HOLD.
 *
 * CONSTITUTIONAL-REVIEW STUB (explicit, on the record): no judiciary
 * exists (stub rows, status forming). Phase C petitions HOLD at
 * constitutional_review; stubConstitutionalReview() is the audited
 * operator advance — it records `petitions/review.stub_validated` with
 * the deferral citation, marks review_stub = true, validates, and queues
 * the referendum question. When Phase E lands, the stub call site is
 * replaced by a real F-JDG-008 referral; review_stub rows remain honest
 * history. Blocking all petitions on a non-existent institution would let
 * an unbuilt phase veto a live constitutional right — but the kill-path
 * is constitutional, so the DECISION is never auto-skipped.
 */
class PetitionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ConstitutionalValidator $validator,
        private readonly PublicRecordService $records,
        private readonly SettingsResolver $settings,
        private readonly ClockService $clocks,
        private readonly ReferendumService $referendums,
    ) {}

    // =========================================================================
    // PURE threshold math — pinned by the constitutional suite
    // =========================================================================

    /** threshold_count = ceil(civic population × pct / 100) — CLK-17. */
    public static function thresholdCount(int $populationBasis, float $thresholdPct): int
    {
        return (int) ceil($populationBasis * $thresholdPct / 100);
    }

    // =========================================================================
    // F-IND-009 — create
    // =========================================================================

    /**
     * @param  array{jurisdiction_id: string, title: string, law_text: string,
     *               act_type: string, scale?: list<string>, scope_judiciary_id?: ?string,
     *               targets_setting_key?: ?string, proposed_value?: mixed}  $payload
     */
    public function create(User $creator, array $payload): Petition
    {
        $jurisdictionId = (string) ($payload['jurisdiction_id'] ?? '');

        $associations = DB::table('residency_confirmations')
            ->where('user_id', (string) $creator->getKey())
            ->where('is_active', true)
            ->pluck('jurisdiction_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! in_array($jurisdictionId, $associations, true)) {
            throw new ConstitutionalViolation(
                'A petition is created inside the creator\'s own association chain — '
                .'association is the only gate (Art. I).',
                'Art. I · Art. II §6'
            );
        }

        if (trim((string) ($payload['title'] ?? '')) === '') {
            throw new ConstitutionalViolation('A petition carries a title.', 'Art. II §6 · as implemented');
        }

        if (trim((string) ($payload['law_text'] ?? '')) === '') {
            throw new ConstitutionalViolation(
                'A petition carries the binding law text voters would ratify.',
                'Art. II §6'
            );
        }

        $actType = (string) ($payload['act_type'] ?? '');

        if (! in_array($actType, Petition::ACT_TYPES, true)) {
            throw new ConstitutionalViolation(
                "Unknown petition act_type [{$actType}] — ordinary, setting_change, or supermajority "
                .'(no dual_supermajority by petition).',
                'Art. II §6 · as implemented'
            );
        }

        // Scale ⊆ the creator's association chain (votes_laws §E).
        $scale = array_values(array_map('strval', $payload['scale'] ?? [$jurisdictionId]));

        foreach ($scale as $scaleId) {
            if (! in_array($scaleId, $associations, true)) {
                throw new ConstitutionalViolation(
                    "Scale jurisdiction [{$scaleId}] lies outside the creator's association chain.",
                    'Art. II §6 · as implemented'
                );
            }
        }

        // Setting petitions: bounds-checked at creation (the same
        // PROTECTED path as bills — reuse checkSettingChange).
        $settingKey = $payload['targets_setting_key'] ?? null;

        if (($actType === 'setting_change') !== ($settingKey !== null)) {
            throw new ConstitutionalViolation(
                'setting_change petitions (and only they) target a setting key.',
                'Art. VII'
            );
        }

        if ($settingKey !== null) {
            $this->validator->checkSettingChange([
                'setting_key' => $settingKey,
                'value' => $payload['proposed_value'] ?? null,
            ]);
        }

        // CLK-17 snapshots: civic population + resolved pct.
        $basis = CivicPopulation::of($jurisdictionId);
        $pct = (float) ($this->settings->resolve($jurisdictionId, 'initiative_petition_threshold_pct') ?? 5.00);

        $petition = Petition::create([
            'creator_user_id' => (string) $creator->getKey(),
            'jurisdiction_id' => $jurisdictionId,
            'title' => (string) $payload['title'],
            'law_text' => (string) $payload['law_text'],
            'act_type' => $actType,
            'targets_setting_key' => $settingKey,
            'proposed_value' => $payload['proposed_value'] ?? null,
            'scale' => $scale,
            'scope_judiciary_id' => $payload['scope_judiciary_id'] ?? null,
            'population_basis' => $basis,
            'threshold_pct' => $pct,
            'threshold_count' => self::thresholdCount($basis, $pct),
            // Created → Gathering is atomic at filing (votes_laws §E).
            'status' => Petition::STATUS_GATHERING,
        ]);

        // CLK-17 threshold-watch (fires_at NULL — the sweep is the safety
        // net; signature inserts are the event-driven primary path).
        $this->clocks->arm(
            'CLK-17',
            $jurisdictionId,
            'petition',
            (string) $petition->id,
            null,
            ['threshold_count' => $petition->threshold_count, 'threshold_pct' => (string) $pct],
        );

        $this->records->publish(
            kind: 'other',
            title: "Petition opened — {$petition->title}",
            body: sprintf(
                "Gathering signatures toward %d (%s%% of the civic population of %d — CLK-17, snapshot at creation).\n\n%s",
                $petition->threshold_count,
                rtrim(rtrim(number_format($pct, 2), '0'), '.'),
                $basis,
                $petition->law_text
            ),
            attrs: [
                'actor_user_id' => (string) $creator->getKey(),
                'jurisdiction_id' => $jurisdictionId,
                'via_form' => 'F-IND-009',
                'subject_type' => 'petition',
                'subject_id' => (string) $petition->id,
            ],
        );

        return $petition;
    }

    // =========================================================================
    // F-IND-010 — sign / revoke
    // =========================================================================

    public function sign(Petition $petition, User $signer): PetitionSignature
    {
        $fresh = Petition::query()->whereKey($petition->id)->lockForUpdate()->firstOrFail();

        if (! in_array($fresh->status, Petition::SIGNABLE_STATUSES, true)) {
            throw new ConstitutionalViolation(
                "Petition is not open for signatures (status: {$fresh->status}) — the audited count "
                .'froze at the threshold check.',
                'Art. II §6'
            );
        }

        // The ONLY gate (Art. I): an active association with the
        // petition's jurisdiction.
        $association = DB::table('residency_confirmations')
            ->where('user_id', (string) $signer->getKey())
            ->where('jurisdiction_id', (string) $fresh->jurisdiction_id)
            ->where('is_active', true)
            ->first(['id']);

        if ($association === null) {
            throw new ConstitutionalViolation(
                'Signing requires an active association with the petition\'s jurisdiction — '
                .'association is the only gate.',
                'Art. I'
            );
        }

        $live = PetitionSignature::query()
            ->where('petition_id', $fresh->id)
            ->where('user_id', (string) $signer->getKey())
            ->whereNull('revoked_at')
            ->exists();

        if ($live) {
            throw new ConstitutionalViolation(
                'One live signature per person per petition — the existing signature stands (revocable while gathering).',
                'Art. II §6 · as implemented'
            );
        }

        $signature = PetitionSignature::create([
            'petition_id' => $fresh->id,
            'user_id' => (string) $signer->getKey(),
            'association_id' => (string) $association->id,
            'signed_at' => now(),
        ]);

        // Event-driven CLK-17 check (the sweep is the safety net).
        $this->evaluateThreshold($fresh);

        return $signature;
    }

    public function revoke(Petition $petition, User $signer): PetitionSignature
    {
        $fresh = Petition::query()->whereKey($petition->id)->lockForUpdate()->firstOrFail();

        if (! in_array($fresh->status, Petition::SIGNABLE_STATUSES, true)) {
            throw new ConstitutionalViolation(
                "Signatures are revocable until the audit (status: {$fresh->status}).",
                'Art. II §6 · as implemented'
            );
        }

        $signature = PetitionSignature::query()
            ->where('petition_id', $fresh->id)
            ->where('user_id', (string) $signer->getKey())
            ->whereNull('revoked_at')
            ->first();

        if ($signature === null) {
            throw new ConstitutionalViolation('No live signature to revoke.', 'Art. II §6 · as implemented');
        }

        $signature->forceFill(['revoked_at' => now()])->save();

        return $signature;
    }

    /**
     * The CLK-17 evaluation (event-driven on insert; swept by
     * EvaluatePetitionThresholdJob): live count ≥ threshold_count flips
     * gathering → threshold_reached, audits, publishes, and notifies the
     * board queue (the F-ELB-005 console reads threshold_reached rows).
     */
    public function evaluateThreshold(Petition $petition): bool
    {
        if ($petition->status !== Petition::STATUS_GATHERING) {
            return false;
        }

        $live = $petition->liveSignatureCount();

        if ($live < (int) $petition->threshold_count) {
            return false;
        }

        $petition->forceFill(['status' => Petition::STATUS_THRESHOLD_REACHED])->save();

        $this->audit->append(
            module: 'civic',
            event: 'petition.threshold_reached',
            payload: [
                'petition_id' => (string) $petition->id,
                'live_signatures' => $live,
                'threshold_count' => (int) $petition->threshold_count,
                'threshold_pct' => (string) $petition->threshold_pct,
            ],
            ref: 'CLK-17',
            jurisdictionId: (string) $petition->jurisdiction_id,
        );

        $this->records->publish(
            kind: 'participation',
            title: "Petition reached its signature threshold — {$petition->title}",
            body: sprintf(
                '%d live signatures against a threshold of %d (%s%% of the civic population snapshot). '
                .'The election board\'s independent signature audit follows (F-ELB-005); the audited '
                .'count freezes here — signatures stay open during review.',
                $live,
                (int) $petition->threshold_count,
                (string) $petition->threshold_pct
            ),
            attrs: [
                'jurisdiction_id' => (string) $petition->jurisdiction_id,
                'via_clock' => 'CLK-17',
                'subject_type' => 'petition',
                'subject_id' => (string) $petition->id,
            ],
        );

        return true;
    }

    // =========================================================================
    // F-ELB-005 — board signature audit
    // =========================================================================

    /**
     * Verify every unrevoked signature: (a) the signer held an active
     * association covering the petition's jurisdiction AT signed_at
     * (point-in-time over confirmed_at/deactivated_at), (b) no duplicate
     * signers (DB-guaranteed; re-asserted), (c) the signature predates the
     * audit. valid ≥ threshold_count → constitutional_review (HOLD —
     * Phase E review); else → invalidated (kill-path 1).
     *
     * @return array{checked: int, valid: int, invalid: int, invalid_reasons: array<string, int>, pct: string, passed: bool}
     */
    public function runSignatureAudit(Petition $petition): array
    {
        $fresh = Petition::query()->whereKey($petition->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== Petition::STATUS_THRESHOLD_REACHED) {
            throw new ConstitutionalViolation(
                "F-ELB-005 audits a petition at its threshold (status: {$fresh->status}).",
                'Art. II §6'
            );
        }

        $fresh->forceFill(['status' => Petition::STATUS_SIGNATURE_AUDIT])->save();

        $auditStartedAt = now();

        $signatures = PetitionSignature::query()
            ->where('petition_id', $fresh->id)
            ->whereNull('revoked_at')
            ->orderBy('signed_at')
            ->get();

        $checked = 0;
        $valid = 0;
        $reasons = [];
        $seen = [];

        foreach ($signatures as $signature) {
            $checked++;
            $userId = (string) $signature->user_id;

            // (b) duplicates — DB partial unique is the authority; re-asserted.
            if (isset($seen[$userId])) {
                $reasons['duplicate_signer'] = ($reasons['duplicate_signer'] ?? 0) + 1;

                continue;
            }
            $seen[$userId] = true;

            // (c) the signature predates the audit.
            if ($signature->signed_at === null || $signature->signed_at->gt($auditStartedAt)) {
                $reasons['postdates_audit'] = ($reasons['postdates_audit'] ?? 0) + 1;

                continue;
            }

            // (a) point-in-time association: a confirmation row covering
            // the jurisdiction, live AT signed_at.
            $held = DB::table('residency_confirmations')
                ->where('user_id', $userId)
                ->where('jurisdiction_id', (string) $fresh->jurisdiction_id)
                ->where('confirmed_at', '<=', $signature->signed_at)
                ->where(function ($q) use ($signature) {
                    $q->whereNull('deactivated_at')
                        ->orWhere('deactivated_at', '>', $signature->signed_at);
                })
                ->exists();

            if (! $held) {
                $reasons['no_association_at_signing'] = ($reasons['no_association_at_signing'] ?? 0) + 1;

                continue;
            }

            $valid++;
        }

        $passed = $valid >= (int) $fresh->threshold_count;

        $result = [
            'checked' => $checked,
            'valid' => $valid,
            'invalid' => $checked - $valid,
            'invalid_reasons' => $reasons,
            'pct' => $checked > 0 ? number_format($valid * 100 / $checked, 1) : '0.0',
            'passed' => $passed,
        ];

        $fresh->forceFill([
            'audit_result' => $result,
            'status' => $passed ? Petition::STATUS_CONSTITUTIONAL_REVIEW : Petition::STATUS_INVALIDATED,
        ])->save();

        $this->records->publish(
            kind: 'certification',
            title: sprintf(
                'Petition signature audit — %s: %d of %d valid (%s%%) — %s',
                $fresh->title,
                $valid,
                $checked,
                $result['pct'],
                $passed ? 'still above threshold' : 'below threshold — petition invalidated'
            ),
            body: $passed
                ? sprintf(
                    'Valid signatures (%d) meet the threshold of %d. The petition now awaits constitutional '
                    .'review — the judiciary is forming (Phase E, F-JDG-008); the petition holds at this '
                    .'stage because the kill-path is constitutional, not skippable.',
                    $valid,
                    (int) $fresh->threshold_count
                )
                : sprintf(
                    'Valid signatures (%d) fall below the threshold of %d — the petition is invalidated '
                    .'(kill-path, Art. II §6 independent audit). Reasons: %s.',
                    $valid,
                    (int) $fresh->threshold_count,
                    json_encode($reasons)
                ),
            attrs: [
                'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                'via_form' => 'F-ELB-005',
                'subject_type' => 'petition',
                'subject_id' => (string) $fresh->id,
            ],
        );

        return $result;
    }

    // =========================================================================
    // Constitutional-review stub (Phase E lands the real F-JDG-008)
    // =========================================================================

    /**
     * The audited Phase C advance past the review HOLD — see class
     * docblock. Validates the petition and queues its referendum question.
     */
    public function stubConstitutionalReview(Petition $petition): Petition
    {
        $fresh = Petition::query()->whereKey($petition->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== Petition::STATUS_CONSTITUTIONAL_REVIEW) {
            throw new ConstitutionalViolation(
                "Only a petition holding at constitutional review can be stub-validated (status: {$fresh->status}).",
                'Art. II §6 · deferred'
            );
        }

        // Phase E (PHASE_E_DESIGN_challenge_law §C.2): the stub is RETIRED for
        // jurisdictions with an active court — the production path is F-JDG-008.
        // Only `forming`-court jurisdictions (and historical demo seeds) may
        // still stub-advance; an operating court means use the real review.
        if ($this->hasActiveCourt((string) $fresh->jurisdiction_id)) {
            throw new ConstitutionalViolation(
                'An active court hears this petition\'s constitutional review — use F-JDG-008, never the '
                .'Phase C stub (the stub survives only for forming-court jurisdictions).',
                'Art. II §6'
            );
        }

        $this->audit->append(
            module: 'civic',
            event: 'petition.review.stub_validated',
            payload: [
                'petition_id' => (string) $fresh->id,
                'note' => 'Judiciary forming — F-JDG-008 review lands in Phase E; petition stub-validated',
                'citation' => 'Art. II §6 · deferred',
            ],
            ref: 'F-JDG-008',
            jurisdictionId: (string) $fresh->jurisdiction_id,
        );

        $fresh->forceFill([
            'review_stub' => true,
            'status' => Petition::STATUS_VALIDATED,
        ])->save();

        $this->referendums->queueFromPetition($fresh);

        return $fresh;
    }

    // =========================================================================
    // F-JDG-008 — the real constitutional review (Phase E supersedes the stub)
    // =========================================================================

    /**
     * The judiciary's real constitutional review of a held petition
     * (PHASE_E_DESIGN_challenge_law §C.2). The F-JDG-008 handler calls this;
     * petition state ownership stays HERE (single source of truth).
     *
     *  - cleared → review_outcome 'cleared', review_case_id set, validated,
     *    then queueFromPetition (the onward ballot path the stub already called).
     *  - struck  → invalidated, review_outcome 'struck' (kill-path 2,
     *    Art. II §6/§8) — NO referendum queued.
     */
    public function reviewByJudiciary(
        Petition $petition,
        string $outcome,
        string $opinionText,
        ?string $caseId = null,
        ?string $contradictionCitation = null,
    ): Petition {
        if (! in_array($outcome, ['cleared', 'struck'], true)) {
            throw new ConstitutionalViolation('A petition review clears or strikes (Art. II §6).', 'Art. II §6');
        }

        $fresh = Petition::query()->whereKey($petition->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== Petition::STATUS_CONSTITUTIONAL_REVIEW) {
            throw new ConstitutionalViolation(
                "Only a petition holding at constitutional review can be reviewed (status: {$fresh->status}).",
                'Art. II §6'
            );
        }

        $record = $this->records->publish(
            kind: 'opinion',
            title: sprintf(
                'Petition constitutional review — %s: %s',
                $fresh->title,
                $outcome === 'cleared' ? 'cleared for the ballot' : 'struck (unconstitutional)'
            ),
            body: $outcome === 'struck' && $contradictionCitation !== null
                ? $opinionText."\n\nContradiction: ".$contradictionCitation
                : $opinionText,
            attrs: [
                'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                'via_form' => 'F-JDG-008',
                'subject_type' => 'petition',
                'subject_id' => (string) $fresh->id,
            ],
        );

        if ($outcome === 'struck') {
            $fresh->forceFill([
                'status' => Petition::STATUS_INVALIDATED,
                'review_outcome' => 'struck',
                'review_case_id' => $caseId,
            ])->save();

            $this->audit->append(
                module: 'civic',
                event: 'petition.review.struck',
                payload: [
                    'petition_id' => (string) $fresh->id,
                    'citation' => $contradictionCitation ?? 'Art. II §6',
                    'record_id' => (string) $record->id,
                ],
                ref: 'F-JDG-008',
                jurisdictionId: (string) $fresh->jurisdiction_id,
            );

            return $fresh;
        }

        $fresh->forceFill([
            'status' => Petition::STATUS_VALIDATED,
            'review_outcome' => 'cleared',
            'review_case_id' => $caseId,
        ])->save();

        $this->audit->append(
            module: 'civic',
            event: 'petition.review.cleared',
            payload: ['petition_id' => (string) $fresh->id, 'record_id' => (string) $record->id],
            ref: 'F-JDG-008',
            jurisdictionId: (string) $fresh->jurisdiction_id,
        );

        $this->referendums->queueFromPetition($fresh);

        return $fresh;
    }

    /** An operating (appointed/elected) court exists in the jurisdiction's footprint. */
    private function hasActiveCourt(string $jurisdictionId): bool
    {
        $current = $jurisdictionId;

        for ($depth = 0; $depth < 32 && $current !== null; $depth++) {
            $exists = DB::table('judiciaries')
                ->where('jurisdiction_id', $current)
                ->whereNull('deleted_at')
                ->whereIn('status', ['appointed', 'elected'])
                ->exists();

            if ($exists) {
                return true;
            }

            $parent = DB::table('jurisdictions')->where('id', $current)->value('parent_id');
            $current = $parent !== null ? (string) $parent : null;
        }

        return false;
    }
}
