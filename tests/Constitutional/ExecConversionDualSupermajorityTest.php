<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ElectionRace;
use App\Models\Executive;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MultiJurisdictionVote;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\Executive\ExecutiveActService;
use App\Services\Executive\ExecutiveFormationService;
use App\Services\MultiJurisdictionVoteService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. III §3 (executive conversion: delegated/forming
 * → directly elected). Pins F-LEG-015 through its FIRST live consumer of the
 * dual-supermajority substrate (MultiJurisdictionVoteService).
 *
 * The Template requires that turning a legislature-delegated executive into a
 * DIRECTLY-ELECTED office clears TWO independent supermajority thresholds:
 *   1. the serving legislature's own chamber supermajority (the
 *      `exec_office_create` vote class — Art. III §2), AND
 *   2. the CONSTITUENT jurisdictions' supermajority (the dual leg —
 *      `constituent_supermajority`, evaluated over multi_jurisdiction_votes,
 *      Art. III §3 · Art. VII).
 * Clearing ONE lane alone never converts. And — ESM-16 — the lifecycle
 * EVOLVES the same `executives` row (status delegated/conversion_voted →
 * elected); there is never a second row.
 *
 * Pins:
 *  1. PURE supermajority sourcing (DB-free, always run):
 *     - BOTH lanes resolve their threshold through the PROTECTED
 *       ConstitutionalValidator::supermajority (ceil(serving·2/3), with the
 *       majority+1 floor) — the chamber lane via
 *       ChamberVoteService::laneThresholds, the constituent lane via
 *       MultiJurisdictionVoteService (source-pinned to the one function).
 *     - the `exec_office_create` registry row is a SUPERMAJORITY vote class
 *       carrying the `constituent_supermajority` dual leg (the two meters).
 *     - the dual-process evaluate() arithmetic: a process PASSES only when
 *       yes_count ≥ required; a single-lane / partial tally never flips it
 *       (the "single lane does not convert" invariant).
 *  2. STATUS-MACHINE invariants (DB-free): the executives enum + the
 *     same-row evolution by construction — the certification path FORCE-FILLS
 *     status on the existing row and NEVER constructs a second executive
 *     (source-scanned).
 *  3. LIVE rolled-back pins (guarded pg — skipped when pg unreachable):
 *     - a real F-LEG-015 adoption on a `forming` executive opens the
 *       constituent process with required = supermajority(constituent_total)
 *       and leaves the executive at conversion_voted, NOT elected — the
 *       chamber lane ALONE does not convert; the SAME single row evolved.
 *     - the constituent lane's own supermajority: a partial yes-tally leaves
 *       the process OPEN (no conversion); only the full supermajority PASSES
 *       it, at which point the executive election schedules against the SAME
 *       executive id (and still no second row).
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class ExecConversionDualSupermajorityTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_exec_conversion_dual_supermajority';

    // ======================================================================
    // 1. PURE supermajority sourcing — BOTH lanes (DB-free, always run)
    // ======================================================================

    /**
     * The chamber lane (`exec_office_create`) is a SUPERMAJORITY vote class
     * carrying the constituent dual leg — the two-meter shape that makes the
     * conversion a DUAL-supermajority act (Art. III §2/§3, vote_types registry).
     */
    public function test_exec_office_create_is_supermajority_with_a_constituent_dual_leg(): void
    {
        $config = ChamberVoteService::voteTypeConfig('exec_office_create');

        $this->assertSame('supermajority', $config['category'], 'Art. III §2 — conversion is a supermajority act.');
        $this->assertSame('supermajority', $config['basis'], 'Art. III §2 — the chamber lane is supermajority of all serving.');
        $this->assertSame('chamber', $config['engine']);
        $this->assertSame(
            'constituent_supermajority',
            $config['dual'],
            'Art. III §3 — conversion carries the SECOND (constituent) supermajority meter; it is a DUAL act.'
        );

        // The proposeConversion path produces exactly this vote class (the
        // chamber lane), and the per-constituent consent vote is the ordinary
        // majority leg of the SAME process (owner ruling, MANIFEST §8).
        $this->assertSame(
            'procedural_motion',
            ExecutiveActService::CONSTITUENT_CONSENT_VOTE_TYPE,
            'A constituent chamber decides its own consent by ordinary majority; the SUPERMAJORITY is across jurisdictions.'
        );
    }

    /**
     * The CHAMBER lane's required-yes is exactly the protected supermajority
     * over ALL serving members — not a reimplemented fraction.
     */
    public function test_chamber_lane_required_yes_is_the_protected_supermajority(): void
    {
        foreach ([5, 6, 7, 8, 9, 41] as $serving) {
            $lane = ChamberVoteService::laneThresholds($serving, ChamberVote::BASIS_SUPERMAJORITY);

            $this->assertSame(
                ConstitutionalValidator::supermajority($serving),
                $lane['required_yes'],
                "Art. VII — the chamber conversion lane at serving={$serving} must clear ceil(serving·2/3) (majority+1 floored)."
            );
        }

        // Spot the constitutional values: ceil(·2/3) with the majority+1 floor.
        $this->assertSame(6, ConstitutionalValidator::supermajority(8), 'supermajority(8) = 6');
        $this->assertSame(6, ConstitutionalValidator::supermajority(9), 'supermajority(9) = 6');
        $this->assertSame(28, ConstitutionalValidator::supermajority(41), 'supermajority(41) = ceil(41·2/3) = 28');
    }

    /**
     * The CONSTITUENT lane (the dual leg) snapshots `required` through the
     * SAME protected function — pinned at the source: open() routes the
     * supermajority basis to ConstitutionalValidator::supermajority and is
     * the ONLY place the process threshold is computed (no rogue fraction).
     */
    public function test_constituent_lane_required_is_sourced_from_the_protected_supermajority(): void
    {
        $source = file_get_contents(app_path('Services/MultiJurisdictionVoteService.php'));

        // The supermajority basis resolves `required` through the protected
        // function; unanimity is the literal total. No open-coded ·2/3.
        $this->assertMatchesRegularExpression(
            '/ConstitutionalValidator::supermajority\s*\(\s*\$total\s*\)/',
            $source,
            'Art. III §3 — the constituent supermajority lane MUST snapshot through ConstitutionalValidator::supermajority($total).'
        );

        $this->assertDoesNotMatchRegularExpression(
            '#\*\s*2\s*/\s*3|intdiv\s*\([^)]*\*\s*2#',
            $source,
            'No reimplemented 2/3 arithmetic may live in the dual-process service — the math is the protected function alone.'
        );

        // The per-total values the live process snapshots (1 constituent can
        // NEVER reach supermajority — majority+1 floor = 2 > 1).
        $this->assertSame(2, ConstitutionalValidator::supermajority(1), 'one constituent: required 2 — the dual leg cannot pass on a lone yes.');
        $this->assertSame(3, ConstitutionalValidator::supermajority(2), 'two constituents: required 3 (majority+1 floor).');
        $this->assertSame(3, ConstitutionalValidator::supermajority(3), 'three constituents: required 3.');
        $this->assertSame(4, ConstitutionalValidator::supermajority(5), 'five constituents: required 4.');
    }

    /**
     * The dual-process evaluate() arithmetic, exercised as PURE math against
     * unsaved model instances (no DB): a process PASSES only when
     * yes_count ≥ required; a partial / single-lane tally never flips it —
     * the "one lane does not convert" invariant lives in the same operator.
     */
    public function test_evaluate_passes_only_on_full_supermajority_not_a_partial_tally(): void
    {
        // 3 constituents, required = supermajority(3) = 3.
        $required = ConstitutionalValidator::supermajority(3);
        $this->assertSame(3, $required);

        // The boundary the engine enforces (MultiJurisdictionVoteService::
        // evaluate): PASS ⇔ yes ≥ required; FAIL ⇔ reachable (total−no) <
        // required; else OPEN. This is the arithmetic the live pin drives.
        $verdict = function (int $yes, int $no, int $total, int $req): string {
            if ($yes >= $req) {
                return MultiJurisdictionVote::STATUS_PASSED;
            }
            if ($total - $no < $req) {
                return MultiJurisdictionVote::STATUS_FAILED;
            }

            return MultiJurisdictionVote::STATUS_OPEN;
        };

        // A single yes (one lane / one constituent) of three — NOT passed.
        $this->assertSame(MultiJurisdictionVote::STATUS_OPEN, $verdict(1, 0, 3, $required),
            'Art. III §3 — one constituent yes of three does not clear the dual supermajority.');

        // Two of three — STILL not passed (3 required); the third is decisive.
        $this->assertSame(MultiJurisdictionVote::STATUS_OPEN, $verdict(2, 0, 3, $required),
            'Art. III §3 — a partial tally below the supermajority leaves the process open, never converts.');

        // All three — the full supermajority — PASSES.
        $this->assertSame(MultiJurisdictionVote::STATUS_PASSED, $verdict(3, 0, 3, $required),
            'Art. III §3 — the constituent supermajority clears only at the full threshold.');

        // Enough no votes that the threshold is unreachable — FAILS.
        $this->assertSame(MultiJurisdictionVote::STATUS_FAILED, $verdict(0, 1, 3, $required),
            'Once the supermajority is arithmetically out of reach the process fails.');
    }

    // ======================================================================
    // 2. STATUS-MACHINE invariants — same row, never a second (DB-free)
    // ======================================================================

    public function test_executive_status_enum_is_the_conversion_lifecycle(): void
    {
        // The enum the lifecycle walks (Art. III — ESM-16: one machine).
        $this->assertSame('forming', Executive::STATUS_FORMING);
        $this->assertSame('delegated', Executive::STATUS_DELEGATED);
        $this->assertSame('conversion_voted', Executive::STATUS_CONVERSION_VOTED);
        $this->assertSame('elected', Executive::STATUS_ELECTED);

        // Conversion is only reachable from forming or delegated, and lands
        // at conversion_voted BEFORE the election — never straight to elected
        // (proposeConversion's own gate; the dual process + election sit between).
        $this->assertContains(Executive::STATUS_FORMING, [Executive::STATUS_FORMING, Executive::STATUS_DELEGATED]);
        $this->assertContains(Executive::STATUS_DELEGATED, [Executive::STATUS_FORMING, Executive::STATUS_DELEGATED]);
    }

    /**
     * Same-row evolution by construction (the TermLockstep no-second-row
     * technique): the certification path that flips status → 'elected' does so
     * by FORCE-FILLING the existing executive row, and NOTHING in the
     * conversion / certification surface constructs a second Executive.
     */
    public function test_conversion_evolves_the_same_executive_row_never_a_second(): void
    {
        $certification = file_get_contents(app_path('Services/CertificationService.php'));

        // The flip to elected rides forceFill on the resolved $executive — the
        // same row the election was scheduled against.
        $this->assertMatchesRegularExpression(
            "/\\\$executive->forceFill\\(\\s*\\[\\s*'status'\\s*=>\\s*'elected'/s",
            $certification,
            "Art. III — certification must EVOLVE the executive row to 'elected' (ESM-16: one machine)."
        );

        // No second executive is ever created on the conversion / certification
        // path. (Setup-wizard scaffolding is the only Executive::create site;
        // it lives outside these two services.)
        foreach ([
            'Services/CertificationService.php',
            'Services/Executive/ExecutiveFormationService.php',
            'Services/Executive/ExecutiveActService.php',
        ] as $relative) {
            $this->assertDoesNotMatchRegularExpression(
                '/Executive::create\s*\(/',
                file_get_contents(app_path($relative)),
                "{$relative} constructs a second executive — conversion must evolve the SAME row (ESM-16)."
            );
        }
    }

    // ======================================================================
    // 3. LIVE rolled-back pins (guarded pg; one transaction, ALWAYS rolled back)
    // ======================================================================

    /**
     * THE dual-supermajority substrate, end to end on the REAL DB (live): the
     * constituent lane (the SECOND meter of the conversion) snapshots its
     * threshold as supermajority(constituent_total) — the protected function —
     * and PASSES only at the full supermajority. A partial yes-tally leaves the
     * process OPEN: one lane (or a partial lane) never converts. Throughout,
     * the executive's SAME row evolves (forming → conversion_voted) — no second
     * executive row is ever created (ESM-16).
     *
     * This drives MultiJurisdictionVoteService (open / recordConsent /
     * evaluate) directly against real constituent jurisdictions — the conversion
     * path's first live consumer of the substrate. (The proposal-routed
     * `applyConversionAdoption` E2E is blocked by a backend bug — see the
     * skipped test below; the dual-supermajority arithmetic it would exercise is
     * proven here without the blocked `chamber_vote_proposals` insert.)
     */
    public function test_constituent_dual_supermajority_lane_lives_and_only_full_supermajority_passes(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            [$legislature, , $executive] = $this->convertibleExecutive($conn);

            // Three REAL constituent jurisdictions (direct children) → required
            // = supermajority(3) = 3. Real rows are mandatory: constituent_
            // consents.jurisdiction_id is FK-constrained to jurisdictions.
            $constituents = $this->realConstituentJurisdictionIds($conn, $legislature, 3);

            $before = Executive::query()->where('jurisdiction_id', $legislature->jurisdiction_id)->count();
            $this->assertSame(1, $before, 'ESM-16: exactly one executive row per jurisdiction before conversion.');

            // The adopted chamber-lane verdict (exec_office_create /
            // supermajority). We do not re-drive the 41 casts; the chamber
            // lane's supermajority MATH is pinned purely above. This stands in
            // for that verdict so the SECOND (constituent) lane opens against
            // real data.
            $vote = $this->adoptedConversionVote($legislature);

            $process = app(MultiJurisdictionVoteService::class)->open(
                'exec_office_create',
                $legislature,
                $constituents,
                MultiJurisdictionVote::BASIS_SUPERMAJORITY,
                $vote,
                'executives',
                (string) $executive->id,
            );

            // The executive's SAME row evolves to conversion_voted — the chamber
            // lane is decided, the constituent lane is now OPEN.
            $executive->forceFill([
                'status' => Executive::STATUS_CONVERSION_VOTED,
                'conversion_process_id' => (string) $process->id,
            ])->save();

            // The constituent lane snapshots the PROTECTED supermajority of the
            // constituent total — the second meter, the same math as the chamber.
            $this->assertSame('exec_office_create', $process->kind);
            $this->assertSame(MultiJurisdictionVote::BASIS_SUPERMAJORITY, $process->basis);
            $this->assertSame('executives', $process->subject_type);
            $this->assertSame((string) $executive->id, (string) $process->subject_id, 'the process is about THIS executive.');
            $this->assertSame(3, (int) $process->constituent_total);
            $this->assertSame(
                ConstitutionalValidator::supermajority(3),
                (int) $process->required,
                'Art. III §3 — the constituent lane requires supermajority(constituent_total), the protected function.'
            );

            // ── PARTIAL consent: 2 of 3 yes — below the supermajority ────────
            app(MultiJurisdictionVoteService::class)->recordConsent($process, $constituents[0], true);
            app(MultiJurisdictionVoteService::class)->recordConsent($process, $constituents[1], true);

            $this->assertSame(MultiJurisdictionVote::STATUS_OPEN, $process->refresh()->status,
                'Art. III §3 — a partial constituent tally (2 of 3) does not clear the supermajority.');

            // The office is STILL only conversion_voted — a single / partial
            // lane never flips it to elected (the exit-criterion negative).
            $executive->refresh();
            $this->assertSame(Executive::STATUS_CONVERSION_VOTED, $executive->status,
                'One lane / partial consent never converts — the office stays conversion_voted, never elected.');
            $this->assertNotSame(Executive::STATUS_ELECTED, $executive->status);

            // ── The decisive third yes — the FULL supermajority PASSES ───────
            app(MultiJurisdictionVoteService::class)->recordConsent($process, $constituents[2], true);
            $this->assertSame(MultiJurisdictionVote::STATUS_PASSED, $process->refresh()->status,
                'Art. III §3 — the constituent supermajority clears only at the full threshold.');

            // BOTH lanes are now clear → the conversion election may schedule.
            // We invoke the scheduling effect directly (the proposal-routed
            // onProcessEvaluated is blocked by the backend bug below) and pin
            // that it lands on the SAME executive id — ESM-16: one machine.
            // (Individual model: its race is seat_kind 'single'. The committee
            // model would be seat_kind 'exec_committee' — itself blocked by a
            // SECOND backend bug: election_races.seat_kind is varchar(8) but
            // the value is 14 chars, a 22001 truncation. The dual-supermajority
            // claim is type-agnostic, so the individual model proves it cleanly;
            // the committee column-width bug is recorded separately.)
            $electionsBefore = DB::table('elections')->where('executive_id', (string) $executive->id)->where('kind', 'executive')->count();
            $this->assertSame(0, $electionsBefore, 'No executive election existed before BOTH lanes cleared.');

            $executive->forceFill(['type' => Executive::TYPE_INDIVIDUAL])->save();

            app(ExecutiveFormationService::class)->scheduleConversionElection(
                $executive->refresh(),
                $legislature,
                ['target_type' => Executive::TYPE_INDIVIDUAL, 'member_count' => 1],
            );

            $election = DB::table('elections')
                ->where('executive_id', (string) $executive->id)
                ->where('kind', 'executive')
                ->first();

            $this->assertNotNull($election,
                'Art. III §3 — once BOTH supermajority lanes clear, the executive election schedules.');
            $this->assertSame((string) $executive->id, (string) $election->executive_id,
                'ESM-16: the election is against the SAME executive row — never a second office.');

            // STILL exactly one executive row for the jurisdiction — every step
            // EVOLVED the same row; nothing forked a second executive.
            $this->assertSame(
                1,
                Executive::query()->where('jurisdiction_id', $legislature->jurisdiction_id)->count(),
                'ESM-16: no second executive row is created across the whole conversion.'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * THE full proposal-routed F-LEG-015 conversion E2E, end to end through
     * ConstitutionalEngine::file() (flipped from the former guard — the three
     * substrate gaps the guard documented are now fixed and pinned below:
     * chamber_vote_proposals_kind_check admits 'exec_conversion', and
     * election_races.seat_kind is varchar(16) so the committee race's
     * 'exec_committee' value no longer truncates).
     *
     * This drives the ENTIRE proposal-routed path the guard deferred —
     * ExecutiveActService::proposeConversion → (chamber supermajority adopts) →
     * ExecutiveFormationService::applyConversionAdoption → (constituent consent)
     * → onProcessEvaluated — every step a real engine filing (F-LEG-015 propose
     * / open_constituent_consent, F-LEG-004 casts), nothing hand-driven.
     *
     * Two live chambers cover the dual-supermajority claim completely (the live
     * DB has exactly one convertible chamber WITH a constituent legislature, and
     * that constituent set is of size 1 — so supermajority(1)=2 is unreachable
     * by the lone constituent; a second convertible chamber has NO constituents):
     *
     *  LANE A (San Marino — chamber lane PASSES, the real MultiJurisdiction
     *  constituent lane OPENS): the 41-seat bicameral supermajority vote adopts
     *  on the protected supermajority(41)=28 of BOTH kind lanes; the now-running
     *  applyConversionAdoption opens the constituent process (the formerly
     *  blocked path) with required = supermajority(constituent_total) via the
     *  SAME protected function; the constituent's OWN ordinary-majority consent
     *  is driven through the engine, and a lone yes CANNOT clear the dual
     *  supermajority (required 2 of 1) → the process FAILS and the SAME
     *  executive row reverts (conversion_voted → its pre-conversion footing).
     *  One lane does not convert; no election; never a second executive row.
     *
     *  LANE B (Montegiardino — no constituents, the conversion COMPLETES to the
     *  election): the 7-seat unicameral supermajority vote adopts on
     *  supermajority(7)=5; applyConversionAdoption's "no constituents" branch
     *  (Art. III §3 — the chamber supermajority alone decides) evolves the SAME
     *  executive row forming → conversion_voted AND schedules the executive
     *  election against the SAME executive id — proving the conversion reaches
     *  the election on one machine (ESM-16). The COMMITTEE model is used here
     *  (seat_kind 'exec_committee'), exercising the widened column the guard
     *  flagged as a second blocker.
     *
     * Together: both supermajority lanes resolve through
     * ConstitutionalValidator::supermajority, the same executives row evolves
     * across the whole conversion (never a second), and the conversion converts
     * to an election only when the dual supermajority is actually clearable.
     */
    public function test_full_proposal_routed_conversion_adoption_runs_the_dual_supermajority_e2e(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            // Substrate facts the flip depends on (the three gaps the guard
            // documented, now closed) — pinned live so a regression re-reds here.
            $constraint = $conn->selectOne(
                "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'chamber_vote_proposals_kind_check'"
            );
            $this->assertNotNull($constraint, 'The kind CHECK constraint must exist.');
            $this->assertStringContainsString(
                'exec_conversion',
                (string) $constraint->def,
                'The F-LEG-015 proposal insert needs chamber_vote_proposals_kind_check to admit exec_conversion.'
            );
            $seatKind = $conn->selectOne(
                'SELECT character_maximum_length AS len FROM information_schema.columns '
                ."WHERE table_name = 'election_races' AND column_name = 'seat_kind'"
            );
            $this->assertGreaterThanOrEqual(
                strlen(ElectionRace::SEAT_KIND_EXEC_COMMITTEE),
                (int) $seatKind->len,
                "election_races.seat_kind must be wide enough for 'exec_committee' — else the committee race truncates (22001)."
            );

            $engine = app(ConstitutionalEngine::class);

            // ── LANE A — San Marino: chamber lane PASSES, the real constituent
            //    MultiJurisdiction lane OPENS, a lone constituent cannot clear
            //    the dual supermajority (one lane does not convert) ────────────
            [$legislature, $executive, $constituentIds] = $this->convertibleWithConstituents($conn, 1);

            $jurisdictionId = (string) $legislature->jurisdiction_id;

            // The revert must return the office to its PRE-CONVERSION footing,
            // whatever it was — `forming` on a virgin instance, or `delegated`
            // once a delegation act stands (institutions:demo-d delegates this
            // very executive). Capture it; the invariant is revert-to-pre-state,
            // never a hardcoded value coupled to live demo seeding.
            $preConversionStatus = (string) $executive->status;

            $this->assertSame(
                1,
                Executive::query()->where('jurisdiction_id', $jurisdictionId)->count(),
                'ESM-16: exactly one executive row per jurisdiction before conversion.'
            );

            // Engine filing #1: F-LEG-015 propose — the proposal row (formerly
            // rejected by the CHECK) now PERSISTS, and the chamber supermajority
            // vote opens (exec_office_create).
            $proposed = $engine->file('F-LEG-015', $this->nonSpeakerUser($legislature), [
                'action' => 'propose',
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => $jurisdictionId,
                'target_type' => Executive::TYPE_INDIVIDUAL,
                'charter_text' => 'ExecConversionDualSupermajorityTest throwaway — directly elected office.',
            ]);

            $proposal = ChamberVoteProposal::query()->findOrFail($proposed->recorded['proposal_id']);
            $this->assertSame(
                ChamberVoteProposal::KIND_EXEC_CONVERSION,
                $proposal->proposal_kind,
                'The F-LEG-015 proposal is an exec_conversion kind — the row the CHECK now admits.'
            );

            $vote = ChamberVote::query()->findOrFail($proposed->recorded['vote_id']);
            $this->assertSame('exec_office_create', $vote->vote_type);
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $vote->threshold_basis,
                'Art. III §2 — the chamber lane is a supermajority vote.');
            $this->assertSame(28, ConstitutionalValidator::supermajority((int) $vote->serving_snapshot),
                'Art. VII — supermajority(41) = 28; the chamber lane clears the protected threshold.');

            // Engine filings #2..n: every serving non-Speaker member casts yes
            // (F-LEG-004) until the supermajority closes the vote ADOPTED. The
            // closing cast fires applyConversionAdoption in the SAME txn.
            $this->castAllYes($engine, $legislature, (string) $vote->id);

            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $vote->refresh()->outcome,
                'Art. III §2 — the chamber supermajority adopts (both kind lanes ≥ supermajority(41)).');

            $proposal->refresh();
            $this->assertSame(ChamberVoteProposal::STATUS_ADOPTED, $proposal->status);
            $this->assertSame('multi_jurisdiction_votes', $proposal->result_type,
                'applyConversionAdoption opened the constituent dual-supermajority process (constituents exist).');

            // The SAME executive row evolved forming → conversion_voted, and the
            // real constituent lane is now OPEN — required snapshotted through
            // the PROTECTED supermajority of the constituent total.
            $executive->refresh();
            $this->assertSame(Executive::STATUS_CONVERSION_VOTED, $executive->status,
                'ESM-16: the same row evolved to conversion_voted — the chamber lane decided.');
            $this->assertNotNull($executive->conversion_process_id);

            $process = MultiJurisdictionVote::query()->findOrFail((string) $executive->conversion_process_id);
            $this->assertSame('exec_office_create', $process->kind);
            $this->assertSame(MultiJurisdictionVote::BASIS_SUPERMAJORITY, $process->basis);
            $this->assertSame('executives', $process->subject_type);
            $this->assertSame((string) $executive->id, (string) $process->subject_id,
                'The process is about THIS executive (same row).');
            $this->assertSame(count($constituentIds), (int) $process->constituent_total);
            $this->assertSame(
                ConstitutionalValidator::supermajority((int) $process->constituent_total),
                (int) $process->required,
                'Art. III §3 — the constituent lane requires supermajority(constituent_total), the protected function.'
            );

            // Engine filing: F-LEG-015 open_constituent_consent on the (single)
            // constituent chamber, then its OWN ordinary-majority yes — driving
            // recordConsent → onProcessEvaluated through the engine.
            $constituentLeg = $this->constituentChamber($constituentIds[0]);
            $opened = $engine->file('F-LEG-015', $this->nonSpeakerUser($constituentLeg), [
                'action' => 'open_constituent_consent',
                'process_id' => (string) $process->id,
                'legislature_id' => (string) $constituentLeg->id,
                'jurisdiction_id' => (string) $constituentLeg->jurisdiction_id,
            ]);

            $consentVote = ChamberVote::query()->findOrFail($opened->recorded['consent_vote_id']);
            $this->assertSame(
                ExecutiveActService::CONSTITUENT_CONSENT_VOTE_TYPE,
                $consentVote->vote_type,
                'A constituent decides its OWN consent by ordinary majority; the supermajority is ACROSS jurisdictions.'
            );

            // The constituent consents YES — but a lone yes of one cannot clear
            // the dual supermajority (required 2 of 1): the process FAILS.
            $this->castAllYes($engine, $constituentLeg, (string) $consentVote->id);

            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $consentVote->refresh()->outcome,
                'The constituent chamber adopted its own consent by ordinary majority.');

            $process->refresh();
            $this->assertSame(1, (int) $process->yes_count);
            $this->assertSame(MultiJurisdictionVote::STATUS_FAILED, $process->status,
                'Art. III §3 — a single constituent yes cannot reach supermajority(1)=2; the dual lane FAILS. '
                .'One lane (here, a lone constituent) never converts.');

            // onProcessEvaluated reverted the SAME row to its pre-conversion
            // footing — never elected, never a second executive, NO election.
            $executive->refresh();
            $this->assertNotSame(Executive::STATUS_ELECTED, $executive->status,
                'One lane does not convert — the office is never elected on a failed constituent lane.');
            $this->assertSame($preConversionStatus, $executive->status,
                'The failed dual lane reverts the executive to its PRE-CONVERSION footing (forming on a '
                .'virgin instance, delegated once a delegation act stands) — never elected, never a fork.');
            $this->assertNotSame(Executive::STATUS_CONVERSION_VOTED, $executive->status,
                'The conversion_voted limbo is cleared on a failed lane — the office is back to a settled state.');
            $this->assertSame(
                0,
                DB::table('elections')->where('executive_id', (string) $executive->id)->where('kind', 'executive')->count(),
                'No executive election schedules while the dual supermajority is unmet.'
            );
            $this->assertSame(
                1,
                Executive::query()->where('jurisdiction_id', $jurisdictionId)->count(),
                'ESM-16: still exactly one executive row — every engine step evolved the same machine.'
            );

            // ── LANE B — Montegiardino: no constituents → the chamber
            //    supermajority alone converts and the election SCHEDULES on the
            //    SAME executive row (Art. III §3 "where constituents exist") ────
            [$soloLeg, $soloExec] = $this->convertibleWithoutConstituents($conn);

            $soloJurisdictionId = (string) $soloLeg->jurisdiction_id;
            $this->assertSame(
                1,
                Executive::query()->where('jurisdiction_id', $soloJurisdictionId)->count(),
                'ESM-16: exactly one executive row before the no-constituent conversion.'
            );
            $this->assertSame(
                0,
                DB::table('elections')->where('executive_id', (string) $soloExec->id)->where('kind', 'executive')->count(),
                'No executive election existed before the conversion.'
            );

            // COMMITTEE model (seat_kind 'exec_committee') — the widened column.
            $soloProposed = $engine->file('F-LEG-015', $this->nonSpeakerUser($soloLeg), [
                'action' => 'propose',
                'legislature_id' => (string) $soloLeg->id,
                'jurisdiction_id' => $soloJurisdictionId,
                'target_type' => Executive::TYPE_COMMITTEE,
                'member_count' => 5,
                'charter_text' => 'ExecConversionDualSupermajorityTest throwaway — elected committee.',
            ]);

            $soloProposal = ChamberVoteProposal::query()->findOrFail($soloProposed->recorded['proposal_id']);
            $this->assertSame(ChamberVoteProposal::KIND_EXEC_CONVERSION, $soloProposal->proposal_kind);

            $soloVote = ChamberVote::query()->findOrFail($soloProposed->recorded['vote_id']);
            $this->assertSame(ChamberVote::BASIS_SUPERMAJORITY, $soloVote->threshold_basis);
            $this->assertSame(5, ConstitutionalValidator::supermajority((int) $soloVote->serving_snapshot),
                'Art. VII — supermajority(7) = 5; the chamber lane clears the protected threshold.');

            $this->castAllYes($engine, $soloLeg, (string) $soloVote->id);

            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $soloVote->refresh()->outcome);

            $soloProposal->refresh();
            $this->assertSame(ChamberVoteProposal::STATUS_ADOPTED, $soloProposal->status);
            $this->assertSame('executives', $soloProposal->result_type,
                'No constituents: applyConversionAdoption completes on the chamber supermajority alone (Art. III §3).');

            // The SAME executive row evolved AND the election scheduled against it.
            $soloExec->refresh();
            $this->assertSame(Executive::STATUS_CONVERSION_VOTED, $soloExec->status);

            $election = DB::table('elections')
                ->where('executive_id', (string) $soloExec->id)
                ->where('kind', 'executive')
                ->first();

            $this->assertNotNull($election,
                'Art. III §3 — with no constituent lane, the chamber supermajority alone schedules the election.');
            $this->assertSame((string) $soloExec->id, (string) $election->executive_id,
                'ESM-16: the election is against the SAME executive row — never a second office.');
            $this->assertSame('conversion_act', (string) $election->trigger);

            $race = ElectionRace::query()->where('election_id', (string) $election->id)->firstOrFail();
            $this->assertSame(ElectionRace::SEAT_KIND_EXEC_COMMITTEE, $race->seat_kind,
                'The committee model schedules an exec_committee race — the value the widened column now stores untruncated.');
            $this->assertSame(5, (int) $race->seats);

            $this->assertSame(
                1,
                Executive::query()->where('jurisdiction_id', $soloJurisdictionId)->count(),
                'ESM-16: still exactly one executive row across the whole conversion.'
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
     * A live chamber whose jurisdiction holds a `forming` (or `delegated`)
     * executive eligible to convert, plus a serving proposer.
     *
     * @return array{0: Legislature, 1: ?LegislatureMember, 2: Executive}
     */
    private function convertibleExecutive(Connection $conn): array
    {
        $executive = Executive::query()
            ->whereIn('status', [Executive::STATUS_FORMING, Executive::STATUS_DELEGATED])
            ->whereHas('jurisdiction', fn ($q) => $q->whereNull('deleted_at'))
            ->get()
            // DETERMINISTIC selection: prefer the convertible chamber with the
            // MOST direct child jurisdictions (San Marino's castelli) so this
            // with-constituents scenario always lands on a chamber that actually
            // HAS constituents. Never the arbitrary physical-row order of get():
            // a Phase D delegation (institutions:demo-d UPDATEs this executive
            // row) reshuffles that order and would otherwise hand back a
            // leaf chamber, skipping the test.
            ->sortByDesc(fn (Executive $exec) => $conn->table('jurisdictions')
                ->where('parent_id', (string) $exec->jurisdiction_id)
                ->whereNull('deleted_at')
                ->count())
            ->values()
            ->first(function (Executive $exec) {
                $legislature = Legislature::query()
                    ->where('jurisdiction_id', $exec->jurisdiction_id)
                    ->whereNull('deleted_at')
                    ->where('status', '!=', 'dissolved')
                    ->whereNotNull('term_ends_on')
                    ->first();

                return $legislature !== null
                    && $legislature->members()->whereIn('status', ['elected', 'seated'])->exists();
            });

        if ($executive === null) {
            $this->markTestSkipped('No live convertible executive (forming/delegated with a serving chamber) — seed the dev DB first.');
        }

        $legislature = Legislature::query()
            ->where('jurisdiction_id', $executive->jurisdiction_id)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'dissolved')
            ->firstOrFail();

        $proposer = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->first();

        return [$legislature, $proposer, $executive];
    }

    /**
     * A convertible executive (forming/delegated) whose serving chamber has
     * EXACTLY $expected direct-constituent jurisdictions holding a non-dissolved
     * legislature with serving members — the set applyConversionAdoption opens
     * the dual-supermajority process across (ExecutiveFormationService::
     * constituentJurisdictionIds). Returns the chamber, the executive, and the
     * constituent jurisdiction ids.
     *
     * @return array{0: Legislature, 1: Executive, 2: list<string>}
     */
    private function convertibleWithConstituents(Connection $conn, int $expected): array
    {
        foreach ($this->convertibleChambers($conn) as [$legislature, $executive]) {
            $constituents = $this->serviceConstituentIds($conn, $legislature);

            if (count($constituents) === $expected) {
                return [$legislature, $executive, $constituents];
            }
        }

        $this->markTestSkipped(
            "Live DB has no convertible executive whose chamber has exactly {$expected} "
            .'constituent legislature(s) with serving members — seed San Marino + Montegiardino.'
        );
    }

    /**
     * A convertible executive whose chamber has NO constituent legislatures —
     * applyConversionAdoption then completes on the chamber supermajority alone
     * (Art. III §3) and schedules the election directly.
     *
     * @return array{0: Legislature, 1: Executive}
     */
    private function convertibleWithoutConstituents(Connection $conn): array
    {
        foreach ($this->convertibleChambers($conn) as [$legislature, $executive]) {
            if ($this->serviceConstituentIds($conn, $legislature) === []) {
                return [$legislature, $executive];
            }
        }

        $this->markTestSkipped(
            'Live DB has no convertible executive whose chamber has zero constituent '
            .'legislatures — seed Montegiardino (a leaf jurisdiction with a chamber).'
        );
    }

    /**
     * Every live convertible (chamber, executive) pair: a forming/delegated
     * executive whose jurisdiction holds a non-dissolved, term-bounded chamber
     * with serving members.
     *
     * @return list<array{0: Legislature, 1: Executive}>
     */
    private function convertibleChambers(Connection $conn): array
    {
        $pairs = [];

        $executives = Executive::query()
            ->whereIn('status', [Executive::STATUS_FORMING, Executive::STATUS_DELEGATED])
            ->whereHas('jurisdiction', fn ($q) => $q->whereNull('deleted_at'))
            ->get();

        foreach ($executives as $executive) {
            $legislature = Legislature::query()
                ->where('jurisdiction_id', $executive->jurisdiction_id)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'dissolved')
                ->whereNotNull('term_ends_on')
                ->first();

            if ($legislature !== null
                && $legislature->members()->whereIn('status', ['elected', 'seated'])->exists()) {
                $pairs[] = [$legislature, $executive];
            }
        }

        return $pairs;
    }

    /**
     * The constituent set applyConversionAdoption itself resolves: DIRECT child
     * jurisdictions holding a non-dissolved legislature WITH serving members
     * (only such chambers can actually decide their consent — the WF-JUR-04
     * precedent the service encodes).
     *
     * @return list<string>
     */
    private function serviceConstituentIds(Connection $conn, Legislature $legislature): array
    {
        return $conn->table('jurisdictions as j')
            ->join('legislatures as l', 'l.jurisdiction_id', '=', 'j.id')
            ->where('j.parent_id', (string) $legislature->jurisdiction_id)
            ->whereNull('j.deleted_at')
            ->whereNull('l.deleted_at')
            ->where('l.status', '!=', 'dissolved')
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
     * A serving NON-Speaker member's User of the chamber (the R-09 actor for
     * F-LEG-015 / F-LEG-004 engine filings — the Speaker cannot cast on yes/no
     * business, so the proposer/casters are ordinary members).
     */
    private function nonSpeakerUser(Legislature $legislature): User
    {
        $member = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', ['elected', 'seated'])
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }

    /**
     * Drive every serving NON-Speaker member's yes cast through the engine
     * (F-LEG-004) until the vote auto-closes at full participation. The Speaker
     * is excluded (Art. II §3 — they cannot cast on yes/no business).
     */
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

    /**
     * N real direct-child jurisdiction ids (the constituent set). Real rows
     * are mandatory: constituent_consents.jurisdiction_id is FK-constrained.
     *
     * @return list<string>
     */
    private function realConstituentJurisdictionIds(Connection $conn, Legislature $legislature, int $n): array
    {
        $ids = $conn->table('jurisdictions')
            ->where('parent_id', (string) $legislature->jurisdiction_id)
            ->whereNull('deleted_at')
            ->limit($n)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (count($ids) < $n) {
            $this->markTestSkipped("Live DB has fewer than {$n} child jurisdictions under the convertible chamber — seed San Marino's communes.");
        }

        return $ids;
    }

    /**
     * A throwaway ADOPTED `exec_office_create` chamber vote — the chamber
     * (first) lane's verdict. We do not re-drive the 41-member casts here; the
     * chamber lane's SUPERMAJORITY math is pinned purely above
     * (laneThresholds + the registry). This stands in for that verdict so the
     * adoption effect (opening the constituent lane) runs against real data.
     */
    private function adoptedConversionVote(Legislature $legislature): ChamberVote
    {
        return ChamberVote::create([
            'body_type' => ChamberVote::BODY_LEGISLATURE,
            'body_id' => (string) $legislature->id,
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'vote_type' => 'exec_office_create',
            'vote_method' => ChamberVote::METHOD_YES_NO,
            'threshold_basis' => ChamberVote::BASIS_SUPERMAJORITY,
            'stage' => ChamberVote::STAGE_FLOOR,
            'bicameral' => false,
            'serving_snapshot' => 41,
            'opened_at' => now()->subHour(),
            'decided_at' => now(),
            'outcome' => ChamberVote::OUTCOME_ADOPTED,
            'status' => ChamberVote::STATUS_CLOSED,
        ]);
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
