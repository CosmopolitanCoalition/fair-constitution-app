<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ClockTimer;
use App\Models\ConstitutionalChallenge;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RemedyRecommendation;
use App\Models\User;
use App\Services\ConstitutionalValidator;
use App\Services\Judiciary\CaseService;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\RoleService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Article IV §5 (Judiciaries: Resolving Questions of Law),
 * THE Phase E exit criterion. The full chain runs end to end THROUGH THE ENGINE:
 *
 *   F-IND-016 (any inhabitant, absolute right) → challenge.filed → a cases row
 *   opens (under_review) → the court hears it → F-JDG-004 finds a contradiction
 *   (finding_issued) → F-JDG-005 sets remedy_timeframe_days + veto_window_days
 *   (ARMS exactly CLK-12 + CLK-11 with override_value) → the legislature does
 *   NOTHING → both windows expire → CLK-11 fires JudicialAutoRemedyJob →
 *   JudicialRemedyService appends a law_versions row source='judicial_remedy',
 *   version history PRESERVED, the law made non-contradictory →
 *   judicial_remedy_applied → closed.
 *
 * §5.1 "All individuals who inhabit a Jurisdiction have the right to make claims
 * against a Government if they believe a law is unjustly impeding their rights…"
 * §5.2 "If the Judiciary finds that any legislation … is contradictory … it
 * informs The Legislature of what laws are in error and recommends a remedy."
 * §5.3 "The Legislature modifies or removes the offending laws in a reasonable
 * timeframe as outlined by the Judiciary." (Path 1)
 * §5.4 "A Supermajority of The Legislature may disagree with the Judiciary and
 * overrule its judgement within a set Judicial veto window." (Path 2)
 * §5.5 "If The Legislature does not modify the law nor override the Judiciary
 * within the window, then the Judiciary applies its own remedy to the law
 * directly…" (Path 3, the exit criterion)
 *
 * Live-DB sections use the guarded pg connection (the CaseLifecycleTest /
 * WorkerRepresentationTest technique): SKIP when pg is unreachable; every write
 * rides one transaction that is ALWAYS rolled back.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class Art4Section5Test extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_art4_s5';

    // ======================================================================
    // 1. PURE pins (DB-free, always run)
    // ======================================================================

    /**
     * §5.1 / Art. I — the challenge is an absolute right: F-IND-016 is a
     * rights-automatic form (no eligibility ground may attach) AND an
     * emergency-protected form (no emergency power can suspend the right to
     * challenge). Both lists are source-pinned.
     */
    public function test_challenge_filing_is_an_absolute_and_emergency_protected_right(): void
    {
        $this->assertContains(
            'F-IND-016',
            ConstitutionalValidator::RIGHTS_AUTOMATIC_FORMS,
            'Art. IV §5.1 · Art. I — the constitutional challenge carries no eligibility gate beyond residency.'
        );

        $this->assertContains(
            'F-IND-016',
            ConstitutionalValidator::EMERGENCY_PROTECTED_FORMS,
            'Art. IV §5.1 — an emergency power can never suspend the right to challenge a law.'
        );

        // An eligibility rider on F-IND-016 is rejected by guardAutomaticRights
        // (Art. I) — the same gate the residency forms ride.
        try {
            app(ConstitutionalValidator::class)->check('F-IND-016', [
                'challenged_law_id' => (string) Str::uuid(),
                'fee' => 100, // a forbidden eligibility key
            ]);
            $this->fail('A fee/eligibility rider on F-IND-016 must be rejected (Art. I).');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. I', $e->citation, 'Art. I — the challenge is condition-free.');
        }
    }

    /**
     * The Art. IV §5 substrate is wired: F-LEG-035 routes to the override
     * handler, the seven F-* forms have handlers, and the CLK-11/CLK-12 fire
     * jobs are mapped (the auto-remedy is the §5.5 guarantee). A refactor that
     * severs any of these silently disables the exit criterion.
     */
    public function test_art_iv_s5_substrate_is_wired(): void
    {
        foreach (['F-IND-016', 'F-JDG-004', 'F-JDG-005', 'F-JDG-006', 'F-JDG-007', 'F-JDG-008', 'F-LEG-035'] as $form) {
            $this->assertNotNull(
                \App\Domain\Forms\FormRegistry::handlerFor($form),
                "Art. IV §5 — {$form} must have a registered handler (the engine dispatches via HANDLERS)."
            );
        }

        // CLK-11 fires the §5.5 auto-remedy (THE exit criterion); CLK-12 is the
        // light timeframe-lapsed marker.
        $this->assertSame(
            \App\Jobs\Clocks\JudicialAutoRemedyJob::class,
            \App\Services\ClockService::HANDLERS['CLK-11'] ?? null,
            'Art. IV §5.5 — CLK-11 fires the judicial auto-remedy.'
        );
        $this->assertSame(
            \App\Jobs\Clocks\LegislativeWindowLapsedJob::class,
            \App\Services\ClockService::HANDLERS['CLK-12'] ?? null,
            'Art. IV §5.3 — CLK-12 marks the legislative remedy timeframe lapse.'
        );

        // judiciary_override is a supermajority vote type (never majority).
        $vt = config('constitution.vote_types.judiciary_override');
        $this->assertSame('supermajority', $vt['category'] ?? null, 'Art. IV §5.4 — the override is a supermajority.');
        $this->assertSame('serving', $vt['denominator'] ?? null, 'Art. IV §5.4 — over ALL serving members.');
    }

    // ======================================================================
    // 2. THE EXIT CRITERION — Path 3 (judicial auto-remedy) end to end
    // ======================================================================

    /**
     * THE Phase E exit criterion: F-IND-016 → finding → remedy (arms CLK-11 +
     * CLK-12) → both windows expire → CLK-11 fires JudicialAutoRemedyJob → the
     * law text is EDITED via a judicial_remedy version with history preserved.
     */
    public function test_auto_remedy_edits_the_law_when_the_legislature_does_nothing(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $judge = $this->aSeatedJudge($judiciary);
            $inhabitant = $this->anAssociatedNonJudge($jurisdictionId);

            // The offending law (v1) — a throwaway in-force act in this
            // legislature's jurisdiction.
            $law = $this->throwawayLaw($jurisdictionId, (string) $legislature->id);
            $this->assertSame(1, (int) $law->current_version_no);
            $originalText = $this->versionText($law, 1);

            // ── F-IND-016 — any inhabitant (R-03) files the challenge ────────
            $filed = $engine->file('F-IND-016', $inhabitant, [
                'jurisdiction_id' => $jurisdictionId,
                'challenged_law_id' => (string) $law->id,
                'claim_text' => 'Act unjustly impedes my rights (throwaway claim).',
                'claimed_basis' => 'constitution',
                'constitutional_citation' => 'Art. I',
            ]);

            $challenge = ConstitutionalChallenge::query()->findOrFail($filed->recorded['challenge_id']);
            $this->assertSame(ConstitutionalChallenge::STATUS_UNDER_REVIEW, $challenge->status, '§5.1 — a filed challenge with a seated court opens a hearing.');
            $this->assertNotNull($challenge->case_id, 'The challenge opens a cases row to be heard.');
            $this->assertSame(CourtCase::KIND_CONSTITUTIONAL, (string) CourtCase::query()->find($challenge->case_id)->kind);

            // The court hears it (accept → panel → heard). Drive the case to
            // a heard/deliberation state through F-JDG-001 + CaseService.
            $this->driveCaseToDeliberation($engine, $judge, (string) $challenge->case_id);

            // ── F-JDG-004 — the court finds a contradiction ──────────────────
            $found = $engine->file('F-JDG-004', $judge, [
                'challenge_id' => (string) $challenge->id,
                'finds_contradiction' => true,
                'contradiction_against' => 'constitution',
                'constitutional_citation' => 'Art. I',
                'offending_law_id' => (string) $law->id,
                'opinion_text' => 'The act contradicts Art. I (throwaway finding).',
            ]);

            $challenge->refresh();
            $this->assertSame(ConstitutionalChallenge::STATUS_FINDING_ISSUED, $challenge->status, '§5.2 — a contradiction finding moves to finding_issued.');
            $this->assertTrue((bool) $found->recorded['finds_contradiction']);

            // ── F-JDG-005 — the judge SETS the windows (Novák fixture: 60/30) ─
            $remedyText = $originalText."\n\n[Judicial remedy: conflicting clause removed to restore Art. I.]";
            $recommended = $engine->file('F-JDG-005', $judge, [
                'challenge_id' => (string) $challenge->id,
                'remedy_kind' => 'modify',
                'recommended_text' => $remedyText,
                'rationale_text' => 'Striking the conflicting clause makes the law non-contradictory.',
                'remedy_timeframe_days' => 60,
                'veto_window_days' => 30,
            ]);

            $challenge->refresh();
            $this->assertSame(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $challenge->status, '§5.2/§5.3/§5.4 — the recommendation opens the legislative window.');

            $recommendation = RemedyRecommendation::query()->findOrFail($recommended->recorded['remedy_id']);

            // EXACTLY two timers armed (CLK-11 + CLK-12), subject the challenge,
            // override_value carrying the judge-set days; NO settings read.
            $timers = ClockTimer::query()
                ->where('subject_type', 'constitutional_challenges')
                ->where('subject_id', (string) $challenge->id)
                ->where('state', ClockTimer::STATE_ARMED)
                ->get()
                ->keyBy('clock_id');

            $this->assertCount(2, $timers, '§5.3/§5.4 — F-JDG-005 arms exactly CLK-11 + CLK-12.');
            $this->assertSame(30, (int) $timers['CLK-11']->override_value['days'], 'CLK-11 carries the judge-set veto window (30).');
            $this->assertSame(60, (int) $timers['CLK-12']->override_value['days'], 'CLK-12 carries the judge-set remedy timeframe (60).');

            // CLK-12 fires at issued+60; CLK-11 fires at max(60,30)=60 (B.7).
            $clk12FiresAt = $timers['CLK-12']->fires_at;
            $clk11FiresAt = $timers['CLK-11']->fires_at;
            $this->assertTrue(
                $clk11FiresAt->equalTo($clk12FiresAt),
                'B.7 — CLK-11 is armed to max(veto, remedy) = the remedy deadline so a single fire suffices.'
            );

            // ── The legislature does NOTHING; both windows expire ────────────
            // Simulate time passing: the deadlines (the recommendation dates AND
            // the armed timers) all move into the past, then the clock sweep runs.
            $recommendation->forceFill([
                'remedy_due_at' => now()->subDay(),
                'veto_closes_at' => now()->subDay(),
            ])->save();
            ClockTimer::query()->whereIn('id', [$timers['CLK-11']->id, $timers['CLK-12']->id])
                ->update(['fires_at' => now()->subDay()]);

            // The sweep fires CLK-12 (marker, no transition) then CLK-11
            // (JudicialAutoRemedyJob, run sync in tests).
            app(\App\Jobs\EvaluateClocksJob::class)->handle(app(\App\Services\ClockService::class));

            // ── §5.5 — the law was EDITED by judicial remedy ─────────────────
            $law->refresh();
            $challenge->refresh();

            $this->assertSame(ConstitutionalChallenge::STATUS_CLOSED, $challenge->status, '§5.5 — the auto-remedy closes the challenge.');
            $this->assertSame(ConstitutionalChallenge::PATH_JUDICIAL_REMEDY, (string) $challenge->resolution_path, '§5.5 — resolved by judicial remedy.');

            $this->assertSame(2, (int) $law->current_version_no, '§5.5 — the law advances to v2.');
            $this->assertSame(Law::STATUS_AMENDED, (string) $law->status, '§5.5 — a modify remedy leaves the law in force (amended).');

            // The NEW version is a judicial_remedy version carrying the remedy text.
            $v2 = LawVersion::query()->where('law_id', (string) $law->id)->where('version_no', 2)->firstOrFail();
            $this->assertSame(LawVersion::SOURCE_JUDICIAL_REMEDY, (string) $v2->source, '§5.5 — the appended version is source=judicial_remedy.');
            $this->assertSame($remedyText, (string) $v2->text, '§5.5 — the judge\'s recommended text is applied directly.');

            // VERSION HISTORY PRESERVED: v1 is untouched and present.
            $v1 = LawVersion::query()->where('law_id', (string) $law->id)->where('version_no', 1)->firstOrFail();
            $this->assertSame($originalText, (string) $v1->text, '§5.5 — the superseded version is preserved, unchanged.');
            $this->assertSame(2, LawVersion::query()->where('law_id', (string) $law->id)->count(), 'Append-only: exactly two versions exist.');

            // The judicial_remedy_applied chain entry is sealed (NOT rejected).
            $entry = $conn->table('audit_log')
                ->where('event', 'challenge.judicial_remedy_applied')
                ->where('payload->challenge_id', (string) $challenge->id)
                ->first();
            $this->assertNotNull($entry, '§5.5 — the auto-remedy seals a chain entry.');
            $this->assertFalse((bool) $entry->rejected);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    /**
     * Path 3 with remedy_kind=remove sets Law::STATUS_STRUCK (the judiciary
     * removed it under Art. IV §5 — distinct from legislative `repealed`), and
     * the judicial remedy PIERCES a CLK-19 referendum shield (§5.5 over
     * Art. II §6 — even a population-supermajority-shielded law yields).
     */
    public function test_remove_remedy_strikes_and_pierces_a_referendum_shield(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $judge = $this->aSeatedJudge($judiciary);
            $inhabitant = $this->anAssociatedNonJudge($jurisdictionId);

            // A SHIELDED referendum act (population supermajority, shield
            // election still pending) — the legislature itself cannot touch it.
            $law = $this->shieldedReferendumLaw($conn, $jurisdictionId, (string) $legislature->id);
            $this->assertSame(Law::ORIGIN_REFERENDUM, (string) $law->origin);
            $this->assertTrue((bool) $law->referendum_passed_by_supermajority);

            $challenge = $this->fileFoundRecommended($engine, $judge, $inhabitant, $jurisdictionId, $law, 'remove', null, 10, 5);

            // Expire both windows and sweep.
            $this->expireAndSweep($challenge);

            $law->refresh();
            $this->assertSame(Law::STATUS_STRUCK, (string) $law->status, '§5.5 — a remove remedy STRIKES the law (judicial removal, Art. IV §5).');
            $this->assertSame(2, (int) $law->current_version_no, '§5.5 — the strike appends a final repeal version.');

            $v2 = LawVersion::query()->where('law_id', (string) $law->id)->where('version_no', 2)->firstOrFail();
            $this->assertSame(LawVersion::SOURCE_JUDICIAL_REMEDY, (string) $v2->source, '§5.5 — even a shielded law is edited by judicial remedy (pierces CLK-19).');

            // v1 preserved.
            $this->assertSame(2, LawVersion::query()->where('law_id', (string) $law->id)->count(), 'Append-only across the strike.');

            $challenge->refresh();
            $this->assertSame(ConstitutionalChallenge::STATUS_CLOSED, $challenge->status);
            $this->assertSame(ConstitutionalChallenge::PATH_JUDICIAL_REMEDY, (string) $challenge->resolution_path);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    // ======================================================================
    // 3. Path 2 — the SUPERMAJORITY override (the law stands unchanged)
    // ======================================================================

    /**
     * §5.4 — Path 2: a supermajority adopts F-LEG-035 within the CLK-11 window;
     * the finding is overruled, the law stands UNCHANGED (no law_version
     * appended), both clocks cancelled, the challenge closes `overridden`.
     */
    public function test_supermajority_override_leaves_the_law_unchanged(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $judge = $this->aSeatedJudge($judiciary);
            $inhabitant = $this->anAssociatedNonJudge($jurisdictionId);

            $law = $this->throwawayLaw($jurisdictionId, (string) $legislature->id);
            $challenge = $this->fileFoundRecommended($engine, $judge, $inhabitant, $jurisdictionId, $law, 'modify', 'replacement text', 60, 30);

            // ── F-LEG-035 — a legislator opens the override; supermajority adopts ─
            $proposer = $this->nonSpeakerUser($legislature);
            $proposed = $engine->file('F-LEG-035', $proposer, [
                'challenge_id' => (string) $challenge->id,
                'jurisdiction_id' => $jurisdictionId,
                'dissent_text' => 'The legislature disagrees with the finding.',
            ]);

            $this->castAllYes($engine, $legislature, $proposed->recorded['vote_id']);

            $challenge->refresh();
            $this->assertSame(ConstitutionalChallenge::STATUS_CLOSED, $challenge->status, '§5.4 — an adopted override closes the challenge.');
            $this->assertSame(ConstitutionalChallenge::PATH_LEGISLATURE_OVERRIDE, (string) $challenge->resolution_path);

            // The override vote met SUPERMAJORITY (not bare majority).
            $vote = ChamberVote::query()->findOrFail($proposed->recorded['vote_id']);
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, (string) $vote->threshold_basis, '§5.4 — the override threshold is supermajority.');

            // The law stands UNCHANGED — no law_version appended.
            $law->refresh();
            $this->assertSame(1, (int) $law->current_version_no, '§5.4 — the overruled law is NOT edited.');
            $this->assertSame(1, LawVersion::query()->where('law_id', (string) $law->id)->count(), '§5.4 — no version appended on override.');

            // Both clocks cancelled — the auto-remedy can never run.
            $this->assertSame(
                0,
                ClockTimer::query()
                    ->where('subject_type', 'constitutional_challenges')
                    ->where('subject_id', (string) $challenge->id)
                    ->where('state', ClockTimer::STATE_ARMED)
                    ->count(),
                '§5.4 — adoption cancels both timers (the auto-remedy never fires).'
            );

            // Running the sweep now does NOTHING (cancelled timers never fire).
            app(\App\Jobs\EvaluateClocksJob::class)->handle(app(\App\Services\ClockService::class));
            $law->refresh();
            $this->assertSame(1, (int) $law->current_version_no, 'A cancelled CLK-11 never fires the auto-remedy.');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    /**
     * §5.4 — an override filed AFTER the CLK-11 veto window has closed is
     * rejected (the window binds it); the auto-remedy path remains.
     */
    public function test_override_after_the_veto_window_is_rejected(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $judge = $this->aSeatedJudge($judiciary);
            $inhabitant = $this->anAssociatedNonJudge($jurisdictionId);

            $law = $this->throwawayLaw($jurisdictionId, (string) $legislature->id);
            $challenge = $this->fileFoundRecommended($engine, $judge, $inhabitant, $jurisdictionId, $law, 'modify', 'replacement', 60, 30);

            // Force the veto window into the past (the override is now too late).
            $recommendation = RemedyRecommendation::query()->where('challenge_id', (string) $challenge->id)->firstOrFail();
            $recommendation->forceFill(['veto_closes_at' => now()->subDay()])->save();

            $proposer = $this->nonSpeakerUser($legislature);
            try {
                $engine->file('F-LEG-035', $proposer, [
                    'challenge_id' => (string) $challenge->id,
                    'jurisdiction_id' => $jurisdictionId,
                ]);
                $this->fail('An override filed after the veto window must be rejected (Art. IV §5.4).');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. IV §5', $e->citation, 'Art. IV §5.4 — the veto window bars a late override.');
            }
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    // ======================================================================
    // 4. Path 1 — the legislature AMENDS in time (cancels the clocks)
    // ======================================================================

    /**
     * §5.3 — Path 1: a remedial bill tagged to the challenge, enacted inside the
     * CLK-12 window, closes the challenge `amended_by_legislature` and cancels
     * both clocks; the auto-remedy never runs.
     */
    public function test_legislative_amendment_in_time_closes_the_challenge(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        app(RoleService::class)->flush();

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary] = $this->appointedCourt($conn);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $judge = $this->aSeatedJudge($judiciary);
            $inhabitant = $this->anAssociatedNonJudge($jurisdictionId);

            // The offending law must be a BILL-origin law so the remedial
            // amendment bill can re-amend it (the EnactmentService::amendLaw
            // path); a throwaway ordinary law is bill-origin.
            $law = $this->throwawayLaw($jurisdictionId, (string) $legislature->id);
            $challenge = $this->fileFoundRecommended($engine, $judge, $inhabitant, $jurisdictionId, $law, 'modify', 'judge text', 60, 30);

            $recommendation = RemedyRecommendation::query()->where('challenge_id', (string) $challenge->id)->firstOrFail();
            $clk11Id = (string) $recommendation->clk11_timer_id;
            $clk12Id = (string) $recommendation->clk12_timer_id;

            // ── F-LEG-003 — a remedial bill tagged to the challenge ──────────
            $sponsor = $this->nonSpeakerUser($legislature);
            $introduced = $engine->file('F-LEG-003', $sponsor, [
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => $jurisdictionId,
                'title' => 'Remedial amendment (throwaway)',
                'law_text' => 'The offending clause is amended by the legislature (throwaway remedy).',
                'act_type' => 'ordinary',
                'targets_challenge_id' => (string) $challenge->id,
            ]);

            // Drive the bill to enactment (floor vote adopts). The bill flow
            // amends the offending law via its OWN enactment — but Path 1 only
            // needs the remedial bill that TARGETS the offending law to enact;
            // here the bill enacts its own law, then onRemedialEnactment runs
            // against the offending law it names via targets_challenge_id.
            $billId = $introduced->recorded['bill_id'];
            $this->driveBillToEnactment($engine, $legislature, $billId);

            $challenge->refresh();
            $this->assertSame(ConstitutionalChallenge::STATUS_CLOSED, $challenge->status, '§5.3 — a timely legislative amendment closes the challenge.');
            $this->assertSame(ConstitutionalChallenge::PATH_LEGISLATIVE_AMENDMENT, (string) $challenge->resolution_path);

            // Both timers cancelled — the auto-remedy never runs.
            $this->assertSame('cancelled', (string) ClockTimer::query()->find($clk11Id)->state, '§5.3 — CLK-11 cancelled on amendment.');
            $this->assertSame('cancelled', (string) ClockTimer::query()->find($clk12Id)->state, '§5.3 — CLK-12 cancelled on amendment.');

            // Running the sweep does nothing (cancelled timers never fire).
            app(\App\Jobs\EvaluateClocksJob::class)->handle(app(\App\Services\ClockService::class));
            $challenge->refresh();
            $this->assertSame(ConstitutionalChallenge::PATH_LEGISLATIVE_AMENDMENT, (string) $challenge->resolution_path, 'The auto-remedy never overrides a completed Path 1.');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
            app(RoleService::class)->flush();
        }
    }

    // ======================================================================
    // 5. The amendments DUAL-DOOR (Art. IV §3)
    // ======================================================================

    /**
     * Art. IV §3 — `judiciary_is_elected` (a DUAL_DOOR_KEYS setting) cannot be
     * amended through Door 1 (ordinary F-LEG-031) alone: the validator rejects
     * an ordinary setting bill targeting it, AND it passes only with the
     * constituent door flag.
     */
    public function test_dual_door_setting_rejects_door_one_alone(): void
    {
        $this->assertContains(
            'judiciary_is_elected',
            ConstitutionalValidator::DUAL_DOOR_KEYS,
            'Art. IV §3 — judiciary_is_elected requires constituent supermajority too.'
        );

        $validator = app(ConstitutionalValidator::class);

        // Door 1 alone — rejected (Art. IV §3).
        try {
            $validator->check('F-LEG-031', [
                'setting_key' => 'judiciary_is_elected',
                'value' => true,
            ]);
            $this->fail('judiciary_is_elected must reject through Door 1 alone (Art. IV §3).');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. IV §3', $e->citation, 'Art. IV §3 — the dual-door gate.');
        }

        // With the constituent-consent flag the validator passes (the second
        // door opens downstream; here only the gate is checked).
        $validator->check('F-LEG-031', [
            'setting_key' => 'judiciary_is_elected',
            'value' => true,
            'requires_constituent_consent' => true,
        ]);
        $this->addToAssertionCount(1);

        // The supermajority clamp still floors any ratio amendment at majority+1
        // (the ratio is NOT a dual-door key — the constitution does not gate it
        // on constituents — but it can never weaken below the hard floor).
        $this->assertNotContains('supermajority_numerator', ConstitutionalValidator::DUAL_DOOR_KEYS);
        try {
            $validator->check('F-LEG-031', ['setting_key' => 'supermajority_numerator', 'value' => 1, 'supermajority_denominator' => 3]);
            $this->fail('A supermajority fraction at or below 1/2 must reject (Art. VII).');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. VII', $e->citation);
        }
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * File F-IND-016 → drive to deliberation → F-JDG-004 (contradiction) →
     * F-JDG-005 (modify/remove with the given windows). Returns the challenge.
     */
    private function fileFoundRecommended(
        ConstitutionalEngine $engine,
        User $judge,
        User $inhabitant,
        string $jurisdictionId,
        Law $law,
        string $remedyKind,
        ?string $recommendedText,
        int $timeframeDays,
        int $vetoDays,
    ): ConstitutionalChallenge {
        $filed = $engine->file('F-IND-016', $inhabitant, [
            'jurisdiction_id' => $jurisdictionId,
            'challenged_law_id' => (string) $law->id,
            'claim_text' => 'Throwaway challenge claim.',
            'claimed_basis' => 'constitution',
        ]);

        $challenge = ConstitutionalChallenge::query()->findOrFail($filed->recorded['challenge_id']);

        $this->driveCaseToDeliberation($engine, $judge, (string) $challenge->case_id);

        $engine->file('F-JDG-004', $judge, [
            'challenge_id' => (string) $challenge->id,
            'finds_contradiction' => true,
            'contradiction_against' => 'constitution',
            'offending_law_id' => (string) $law->id,
            'opinion_text' => 'Throwaway finding.',
        ]);

        $engine->file('F-JDG-005', $judge, array_filter([
            'challenge_id' => (string) $challenge->id,
            'remedy_kind' => $remedyKind,
            'recommended_text' => $recommendedText,
            'rationale_text' => 'Throwaway rationale.',
            'remedy_timeframe_days' => $timeframeDays,
            'veto_window_days' => $vetoDays,
        ], fn ($v) => $v !== null));

        return $challenge->refresh();
    }

    /** Simulate time passing: expire the recommendation windows + timers, sweep. */
    private function expireAndSweep(ConstitutionalChallenge $challenge): void
    {
        RemedyRecommendation::query()
            ->where('challenge_id', (string) $challenge->id)
            ->update(['remedy_due_at' => now()->subDay(), 'veto_closes_at' => now()->subDay()]);

        ClockTimer::query()
            ->where('subject_type', 'constitutional_challenges')
            ->where('subject_id', (string) $challenge->id)
            ->where('state', ClockTimer::STATE_ARMED)
            ->update(['fires_at' => now()->subDay()]);

        app(\App\Jobs\EvaluateClocksJob::class)->handle(app(\App\Services\ClockService::class));
    }

    /** accept (constitutional_major → en banc) + panel, then heard → deliberation. */
    private function driveCaseToDeliberation(ConstitutionalEngine $engine, User $judge, string $caseId): void
    {
        $case = CourtCase::query()->findOrFail($caseId);

        $engine->file('F-JDG-001', $judge, [
            'case_id' => $caseId,
            'court_severity' => 'constitutional_major',
        ]);

        $cases = app(CaseService::class);
        $cases->advanceToHearing($case->refresh());
        $cases->enterDeliberation($case->refresh());
    }

    /**
     * Drive a tagged remedial bill to enactment through the floor. On
     * enactment, BillService::resolveBillVote fires the Path-1 hook
     * (onRemedialEnactment) because the bill carries targets_challenge_id —
     * §5.3 lets the legislature craft any compliant remedy.
     */
    private function driveBillToEnactment(ConstitutionalEngine $engine, Legislature $legislature, string $billId): void
    {
        $bill = \App\Models\Bill::query()->findOrFail($billId);

        // Move the bill to the floor (opens the bill_pass vote) then cast yes.
        $floor = app(\App\Services\BillService::class)->moveToFloor($bill->refresh());

        if ($floor !== null) {
            $this->castAllYes($engine, $legislature, (string) $floor->id);
        }
    }

    /**
     * Drive a forming court to APPOINTED with a 5-judge bench (the
     * CaseLifecycleTest::appointedFiveJudgeCourt pattern).
     *
     * @return array{0: Legislature, 1: Judiciary}
     */
    private function appointedCourt(Connection $conn): array
    {
        $judiciaries = Judiciary::query()->where('status', Judiciary::STATUS_FORMING)->get();

        foreach ($judiciaries as $judiciary) {
            $legislature = Legislature::query()
                ->where('jurisdiction_id', $judiciary->jurisdiction_id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'dissolved')
                ->whereNotNull('term_ends_on')
                ->first();

            if ($legislature === null
                || ! $legislature->members()->whereIn('status', ['elected', 'seated'])->exists()) {
                continue;
            }

            $constituents = \App\Services\Judiciary\JudiciaryFormationService::constituentJurisdictionIds($legislature);

            $associated = $conn->table('residency_confirmations')
                ->where('jurisdiction_id', (string) $legislature->jurisdiction_id)
                ->where('is_active', true)
                ->count();

            if ($associated < 12) {
                continue;
            }

            try {
                $this->driveToAppointed(app(ConstitutionalEngine::class), $legislature, $judiciary, $constituents);
            } catch (\Throwable $e) {
                continue;
            }

            return [$legislature, $judiciary->refresh()];
        }

        $this->markTestSkipped('Live DB has no forming judiciary with a chamber + a ≥12-resident pool — seed San Marino.');
    }

    /** @param list<string> $constituentIds */
    private function driveToAppointed(
        ConstitutionalEngine $engine,
        Legislature $legislature,
        Judiciary $judiciary,
        array $constituentIds,
    ): void {
        $jurisdictionId = (string) $legislature->jurisdiction_id;

        $payload = [
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => $jurisdictionId,
            'court_name' => 'Art4Section5Test throwaway court',
            'function_text' => 'Art4Section5Test throwaway — appointed court.',
        ];

        if ($constituentIds !== []) {
            $payload['judges_per_constituent'] = 5;
        } else {
            $payload['committee_judge_count'] = 5;
        }

        $proposed = $engine->file('F-LEG-017', $this->nonSpeakerUser($legislature), $payload);
        $this->castAllYes($engine, $legislature, $proposed->recorded['vote_id']);

        $seats = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_VACANT)
            ->orderBy('seat_number')
            ->get();

        $nominees = $this->associatedUsers($jurisdictionId, $seats->count());
        $seatService = app(JudicialSeatService::class);

        foreach ($seats as $i => $seat) {
            $out = $constituentIds !== []
                ? $seatService->nominate($seat, (string) $nominees[$i]->id, (string) $seat->nominating_jurisdiction_id)
                : $seatService->committeeNominate($seat, (string) $nominees[$i]->id);

            $this->castAllYes($engine, $legislature, $out['consent_vote_id']);
        }
    }

    private function aSeatedJudge(Judiciary $judiciary): User
    {
        $seat = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->firstOrFail();

        $judge = User::query()->findOrFail((string) $seat->user_id);
        app(RoleService::class)->flushUser((string) $judge->id);

        return $judge;
    }

    private function anAssociatedNonJudge(string $jurisdictionId): User
    {
        $seatedJudgeIds = JudicialSeat::query()
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all();

        $id = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->whereNotIn('user_id', $seatedJudgeIds)
            ->value('user_id');

        if ($id === null) {
            $this->markTestSkipped('No free associated user to file the challenge — seed residents.');
        }

        $user = User::query()->findOrFail((string) $id);
        app(RoleService::class)->flushUser((string) $user->id);

        return $user;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function associatedUsers(string $jurisdictionId, int $n)
    {
        $ids = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->limit($n)
            ->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        if (count($ids) < $n) {
            $this->markTestSkipped("Live DB has fewer than {$n} associated users — seed residents.");
        }

        return User::query()->whereIn('id', $ids)->get()->values();
    }

    private function nonSpeakerUser(Legislature $legislature): User
    {
        $member = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }

    private function castAllYes(ConstitutionalEngine $engine, Legislature $legislature, string $voteId): void
    {
        $members = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->get();

        foreach ($members as $member) {
            if (ChamberVote::query()->whereKey($voteId)->value('status') !== ChamberVote::STATUS_OPEN) {
                break;
            }

            $engine->file('F-LEG-004', User::query()->findOrFail($member->user_id), [
                'vote_id' => $voteId,
                'value' => 'yes',
            ]);
        }
    }

    private function throwawayLaw(string $jurisdictionId, string $legislatureId): Law
    {
        $law = Law::create([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => $legislatureId,
            'act_number' => 'A45-'.strtoupper(Str::random(10)),
            'title' => 'Art4Section5 Throwaway Act '.Str::random(6),
            'kind' => Law::KIND_ORDINARY,
            'scale' => [$jurisdictionId],
            'origin' => Law::ORIGIN_BILL,
            'status' => Law::STATUS_IN_FORCE,
            'current_version_no' => 1,
            'effective_at' => now(),
            'enacted_at' => now(),
        ]);

        LawVersion::create([
            'id' => (string) Str::uuid(),
            'law_id' => (string) $law->id,
            'version_no' => 1,
            'text' => 'Original offending law text v1 (throwaway).',
            'text_hash' => hash('sha256', 'Original offending law text v1 (throwaway).'),
            'source' => LawVersion::SOURCE_ENACTMENT,
            'source_ref_type' => 'bill',
            'source_ref_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return $law;
    }

    /** A referendum-origin law shielded by population supermajority (shield election pending). */
    private function shieldedReferendumLaw(Connection $conn, string $jurisdictionId, string $legislatureId): Law
    {
        // An OPEN (uncertified) election to act as the pending shield.
        $electionId = (string) Str::uuid();
        $conn->table('elections')->insert([
            'id' => $electionId,
            'legislature_id' => $legislatureId,
            'jurisdiction_id' => $jurisdictionId,
            'kind' => 'general',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $law = Law::create([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => $legislatureId,
            'act_number' => 'A45R-'.strtoupper(Str::random(8)),
            'title' => 'Shielded referendum act (throwaway)',
            'kind' => Law::KIND_REFERENDUM_ACT,
            'scale' => [$jurisdictionId],
            'origin' => Law::ORIGIN_REFERENDUM,
            'referendum_passed_by_supermajority' => true,
            'shield_expires_with_election_id' => $electionId,
            'status' => Law::STATUS_IN_FORCE,
            'current_version_no' => 1,
            'effective_at' => now(),
            'enacted_at' => now(),
        ]);

        LawVersion::create([
            'id' => (string) Str::uuid(),
            'law_id' => (string) $law->id,
            'version_no' => 1,
            'text' => 'Shielded referendum text v1 (throwaway).',
            'text_hash' => hash('sha256', 'Shielded referendum text v1 (throwaway).'),
            'source' => LawVersion::SOURCE_ENACTMENT,
            'source_ref_type' => 'referendum_question',
            'source_ref_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return $law;
    }

    private function versionText(Law $law, int $versionNo): string
    {
        return (string) LawVersion::query()
            ->where('law_id', (string) $law->id)
            ->where('version_no', $versionNo)
            ->value('text');
    }

    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
