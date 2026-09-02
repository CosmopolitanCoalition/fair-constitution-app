<?php

namespace Tests\Constitutional;

use App\Jobs\AutoscaleWorkerJob;
use App\Models\AutoscaleRun;
use App\Models\User;
use App\Services\Autoscale\AutoscaleRunControl;
use App\Support\AutoscaleClaims;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * PIN — LANE KILL CONTROLS (operator order 2026-09-02): deadlines are
 * WARNINGS, kills are manual or opt-in automatic, and a killed scope PARKS
 * in review with no retry.
 *
 *  - POST /api/setup/wizard/step3/lanes/{lease}/kill (operator only) parks
 *    THE SCOPE IN HAND (the lease's current_scope_id) in review, hands its
 *    header to the finalize rung (the processor's one review path), returns
 *    a batch's untouched remainder to pending, and deletes the lease. 404
 *    for no lease.
 *  - Auto-kill parks leases whose scope in hand (claim_started_at, restarted
 *    by the lane before each batch scope) is older than
 *    autoscale_runs.auto_kill_minutes and leaves 'singles' claims alone.
 *  - POST /api/setup/wizard/step3/auto-kill sets the limit (1..1440 or null).
 *  - THE LEASE IS THE LANE'S LIFE: a lane whose lease vanished releases the
 *    claim it just took, works nothing, ends, and respawns its successor.
 *  - THE CONNECTION GUARD: a lane's lost session is never transparently
 *    replaced and its statement never re-run; it throws.
 *
 * The fixtures use an ABSENT backend pid (no live session holds it), so no
 * kill test ever terminates a real backend; the guard test terminates its
 * own disposable probe session only. Live-pg connection, TEMP shadow tables
 * (the HeavyLaneClaimTest posture).
 *
 * If an edit breaks these, the edit is the violation — fix the edit.
 */
class AutoscaleLaneKillTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_autoscale_lane_kill';

    private const PROBE_CONNECTION = 'pgsql_autoscale_lane_guard_probe';

    private const CSRF = 'lane-kill-csrf';

    /** A pid no live backend holds. */
    private const ABSENT_PID = 2147483000;

    private const SHADOWED = [
        'apportionment_ledger', 'apportionment_ledger_scopes',
        'autoscale_worker_leases', 'jurisdiction_adjacency_parents',
    ];

    public function test_the_kill_endpoint_parks_the_scope_in_review_hands_its_header_to_finalize_and_deletes_the_lease(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $lease = $this->mkLease($run, 'scope', claimMinutesAgo: 12);
            [$legId, $scopeId] = $this->mkScope($lease);

            $this->actingAs($this->user(isOperator: true))
                ->withSession(['_token' => self::CSRF])
                ->post("/api/setup/wizard/step3/lanes/{$lease}/kill", ['_token' => self::CSRF])
                ->assertOk()
                ->assertJson(['ok' => true]);

            $scope = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
            $this->assertSame('review', $scope->status);
            $this->assertNull($scope->claim_token);
            $this->assertSame('killed by operator after 12 min', $scope->reason);
            $this->assertNotNull($scope->finished_at);

            // THE ONE REVIEW PATH: the header stays running; the finalize
            // rung claims it (a review scope counts as closed) and the
            // assessment closes it as review. No direct close by the kill.
            $this->assertSame('running', DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('map_status'));
            $claim = AutoscaleClaims::next($run, (string) Str::uuid());
            $this->assertNotNull($claim);
            $this->assertSame('finalize', $claim['type']);
            $this->assertSame($legId, $claim['legislature_id']);

            $this->assertSame(0, (int) DB::table('autoscale_worker_leases')->where('id', $lease)->count(),
                'the killed lease row is deleted');
        });
    }

    public function test_the_kill_endpoint_refuses_a_non_operator_and_404s_an_unknown_lease(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $lease = $this->mkLease($run, 'scope', claimMinutesAgo: 1);
            [, $scopeId] = $this->mkScope($lease);

            $this->actingAs($this->user(isOperator: false))
                ->withSession(['_token' => self::CSRF])
                ->post("/api/setup/wizard/step3/lanes/{$lease}/kill", ['_token' => self::CSRF])
                ->assertForbidden();
            $this->assertSame('running', DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('status'));

            $operator = $this->user(isOperator: true);
            $this->actingAs($operator)
                ->withSession(['_token' => self::CSRF])
                ->post('/api/setup/wizard/step3/lanes/'.Str::uuid().'/kill', ['_token' => self::CSRF])
                ->assertNotFound();
            $this->actingAs($operator)
                ->withSession(['_token' => self::CSRF])
                ->post('/api/setup/wizard/step3/lanes/not-a-uuid/kill', ['_token' => self::CSRF])
                ->assertNotFound();
        });
    }

    public function test_killing_a_batch_lane_parks_only_the_scope_in_hand_and_returns_the_remainder(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $lease = $this->mkLease($run, 'scope_batch', claimMinutesAgo: 7);
            [$legInHand, $inHand] = $this->mkScope($lease);
            // Three untouched batch scopes under the same token: running,
            // never started (their headers still pending, as the batch claim
            // leaves them).
            $rest = [];
            for ($i = 0; $i < 3; $i++) {
                [, $rest[]] = $this->mkScope($lease, inHand: false, headerStatus: 'pending');
            }

            $result = app(AutoscaleRunControl::class)->killLease($lease, 'killed by operator');

            $this->assertSame(1, $result['parked']);
            $this->assertSame(3, $result['released']);
            $this->assertSame(1, $result['headers_handed']);

            $parked = DB::table('apportionment_ledger_scopes')->where('id', $inHand)->first();
            $this->assertSame('review', $parked->status);
            $this->assertSame('killed by operator after 7 min', $parked->reason);
            $this->assertNull($parked->claim_token);
            $this->assertSame('running', DB::table('apportionment_ledger')->where('legislature_id', $legInHand)->value('map_status'));

            foreach ($rest as $scopeId) {
                $s = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
                $this->assertSame('pending', $s->status, 'an untouched batch scope returns to the pile');
                $this->assertNull($s->claim_token);
                $this->assertNull($s->started_at);
                $this->assertNull($s->reason, 'no kill reason, no retry bump on a scope that was never worked');
                $this->assertSame(0, (int) $s->retry_count);
                $this->assertSame('pending', DB::table('apportionment_ledger')->where('legislature_id', $s->legislature_id)->value('map_status'));
            }
            $this->assertSame(0, (int) DB::table('autoscale_worker_leases')->where('id', $lease)->count());
        });
    }

    public function test_auto_kill_parks_overdue_scope_claims_and_leaves_singles_and_young_scopes_alone(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            AutoscaleRun::query()->whereKey($run->id)->update(['auto_kill_minutes' => 30]);
            $run->refresh();

            $overdueScope  = $this->mkLease($run, 'scope', claimMinutesAgo: 45);
            $overdueBatch  = $this->mkLease($run, 'scope_batch', claimMinutesAgo: 31);
            $overdueSingle = $this->mkLease($run, 'singles', claimMinutesAgo: 90);
            $youngScope    = $this->mkLease($run, 'scope', claimMinutesAgo: 5);
            // An OLD batch lease whose scope in hand is YOUNG: the lane
            // restarted claim_started_at before this scope. The deadline
            // measures the scope in hand, never the batch.
            $oldBatchYoungScope = $this->mkLease($run, 'scope_batch', claimMinutesAgo: 5, startedMinutesAgo: 45);
            [$legA, $scopeA] = $this->mkScope($overdueScope);
            [$legB, $scopeB] = $this->mkScope($overdueBatch);
            [, $scopeBRest]  = $this->mkScope($overdueBatch, inHand: false, headerStatus: 'pending');
            [, $scopeC]      = $this->mkScope($youngScope);
            [, $scopeD]      = $this->mkScope($oldBatchYoungScope);

            $killed = app(AutoscaleRunControl::class)->sweepKills($run);

            $this->assertSame(2, $killed, 'exactly the two overdue scope-kind claims are killed');

            $a = DB::table('apportionment_ledger_scopes')->where('id', $scopeA)->first();
            $this->assertSame('review', $a->status);
            $this->assertSame('auto-killed after 45 min (limit 30)', $a->reason);
            $this->assertNull($a->claim_token);
            $this->assertSame('running', DB::table('apportionment_ledger')->where('legislature_id', $legA)->value('map_status'));

            $b = DB::table('apportionment_ledger_scopes')->where('id', $scopeB)->first();
            $this->assertSame('review', $b->status);
            $this->assertSame('auto-killed after 31 min (limit 30)', $b->reason);
            $this->assertSame('running', DB::table('apportionment_ledger')->where('legislature_id', $legB)->value('map_status'));
            $this->assertSame('pending', DB::table('apportionment_ledger_scopes')->where('id', $scopeBRest)->value('status'),
                'the killed batch\'s untouched remainder returns to the pile');

            $this->assertSame('running', DB::table('apportionment_ledger_scopes')->where('id', $scopeC)->value('status'),
                'a claim younger than the limit is untouched');
            $this->assertSame('running', DB::table('apportionment_ledger_scopes')->where('id', $scopeD)->value('status'),
                'an old batch with a young scope in hand is untouched');

            $left = DB::table('autoscale_worker_leases')->pluck('id')->map(fn ($v) => (string) $v)->all();
            sort($left);
            $expected = [$overdueSingle, $youngScope, $oldBatchYoungScope];
            sort($expected);
            $this->assertSame($expected, $left, 'singles claims are never auto-killed; young scopes in hand survive');
        });
    }

    public function test_auto_kill_is_off_when_the_limit_is_null(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $lease = $this->mkLease($run, 'scope', claimMinutesAgo: 600);
            [, $scopeId] = $this->mkScope($lease);

            $this->assertSame(0, app(AutoscaleRunControl::class)->sweepKills($run));
            $this->assertSame('running', DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('status'),
                'no limit, no automatic kill: a deadline is a warning');
        });
    }

    public function test_a_kill_request_stamp_is_honored_by_the_pump_sweep(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $lease = $this->mkLease($run, 'scope', claimMinutesAgo: 3);
            [$legId, $scopeId] = $this->mkScope($lease);
            DB::table('autoscale_worker_leases')->where('id', $lease)->update(['kill_requested_at' => now()]);

            $this->assertSame(1, app(AutoscaleRunControl::class)->sweepKills($run));
            $this->assertSame('review', DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('status'));
            $this->assertSame('killed by operator after 3 min', DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('reason'));
            $this->assertSame('running', DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('map_status'));
            $this->assertSame(0, (int) DB::table('autoscale_worker_leases')->where('id', $lease)->count());
        });
    }

    public function test_the_auto_kill_endpoint_sets_and_clears_the_limit(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $operator = $this->user(isOperator: true);

            $this->actingAs($operator)
                ->withSession(['_token' => self::CSRF])
                ->post('/api/setup/wizard/step3/auto-kill', ['_token' => self::CSRF, 'minutes' => 45])
                ->assertOk()
                ->assertJson(['ok' => true, 'auto_kill_minutes' => 45]);
            $this->assertSame(45, (int) DB::table('autoscale_runs')->where('id', $run->id)->value('auto_kill_minutes'));

            $this->actingAs($operator)
                ->withSession(['_token' => self::CSRF])
                ->post('/api/setup/wizard/step3/auto-kill', ['_token' => self::CSRF, 'minutes' => null])
                ->assertOk()
                ->assertJson(['ok' => true, 'auto_kill_minutes' => null]);
            $this->assertNull(DB::table('autoscale_runs')->where('id', $run->id)->value('auto_kill_minutes'));

            $this->actingAs($operator)
                ->withSession(['_token' => self::CSRF])
                ->postJson('/api/setup/wizard/step3/auto-kill', ['_token' => self::CSRF, 'minutes' => 0])
                ->assertStatus(422);

            $this->actingAs($this->user(isOperator: false))
                ->withSession(['_token' => self::CSRF])
                ->post('/api/setup/wizard/step3/auto-kill', ['_token' => self::CSRF, 'minutes' => 10])
                ->assertForbidden();
        });
    }

    /**
     * THE LEASE IS THE LANE'S LIFE. A live lane loop (AutoscaleWorkerJob
     * inline) claims a scope; its lease vanishes at that instant (a kill or
     * a reap, simulated by a BEFORE UPDATE trigger on the shadow lease table
     * that deletes the row and skips the claim-start touch). The lane must
     * see the zero-row touch, release the claim under its token, work
     * nothing, end, and respawn its successor.
     */
    public function test_a_lane_whose_lease_vanishes_releases_its_claim_works_nothing_and_ends(): void
    {
        $this->onLivePgSession(function (AutoscaleRun $run): void {
            Queue::fake();

            // A pending, stamped root scope on a pending sweep header. Its
            // legislature does not exist in `legislatures`, so any WORK on it
            // would fail fast into a guarded 'failed' write; the assertion
            // below is that no such write happens.
            $legId = (string) Str::uuid();
            $jid   = (string) Str::uuid();
            DB::table('apportionment_ledger')->insert([
                'legislature_id' => $legId, 'jurisdiction_id' => $jid,
                'population' => 1000, 'head_seats' => 20, 'scope_count' => 1,
                'compute_status' => 'done', 'computed_at' => now(),
                'adm_level' => 5, 'kind' => 'sweep', 'child_count' => 0,
                'map_status' => 'pending', 'position' => 1,
                'block_rank' => -100, 'block_order' => 0, 'area_tier' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $scopeId = (string) Str::uuid();
            DB::table('apportionment_ledger_scopes')->insert([
                'id' => $scopeId, 'legislature_id' => $legId, 'scope_jurisdiction_id' => $jid,
                'depth' => 0, 'walk_position' => 1, 'seat_budget' => 20, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // THE VANISHING LEASE: the claim-start touch (claim_type set) is
            // skipped by the trigger, so the lane's touch affects zero rows,
            // exactly what it sees when a kill or a reap has deleted the
            // row. Every other lease write runs normally.
            DB::unprepared("
                CREATE FUNCTION pg_temp.vanish_lease() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF NEW.claim_type IS NOT NULL THEN
                        RETURN NULL;
                    END IF;
                    RETURN NEW;
                END \$\$;
                CREATE TRIGGER vanish_lease BEFORE UPDATE ON autoscale_worker_leases
                    FOR EACH ROW EXECUTE FUNCTION pg_temp.vanish_lease();
            ");

            (new AutoscaleWorkerJob((string) $run->id))->handle();

            $scope = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
            $this->assertSame('pending', $scope->status, 'the claim is released, not worked');
            $this->assertNull($scope->claim_token);
            $this->assertNull($scope->started_at);
            $this->assertNull($scope->reason, 'no failure write: nothing was worked');
            $this->assertSame(0, (int) $scope->retry_count);
            $this->assertSame('pending', DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('map_status'),
                'the header was never flipped: the processor never ran');
            $this->assertSame(0, (int) DB::table('autoscale_worker_leases')->count(), 'the lane holds no lease');

            // The pool stays full: the lane dispatched its successor because
            // claimable work remains.
            Queue::assertPushed(AutoscaleWorkerJob::class, 1);
        });
    }

    /**
     * THE CONNECTION GUARD. On a disposable probe session with the guard
     * installed, a terminated backend makes the next statement throw
     * LostConnectionException: the framework's transparent reconnect-and-
     * re-run is refused. With the framework's reconnector restored, the same
     * statement is transparently retried and succeeds (positive control).
     * Only the probe's own backend is terminated.
     */
    public function test_the_connection_guard_refuses_the_transparent_retry_after_a_backend_kill(): void
    {
        $control = $this->livePg(self::LIVE_CONNECTION);
        $probe   = $this->livePg(self::PROBE_CONNECTION);
        $probePid = (int) $probe->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $this->assertNotSame((int) $control->selectOne('SELECT pg_backend_pid() AS pid')->pid, $probePid);

        AutoscaleWorkerJob::installConnectionGuard($probe);
        try {
            $terminated = (bool) $control->selectOne('SELECT pg_terminate_backend(?, 5000) AS t', [$probePid])->t;
            $this->assertTrue($terminated, 'the probe backend is gone before the next statement');

            try {
                $probe->selectOne('SELECT 1 AS ok');
                $this->fail('the guard must refuse the transparent retry');
            } catch (LostConnectionException $e) {
                $this->assertStringContainsString('the transparent retry is refused', $e->getMessage());
            }
        } finally {
            AutoscaleWorkerJob::restoreConnectionReconnector($probe);
        }

        // Positive control: the framework's reconnector replaces the dead
        // session and re-runs the statement.
        $this->assertSame(1, (int) $probe->selectOne('SELECT 1 AS ok')->ok);
        $this->assertNotSame($probePid, (int) $probe->selectOne('SELECT pg_backend_pid() AS pid')->pid);
    }

    // ── fixture plumbing ────────────────────────────────────────────────────

    /** Transaction-wrapped hermetic world: TEMP shadows, everything rolled back. */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $this->createShadows();

            $runId = (string) Str::uuid();
            DB::table('autoscale_runs')->insert($this->runRow($runId));
            $body(AutoscaleRun::query()->findOrFail($runId));
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /**
     * Session-scoped hermetic world with NO open transaction: the worker's
     * claim-boundary hygiene rolls back any open transaction it finds, so a
     * live lane loop runs against TEMP shadows (autoscale_runs included) that
     * are dropped explicitly afterwards.
     */
    private function onLivePgSession(callable $body): void
    {
        $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        try {
            $this->dropShadows();
            $this->createShadows();
            DB::statement('CREATE TEMP TABLE autoscale_runs
                           (LIKE public.autoscale_runs INCLUDING DEFAULTS INCLUDING INDEXES)');

            $runId = (string) Str::uuid();
            DB::table('autoscale_runs')->insert($this->runRow($runId));
            $body(AutoscaleRun::query()->findOrFail($runId));
        } finally {
            $this->dropShadows();
            DB::setDefaultConnection($original);
        }
    }

    private function createShadows(): void
    {
        foreach (self::SHADOWED as $table) {
            DB::statement("CREATE TEMP TABLE {$table}
                           (LIKE public.{$table} INCLUDING DEFAULTS INCLUDING INDEXES)");
        }
    }

    private function dropShadows(): void
    {
        // Dropping the lease shadow drops its trigger; the function follows.
        foreach (array_merge(self::SHADOWED, ['autoscale_runs']) as $table) {
            DB::statement("DROP TABLE IF EXISTS pg_temp.{$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS pg_temp.vanish_lease()');
    }

    private function runRow(string $runId): array
    {
        return [
            'id' => $runId, 'status' => 'mapping', 'adm_max' => 6,
            'sized_parents' => 0, 'sized_leaves' => 0,
            'singles_total' => 0, 'singles_done' => 0,
            'sweeps_total' => 0, 'sweeps_done' => 0, 'review_count' => 0,
            'mapping_started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function user(bool $isOperator): User
    {
        return User::create([
            'name' => 'Lane '.Str::random(5),
            'email' => 'lane-kill-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
            'is_operator' => $isOperator,
        ]);
    }

    /**
     * One lease holding a claim of $claimType whose scope in hand started
     * $claimMinutesAgo (the lane started $startedMinutesAgo, default one
     * minute earlier). Backend pid ABSENT.
     */
    private function mkLease(AutoscaleRun $run, string $claimType, int $claimMinutesAgo, ?int $startedMinutesAgo = null): string
    {
        $id = (string) Str::uuid();
        DB::table('autoscale_worker_leases')->insert([
            'id' => $id, 'run_id' => (string) $run->id, 'lane' => 'auto',
            'pg_backend_pid' => self::ABSENT_PID,
            'claim_type' => $claimType, 'claim_label' => 'test '.$claimType,
            'claim_started_at' => now()->subMinutes($claimMinutesAgo),
            'started_at' => now()->subMinutes($startedMinutesAgo ?? ($claimMinutesAgo + 1)),
            'last_seen_at' => now(),
        ]);

        return $id;
    }

    /**
     * One sweep header (status $headerStatus) + its running root scope held
     * by $token. With $inHand the lease records the scope as its
     * current_scope_id (the scope the lane is working).
     */
    private function mkScope(string $token, bool $inHand = true, string $headerStatus = 'running'): array
    {
        $legId = (string) Str::uuid();
        $jid   = (string) Str::uuid();
        DB::table('apportionment_ledger')->insert([
            'legislature_id' => $legId, 'jurisdiction_id' => $jid,
            'population' => 1000, 'head_seats' => 20, 'scope_count' => 1,
            'compute_status' => 'done', 'computed_at' => now(),
            'adm_level' => 5, 'kind' => 'sweep', 'child_count' => 0,
            'map_status' => $headerStatus, 'position' => 1,
            'block_rank' => -100, 'block_order' => 0, 'area_tier' => 1,
            'started_at' => $headerStatus === 'pending' ? null : now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $scopeId = (string) Str::uuid();
        DB::table('apportionment_ledger_scopes')->insert([
            'id' => $scopeId, 'legislature_id' => $legId,
            'scope_jurisdiction_id' => $jid,
            'depth' => 0, 'walk_position' => 1, 'seat_budget' => 20,
            'status' => 'running', 'claim_token' => $token,
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inHand) {
            DB::table('autoscale_worker_leases')->where('id', $token)->update(['current_scope_id' => $scopeId]);
        }

        return [$legId, $scopeId];
    }
}
