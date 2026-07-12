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
 *   (4) FINE-TUNING NEVER BUYS BREAKS (Uttar Pradesh shatter regression,
 *       2026-07-08 rematch): a contiguous map already inside the acceptable
 *       band must never be shattered to polish its deviation toward zero —
 *       breaks are a last resort for BAD balance, not a finishing tool.
 *
 *   (5) COMPARATOR PIN for the same regression: a shattered near-zero map
 *       must LOSE to a decent contiguous map under scoreBeats(), while the
 *       Canada-class rescue (±32% → ~0% with one break) must still WIN.
 *
 *   (6) NO BALLAST ATTACHMENT (Zhoushan, round-2 rematch): a mainland bin in
 *       the override window (4.5..5.0 fracs) rounds to the floor legally and
 *       must never grab a far-away orphan island to cross 5.0; orphans attach
 *       to the nearest SHORE (closest approach), never the nearest centroid.
 *
 *   (7) REPS-PER-DISTRICT EQUALITY (round-3 tuning): within acceptable
 *       balance and equal contiguity, the most-equal seat mix wins (6/6/6 at
 *       2.6% beats 7/6/5 at 0.4%) — but mix equality never buys unacceptable
 *       balance or a contiguity break.
 *
 *   (8) BOUNDED REBALANCER (São Paulo hang, round-3): breakRebalance must
 *       converge on a 600-child scope in bounded work — worst-bin-focused
 *       exchanges, capped member lists, heavy checks only on a shortlist.
 *
 *   (9) COMPACTNESS RELAXED (round-4, operator's full-81 review): within
 *       acceptability and at equal mix, a full 1pp equality band outranks
 *       shape — but a fractional equality edge still never buys a snake.
 *
 *   (10) POST-ATTACHMENT REBALANCE (round-5, Tanzania/France class): island
 *        attachment shifts population after all scoring; a clean-only
 *        rebalance over the final bins must compensate before Webster.
 *
 *   (11) SEQUENTIAL BUILDER (round-5, Russia/Egypt class): the operator's
 *        one-district-at-a-time method as a generator must construct
 *        canonical whole-seat districts exactly on a uniform chain.
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

    // ─── (4) Fine-tuning never buys contiguity breaks ───────────────────────

    public function test_fine_tuning_never_buys_contiguity_breaks(): void
    {
        $this->onLivePg(function () {
            // Chain of 10 near-uniform children (total 10.0M), budget 10, quota 1M.
            // The best contiguous cut is 5.02M/4.98M — 0.4% on both districts.
            // A break could polish that to 0.0%; the doctrine forbids it: within
            // the acceptable band, contiguity outranks polish (the Uttar Pradesh
            // shatter regression — Automatic Draft 2 broke EVERY district for ~0%).
            $pops = [103, 99, 101, 97, 102, 98, 103, 99, 101, 97];
            [$leg, $scopeId] = $this->makeScopeFixture('zzdd', $pops, 10_000, 10);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 10, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, $result['districts_created']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)
                ->whereNull('deleted_at')
                ->get(['id', 'seats', 'actual_population', 'is_contiguous']);

            $quota = 10_000_000 / 10;
            foreach ($districts as $d) {
                $this->assertTrue(
                    (bool) $d->is_contiguous,
                    'a 0.4% contiguous map must never be shattered for polish'
                );
                $dev = abs($d->actual_population / $d->seats - $quota) / $quota;
                $this->assertLessThan(0.01, $dev);
            }
        });
    }

    // ─── (5) Comparator pin: shatter loses, rescue wins ─────────────────────

    public function test_shatter_never_beats_a_decent_contiguous_map(): void
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'scoreBeats');
        $m->setAccessible(true);

        // The Uttar Pradesh regression, as scores: decent contiguous (avg 1.3%,
        // worst 3.8%) vs shattered near-zero (6 broken districts).
        $contiguous = [
            'avg_deviation_pct' => 1.3, 'max_deviation_pct' => 3.8,
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0, 'neck_count' => 0, 'seat_spread' => 0,
            'avg_rg_sq' => 2.0, 'avg_droop_threshold' => 0.118,
        ];
        $shattered = [
            'avg_deviation_pct' => 0.1, 'max_deviation_pct' => 0.3,
            'non_contiguous_count' => 6, 'fragment_gap' => 14.0, 'neck_count' => 0, 'seat_spread' => 0,
            'avg_rg_sq' => 1.4, 'avg_droop_threshold' => 0.118,
        ];
        $this->assertFalse(
            $m->invoke($svc, $shattered, $contiguous),
            'a shattered near-zero map must never beat a decent contiguous map'
        );
        $this->assertTrue(
            $m->invoke($svc, $contiguous, $shattered),
            'the decent contiguous map wins on fewest breaks within acceptability'
        );

        // The West Bengal band-edge regression (round-2 rematch): BOTH maps are
        // acceptable (≤4% avg / ≤10% max), so nudging 2.1% down to 1.5% must never
        // buy the north/south teleport.
        $wbContiguous = [
            'avg_deviation_pct' => 2.1, 'max_deviation_pct' => 5.0,
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0, 'neck_count' => 1, 'seat_spread' => 0,
            'avg_rg_sq' => 2.4, 'avg_droop_threshold' => 0.118,
        ];
        $wbBroken = [
            'avg_deviation_pct' => 1.5, 'max_deviation_pct' => 1.9,
            'non_contiguous_count' => 1, 'fragment_gap' => 3.5, 'neck_count' => 0, 'seat_spread' => 0,
            'avg_rg_sq' => 1.9, 'avg_droop_threshold' => 0.118,
        ];
        $this->assertTrue(
            $m->invoke($svc, $wbContiguous, $wbBroken),
            'within acceptability, a band-edge equality gain must never buy a break'
        );

        // Pinch points decide between otherwise-equal contiguous maps.
        $pinched = $contiguous;
        $pinched['neck_count'] = 2;
        $this->assertTrue(
            $m->invoke($svc, $contiguous, $pinched),
            'fewer necks wins when balance and contiguity tie'
        );

        // The Egypt probe (round-6, real geography): a 7+7+7+7 at 0.81% with ONE
        // unavoidable Nile-chain pinch must beat the 9+8+6+5 at 1.40% with none —
        // a pinch is shape spirit and never vetoes reps-equality or an equality band.
        $canonical = [
            'avg_deviation_pct' => 0.81, 'max_deviation_pct' => 1.62,
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0, 'neck_count' => 1,
            'seat_spread' => 0, 'avg_rg_sq' => 1.542, 'avg_droop_threshold' => 0.125,
        ];
        $unevenNeckless = [
            'avg_deviation_pct' => 1.40, 'max_deviation_pct' => 2.69,
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0, 'neck_count' => 0,
            'seat_spread' => 4, 'avg_rg_sq' => 1.422, 'avg_droop_threshold' => 0.130,
        ];
        $this->assertTrue(
            $m->invoke($svc, $canonical, $unevenNeckless),
            'the equal-mix canonical map wins despite one pinch point (the Egypt probe)'
        );
        // …while at equal balance and mix, fewer pinches still decide before shape.
        $twoNecks = $canonical;
        $twoNecks['neck_count'] = 2;
        $this->assertTrue(
            $m->invoke($svc, $canonical, $twoNecks),
            'pinches still decide within the shape cluster'
        );

        // The Canada-class rescue must still win: a break IS worth escaping ±32%.
        $catastrophe = [
            'avg_deviation_pct' => 20.0, 'max_deviation_pct' => 32.3,
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0, 'neck_count' => 0, 'seat_spread' => 0,
            'avg_rg_sq' => 1.0, 'avg_droop_threshold' => 0.118,
        ];
        $rescue = [
            'avg_deviation_pct' => 0.2, 'max_deviation_pct' => 0.5,
            'non_contiguous_count' => 1, 'fragment_gap' => 2.0, 'neck_count' => 0, 'seat_spread' => 0,
            'avg_rg_sq' => 1.6, 'avg_droop_threshold' => 0.118,
        ];
        $this->assertTrue(
            $m->invoke($svc, $rescue, $catastrophe),
            'escaping ±32% with one break must still win'
        );
    }

    // ─── (7) Equal seat mix buys balance imperfection within acceptability ──

    public function test_equal_seat_mix_buys_balance_imperfection_within_acceptability(): void
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'scoreBeats');
        $m->setAccessible(true);

        $base = [
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0, 'neck_count' => 0,
            'avg_droop_threshold' => 0.13,
        ];
        // Round-3 tuning: "it is giving up reps per district balance long before
        // it has to." A 6/6/6 map at 2.6% must beat a 7/6/5 map at 0.4% — both
        // are inside the acceptability threshold, so the even mix decides.
        $equalMix  = $base + ['avg_deviation_pct' => 2.6, 'max_deviation_pct' => 3.9, 'seat_spread' => 0, 'avg_rg_sq' => 2.3];
        $unevenMix = $base + ['avg_deviation_pct' => 0.4, 'max_deviation_pct' => 0.8, 'seat_spread' => 2, 'avg_rg_sq' => 1.9];
        $this->assertTrue(
            $m->invoke($svc, $equalMix, $unevenMix),
            'within acceptability, the even seat mix wins over tighter balance'
        );
        $this->assertFalse($m->invoke($svc, $unevenMix, $equalMix));

        // But mix equality never buys UNACCEPTABLE balance…
        $equalButUnacceptable = $base + ['avg_deviation_pct' => 4.6, 'max_deviation_pct' => 6.0, 'seat_spread' => 0, 'avg_rg_sq' => 2.0];
        $this->assertTrue(
            $m->invoke($svc, $unevenMix, $equalButUnacceptable),
            'an even mix past the acceptability threshold still loses'
        );

        // …and never buys a contiguity break.
        $equalButBroken = $equalMix;
        $equalButBroken['non_contiguous_count'] = 1;
        $equalButBroken['fragment_gap'] = 1.5;
        $this->assertTrue(
            $m->invoke($svc, $unevenMix, $equalButBroken),
            'an even mix bought with a break still loses'
        );
    }

    // ─── (6) Orphan islands attach to the nearest shore, never as ballast ───

    public function test_orphan_island_attaches_to_nearest_shore_not_as_ballast(): void
    {
        $this->onLivePg(function () {
            // Three components: a 10-cell mainland chain (93k each — 4.70 fracs,
            // legally rounds to the 5-seat floor), a 5-cell block (240k each —
            // 6.07 fracs), and an orphan island floating 0.3° off the BLOCK's
            // north end. The Zhoushan ballast bug: the sub-5.0 chain used to grab
            // the island (~8° away) as population ballast to cross the floor.
            // Doctrine: the chain keeps its floor_override rounding and the
            // island joins the shore it actually sits next to.
            $rootId  = $this->makeJurisdiction('zzde-0-root', 'Test Root', 0, null, $this->square(0, 0, 20, 20), 2_175_000);
            $scopeId = $this->makeJurisdiction('zzde-1-scope', 'Test Scope', 1, $rootId, $this->square(0, 0, 15, 10), 2_175_000);
            for ($i = 0; $i < 10; $i++) {
                $this->makeJurisdiction("zzde-2-chain-{$i}", "Chain {$i}", 2, $scopeId, $this->square($i, 0, $i + 1, 1), 93_000);
            }
            for ($i = 0; $i < 5; $i++) {
                $this->makeJurisdiction("zzde-2-block-{$i}", "Block {$i}", 2, $scopeId, $this->square(12, 2 + $i, 13, 3 + $i), 240_000);
            }
            $islandId = $this->makeJurisdiction('zzde-2-island', 'Island', 2, $scopeId, $this->square(12, 7.3, 13, 8.3), 45_000);
            $leg = $this->makeLegislature($rootId, 11);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 11, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, $result['districts_created']);

            $islandDistrict = DB::table('legislature_district_jurisdictions as ldj')
                ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
                ->where('ldj.jurisdiction_id', $islandId)
                ->whereNull('ld.deleted_at')
                ->first(['ld.id', 'ld.seats']);
            $memberCount = (int) DB::table('legislature_district_jurisdictions')
                ->where('district_id', $islandDistrict->id)->count();
            $this->assertSame(6, $memberCount, 'island joins the 5-cell block next door, never the far chain');
            $this->assertSame(6, (int) $islandDistrict->seats);

            $chainDistrict = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)
                ->whereNull('deleted_at')
                ->where('id', '!=', $islandDistrict->id)
                ->sole();
            $this->assertSame(5, (int) $chainDistrict->seats, 'the 4.70-frac chain rounds to the floor on its own');
            $this->assertTrue((bool) $chainDistrict->floor_override, 'flagged for audit, not "fixed" with ballast');
        });
    }

    // ─── (9) A full point of equality outranks compactness — a fraction never ─

    public function test_equality_point_beats_compactness_but_fractions_do_not(): void
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'scoreBeats');
        $m->setAccessible(true);

        $base = [
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0, 'neck_count' => 0,
            'seat_spread' => 0, 'avg_droop_threshold' => 0.13,
        ];
        // Round-4 relaxation: within acceptability and at equal mix, a config a
        // full 1pp band better on equality beats a more compact one…
        $balanced = $base + ['avg_deviation_pct' => 1.2, 'max_deviation_pct' => 2.1, 'avg_rg_sq' => 2.6];
        $compact  = $base + ['avg_deviation_pct' => 2.4, 'max_deviation_pct' => 3.0, 'avg_rg_sq' => 1.4];
        $this->assertTrue(
            $m->invoke($svc, $balanced, $compact),
            'a full equality point buys shape (the operator round-4 relaxation)'
        );

        // …but within the same 1pp band, compactness still decides — the São
        // Paulo snake can never be bought with a fraction of a point.
        $snake = $base + ['avg_deviation_pct' => 0.4, 'max_deviation_pct' => 0.9, 'avg_rg_sq' => 3.1];
        $block = $base + ['avg_deviation_pct' => 0.8, 'max_deviation_pct' => 1.2, 'avg_rg_sq' => 1.5];
        $this->assertTrue(
            $m->invoke($svc, $block, $snake),
            'within a band, the compact shape wins regardless of a fractional equality edge'
        );
    }

    // ─── (8) breakRebalance stays bounded on São Paulo-scale scopes ─────────

    public function test_break_rebalance_bounded_on_fine_grained_scopes(): void
    {
        // Round-3 São Paulo hang: 637 children × O(n²) exchange pairs × per-
        // candidate BFS wedged the scope for 10+ minutes. This pin drives the
        // rebalancer directly with a 600-child chain split badly (360/240) and
        // requires convergence to the integer targets within seconds of work —
        // the bounded two-phase candidate machinery, exercised at real scale.
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'breakRebalance');
        $m->setAccessible(true);

        $n = 600;
        $childById = []; $centroids = []; $adj = []; $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $id = sprintf('c%04d', $i);
            $ids[] = $id;
            $childById[$id] = (object) ['population' => 100_000, 'fractional_seats' => 0.02];
            $centroids[$id] = ['x' => $i * 0.01, 'y' => 0.0];
            $adj[$id] = [];
        }
        for ($i = 1; $i < $n; $i++) {
            $adj[$ids[$i - 1]][] = $ids[$i];
            $adj[$ids[$i]][]     = $ids[$i - 1];
        }
        // 60M total, budget 12 → quota 5M; bins split 36M/24M (targets 7/5 →
        // 35M/25M) so the rebalancer must move ~1M (10 children) to converge.
        $bins = [array_slice($ids, 0, 360), array_slice($ids, 360)];
        // fractional_seats must reflect the scope quota for the frac guards.
        foreach ($childById as $c) {
            $c->fractional_seats = 100_000 / 5_000_000;
        }

        $start  = microtime(true);
        $result = $m->invoke($svc, $bins, $childById, $centroids, $adj, 5_000_000.0, 12, 5, 9, 9.5, 5.0);
        $elapsed = microtime(true) - $start;

        $this->assertCount(2, $result);
        $pops = array_map(fn($b) => count($b) * 100_000, $result);
        $targets = [7 * 5_000_000, 5 * 5_000_000];
        rsort($pops);
        foreach ($pops as $bi => $p) {
            $this->assertLessThanOrEqual(
                0.021,
                abs($p - $targets[$bi]) / $targets[$bi],
                'rebalancer converges to within 2% of the integer targets'
            );
        }
        $this->assertLessThan(30.0, $elapsed, 'bounded candidate machinery — never minutes per scope');
    }

    // ─── (10) Island attachment is compensated before Webster runs ──────────

    public function test_island_attachment_is_rebalanced_before_webster(): void
    {
        $this->onLivePg(function () {
            // The Tanzania mechanism: a mainland chain splits beautifully, then
            // the offshore island lands on one side AFTER all scoring — and
            // before round 5, nothing ever rebalanced. Mainland: 10×120k,
            // 4×60k (the fine-grained middle where the border falls), 10×120k;
            // island 120k off the east end. Budget 10, quota 276k. Without the
            // post-attachment rebalance both districts sit at ±4.3%; with it, a
            // 60k border cell crosses back and both land exactly on quota.
            $rootId  = $this->makeJurisdiction('zzdf-0-root', 'Test Root', 0, null, $this->square(0, 0, 30, 10), 2_760_000);
            $scopeId = $this->makeJurisdiction('zzdf-1-scope', 'Test Scope', 1, $rootId, $this->square(0, 0, 26, 2), 2_760_000);
            for ($i = 0; $i < 24; $i++) {
                $pop = ($i >= 10 && $i <= 13) ? 60_000 : 120_000;
                $this->makeJurisdiction("zzdf-2-cell-{$i}", "Cell {$i}", 2, $scopeId, $this->square($i, 0, $i + 1, 1), $pop);
            }
            $this->makeJurisdiction('zzdf-2-island', 'Island', 2, $scopeId, $this->square(24.4, 0, 25.4, 1), 120_000);
            $leg = $this->makeLegislature($rootId, 10);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 10, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, $result['districts_created']);

            $quota = 2_760_000 / 10;
            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'actual_population']);
            $this->assertSame([5, 5], $districts->pluck('seats')->sort()->values()->all());
            foreach ($districts as $d) {
                $dev = abs($d->actual_population / $d->seats - $quota) / $quota;
                $this->assertLessThan(
                    0.021,
                    $dev,
                    'the mainland must compensate for the island AFTER attachment (was ±4.3% before round 5)'
                );
            }
        });
    }

    // ─── (11) The sequential builder constructs canonical districts exactly ──

    public function test_sequential_builder_constructs_canonical_districts(): void
    {
        // Russia-class: 9+9+9+9 is trivial to construct one district at a time
        // but nearly unreachable for round-robin growth under the giant guard.
        // Drive the builder directly: a 36-cell uniform chain toward [9,9,9,9]
        // must produce exactly four consecutive nine-cell districts.
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'sequentialBuild');
        $m->setAccessible(true);

        $n = 36;
        $childById = []; $centroids = []; $adj = []; $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $id = sprintf('c%04d', $i);
            $ids[] = $id;
            $childById[$id] = (object) ['population' => 100_000, 'fractional_seats' => 1.0];
            $centroids[$id] = ['x' => $i * 0.5, 'y' => 0.0];
            $adj[$id] = [];
        }
        for ($i = 1; $i < $n; $i++) {
            $adj[$ids[$i - 1]][] = $ids[$i];
            $adj[$ids[$i]][]     = $ids[$i - 1];
        }

        $bins = $m->invoke($svc, $ids, $childById, $adj, $centroids, 36, 4, 100_000.0, 9.5, 5, 9, true);
        $this->assertNotNull($bins);
        $this->assertCount(4, $bins);
        foreach ($bins as $bin) {
            $this->assertCount(9, $bin, 'each district lands exactly on its whole-seat target');
            $idx = array_map(fn($id) => (int) substr($id, 1), $bin);
            $this->assertSame(8, max($idx) - min($idx), 'each district is a contiguous run of the chain');
        }

        // Egypt-class fat atoms (2.5-seat governorates on a chain): the round-6
        // remainder-aware builder must produce contiguous, near-canonical
        // districts in BOTH build orders — no degenerate remainders.
        $pops = [250, 230, 220, 200, 190, 180, 170, 160, 150, 140, 130, 120, 110, 100, 80, 70];
        $n = count($pops);
        $total = array_sum($pops) * 1000;           // 2,500,000
        $budget = 25; $quota = $total / $budget;    // 100k
        $childById = []; $centroids = []; $adj = []; $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $id = sprintf('e%04d', $i);
            $ids[] = $id;
            $childById[$id] = (object) ['population' => $pops[$i] * 1000, 'fractional_seats' => $pops[$i] * 1000 / $quota];
            $centroids[$id] = ['x' => $i * 0.5, 'y' => 0.0];
            $adj[$id] = [];
        }
        for ($i = 1; $i < $n; $i++) {
            $adj[$ids[$i - 1]][] = $ids[$i];
            $adj[$ids[$i]][]     = $ids[$i - 1];
        }
        foreach ([true, false] as $bigFirst) {
            $bins = $m->invoke($svc, $ids, $childById, $adj, $centroids, $budget, 4, (float) $quota, 9.5, 5, 9, $bigFirst);
            $this->assertNotNull($bins, 'fat-atom chain must not degenerate');
            $this->assertCount(4, $bins);
            foreach ($bins as $bin) {
                $this->assertNotEmpty($bin);
                $idx = array_map(fn($id) => (int) substr($id, 1), $bin);
                $this->assertSame(count($bin) - 1, max($idx) - min($idx), 'every district is a contiguous run');
                $binPop = array_sum(array_map(fn($id) => $childById[$id]->population, $bin));
                $est = (int) round($binPop / $quota);
                $this->assertGreaterThanOrEqual(5, $est, 'district rounds to at least the floor');
                $this->assertLessThanOrEqual(9, $est, 'district rounds to at most the ceiling');
            }
        }
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
