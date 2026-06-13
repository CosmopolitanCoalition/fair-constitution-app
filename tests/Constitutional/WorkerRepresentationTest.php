<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Election;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\Organizations\CoDeterminationService;
use App\Services\Organizations\OrgBoardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. III §6 (worker co-determination). Replaces
 * the Phase D placeholder `test_worker_representation_thresholds_and_scaling`.
 *
 *  - FIRST worker-elected seat at the resolved minimum (default 100
 *    workers — CLK-13), LINEAR scaling to parity at the resolved parity
 *    threshold (default 2,000 — CLK-14), parity as the CEILING (worker
 *    seats never exceed the owner side).
 *  - The formula takes (workers, ownerSeats) and NOTHING ELSE — it
 *    applies identically to departments, CGCs, and private enterprises;
 *    exactly ONE implementation exists (source-pinned).
 *  - The joint chair is elected by the FULL board (RCV, majority of all
 *    seated); any composition change re-triggers it.
 *  - An INVALID board (required worker seats unfilled) cannot ACT but
 *    can always CURE.
 *  - The 100th active F-IND-014 worker auto-triggers the first worker
 *    seat: CLK-13 fire on the chain, worker_seats 0→1,
 *    composition_valid=false, a vacant worker_elected seat, and an open
 *    org_board_worker election — with ZERO R-23 action (the Phase D
 *    exit criterion).
 *
 * Live-DB sections use the guarded pg connection (BallotSecrecyTest
 * technique): SKIP when pg is unreachable; every write rides one
 * transaction that is ALWAYS rolled back — zero residue.
 *
 * If an edit breaks these tests, that edit is a constitutional
 * violation — fix the edit, never the test.
 */
class WorkerRepresentationTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_worker_representation';

    // ======================================================================
    // 1. Pure formula pins (DB-free, always run)
    // ======================================================================

    public function test_endpoints_are_pinned(): void
    {
        foreach (range(1, 15) as $o) {
            // Below the minimum: zero — always.
            $this->assertSame(0, CoDeterminationService::workerSeats(0, $o));
            $this->assertSame(0, CoDeterminationService::workerSeats(99, $o), "f(99, {$o})");

            // The 100th worker seats the FIRST representative (CLK-13).
            $this->assertSame(1, CoDeterminationService::workerSeats(100, $o), "f(100, {$o})");

            // Parity at 2,000 (CLK-14) — and parity is the CEILING.
            $this->assertSame($o, CoDeterminationService::workerSeats(2000, $o), "f(2000, {$o})");
            $this->assertSame($o, CoDeterminationService::workerSeats(2001, $o));
            $this->assertSame($o, CoDeterminationService::workerSeats(50000, $o), 'never exceeds the owner side');
        }

        // No owner side ⇒ no worker side (the formula needs a board).
        $this->assertSame(0, CoDeterminationService::workerSeats(5000, 0));
    }

    /**
     * The frozen mockup contract VERBATIM
     * (mockups/organizations/co-determination.html + cgc-detail's worked
     * case) — PHP round() half-up, matching the mockup's Math.round.
     */
    public function test_linear_interpolation_matches_the_frozen_mockup_cases(): void
    {
        $this->assertSame(3, CoDeterminationService::workerSeats(740, 9), 'Bluefin: 740 workers, 9 owner seats');
        $this->assertSame(5, CoDeterminationService::workerSeats(1450, 7), 'cgc-detail: round(1350/1900×7) = round(4.97) = 5');
        $this->assertSame(4, CoDeterminationService::workerSeats(1240, 7), 'PW&U: 1,240 workers, 7 governors');

        foreach (range(1, 15) as $o) {
            $this->assertSame(1, CoDeterminationService::workerSeats(152, $o), "Treasury: 152 workers → 1 seat (o={$o})");
        }

        // Half-up at the exact .5 boundaries — PHP and JS can never
        // diverge: (575−100)/1900×2 = 0.5 → 1; (1525−100)/1900×2 = 1.5 → 2.
        $this->assertSame(1, CoDeterminationService::workerSeats(575, 2));
        $this->assertSame(2, CoDeterminationService::workerSeats(1525, 2));
    }

    public function test_monotone_and_bounded_over_the_full_sweep(): void
    {
        foreach (range(1, 15) as $o) {
            $previous = 0;

            for ($w = 0; $w <= 2200; $w++) {
                $seats = CoDeterminationService::workerSeats($w, $o);

                $this->assertGreaterThanOrEqual(0, $seats);
                $this->assertLessThanOrEqual($o, $seats, "f({$w}, {$o}) exceeds the owner side");
                $this->assertGreaterThanOrEqual($previous, $seats, "f not monotone at w={$w}, o={$o}");

                // f(w) ≥ 1 ⇔ w ≥ min — the first-seat biconditional.
                $this->assertSame($w >= 100, $seats >= 1, "first-seat biconditional at w={$w}, o={$o}");

                $previous = $seats;
            }
        }
    }

    public function test_formula_honors_amendable_thresholds_and_next_step_projection(): void
    {
        // Amended thresholds flow through the (min, par) arguments —
        // resolved per jurisdiction at EVALUATION time, never frozen.
        $this->assertSame(0, CoDeterminationService::workerSeats(49, 9, 50, 1000));
        $this->assertSame(1, CoDeterminationService::workerSeats(50, 9, 50, 1000));
        $this->assertSame(9, CoDeterminationService::workerSeats(1000, 9, 50, 1000));

        // nextStep: the smallest headcount where entitlement first
        // exceeds the current seats; parity-capped; null at parity.
        $this->assertSame(100, CoDeterminationService::nextStep(0, 9));
        $this->assertNull(CoDeterminationService::nextStep(9, 9));

        foreach ([1, 2, 5, 8] as $seats) {
            $step = CoDeterminationService::nextStep($seats, 9);
            $this->assertNotNull($step);
            $this->assertSame($seats, CoDeterminationService::workerSeats($step - 1, 9), "f(step−1) still {$seats}");
            $this->assertSame($seats + 1, CoDeterminationService::workerSeats($step, 9), 'f(step) crosses to '.($seats + 1));
        }
    }

    public function test_cross_field_rule_rejects_min_at_or_above_parity(): void
    {
        // The Art. III §6 ordering guard (validator rule, F-LEG-031 path).
        ConstitutionalValidator::assertCodeterminationOrdering(100, 2000); // legal

        try {
            ConstitutionalValidator::assertCodeterminationOrdering(2000, 2000);
            $this->fail('min == parity must be rejected.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. III §6', $e->citation);
        }

        $this->expectException(ConstitutionalViolation::class);
        (new ConstitutionalValidator)->checkSettingChange([
            'setting_key' => 'worker_rep_min_employees',
            'value' => 90,
            'worker_rep_parity_employees' => 80,
        ]);
    }

    /**
     * SINGLE-SOURCE PIN (architecture): exactly one implementation of the
     * Art. III §6 interpolation exists — every body kind (departments,
     * CGCs, private orgs) resolves seat counts through
     * CoDeterminationService::workerSeats. (The TermLockstepTest no-API
     * technique: a second implementation is a constitutional fork.)
     */
    public function test_the_formula_has_exactly_one_implementation(): void
    {
        $allowed = str_replace('\\', '/', app_path('Services/Organizations/CoDeterminationService.php'));

        $hits = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $contents = file_get_contents($path);

            // The interpolation's signature expression and any rogue
            // definition of the function name.
            if (preg_match('/function\s+workerSeats\s*\(/', $contents)
                || preg_match('/\(\s*\$workers\s*-\s*\$min\s*\)\s*\/\s*\(\s*\$par(ity)?\s*-\s*\$min\s*\)/', $contents)) {
                $hits[] = $path;
            }
        }

        $this->assertSame([$allowed], $hits, 'Exactly ONE Art. III §6 formula implementation may exist.');

        // And a NON-ZERO boards.worker_seats VALUE is written nowhere
        // outside the engine: board stand-up initializes the column to 0;
        // only CoDeterminationService writes the entitlement snapshot. The
        // lookahead allows the zero-init (`=> 0`) and the Board model's
        // integer CAST declaration (`=> 'integer'` — a type tag, not a value
        // write); every real assignment (`=> 1`, `=> $seats`, …) still trips.
        // `\s*+` is POSSESSIVE so the post-`=>` whitespace cannot give back
        // characters and let the lookahead evaluate against a space (which
        // would defeat both exclusions).
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if ($path === $allowed) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                "/'worker_seats'\s*=>\s*+(?!0\s*[,\)\]]|'(?:integer|int)')/",
                file_get_contents($path),
                "{$path} writes a non-zero boards.worker_seats — only CoDeterminationService may."
            );
        }
    }

    // ======================================================================
    // 2. Live pins (guarded pg; one transaction, ALWAYS rolled back)
    // ======================================================================

    /**
     * THE Phase D exit criterion (backend chain): an org crossing 100
     * ACTIVE F-IND-014 workers auto-triggers its first worker seat with
     * ZERO R-23 action — CLK-13 fires on the chain, worker_seats 0→1,
     * composition_valid flips false, a vacant worker_elected seat stands,
     * and the worker-track election (kind org_board_worker, electorate
     * workers) is open.
     */
    public function test_the_100th_worker_auto_triggers_the_first_seat_clk13_chain(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            [$org, $agent] = $this->throwawayOrg($conn);

            // A provisioned board: 5 owner seats, 2 seated (the formula
            // needs an owner side; validity tracks the worker side).
            $board = $this->throwawayBoard($org, ownerSeats: 5, seatHolders: 2);

            // 99 active workers: two through the FULL F-IND-014 →
            // F-ORG-001 countersign path (the form path is the pin), the
            // rest as direct active rows (the headcount source is the
            // row count — owner ruling #12).
            $engine = app(ConstitutionalEngine::class);

            for ($i = 0; $i < 2; $i++) {
                $worker = $this->throwawayUser("worker-{$i}");

                $filed = $engine->file('F-IND-014', $worker, [
                    'employer_type' => 'organizations',
                    'employer_id' => (string) $org->id,
                ]);

                $engine->file('F-ORG-001', $agent, [
                    'organization_id' => (string) $org->id,
                    'action' => 'countersign_contract',
                    'contract_id' => $filed->recorded['contract_id'],
                ]);
            }

            $this->bulkActiveWorkers($conn, (string) $org->id, 97);

            $this->runRecompute($org);
            $board->refresh();

            $this->assertSame(99, (int) $board->worker_headcount);
            $this->assertSame(0, (int) $board->worker_seats, '99 workers entitle zero seats');
            $this->assertTrue((bool) $board->composition_valid);

            $clk13Armed = $conn->table('clock_timers')
                ->where('clock_id', 'CLK-13')
                ->where('subject_type', 'organizations')
                ->where('subject_id', (string) $org->id)
                ->where('state', 'armed')
                ->exists();
            $this->assertTrue($clk13Armed, 'CLK-13 watcher armed lazily on the first worker write');

            $seqBefore = (int) $conn->table('audit_log')->max('seq');

            // ── The 100th worker — full form path, zero R-23 election
            // action afterwards ──────────────────────────────────────────
            $hundredth = $this->throwawayUser('worker-100');

            $filed = $engine->file('F-IND-014', $hundredth, [
                'employer_type' => 'organizations',
                'employer_id' => (string) $org->id,
            ]);

            $engine->file('F-ORG-001', $agent, [
                'organization_id' => (string) $org->id,
                'action' => 'countersign_contract',
                'contract_id' => $filed->recorded['contract_id'],
            ]);

            $this->runRecompute($org);
            $board->refresh();

            // worker_seats 0 → 1; composition invalid; vacant seat.
            $this->assertSame(100, (int) $board->worker_headcount);
            $this->assertSame(1, (int) $board->worker_seats, 'the first worker seat (CLK-13)');
            $this->assertFalse((bool) $board->composition_valid, 'unfilled required seat ⇒ the board cannot act');

            $vacantWorkerSeats = BoardSeat::query()
                ->where('board_id', $board->id)
                ->where('seat_class', BoardSeat::CLASS_WORKER_ELECTED)
                ->where('status', BoardSeat::STATUS_VACANT)
                ->count();
            $this->assertSame(1, $vacantWorkerSeats);

            // The worker-track election opened through the ELECTION
            // MACHINERY — system-filed F-ORG-004 (no R-23 in the loop).
            $election = Election::query()
                ->where('board_id', $board->id)
                ->where('kind', Election::KIND_ORG_BOARD_WORKER)
                ->first();
            $this->assertNotNull($election, 'WF-ORG-04 auto-trigger opened the worker-track election');
            $this->assertSame(Election::STATUS_APPROVAL_OPEN, $election->status);

            $race = $election->races()->first();
            $this->assertNotNull($race);
            $this->assertSame('workers', $race->electorate_type);
            $this->assertSame(1, (int) $race->seats);

            // The chain: CLK-13 fired + the recompute + the F-ORG-004
            // system filing all sealed.
            $entries = $conn->table('audit_log')->where('seq', '>', $seqBefore)->get();

            $clk13Fired = $entries->first(fn ($e) => $e->module === 'clocks'
                && $e->event === 'fired'
                && str_contains((string) $e->payload, 'CLK-13'));
            $this->assertNotNull($clk13Fired, 'CLK-13 fire is a chain entry');

            $this->assertNotNull(
                $entries->first(fn ($e) => $e->event === 'co_determination.recomputed'),
                'the recompute is a chain entry'
            );
            $this->assertNotNull(
                $entries->first(fn ($e) => $e->ref === 'F-ORG-004'),
                'the system-filed F-ORG-004 is a chain entry'
            );
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * Validity posture + joint chair: an INVALID board cannot open
     * ordinary board business (Art. III §6 citation) while the cure path
     * (board_chair_elect) stays open; the chair vote runs RCV over the
     * FULL board and the winner must reach a majority of ALL seated.
     */
    public function test_invalid_board_blocks_acts_and_chair_majority_is_of_all_seated(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            [$org] = $this->throwawayOrg($conn);
            $board = $this->throwawayBoard($org, ownerSeats: 3, seatHolders: 3);

            // Force the invalid posture (required worker seat unfilled).
            $board->forceFill(['composition_valid' => false, 'worker_seats' => 1])->save();

            try {
                app(ChamberVoteService::class)->open(
                    bodyType: ChamberVote::BODY_BOARD,
                    bodyId: (string) $board->id,
                    voteType: 'procedural_motion',
                );
                $this->fail('An invalid board must not open ordinary business.');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. III §6', $e->citation);
            }

            // The CURE path stays open: the chair election proceeds.
            $vote = app(OrgBoardService::class)->openChairElection($board);
            $this->assertSame(ChamberVote::BODY_BOARD, $vote->body_type);
            $this->assertSame('board_chair_elect', $vote->vote_type);
            $this->assertSame('rcv', $vote->vote_method);

            $tally = $vote->tallies()->first();
            $this->assertSame(3, (int) $tally->serving, 'one lane over ALL seated seats');
            $this->assertSame(2, (int) $tally->quorum_required, 'majority of all seated = floor(3/2)+1');
            $this->assertSame(2, (int) $tally->required_yes, 'rcv_majority: winner needs the majority peg');

            // Every seated seat casts (owner ruling: equal votes); a
            // 2-of-3 final-round winner reaches the majority and seats as
            // chair; composition change had cleared chair_seat_id first.
            $seats = $board->seats()->seated()->orderBy('seat_no')->get();
            $svc = app(ChamberVoteService::class);

            $svc->castBoardSeat($vote, $seats[0], null, [(string) $seats[0]->id, (string) $seats[1]->id]);
            $svc->castBoardSeat($vote, $seats[1], null, [(string) $seats[0]->id]);
            $svc->castBoardSeat($vote, $seats[2], null, [(string) $seats[1]->id]);

            $vote->refresh();
            $this->assertSame(ChamberVote::STATUS_CLOSED, $vote->status, 'auto-closes at full participation');
            $this->assertSame(ChamberVote::OUTCOME_ADOPTED, $vote->outcome);

            $board->refresh();
            $this->assertSame((string) $seats[0]->id, (string) $board->chair_seat_id, 'the majority winner chairs');
            $this->assertTrue((bool) $seats[0]->refresh()->is_chair);

            // Casts are PUBLIC (board votes are governance acts) and ride
            // vote_casts.board_seat_id — never member_id.
            $casts = $conn->table('vote_casts')->where('vote_id', (string) $vote->id)->get();
            $this->assertCount(3, $casts);
            foreach ($casts as $cast) {
                $this->assertNull($cast->member_id);
                $this->assertNotNull($cast->board_seat_id);
                $this->assertNotNull($cast->public_record_id);
            }
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /** @return array{0: Organization, 1: User} */
    private function throwawayOrg($conn): array
    {
        $jurisdictionId = $conn->table('jurisdictions')->whereNull('deleted_at')->value('id');
        $this->assertNotNull($jurisdictionId, 'Live DB has no jurisdictions — seed it first.');

        $agent = $this->throwawayUser('agent');

        $org = Organization::create([
            'jurisdiction_id' => (string) $jurisdictionId,
            'type' => Organization::TYPE_BUSINESS,
            'structure' => Organization::STRUCTURE_STOCK,
            'name' => 'Worker Representation Throwaway '.Str::random(6),
            'slug' => 'worker-rep-throwaway-'.strtolower(Str::random(8)),
            'status' => Organization::STATUS_ACTIVE,
            'is_active' => true,
            'is_registered' => true,
            'registered_at' => now(),
            'agent_user_id' => (string) $agent->getKey(),
            'registered_by_user_id' => (string) $agent->getKey(),
            'registered_via_form' => 'F-IND-012',
            'worker_count' => 0,
        ]);

        return [$org, $agent];
    }

    private function throwawayBoard(Organization $org, int $ownerSeats, int $seatHolders): Board
    {
        $board = Board::create([
            'boardable_type' => Board::BOARDABLE_ORGANIZATIONS,
            'boardable_id' => (string) $org->id,
            'owner_seats' => $ownerSeats,
            'worker_seats' => 0,
            'worker_headcount' => 0,
            'composition_valid' => true,
            'status' => Board::STATUS_ACTIVE,
        ]);

        for ($no = 1; $no <= $ownerSeats; $no++) {
            BoardSeat::create([
                'board_id' => (string) $board->id,
                'seat_class' => BoardSeat::CLASS_OWNER_ELECTED,
                'seat_no' => $no,
                'holder_user_id' => $no <= $seatHolders ? (string) $this->throwawayUser("owner-{$no}")->getKey() : null,
                'status' => $no <= $seatHolders ? BoardSeat::STATUS_SEATED : BoardSeat::STATUS_VACANT,
            ]);
        }

        $org->forceFill(['board_id' => (string) $board->id])->save();

        return $board;
    }

    private function throwawayUser(string $label): User
    {
        return User::create([
            'name' => "WorkerRep {$label}",
            'email' => 'worker-rep-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function bulkActiveWorkers($conn, string $orgId, int $count): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'employer_type' => 'organizations',
                'employer_id' => $orgId,
                'user_id' => (string) $this->throwawayUser("bulk-{$i}")->getKey(),
                'status' => 'active',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            $conn->table('org_workers')->insert($chunk);
        }
    }

    /** The queued job, run inline (queued in prod; the body is the engine). */
    private function runRecompute(Organization $org): void
    {
        (new \App\Jobs\Organizations\RecomputeWorkerHeadcountJob('organizations', (string) $org->id))
            ->handle(app(CoDeterminationService::class));
    }

    private function livePg(): \Illuminate\Database\Connection
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
