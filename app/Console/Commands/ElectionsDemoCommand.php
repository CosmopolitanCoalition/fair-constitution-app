<?php

namespace App\Console\Commands;

use App\Domain\Ballots\BallotReceipt;
use App\Domain\Ballots\BallotReceiptHolder;
use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Jobs\ApprovalStandingsRollupJob;
use App\Jobs\Clocks\EvaluateResidencyThresholdsJob;
use App\Jobs\EvaluateClocksJob;
use App\Models\BallotEnvelope;
use App\Models\Candidacy;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\Jurisdiction;
use App\Models\JurisdictionActivation;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\ResidencyClaim;
use App\Models\Tabulation;
use App\Models\User;
use App\Services\ActivationService;
use App\Services\ApprovalService;
use App\Services\ClockService;
use App\Services\ElectionLifecycleService;
use App\Services\ResidencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WI-B9 — `elections:demo` (design §D WI-B9): the Phase B exit criterion.
 *
 *   php artisan elections:demo smr-2-montegiardino --voters=40 --candidates=12 --instant
 *
 * One command that walks a REAL election end to end — every mutation goes
 * through the constitutional engine / the owning services, never around
 * them. The demo IS the verification:
 *
 *   1. activation / bootstrap board / scheduled election adopted or created
 *      (ActivationService::activate / replan — idempotent; an already-
 *      consumed approval_open cycle or a seated chamber REFUSES without
 *      --again; --again supersedes a dirty cycle by the lawful
 *      approval_open → cancelled move and files a fresh F-ELB-001);
 *   2. N voters seeded the genuine way: F-IND-001 → F-IND-003 (declared at
 *      the chamber's jurisdiction, or round-robin across the district
 *      footprints' member jurisdictions for districted chambers) →
 *      backdated F-IND-005 pings (ResidencyService::simulatePings) →
 *      CLK-05 sweep → F-IND-006 verification → R-04;
 *   3. K candidacies (F-IND-011) allocated across ALL races; the bootstrap
 *      board validates each (F-ELB-002, system-as-board provenance via the
 *      synthetic member row);
 *   4. Zipf-skewed revocable approvals (ApprovalService — deliberately not
 *      a form, zero per-approval audit) + ApprovalStandingsRollupJob;
 *   5. phase advance with REAL clock provenance: the armed CLK-18/CLK-01
 *      phase timers are FIRED through ClockService::fire — with --instant
 *      the queue runs sync so every handler job (FinalistCutoffJob,
 *      AdvanceElectionPhaseJob, TabulateElectionJob fan-out) executes
 *      inline, each still audited; without --instant the schedule is
 *      re-confirmed via F-ELB-001 under config('cga.election_demo_
 *      compression') (config, never data) and a poll loop runs the real
 *      EvaluateClocksJob sweep until each boundary passes;
 *   6. every voter files F-IND-007 with randomized rankings over their
 *      race's finalists (+ write-ins of validated non-finalists where any
 *      exist — tabulated identically); receipts collected via the
 *      read-once BallotReceiptHolder, one printed with verify steps;
 *   7. ranked close → TabulateElectionJob (publication first, then the
 *      count) → F-ELB-004 system certification → members seated, terms
 *      opened (CLK-10 lockstep), legislature active, CLK-01 armed,
 *      successor approval phase open;
 *   8. report: chamber URL, per-race results URLs, the example receipt +
 *      verification instructions, audit:verify, timing.
 *
 * Demo voters (password 'demo') remain impersonatable via the Phase A dev
 * bar for manual UI walking. Re-running without --again refuses cleanly —
 * the certified election + seated chamber + open successor ARE the demo
 * state and are left in place.
 */
class ElectionsDemoCommand extends Command
{
    protected $signature = 'elections:demo
                            {slug : Jurisdiction slug or UUID}
                            {--voters=40 : Demo voters to seed (F-IND-001/003/005/006)}
                            {--candidates=12 : Candidacies across all races (min Σ(seats+1) per election)}
                            {--instant : Drive every phase synchronously (timers fired, jobs inline)}
                            {--again : Supersede a consumed approval_open cycle / re-elect a seated chamber}';

    protected $description = 'Run a full demo election through the real constitutional engine (Phase B exit criterion)';

    /** Compressed minutes per phase when --instant is off and no env value is set. */
    private const DEFAULT_COMPRESSION_MINUTES = 2;

    /** Poll cadence + ceiling for the non-instant clock-driven walk. */
    private const POLL_SECONDS   = 10;
    private const POLL_TIMEOUT_S = 3600;

    /** Step timings for the final report. @var array<string, float> */
    private array $timings = [];

    /** Engine-filing tallies for the final report. @var array<string, int> */
    private array $filings = [];

    /**
     * The example receipt: [BallotReceipt, rankings, ElectionRace, voter email].
     *
     * @var array{0: BallotReceipt, 1: list<string>, 2: ElectionRace, 3: string}|null
     */
    private ?array $exampleReceipt = null;

    private int $receiptCount = 0;

    public function __construct(
        private readonly ActivationService $activation,
        private readonly ElectionLifecycleService $lifecycle,
        private readonly ConstitutionalEngine $engine,
        private readonly ResidencyService $residency,
        private readonly ApprovalService $approvals,
        private readonly ClockService $clocks,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);

        $instant    = (bool) $this->option('instant');
        $again      = (bool) $this->option('again');
        $voters     = max(1, (int) $this->option('voters'));
        $candidates = max(1, (int) $this->option('candidates'));

        // All queue dispatches in THIS process run inline: ClockService::fire
        // hands its handler job to the default connection, and the demo must
        // observe each phase's effects synchronously (with --instant) or at
        // worst within its own poll tick (without). Horizon and other
        // processes are untouched — this is process-local config.
        config(['queue.default' => 'sync']);

        $jurisdiction = $this->resolveJurisdiction((string) $this->argument('slug'));

        if ($jurisdiction === null) {
            $this->error('Jurisdiction not found: ' . $this->argument('slug'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'elections:demo — %s (%s) · voters=%d candidates=%d · %s mode',
            $jurisdiction->name,
            $jurisdiction->slug,
            $voters,
            $candidates,
            $instant ? 'instant' : 'compressed-timer'
        ));

        try {
            // ── 1. activation / board / election ──────────────────────────
            $election = $this->step('1. ensure election', fn () => $this->ensureElection($jurisdiction, $again, $instant));

            $legislature = Legislature::query()->findOrFail($election->legislature_id);
            $races       = $election->races()->orderByRaw('district_id IS NULL, seats DESC')->get();

            $this->table(
                ['race', 'seat_kind', 'district', 'seats', 'finalist X', 'status'],
                $races->map(fn (ElectionRace $r) => [
                    substr((string) $r->id, 0, 8),
                    $r->seat_kind,
                    $r->district_id === null ? 'at-large' : substr((string) $r->district_id, 0, 8),
                    $r->seats,
                    $r->finalist_count,
                    $r->status,
                ])->all(),
            );

            // ── 2. voters ──────────────────────────────────────────────────
            $seeded = $this->step('2. seed voters', fn () => $this->seedVoters($election, $races, $voters));

            // ── 3. candidacies + board validation ──────────────────────────
            $byRace = $this->step('3. candidacies', fn () => $this->registerCandidates($election, $races, $seeded, $candidates));

            // ── 4. approvals + rollup ──────────────────────────────────────
            $popularity = $this->step('4. approvals', fn () => $this->castApprovals($election, $races, $seeded, $byRace));

            // ── 5. finalist cutoff → ranked open (clock provenance) ────────
            $this->step('5. cutoff + ranked open', function () use ($election, $instant) {
                $this->advance($election, 'finalist_cutoff', Election::STATUS_FINALIST_CUTOFF, $instant);
                $this->advance($election, 'ranked_open', Election::STATUS_RANKED_OPEN, $instant);
            });

            // ── 6. ballots ─────────────────────────────────────────────────
            $this->step('6. ballots', fn () => $this->castBallots($election, $races, $seeded, $popularity));

            // ── 7. close → tabulate → certify ──────────────────────────────
            $certified = $this->step('7. close + tabulate + certify', function () use ($election, $instant) {
                $this->advance($election, 'ranked_close', Election::STATUS_TABULATING, $instant, allowVotingClosed: true);
                $this->awaitTabulation($election, $instant);

                return $this->certify($election);
            });

            // ── 8. report ──────────────────────────────────────────────────
            $this->report($jurisdiction, $legislature, $election, $races, $certified, $startedAt);
        } catch (DemoRefused $refusal) {
            $this->warn($refusal->getMessage());

            return self::FAILURE;
        } catch (ConstitutionalViolation $violation) {
            $this->error("Constitutional rejection: {$violation->getMessage()} ({$violation->citation})");
            $this->line('The rejection is sealed on the audit chain (rejected=true, with citation).');

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // =========================================================================
    // 1 — activation / board / election (+ --again semantics)
    // =========================================================================

    /**
     * Adopt or create the open-cycle election for the jurisdiction's
     * chamber. Refusal rules (idempotency contract, design WI-B9):
     *
     *  - chamber seated, successor approval_open  → --again re-elects it;
     *  - approval_open cycle already carries candidacies/approvals/ballots
     *    (another session consumed it)             → --again cancels it
     *    (legal ESM-03 move pre-ranked-window) and files a fresh F-ELB-001;
     *  - a cycle mid-flight past the cutoff        → refuse outright (it
     *    cannot lawfully be cancelled; wait for its certification — the
     *    successor opens automatically — then rerun with --again).
     */
    private function ensureElection(Jurisdiction $jurisdiction, bool $again, bool $instant): Election
    {
        $legislature = Legislature::query()->where('jurisdiction_id', $jurisdiction->id)->first();
        $activation  = JurisdictionActivation::query()->where('jurisdiction_id', $jurisdiction->id)->first();

        if ($legislature === null || $activation === null) {
            // Never activated: run the WF-JUR-01 pipeline (dev bootstrap —
            // CLK-06 bypassed, same posture as jurisdiction:activate --force).
            $this->line('  activating jurisdiction (WF-JUR-01 pipeline)…');
            $this->activation->activate($jurisdiction);
            $legislature = Legislature::query()->where('jurisdiction_id', $jurisdiction->id)->first();

            if ($legislature === null) {
                throw new RuntimeException('Activation produced no legislature — inspect audit_log.');
            }
        }

        $openCycle = fn (): ?Election => Election::query()
            ->where('legislature_id', $legislature->id)
            ->where('kind', Election::KIND_GENERAL)
            ->whereIn('status', [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN])
            ->orderByDesc('created_at')
            ->first();

        $election = $openCycle();

        if ($election === null) {
            $midFlight = Election::query()
                ->where('legislature_id', $legislature->id)
                ->whereIn('status', [
                    Election::STATUS_FINALIST_CUTOFF,
                    Election::STATUS_RANKED_OPEN,
                    Election::STATUS_VOTING_CLOSED,
                    Election::STATUS_TABULATING,
                    Election::STATUS_AUDIT_RERUN,
                ])
                ->orderByDesc('created_at')
                ->first();

            if ($midFlight !== null) {
                throw new DemoRefused(sprintf(
                    'Election %s is mid-flight (status: %s) — another session is driving this chamber. '
                    . 'Past the finalist cutoff a cycle cannot lawfully be cancelled (ESM-03); wait for its '
                    . 'certification (the successor approval phase opens automatically), then rerun with --again.',
                    $midFlight->id,
                    $midFlight->status,
                ));
            }

            // Activated but no open cycle (e.g. blocked posture resolved
            // since): re-enter step 3.5 — board + sizing posture + F-ELB-001.
            $this->line('  no open cycle — re-entering activation step 3.5 (replan)…');
            $this->activation->replan($jurisdiction);
            $election = $openCycle();

            if ($election === null) {
                throw new RuntimeException(
                    'No open-cycle election after re-plan — likely the §B.4 blocked-pending-subdivision '
                    . 'posture; inspect jurisdiction_activations.notes and the audit chain (Art. II §8).'
                );
            }
        }

        $raceIds = $election->races()->pluck('id')->map(fn ($id) => (string) $id)->all();

        // Participation read only (no secrecy-table writer outside BallotBox;
        // the model read mirrors the F-IND-007 double-vote check).
        $dirty = DB::table('candidacies')->where('election_id', $election->id)->whereNull('deleted_at')->exists()
            || DB::table('approvals')->where('election_id', $election->id)->exists()
            || ($raceIds !== [] && BallotEnvelope::query()->whereIn('race_id', $raceIds)->exists());

        $seated = DB::table('legislature_members')
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->whereNull('deleted_at')
            ->exists();

        if (($dirty || $seated) && ! $again) {
            throw new DemoRefused(sprintf(
                "Refusing to consume election %s (status: %s):%s%s\n"
                . 'Re-run with --again to run a fresh demo cycle (a dirty approval phase is cancelled and '
                . 'rescheduled; a seated chamber is lawfully re-elected through its open successor).',
                $election->id,
                $election->status,
                $dirty ? "\n  - the open approval phase already carries candidacies/approvals/ballots (consumed by another session)" : '',
                $seated ? "\n  - the chamber is seated; this open election is the live successor cycle (the demo state)" : '',
            ));
        }

        if ($dirty && $again) {
            // Supersede per lifecycle rules: approval_open → cancelled is a
            // legal ESM-03 move before the ranked window. Phase timers of the
            // cancelled cycle are cancelled too (their fires would no-op, but
            // the chain should show the tidy supersession).
            $this->line("  --again: cancelling consumed cycle {$election->id} and scheduling a fresh one…");
            $priorId = $election->prior_election_id;
            $this->lifecycle->cancel($election, 'superseded by elections:demo --again (fresh demo cycle)');

            foreach ($this->armedPhaseTimers($election) as $timer) {
                $this->clocks->cancel($timer, 'election cancelled by elections:demo --again');
            }

            $election = $this->fileSchedulingOrder($jurisdiction, $legislature, $instant, priorElectionId: $priorId);
        } else {
            $election = $this->confirmSchedule($jurisdiction, $election, $instant);
        }

        $election->refresh();

        if ($election->status !== Election::STATUS_APPROVAL_OPEN) {
            throw new RuntimeException(
                "Election {$election->id} is not approval_open after scheduling (status: {$election->status})."
            );
        }

        if (! $election->races()->exists()) {
            throw new RuntimeException(
                "Election {$election->id} has no races — §B.4 blocked posture (Art. II §8); see the audit chain."
            );
        }

        $this->line("  election {$election->id} ({$election->kind}, {$election->status})");

        return $election;
    }

    /** File a CREATE F-ELB-001 through the engine (system-as-board). */
    private function fileSchedulingOrder(Jurisdiction $jurisdiction, Legislature $legislature, bool $instant, ?string $priorElectionId): Election
    {
        $dates = $this->demoDates((string) $jurisdiction->id, $instant);

        $result = $this->engine->file('F-ELB-001', null, array_filter([
            'jurisdiction_id'    => (string) $jurisdiction->id,
            'legislature_id'     => (string) $legislature->id,
            'kind'               => Election::KIND_GENERAL,
            'trigger'            => 'demo',
            'prior_election_id'  => $priorElectionId,
            'approval_opens_at'  => $dates['approval_opens_at']->toIso8601String(),
            'finalist_cutoff_at' => $dates['finalist_cutoff_at']->toIso8601String(),
            'ranked_opens_at'    => $dates['ranked_opens_at']->toIso8601String(),
            'ranked_closes_at'   => $dates['ranked_closes_at']->toIso8601String(),
        ], fn ($v) => $v !== null));

        $this->bump('F-ELB-001');

        return Election::query()->findOrFail($result->recorded['election_id']);
    }

    /**
     * Confirm/refine an adopted open cycle through F-ELB-001 when needed:
     * a successor election starts with NULL phase dates (set at the next
     * CLK-01 fire in real life); compressed mode rewrites the boundaries.
     * A fully-dated cycle with armed timers is adopted as-is in --instant
     * mode (timers are fired early — no date surgery required).
     */
    private function confirmSchedule(Jurisdiction $jurisdiction, Election $election, bool $instant): Election
    {
        $needsDates = $election->finalist_cutoff_at === null
            || $election->ranked_opens_at === null
            || $election->ranked_closes_at === null;

        $needsTimers = count($this->armedPhaseTimers($election)) < 3;

        if ($instant && ! $needsDates && ! $needsTimers) {
            return $election; // adopt verbatim — the demo fires the armed timers
        }

        if ($needsDates || ! $instant) {
            $dates = $this->demoDates((string) $jurisdiction->id, $instant, $election);
        } else {
            $dates = [ // re-arm only: confirm the row's own published schedule
                'approval_opens_at'  => $election->approval_opens_at,
                'finalist_cutoff_at' => $election->finalist_cutoff_at,
                'ranked_opens_at'    => $election->ranked_opens_at,
                'ranked_closes_at'   => $election->ranked_closes_at,
            ];
        }

        $result = $this->engine->file('F-ELB-001', null, [
            'jurisdiction_id'    => (string) $jurisdiction->id,
            'election_id'        => (string) $election->id,
            'approval_opens_at'  => $dates['approval_opens_at']->toIso8601String(),
            'finalist_cutoff_at' => $dates['finalist_cutoff_at']->toIso8601String(),
            'ranked_opens_at'    => $dates['ranked_opens_at']->toIso8601String(),
            'ranked_closes_at'   => $dates['ranked_closes_at']->toIso8601String(),
        ]);

        $this->bump('F-ELB-001');

        return Election::query()->findOrFail($result->recorded['election_id']);
    }

    /**
     * The demo schedule. --instant keeps the constitutional defaults (the
     * armed timers are fired early — dates never lie about the law);
     * without --instant the boundaries compress to N minutes via
     * config('cga.election_demo_compression') — config, never data.
     *
     * @return array{approval_opens_at: \Illuminate\Support\Carbon, finalist_cutoff_at: \Illuminate\Support\Carbon, ranked_opens_at: \Illuminate\Support\Carbon, ranked_closes_at: \Illuminate\Support\Carbon}
     */
    private function demoDates(string $jurisdictionId, bool $instant, ?Election $election = null): array
    {
        if (! $instant) {
            $minutes = max(1, (int) config('cga.election_demo_compression') ?: self::DEFAULT_COMPRESSION_MINUTES);
            config(['cga.election_demo_compression' => $minutes]);
        }

        $dates = $this->lifecycle->defaultDates($jurisdictionId, $election?->approval_opens_at);

        if ($election?->approval_opens_at !== null) {
            $dates['approval_opens_at'] = \Illuminate\Support\Carbon::instance($election->approval_opens_at);
        }

        return $dates;
    }

    // =========================================================================
    // 2 — voters (F-IND-001 → F-IND-003 → F-IND-005×threshold → CLK-05 → F-IND-006)
    // =========================================================================

    /**
     * Seed N verified voters. Districted chambers distribute declarations
     * round-robin across district races and, within each, across the
     * district's member jurisdictions — every race footprint gets an
     * electorate. At-large-only chambers declare at the chamber's own
     * jurisdiction.
     *
     * @param  \Illuminate\Support\Collection<int, ElectionRace>  $races
     * @return list<array{user: User, jurisdiction_id: string, district_race_id: string|null}>
     */
    private function seedVoters(Election $election, $races, int $count): array
    {
        $districtRaces = $races->filter(fn (ElectionRace $r) => $r->district_id !== null)->values();

        // Footprint member jurisdictions per district race.
        $footprints = [];
        foreach ($districtRaces as $race) {
            $members = DB::table('legislature_district_jurisdictions')
                ->where('district_id', $race->district_id)
                ->pluck('jurisdiction_id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();

            if ($members === []) {
                throw new RuntimeException("District race {$race->id} has no member jurisdictions.");
            }

            $footprints[(string) $race->id] = $members;
        }

        $passwordHash = Hash::make('demo');
        $faker        = fake();
        $seeded       = [];

        $bar = $this->output->createProgressBar($count);
        $bar->setFormat('  voters %current%/%max% [%bar%] %elapsed%');
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            if ($districtRaces->isNotEmpty()) {
                $race    = $districtRaces[$i % $districtRaces->count()];
                $members = $footprints[(string) $race->id];
                $target  = $members[intdiv($i, $districtRaces->count()) % count($members)];
                $raceId  = (string) $race->id;
            } else {
                $target = (string) $election->jurisdiction_id;
                $raceId = null;
            }

            // F-IND-001 — guest-filable registration (the controller's
            // pre-hash contract: raw passwords never enter the engine).
            $registered = $this->engine->file('F-IND-001', null, [
                'name'          => $faker->name(),
                'email'         => sprintf('demo.%s.%03d.%s@cga.test', substr(md5((string) $election->id), 0, 6), $i, Str::lower(Str::random(5))),
                'password_hash' => $passwordHash,
                'terms'         => true,
                'languages'     => ['en'],
                'timezone'      => 'UTC',
            ]);
            $this->bump('F-IND-001');

            $user = User::query()->findOrFail($registered->recorded['user_id']);

            // F-IND-003 — declaration with ping consent.
            $declared = $this->engine->file('F-IND-003', $user, [
                'jurisdiction_id' => $target,
                'ping_consent'    => true,
            ]);
            $this->bump('F-IND-003');

            // Backdated qualifying pings — each one a REAL F-IND-005 filing
            // (the Phase A simulator path, wholesale).
            $threshold = (int) ($declared->recorded['threshold_days'] ?? ResidencyService::DEFAULT_THRESHOLD_DAYS);
            $this->residency->simulatePings($user, $threshold);
            $this->filings['F-IND-005'] = ($this->filings['F-IND-005'] ?? 0) + $threshold;

            $seeded[] = ['user' => $user, 'jurisdiction_id' => $target, 'district_race_id' => $raceId];
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // CLK-05 — the catch-up sweep (recordPing already flips inline; the
        // sweep is the constitutional evaluator the design names).
        dispatch_sync(new EvaluateResidencyThresholdsJob);

        // F-IND-006 — system verification per claim → associations → R-04.
        foreach ($seeded as $row) {
            $claim = ResidencyClaim::query()
                ->where('user_id', $row['user']->id)
                ->whereIn('status', ResidencyClaim::MONITORING_STATUSES)
                ->firstOrFail();

            $this->residency->verify($claim);
            $this->bump('F-IND-006');
        }

        $this->line(sprintf('  %d voters verified (R-04) across %s', count($seeded), $districtRaces->isNotEmpty()
            ? $districtRaces->count() . ' district footprints + at-large'
            : 'the at-large footprint'));

        return $seeded;
    }

    // =========================================================================
    // 3 — candidacies (F-IND-011) + board validation (F-ELB-002)
    // =========================================================================

    /**
     * Allocate K candidacies across all races (each race gets at least
     * seats+1 so every count has a loser; the surplus spreads
     * proportionally by seats) and validate each through the bootstrap
     * board (F-ELB-002 with explicit race_id — the multi-race
     * disambiguator for bicameral chambers).
     *
     * @param  \Illuminate\Support\Collection<int, ElectionRace>  $races
     * @param  list<array{user: User, jurisdiction_id: string, district_race_id: string|null}>  $seeded
     * @return array<string, list<Candidacy>> candidacies per race id
     */
    private function registerCandidates(Election $election, $races, array $seeded, int $count): array
    {
        $minTotal = $races->sum(fn (ElectionRace $r) => $r->seats + 1);

        if ($count < $minTotal) {
            throw new RuntimeException(sprintf(
                '--candidates=%d is below the minimum %d for this election (Σ seats+1 over %d races: %s) — '
                . 'every race needs more candidates than seats for a meaningful count.',
                $count,
                $minTotal,
                $races->count(),
                $races->map(fn (ElectionRace $r) => $r->seats . '+1')->implode(', '),
            ));
        }

        // seats+1 floor, surplus by largest remainder proportional to seats.
        $totalSeats = max(1, (int) $races->sum('seats'));
        $surplus    = $count - $minTotal;
        $alloc      = [];
        $fractions  = [];
        $given      = 0;

        foreach ($races as $race) {
            $share                       = $surplus * $race->seats / $totalSeats;
            $alloc[(string) $race->id]   = $race->seats + 1 + (int) floor($share);
            $fractions[(string) $race->id] = $share - floor($share);
            $given += (int) floor($share);
        }

        arsort($fractions);
        foreach (array_keys($fractions) as $raceId) {
            if ($given >= $surplus) {
                break;
            }
            $alloc[$raceId]++;
            $given++;
        }

        // Eligibility pools: district race → voters declared inside its
        // footprint; at-large → any seeded voter (the F-IND-006 ancestor
        // sweep guarantees the chamber-jurisdiction association).
        $isCandidate = [];
        $byRace      = [];

        $ordered = $races->sortBy(fn (ElectionRace $r) => $r->district_id === null ? 1 : 0)->values();

        foreach ($ordered as $race) {
            $raceId = (string) $race->id;
            $pool   = array_values(array_filter(
                $seeded,
                fn (array $row) => ! isset($isCandidate[(string) $row['user']->id])
                    && ($race->district_id === null || $row['district_race_id'] === $raceId)
            ));

            if (count($pool) < $alloc[$raceId]) {
                throw new RuntimeException(sprintf(
                    'Race %s needs %d candidates but only %d un-cast voters hold residency in its footprint — '
                    . 'raise --voters or lower --candidates.',
                    $raceId,
                    $alloc[$raceId],
                    count($pool),
                ));
            }

            $byRace[$raceId] = [];

            for ($i = 0; $i < $alloc[$raceId]; $i++) {
                $user = $pool[$i]['user'];
                $isCandidate[(string) $user->id] = true;

                $registered = $this->engine->file('F-IND-011', $user, [
                    'election_id'        => (string) $election->id,
                    'jurisdiction_id'    => (string) $election->jurisdiction_id,
                    'residency_attested' => true,
                    'platform_statement' => fake()->sentence(12),
                    'position_tags'      => fake()->randomElements(
                        ['transit', 'housing', 'water', 'energy', 'education', 'health', 'parks', 'budget'],
                        random_int(1, 3),
                    ),
                ]);
                $this->bump('F-IND-011');

                // F-ELB-002 — system-as-board validation, race bound
                // explicitly (district vs at-large disambiguation).
                $validated = $this->engine->file('F-ELB-002', null, [
                    'candidacy_id'    => (string) $registered->recorded['candidacy_id'],
                    'decision'        => 'validate',
                    'race_id'         => $raceId,
                    'jurisdiction_id' => (string) $election->jurisdiction_id,
                ]);
                $this->bump('F-ELB-002');

                $byRace[$raceId][] = Candidacy::query()->findOrFail($validated->recorded['candidacy_id']);
            }
        }

        $this->line('  ' . collect($byRace)->map(fn ($list, $raceId) => substr($raceId, 0, 8) . ': ' . count($list))
            ->implode(' · ') . ' candidacies validated (board: system-as-board)');

        return $byRace;
    }

    // =========================================================================
    // 4 — approvals (Zipf-skewed; deliberately un-audited per approval)
    // =========================================================================

    /**
     * Skewed approval sets: each race gets a hidden popularity order; voter
     * v approves candidate at popularity rank k with p ≈ 0.85/k^0.7 — the
     * resulting standings look like a real field, and the same weights
     * later drive ballot rankings (approval strength ≈ first-pref
     * strength, like an actual electorate).
     *
     * @param  \Illuminate\Support\Collection<int, ElectionRace>  $races
     * @param  list<array{user: User, jurisdiction_id: string, district_race_id: string|null}>  $seeded
     * @param  array<string, list<Candidacy>>  $byRace
     * @return array<string, float> popularity weight per candidacy id
     */
    private function castApprovals(Election $election, $races, array $seeded, array $byRace): array
    {
        mt_srand(crc32((string) $election->id)); // reproducible within a cycle

        $weights = [];
        $casts   = 0;

        foreach ($races as $race) {
            $raceId      = (string) $race->id;
            $candidacies = $byRace[$raceId] ?? [];
            shuffle($candidacies); // hidden popularity order

            foreach ($candidacies as $rank => $candidacy) {
                $weights[(string) $candidacy->id] = 1.0 / (($rank + 1) ** 0.85);
            }

            foreach ($this->eligibleVoters($seeded, $race) as $row) {
                foreach ($candidacies as $rank => $candidacy) {
                    $p = min(0.92, 0.85 / (($rank + 1) ** 0.7));

                    if (mt_rand() / mt_getrandmax() < $p) {
                        $this->approvals->cast($row['user'], $candidacy);
                        $casts++;
                    }
                }
            }
        }

        // The audited event: one standings rollup per race (counts hash
        // only — identities never leave the approvals table).
        dispatch_sync(new ApprovalStandingsRollupJob((string) $election->id));

        $this->line("  {$casts} approvals cast (secret, revocable) · standings rolled up");

        return $weights;
    }

    // =========================================================================
    // 5/7 — phase advancement with real clock provenance
    // =========================================================================

    /**
     * Advance one phase boundary. --instant fires the armed timer through
     * ClockService::fire (armed → fired + chain entry + handler inline on
     * the sync queue); without --instant the real EvaluateClocksJob sweep
     * is run every poll tick until the compressed fires_at passes.
     */
    private function advance(Election $election, string $step, string $expectedStatus, bool $instant, bool $allowVotingClosed = false): void
    {
        if ($instant) {
            $timer = collect($this->armedPhaseTimers($election))
                ->first(fn (ClockTimer $t) => ($t->payload['step'] ?? null) === $step);

            if ($timer === null) {
                throw new RuntimeException("No armed '{$step}' phase timer for election {$election->id}.");
            }

            $this->clocks->fire($timer, ['fired_early_by' => 'elections:demo --instant']);
        } else {
            $deadline = time() + self::POLL_TIMEOUT_S;

            while (true) {
                dispatch_sync(new EvaluateClocksJob);
                $election->refresh();

                if ($election->status === $expectedStatus
                    || ($allowVotingClosed && $election->status === Election::STATUS_VOTING_CLOSED)) {
                    break;
                }

                if (time() > $deadline) {
                    throw new RuntimeException("Timed out waiting for '{$step}' (election status: {$election->status}).");
                }

                sleep(self::POLL_SECONDS);
            }
        }

        $election->refresh();

        $ok = $election->status === $expectedStatus
            || ($allowVotingClosed && $election->status === Election::STATUS_VOTING_CLOSED);

        if (! $ok) {
            throw new RuntimeException(
                "Phase '{$step}' did not land on {$expectedStatus} (election status: {$election->status})."
            );
        }

        $this->line("  {$step} → {$election->status}");
    }

    /** Every race must hold a COMPLETE initial tabulation before F-ELB-004. */
    private function awaitTabulation(Election $election, bool $instant): void
    {
        $raceIds  = $election->races()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $deadline = time() + self::POLL_TIMEOUT_S;

        while (true) {
            $complete = Tabulation::query()
                ->whereIn('race_id', $raceIds)
                ->where('kind', Tabulation::KIND_INITIAL)
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->distinct()
                ->count('race_id');

            if ($complete >= count($raceIds)) {
                $this->line('  all ' . count($raceIds) . ' race(s) tabulated (publication sealed first, then the count)');

                return;
            }

            if ($instant) {
                throw new RuntimeException(
                    "Inline tabulation left {$complete}/" . count($raceIds) . ' races complete — inspect failed jobs / audit_log.'
                );
            }

            if (time() > $deadline) {
                throw new RuntimeException("Timed out waiting for tabulation ({$complete}/" . count($raceIds) . ' complete).');
            }

            sleep(self::POLL_SECONDS);
        }
    }

    // =========================================================================
    // 6 — ballots (F-IND-007, receipts via the read-once holder)
    // =========================================================================

    /**
     * Every voter files F-IND-007 in every race whose footprint holds
     * their association (district + at-large for bicameral chambers).
     * Rankings are Plackett-Luce samples over the race's finalists using
     * the approval-popularity weights; where validated non-finalists exist
     * (the field exceeded X), every ~6th ballot writes one in — proving
     * write-ins tabulate identically.
     *
     * @param  \Illuminate\Support\Collection<int, ElectionRace>  $races
     * @param  list<array{user: User, jurisdiction_id: string, district_race_id: string|null}>  $seeded
     * @param  array<string, float>  $weights
     */
    private function castBallots(Election $election, $races, array $seeded, array $weights): void
    {
        $holder   = app(BallotReceiptHolder::class);
        $writeIns = 0;
        $ballots  = 0;

        foreach ($races as $race) {
            $raceId = (string) $race->id;

            $finalists = Candidacy::query()
                ->where('race_id', $raceId)
                ->where('status', Candidacy::STATUS_FINALIST)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $nonFinalists = Candidacy::query()
                ->where('race_id', $raceId)
                ->where('status', Candidacy::STATUS_NON_FINALIST)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($finalists === []) {
                throw new RuntimeException("Race {$raceId} has no finalists after the cutoff.");
            }

            foreach ($this->eligibleVoters($seeded, $race) as $i => $row) {
                $rankings = $this->sampleRankings($finalists, $weights);

                if ($nonFinalists !== [] && $i % 6 === 0) {
                    // Write-in: any VALIDATED candidacy id ranks lawfully —
                    // the right to stand survives the cutoff (Art. I).
                    $writeIn = $nonFinalists[array_rand($nonFinalists)];
                    array_splice($rankings, random_int(0, count($rankings)), 0, [$writeIn]);
                    $writeIns++;
                }

                $this->engine->file('F-IND-007', $row['user'], [
                    'race_id'         => $raceId,
                    'rankings'        => $rankings,
                    'jurisdiction_id' => (string) $race->jurisdiction_id,
                ]);
                $this->bump('F-IND-007');

                // The {ballot_hash, salt} receipt travels out-of-band (the
                // chain carries participation only — §B.5.6).
                $receipt = $holder->take();

                if ($receipt !== null) {
                    $this->receiptCount++;

                    if ($this->exampleReceipt === null) {
                        $this->exampleReceipt = [$receipt, $rankings, $race, (string) $row['user']->email];
                    }
                }

                $ballots++;
            }
        }

        $this->line("  {$ballots} ballots committed ({$this->receiptCount} receipts issued, {$writeIns} carrying a write-in)");
    }

    /**
     * Plackett-Luce sample without replacement over the finalist pool —
     * popular candidates land early ranks more often, with genuine
     * variation. Length: half the field to the full field.
     *
     * @param  list<string>  $pool
     * @param  array<string, float>  $weights
     * @return list<string>
     */
    private function sampleRankings(array $pool, array $weights): array
    {
        $n      = count($pool);
        $length = random_int(max(1, (int) ceil($n / 2)), $n);

        $remaining = $pool;
        $ranking   = [];

        while (count($ranking) < $length && $remaining !== []) {
            $total = 0.0;
            foreach ($remaining as $id) {
                $total += $weights[$id] ?? 0.05;
            }

            $roll = (mt_rand() / mt_getrandmax()) * $total;
            $acc  = 0.0;
            $pick = array_key_last($remaining);

            foreach ($remaining as $key => $id) {
                $acc += $weights[$id] ?? 0.05;
                if ($roll <= $acc) {
                    $pick = $key;
                    break;
                }
            }

            $ranking[] = $remaining[$pick];
            array_splice($remaining, $pick, 1);
        }

        return $ranking;
    }

    // =========================================================================
    // 7 — certification (F-ELB-004, system-as-board)
    // =========================================================================

    /** @return array the F-ELB-004 recorded payload (winners, terms, successor…) */
    private function certify(Election $election): array
    {
        $result = $this->engine->file('F-ELB-004', null, [
            'election_id'     => (string) $election->id,
            'jurisdiction_id' => (string) $election->jurisdiction_id,
        ]);

        $this->bump('F-ELB-004');
        $this->line('  certified — count_record_hash ' . substr((string) $result->recorded['count_record_hash'], 0, 16) . '…');

        return $result->recorded;
    }

    // =========================================================================
    // 8 — report
    // =========================================================================

    /** @param \Illuminate\Support\Collection<int, ElectionRace> $races */
    private function report(
        Jurisdiction $jurisdiction,
        Legislature $legislature,
        Election $election,
        $races,
        array $certified,
        float $startedAt,
    ): void {
        $legislature->refresh();
        $election->refresh();

        $base = rtrim((string) config('app.url'), '/');

        $this->newLine();
        $this->info('════════════════════ DEMO ELECTION COMPLETE ════════════════════');

        // ── chamber ───────────────────────────────────────────────────────
        $memberCounts = DB::table('legislature_members')
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->whereNull('deleted_at')
            ->selectRaw("seat_type, count(*) AS n")
            ->groupBy('seat_type')
            ->pluck('n', 'seat_type');

        $this->line(sprintf(
            'Chamber: %s — status %s, %d members seated (type_a %d / type_b %d) · term %s → %s',
            $jurisdiction->name,
            $legislature->status,
            (int) $memberCounts->sum(),
            (int) ($memberCounts['a'] ?? 0),
            (int) ($memberCounts['b'] ?? 0),
            (string) $legislature->term_starts_on,
            (string) $legislature->term_ends_on,
        ));
        $this->line("Chamber URL:  {$base}/legislatures/{$legislature->id}");
        $this->line("Election URL: {$base}/elections/{$election->id}");

        // ── per-race results ──────────────────────────────────────────────
        $this->newLine();
        $this->line('Races:');

        foreach ($races as $race) {
            $race->refresh();

            $tabulation = Tabulation::query()
                ->where('race_id', $race->id)
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->orderByDesc('completed_at')
                ->first();

            $winners = $tabulation === null ? collect() : DB::table('race_results as rr')
                ->join('candidacies as c', 'c.id', '=', 'rr.candidacy_id')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->where('rr.tabulation_id', $tabulation->id)
                ->whereNotNull('rr.seat_no')
                ->orderBy('rr.seat_no')
                ->get(['u.name', 'rr.seat_no', 'c.status']);

            $this->line(sprintf(
                '  %s %s — %d seats · %d valid ballots · quota %s · %d elected%s',
                $race->seat_kind,
                $race->district_id === null ? 'at-large' : 'district ' . substr((string) $race->district_id, 0, 8),
                (int) $race->seats,
                (int) ($tabulation->total_valid ?? 0),
                (string) ($tabulation->quota ?? '—'),
                $winners->count(),
                $tabulation !== null ? ' · record ' . substr((string) $tabulation->record_hash, 0, 12) . '…' : '',
            ));
            $this->line('    results: ' . "{$base}/elections/{$election->id}/results?race={$race->id}");
            $this->line('    winners: ' . $winners->pluck('name')->implode(', '));
        }

        // ── receipt + verification instructions ──────────────────────────
        if ($this->exampleReceipt !== null) {
            [$receipt, $rankings, $race, $email] = $this->exampleReceipt;

            $published = DB::table('audit_log')
                ->where('module', 'elections')
                ->where('event', 'ballot_hashes.published')
                ->where('payload->race_id', (string) $race->id)
                ->whereJsonContains('payload->ballot_hashes', $receipt->ballotHash)
                ->exists();

            $this->newLine();
            $this->line("Example ballot receipt ({$this->receiptCount} issued; voter {$email}, race " . substr((string) $race->id, 0, 8) . '…):');
            $this->line("  ballot_hash: {$receipt->ballotHash}");
            $this->line("  salt:        {$receipt->salt}");
            $this->line('  local commitment re-check (sha256(salt ‖ canonical(rankings))): '
                . ($receipt->verifies($rankings) ? 'VERIFIED' : 'FAILED'));
            $this->line('  inclusion in the published per-race hash list (chain event ballot_hashes.published): '
                . ($published ? 'INCLUDED' : 'NOT FOUND'));
            $this->line('  Verify it yourself:');
            $this->line("    1. POST {$base}/receipt-check {hash} — the anonymous lookup (found · committed bucket · counted)");
            $this->line('    2. Check inclusion in the sorted hash list sealed on the audit chain for the race');
            $this->line('       (event ballot_hashes.published; its root_hash pins the list — tamper-evident).');
            $this->line('    3. Holding the salt, recompute sha256(salt ‖ canonical rankings) and compare.');
        }

        // ── successor + clocks ────────────────────────────────────────────
        $successorId = $certified['next_election_id'] ?? null;
        $successor   = $successorId !== null ? Election::query()->find($successorId) : null;

        $clk01 = ClockTimer::query()
            ->armed()
            ->where('clock_id', 'CLK-01')
            ->where('subject_type', 'legislature')
            ->where('subject_id', (string) $legislature->id)
            ->first();

        $clk10 = ClockTimer::query()
            ->armed()
            ->where('clock_id', 'CLK-10')
            ->where('subject_type', 'term')
            ->count();

        $this->newLine();
        $this->line(sprintf(
            'Cycle loop: successor election %s (%s) · CLK-01 next cycle %s · %d CLK-10 lockstep flags armed',
            $successorId ?? '—',
            $successor->status ?? '—',
            $clk01?->fires_at?->toIso8601String() ?? 'NOT ARMED',
            $clk10,
        ));
        $this->line("Demo voters are impersonatable from the dev bar (password 'demo').");

        // ── filings + timing ──────────────────────────────────────────────
        $this->newLine();
        $this->line('Engine filings: ' . collect($this->filings)
            ->map(fn ($n, $form) => "{$form}×{$n}")
            ->implode(' · '));

        $this->line('Timing: ' . collect($this->timings)
            ->map(fn ($s, $step) => sprintf('%s %.1fs', $step, $s))
            ->implode(' · ')
            . sprintf(' · TOTAL %.1fs', microtime(true) - $startedAt));

        // ── audit chain ───────────────────────────────────────────────────
        $this->newLine();
        $this->call('audit:verify');
    }

    // =========================================================================
    // Small helpers
    // =========================================================================

    private function resolveJurisdiction(string $slugOrUuid): ?Jurisdiction
    {
        return Jurisdiction::query()
            ->where(function ($q) use ($slugOrUuid) {
                $q->where('slug', $slugOrUuid);
                if (Str::isUuid($slugOrUuid)) {
                    $q->orWhere('id', $slugOrUuid);
                }
            })
            ->first();
    }

    /**
     * Voters eligible for one race: district races take the voters whose
     * declaration round-robined into their footprint; at-large races take
     * every seeded voter (the verification sweep wrote the chamber
     * association for all of them).
     *
     * @param  list<array{user: User, jurisdiction_id: string, district_race_id: string|null}>  $seeded
     * @return list<array{user: User, jurisdiction_id: string, district_race_id: string|null}>
     */
    private function eligibleVoters(array $seeded, ElectionRace $race): array
    {
        if ($race->district_id === null) {
            return $seeded;
        }

        $raceId = (string) $race->id;

        return array_values(array_filter($seeded, fn (array $row) => $row['district_race_id'] === $raceId));
    }

    /** @return list<ClockTimer> the election's armed CLK-18/CLK-01 phase timers */
    private function armedPhaseTimers(Election $election): array
    {
        return ClockTimer::query()
            ->armed()
            ->where('subject_type', 'election')
            ->where('subject_id', (string) $election->id)
            ->get()
            ->filter(fn (ClockTimer $t) => in_array($t->payload['step'] ?? null, ['finalist_cutoff', 'ranked_open', 'ranked_close'], true))
            ->values()
            ->all();
    }

    /** Run one numbered step with wall-clock timing for the report. */
    private function step(string $label, callable $fn): mixed
    {
        $this->newLine();
        $this->info($label);

        $t0     = microtime(true);
        $result = $fn();

        $this->timings[$label] = microtime(true) - $t0;

        return $result;
    }

    private function bump(string $formId): void
    {
        $this->filings[$formId] = ($this->filings[$formId] ?? 0) + 1;
    }
}

/**
 * A clean operator-facing refusal (idempotency contract) — not an error in
 * the system, just "this run would consume state you didn't ask to consume".
 */
class DemoRefused extends RuntimeException
{
}
