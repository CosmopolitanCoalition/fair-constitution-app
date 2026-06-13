<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Jobs\Organizations\RecomputeWorkerHeadcountJob;
use App\Models\Appointment;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ClockTimer;
use App\Models\Department;
use App\Models\Election;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\ExecutiveOrder;
use App\Models\Jurisdiction;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Models\OrgContract;
use App\Models\OrgWorker;
use App\Models\Term;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\Executive\BoardGovernorService;
use App\Services\Executive\ExecutiveOrderService;
use App\Services\Organizations\CgcIpRegisterService;
use App\Services\Organizations\CoDeterminationService;
use App\Services\Organizations\OrgBoardService;
use App\Services\Organizations\OrgRegistryService;
use App\Services\RoleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase D analogue of `elections:demo` — persist the Phase D exit-criterion
 * flows on San Marino so the executive & organizations pages render with
 * STANDING, BROWSABLE data (instead of the empty states they show while
 * nothing is delegated/registered).
 *
 *   php artisan institutions:demo-d
 *   php artisan institutions:demo-d --fresh
 *
 * The four constitutional Phase D tests
 * (ExecDelegationProportionalityTest, GovernorRemovalOrdinaryMajorityTest,
 * WorkerRepresentationTest, OrderScopeValidationTest) drive each flow end
 * to end through the REAL ConstitutionalEngine and then ROLL BACK. This
 * command replays the SAME working sequences but PERSISTS them — the demo
 * IS the data the pages browse.
 *
 * It seeds, on San Marino's bicameral chamber + its FORMING executive:
 *
 *   1. DELEGATION (engine-filed F-LEG-014 → exec_delegate SUPERMAJORITY
 *      vote → every seated member casts → auto-close ADOPTED →
 *      ExecutiveFormationService::applyDelegation): forming → delegated
 *      with 5 ex-officio principals by vote_share_norm. The Executive/Home
 *      panel lights up.
 *   2. A DEPARTMENT with a board + a SEATED governor on a 10-year CLK-09
 *      civil-appointment term — chartered through the real engine-filed
 *      F-LEG-016 act, then the real F-EXE-001 nomination → F-LEG-020
 *      consent → seat chain (CLK-09 armed via CivilAppointmentService).
 *      Departments + DepartmentDetail show the two-clock BoardStrip.
 *   3. TWO executive orders filed through the engine F-EXE-005: ONE
 *      well-scoped (status issued, EO-YYYY-NN, on the public record) and
 *      ONE out-of-scope (a civic-process target_domain →
 *      rejected_pre_issuance, also on the public record). The Actions
 *      register + /system/public-records show both.
 *   4. AN ORGANISATION crossing 100 active workers (the CLK-13 chain):
 *      register (service), provision its board, 2 workers through the full
 *      F-IND-014 → F-ORG-001 countersign path + 98 bulk active rows, then
 *      RecomputeWorkerHeadcountJob → worker_seats 0→1, composition_valid
 *      false, a vacant worker_elected seat, an open org_board_worker
 *      election. Registry/OrgDetail/CoDetermination/BoardElections light up.
 *   5. (Bonus) a CGC chartered through the engine-filed F-LEG-019 act
 *      (genesis IP dedication) + one extra public-domain IP asset, so
 *      CgcDetail's register has rows.
 *
 * IDEMPOTENT / RE-RUNNABLE: a second plain run detects the seeded state
 * and reports-and-exits without duplicating. `--fresh` tears down ONLY the
 * demo's own Phase D rows (its departments + boards + seats + terms +
 * CLK-09 timers, the executive_members for the demo exec, its orders, the
 * demo org + CGC and their boards/workers/contracts/elections/IP, the
 * chamber_vote_proposals + votes it filed) and resets the San Marino
 * executive back to `forming`, then reseeds.
 *
 * Demo rows are TAGGED so teardown is exact and never touches Phase A/B/C
 * data: orgs/CGC carry the slug prefix self::DEMO_SLUG_PREFIX, the
 * department carries self::DEMO_DEPT_TAG in its name, the enabling law for
 * the orders carries self::DEMO_LAW_TAG. The audit chain + public_records
 * are append-only (cryptographically chained) and are NEVER deleted — a
 * torn-down + reseeded run simply appends fresh history (audit:verify stays
 * green; old chain rows referencing torn-down ids are harmless).
 *
 * This writes to the LIVE dev DB and does NOT roll back — that is the
 * point. Each step rides its own small transaction so a mid-run failure
 * cannot leave half-state.
 */
class PhaseDDemoCommand extends Command
{
    protected $signature = 'institutions:demo-d
                            {--fresh : tear down prior Phase D demo state and reseed}';

    protected $description = 'Persist the Phase D exit-criterion flows on San Marino so the executive & organizations pages are browsable (Phase D analogue of elections:demo).';

    /** San Marino is the ADM1 jurisdiction (its bicameral chamber is the demo substrate). */
    private const SAN_MARINO_SLUG = 'smr-1-san-marino';

    /** Demo-row tags (the teardown selectors — never collide with real data). */
    private const DEMO_SLUG_PREFIX = 'pd-demo-';

    private const DEMO_DEPT_TAG = '[PhaseD-Demo]';

    private const DEMO_LAW_TAG = 'PD-DEMO-ENABLING';

    private const DEMO_EMAIL_TAG = 'pd-demo';

    /** The delegated committee size (the floor — Art. III §2). */
    private const COMMITTEE_SIZE = 5;

    /** Engine-filing tallies for the report. @var array<string, int> */
    private array $filings = [];

    /** The persisted artifacts for the report. @var array<string, mixed> */
    private array $artifacts = [];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ChamberVoteService $votes,
        private readonly BoardGovernorService $governors,
        private readonly ExecutiveOrderService $orders,
        private readonly OrgRegistryService $orgRegistry,
        private readonly OrgBoardService $orgBoards,
        private readonly CoDeterminationService $coDetermination,
        private readonly CgcIpRegisterService $ipRegister,
        private readonly RoleService $roles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);

        // All queue dispatches in THIS process run inline — the demo must
        // observe each effect synchronously (RecomputeWorkerHeadcountJob).
        // Process-local config; Horizon and other processes are untouched.
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

        $executive = Executive::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->first();

        if ($executive === null) {
            $this->error('San Marino has no executive row — the setup wizard scaffolds one per legislature.');

            return self::FAILURE;
        }

        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->orderBy('seat_no')
            ->get();

        if ($serving->count() <= self::COMMITTEE_SIZE) {
            $this->error(sprintf(
                'San Marino chamber has %d serving members — need more than %d so the committee floor seats. '
                .'Run elections:demo on San Marino first.',
                $serving->count(),
                self::COMMITTEE_SIZE,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'institutions:demo-d — %s (%s) · executive %s · %d serving members%s',
            $jurisdiction->name,
            $jurisdiction->slug,
            substr((string) $executive->id, 0, 8),
            $serving->count(),
            $this->option('fresh') ? ' · --fresh' : '',
        ));

        try {
            if ($this->option('fresh')) {
                $this->step('0. teardown prior demo state (--fresh)', fn () => $this->teardown($jurisdiction, $executive));
                $executive->refresh();
            }

            // Idempotency: if already delegated WITHOUT --fresh, the demo
            // state stands — report and exit gracefully (no duplication).
            if (! $this->option('fresh') && $executive->status === Executive::STATUS_DELEGATED) {
                $this->warn('San Marino executive is already delegated — Phase D demo state is in place.');
                $this->line('Re-run with --fresh to tear down and reseed a clean Phase D slice.');
                $this->reportExisting($jurisdiction, $executive);

                return self::SUCCESS;
            }

            // ── 1. delegate the executive committee ───────────────────────
            $this->step('1. delegate executive (F-LEG-014)', fn () => $this->delegateExecutive($legislature, $executive, $serving));
            $executive->refresh();

            // ── 2. charter a department + seat a governor (CLK-09) ─────────
            $this->step('2. charter department + seat governor', fn () => $this->charterDepartmentWithGovernor($jurisdiction, $legislature, $executive, $serving));

            // ── 3. issue + reject executive orders (F-EXE-005) ─────────────
            $this->step('3. executive orders (F-EXE-005)', fn () => $this->issueExecutiveOrders($jurisdiction, $legislature, $executive));

            // ── 4. organisation crosses 100 workers (CLK-13) ──────────────
            $this->step('4. organisation co-determination (CLK-13)', fn () => $this->seedWorkerCoDetermination($jurisdiction));

            // ── 5. CGC + public-domain IP ──────────────────────────────────
            $this->step('5. CGC charter + public-domain IP (F-LEG-019)', fn () => $this->charterCgc($legislature, $serving));

            $this->report($jurisdiction, $executive, $startedAt);
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
    // 1 — delegation (engine-filed F-LEG-014, lifted from
    //     ExecDelegationProportionalityTest::test_engine_filed_delegation_…)
    // =========================================================================

    private function delegateExecutive(Legislature $legislature, Executive $executive, $serving): void
    {
        if ($executive->status !== Executive::STATUS_FORMING) {
            throw new RuntimeException(
                "Executive {$executive->id} is not forming (status: {$executive->status}) — run with --fresh to reset it."
            );
        }

        $speakerId = $legislature->speaker_id !== null ? (string) $legislature->speaker_id : null;

        // A non-Speaker serving member files the act (R-09) — the Speaker
        // cannot cast on the yes/no business they would open.
        $proposer = $serving->first(fn (LegislatureMember $m) => (string) $m->id !== $speakerId);
        $proposerUser = User::query()->findOrFail($proposer->user_id);

        // File F-LEG-014 through THE ENGINE: authorize (R-09) → validate →
        // ExecutiveActService::proposeDelegation persists the exec_delegation
        // proposal + opens the exec_delegate SUPERMAJORITY vote.
        $result = $this->engine->file('F-LEG-014', $proposerUser, [
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'delegated_scope' => 'Phase D demo: delegated executive committee for San Marino (water, transit, public works).',
            'member_count' => self::COMMITTEE_SIZE,
            'interest' => [],
        ]);
        $this->bump('F-LEG-014');

        $vote = ChamberVote::query()->findOrFail($result->recorded['vote_id']);

        // Every non-Speaker serving member casts yes — clears the
        // supermajority; the engine auto-closes at full participation and the
        // votable-effect dispatch runs applyDelegation in the same txn.
        foreach ($serving as $member) {
            if ((string) $member->id === $speakerId) {
                continue;
            }
            $this->votes->cast($vote->fresh(), $member, 'yes');
        }

        $vote->refresh();
        $executive->refresh();

        if ($executive->status !== Executive::STATUS_DELEGATED) {
            throw new RuntimeException(
                "Delegation vote closed {$vote->outcome} but executive is {$executive->status} (expected delegated)."
            );
        }

        $principals = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->count();

        if ($principals < self::COMMITTEE_SIZE) {
            throw new RuntimeException("Delegation seated only {$principals} principals (expected ".self::COMMITTEE_SIZE.').');
        }

        $this->artifacts['executive_id'] = (string) $executive->id;
        $this->artifacts['delegation_principals'] = $principals;
        $this->line("  executive {$executive->id} → delegated · {$principals} ex-officio principals seated by vote_share_norm");
    }

    // =========================================================================
    // 2 — department + seated governor on a 10-yr CLK-09 term
    //     (engine-filed F-LEG-016, then the real F-EXE-001 → F-LEG-020 →
    //      seat chain — GovernorRemovalOrdinaryMajorityTest fixture posture).
    // =========================================================================

    private function charterDepartmentWithGovernor(Jurisdiction $jurisdiction, Legislature $legislature, Executive $executive, $serving): void
    {
        $speakerId = $legislature->speaker_id !== null ? (string) $legislature->speaker_id : null;
        $proposer = $serving->first(fn (LegislatureMember $m) => (string) $m->id !== $speakerId);
        $proposerUser = User::query()->findOrFail($proposer->user_id);

        // Charter the department through the engine (F-LEG-016) — an
        // ORDINARY-MAJORITY procedural_motion proposal; adoption creates the
        // charter law + department + unified board + vacant governor seats.
        $result = $this->engine->file('F-LEG-016', $proposerUser, [
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => (string) $jurisdiction->id,
            'executive_id' => (string) $executive->id,
            'kind' => Department::KIND_TREASURY,
            'name' => 'Treasury '.self::DEMO_DEPT_TAG,
            'charter' => [
                'function_text' => 'Stewards San Marino public finances: budgets, revenue, and the public accounts (Phase D demo department).',
                'powers_text' => 'Issues treasury rules under enabling law; reports to the chamber on the charter cadence.',
                'reporting_interval_months' => 6,
            ],
            'owner_seats' => 3,
            'nominees' => [],
        ]);
        $this->bump('F-LEG-016');

        $proposal = ChamberVoteProposal::query()->findOrFail($result->recorded['proposal_id']);
        $vote = ChamberVote::query()->findOrFail($result->recorded['vote_id']);

        // Drive the ordinary-majority vote to adoption (all non-Speaker
        // members cast yes — auto-close → DepartmentService::createFromProposal).
        foreach ($serving as $member) {
            if ((string) $member->id === $speakerId) {
                continue;
            }
            $this->votes->cast($vote->fresh(), $member, 'yes');
        }

        $proposal->refresh();

        if ($proposal->status !== ChamberVoteProposal::STATUS_ADOPTED || $proposal->result_type !== 'departments') {
            throw new RuntimeException("Department creation proposal resolved {$proposal->status} ({$proposal->result_type}).");
        }

        $department = Department::query()->findOrFail($proposal->result_id);

        // ── Nominate + consent + seat a governor (the real F-EXE-001 →
        // F-LEG-020 chain). The nominator is a seated principal of the
        // overseeing executive; the nominee is a San Marino resident.
        $nominator = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->where('role', ExecutiveMember::ROLE_PRINCIPAL)
            ->firstOrFail();

        $nomineeUserId = $this->residentNominee((string) $jurisdiction->id, $department, $serving);

        $nomination = $this->governors->nominate(
            $department->refresh(),
            $nominator,
            $nomineeUserId,
            'Phase D demo: a qualified San Marino resident nominated to steward the Treasury board (F-EXE-001).',
        );
        $this->countFiling('F-EXE-001 (service)');

        // F-LEG-020 IS the consent vote (bog_consent — ordinary majority of
        // ALL serving). Drive it to adoption → BoardGovernorService::seat()
        // opens the 10-year civil term + arms CLK-09.
        $consentVote = ChamberVote::query()->findOrFail($nomination['consent_vote_id']);

        foreach ($serving as $member) {
            if ((string) $member->id === $speakerId) {
                continue;
            }
            $this->votes->cast($consentVote->fresh(), $member, 'yes');
        }

        $consentVote->refresh();

        $governorSeat = BoardSeat::query()->findOrFail($nomination['seat_id'])->refresh();

        if ($governorSeat->status !== BoardSeat::STATUS_SEATED || $governorSeat->term_id === null) {
            throw new RuntimeException(
                "Governor consent vote closed {$consentVote->outcome} but the seat is {$governorSeat->status} (term ".($governorSeat->term_id ?? 'null').')'
            );
        }

        // Verify the 10-year civil term + the armed CLK-09 lockstep timer
        // (the two-clock BoardStrip the DepartmentDetail page draws).
        $term = Term::query()->findOrFail($governorSeat->term_id);
        $clk09 = ClockTimer::query()
            ->where('clock_id', 'CLK-09')
            ->where('subject_type', 'term')
            ->where('subject_id', (string) $term->id)
            ->where('state', ClockTimer::STATE_ARMED)
            ->first();

        if ($clk09 === null) {
            throw new RuntimeException("No armed CLK-09 timer for governor term {$term->id} — the lockstep clock did not arm.");
        }

        $this->artifacts['department_id'] = (string) $department->id;
        $this->artifacts['governor_user_id'] = $nomineeUserId;
        $this->artifacts['governor_seat_id'] = (string) $governorSeat->id;
        $this->artifacts['governor_term_id'] = (string) $term->id;
        $this->artifacts['governor_clk09_id'] = (string) $clk09->id;

        $this->line(sprintf(
            '  department %s (%s) chartered · governor %s seated · 10-yr term ends %s · CLK-09 armed',
            substr((string) $department->id, 0, 8),
            $department->name,
            substr($nomineeUserId, 0, 8),
            (string) $term->ends_on,
        ));
    }

    // =========================================================================
    // 3 — executive orders (engine-filed F-EXE-005, lifted from
    //     OrderScopeValidationTest): one well-scoped issue + one rejected.
    // =========================================================================

    private function issueExecutiveOrders(Jurisdiction $jurisdiction, Legislature $legislature, Executive $executive): void
    {
        // A seated PRINCIPAL of the executive is the R-14 actor the engine
        // authorizes the filing through.
        $member = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->where('role', ExecutiveMember::ROLE_PRINCIPAL)
            ->firstOrFail();
        $principalUser = User::query()->findOrFail($member->user_id);

        // The role gate reads the request-cached RoleService — flush so the
        // freshly-seated principal derives R-14 cleanly.
        $this->roles->flush();

        // An in-force enabling law in the executive's jurisdiction (the
        // F-EXE-005 enabling instrument). Persisted directly — this is a
        // demo fixture law, not a legislative-flow artifact (a full bill→law
        // enactment is out of scope for the order seed; tagged for teardown).
        $law = $this->demoEnablingLaw($jurisdiction, $legislature);

        $department = Department::query()->findOrFail($this->artifacts['department_id']);

        $base = [
            'executive_id' => (string) $executive->id,
            'issued_by_member_id' => (string) $member->id,
            'enabling_type' => ExecutiveOrder::ENABLING_LAW,
            'enabling_id' => (string) $law->id,
            'jurisdiction_id' => (string) $jurisdiction->id,
        ];

        // ── WELL-SCOPED order — issues cleanly (EO-YYYY-NN, status issued).
        $issued = $this->engine->file('F-EXE-005', $principalUser, $base + [
            'title' => 'Treasury efficiency directive '.self::DEMO_DEPT_TAG,
            'body' => 'Directs the Treasury board to publish a quarterly public-accounts dashboard under the enabling act.',
            'target_domain' => ExecutiveOrder::DOMAIN_DEPARTMENT_OPERATIONS,
            'department_id' => (string) $department->id,
        ]);
        $this->bump('F-EXE-005');

        $issuedOrder = ExecutiveOrder::query()->findOrFail($issued->recorded['order_id']);

        if ($issuedOrder->status !== ExecutiveOrder::STATUS_ISSUED || $issuedOrder->order_no === null) {
            throw new RuntimeException("Well-scoped order did not issue (status: {$issuedOrder->status}, no: ".($issuedOrder->order_no ?? 'null').').');
        }

        // ── OUT-OF-SCOPE order — a civic-process target_domain (Art. II §7).
        // It is rejected pre-issuance; the engine seals the rejected=true
        // chain row + the rejected_pre_issuance order + its public record.
        $rejectedOrderId = null;
        try {
            $this->engine->file('F-EXE-005', $principalUser, $base + [
                'title' => 'Attempt to reschedule the election '.self::DEMO_DEPT_TAG,
                'body' => 'An out-of-scope order that reaches into the electoral process — must be rejected (Art. II §7).',
                'target_domain' => ExecutiveOrder::DOMAIN_ELECTORAL_PROCESS,
                'department_id' => (string) $department->id,
            ]);
            throw new RuntimeException('A civic-process executive order was NOT rejected — the hardened shield failed.');
        } catch (ConstitutionalViolation $violation) {
            // Expected: Art. II §7. The artifacts persist (rejection-on-record).
            $rejected = ExecutiveOrder::query()
                ->where('executive_id', $executive->id)
                ->where('target_domain', ExecutiveOrder::DOMAIN_ELECTORAL_PROCESS)
                ->where('status', ExecutiveOrder::STATUS_REJECTED_PRE_ISSUANCE)
                ->latest('created_at')
                ->first();

            if ($rejected === null) {
                throw new RuntimeException('The civic-process order was rejected but no rejected_pre_issuance row persisted.');
            }
            $this->bump('F-EXE-005');
            $rejectedOrderId = (string) $rejected->id;
        }

        $this->artifacts['order_issued_id'] = (string) $issuedOrder->id;
        $this->artifacts['order_issued_no'] = (string) $issuedOrder->order_no;
        $this->artifacts['order_rejected_id'] = $rejectedOrderId;

        $this->line(sprintf(
            '  issued %s (%s) on the public record · rejected order %s (rejected_pre_issuance, Art. II §7)',
            $issuedOrder->order_no,
            substr((string) $issuedOrder->id, 0, 8),
            substr((string) $rejectedOrderId, 0, 8),
        ));
    }

    // =========================================================================
    // 4 — worker co-determination (CLK-13 chain, lifted from
    //     WorkerRepresentationTest::test_the_100th_worker_…).
    // =========================================================================

    private function seedWorkerCoDetermination(Jurisdiction $jurisdiction): void
    {
        // Register the org (the F-IND-012 service path — registration IS
        // activation). Its agent is a fresh demo user.
        $agent = $this->demoUser('org-agent');

        $registered = $this->orgRegistry->register($agent, [
            'type' => Organization::TYPE_BUSINESS,
            'structure' => Organization::STRUCTURE_STOCK,
            'name' => 'Bluefin Logistics '.self::DEMO_DEPT_TAG,
            'jurisdiction_id' => (string) $jurisdiction->id,
            'purpose' => 'A worker co-operative logistics firm (Phase D demo — crosses the 100-worker co-determination threshold).',
        ]);
        $this->countFiling('F-IND-012 (service)');

        $org = Organization::query()->findOrFail($registered['organization_id']);

        // Force the demo slug prefix so teardown finds it exactly (the
        // registry slug is name-derived; override to the tagged form).
        $org->forceFill(['slug' => self::DEMO_SLUG_PREFIX.'bluefin-'.Str::lower(Str::random(8))])->save();

        // Provision a 5-owner-seat board, 2 seated (the formula needs an
        // owner side; validity tracks the worker side).
        $board = $this->orgBoards->provision($org->refresh(), ownerSeats: 5);

        $no = 0;
        foreach ($board->seats()->orderBy('seat_no')->get() as $seat) {
            if ($no >= 2) {
                break;
            }
            $owner = $this->demoUser("org-owner-{$no}");
            $seat->forceFill(['holder_user_id' => (string) $owner->getKey(), 'status' => BoardSeat::STATUS_SEATED])->save();
            $no++;
        }

        // 2 workers through the FULL F-IND-014 → F-ORG-001 countersign path
        // (the form path is the constitutional pin).
        for ($i = 0; $i < 2; $i++) {
            $worker = $this->demoUser("worker-form-{$i}");

            $filed = $this->engine->file('F-IND-014', $worker, [
                'employer_type' => OrgWorker::EMPLOYER_ORGANIZATIONS,
                'employer_id' => (string) $org->id,
            ]);
            $this->bump('F-IND-014');

            $this->engine->file('F-ORG-001', $agent, [
                'organization_id' => (string) $org->id,
                'action' => 'countersign_contract',
                'contract_id' => $filed->recorded['contract_id'],
            ]);
            $this->bump('F-ORG-001');
        }

        // 98 more as direct active rows (the headcount source is the active
        // row count — owner ruling #12), crossing 100 total.
        $this->bulkActiveWorkers((string) $org->id, 98);

        // The QUEUED recompute, run inline — the CLK-13 fire chain, the
        // worker_seats snapshot, the worker_elected seat, and the
        // system-filed F-ORG-004 worker election.
        (new RecomputeWorkerHeadcountJob(OrgWorker::EMPLOYER_ORGANIZATIONS, (string) $org->id))
            ->handle($this->coDetermination);

        $board->refresh();

        $headcount = (int) $board->worker_headcount;
        $workerSeats = (int) $board->worker_seats;

        if ($headcount < 100 || $workerSeats < 1 || (bool) $board->composition_valid !== false) {
            throw new RuntimeException(sprintf(
                'Co-determination did not fire: headcount=%d worker_seats=%d composition_valid=%s (expected ≥100 / ≥1 / false).',
                $headcount,
                $workerSeats,
                $board->composition_valid ? 'true' : 'false',
            ));
        }

        $election = Election::query()
            ->where('board_id', $board->id)
            ->where('kind', Election::KIND_ORG_BOARD_WORKER)
            ->first();

        if ($election === null || $election->status !== Election::STATUS_APPROVAL_OPEN) {
            throw new RuntimeException('No open org_board_worker election after crossing 100 workers.');
        }

        $vacantWorkerSeats = BoardSeat::query()
            ->where('board_id', $board->id)
            ->where('seat_class', BoardSeat::CLASS_WORKER_ELECTED)
            ->where('status', BoardSeat::STATUS_VACANT)
            ->count();

        $this->artifacts['org_id'] = (string) $org->id;
        $this->artifacts['org_board_id'] = (string) $board->id;
        $this->artifacts['org_worker_headcount'] = $headcount;
        $this->artifacts['org_worker_seats'] = $workerSeats;
        $this->artifacts['org_vacant_worker_seats'] = $vacantWorkerSeats;
        $this->artifacts['org_election_id'] = (string) $election->id;

        $this->line(sprintf(
            '  org %s · %d active workers → worker_seats %d · composition_valid false · %d vacant worker_elected · election %s (org_board_worker, approval_open)',
            substr((string) $org->id, 0, 8),
            $headcount,
            $workerSeats,
            $vacantWorkerSeats,
            substr((string) $election->id, 0, 8),
        ));
    }

    // =========================================================================
    // 5 — CGC + public-domain IP (engine-filed F-LEG-019).
    // =========================================================================

    private function charterCgc(Legislature $legislature, $serving): void
    {
        $speakerId = $legislature->speaker_id !== null ? (string) $legislature->speaker_id : null;
        $proposer = $serving->first(fn (LegislatureMember $m) => (string) $m->id !== $speakerId);
        $proposerUser = User::query()->findOrFail($proposer->user_id);

        // F-LEG-019 — CGC creation act (ordinary-majority procedural_motion).
        // Adoption charters the CGC, the jurisdiction's 100% stake, the
        // governor board, and the GENESIS public-domain IP dedication.
        $result = $this->engine->file('F-LEG-019', $proposerUser, [
            'legislature_id' => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'name' => 'San Marino Open Data Authority '.self::DEMO_DEPT_TAG,
            'charter' => 'A Common Good Corporation providing open civic data and software; all IP is public domain (Art. III §5).',
            'goods_services' => 'Open civic data platforms and tooling.',
            'owner_seats' => 3,
        ]);
        $this->bump('F-LEG-019');

        $proposal = ChamberVoteProposal::query()->findOrFail($result->recorded['proposal_id']);
        $vote = ChamberVote::query()->findOrFail($result->recorded['vote_id']);

        foreach ($serving as $member) {
            if ((string) $member->id === $speakerId) {
                continue;
            }
            $this->votes->cast($vote->fresh(), $member, 'yes');
        }

        $proposal->refresh();

        if ($proposal->status !== ChamberVoteProposal::STATUS_ADOPTED || $proposal->result_type !== 'organizations') {
            throw new RuntimeException("CGC creation proposal resolved {$proposal->status} ({$proposal->result_type}).");
        }

        $cgc = Organization::query()->findOrFail($proposal->result_id);

        // Tag the slug for teardown (CgcService derives it from the name).
        $cgc->forceFill(['slug' => self::DEMO_SLUG_PREFIX.'opendata-'.Str::lower(Str::random(8))])->save();

        // One extra dedicated public-domain IP asset (beyond the genesis
        // dedication) so the CgcDetail register shows a concrete row.
        $this->ipRegister->dedicate(
            $cgc->refresh(),
            'San Marino Open Census Dataset 2026',
            'data',
            'The full anonymised civic census dataset, released to the public domain (Phase D demo dedication).',
            'F-ORG-001',
        );
        $this->countFiling('IP dedication (service)');

        // Count the public-domain IP rows through the Organization relation
        // (ipRegisterEntries) — never a static reference to the register
        // model/table, which the Art. III §5 source-scan pin reserves for
        // the model + dedicate service (CgcIpPublicDomainTest).
        $ipRows = $cgc->ipRegisterEntries()->count();

        $this->artifacts['cgc_id'] = (string) $cgc->id;
        $this->artifacts['cgc_ip_rows'] = $ipRows;

        $this->line(sprintf(
            '  CGC %s (%s) chartered · %d public-domain IP register row(s)',
            substr((string) $cgc->id, 0, 8),
            $cgc->name,
            $ipRows,
        ));
    }

    // =========================================================================
    // --fresh teardown (exact: only this demo's tagged Phase D rows)
    // =========================================================================

    private function teardown(Jurisdiction $jurisdiction, Executive $executive): void
    {
        DB::transaction(function () use ($jurisdiction, $executive) {
            // ── Demo orgs + CGC (slug-tagged) and everything hanging off them.
            $demoOrgs = Organization::withTrashed()
                ->where('jurisdiction_id', $jurisdiction->id)
                ->where('slug', 'like', self::DEMO_SLUG_PREFIX.'%')
                ->get();

            foreach ($demoOrgs as $org) {
                $orgId = (string) $org->id;

                $this->teardownBoardableBoards(Board::BOARDABLE_ORGANIZATIONS, $orgId, softOnly: (bool) $org->is_cgc);

                OrgWorker::withTrashed()->forEmployer(OrgWorker::EMPLOYER_ORGANIZATIONS, $orgId)->forceDelete();
                OrgContract::withTrashed()->where('organization_id', $orgId)->forceDelete();

                if ($org->is_cgc) {
                    // Art. III §5: cgc_ip_register is append-only +
                    // irreversible (a DB trigger blocks DELETE; the FK from
                    // it to organizations is RESTRICT). The genesis + demo IP
                    // dedications legitimately persist forever — so a demo CGC
                    // is SOFT-deleted (hidden from every page, which filters
                    // deleted_at) rather than force-deleted. Its ownership
                    // stake is closed; the IP rows stand by constitutional
                    // design. NOTE: this is the one demo artifact --fresh
                    // cannot fully erase — the immutable register is the
                    // point of the pin.
                    DB::table('org_ownership_stakes')->where('organization_id', $orgId)->delete();
                    Organization::withTrashed()->whereKey($orgId)->restore();
                    $org->delete();
                } else {
                    DB::table('org_ownership_stakes')->where('organization_id', $orgId)->delete();
                    Organization::withTrashed()->whereKey($orgId)->forceDelete();
                }
            }

            // ── The demo executive's orders FIRST (they FK to the demo
            // department via department_id), then the delegated members.
            ExecutiveOrder::withTrashed()->where('executive_id', $executive->id)->forceDelete();
            ExecutiveMember::withTrashed()->where('executive_id', $executive->id)->forceDelete();

            // ── Demo departments (name-tagged) of THIS executive + their
            // boards + every table that FKs to a department.
            $demoDeptIds = Department::withTrashed()
                ->where('executive_id', $executive->id)
                ->where('name', 'like', '%'.self::DEMO_DEPT_TAG.'%')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            foreach ($demoDeptIds as $deptId) {
                // Department child rows FIRST (some FK board_seats via
                // filed_by_seat_id): reports (the charter cadence seeds one),
                // rules, investigations, policy proposals — all FK
                // departments.id. Then the board (+ seats/term/CLK-09), then
                // the department row itself.
                foreach (['department_reports', 'department_rules', 'executive_investigations', 'policy_proposals'] as $childTable) {
                    DB::table($childTable)->where('department_id', $deptId)->delete();
                }

                $this->teardownBoardableBoards(Board::BOARDABLE_DEPARTMENTS, $deptId);

                Department::withTrashed()->whereKey($deptId)->forceDelete();
            }

            // ── The chamber votes + proposals the demo filed (the Phase D
            // proposal kinds, for THIS legislature).
            $this->teardownDemoVotes($jurisdiction);

            // ── Reset the executive back to forming (the pre-demo footing)
            // — this NULLs delegation_law_id BEFORE teardownDemoLaws so the
            // RESTRICT FK from executives → laws no longer blocks the
            // delegation creation-act delete.
            $executive->forceFill([
                'status' => Executive::STATUS_FORMING,
                'type' => Executive::TYPE_COMMITTEE,
                'delegation_law_id' => null,
                'delegated_scope' => null,
                'delegated_member_count' => null,
                'source_legislature_id' => null,
                'conversion_law_id' => null,
                'conversion_process_id' => null,
            ])->save();

            // ── Demo enabling laws (order instrument + charter/creation acts
            // the demo enacted carry recognizable tags) — LAST, once nothing
            // references them (departments + executive law-pointers cleared).
            $this->teardownDemoLaws($jurisdiction);

            $this->roles->flush();
        });

        $this->line('  demo Phase D rows removed; San Marino executive reset to forming');
    }

    /**
     * Tear down the board (+ seats, terms, CLK-09 timers, worker elections)
     * for one boardable. When $softOnly (a CGC, whose IP register pins its
     * org row in place — see teardown()), the board + seats are SOFT-deleted
     * so the immutable register's FK target survives; everything else still
     * hard-deletes.
     */
    private function teardownBoardableBoards(string $boardableType, string $boardableId, bool $softOnly = false): void
    {
        $boards = Board::withTrashed()
            ->where('boardable_type', $boardableType)
            ->where('boardable_id', $boardableId)
            ->get();

        foreach ($boards as $board) {
            $seats = BoardSeat::withTrashed()->where('board_id', $board->id)->get();

            foreach ($seats as $seat) {
                if ($seat->term_id !== null) {
                    // Cancel the lockstep timers, then drop the demo term.
                    DB::table('clock_timers')
                        ->where('subject_type', 'term')
                        ->where('subject_id', (string) $seat->term_id)
                        ->delete();
                    Term::withTrashed()->whereKey($seat->term_id)->forceDelete();
                }

                if ($seat->appointment_id !== null) {
                    Appointment::withTrashed()->whereKey($seat->appointment_id)->forceDelete();
                }
            }

            // Worker-track elections + their races for this board.
            $electionIds = Election::withTrashed()->where('board_id', $board->id)->pluck('id')->all();
            if ($electionIds !== []) {
                DB::table('election_races')->whereIn('election_id', $electionIds)->delete();
                Election::withTrashed()->whereIn('id', $electionIds)->forceDelete();
            }

            // The board's co-determination CLK-13/14 watchers.
            DB::table('clock_timers')
                ->whereIn('clock_id', ['CLK-13', 'CLK-14'])
                ->where('subject_type', $boardableType)
                ->where('subject_id', $boardableId)
                ->delete();

            if ($softOnly) {
                BoardSeat::withTrashed()->where('board_id', $board->id)->restore();
                BoardSeat::query()->where('board_id', $board->id)->delete();
                Board::withTrashed()->whereKey($board->id)->restore();
                $board->delete();
            } else {
                BoardSeat::withTrashed()->where('board_id', $board->id)->forceDelete();
                Board::withTrashed()->whereKey($board->id)->forceDelete();
            }
        }
    }

    /**
     * Remove the chamber votes + proposals the demo opened on San Marino's
     * chamber — the Phase D proposal kinds, plus the bog_consent and
     * exec_delegate votes (and their casts/tallies).
     */
    private function teardownDemoVotes(Jurisdiction $jurisdiction): void
    {
        $legislatureIds = Legislature::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($legislatureIds === []) {
            return;
        }

        $phaseDKinds = [
            ChamberVoteProposal::KIND_EXEC_DELEGATION,
            ChamberVoteProposal::KIND_EXEC_CONVERSION,
            ChamberVoteProposal::KIND_DEPARTMENT_CREATION,
            ChamberVoteProposal::KIND_CGC_CREATION,
        ];

        // ChamberVoteProposal / ChamberVote are hard-delete models (no
        // SoftDeletes) — plain queries, plain deletes.
        $proposals = ChamberVoteProposal::query()
            ->whereIn('legislature_id', $legislatureIds)
            ->whereIn('proposal_kind', $phaseDKinds)
            ->get();

        // The vote ids: the Phase D proposal votes + the bog_consent /
        // exec_delegate / exec_office_create votes on this chamber.
        $voteIds = $proposals->pluck('vote_id')->filter()->map(fn ($id) => (string) $id)->all();

        $voteIds = array_values(array_unique(array_merge(
            $voteIds,
            ChamberVote::query()
                ->whereIn('body_id', $legislatureIds)
                ->whereIn('vote_type', ['bog_consent', 'exec_delegate', 'exec_office_create'])
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all(),
        )));

        if ($voteIds !== []) {
            DB::table('vote_casts')->whereIn('vote_id', $voteIds)->delete();
            DB::table('chamber_vote_tallies')->whereIn('vote_id', $voteIds)->delete();
        }

        // Drop the demo appointments riding bog_consent votes (governor
        // nominations) before the votes themselves.
        if ($voteIds !== []) {
            $appointmentIds = Appointment::withTrashed()->whereIn('consent_vote_id', $voteIds)->pluck('id')->all();
            if ($appointmentIds !== []) {
                Appointment::withTrashed()->whereIn('id', $appointmentIds)->forceDelete();
            }
        }

        ChamberVoteProposal::query()->whereIn('legislature_id', $legislatureIds)->whereIn('proposal_kind', $phaseDKinds)->delete();

        if ($voteIds !== []) {
            ChamberVote::query()->whereIn('id', $voteIds)->delete();
        }
    }

    /**
     * Fully PURGE the demo enabling/charter/creation laws (tagged in
     * title/act_number) — the order-enabling fixture, anything carrying the
     * demo department/CGC tag, and the F-LEG-014 delegation creation act
     * (whose generic title carries no tag, but San Marino's executive
     * delegation is always demo-created).
     *
     * Force-delete (not soft) so the act-number sequence stays CONTIGUOUS:
     * EnactmentService::allocateActNumber() returns
     * "Act {year}-{count(withTrashed)+1}", so a soft-deleted (still-counted)
     * demo law would leave the count above the live max and the next reseed
     * would re-allocate a number a surviving law already holds → unique
     * violation. Purging leaves only the genuine (non-demo) acts counted, so
     * reseeds never collide. law_versions cascades on the law delete; the
     * RESTRICT FK from a soft-deleted demo CGC's created_by_law_id is
     * released by NULLing it first (the CGC org survives by Art. III §5 — its
     * append-only IP register pins the ROW, not its creation act).
     */
    private function teardownDemoLaws(Jurisdiction $jurisdiction): void
    {
        // Release the RESTRICT FK from any surviving (soft-deleted) demo CGC
        // to its creation act, so the act can be purged.
        Organization::withTrashed()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('slug', 'like', self::DEMO_SLUG_PREFIX.'%')
            ->update(['created_by_law_id' => null]);

        Law::withTrashed()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where(function ($q) {
                $q->where('act_number', 'like', self::DEMO_LAW_TAG.'%')
                    ->orWhere('title', 'like', '%'.self::DEMO_DEPT_TAG.'%')
                    ->orWhere('title', 'Executive Committee Delegation Act');
            })
            ->forceDelete();
    }

    // =========================================================================
    // Fixtures / helpers
    // =========================================================================

    /**
     * A San Marino resident eligible to be a governor nominee (active
     * residency association — the ONLY eligibility check, Art. I). Prefer a
     * confirmed resident who is NOT a seated chamber member (cleaner role
     * separation); fall back to minting one with a confirmation row.
     */
    private function residentNominee(string $jurisdictionId, Department $department, $serving): string
    {
        $servingUserIds = $serving->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        $candidate = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->whereNotIn('user_id', $servingUserIds)
            ->value('user_id');

        if ($candidate !== null) {
            return (string) $candidate;
        }

        // No spare resident — mint one with an active confirmation (the
        // F-EXE-001 association gate reads residency_confirmations directly).
        $user = $this->demoUser('governor');

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

        return (string) $user->getKey();
    }

    /** A demo enabling law (in-force) bound to San Marino — the F-EXE-005 instrument. */
    private function demoEnablingLaw(Jurisdiction $jurisdiction, Legislature $legislature): Law
    {
        $existing = Law::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('act_number', 'like', self::DEMO_LAW_TAG.'%')
            ->where('status', Law::STATUS_IN_FORCE)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Law::create([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => (string) $jurisdiction->id,
            'legislature_id' => (string) $legislature->id,
            'act_number' => self::DEMO_LAW_TAG.'-'.strtoupper(Str::random(6)),
            'title' => 'Public Accounts Transparency Act '.self::DEMO_DEPT_TAG,
            'kind' => Law::KIND_ORDINARY,
            'scale' => ['scope' => 'phase-d-demo'],
            'origin' => Law::ORIGIN_BILL,
            'status' => Law::STATUS_IN_FORCE,
            'current_version_no' => 1,
            'effective_at' => now(),
            'enacted_at' => now(),
        ]);
    }

    private function bulkActiveWorkers(string $orgId, int $count): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'employer_type' => OrgWorker::EMPLOYER_ORGANIZATIONS,
                'employer_id' => $orgId,
                'user_id' => (string) $this->demoUser("worker-bulk-{$i}")->getKey(),
                'status' => OrgWorker::STATUS_ACTIVE,
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('org_workers')->insert($chunk);
        }
    }

    private function demoUser(string $label): User
    {
        return User::create([
            'name' => "Phase D Demo {$label}",
            'email' => self::DEMO_EMAIL_TAG.'.'.$label.'.'.Str::lower(Str::random(8)).'@cga.test',
            'password' => Hash::make('demo'),
            'terms_accepted_at' => now(),
        ]);
    }

    // =========================================================================
    // Reporting
    // =========================================================================

    private function report(Jurisdiction $jurisdiction, Executive $executive, float $startedAt): void
    {
        $executive->refresh();
        $base = rtrim((string) config('app.url'), '/');

        $this->newLine();
        $this->info('════════════════════ PHASE D DEMO COMPLETE ════════════════════');
        $this->line("Executive: {$executive->id} — status {$executive->status} · {$this->artifacts['delegation_principals']} ex-officio principals");
        $this->line("  {$base}/executives/{$executive->id}");
        $this->line("  {$base}/executives/{$executive->id}/departments");
        $this->line("  {$base}/executives/{$executive->id}/actions");

        if (isset($this->artifacts['department_id'])) {
            $this->line(sprintf(
                'Department: %s — governor %s seated (10-yr CLK-09 term %s)',
                $this->artifacts['department_id'],
                $this->artifacts['governor_user_id'],
                $this->artifacts['governor_term_id'],
            ));
            $this->line("  {$base}/departments/{$this->artifacts['department_id']}");
        }

        if (isset($this->artifacts['order_issued_no'])) {
            $this->line(sprintf(
                'Orders: %s issued (%s) · %s rejected_pre_issuance',
                $this->artifacts['order_issued_no'],
                $this->artifacts['order_issued_id'],
                $this->artifacts['order_rejected_id'],
            ));
        }

        if (isset($this->artifacts['org_id'])) {
            $this->line(sprintf(
                'Organisation: %s — %d active workers · worker_seats %d · %d vacant worker_elected · election %s (org_board_worker)',
                $this->artifacts['org_id'],
                $this->artifacts['org_worker_headcount'],
                $this->artifacts['org_worker_seats'],
                $this->artifacts['org_vacant_worker_seats'],
                $this->artifacts['org_election_id'],
            ));
            $this->line("  {$base}/organizations/{$this->artifacts['org_id']}");
            $this->line("  {$base}/organizations/co-determination");
            $this->line("  {$base}/organizations/{$this->artifacts['org_id']}/board-elections");
        }

        if (isset($this->artifacts['cgc_id'])) {
            $this->line(sprintf('CGC: %s — %d public-domain IP register row(s)', $this->artifacts['cgc_id'], $this->artifacts['cgc_ip_rows']));
            $this->line("  {$base}/organizations/{$this->artifacts['cgc_id']}/cgc");
        }

        $this->newLine();
        $this->line('Engine filings: '.collect($this->filings)->map(fn ($n, $f) => "{$f}×{$n}")->implode(' · '));
        $this->line(sprintf('TOTAL %.1fs', microtime(true) - $startedAt));
        $this->newLine();
        $this->call('audit:verify');
    }

    /** Report-and-exit summary for an already-seeded (delegated) instance. */
    private function reportExisting(Jurisdiction $jurisdiction, Executive $executive): void
    {
        $base = rtrim((string) config('app.url'), '/');
        $principals = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->count();

        $depts = Department::query()->where('executive_id', $executive->id)->count();
        $orders = ExecutiveOrder::query()->where('executive_id', $executive->id)->count();

        $this->newLine();
        $this->line("Executive {$executive->id}: delegated · {$principals} seated principals · {$depts} department(s) · {$orders} order(s)");
        $this->line("  {$base}/executives/{$executive->id}");
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
