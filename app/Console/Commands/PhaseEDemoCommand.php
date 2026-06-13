<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Advocate;
use App\Models\Appointment;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ClockTimer;
use App\Models\ConstitutionalChallenge;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jurisdiction;
use App\Models\Jury;
use App\Models\JuryMember;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RemedyRecommendation;
use App\Models\Term;
use App\Models\User;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\RoleService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase E analogue of `institutions:demo-d` — persist the Phase E exit-criterion
 * flows on San Marino so the JUDICIARY pages render with STANDING, BROWSABLE
 * data (instead of the empty states they show while no court is created and no
 * case/challenge is on the docket).
 *
 *   php artisan institutions:demo-e
 *   php artisan institutions:demo-e --fresh
 *
 * The four constitutional Phase E tests drive each flow end to end through the
 * REAL ConstitutionalEngine and then ROLL BACK
 * (JudiciaryCreationConversionTest, CaseLifecycleTest, Art4Section5Test). This
 * command replays the SAME working sequences but PERSISTS them — the demo IS
 * the data the pages browse.
 *
 * It seeds, on San Marino's bicameral chamber + its FORMING judiciary:
 *
 *   1. An APPOINTED court (engine-filed F-LEG-017 supermajority `judiciary_create`
 *      → judiciary forming → creating, mode DERIVED 'constituent' — San Marino
 *      HAS the Montegiardino constituent — equal-per-constituent seat pool) →
 *      per-seat F-LEG-021 nominate + bog_consent (cast via F-LEG-004) seating
 *      each judge on a 10-year CLK-09 civil-appointment term → creating →
 *      appointed. /judiciaries/{id} + /docket render a real court.
 *   2. TWO court cases: one CRIMINAL case driven mid-lifecycle (file → accept +
 *      paneled odd≥3 bench → jury empaneled), and one CRIMINAL case driven to
 *      close (verdict → sentence → opinion). The docket + case-detail light up;
 *      the jury_members rows let /judiciary/jury/{summons} render.
 *   3. An ADVOCATE (engine-filed F-IND-015 → R-21) + one advocate filing
 *      (engine-filed F-ADV-001, a case on behalf of a client). The advocate
 *      console lights up.
 *   4. TWO constitutional challenges (Art. IV §5 — THE exit criterion):
 *        (a) one resting at legislative_window_open with CLK-11/CLK-12 ARMED
 *            (the Art4Section5Tracker shows the live veto + remedy windows), and
 *        (b) one driven all the way to judicial_remedy_applied (windows expired
 *            → the clock sweep fires JudicialAutoRemedyJob → the law text is
 *            EDITED via a source='judicial_remedy' v2, v1 PRESERVED) so the
 *            ConstitutionalChallenge page renders the LawDiff with the judge's
 *            edit + the preserved prior version.
 *
 * IDEMPOTENT / RE-RUNNABLE: a second plain run detects the seeded state (the
 * San Marino judiciary at `appointed`) and reports-and-exits without
 * duplicating. `--fresh` tears down ONLY the demo's own Phase E rows (the
 * judiciary's seats + nominations + appointments + terms + CLK-09 timers, the
 * cases + panels + juries + verdicts + sentences + warrants + opinions +
 * case_filings, the advocates, the challenges + findings + remedies + the demo
 * challenge clock_timers, the chamber_vote_proposals + votes it filed) and
 * resets San Marino's judiciary back to `forming`, then reseeds.
 *
 * APPEND-ONLY RESPECT: audit_log, public_records, and law_versions are NEVER
 * deleted. The judicial-remedy edit appends a law_version to a DEMO law the
 * command creates; that law is SOFT-deleted on teardown (per the
 * cgc_ip_register precedent in PhaseDDemoCommand) so the immutable version
 * history stands while the law disappears from every page. The window-open
 * demo law (no judicial-remedy version) is force-purged to keep the act-number
 * sequence contiguous.
 *
 * Demo rows are TAGGED so teardown is exact and never touches Phase A/B/C/D
 * data: the court carries self::DEMO_COURT_TAG in its name; the offending demo
 * laws carry self::DEMO_LAW_TAG in their act_number; the advocate user carries
 * self::DEMO_EMAIL_TAG. Everything else (seats, cases, challenges) is keyed to
 * the demo judiciary id, so teardown is precise.
 *
 * This writes to the LIVE dev DB and does NOT roll back — that is the point.
 * Each step rides its own small transaction so a mid-run failure cannot leave
 * half-state. Queue dispatches run inline so the clock sweep is observed
 * synchronously.
 */
class PhaseEDemoCommand extends Command
{
    protected $signature = 'institutions:demo-e
                            {--fresh : tear down prior Phase E demo state and reseed}';

    protected $description = 'Persist the Phase E exit-criterion flows on San Marino so the judiciary pages are browsable (Phase E analogue of institutions:demo-d).';

    /** San Marino is the ADM1 jurisdiction (its bicameral chamber is the demo substrate). */
    private const SAN_MARINO_SLUG = 'smr-1-san-marino';

    /** Demo-row tags (the teardown selectors — never collide with real data). */
    private const DEMO_COURT_TAG = '[PhaseE-Demo]';

    private const DEMO_LAW_TAG = 'PE-DEMO';

    private const DEMO_EMAIL_TAG = 'pe-demo';

    /** judges_per_constituent — with San Marino's one constituent this floors the bench at 5. */
    private const JUDGES_PER_CONSTITUENT = 5;

    /** Engine-filing tallies for the report. @var array<string, int> */
    private array $filings = [];

    /** The persisted artifacts for the report. @var array<string, mixed> */
    private array $artifacts = [];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly JudicialSeatService $seats,
        private readonly RoleService $roles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);

        // All queue dispatches in THIS process run inline — the demo must
        // observe each effect synchronously (the CLK-11 JudicialAutoRemedyJob
        // fired by the clock sweep). Process-local config; Horizon untouched.
        config(['queue.default' => 'sync']);

        $jurisdiction = Jurisdiction::query()
            ->where('slug', self::SAN_MARINO_SLUG)
            ->whereNull('deleted_at')
            ->first();

        if ($jurisdiction === null) {
            $this->error('San Marino jurisdiction ('.self::SAN_MARINO_SLUG.') not found — seed the instance first.');

            return self::FAILURE;
        }

        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->first();

        if ($legislature === null) {
            $this->error('San Marino has no legislature — run the activation/elections demo first.');

            return self::FAILURE;
        }

        $judiciary = Judiciary::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->first();

        if ($judiciary === null) {
            $this->error('San Marino has no judiciary row — the setup wizard scaffolds one per legislature.');

            return self::FAILURE;
        }

        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->orderBy('seat_no')
            ->get();

        if ($serving->count() < 5) {
            $this->error(sprintf(
                'San Marino chamber has only %d serving members — need a quorate chamber to charter a court. Run elections:demo first.',
                $serving->count(),
            ));

            return self::FAILURE;
        }

        $pool = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('is_active', true)
            ->count();

        if ($pool < 20) {
            $this->error(sprintf(
                'San Marino has only %d associated residents — need ≥20 to nominate a bench AND draw a jury.',
                $pool,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'institutions:demo-e — %s (%s) · judiciary %s (%s) · %d serving members · %d associated residents%s',
            $jurisdiction->name,
            $jurisdiction->slug,
            substr((string) $judiciary->id, 0, 8),
            $judiciary->status,
            $serving->count(),
            $pool,
            $this->option('fresh') ? ' · --fresh' : '',
        ));

        try {
            if ($this->option('fresh')) {
                $this->step('0. teardown prior demo state (--fresh)', fn () => $this->teardown($jurisdiction, $judiciary, $legislature));
                $judiciary->refresh();
            }

            // Idempotency: if the court is already standing WITHOUT --fresh, the
            // demo state stands — report and exit gracefully (no duplication).
            if (! $this->option('fresh') && in_array($judiciary->status, Judiciary::OPERATING_STATUSES, true)) {
                $this->warn("San Marino judiciary is already {$judiciary->status} — Phase E demo state is in place.");
                $this->line('Re-run with --fresh to tear down and reseed a clean Phase E slice.');
                $this->reportExisting($jurisdiction, $judiciary);

                return self::SUCCESS;
            }

            // ── 1. create + seat an appointed court (F-LEG-017 → F-LEG-021) ───
            $this->step('1. create + seat appointed judiciary (F-LEG-017 → F-LEG-021)', fn () => $this->standUpJudiciary($jurisdiction, $legislature, $judiciary, $serving));
            $judiciary->refresh();

            // ── 2. an advocate + an advocate-filed case (F-IND-015 / F-ADV-001) ─
            $this->step('2. advocate + advocate filing (F-IND-015 → F-ADV-001)', fn () => $this->seedAdvocate($jurisdiction, $judiciary));

            // ── 3. two cases (one mid-lifecycle paneled+jury, one closed) ─────
            $this->step('3. court cases (F-IND-017 → F-JDG-001/002/009/010/003)', fn () => $this->seedCases($jurisdiction, $judiciary));

            // ── 4. two constitutional challenges (Art. IV §5 exit criterion) ──
            $this->step('4. constitutional challenges (F-IND-016 → F-JDG-004/005 → auto-remedy)', fn () => $this->seedChallenges($jurisdiction, $legislature, $judiciary));

            $this->report($jurisdiction, $judiciary, $startedAt);
        } catch (ConstitutionalViolation $violation) {
            $this->error("Constitutional rejection: {$violation->getMessage()} ({$violation->citation})");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // =========================================================================
    // 1 — appointed court stand-up (engine-filed F-LEG-017 + F-LEG-021,
    //     lifted from JudiciaryCreationConversionTest::
    //     test_appointed_creation_seats_an_equal_per_constituent_bench_…)
    // =========================================================================

    private function standUpJudiciary(Jurisdiction $jurisdiction, Legislature $legislature, Judiciary $judiciary, $serving): void
    {
        if ($judiciary->status !== Judiciary::STATUS_FORMING) {
            throw new RuntimeException(
                "Judiciary {$judiciary->id} is not forming (status: {$judiciary->status}) — run with --fresh to reset it."
            );
        }

        $jurisdictionId = (string) $jurisdiction->id;
        $proposerUser = $this->nonSpeakerUser($legislature);

        // File F-LEG-017 through THE ENGINE: a supermajority judiciary_create
        // vote opens. judges_per_constituent = 5 floors the bench at min_judges
        // (5 × 1 constituent = 5 ≥ 5).
        $result = $this->engine->file('F-LEG-017', $proposerUser, [
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => $jurisdictionId,
            'court_name' => 'San Marino Superior Court '.self::DEMO_COURT_TAG,
            'function_text' => 'Hears the civil, criminal, administrative, and constitutional matters of San Marino (Phase E demo court).',
            'judges_per_constituent' => self::JUDGES_PER_CONSTITUENT,
        ]);
        $this->bump('F-LEG-017');

        $vote = ChamberVote::query()->findOrFail($result->recorded['vote_id']);

        // Every non-Speaker serving member casts yes (via F-LEG-004) — clears the
        // supermajority; the engine auto-closes and applyCreation runs in the
        // same txn (forming → creating, the equal-per-constituent seat pool).
        $this->castAllYes($legislature, (string) $vote->id);

        $judiciary->refresh();

        if ($judiciary->status !== Judiciary::STATUS_CREATING) {
            throw new RuntimeException(
                "Creation vote closed {$vote->fresh()->outcome} but the judiciary is {$judiciary->status} (expected creating)."
            );
        }

        if ($judiciary->nomination_mode !== Judiciary::NOMINATION_CONSTITUENT) {
            throw new RuntimeException(
                "Expected DERIVED constituent nomination mode (San Marino has a constituent) — got {$judiciary->nomination_mode}."
            );
        }

        // ── Seat the bench: per-seat F-LEG-021 nominate + bog_consent ─────────
        $seats = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_VACANT)
            ->orderBy('seat_number')
            ->get();

        $nominees = $this->associatedUsers($jurisdictionId, $seats->count());
        $firstTermId = null;

        foreach ($seats as $i => $seat) {
            $out = $this->seats->nominate(
                $seat,
                (string) $nominees[$i]->id,
                (string) $seat->nominating_jurisdiction_id,
            );
            $this->countFiling('F-LEG-021 (nominate)');

            // F-LEG-021 IS the consent vote (bog_consent — ordinary majority of
            // all serving). Drive it to adoption via F-LEG-004 → seat the judge.
            $this->castAllYes($legislature, $out['consent_vote_id']);

            $seat->refresh();

            if ($seat->status !== JudicialSeat::STATUS_SEATED || $seat->term_id === null) {
                throw new RuntimeException("Judge seat {$seat->id} did not seat (status {$seat->status}, term ".($seat->term_id ?? 'null').').');
            }

            $firstTermId ??= (string) $seat->term_id;
        }

        $judiciary->refresh();

        if ($judiciary->status !== Judiciary::STATUS_APPOINTED) {
            throw new RuntimeException("All seats consented but the court is {$judiciary->status} (expected appointed).");
        }

        $seatedCount = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->count();

        // Verify the 10-year civil-appointment term + the armed CLK-09 timer.
        $term = Term::query()->findOrFail($firstTermId);
        $years = (int) CarbonImmutable::parse($term->starts_on)->diffInYears(CarbonImmutable::parse($term->ends_on));
        $clk09 = ClockTimer::query()
            ->where('clock_id', 'CLK-09')
            ->where('subject_type', 'term')
            ->where('subject_id', $firstTermId)
            ->where('state', ClockTimer::STATE_ARMED)
            ->exists();

        if ($years !== 10 || ! $clk09) {
            throw new RuntimeException("Judge term is {$years}yr / CLK-09 armed=".($clk09 ? 'yes' : 'no').' (expected 10yr + armed).');
        }

        $this->artifacts['judiciary_id'] = (string) $judiciary->id;
        $this->artifacts['judges_seated'] = $seatedCount;
        $this->artifacts['judge_term_years'] = $years;
        $this->artifacts['judge_user_ids'] = $nominees->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->line(sprintf(
            '  judiciary %s → appointed · %d judges seated (constituent mode) · 10-yr CLK-09 terms (ends %s)',
            substr((string) $judiciary->id, 0, 8),
            $seatedCount,
            (string) $term->ends_on,
        ));
    }

    // =========================================================================
    // 2 — advocate + advocate filing (engine-filed F-IND-015 + F-ADV-001,
    //     lifted from CaseLifecycleTest::test_advocate_registration_…)
    // =========================================================================

    private function seedAdvocate(Jurisdiction $jurisdiction, Judiciary $judiciary): void
    {
        $jurisdictionId = (string) $jurisdiction->id;

        // A demo advocate user with an active San Marino association (the F-IND-015
        // association gate). Tagged by email so teardown can drop the row.
        $advocateUser = $this->demoResident($jurisdictionId, 'advocate');
        $clientUser = $this->demoResident($jurisdictionId, 'client');

        $this->roles->flush();

        // F-IND-015 — register the advocate → R-21.
        $registered = $this->engine->file('F-IND-015', $advocateUser, [
            'judiciary_id' => (string) $judiciary->id,
            'qualifications_note' => 'Admitted to the San Marino bar (Phase E demo advocate).',
        ]);
        $this->bump('F-IND-015');

        $advocate = Advocate::query()->findOrFail($registered->recorded['advocate_id']);
        $this->roles->flushUser((string) $advocateUser->id);

        // F-ADV-001 — file a case on behalf of the client.
        $filed = $this->engine->file('F-ADV-001', $advocateUser, [
            'judiciary_id' => (string) $judiciary->id,
            'jurisdiction_id' => $jurisdictionId,
            'kind' => CourtCase::KIND_CIVIL,
            'title' => 'Client v. Demo Respondent '.self::DEMO_COURT_TAG,
            'statement_of_claim' => 'A demo civil matter filed by the advocate on behalf of the client (Phase E demo).',
            'claimed_severity' => CourtCase::SEVERITY_MODERATE,
            'filed_on_behalf_of_user_id' => (string) $clientUser->id,
            'retainer_note' => 'Retainer recorded with the filing (Phase E demo).',
        ]);
        $this->bump('F-ADV-001');

        $advocateCase = CourtCase::query()->findOrFail($filed->recorded['case_id']);

        if ((string) $advocateCase->advocate_id !== (string) $advocate->id) {
            throw new RuntimeException('The advocate case did not record the engaged advocate.');
        }

        $this->artifacts['advocate_id'] = (string) $advocate->id;
        $this->artifacts['advocate_user_id'] = (string) $advocateUser->id;
        $this->artifacts['advocate_case_id'] = (string) $advocateCase->id;

        $this->line(sprintf(
            '  advocate %s registered (R-21) · filed case %s (%s) on behalf of a client (F-ADV-001)',
            substr((string) $advocate->id, 0, 8),
            $advocateCase->docket_no,
            substr((string) $advocateCase->id, 0, 8),
        ));
    }

    // =========================================================================
    // 3 — two court cases (engine-filed, lifted from CaseLifecycleTest::
    //     test_criminal_case_runs_file_accept_panel_jury_verdict_sentence_…)
    // =========================================================================

    private function seedCases(Jurisdiction $jurisdiction, Judiciary $judiciary): void
    {
        $jurisdictionId = (string) $jurisdiction->id;

        $judge = $this->aSeatedJudge($judiciary);
        $this->roles->flushUser((string) $judge->id);

        // ── Case A: a CRIMINAL case driven mid-lifecycle (paneled + jury) ─────
        [$complainantA, $accusedA] = $this->twoFreeResidents($jurisdictionId, $judiciary);

        $filedA = $this->engine->file('F-IND-017', $complainantA, [
            'judiciary_id' => (string) $judiciary->id,
            'jurisdiction_id' => $jurisdictionId,
            'kind' => CourtCase::KIND_CRIMINAL,
            'title' => 'State v. Demo Accused (mid-lifecycle) '.self::DEMO_COURT_TAG,
            'statement_of_claim' => 'A demo criminal accusation paused at jury empanelment (Phase E demo).',
            'claimed_severity' => CourtCase::SEVERITY_SERIOUS,
            'accused_user_id' => (string) $accusedA->id,
        ]);
        $this->bump('F-IND-017');

        $caseA = CourtCase::query()->findOrFail($filedA->recorded['case_id']);

        // F-JDG-001 — accept (serious) + panel (odd ≥3 bench).
        $acceptedA = $this->engine->file('F-JDG-001', $judge, [
            'case_id' => (string) $caseA->id,
            'court_severity' => 'serious',
        ]);
        $this->bump('F-JDG-001');

        $panelSize = (int) $acceptedA->recorded['panel_size'];

        if ($caseA->refresh()->status !== CourtCase::STATUS_PANELED || $panelSize < 3 || $panelSize % 2 === 0) {
            throw new RuntimeException("Case A panel did not seat odd≥3 (status {$caseA->status}, size {$panelSize}).");
        }

        // F-JDG-002 — empanel the jury (a verifiable draw → jury_members rows).
        $juryOrdered = $this->engine->file('F-JDG-002', $judge, [
            'case_id' => (string) $caseA->id,
            'seats' => 12,
            'alternates' => 2,
        ]);
        $this->bump('F-JDG-002');

        $jury = Jury::query()->findOrFail($juryOrdered->recorded['jury_id']);
        $summons = JuryMember::query()->where('jury_id', $jury->id)->orderBy('created_at')->firstOrFail();

        // ── Case B: a CRIMINAL case driven to CLOSE (verdict → sentence → opinion) ─
        [$complainantB, $accusedB] = $this->twoFreeResidents($jurisdictionId, $judiciary, exclude: [
            (string) $complainantA->id, (string) $accusedA->id,
        ]);

        $filedB = $this->engine->file('F-IND-017', $complainantB, [
            'judiciary_id' => (string) $judiciary->id,
            'jurisdiction_id' => $jurisdictionId,
            'kind' => CourtCase::KIND_CRIMINAL,
            'title' => 'State v. Demo Accused (closed) '.self::DEMO_COURT_TAG,
            'statement_of_claim' => 'A demo criminal case carried to verdict, sentence, and opinion (Phase E demo).',
            'claimed_severity' => CourtCase::SEVERITY_SERIOUS,
            'accused_user_id' => (string) $accusedB->id,
        ]);
        $this->bump('F-IND-017');

        $caseB = CourtCase::query()->findOrFail($filedB->recorded['case_id']);

        $this->engine->file('F-JDG-001', $judge, [
            'case_id' => (string) $caseB->id,
            'court_severity' => 'serious',
            'jury_waived' => true, // a bench trial — no jury draw needed for the closed case
        ]);
        $this->bump('F-JDG-001');

        // hearing → deliberation → verdict (CaseService transitions, not forms).
        $cases = app(\App\Services\Judiciary\CaseService::class);
        $cases->advanceToHearing($caseB->refresh());
        $cases->enterDeliberation($caseB->refresh());
        $cases->recordVerdict($caseB->refresh(), [
            'decided_by' => 'panel',
            'outcome' => 'guilty',
            'panel_vote_for' => 3,
            'panel_vote_against' => 0,
            'summary' => 'Guilty on all counts (Phase E demo verdict).',
        ]);

        if ($caseB->refresh()->status !== CourtCase::STATUS_DECIDED || ! (bool) $caseB->double_jeopardy_locked) {
            throw new RuntimeException("Case B verdict did not lock double jeopardy (status {$caseB->status}).");
        }

        // F-JDG-009 — sentence the guilty verdict.
        $this->engine->file('F-JDG-009', $judge, [
            'case_id' => (string) $caseB->id,
            'terms' => '8 years (Phase E demo sentence).',
        ]);
        $this->bump('F-JDG-009');

        // F-JDG-010 — a warrant carrying the Art. II §8 facts.
        $warranted = $this->engine->file('F-JDG-010', $judge, [
            'case_id' => (string) $caseB->id,
            'kind' => 'arrest',
            'stated_reason' => 'Failure to appear (Phase E demo warrant).',
            'max_hold_duration_hours' => 48,
            'subject_user_id' => (string) $accusedB->id,
        ]);
        $this->bump('F-JDG-010');

        // F-JDG-003 — the opinion closes the case.
        $this->engine->file('F-JDG-003', $judge, [
            'case_id' => (string) $caseB->id,
            'kind' => 'majority',
            'title' => 'Opinion of the court (Phase E demo)',
            'body' => 'The panel finds the accused guilty and enters its reasoning (Phase E demo opinion).',
        ]);
        $this->bump('F-JDG-003');

        if ($caseB->refresh()->status !== CourtCase::STATUS_CLOSED) {
            throw new RuntimeException("Case B opinion did not close the case (status {$caseB->status}).");
        }

        $this->artifacts['case_paneled_id'] = (string) $caseA->id;
        $this->artifacts['case_paneled_docket'] = (string) $caseA->docket_no;
        $this->artifacts['case_paneled_panel_size'] = $panelSize;
        $this->artifacts['case_closed_id'] = (string) $caseB->id;
        $this->artifacts['case_closed_docket'] = (string) $caseB->docket_no;
        $this->artifacts['jury_id'] = (string) $jury->id;
        $this->artifacts['jury_summons_id'] = (string) $summons->id;
        $this->artifacts['warrant_id'] = (string) $warranted->recorded['warrant_id'];

        $this->line(sprintf(
            '  case %s paneled (%d judges) + jury empaneled (summons %s) · case %s closed with verdict+sentence+warrant+opinion',
            $caseA->docket_no,
            $panelSize,
            substr((string) $summons->id, 0, 8),
            $caseB->docket_no,
        ));
    }

    // =========================================================================
    // 4 — two constitutional challenges (engine-filed, lifted from
    //     Art4Section5Test): one at legislative_window_open (CLK-11/12 armed),
    //     one driven all the way to judicial_remedy_applied (LawDiff + v1 intact).
    // =========================================================================

    private function seedChallenges(Jurisdiction $jurisdiction, Legislature $legislature, Judiciary $judiciary): void
    {
        $jurisdictionId = (string) $jurisdiction->id;

        $judge = $this->aSeatedJudge($judiciary);
        $this->roles->flushUser((string) $judge->id);

        // ── Challenge A — rests at legislative_window_open (CLK-11/12 ARMED) ───
        $lawA = $this->demoOffendingLaw($jurisdictionId, (string) $legislature->id, (string) $judiciary->id, 'window-open');
        $inhabitantA = $this->demoResident($jurisdictionId, 'challenger-a');
        $this->roles->flushUser((string) $inhabitantA->id);

        $challengeA = $this->fileFoundRecommended(
            $judge,
            $inhabitantA,
            $jurisdictionId,
            $lawA,
            remedyKind: 'modify',
            recommendedText: $this->versionText($lawA, 1)."\n\n[Judicial remedy (recommended): the conflicting clause is struck to restore Art. I.]",
            timeframeDays: 60,
            vetoDays: 30,
        );

        if ($challengeA->status !== ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN) {
            throw new RuntimeException("Challenge A did not rest at legislative_window_open (status {$challengeA->status}).");
        }

        $armed = ClockTimer::query()
            ->where('subject_type', 'constitutional_challenges')
            ->where('subject_id', (string) $challengeA->id)
            ->where('state', ClockTimer::STATE_ARMED)
            ->whereIn('clock_id', ['CLK-11', 'CLK-12'])
            ->count();

        if ($armed !== 2) {
            throw new RuntimeException("Challenge A has {$armed} armed CLK-11/12 timers (expected 2).");
        }

        // ── Challenge B — driven to judicial_remedy_applied (windows expired) ─
        $lawB = $this->demoOffendingLaw($jurisdictionId, (string) $legislature->id, (string) $judiciary->id, 'auto-remedy');
        $inhabitantB = $this->demoResident($jurisdictionId, 'challenger-b');
        $this->roles->flushUser((string) $inhabitantB->id);

        $originalTextB = $this->versionText($lawB, 1);
        $remedyTextB = $originalTextB."\n\n[Judicial remedy applied — Art. IV §5.5: the conflicting clause is removed to restore Art. I.]";

        $challengeB = $this->fileFoundRecommended(
            $judge,
            $inhabitantB,
            $jurisdictionId,
            $lawB,
            remedyKind: 'modify',
            recommendedText: $remedyTextB,
            timeframeDays: 60,
            vetoDays: 30,
        );

        // The legislature does NOTHING; both windows expire. Move the
        // recommendation deadlines (remedy_due_at / veto_closes_at) into the
        // past so the §5.5 "both windows closed" gate is satisfied, then invoke
        // the SAME JudicialRemedyService::applyRemedy the CLK-11 fire job runs
        // (JudicialAutoRemedyJob → applyRemedy) — it appends the
        // source='judicial_remedy' v2, preserves v1, and CANCELS the armed
        // CLK-11/CLK-12 itself.
        //
        // NB: the constitutional source-scan (ElectionClockTest) forbids ANY
        // 'fires_at' write outside ClockService::arm() across app/ — so the
        // demo NEVER moves a timer's fires_at (armed timers are only fired or
        // cancelled). Driving applyRemedy directly is the canonical fire effect
        // without touching the clock row.
        RemedyRecommendation::query()
            ->where('challenge_id', (string) $challengeB->id)
            ->update(['remedy_due_at' => now()->subDay(), 'veto_closes_at' => now()->subDay()]);

        app(\App\Services\Judiciary\JudicialRemedyService::class)->applyRemedy($challengeB->refresh());

        $lawB->refresh();
        $challengeB->refresh();

        if ($challengeB->status !== ConstitutionalChallenge::STATUS_CLOSED
            || (string) $challengeB->resolution_path !== ConstitutionalChallenge::PATH_JUDICIAL_REMEDY) {
            throw new RuntimeException("Challenge B did not auto-remedy (status {$challengeB->status}, path ".($challengeB->resolution_path ?? 'null').').');
        }

        if ((int) $lawB->current_version_no !== 2) {
            throw new RuntimeException("Challenge B law did not advance to v2 (current_version_no {$lawB->current_version_no}).");
        }

        $v2 = LawVersion::query()->where('law_id', (string) $lawB->id)->where('version_no', 2)->firstOrFail();
        $v1 = LawVersion::query()->where('law_id', (string) $lawB->id)->where('version_no', 1)->firstOrFail();

        if ((string) $v2->source !== LawVersion::SOURCE_JUDICIAL_REMEDY) {
            throw new RuntimeException("Challenge B v2 is not source=judicial_remedy (source {$v2->source}).");
        }

        if ((string) $v1->text !== $originalTextB) {
            throw new RuntimeException('Challenge B v1 text was mutated — version history not preserved.');
        }

        $this->artifacts['challenge_window_open_id'] = (string) $challengeA->id;
        $this->artifacts['challenge_window_open_law_id'] = (string) $lawA->id;
        $this->artifacts['challenge_applied_id'] = (string) $challengeB->id;
        $this->artifacts['challenge_applied_law_id'] = (string) $lawB->id;
        $this->artifacts['challenge_applied_law_version'] = (int) $lawB->current_version_no;

        $this->line(sprintf(
            '  challenge %s → legislative_window_open (CLK-11/12 armed) · challenge %s → judicial_remedy_applied (law %s v2 judicial_remedy, v1 intact)',
            substr((string) $challengeA->id, 0, 8),
            substr((string) $challengeB->id, 0, 8),
            substr((string) $lawB->id, 0, 8),
        ));
    }

    /**
     * File F-IND-016 → drive the hearing to deliberation → F-JDG-004
     * (contradiction) → F-JDG-005 (modify with the given windows). Returns the
     * challenge (the Art4Section5Test::fileFoundRecommended pattern).
     */
    private function fileFoundRecommended(
        User $judge,
        User $inhabitant,
        string $jurisdictionId,
        Law $law,
        string $remedyKind,
        string $recommendedText,
        int $timeframeDays,
        int $vetoDays,
    ): ConstitutionalChallenge {
        $filed = $this->engine->file('F-IND-016', $inhabitant, [
            'jurisdiction_id' => $jurisdictionId,
            'challenged_law_id' => (string) $law->id,
            'claim_text' => 'The act unjustly impedes my rights (Phase E demo claim).',
            'claimed_basis' => 'constitution',
            'constitutional_citation' => 'Art. I',
        ]);
        $this->bump('F-IND-016');

        $challenge = ConstitutionalChallenge::query()->findOrFail($filed->recorded['challenge_id']);

        if ($challenge->status !== ConstitutionalChallenge::STATUS_UNDER_REVIEW || $challenge->case_id === null) {
            throw new RuntimeException("Challenge filing did not open a hearing (status {$challenge->status}).");
        }

        // Drive the hearing case to deliberation (F-JDG-001 constitutional_major
        // → en banc panel, then heard → deliberation).
        $case = CourtCase::query()->findOrFail((string) $challenge->case_id);

        $this->engine->file('F-JDG-001', $judge, [
            'case_id' => (string) $case->id,
            'court_severity' => 'constitutional_major',
        ]);
        $this->bump('F-JDG-001');

        $cases = app(\App\Services\Judiciary\CaseService::class);
        $cases->advanceToHearing($case->refresh());
        $cases->enterDeliberation($case->refresh());

        // F-JDG-004 — the court finds a contradiction.
        $this->engine->file('F-JDG-004', $judge, [
            'challenge_id' => (string) $challenge->id,
            'finds_contradiction' => true,
            'contradiction_against' => 'constitution',
            'constitutional_citation' => 'Art. I',
            'offending_law_id' => (string) $law->id,
            'opinion_text' => 'The act contradicts Art. I (Phase E demo finding).',
        ]);
        $this->bump('F-JDG-004');

        // F-JDG-005 — the judge SETS the windows (arms CLK-11 + CLK-12).
        $this->engine->file('F-JDG-005', $judge, [
            'challenge_id' => (string) $challenge->id,
            'remedy_kind' => $remedyKind,
            'recommended_text' => $recommendedText,
            'rationale_text' => 'Striking the conflicting clause makes the law non-contradictory (Phase E demo).',
            'remedy_timeframe_days' => $timeframeDays,
            'veto_window_days' => $vetoDays,
        ]);
        $this->bump('F-JDG-005');

        return $challenge->refresh();
    }

    // =========================================================================
    // --fresh teardown (exact: only this demo's tagged Phase E rows)
    // =========================================================================

    private function teardown(Jurisdiction $jurisdiction, Judiciary $judiciary, Legislature $legislature): void
    {
        DB::transaction(function () use ($jurisdiction, $judiciary, $legislature) {
            $judiciaryId = (string) $judiciary->id;

            // ── Cases of this court (the demo court is the only court created
            //    on San Marino's judiciary row), plus everything hanging off
            //    them. The seat-FK rows (opinions / sentencing_orders / warrants
            //    / panel_judges) RESTRICT the judicial_seats delete — hard-delete
            //    them FIRST (none is append-only) so the bench can be purged.
            //    case_filings is APPEND-ONLY (a DB trigger blocks DELETE — the
            //    immutable docket) and RESTRICTs the cases delete, so the case
            //    itself is SOFT-deleted (cgc_ip_register precedent): it leaves
            //    every page, the docket chain stands.
            $caseIds = CourtCase::withTrashed()->where('judiciary_id', $judiciaryId)->pluck('id')->map(fn ($id) => (string) $id)->all();

            if ($caseIds !== []) {
                DB::table('opinion_law_links')->whereIn('opinion_id', DB::table('opinions')->whereIn('case_id', $caseIds)->pluck('id'))->delete();
                DB::table('opinions')->whereIn('case_id', $caseIds)->delete();
                DB::table('sentencing_orders')->whereIn('case_id', $caseIds)->delete();
                DB::table('warrants')->whereIn('case_id', $caseIds)->delete();
                DB::table('verdicts')->whereIn('case_id', $caseIds)->delete();
                DB::table('jury_members')->whereIn('jury_id', DB::table('juries')->whereIn('case_id', $caseIds)->pluck('id'))->delete();
                DB::table('juries')->whereIn('case_id', $caseIds)->delete();
                DB::table('panel_judges')->whereIn('panel_id', DB::table('panels')->whereIn('case_id', $caseIds)->pluck('id'))->delete();
                // Release the cases.panel_id / jury_id SET-NULL refs before the panels delete.
                DB::table('cases')->whereIn('id', $caseIds)->update(['panel_id' => null, 'jury_id' => null]);
                DB::table('panels')->whereIn('case_id', $caseIds)->delete();
                DB::table('case_parties')->whereIn('case_id', $caseIds)->delete();
                // case_filings is append-only — it is NOT deleted (the docket
                // chain is immutable); it pins the case, so the case soft-deletes.
            }

            // ── Constitutional challenges of this court + their findings /
            //    recommendations / armed timers (challenge→finding→remedy
            //    CASCADE on the challenge delete; the per-challenge clock_timers
            //    are demo rows — drop them; audit_log/public_records are kept).
            $challengeIds = ConstitutionalChallenge::withTrashed()->where('judiciary_id', $judiciaryId)->pluck('id')->map(fn ($id) => (string) $id)->all();

            if ($challengeIds !== []) {
                // The demo challenges' CLK-11/CLK-12 per-case timers (armed or
                // fired) — exact teardown of the demo's own clocks.
                ClockTimer::query()
                    ->where('subject_type', 'constitutional_challenges')
                    ->whereIn('subject_id', $challengeIds)
                    ->delete();

                // Null the SET-NULL self-refs first so the cascade is clean.
                DB::table('constitutional_challenges')->whereIn('id', $challengeIds)->update([
                    'finding_id' => null, 'remedy_id' => null, 'case_id' => null,
                ]);
                DB::table('bills')->whereIn('targets_challenge_id', $challengeIds)->update(['targets_challenge_id' => null]);

                $findingIds = DB::table('constitutional_findings')->whereIn('challenge_id', $challengeIds)->pluck('id');
                DB::table('finding_offending_laws')->whereIn('finding_id', $findingIds)->delete();
                DB::table('remedy_recommendations')->whereIn('challenge_id', $challengeIds)->delete();
                DB::table('constitutional_findings')->whereIn('challenge_id', $challengeIds)->delete();
                DB::table('constitutional_challenges')->whereIn('id', $challengeIds)->delete();
            }

            // ── The cases themselves: SOFT-delete (case_filings append-only
            //    pins the row; deleted_at hides it from every page). Force a
            //    fresh deleted_at even on already-trashed demo rows so a second
            //    --fresh is a clean no-op. ───────────────────────────────────
            if ($caseIds !== []) {
                DB::table('cases')->whereIn('id', $caseIds)->whereNull('deleted_at')->update(['deleted_at' => now()]);
            }

            // ── Advocates of this court: SOFT-delete. A hard delete would fire
            //    the case_filings.advocate_id SET-NULL cascade as an UPDATE on
            //    the APPEND-ONLY case_filings docket (the trigger blocks DELETE
            //    AND UPDATE) — so the advocate row soft-deletes (leaves every
            //    page) while the immutable docket keeps its advocate_id. ──────
            DB::table('advocates')->where('judiciary_id', $judiciaryId)->whereNull('deleted_at')->update(['deleted_at' => now()]);

            // ── The bench: nominations + appointments + terms + CLK-09 timers +
            //    judicial_seats (seat-FK RESTRICT rows already cleared above). ─
            $seatRows = JudicialSeat::withTrashed()->where('judiciary_id', $judiciaryId)->get();

            foreach ($seatRows as $seat) {
                if ($seat->term_id !== null) {
                    ClockTimer::query()->where('subject_type', 'term')->where('subject_id', (string) $seat->term_id)->delete();
                    Term::withTrashed()->whereKey($seat->term_id)->forceDelete();
                }
                if ($seat->appointment_id !== null) {
                    Appointment::withTrashed()->whereKey($seat->appointment_id)->forceDelete();
                }
            }

            DB::table('judicial_nominations')->where('judiciary_id', $judiciaryId)->delete();
            DB::table('judicial_seats')->where('judiciary_id', $judiciaryId)->delete();

            // ── The chamber votes + proposals the demo filed (the Phase E
            //    judiciary kinds + the bog_consent seating votes). ────────────
            $this->teardownDemoVotes($legislature);

            // ── Reset the judiciary back to forming (the pre-demo footing) —
            //    NULL creation/conversion law pointers BEFORE law teardown so
            //    the SET-NULL FKs don't strand. ──────────────────────────────
            // court_name is NOT NULL with default 'Superior Court'; restore that
            // default footing rather than NULL (the activation stub posture).
            $judiciary->forceFill([
                'status' => Judiciary::STATUS_FORMING,
                'type' => Judiciary::TYPE_APPOINTED,
                'nomination_mode' => null,
                'judge_count' => null,
                'court_name' => 'Superior Court',
                'creation_law_id' => null,
                'conversion_law_id' => null,
                'conversion_process_id' => null,
                'converted_at' => null,
                'source_legislature_id' => null,
            ])->save();

            // ── Demo laws. Two families:
            //    (a) the F-LEG-017 charter law ("Judiciary Creation Act") — its
            //        generic title carries no tag, but it is uniquely scoped to
            //        THIS demo court (scope_judiciary_id), and the demo is the
            //        only thing that creates a court on San Marino's judiciary
            //        row; it is bill-origin with only its enactment version, so
            //        force-purge it to keep the act-number sequence contiguous
            //        (the PhaseDDemoCommand 'Executive Committee Delegation Act'
            //        precedent — purge the generic creation act).
            //    (b) the tagged offending laws (PE-DEMO): the auto-remedy law
            //        carries an append-only judicial_remedy v2 — SOFT-delete it
            //        (cgc_ip_register precedent: the immutable version history
            //        stands, the law leaves every page); the window-open law has
            //        only v1 — force-purge it.
            $this->teardownDemoLaws($jurisdiction, $judiciaryId);

            $this->roles->flush();
        });

        $this->line('  demo Phase E rows removed; San Marino judiciary reset to forming');
    }

    /**
     * Remove the chamber votes + proposals the demo opened on San Marino's
     * chamber — the Phase E judiciary proposal kinds, plus the bog_consent
     * seating votes (and their casts/tallies + the riding appointments).
     */
    private function teardownDemoVotes(Legislature $legislature): void
    {
        $legislatureId = (string) $legislature->id;

        $phaseEKinds = [
            ChamberVoteProposal::KIND_JUDICIARY_CREATION,
            ChamberVoteProposal::KIND_JUDICIARY_CONVERSION,
        ];

        $proposals = ChamberVoteProposal::query()
            ->where('legislature_id', $legislatureId)
            ->whereIn('proposal_kind', $phaseEKinds)
            ->get();

        $voteIds = $proposals->pluck('vote_id')->filter()->map(fn ($id) => (string) $id)->all();

        // The judicial consent votes (bog_consent) on this chamber + the
        // judiciary_override votes (Path 2 — none in this demo, but defensive).
        $voteIds = array_values(array_unique(array_merge(
            $voteIds,
            ChamberVote::query()
                ->where('body_id', $legislatureId)
                ->whereIn('vote_type', ['bog_consent', 'judiciary_override'])
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all(),
        )));

        if ($voteIds !== []) {
            DB::table('vote_casts')->whereIn('vote_id', $voteIds)->delete();
            DB::table('chamber_vote_tallies')->whereIn('vote_id', $voteIds)->delete();

            // The demo appointments riding bog_consent votes (judge consent) —
            // already force-deleted via the seat walk, but a defensive sweep of
            // any orphan keyed to these votes.
            $appointmentIds = Appointment::withTrashed()->whereIn('consent_vote_id', $voteIds)->pluck('id')->all();
            if ($appointmentIds !== []) {
                Appointment::withTrashed()->whereIn('id', $appointmentIds)->forceDelete();
            }
        }

        ChamberVoteProposal::query()->where('legislature_id', $legislatureId)->whereIn('proposal_kind', $phaseEKinds)->delete();

        if ($voteIds !== []) {
            ChamberVote::query()->whereIn('id', $voteIds)->delete();
        }
    }

    /**
     * Tear down the demo laws: the PE-DEMO-tagged offending laws AND the
     * F-LEG-017 charter law scoped to THIS demo court (the generic "Judiciary
     * Creation Act" title carries no tag, but scope_judiciary_id uniquely
     * identifies it). A law carrying a source='judicial_remedy' version is
     * SOFT-deleted (append-only history is irreversible — the cgc_ip_register
     * precedent); a law with only its enactment version is force-purged so the
     * act-number sequence stays contiguous (the EnactmentService::
     * allocateActNumber count argument). The judiciary's creation_law_id /
     * conversion_law_id were NULLed by the reset above so the RESTRICT/SET-NULL
     * FKs no longer strand.
     */
    private function teardownDemoLaws(Jurisdiction $jurisdiction, string $judiciaryId): void
    {
        $laws = Law::withTrashed()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where(function ($q) use ($judiciaryId) {
                $q->where('act_number', 'like', self::DEMO_LAW_TAG.'%')
                    ->orWhere('scope_judiciary_id', $judiciaryId);
            })
            ->get();

        foreach ($laws as $law) {
            $hasJudicialRemedy = LawVersion::query()
                ->where('law_id', (string) $law->id)
                ->where('source', LawVersion::SOURCE_JUDICIAL_REMEDY)
                ->exists();

            // Release any SET-NULL/RESTRICT inbound refs that survive (the
            // challenge/finding rows were already deleted; scope_judiciary_id was
            // NULLed via the judiciary reset). Defensive: null the offending-law
            // findings ref if any orphan remains.
            if ($hasJudicialRemedy) {
                // Append-only history pins this row — soft-delete (hide it from
                // every page, which filters deleted_at) and leave law_versions.
                Law::withTrashed()->whereKey($law->id)->restore();
                $law->refresh()->delete();
            } else {
                // law_versions CASCADES on the hard delete; no judicial-remedy
                // history to preserve here.
                Law::withTrashed()->whereKey($law->id)->forceDelete();
            }
        }
    }

    // =========================================================================
    // Fixtures / helpers
    // =========================================================================

    /**
     * A demo offending in-force law bound to San Marino + scoped to the demo
     * court (so F-IND-016's courtFor resolves to the appointed court directly).
     * Tagged in act_number for teardown.
     */
    private function demoOffendingLaw(string $jurisdictionId, string $legislatureId, string $judiciaryId, string $label): Law
    {
        $law = Law::create([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => $legislatureId,
            'act_number' => self::DEMO_LAW_TAG.'-'.strtoupper(Str::random(8)),
            'title' => 'Demo Contested Act ('.$label.') '.self::DEMO_COURT_TAG,
            'kind' => Law::KIND_ORDINARY,
            'scale' => [$jurisdictionId],
            'origin' => Law::ORIGIN_BILL,
            'status' => Law::STATUS_IN_FORCE,
            'scope_judiciary_id' => $judiciaryId,
            'current_version_no' => 1,
            'effective_at' => now(),
            'enacted_at' => now(),
        ]);

        $text = "Original demo act text v1 ({$label}) — Phase E demo. This clause is the contested provision.";

        LawVersion::create([
            'id' => (string) Str::uuid(),
            'law_id' => (string) $law->id,
            'version_no' => 1,
            'text' => $text,
            'text_hash' => hash('sha256', $text),
            'source' => LawVersion::SOURCE_ENACTMENT,
            'source_ref_type' => 'bill',
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

    /**
     * $n associated San Marino residents (the judicial-nominee / party /
     * challenger eligibility = active association ONLY, Art. I).
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
            throw new RuntimeException("San Marino has fewer than {$n} associated residents — seed more residents.");
        }

        return User::query()->whereIn('id', $ids)->get()->values();
    }

    /**
     * Two associated residents NOT seated as judges of this court (free to be
     * parties), excluding any ids in $exclude.
     *
     * @param  list<string>  $exclude
     * @return array{0: User, 1: User}
     */
    private function twoFreeResidents(string $jurisdictionId, Judiciary $judiciary, array $exclude = []): array
    {
        $seatedJudgeIds = JudicialSeat::query()
            ->where('judiciary_id', (string) $judiciary->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        $ids = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->whereNotIn('user_id', array_merge($seatedJudgeIds, $exclude))
            ->limit(2)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (count($ids) < 2) {
            throw new RuntimeException('San Marino has fewer than two free associated residents — seed more residents.');
        }

        return [User::query()->findOrFail($ids[0]), User::query()->findOrFail($ids[1])];
    }

    /** A seated judge of the court (any seated judge derives R-19). */
    private function aSeatedJudge(Judiciary $judiciary): User
    {
        $seat = JudicialSeat::query()
            ->where('judiciary_id', (string) $judiciary->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->orderBy('seat_number')
            ->firstOrFail();

        return User::query()->findOrFail((string) $seat->user_id);
    }

    /**
     * A demo resident user with an active San Marino association (the advocate /
     * client / challenger actors). Email-tagged for teardown convenience; the
     * row persists harmlessly even when not torn down (users are append-only by
     * design in this app — no demo-user purge is attempted, matching the Phase D
     * command's posture).
     */
    private function demoResident(string $jurisdictionId, string $label): User
    {
        $user = User::create([
            'name' => "Phase E Demo {$label}",
            'email' => self::DEMO_EMAIL_TAG.'.'.$label.'.'.Str::lower(Str::random(8)).'@cga.test',
            'password' => \Illuminate\Support\Facades\Hash::make('demo'),
            'terms_accepted_at' => now(),
        ]);

        DB::table('residency_confirmations')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $user->getKey(),
            'jurisdiction_id' => $jurisdictionId,
            'days_confirmed' => 30,
            'confirmed_at' => now(),
            'is_active' => true,
            'depth' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** A serving NON-Speaker member's User of the chamber (the R-09 actor). */
    private function nonSpeakerUser(Legislature $legislature): User
    {
        $member = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }

    /** Drive every serving NON-Speaker member's yes cast (via F-LEG-004) until the vote closes. */
    private function castAllYes(Legislature $legislature, string $voteId): void
    {
        $members = LegislatureMember::query()
            ->where('legislature_id', (string) $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->when($legislature->speaker_id !== null, fn ($q) => $q->whereKeyNot($legislature->speaker_id))
            ->get();

        foreach ($members as $member) {
            if (ChamberVote::query()->whereKey($voteId)->value('status') !== ChamberVote::STATUS_OPEN) {
                break;
            }

            $this->engine->file('F-LEG-004', User::query()->findOrFail($member->user_id), [
                'vote_id' => $voteId,
                'value' => 'yes',
            ]);
        }
    }

    // =========================================================================
    // Reporting
    // =========================================================================

    private function report(Jurisdiction $jurisdiction, Judiciary $judiciary, float $startedAt): void
    {
        $judiciary->refresh();
        $base = rtrim((string) config('app.url'), '/');

        $this->newLine();
        $this->info('════════════════════ PHASE E DEMO COMPLETE ════════════════════');
        $this->line("Judiciary: {$judiciary->id} — status {$judiciary->status} · {$this->artifacts['judges_seated']} judges seated (10-yr CLK-09 terms)");
        $this->line("  {$base}/judiciaries/{$judiciary->id}");
        $this->line("  {$base}/judiciaries/{$judiciary->id}/docket");

        if (isset($this->artifacts['advocate_id'])) {
            $this->line("Advocate: {$this->artifacts['advocate_id']} — filed case {$this->artifacts['advocate_case_id']} (F-ADV-001)");
            $this->line("  {$base}/judiciary/advocate");
        }

        if (isset($this->artifacts['case_paneled_id'])) {
            $this->line(sprintf(
                'Cases: %s paneled (%d judges) + jury (summons %s) · %s closed (verdict+sentence+warrant+opinion)',
                $this->artifacts['case_paneled_docket'],
                $this->artifacts['case_paneled_panel_size'],
                $this->artifacts['jury_summons_id'],
                $this->artifacts['case_closed_docket'],
            ));
            $this->line("  {$base}/cases/{$this->artifacts['case_paneled_id']}");
            $this->line("  {$base}/cases/{$this->artifacts['case_closed_id']}");
            $this->line("  {$base}/judiciary/jury/{$this->artifacts['jury_summons_id']}");
        }

        if (isset($this->artifacts['challenge_window_open_id'])) {
            $this->line(sprintf(
                'Challenges: %s legislative_window_open (CLK-11/12 armed) · %s judicial_remedy_applied (law %s v%d)',
                $this->artifacts['challenge_window_open_id'],
                $this->artifacts['challenge_applied_id'],
                $this->artifacts['challenge_applied_law_id'],
                $this->artifacts['challenge_applied_law_version'],
            ));
            $this->line("  {$base}/constitutional-challenges");
            $this->line("  {$base}/constitutional-challenges/{$this->artifacts['challenge_applied_id']}");
        }

        $this->newLine();
        $this->line('Engine filings: '.collect($this->filings)->map(fn ($n, $f) => "{$f}×{$n}")->implode(' · '));
        $this->line(sprintf('TOTAL %.1fs', microtime(true) - $startedAt));
        $this->newLine();
        $this->call('audit:verify');
    }

    /** Report-and-exit summary for an already-seeded (standing) instance. */
    private function reportExisting(Jurisdiction $jurisdiction, Judiciary $judiciary): void
    {
        $base = rtrim((string) config('app.url'), '/');

        $judges = JudicialSeat::query()->where('judiciary_id', $judiciary->id)->where('status', JudicialSeat::STATUS_SEATED)->count();
        $cases = CourtCase::query()->where('judiciary_id', $judiciary->id)->count();
        $challenges = ConstitutionalChallenge::query()->where('judiciary_id', $judiciary->id)->count();
        $advocates = Advocate::query()->where('judiciary_id', $judiciary->id)->count();

        $this->newLine();
        $this->line("Judiciary {$judiciary->id}: {$judiciary->status} · {$judges} judges · {$cases} case(s) · {$challenges} challenge(s) · {$advocates} advocate(s)");
        $this->line("  {$base}/judiciaries/{$judiciary->id}");
        $this->line("  {$base}/judiciaries/{$judiciary->id}/docket");
    }

    // =========================================================================
    // Small helpers
    // =========================================================================

    private function step(string $label, callable $fn): mixed
    {
        $this->newLine();
        $this->info($label);

        return $fn();
    }

    private function bump(string $formId): void
    {
        $this->filings[$formId] = ($this->filings[$formId] ?? 0) + 1;
    }

    private function countFiling(string $label): void
    {
        $this->filings[$label] = ($this->filings[$label] ?? 0) + 1;
    }
}
