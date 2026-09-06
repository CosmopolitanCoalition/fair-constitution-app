<?php

namespace Tests\Constitutional;

use App\Console\Commands\ProvisionPumpCommand;
use App\Models\ProvisionRun;
use App\Support\ProvisionClaims;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — THE STEP 4 ENGINE (Wave 6): the claim ladder over
 * provision_ledger and the reclaim rule. Runs on a synthetic ledger inside one
 * transaction on the live PostgreSQL (the claims use FOR UPDATE SKIP LOCKED,
 * which SQLite cannot). Skips without a live database.
 *
 *  1. shell batches claim first, then units; a lane never idles while either
 *     rung holds work;
 *  2. THE TWO-ENDED DRAIN: a topdown lane takes the largest est_cost first, a
 *     bottomup lane the smallest;
 *  3. a running row held by no lease returns to pending on reclaim; three
 *     reclaims park it in review;
 *  4. skipped rows (the zero rule) are never claimed.
 */
class ProvisionEnginePinTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_provision_pin';

    public function test_the_ladder_drains_from_both_ends_and_reclaims_dead_lanes(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        if (! \Illuminate\Support\Facades\Schema::hasTable('provision_ledger')) {
            DB::setDefaultConnection($original);
            $this->markTestSkipped('provision_ledger not migrated on this box');
        }

        // LIVE-BOX GUARD (mirrors AutoscalePinTest): this pin parks the pending
        // pile to assert claim order, which deadlocks against a live run's
        // lanes. Skip while a run is active; it runs clean on CI and a fresh box.
        $liveRun = ProvisionRun::query()
            ->whereIn('status', ['queued', 'running', 'halted'])
            ->exists();
        if ($liveRun) {
            DB::setDefaultConnection($original);
            $this->markTestSkipped('a Step 4 run is live on this box — the claim-order pin needs a quiet ledger');
        }

        $conn->beginTransaction();
        try {
            // A clean synthetic pile: the live rows are parked out of reach.
            DB::statement("UPDATE provision_ledger SET status = 'parked_pin' WHERE status IN ('pending', 'running')");

            $run = ProvisionRun::create(['status' => ProvisionRun::STATUS_RUNNING, 'started_at' => now()]);
            $rows = [];
            foreach ([10, 20, 30, 40] as $cost) {
                $rows[] = ['legislature_id' => (string) Str::uuid(), 'jurisdiction_id' => (string) Str::uuid(),
                    'est_cost' => $cost, 'stage' => 1, 'status' => 'pending', 'updated_at' => now()];
            }
            $shell = ['legislature_id' => (string) Str::uuid(), 'jurisdiction_id' => (string) Str::uuid(),
                'est_cost' => 5, 'stage' => 0, 'status' => 'pending', 'updated_at' => now()];
            $skipped = ['legislature_id' => (string) Str::uuid(), 'jurisdiction_id' => (string) Str::uuid(),
                'est_cost' => 0, 'stage' => 0, 'status' => 'skipped', 'updated_at' => now()];
            DB::table('provision_ledger')->insert(array_merge($rows, [$shell, $skipped]));

            $top    = (string) Str::uuid();
            $bottom = (string) Str::uuid();

            // 1. The shell batch outranks every unit.
            $first = ProvisionClaims::next($run, $top, ProvisionClaims::LANE_TOPDOWN);
            $this->assertSame('shell_batch', $first['type']);
            $this->assertSame(1, $first['count'], 'the one stage-0 row; the skipped row is never claimed');

            // 2. Two ends of the pile.
            $t = ProvisionClaims::next($run, $top, ProvisionClaims::LANE_TOPDOWN);
            $b = ProvisionClaims::next($run, $bottom, ProvisionClaims::LANE_BOTTOMUP);
            $this->assertSame('unit', $t['type']);
            $this->assertSame(40, $t['est_cost'], 'topdown takes the largest');
            $this->assertSame(10, $b['est_cost'], 'bottomup takes the smallest');

            $t2 = ProvisionClaims::next($run, $top, ProvisionClaims::LANE_TOPDOWN);
            $this->assertSame(30, $t2['est_cost'], 'then the next largest');

            // 4. The skipped row stays untouched.
            $this->assertSame('skipped', DB::table('provision_ledger')->where('legislature_id', $skipped['legislature_id'])->value('status'));

            // 3. Reclaim: the running rows hold tokens with no lease → pending, retry +1.
            $reclaimed = ProvisionPumpCommand::reclaimDeadRows();
            $this->assertSame(4, $reclaimed, 'the shell batch row and the three claimed units return');
            $this->assertSame(0, (int) DB::table('provision_ledger')->where('status', 'running')->count());
            $this->assertSame(1, (int) DB::table('provision_ledger')->where('legislature_id', $t['legislature_id'])->value('retry_count'));

            // Three strikes park the row.
            DB::table('provision_ledger')->where('legislature_id', $t['legislature_id'])
                ->update(['status' => 'running', 'claim_token' => (string) Str::uuid(), 'retry_count' => 2]);
            ProvisionPumpCommand::reclaimDeadRows();
            $this->assertSame('review', DB::table('provision_ledger')->where('legislature_id', $t['legislature_id'])->value('status'));

            // A live lease keeps its row (the lane is alive, its backend present).
            $token = (string) Str::uuid();
            DB::statement('INSERT INTO provision_worker_leases (id, run_id, lane, pg_backend_pid, started_at, last_seen_at)
                           VALUES (?::uuid, ?::uuid, ?, pg_backend_pid(), now(), now())', [$token, (string) $run->id, 'topdown']);
            DB::table('provision_ledger')->where('legislature_id', $b['legislature_id'])
                ->update(['status' => 'running', 'claim_token' => $token]);
            $this->assertSame(0, ProvisionPumpCommand::reclaimDeadRows(), 'a live lane is never reclaimed');
            $this->assertSame('running', DB::table('provision_ledger')->where('legislature_id', $b['legislature_id'])->value('status'));
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($original);
        }
    }
}
