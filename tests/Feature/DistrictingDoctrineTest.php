<?php

namespace Tests\Feature;

use App\Services\DistrictingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Districting doctrine pins — the operator's manual-method objective function
 * (ruling 2026-07-08), mechanized in DistrictingService and pinned here so the
 * PROTECTED algorithm can never silently regress to the old behavior:
 *
 *   (1) LUMPY SCOPE (Canada class): when no contiguous split can balance, the
 *       engine must BREAK contiguity to reach the integer seat targets —
 *       "I have to break contiguity in order to be above the floor and below
 *       the ceiling. Population balance last [to be sacrificed]."
 *       Old engine: contiguity-first comparator + equal-population targets
 *       left ±20-32% deviations (live Canada: ±32.32%).
 *
 *   (2) UNEVEN INTEGER TARGETS: bins must land on whole seat multiples
 *       (9×q, 6×q …), not on the uniform pop/k midpoint — "look at the optimal
 *       breakdown of reps per district first … 6.55 vs 8.12, I'm taking the 8".
 *       Old engine: equal-target refinement pulled every candidate to the
 *       750/750 midpoint, then bounded Webster left ~7% on both districts.
 *
 *   (3) COMPACT SHAPE AT EQUAL BALANCE (São Paulo class): among equally
 *       balanced contiguous configurations the compact one must win, and the
 *       compact pass must be able to reshape coarse-grained scopes via
 *       balance-neutral pairwise exchanges (single moves always breach the
 *       deviation cap when each child is a double-digit share of its bin).
 *
 * Live-pg posture (PostGIS adjacency + real Step 12 inserts) — per-test
 * transaction, rolled back; never RefreshDatabase.
 */
class DistrictingDoctrineTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_districting_doctrine';

    // ─── (1) Lumpy scope: balance is bought with a contiguity break ─────────

    public function test_lumpy_scope_breaks_contiguity_to_reach_balance(): void
    {
        $this->onLivePg(function () {
            // Chain of 10 children (adjacent unit squares), pops in 100k units:
            // 38-22-3-2-2-4-3-12-13-1 (total 10.0M), budget 10 seats, quota 1M.
            // Best CONTIGUOUS 2-split is 6.0M/4.0M → forced 5+5 seats → ±20%.
            // The doctrine solve swaps the 2.2M child for the 1.2M child across
            // the cut: 5.0M/5.0M → 0% — non-contiguous, and correct.
            $pops = [38, 22, 3, 2, 2, 4, 3, 12, 13, 1];
            [$leg, $scopeId] = $this->makeScopeFixture('zzda', $pops, 100_000, 10);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 10, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, $result['districts_created']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)
                ->whereNull('deleted_at')
                ->get(['id', 'seats', 'actual_population']);

            $quota = 10_000_000 / 10;
            $seats = $districts->pluck('seats')->sort()->values()->all();
            $this->assertSame([5, 5], $seats, 'floor×bins = budget — the seat vector is forced');

            foreach ($districts as $d) {
                $dev = abs($d->actual_population / $d->seats - $quota) / $quota;
                $this->assertLessThan(
                    0.05,
                    $dev,
                    'per-seat deviation must be within 5% — only a deliberate contiguity break achieves this'
                );
            }
        });
    }

    // ─── (2) Uneven integer targets beat the equal-population midpoint ──────

    public function test_bins_land_on_whole_seat_targets_not_equal_midpoints(): void
    {
        $this->onLivePg(function () {
            // Chain of 12: six 150k children then six 100k (total 1.5M),
            // budget 15, quota 100k. The equal-target midpoint (750/750) can
            // only earn a 7+8 Webster split → ~7% on both districts. Integer
            // targeting finds 900k/600k → 9+6 seats → 0%, fully contiguous.
            $pops = array_merge(array_fill(0, 6, 150), array_fill(0, 6, 100));
            [$leg, $scopeId] = $this->makeScopeFixture('zzdb', $pops, 1_000, 15);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 15, null
            );
            $this->assertNull($result['error']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)
                ->whereNull('deleted_at')
                ->get(['id', 'seats', 'actual_population']);

            $quota = 1_500_000 / 15;
            $this->assertSame(15, (int) $districts->sum('seats'), 'the full budget is placed');
            foreach ($districts as $d) {
                $this->assertGreaterThanOrEqual(5, $d->seats, 'constitutional floor');
                $this->assertLessThanOrEqual(9, $d->seats, 'constitutional ceiling');
                $dev = abs($d->actual_population / $d->seats - $quota) / $quota;
                $this->assertLessThan(
                    0.02,
                    $dev,
                    'bins must land on whole seat multiples (old equal-midpoint engine left ~7%)'
                );
            }
        });
    }

    // ─── (3) Equal balance resolves to the compact shape ────────────────────

    public function test_equal_balance_tie_resolves_to_compact_shape(): void
    {
        $this->onLivePg(function () {
            // 4×4 grid of uniform 100k children, budget 10 → two districts of
            // 8 cells, 5 seats each, every 8/8 split has 0% deviation. The
            // comparator must resolve the tie on compactness: half-grid blocks
            // (avg Rg² = 1.5 cell²), not diagonal staircases (≈2.2) or snakes.
            $grid = [];
            for ($gy = 0; $gy < 4; $gy++) {
                for ($gx = 0; $gx < 4; $gx++) {
                    $grid[] = [$gx, $gy];
                }
            }
            [$leg, $scopeId, $cellById] = $this->makeGridFixture('zzdc', $grid, 100_000, 10);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 10, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, $result['districts_created']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)
                ->whereNull('deleted_at')
                ->get(['id', 'seats', 'actual_population', 'is_contiguous']);

            $quota = 1_600_000 / 10;
            $rgSum = 0.0;
            foreach ($districts as $d) {
                $dev = abs($d->actual_population / $d->seats - $quota) / $quota;
                $this->assertLessThan(0.005, $dev, 'every 8/8 grid split balances exactly');
                $this->assertTrue((bool) $d->is_contiguous, 'no break is ever justified here');

                $members = DB::table('legislature_district_jurisdictions')
                    ->where('district_id', $d->id)
                    ->pluck('jurisdiction_id')
                    ->all();
                $this->assertCount(8, $members);

                // Radius of gyration² from the known cell centroids (uniform pops).
                $mx = 0.0; $my = 0.0;
                foreach ($members as $mid) {
                    $mx += $cellById[$mid][0];
                    $my += $cellById[$mid][1];
                }
                $mx /= count($members);
                $my /= count($members);
                $rg = 0.0;
                foreach ($members as $mid) {
                    $rg += ($cellById[$mid][0] - $mx) ** 2 + ($cellById[$mid][1] - $my) ** 2;
                }
                $rgSum += $rg / count($members);
            }
            $avgRg = $rgSum / max(count($districts), 1);
            $this->assertLessThan(
                1.8,
                $avgRg,
                'compact halves (1.5) must win over staircases (~2.2) and snakes — the exchange pass must fire'
            );
        });
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Root → scope → chain of children (adjacent unit squares along the x axis).
     * Returns [$legRow, $scopeId].
     */
    private function makeScopeFixture(string $prefix, array $pops, int $popUnit, int $seats): array
    {
        $n      = count($pops);
        $rootId = $this->makeJurisdiction("{$prefix}-0-root", 'Test Root', 0, null, $this->square(0, 0, $n, 3), array_sum($pops) * $popUnit);
        $scopeId = $this->makeJurisdiction("{$prefix}-1-scope", 'Test Scope', 1, $rootId, $this->square(0, 0, $n, 1), array_sum($pops) * $popUnit);
        foreach ($pops as $i => $p) {
            $this->makeJurisdiction(
                "{$prefix}-2-child-{$i}",
                "Child {$i}",
                2,
                $scopeId,
                $this->square($i, 0, $i + 1, 1),
                $p * $popUnit
            );
        }

        return [$this->makeLegislature($rootId, $seats), $scopeId];
    }

    /**
     * Root → scope → grid of unit-square children.
     * Returns [$legRow, $scopeId, $cellById] where $cellById maps child id → [cx, cy].
     */
    private function makeGridFixture(string $prefix, array $cells, int $pop, int $seats): array
    {
        $total   = count($cells) * $pop;
        $rootId  = $this->makeJurisdiction("{$prefix}-0-root", 'Test Root', 0, null, $this->square(0, 0, 5, 5), $total);
        $scopeId = $this->makeJurisdiction("{$prefix}-1-scope", 'Test Scope', 1, $rootId, $this->square(0, 0, 4, 4), $total);
        $cellById = [];
        foreach ($cells as $i => [$gx, $gy]) {
            $id = $this->makeJurisdiction(
                "{$prefix}-2-cell-{$gx}-{$gy}",
                "Cell {$gx},{$gy}",
                2,
                $scopeId,
                $this->square($gx, $gy, $gx + 1, $gy + 1),
                $pop
            );
            $cellById[$id] = [$gx + 0.5, $gy + 0.5];
        }

        return [$this->makeLegislature($rootId, $seats), $scopeId, $cellById];
    }

    private function makeLegislature(string $jurisdictionId, int $seats): object
    {
        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id'              => $legId,
            'jurisdiction_id' => $jurisdictionId,
            'term_number'     => 1,
            'status'          => 'active',
            'total_seats'     => $seats,
            'type_a_seats'    => $seats,
            'type_b_seats'    => 0,
            'quorum_required' => intdiv($seats, 2) + 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('legislatures')->where('id', $legId)->first();
    }

    /** Insert one live jurisdictions row with PostGIS geometry; returns its id. */
    private function makeJurisdiction(
        string $slug,
        string $name,
        int $admLevel,
        ?string $parentId,
        string $wkt,
        int $population
    ): string {
        $id = (string) Str::uuid();
        DB::statement("
            INSERT INTO jurisdictions (
                id, name, slug, iso_code, adm_level, parent_id, population,
                source, parent_assigned_via, geom, centroid, created_at, updated_at
            ) VALUES (
                ?, ?, ?, 'ZZD', ?, ?, ?,
                'geoboundaries', ?, ST_GeomFromText(?, 4326),
                ST_Centroid(ST_GeomFromText(?, 4326)), NOW(), NOW()
            )
        ", [$id, $name, $slug, $admLevel, $parentId, $population, $parentId ? 'direct' : null, $wkt, $wkt]);

        return $id;
    }

    /** Axis-aligned MULTIPOLYGON rectangle (lon/lat degrees). */
    private function square(float $x0, float $y0, float $x1, float $y1): string
    {
        return sprintf(
            'MULTIPOLYGON(((%1$s %2$s, %3$s %2$s, %3$s %4$s, %1$s %4$s, %1$s %2$s)))',
            $x0, $y0, $x1, $y1
        );
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
