<?php

namespace Tests\Constitutional;

use App\Models\AutoscaleRun;
use App\Support\AutoscaleClaims;
use App\Support\HostCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the heavy-lane claim cap (operator ruling 2026-07-21).
 *
 * The est-2 tail collapse proved that a consecutive block of mega-geometry
 * scopes captures the whole worker pool at once (rate 20.8k/h → ~1k/h, two
 * OOM episodes). The ruling: scopes with area_tier ≥ HEAVY_TIER form a
 * HEAVY LANE holding at most 20% of worker threads; light workers keep
 * flying. THE DRAIN RULE: when no light work remains pending, the cap lifts
 * and every worker may take heavy remainder.
 *
 * Pins: (1) with the heavy pool full and light work pending, the next claim
 * takes the LIGHT scope even though heavy scopes hold earlier positions;
 * (2) with light drained, heavy claims flow past the cap; (3) the cap
 * arithmetic (20%, floor 1).
 *
 * If an edit breaks these, the edit is the constitutional violation — fix
 * the edit, not the test.
 */
class HeavyLaneClaimTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_heavy_lane';

    public function test_cap_arithmetic(): void
    {
        $this->assertSame(
            max(1, (int) ceil(0.2 * HostCapacity::autoscaleWorkers())),
            AutoscaleClaims::heavyWorkerCap()
        );
        $this->assertGreaterThanOrEqual(1, AutoscaleClaims::heavyWorkerCap());
    }

    public function test_full_heavy_pool_routes_the_next_claim_to_light_work(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            // Heavy pool exactly at the cap; a light scope pending at a LATER
            // position than the pending heavy one. The claim must skip the
            // heavy scope and take the light one.
            $this->fillHeavyPool($run, AutoscaleClaims::heavyWorkerCap());
            $pendingHeavy = $this->mkScope($run, 4, 'pending', position: 1);
            $pendingLight = $this->mkScope($run, 1, 'pending', position: 1000);

            $claim = $this->claimScope($run);

            $this->assertNotNull($claim, 'light work must remain claimable while the heavy pool is full');
            $this->assertSame($pendingLight, $claim['scope_id'], 'the claim must route past the earlier-position heavy scope to light work');
        });
    }

    public function test_drained_light_work_lifts_the_cap(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            // Heavy pool at the cap and ONLY heavy work pending — the drain
            // rule lifts the cap so the remainder still gets done.
            $this->fillHeavyPool($run, AutoscaleClaims::heavyWorkerCap());
            $pendingHeavy = $this->mkScope($run, 5, 'pending', position: 1);

            $claim = $this->claimScope($run);

            $this->assertNotNull($claim, 'heavy remainder must be claimable once light work is drained');
            $this->assertSame($pendingHeavy, $claim['scope_id']);
        });
    }

    public function test_below_cap_heavy_claims_by_position(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            // Heavy pool BELOW the cap: normal position order holds — the
            // earlier heavy scope wins over the later light one.
            $this->fillHeavyPool($run, AutoscaleClaims::heavyWorkerCap() - 1);
            $pendingHeavy = $this->mkScope($run, 4, 'pending', position: 1);
            $this->mkScope($run, 1, 'pending', position: 1000);

            $claim = $this->claimScope($run);

            $this->assertNotNull($claim);
            $this->assertSame($pendingHeavy, $claim['scope_id'], 'below the cap the heavy lane claims by plain position order');
        });
    }

    public function test_topdown_lane_claims_the_highest_position(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            $low  = $this->mkScope($run, 1, 'pending', position: 1);
            $high = $this->mkScope($run, 1, 'pending', position: 900000);

            $claim = $this->claimScope($run, 'topdown');

            $this->assertNotNull($claim);
            $this->assertSame($high, $claim['scope_id'], 'the top-down lane works the queue from the top');

            // The auto lane still takes the bottom.
            $claim2 = $this->claimScope($run, 'auto');
            $this->assertSame($low, $claim2['scope_id'], 'the auto lane keeps bottom-up order');
        });
    }

    public function test_topdown_lane_respects_the_global_heavy_cap(): void
    {
        $this->onLivePg(function (AutoscaleRun $run): void {
            // Heavy pool full; the TOP of the queue is a heavy scope. The
            // top-down claim must skip it and take the highest-position
            // LIGHT scope instead — one memory bound binds across lanes.
            $this->fillHeavyPool($run, AutoscaleClaims::heavyWorkerCap());
            $this->mkScope($run, 5, 'pending', position: 900000);
            $lightTop = $this->mkScope($run, 1, 'pending', position: 800000);

            $claim = $this->claimScope($run, 'topdown');

            $this->assertNotNull($claim);
            $this->assertSame($lightTop, $claim['scope_id'], 'a capped top-down worker takes the highest-position light work');
        });
    }

    // ── fixture plumbing ────────────────────────────────────────────────────

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        config(['cga.autoscale_precompute' => 'lazy']);   // bypass the global precompute gate
        $conn->beginTransaction();

        try {
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

    /** Insert one sweep item + its scope; returns the scope id. */
    private function mkScope(AutoscaleRun $run, int $areaTier, string $status, int $position): string
    {
        $itemId = (string) Str::uuid();
        DB::table('autoscale_items')->insert([
            'id' => $itemId, 'run_id' => $run->id,
            'legislature_id' => (string) Str::uuid(), 'jurisdiction_id' => (string) Str::uuid(),
            'adm_level' => 5, 'kind' => 'sweep', 'status' => 'running',
            'position' => $position, 'child_count' => 0, 'area_tier' => $areaTier,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $scopeId = (string) Str::uuid();
        DB::table('autoscale_scopes')->insert([
            'id' => $scopeId, 'run_id' => $run->id, 'item_id' => $itemId,
            'legislature_id' => (string) Str::uuid(), 'scope_jurisdiction_id' => (string) Str::uuid(),
            'depth' => 0, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $scopeId;
    }

    /** N heavy scopes already RUNNING — the occupied heavy pool. */
    private function fillHeavyPool(AutoscaleRun $run, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->mkScope($run, 5, 'running', position: 10000 + $i);
        }
    }

    private function claimScope(AutoscaleRun $run, string $lane = 'auto'): ?array
    {
        $m = new \ReflectionMethod(AutoscaleClaims::class, 'claimScope');
        $m->setAccessible(true);

        return $m->invoke(null, $run, (string) Str::uuid(), $lane);
    }
}
