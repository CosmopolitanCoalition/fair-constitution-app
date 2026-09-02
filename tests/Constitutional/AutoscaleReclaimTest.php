<?php

namespace Tests\Constitutional;

use App\Console\Commands\AutoscalePumpCommand;
use App\Models\AutoscaleRun;
use App\Services\Autoscale\SweepScopeProcessor;
use App\Support\AutoscaleClaims;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * PIN — THE SCOPE RECLAIM READS BACKEND ABSENCE, NEVER A TIMER (operator
 * order 2026-09-02, the three Tumaco lanes on one scope, retry_count 4).
 *
 * A running scope returns to the pile only when its lane is certainly gone:
 * its lease row is missing, or the lease's backend pid is absent from
 * pg_stat_activity, or (a pre-pid lease) its heartbeat is stale. A live lane
 * whose backend is present is never reclaimed, however old the scope's
 * updated_at is. Every reclaim bumps retry_count; the third parks the scope
 * in review and hands its header to the finalize rung, the processor's one
 * review path (a review scope counts as closed for claimFinalize).
 *
 * THE CLAIM-TOKEN GUARD: a scope state write carrying the wrong token
 * affects zero rows and the processor writes nothing further for it.
 *
 * Runs on the guarded live-pg connection with session TEMP tables shadowing
 * the ledger, the lease table and the precompute worklist (the
 * HeavyLaneClaimTest posture), so the fixtures ARE the world and the box's
 * own rows never enter a test.
 *
 * If an edit breaks these, the edit is the violation — fix the edit.
 */
class AutoscaleReclaimTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_autoscale_reclaim';

    /** A pid no live backend holds. */
    private const ABSENT_PID = 2147483000;

    public function test_a_connected_backend_is_never_reclaimed_even_with_a_three_hour_old_scope(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $ownPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            $lease  = $this->mkLease($run, $ownPid, lastSeenMinutesAgo: 180);
            [$legId, $scopeId] = $this->mkScope($lease, retryCount: 0, updatedMinutesAgo: 180);

            $result = AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame(0, $result['reclaimed'], 'a lane whose backend is present is never reclaimed');
            $scope = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
            $this->assertSame('running', $scope->status);
            $this->assertSame($lease, (string) $scope->claim_token);
            $this->assertSame(0, (int) $scope->retry_count);
            $this->assertSame('running', DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('map_status'));
        });
    }

    public function test_a_missing_lease_row_is_reclaimed_and_retry_count_increments(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $ghost = (string) Str::uuid();   // no lease row carries this id
            [, $scopeId] = $this->mkScope($ghost, retryCount: 0, updatedMinutesAgo: 1);

            $result = AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame(1, $result['reclaimed']);
            $this->assertSame(0, $result['parked']);
            $scope = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
            $this->assertSame('pending', $scope->status);
            $this->assertNull($scope->claim_token);
            $this->assertNull($scope->started_at);
            $this->assertSame(1, (int) $scope->retry_count);
            $this->assertSame('reclaimed: lease gone mid-scope', $scope->reason);
        });
    }

    public function test_an_absent_backend_pid_is_reclaimed_at_once(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            // Heartbeat FRESH, backend GONE: the pid is the evidence, not
            // the clock.
            $lease = $this->mkLease($run, self::ABSENT_PID, lastSeenMinutesAgo: 0);
            [, $scopeId] = $this->mkScope($lease, retryCount: 1, updatedMinutesAgo: 0);

            $result = AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame(1, $result['reclaimed']);
            $scope = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
            $this->assertSame('pending', $scope->status);
            $this->assertSame(2, (int) $scope->retry_count);
            $this->assertSame('reclaimed: worker backend gone mid-scope', $scope->reason);
        });
    }

    public function test_a_pidless_lease_is_reclaimed_only_when_its_heartbeat_is_stale(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $fresh = $this->mkLease($run, null, lastSeenMinutesAgo: 1);
            $stale = $this->mkLease($run, null, lastSeenMinutesAgo: 11);
            [, $freshScope] = $this->mkScope($fresh, retryCount: 0, updatedMinutesAgo: 200);
            [, $staleScope] = $this->mkScope($stale, retryCount: 0, updatedMinutesAgo: 1);

            $result = AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame(1, $result['reclaimed']);
            $this->assertSame('running', DB::table('apportionment_ledger_scopes')->where('id', $freshScope)->value('status'),
                'a pre-pid lease with a fresh heartbeat is alive; the scope age is not evidence');
            $stale = DB::table('apportionment_ledger_scopes')->where('id', $staleScope)->first();
            $this->assertSame('pending', $stale->status);
            $this->assertSame('reclaimed: worker heartbeat stale mid-scope', $stale->reason);
        });
    }

    public function test_the_third_reclaim_parks_the_scope_in_review_and_hands_its_header_to_the_finalize_rung(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $ghost = (string) Str::uuid();
            [$legId, $scopeId] = $this->mkScope($ghost, retryCount: 2, updatedMinutesAgo: 5, reason: 'transient retry 2/3: x');

            $result = AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame(1, $result['reclaimed']);
            $this->assertSame(1, $result['parked']);
            $scope = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
            $this->assertSame('review', $scope->status);
            $this->assertNull($scope->claim_token);
            $this->assertNotNull($scope->finished_at);
            $this->assertSame(3, (int) $scope->retry_count);
            $this->assertStringStartsWith('reclaimed 3 times: reclaimed: lease gone mid-scope', $scope->reason);
            $this->assertStringContainsString('prior: transient retry 2/3: x', $scope->reason);

            // THE ONE REVIEW PATH: the header stays running and the finalize
            // rung claims it at once (a review scope counts as closed). The
            // assessment then closes it as review with the reason as a
            // diagnostic. No direct close by the pump.
            $header = DB::table('apportionment_ledger')->where('legislature_id', $legId)->first();
            $this->assertSame('running', $header->map_status);
            $this->assertNull($header->finished_at);

            $claim = AutoscaleClaims::next($run, (string) Str::uuid());
            $this->assertNotNull($claim);
            $this->assertSame('finalize', $claim['type'], 'the finalize rung takes the parked scope\'s header');
            $this->assertSame($legId, $claim['legislature_id']);
            $this->assertSame('assessing', DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('map_status'));
        });
    }

    public function test_a_park_before_the_first_flip_makes_the_header_visible_to_the_finalize_rung(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $ghost = (string) Str::uuid();
            [$legId, $scopeId] = $this->mkScope($ghost, retryCount: 2, updatedMinutesAgo: 1, headerStatus: 'pending');

            AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame('review', DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('status'));
            $header = DB::table('apportionment_ledger')->where('legislature_id', $legId)->first();
            $this->assertSame('running', $header->map_status, 'a pending header is flipped running so claimFinalize can see it');
            $this->assertNotNull($header->started_at);
        });
    }

    public function test_a_parked_scope_with_an_open_sibling_leaves_the_header_to_the_finalize_rung(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $ghost = (string) Str::uuid();
            [$legId, $scopeId] = $this->mkScope($ghost, retryCount: 2, updatedMinutesAgo: 5);
            // A sibling still pending on the same header.
            $siblingId = (string) Str::uuid();
            DB::table('apportionment_ledger_scopes')->insert([
                'id' => $siblingId, 'legislature_id' => $legId,
                'scope_jurisdiction_id' => (string) Str::uuid(), 'depth' => 1,
                'walk_position' => 2, 'seat_budget' => 5, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame('review', DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('status'));
            $this->assertSame('running', DB::table('apportionment_ledger')->where('legislature_id', $legId)->value('map_status'),
                'the header stays running while a sibling is open');

            // The finalize rung does not take it: the next claim is the
            // sibling scope itself, not an assessment.
            $claim = AutoscaleClaims::next($run, (string) Str::uuid());
            $this->assertNotNull($claim);
            $this->assertSame('scope', $claim['type']);
            $this->assertSame($siblingId, $claim['scope_id']);
        });
    }

    public function test_a_done_write_with_the_wrong_token_affects_no_row_and_writes_nothing_further(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $owner = $this->mkLease($run, null, lastSeenMinutesAgo: 0);
            [$legId, $scopeId, $scopeJid] = $this->mkScope($owner, retryCount: 0, updatedMinutesAgo: 0);

            // A real jurisdiction id makes the follow-on giant-child insert
            // observable (the insert selects FROM jurisdictions). Read-only
            // pick, bounded by the primary key.
            $childJid = DB::table('jurisdictions')->orderBy('id')->limit(1)->value('id');
            $giants = $childJid !== null ? [(string) $childJid => 5] : [];

            $m = new \ReflectionMethod(SweepScopeProcessor::class, 'closeScopeDone');
            $m->setAccessible(true);
            $processor = app(SweepScopeProcessor::class);

            $owned = $m->invoke($processor, $scopeId, $legId, $scopeJid, 0, $giants, null, (string) Str::uuid());

            $this->assertFalse($owned, 'the wrong token owns nothing');
            $scope = DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->first();
            $this->assertSame('running', $scope->status, 'the done write affected zero rows');
            $this->assertSame($owner, (string) $scope->claim_token);
            $this->assertSame(1, (int) DB::table('apportionment_ledger_scopes')->where('legislature_id', $legId)->count(),
                'no giant-child scope row was materialized after the lost write');

            // Positive control: the owner's token closes the scope and
            // materializes the child.
            $owned = $m->invoke($processor, $scopeId, $legId, $scopeJid, 0, $giants, null, $owner);
            $this->assertTrue($owned);
            $this->assertSame('done', DB::table('apportionment_ledger_scopes')->where('id', $scopeId)->value('status'));
            if ($childJid !== null) {
                $this->assertSame(2, (int) DB::table('apportionment_ledger_scopes')->where('legislature_id', $legId)->count());
            }
        });
    }

    // ── fixture plumbing ────────────────────────────────────────────────────

    /**
     * THE SCOPE IN HAND (review catch 2026-09-02): a dead batch lane's
     * untouched remainder returns to the pile with no bump and no reason;
     * only the scope it was working carries the reclaim.
     */
    public function test_a_dead_batch_lane_bumps_only_the_scope_in_hand(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $token = (string) Str::uuid();
            [, $inHand]  = $this->mkScope($token, retryCount: 2, updatedMinutesAgo: 5);
            [, $restA]   = $this->mkScope($token, retryCount: 0, updatedMinutesAgo: 5, reason: 'untouched');
            [, $restB]   = $this->mkScope($token, retryCount: 0, updatedMinutesAgo: 5, reason: 'untouched');
            DB::table('autoscale_worker_leases')->insert([
                'id' => $token, 'run_id' => (string) $run->id, 'lane' => 'auto',
                'pg_backend_pid' => self::ABSENT_PID,
                'claim_type' => 'scope_batch', 'claim_label' => '2-cut batch',
                'claim_started_at' => now()->subMinutes(5), 'started_at' => now()->subMinutes(6),
                'last_seen_at' => now()->subMinutes(5), 'current_scope_id' => $inHand,
            ]);

            $result = AutoscalePumpCommand::reclaimDeadScopes();

            $this->assertSame(1, $result['reclaimed'], 'only the scope in hand counts as reclaimed');
            $this->assertSame(2, $result['released'], 'the untouched remainder is released');
            $this->assertSame(1, $result['parked'], 'the in-hand scope hit its third reclaim');
            $hand = DB::table('apportionment_ledger_scopes')->where('id', $inHand)->first();
            $this->assertSame('review', $hand->status);
            $this->assertSame(3, (int) $hand->retry_count);
            foreach ([$restA, $restB] as $id) {
                $row = DB::table('apportionment_ledger_scopes')->where('id', $id)->first();
                $this->assertSame('pending', $row->status);
                $this->assertNull($row->claim_token);
                $this->assertNull($row->started_at);
                $this->assertSame(0, (int) $row->retry_count, 'remainder carries no bump');
                $this->assertSame('untouched', $row->reason, 'remainder keeps its reason');
            }
        });
    }

    /**
     * THE FINALIZE INPUT STAYS RUNNING (review catch 2026-09-02): the halt
     * reaper leaves a running header with every scope closed for the
     * finalize rung, returns an assessing header to running for re-claim,
     * and sends back to pending only a header that still owns a pending scope.
     */
    public function test_the_halt_reaper_keeps_the_finalize_input_running(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $run->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();

            // (a) all scopes closed (one parked in review): header stays running.
            [$closedLeg, $parkedScope] = $this->mkScope((string) Str::uuid(), retryCount: 3, updatedMinutesAgo: 1);
            DB::table('apportionment_ledger_scopes')->where('id', $parkedScope)
                ->update(['status' => 'review', 'claim_token' => null, 'finished_at' => now()]);
            // (b) a finalize claim in flight: header returns to running, token cleared.
            [$assessLeg, $doneScope] = $this->mkScope((string) Str::uuid(), retryCount: 0, updatedMinutesAgo: 1, headerStatus: 'assessing');
            DB::table('apportionment_ledger_scopes')->where('id', $doneScope)
                ->update(['status' => 'done', 'claim_token' => null, 'finished_at' => now()]);
            DB::table('apportionment_ledger')->where('legislature_id', $assessLeg)->update(['claim_token' => (string) Str::uuid()]);
            // (c) a pending scope remains: header returns to pending.
            [$openLeg, $pendingScope] = $this->mkScope((string) Str::uuid(), retryCount: 0, updatedMinutesAgo: 1);
            DB::table('apportionment_ledger_scopes')->where('id', $pendingScope)
                ->update(['status' => 'pending', 'claim_token' => null, 'started_at' => null]);

            AutoscalePumpCommand::reapHaltedLanes($run->fresh());

            $status = fn (string $leg) => DB::table('apportionment_ledger')->where('legislature_id', $leg)->first();
            $this->assertSame('running', $status($closedLeg)->map_status, 'closed header waits for the finalize rung');
            $this->assertSame('running', $status($assessLeg)->map_status, 'assessing header returns to running');
            $this->assertNull($status($assessLeg)->claim_token);
            $this->assertSame('pending', $status($openLeg)->map_status, 'a header with a pending scope returns to pending');
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            // HERMETIC WORLD: session TEMP tables shadow the ledger, the
            // lease table and the precompute worklist for this connection —
            // pg_temp resolves first — so the fixtures ARE the world and
            // every rung of the claim ladder reads them. Dropped with the
            // transaction.
            DB::statement('CREATE TEMP TABLE apportionment_ledger
                           (LIKE public.apportionment_ledger INCLUDING DEFAULTS INCLUDING INDEXES)');
            DB::statement('CREATE TEMP TABLE apportionment_ledger_scopes
                           (LIKE public.apportionment_ledger_scopes INCLUDING DEFAULTS INCLUDING INDEXES)');
            DB::statement('CREATE TEMP TABLE autoscale_worker_leases
                           (LIKE public.autoscale_worker_leases INCLUDING DEFAULTS INCLUDING INDEXES)');
            DB::statement('CREATE TEMP TABLE jurisdiction_adjacency_parents
                           (LIKE public.jurisdiction_adjacency_parents INCLUDING DEFAULTS INCLUDING INDEXES)');

            $runId = (string) Str::uuid();
            DB::table('autoscale_runs')->insert([
                'id' => $runId, 'status' => 'mapping', 'adm_max' => 6,
                'sized_parents' => 0, 'sized_leaves' => 0,
                'singles_total' => 0, 'singles_done' => 0,
                'sweeps_total' => 0, 'sweeps_done' => 0, 'review_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $body(AutoscaleRun::query()->findOrFail($runId));
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /** One lease; returns its id (the lane's token). */
    private function mkLease(AutoscaleRun $run, ?int $pid, int $lastSeenMinutesAgo): string
    {
        $id = (string) Str::uuid();
        DB::table('autoscale_worker_leases')->insert([
            'id' => $id, 'run_id' => (string) $run->id, 'lane' => 'auto',
            'pg_backend_pid' => $pid,
            'claim_type' => 'scope', 'claim_label' => 'test scope',
            'claim_started_at' => now()->subMinutes($lastSeenMinutesAgo),
            'started_at' => now()->subMinutes($lastSeenMinutesAgo + 1),
            'last_seen_at' => now()->subMinutes($lastSeenMinutesAgo),
        ]);

        return $id;
    }

    /**
     * One sweep header (status $headerStatus) + its running root scope held
     * by $token.
     *
     * @return array{0:string,1:string,2:string} [legislature_id, scope_id, scope_jurisdiction_id]
     */
    private function mkScope(string $token, int $retryCount, int $updatedMinutesAgo, ?string $reason = null, string $headerStatus = 'running'): array
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
            'started_at' => $headerStatus === 'pending' ? null : now()->subMinutes($updatedMinutesAgo),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $scopeId = (string) Str::uuid();
        DB::table('apportionment_ledger_scopes')->insert([
            'id' => $scopeId, 'legislature_id' => $legId,
            'scope_jurisdiction_id' => $jid,
            'depth' => 0, 'walk_position' => 1, 'seat_budget' => 20,
            'status' => 'running', 'claim_token' => $token,
            'retry_count' => $retryCount, 'reason' => $reason,
            'started_at' => now()->subMinutes($updatedMinutesAgo),
            'created_at' => now()->subMinutes($updatedMinutesAgo),
            'updated_at' => now()->subMinutes($updatedMinutesAgo),
        ]);

        return [$legId, $scopeId, $jid];
    }
}
