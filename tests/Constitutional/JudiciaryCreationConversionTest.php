<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Candidacy;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MultiJurisdictionVote;
use App\Models\RaceResult;
use App\Models\RemovalProceeding;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\Judiciary\JudiciaryFormationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Article IV (the Judiciary). Pins F-LEG-017 (appointed
 * creation) and F-LEG-018 (conversion to an elected court) through the engine.
 *
 * Art. IV §1 — "Legislatures may, by Supermajority vote, delegate Judicial
 * authority to an appointed and politically neutral Judiciary…" The DEFAULT
 * output of creation is an APPOINTED court — elected courts come ONLY via the
 * conversion act.
 *
 * Art. IV §2 — "If a Judicial Jurisdiction contains Constituent Jurisdictions,
 * then an equal number of Judges are nominated by each of the Constituent
 * Jurisdictions." The nomination mode is DERIVED from constituent structure;
 * the equal-count is a HARDENED invariant.
 *
 * Art. IV §3 — "Legislatures may, by Supermajority vote, delegate Judicial
 * authority to representatives elected directly by the population… If a
 * Jurisdiction is composed of Constituent Jurisdictions, then a Supermajority
 * of Constituent Jurisdictions must also consent…" — the DUAL supermajority
 * (the Phase E exit criterion #2). "…their terms last for the same length" as
 * legislators — the lockstep judges' term.
 *
 * Art. IV §4 — judges carry "the same … duties including the capacity to be
 * removed from office by Supermajority vote" — removal parity with legislators.
 *
 * If an edit breaks these tests, that edit is a constitutional violation — fix
 * the edit, never the test.
 */
class JudiciaryCreationConversionTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_judiciary_creation_conversion';

    // ======================================================================
    // 1. PURE pins — supermajority sourcing + creation shape (DB-free)
    // ======================================================================

    /**
     * Art. IV §1 — creation is a SUPERMAJORITY chamber act; conversion is a
     * supermajority act carrying the SECOND (constituent) supermajority meter
     * (a DUAL act, Art. IV §3) — the same two-meter shape as the executive.
     */
    public function test_judiciary_vote_types_are_supermajority_with_the_dual_conversion_leg(): void
    {
        $create = ChamberVoteService::voteTypeConfig('judiciary_create');
        $this->assertSame('supermajority', $create['category'], 'Art. IV §1 — appointed creation is a supermajority act.');
        $this->assertSame('supermajority', $create['basis']);
        $this->assertSame('chamber', $create['engine']);
        $this->assertNull($create['dual'], 'Appointed creation has no constituent dual leg (Art. IV §1).');

        $convert = ChamberVoteService::voteTypeConfig('judiciary_convert');
        $this->assertSame('supermajority', $convert['category']);
        $this->assertSame('supermajority', $convert['basis'], 'Art. IV §3 — the chamber lane is supermajority of all serving.');
        $this->assertSame(
            'constituent_supermajority',
            $convert['dual'],
            'Art. IV §3 — conversion carries the SECOND (constituent) supermajority meter; it is a DUAL act.'
        );

        // The constituent's OWN per-chamber consent is ordinary majority (the
        // supermajority is ACROSS jurisdictions — the owner ruling, reused
        // verbatim from the executive design).
        $this->assertSame(
            'procedural_motion',
            \App\Services\Judiciary\JudiciaryActService::CONSTITUENT_CONSENT_VOTE_TYPE,
            'A constituent decides its own consent by ordinary majority; the SUPERMAJORITY is across jurisdictions.'
        );
    }

    /**
     * Art. IV §1 — both the chamber creation lane AND the constituent
     * conversion lane resolve their threshold through the PROTECTED
     * ConstitutionalValidator::supermajority (no reimplemented fraction).
     */
    public function test_both_judiciary_lanes_use_the_protected_supermajority(): void
    {
        foreach ([5, 7, 9, 41] as $serving) {
            $lane = ChamberVoteService::laneThresholds($serving, ChamberVote::BASIS_SUPERMAJORITY);
            $this->assertSame(
                ConstitutionalValidator::supermajority($serving),
                $lane['required_yes'],
                "Art. VII — the judiciary chamber lane at serving={$serving} must clear ceil(serving·2/3)."
            );
        }

        $this->assertSame(28, ConstitutionalValidator::supermajority(41), 'supermajority(41) = 28');
        $this->assertSame(5, ConstitutionalValidator::supermajority(7), 'supermajority(7) = 5');

        // The MJV constituent lane is sourced from the SAME protected function.
        $source = file_get_contents(app_path('Services/MultiJurisdictionVoteService.php'));
        $this->assertMatchesRegularExpression(
            '/ConstitutionalValidator::supermajority\s*\(\s*\$total\s*\)/',
            $source,
            'Art. IV §3 — the constituent supermajority lane snapshots through ConstitutionalValidator::supermajority($total).'
        );
        $this->assertSame(2, ConstitutionalValidator::supermajority(1), 'one constituent: required 2 — a lone yes never converts.');
    }

    /**
     * Art. IV §1 — the DEFAULT output of creation is APPOINTED, never elected;
     * the judiciary stub default type is appointed and the elected type is
     * reached ONLY through the conversion certification.
     */
    public function test_default_judiciary_type_is_appointed_never_elected_by_creation(): void
    {
        $this->assertSame('appointed', Judiciary::TYPE_APPOINTED);
        $this->assertSame('elected', Judiciary::TYPE_ELECTED);

        // The creation effect force-fills type=appointed; only certifyJudicial
        // flips it to elected (source-pinned — never a creation-time elected).
        $formation = file_get_contents(app_path('Services/Judiciary/JudiciaryFormationService.php'));
        $this->assertMatchesRegularExpression(
            "/'type'\s*=>\s*Judiciary::TYPE_APPOINTED/",
            $formation,
            'Art. IV §1 — creation always produces an APPOINTED court.'
        );
        $this->assertStringNotContainsString(
            'TYPE_ELECTED',
            $formation,
            'Art. IV §1 — the formation service never flips a court to elected; only conversion certification does.'
        );

        $certification = file_get_contents(app_path('Services/CertificationService.php'));
        $this->assertMatchesRegularExpression(
            "/'status'\s*=>\s*\\\\App\\\\Models\\\\Judiciary::STATUS_ELECTED/",
            $certification,
            'Art. IV §3 — only the judicial-election certification evolves the court to elected.'
        );
    }

    /**
     * Art. IV §1/§2 — the creation seat-pool shape: the committee path floors
     * at min_judges; the constituent path requires per-constituent ≥ 1; the
     * mode is DERIVED ($hasConstituents), never an input.
     */
    public function test_creation_shape_asserts_the_seat_pool_floor_and_derived_mode(): void
    {
        // Constituent path: per-constituent ≥ 1.
        JudiciaryFormationService::assertCreationShape(true, 5, 1, null); // ok
        JudiciaryFormationService::assertCreationShape(true, 5, 3, null); // ok

        $this->expectViolation('Art. IV §2', function () {
            JudiciaryFormationService::assertCreationShape(true, 5, 0, null);
        });

        // Committee path: bench ≥ min_judges.
        JudiciaryFormationService::assertCreationShape(false, 5, null, 5); // ok
        JudiciaryFormationService::assertCreationShape(false, 5, null, 9); // ok

        $this->expectViolation('Art. IV §1', function () {
            JudiciaryFormationService::assertCreationShape(false, 5, null, 4);
        });
        $this->expectViolation('Art. IV §1', function () {
            JudiciaryFormationService::assertCreationShape(false, 5, null, null);
        });
    }

    /**
     * Art. IV §1 — the conversion target (elected race) floors at min_judges
     * (judiciary_min_judges_per_race), no ceiling.
     */
    public function test_conversion_target_floors_at_the_minimum_judges_per_race(): void
    {
        JudiciaryFormationService::assertConversionTarget(5, 5);   // ok
        JudiciaryFormationService::assertConversionTarget(11, 5);  // ok — no ceiling

        $this->expectViolation('Art. IV §1', function () {
            JudiciaryFormationService::assertConversionTarget(4, 5);
        });
    }

    /**
     * Art. IV §2 — the HARDENED equal-constituent invariant: a uniform
     * allocation passes; any uneven allocation is rejected with the citation.
     */
    public function test_equal_constituent_nomination_is_hardened(): void
    {
        // Uniform — 2 each across three constituents.
        ConstitutionalValidator::assertEqualConstituentNomination(['a' => 2, 'b' => 2, 'c' => 2]);
        ConstitutionalValidator::assertEqualConstituentNomination(['a' => 1]);

        $this->expectViolation('Art. IV §2', function () {
            ConstitutionalValidator::assertEqualConstituentNomination(['a' => 2, 'b' => 1]);
        });
        $this->expectViolation('Art. IV §2', function () {
            ConstitutionalValidator::assertEqualConstituentNomination([]);
        });
    }

    /**
     * Art. IV §1 · Art. II §9 — the judicial/civil lockstep guard: the two
     * term lengths must be equal; a divergent pair is rejected.
     */
    public function test_judicial_civil_lockstep_guard(): void
    {
        ConstitutionalValidator::assertJudicialCivilLockstep(10, 10);
        ConstitutionalValidator::assertJudicialCivilLockstep(7, 7);

        $this->expectViolation('Art. IV §1 · Art. II §9', function () {
            ConstitutionalValidator::assertJudicialCivilLockstep(10, 7);
        });

        // Driven through F-LEG-031: changing only one side defaults the
        // companion to 10, so a non-10 value is rejected.
        $this->expectViolation('Art. IV §1 · Art. II §9', function () {
            (new ConstitutionalValidator)->checkSettingChange([
                'setting_key' => 'judicial_appointment_years',
                'value' => 8,
            ]);
        });
    }

    /**
     * ESM-18 (the judiciary status machine) — the enum the lifecycle walks,
     * and the same-row evolution by construction (the certification path
     * force-fills the existing judiciary row; nothing on the formation /
     * certification surface constructs a SECOND judiciary — ESM-18 is one
     * machine).
     */
    public function test_esm18_enum_and_same_row_evolution(): void
    {
        $this->assertSame('forming', Judiciary::STATUS_FORMING);
        $this->assertSame('creating', Judiciary::STATUS_CREATING);
        $this->assertSame('appointed', Judiciary::STATUS_APPOINTED);
        $this->assertSame('conversion_voted', Judiciary::STATUS_CONVERSION_VOTED);
        $this->assertSame('elected', Judiciary::STATUS_ELECTED);
        $this->assertSame('reverted', Judiciary::STATUS_REVERTED);

        // Conversion certification evolves the SAME row (forceFill on the
        // resolved $judiciary), never a second.
        $certification = file_get_contents(app_path('Services/CertificationService.php'));
        $this->assertMatchesRegularExpression(
            "/\\\$judiciary->forceFill\\(\\s*\\[\\s*'status'\\s*=>\\s*\\\\App\\\\Models\\\\Judiciary::STATUS_ELECTED/s",
            $certification,
            'Art. IV — certification must EVOLVE the judiciary row to elected (ESM-18: one machine).'
        );

        foreach ([
            'Services/CertificationService.php',
            'Services/Judiciary/JudiciaryFormationService.php',
            'Services/Judiciary/JudiciaryActService.php',
            'Services/Judiciary/JudicialSeatService.php',
        ] as $relative) {
            $this->assertDoesNotMatchRegularExpression(
                '/Judiciary::create\s*\(/',
                file_get_contents(app_path($relative)),
                "{$relative} constructs a second judiciary — conversion must evolve the SAME row (ESM-18)."
            );
        }
    }

    /**
     * Art. IV §4 — judge removal is the SAME supermajority machinery and
     * threshold as a legislator: judge_removal is an ACTIVE removal-proceeding
     * kind opened at officeholder_remove (supermajority) — NEVER the
     * ordinary-majority procedural_motion governor path (the contrast).
     */
    public function test_judge_removal_is_supermajority_parity_not_the_governor_path(): void
    {
        $this->assertContains(
            RemovalProceeding::KIND_JUDGE_REMOVAL,
            RemovalProceeding::ACTIVE_KINDS,
            'Art. IV §4 — judge removal is now an ACTIVE proceeding kind.'
        );

        // The removal vote opens at officeholder_remove (supermajority) — the
        // same vote type as a legislator impeachment.
        $oversight = file_get_contents(app_path('Services/Legislature/OversightService.php'));
        $this->assertMatchesRegularExpression(
            "/voteType:\s*'officeholder_remove'/",
            $oversight,
            'Art. IV §4 — the removal vote is officeholder_remove (supermajority).'
        );
        $this->assertSame(
            'supermajority',
            ChamberVoteService::voteTypeConfig('officeholder_remove')['basis'],
            'officeholder_remove is a supermajority vote class.'
        );

        // Contrast: the governor-removal path is ordinary-majority procedural —
        // judge removal is deliberately NOT that softer threshold.
        $this->assertSame(
            'procedural_motion',
            \App\Services\Executive\ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE,
            'Governor removal is ordinary-majority hiring-and-firing — judge removal is the harsher supermajority.'
        );
    }

    // ======================================================================
    // 2. LIVE rolled-back pins (guarded pg; one transaction, ALWAYS rolled back)
    // ======================================================================

    /**
     * EXIT CRITERION #1 — APPOINTED creation with a CONSENTED, equal-per-
     * constituent bench and 10-year terms, end to end through the engine.
     *
     * F-LEG-017 propose → (41-seat chamber supermajority adopts) → the SAME
     * judiciary row evolves forming → creating, mode DERIVED 'constituent'
     * (San Marino HAS a constituent), the seat pool allocated EQUALLY per
     * constituent; then F-LEG-021 nomination + bog_consent (cast via F-LEG-004)
     * seats each judge with a 10-year civil-appointment term (CLK-09 armed at
     * ends_on), the court advances creating → appointed, and the judge derives
     * R-19. ONE judiciary row throughout (ESM-18).
     */
    public function test_appointed_creation_seats_an_equal_per_constituent_bench_with_ten_year_terms(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $engine = app(ConstitutionalEngine::class);

            [$legislature, $judiciary, $constituentIds] = $this->formingJudiciaryWithConstituents($conn, 1);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $this->assertSame(
                1,
                Judiciary::query()->where('jurisdiction_id', $jurisdictionId)->count(),
                'ESM-18: exactly one judiciary row per jurisdiction before creation.'
            );

            // Engine filing: F-LEG-017 propose — supermajority chamber vote
            // (judiciary_create) opens. judges_per_constituent = 5 so the bench
            // floors at min_judges (5 × 1 constituent = 5 ≥ 5).
            $proposed = $engine->file('F-LEG-017', $this->nonSpeakerUser($legislature), [
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => $jurisdictionId,
                'court_name' => 'Throwaway Superior Court',
                'function_text' => 'JudiciaryCreationConversionTest throwaway — appointed court.',
                'judges_per_constituent' => 5,
            ]);

            $proposal = ChamberVoteProposal::query()->findOrFail($proposed->recorded['proposal_id']);
            $this->assertSame(ChamberVoteProposal::KIND_JUDICIARY_CREATION, $proposal->proposal_kind);

            $vote = ChamberVote::query()->findOrFail($proposed->recorded['vote_id']);
            $this->assertSame('judiciary_create', $vote->vote_type);
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis, 'Art. IV §1 — creation is supermajority.');

            // Every serving non-Speaker member casts yes → the supermajority
            // adopts, firing applyCreation in the SAME txn.
            $this->castAllYes($engine, $legislature, (string) $vote->id);

            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $vote->refresh()->outcome);

            $judiciary->refresh();
            $this->assertSame(Judiciary::STATUS_CREATING, $judiciary->status,
                'The court is `creating` — the bench is allocated but not yet consented.');
            $this->assertSame(Judiciary::TYPE_APPOINTED, $judiciary->type, 'Art. IV §1 — creation always produces an appointed court.');
            $this->assertSame(Judiciary::NOMINATION_CONSTITUENT, $judiciary->nomination_mode,
                'Art. IV §2 — San Marino HAS a constituent, so the mode is DERIVED constituent.');
            $this->assertSame(5, (int) $judiciary->judge_count, 'judge_count = 5 per constituent × 1 constituent = 5 (≥ min_judges).');
            $this->assertNotNull($judiciary->creation_law_id, 'The charter law enacted.');

            // The creation law is scoped to the new court (the §F cases-agent
            // substrate reservation).
            $this->assertSame(
                (string) $judiciary->id,
                (string) DB::table('laws')->where('id', (string) $judiciary->creation_law_id)->value('scope_judiciary_id'),
                'The creation law is scoped to the court (judicial-remedy versioning substrate).'
            );

            // EQUAL per constituent (Art. IV §2): 5 seats, all nominated by the
            // one constituent → uniform.
            $counts = app(JudiciaryFormationService::class)->seatCountsByConstituent($judiciary);
            $this->assertSame([5], array_values($counts), 'Art. IV §2 — equal allocation per constituent (5 to the one constituent).');
            ConstitutionalValidator::assertEqualConstituentNomination($counts); // does not throw

            // ── Seat the bench (§B.3 — nominate + bog_consent per seat) ───────
            $seats = JudicialSeat::query()
                ->where('judiciary_id', $judiciary->id)
                ->where('status', JudicialSeat::STATUS_VACANT)
                ->orderBy('seat_number')
                ->get();

            $this->assertCount(5, $seats);

            $nominees = $this->associatedUsers($jurisdictionId, 5);
            $seatService = app(JudicialSeatService::class);

            $firstTermId = null;

            foreach ($seats as $i => $seat) {
                $this->assertSame(JudicialSeat::CLASS_CONSTITUENT_NOMINATED, $seat->seat_class);
                $this->assertSame($constituentIds[0], (string) $seat->nominating_jurisdiction_id,
                    'Each seat is tagged to the nominating constituent.');

                $out = $seatService->nominate(
                    $seat,
                    (string) $nominees[$i]->id,
                    $constituentIds[0],
                );

                // The consent vote rides bog_consent (ordinary majority of all
                // serving — vacancy stays in the denominator, the F-LEG-021
                // threshold). Drive it via the engine (F-LEG-004 casts).
                $consentVote = ChamberVote::query()->findOrFail($out['consent_vote_id']);
                $this->assertSame(JudicialSeatService::CONSENT_VOTE_TYPE, $consentVote->vote_type);
                $this->assertSame(ChamberVote::BASIS_MAJORITY, $consentVote->threshold_basis,
                    'Art. IV — the consent threshold is ordinary majority of all serving (bog_consent).');

                $this->castAllYes($engine, $legislature, (string) $consentVote->id);

                $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $consentVote->refresh()->outcome);

                $seat->refresh();
                $this->assertSame(JudicialSeat::STATUS_SEATED, $seat->status, 'The consented judge is seated.');
                $this->assertSame((string) $nominees[$i]->id, (string) $seat->user_id);
                $this->assertNotNull($seat->term_id);

                // 10-year civil-appointment term (Art. IV §1 · Art. II §9).
                $term = Term::query()->findOrFail($seat->term_id);
                $this->assertSame('judicial_seat', $term->office_kind);
                $this->assertSame(Term::CLASS_CIVIL_APPOINTMENT, $term->term_class);
                $this->assertSame(
                    10,
                    (int) CarbonImmutable::parse($term->starts_on)->diffInYears(CarbonImmutable::parse($term->ends_on)),
                    'Art. IV §1 — a judicial appointment lasts 10 years.'
                );

                // CLK-09 armed at the term's ends_on.
                $clk09 = ClockTimer::query()
                    ->where('clock_id', 'CLK-09')
                    ->where('subject_type', 'term')
                    ->where('subject_id', (string) $term->id)
                    ->first();
                $this->assertNotNull($clk09, 'CLK-09 is armed at the judicial term expiry.');

                // R-19 derives for the seated APPOINTED judge.
                $this->assertContains('R-19', app(\App\Services\RoleService::class)->rolesFor($nominees[$i]),
                    'Art. IV — a seated appointed judge derives R-19.');

                $firstTermId ??= (string) $term->id;
            }

            // ── The court advances creating → appointed (§B.4) ───────────────
            $judiciary->refresh();
            $this->assertSame(Judiciary::STATUS_APPOINTED, $judiciary->status,
                'Art. IV §1 — every seat consented and the equal-constituent invariant holds: the court is appointed.');
            $this->assertContains($judiciary->status, Judiciary::OPERATING_STATUSES,
                'The appointed court is in an operating status — the cases-agent entry gate (§F).');

            // ESM-18: still exactly one judiciary row across the whole creation.
            $this->assertSame(
                1,
                Judiciary::query()->where('jurisdiction_id', $jurisdictionId)->count(),
                'ESM-18: no second judiciary row across creation + seating.'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * EXIT CRITERION #2 — conversion to an ELECTED court via DUAL supermajority,
     * end to end through the engine, and the STV-elected judges seated lockstep.
     *
     * LANE A (San Marino — chamber lane PASSES, the constituent MJV lane OPENS,
     * a lone constituent cannot clear the dual supermajority): F-LEG-018 propose
     * on the now-appointed court → 41-seat supermajority adopts → the
     * constituent process opens with required = supermajority(constituent_total)
     * via the SAME protected function → the lone constituent's yes (required 2
     * of 1) FAILS the dual lane → the SAME judiciary row reverts to its
     * appointed footing. One lane does not convert; no election.
     *
     * LANE B (Montegiardino — no constituents): the chamber supermajority alone
     * converts (Art. IV §3) and schedules the judicial_group election against
     * the SAME judiciary id; then a certified STV tabulation is seated by
     * certifyJudicial → the court evolves conversion_voted → elected (the SAME
     * row), elected judges hold LOCKSTEP terms ending on the chamber's
     * term_ends_on, and each derives R-20.
     */
    public function test_conversion_runs_the_dual_supermajority_and_seats_elected_judges_lockstep(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            // Pin the wiring substrate live (the D-9 analogue gaps, now closed).
            $constraint = $conn->selectOne(
                "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'chamber_vote_proposals_kind_check'"
            );
            $this->assertStringContainsString('judiciary_conversion', (string) $constraint->def,
                'The F-LEG-018 proposal insert needs the kind CHECK to admit judiciary_conversion.');
            $seatKind = $conn->selectOne(
                "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'election_races_seat_kind_check'"
            );
            $this->assertStringContainsString('judicial_group', (string) $seatKind->def,
                'The judicial election needs election_races_seat_kind_check to admit judicial_group.');

            $engine = app(ConstitutionalEngine::class);

            // ── LANE A — San Marino: a lone constituent cannot clear the dual ─
            [$legislature, $judiciary, $constituentIds] = $this->appointedJudiciaryWithConstituents($conn, 1);
            $jurisdictionId = (string) $legislature->jurisdiction_id;

            $proposed = $engine->file('F-LEG-018', $this->nonSpeakerUser($legislature), [
                'action' => 'propose',
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => $jurisdictionId,
                'judge_count' => 5,
                'charter_text' => 'JudiciaryCreationConversionTest throwaway — elected court.',
            ]);

            $proposal = ChamberVoteProposal::query()->findOrFail($proposed->recorded['proposal_id']);
            $this->assertSame(ChamberVoteProposal::KIND_JUDICIARY_CONVERSION, $proposal->proposal_kind);

            $vote = ChamberVote::query()->findOrFail($proposed->recorded['vote_id']);
            $this->assertSame('judiciary_convert', $vote->vote_type);
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis);

            $this->castAllYes($engine, $legislature, (string) $vote->id);
            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $vote->refresh()->outcome);

            $proposal->refresh();
            $this->assertSame('multi_jurisdiction_votes', $proposal->result_type,
                'applyConversionAdoption opened the constituent dual-supermajority process (constituents exist).');

            $judiciary->refresh();
            $this->assertSame(Judiciary::STATUS_CONVERSION_VOTED, $judiciary->status,
                'ESM-18: the same row evolved to conversion_voted — the chamber lane decided.');
            $this->assertNotNull($judiciary->conversion_process_id);

            $process = MultiJurisdictionVote::query()->findOrFail((string) $judiciary->conversion_process_id);
            $this->assertSame('judiciary_convert', $process->kind);
            $this->assertSame('judiciaries', $process->subject_type);
            $this->assertSame((string) $judiciary->id, (string) $process->subject_id);
            $this->assertSame(
                ConstitutionalValidator::supermajority((int) $process->constituent_total),
                (int) $process->required,
                'Art. IV §3 — the constituent lane requires supermajority(constituent_total), the protected function.'
            );

            // The constituent opens + adopts its OWN ordinary-majority consent —
            // but a lone yes of one cannot reach supermajority(1)=2 → FAILS.
            $constituentLeg = $this->constituentChamber($constituentIds[0]);
            $opened = $engine->file('F-LEG-018', $this->nonSpeakerUser($constituentLeg), [
                'action' => 'open_constituent_consent',
                'process_id' => (string) $process->id,
                'legislature_id' => (string) $constituentLeg->id,
                'jurisdiction_id' => (string) $constituentLeg->jurisdiction_id,
            ]);

            $consentVote = ChamberVote::query()->findOrFail($opened->recorded['consent_vote_id']);
            $this->assertSame(
                \App\Services\Judiciary\JudiciaryActService::CONSTITUENT_CONSENT_VOTE_TYPE,
                $consentVote->vote_type,
                'A constituent decides its OWN consent by ordinary majority; the supermajority is ACROSS jurisdictions.'
            );

            $this->castAllYes($engine, $constituentLeg, (string) $consentVote->id);

            $process->refresh();
            $this->assertSame(1, (int) $process->yes_count);
            $this->assertSame(MultiJurisdictionVote::STATUS_FAILED, $process->status,
                'Art. IV §3 — a single constituent yes cannot reach supermajority(1)=2; the dual lane FAILS.');

            // onProcessEvaluated (subject judiciaries) reverted the SAME row to
            // its appointed footing — never elected, NO judicial election.
            $judiciary->refresh();
            $this->assertSame(Judiciary::STATUS_REVERTED, $judiciary->status,
                'Art. IV §3 — a failed dual lane reverts the court to its appointed footing; nothing re-seats.');
            $this->assertNotSame(Judiciary::STATUS_ELECTED, $judiciary->status);
            $this->assertSame(
                0,
                DB::table('elections')->where('judiciary_id', (string) $judiciary->id)->where('kind', 'judicial')->count(),
                'No judicial election schedules while the dual supermajority is unmet.'
            );
            $this->assertSame(
                1,
                Judiciary::query()->where('jurisdiction_id', $jurisdictionId)->count(),
                'ESM-18: still exactly one judiciary row — one lane never forks a second court.'
            );

            // ── LANE B — Montegiardino: no constituents → chamber supermajority
            //    alone converts and the judicial_group election schedules ──────
            [$soloLeg, $soloJud] = $this->appointedJudiciaryWithoutConstituents($conn);
            $soloJurisdictionId = (string) $soloLeg->jurisdiction_id;

            $soloProposed = $engine->file('F-LEG-018', $this->nonSpeakerUser($soloLeg), [
                'action' => 'propose',
                'legislature_id' => (string) $soloLeg->id,
                'jurisdiction_id' => $soloJurisdictionId,
                'judge_count' => 5,
                'charter_text' => 'JudiciaryCreationConversionTest throwaway — solo elected court.',
            ]);

            $soloVote = ChamberVote::query()->findOrFail($soloProposed->recorded['vote_id']);
            $this->assertSame(5, ConstitutionalValidator::supermajority((int) $soloVote->serving_snapshot),
                'Art. VII — supermajority(7) = 5; the chamber lane clears the protected threshold.');

            $this->castAllYes($engine, $soloLeg, (string) $soloVote->id);
            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $soloVote->refresh()->outcome);

            $soloProposal = ChamberVoteProposal::query()->findOrFail($soloProposed->recorded['proposal_id']);
            $this->assertSame('judiciaries', $soloProposal->result_type,
                'No constituents: the conversion completes on the chamber supermajority alone (Art. IV §3).');

            $soloJud->refresh();
            $this->assertSame(Judiciary::STATUS_CONVERSION_VOTED, $soloJud->status);

            $election = Election::query()
                ->where('judiciary_id', (string) $soloJud->id)
                ->where('kind', Election::KIND_JUDICIAL)
                ->firstOrFail();
            $this->assertSame((string) $soloJud->id, (string) $election->judiciary_id,
                'ESM-18: the election is against the SAME judiciary row.');
            $this->assertSame('conversion_act', (string) $election->trigger);

            $race = ElectionRace::query()->where('election_id', (string) $election->id)->firstOrFail();
            $this->assertSame(ElectionRace::SEAT_KIND_JUDICIAL_GROUP, $race->seat_kind,
                'Art. IV §3 — judges are elected in a GROUP (judicial_group race).');
            $this->assertSame(5, (int) $race->seats, 'min 5 judges per race (Art. IV §1).');

            // ── Seat the elected judges via certifyJudicial (STV winners) ─────
            $winners = $this->seatElectedBench($soloLeg, $soloJud, $election, $race, 5);

            $soloJud->refresh();
            $this->assertSame(Judiciary::STATUS_ELECTED, $soloJud->status,
                'Art. IV §3 — the certified election evolves the court conversion_voted → elected (the SAME row).');
            $this->assertSame(Judiciary::TYPE_ELECTED, $soloJud->type);
            $this->assertNotNull($soloJud->converted_at);

            $electedSeats = JudicialSeat::query()
                ->where('judiciary_id', $soloJud->id)
                ->where('status', JudicialSeat::STATUS_SEATED)
                ->where('seat_class', JudicialSeat::CLASS_ELECTED)
                ->get();
            $this->assertCount(5, $electedSeats, 'Five elected judges seated.');

            // LOCKSTEP terms ending on the chamber's term_ends_on (Art. IV §3 —
            // "their terms last for the same length"; the inherited window).
            foreach ($electedSeats as $seat) {
                $term = Term::query()->findOrFail($seat->term_id);
                $this->assertSame(Term::CLASS_LOCKSTEP, $term->term_class, 'Art. IV §3 — elected judges hold lockstep terms.');
                $this->assertSame(
                    CarbonImmutable::parse($soloLeg->term_ends_on)->toDateString(),
                    CarbonImmutable::parse($term->ends_on)->toDateString(),
                    'Art. IV §3 — the first elected term inherits the chamber\'s remaining lockstep window (CLK-10).'
                );
            }

            // R-20 derives for an elected judge.
            $electedUser = User::query()->findOrFail((string) $electedSeats->first()->user_id);
            $this->assertContains('R-20', app(\App\Services\RoleService::class)->rolesFor($electedUser),
                'Art. IV — a seated elected judge derives R-20.');

            $this->assertSame(
                1,
                Judiciary::query()->where('jurisdiction_id', $soloJurisdictionId)->count(),
                'ESM-18: still exactly one judiciary row across the whole conversion.'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * A forming judiciary whose chamber has EXACTLY $expected constituent
     * legislatures with serving members (San Marino → 1).
     *
     * @return array{0: Legislature, 1: Judiciary, 2: list<string>}
     */
    private function formingJudiciaryWithConstituents(Connection $conn, int $expected): array
    {
        foreach ($this->formingJudiciaries($conn) as [$legislature, $judiciary]) {
            $constituents = JudiciaryFormationService::constituentJurisdictionIds($legislature);
            $withMembers = $this->constituentsWithServingMembers($conn, $constituents);

            if (count($withMembers) === $expected && count($constituents) === $expected) {
                $this->resetJudiciaryToForming($conn, $judiciary);

                return [$legislature, $judiciary->refresh(), $withMembers];
            }
        }

        $this->markTestSkipped("Live DB has no judiciary whose chamber has exactly {$expected} constituent legislature(s) — seed San Marino.");
    }

    /**
     * Reset a (possibly demo-seeded appointed/elected) court to a clean
     * `forming` footing — status, lifecycle pointers, and the seat pool —
     * inside the caller's rolled-back transaction, so the F-LEG-017 creation
     * flow runs from scratch with zero residue.
     */
    private function resetJudiciaryToForming(Connection $conn, Judiciary $judiciary): void
    {
        $seatIds = $conn->table('judicial_seats')->where('judiciary_id', $judiciary->id)->pluck('id')->all();

        if ($seatIds !== []) {
            // Demo cases seat these judges on panels and attribute opinions /
            // sentences / warrants to them. Clear every FK to the seat pool
            // before tearing it down — all inside this test's rolled-back
            // transaction (zero residue). None of these tables is append-only
            // (only case_filings is, and it references cases, not seats).
            $conn->table('panel_judges')->whereIn('judicial_seat_id', $seatIds)->delete();
            $conn->table('panels')->whereIn('presiding_judge_seat_id', $seatIds)->update(['presiding_judge_seat_id' => null]);
            $conn->table('opinions')->whereIn('authored_by_seat_id', $seatIds)->delete();
            $conn->table('sentencing_orders')->whereIn('issued_by_seat_id', $seatIds)->delete();
            $conn->table('warrants')->whereIn('issued_by_seat_id', $seatIds)->delete();
        }

        $conn->table('judicial_nominations')->where('judiciary_id', $judiciary->id)->delete();
        $conn->table('judicial_seats')->where('judiciary_id', $judiciary->id)->delete();

        $judiciary->forceFill([
            'status' => Judiciary::STATUS_FORMING,
            'type' => Judiciary::TYPE_APPOINTED,
            'nomination_mode' => null,
            'judge_count' => null,
            'creation_law_id' => null,
            'conversion_process_id' => null,
            'conversion_law_id' => null,
            'converted_at' => null,
            'source_legislature_id' => null,
        ])->save();
    }

    /**
     * Drive a forming judiciary all the way to APPOINTED (creation + consent)
     * so the conversion test has a real appointed court to convert, with
     * $expected constituents.
     *
     * @return array{0: Legislature, 1: Judiciary, 2: list<string>}
     */
    private function appointedJudiciaryWithConstituents(Connection $conn, int $expected): array
    {
        [$legislature, $judiciary, $constituentIds] = $this->formingJudiciaryWithConstituents($conn, $expected);

        $this->driveToAppointed(app(ConstitutionalEngine::class), $legislature, $judiciary, $constituentIds);

        return [$legislature, $judiciary->refresh(), $constituentIds];
    }

    /**
     * A forming judiciary whose chamber has NO constituent legislatures, driven
     * to APPOINTED (committee path).
     *
     * @return array{0: Legislature, 1: Judiciary}
     */
    private function appointedJudiciaryWithoutConstituents(Connection $conn): array
    {
        foreach ($this->formingJudiciaries($conn) as [$legislature, $judiciary]) {
            if (JudiciaryFormationService::constituentJurisdictionIds($legislature) === []) {
                $this->resetJudiciaryToForming($conn, $judiciary);
                $this->driveToAppointed(app(ConstitutionalEngine::class), $legislature, $judiciary->refresh(), []);

                return [$legislature, $judiciary->refresh()];
            }
        }

        $this->markTestSkipped('Live DB has no judiciary whose chamber has zero constituents — seed Montegiardino.');
    }

    /**
     * Engine-drive F-LEG-017 creation + the F-LEG-021 consent of every seat so
     * the court reaches `appointed`. Constituent path nominates per the
     * constituent; committee path nominates the slate.
     *
     * @param  list<string>  $constituentIds
     */
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
            'court_name' => 'Throwaway Superior Court',
            'function_text' => 'JudiciaryCreationConversionTest throwaway — appointed court for conversion.',
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

    /**
     * Build a certified STV tabulation of $seats winners for the judicial race
     * and run the certify pipeline — seating the elected bench (certifyJudicial).
     *
     * @return list<string> winner user ids
     */
    private function seatElectedBench(
        Legislature $legislature,
        Judiciary $judiciary,
        Election $election,
        ElectionRace $race,
        int $seats,
    ): array {
        $voters = $this->associatedUsers((string) $legislature->jurisdiction_id, $seats);

        $tabulation = Tabulation::create([
            'race_id' => (string) $race->id,
            'kind' => Tabulation::KIND_INITIAL,
            'engine_version' => \App\Services\VoteCountingService::ENGINE_VERSION,
            'total_valid' => $seats,
            'quota' => 1,
            'seats' => $seats,
            'status' => Tabulation::STATUS_COMPLETE,
            'completed_at' => now(),
            'record_hash' => hash('sha256', 'judiciary-test-'.$race->id),
        ]);

        $winnerIds = [];

        foreach ($voters as $i => $voter) {
            $candidacy = Candidacy::create([
                'election_id' => (string) $election->id,
                'race_id' => (string) $race->id,
                'user_id' => (string) $voter->id,
                'status' => Candidacy::STATUS_FINALIST,
                'residency_attested_at' => now(),
                'validated_at' => now(),
            ]);

            RaceResult::create([
                'tabulation_id' => (string) $tabulation->id,
                'candidacy_id' => (string) $candidacy->id,
                'round_elected' => 1,
                'seat_no' => $i + 1,
                'vote_share_norm' => '1.0000',
            ]);

            $winnerIds[] = (string) $voter->id;
        }

        $certification = ElectionCertification::create([
            'election_id' => (string) $election->id,
            'election_board_id' => (string) $election->election_board_id,
            'certified_at' => now(),
            'count_record_hash' => (string) $tabulation->record_hash,
            'status' => ElectionCertification::STATUS_CERTIFIED,
        ]);

        app(\App\Services\CertificationService::class)->certify($election->refresh(), $certification);

        return $winnerIds;
    }

    /**
     * Every (chamber, judiciary) pair in `forming`: a forming judiciary whose
     * jurisdiction holds a non-dissolved, term-bounded chamber with serving
     * members.
     *
     * @return list<array{0: Legislature, 1: Judiciary}>
     */
    private function formingJudiciaries(Connection $conn): array
    {
        $pairs = [];

        // Select by STRUCTURE (a chamber with serving members), NOT by judiciary
        // lifecycle stage: institutions:demo-e leaves San Marino's court
        // `appointed`, and it is the only one with constituents. The specific
        // helpers RESET the chosen court to `forming` inside this test's
        // rolled-back transaction, so the F-LEG-017 flow runs from scratch —
        // never coupled to the live judiciary stage (the demo-d/ExecConversion
        // precedent).
        $judiciaries = Judiciary::query()
            ->where('status', '!=', 'dissolved')
            ->whereNull('deleted_at')
            ->get();

        foreach ($judiciaries as $judiciary) {
            $legislature = Legislature::query()
                ->where('jurisdiction_id', $judiciary->jurisdiction_id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'dissolved')
                ->whereNotNull('term_ends_on')
                ->first();

            if ($legislature !== null
                && $legislature->members()->whereIn('status', ['elected', 'seated'])->exists()) {
                $pairs[] = [$legislature, $judiciary];
            }
        }

        return $pairs;
    }

    /**
     * Of the given constituent jurisdiction ids, those whose legislature has
     * serving members (only such chambers can actually decide consent).
     *
     * @param  list<string>  $constituentIds
     * @return list<string>
     */
    private function constituentsWithServingMembers(Connection $conn, array $constituentIds): array
    {
        if ($constituentIds === []) {
            return [];
        }

        return $conn->table('jurisdictions as j')
            ->join('legislatures as l', 'l.jurisdiction_id', '=', 'j.id')
            ->whereIn('j.id', $constituentIds)
            ->whereNull('l.deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('legislature_members as m')
                    ->whereColumn('m.legislature_id', 'l.id')
                    ->whereIn('m.status', ['elected', 'seated']);
            })
            ->pluck('j.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** The constituent chamber for a given constituent jurisdiction id. */
    private function constituentChamber(string $jurisdictionId): Legislature
    {
        return Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'dissolved')
            ->firstOrFail();
    }

    /**
     * $n users holding an active association in the jurisdiction (judicial
     * nominee eligibility = association ONLY, Art. I).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function associatedUsers(string $jurisdictionId, int $n)
    {
        $ids = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->limit($n)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (count($ids) < $n) {
            $this->markTestSkipped("Live DB has fewer than {$n} associated users in the jurisdiction — seed residents.");
        }

        return User::query()->whereIn('id', $ids)->get()->values();
    }

    /** A serving NON-Speaker member's User of the chamber (the R-09 actor). */
    private function nonSpeakerUser(Legislature $legislature): User
    {
        $member = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }

    /** Drive every serving NON-Speaker member's yes cast until the vote closes. */
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

    private function expectViolation(string $citationFragment, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected a ConstitutionalViolation citing {$citationFragment}, none thrown.");
        } catch (ConstitutionalViolation $e) {
            $this->assertStringContainsString($citationFragment, $e->citation ?? $e->getMessage());
        }
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
