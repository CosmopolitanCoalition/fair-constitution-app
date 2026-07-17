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
 *       750/750 midpoint, then bounded rounding left ~7% on both districts.
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
 *        rebalance over the final bins must compensate before seating.
 *
 *   (11) SEQUENTIAL BUILDER (round-5, Russia/Egypt class): the operator's
 *        one-district-at-a-time method as a generator must construct
 *        canonical whole-seat districts exactly on a uniform chain.
 *
 *   (12) PRESET RESHAPING (round-7, the five stringiness flags): sequential
 *        winners get the same compact/smoothing passes as Phase-B candidates.
 *
 *   (13) ISLAND BUDGET BEFORE K (round-8, the France probe): sub-floor island
 *        population counts toward a component's budget before k is chosen.
 *
 *   (14) HONEST CONTIGUITY FLAGS (round-8, the gbr ruling): the flag applies
 *        only when contiguity was POSSIBLE among available siblings.
 *
 *   (15) REAL BORDER LENGTH LEADS SHAPE (round-10, the 5-scope stringiness
 *        probe): cut_length — summed real border length between districts —
 *        decides the shape cluster ahead of neck_count and Rg², which were
 *        blind or backwards on all five operator-flagged scopes. It sits
 *        BELOW the 1pp equality band, seat spread, and contiguity: shape
 *        never buys balance, mix, or a break.
 *
 *   (16) THE SEATING LAW (operator ruling 2026-07-13): drawn districts round
 *        to NEAREST, independently — "there is no rebudgeting a district
 *        after giants are split." No Webster / Sainte-Laguë / largest-
 *        remainder total-forcing exists: when drawn shares miss whole
 *        multiples the seated total drifts from the pool budget in either
 *        direction, and that drift is the drawing's defect to fix by
 *        redrawing. Rounding lives in exactly two places: the giant split
 *        and the drawn district.
 *
 *   (17) BUDGET EXACTNESS (operator ruling 2026-07-13, the Draft-9 India
 *        undercount): "generated outcomes that dont arrive at the parent
 *        seat budget are excluded … another configuration needs to be
 *        considered when generating." seat_drift is scoreRank's FIRST key:
 *        a drifted drawing loses to ANY budget-exact one regardless of
 *        every lower key; the fallback of pin 16 applies only when no
 *        exact drawing exists at all.
 *
 *   (18) SCATTERED POOLS LAND THE BUDGET (the India rematch): components
 *        below the giant threshold become bins WITHOUT entering the
 *        k-loop, so no comparator ever sees their sum — a scattered-smalls
 *        pool rounds down a hair per bin and drifts −1. The final-bin
 *        repair (landPoolBudget) nudges real members across bins until the
 *        nearest-rounded sum lands the pool budget exactly.
 *
 *   (19) THE NUDGE ROUTES AROUND INDIVISIBLE DISTRICTS (the China/Earth
 *        rematch, Draft 9 shipped +3): the arithmetically-cheapest
 *        correction can sit on a SINGLE-member district that has nothing
 *        to give — a target-chasing walk stalls there forever. The
 *        boundary nudge only ever considers moves that exist, so it takes
 *        the feasible correction one rank down the cost list.
 *
 *   (20) THE NUDGE EXCHANGES WHEN NO SINGLE MOVE EXISTS (the Draft-10
 *        Ethiopia lottery): a pool can drift where every single move
 *        either flips BOTH bins' rounds (net zero) or strands a
 *        sub-window remainder — the real 8+7+5 Ethiopia had no single
 *        move at all. A pairwise exchange still crosses exactly one
 *        boundary (Addis Ababa out for Afar in). Related determinism fix,
 *        not pinnable directly: the Step-7 edge query now carries ORDER
 *        BY j1, j2 — without it the adjacency lists inherit plan/heap
 *        row order, and the scope-walk and the recursive sweep can draw
 *        DIFFERENT maps from identical data (how Draft 10 diverged from
 *        Draft 9 on one scope).
 *
 *   (21) THE CANONICAL-MIX LANDING (round 11, the Draft-11 spread flags:
 *        Hubei 8+6, Oromia 8+5, Vietnam 9+9+7, Russia's missing 9+9+9+9,
 *        Japan/Ethiopia/Philippines): the old equalizer chased canonical
 *        targets without checking which moves exist and stalled.
 *        landSeatVector walks only feasible moves that strictly reduce
 *        Σ|round(frac) − target| — clean joins preferred, breaks
 *        purchasable closest-first, exchanges last, islands only closer —
 *        and the comparator still disposes: in the k-loop as a variant,
 *        post-attachment behind a scoreBeats gate.
 *
 *   (22) FRAGMENT PROXIMITY IN DOUBLING BANDS (round 11.1, the Vietnam
 *        veto): raw-float fragment_gap let a 20 km shift of an
 *        already-detached piece outvote a halving of deviation and a
 *        spread win. Same-class detachment (band edges ~1°/3°/7°/15°)
 *        now ties and the lower rules decide; jumping a class still
 *        blocks; EXTRA breaks still lose absolutely at the count key;
 *        raw gap returns as the final tiebreak.
 *
 *   (23) BORDER-FIRST BISECTION (round 12, the São Paulo snake): growth
 *        generators leave the border as an emergent scar — on
 *        population-lopsided scopes it snakes, and no local pass can
 *        unwind it. bisectionCandidates draws the border first, the way
 *        the operator does: 12 sweep directions, cut at the canonical
 *        population boundary, strays rejoin so each side stays whole,
 *        recurse for k > 2. Pre-flight on the real fixtures: São Paulo
 *        cut 16.5 → 6.7 at equal balance; Sichuan 16.3 → 13.1.
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
            // only earn a 7+8 split → ~7% on both districts. Integer
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

    // ─── (10) Island attachment is compensated before seating ───────────────

    public function test_island_attachment_is_rebalanced_before_seating(): void
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

    // ─── (12) Preset bins go through the shape passes ────────────────────────

    public function test_preset_bins_are_reshaped_by_the_refinement_passes(): void
    {
        // Round-7 (the operator's five stringiness flags): sequential-builder
        // winners never met the compact/smoothing passes, so their remainder
        // district shipped as a crescent. Preset mode must take a contiguous-
        // but-stringy split and hand back a compacter one at held balance.
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'geographicSeedExpansion');
        $m->setAccessible(true);

        // 4×4 uniform grid; preset = an L-shaped hook (8) vs the interior blob (8).
        $childById = []; $centroids = []; $adj = []; $byCell = [];
        for ($gy = 0; $gy < 4; $gy++) {
            for ($gx = 0; $gx < 4; $gx++) {
                $id = sprintf('g%d%d', $gx, $gy);
                $byCell["$gx,$gy"] = $id;
                $childById[$id] = (object) ['population' => 100_000, 'fractional_seats' => 0.625,
                                            'centroid_x' => $gx + 0.5, 'centroid_y' => $gy + 0.5];
                $centroids[$id] = ['x' => $gx + 0.5, 'y' => $gy + 0.5];
                $adj[$id] = [];
            }
        }
        foreach ($byCell as $key => $id) {
            [$gx, $gy] = array_map('intval', explode(',', $key));
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nb = $byCell[($gx + $dx) . ',' . ($gy + $dy)] ?? null;
                if ($nb !== null) $adj[$id][] = $nb;
            }
        }
        $hook = [$byCell['0,3'], $byCell['1,3'], $byCell['2,3'], $byCell['3,3'],
                 $byCell['3,2'], $byCell['3,1'], $byCell['3,0'], $byCell['2,0']];
        $blob = array_values(array_diff(array_values($byCell), $hook));
        $rg = function (array $bins) use ($centroids) {
            $sum = 0.0;
            foreach ($bins as $b) {
                $mx = array_sum(array_map(fn($j) => $centroids[$j]['x'], $b)) / count($b);
                $my = array_sum(array_map(fn($j) => $centroids[$j]['y'], $b)) / count($b);
                foreach ($b as $j) {
                    $sum += ($centroids[$j]['x'] - $mx) ** 2 + ($centroids[$j]['y'] - $my) ** 2;
                }
            }
            return $sum;
        };

        $ids = array_values($byCell);
        $out = $m->invoke($svc, $ids, $childById, $adj, $centroids, [], 9.5, 5.0, false, 10, [$hook, $blob]);

        $this->assertCount(2, $out);
        $sizes = array_map('count', $out);
        sort($sizes);
        $this->assertSame([8, 8], $sizes, 'balance held through reshaping');
        $this->assertLessThan(
            $rg([$hook, $blob]),
            $rg($out),
            'the passes must strictly reduce the stringy preset\'s spread (crescent → blocks)'
        );
    }

    // ─── (13) Sub-floor islands count toward the budget BEFORE k is chosen ──

    public function test_island_population_counts_before_district_count_is_chosen(): void
    {
        $this->onLivePg(function () {
            // The France probe: a mainland whose lone share rounds DOWN (9.19
            // fracs → sub-budget 9, where no legal 2-split exists) plus a small
            // island that pushes the true budget to 10. Before round 8 the island
            // was attached after all decisions — here the mainland alone isn't
            // even a giant (9.19 < 9.5), so it stayed ONE bin and the merged
            // 10.0-frac district couldn't legally seat its budget. Pre-attaching
            // makes the component 10.0 fracs → split → 5+5 at the true quota.
            $rootId  = $this->makeJurisdiction('zzdg-0-root', 'Test Root', 0, null, $this->square(0, 0, 20, 6), 1_240_000);
            $scopeId = $this->makeJurisdiction('zzdg-1-scope', 'Test Scope', 1, $rootId, $this->square(0, 0, 16, 2), 1_240_000);
            for ($i = 0; $i < 12; $i++) {
                $this->makeJurisdiction("zzdg-2-main-{$i}", "Main {$i}", 2, $scopeId, $this->square($i, 0, $i + 1, 1), 95_000);
            }
            $this->makeJurisdiction('zzdg-2-island', 'Island', 2, $scopeId, $this->square(13.4, 0, 14.4, 1), 100_000);
            $leg = $this->makeLegislature($rootId, 10);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 10, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, $result['districts_created'], 'the merged 10-frac component must split');

            $quota = 1_240_000 / 10;
            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'actual_population']);
            $this->assertSame(10, (int) $districts->sum('seats'), 'the full budget is seated');
            $this->assertSame([5, 5], $districts->pluck('seats')->sort()->values()->all());
            foreach ($districts as $d) {
                $dev = abs($d->actual_population / $d->seats - $quota) / $quota;
                // 95k atoms + one 100k island bound the optimal split at ±7.26% —
                // the engine must land ON that optimum, not merely near it.
                $this->assertLessThan(0.075, $dev);
            }
        });
    }

    // ─── (14) Contiguity flags only when contiguity was POSSIBLE ────────────

    public function test_contiguity_flag_exempts_pieces_that_only_border_giants(): void
    {
        $this->onLivePg(function () {
            // The gbr ruling: {Scotland, Wales, NI} can never be contiguous —
            // Scotland and Wales border only England, which is a GIANT and was
            // never available to the composite pool; NI borders nothing. The old
            // check counted the England border as "fixable" and flipped with the
            // BFS start node. New rule: exempt when EVERY orphaned piece borders
            // only giants (or nothing).
            $rootId  = $this->makeJurisdiction('zzdh-0-root', 'Test Root', 0, null, $this->square(-4, -2, 14, 10), 1_500_000);
            $scopeId = $this->makeJurisdiction('zzdh-1-scope', 'Test Scope', 1, $rootId, $this->square(-3, -1, 13, 9), 1_500_000);
            $giant = $this->makeJurisdiction('zzdh-2-giant', 'Giantland', 2, $scopeId, $this->square(0, 0, 6, 6), 1_100_000);
            $s = $this->makeJurisdiction('zzdh-2-south', 'Southpiece', 2, $scopeId, $this->square(-1, 0, 0, 1), 140_000);
            $w = $this->makeJurisdiction('zzdh-2-west', 'Westpiece', 2, $scopeId, $this->square(-1, 2, 0, 3), 140_000);
            $n = $this->makeJurisdiction('zzdh-2-isle', 'Islepiece', 2, $scopeId, $this->square(8, 0, 9, 1), 120_000);
            $leg = $this->makeLegislature($rootId, 15);

            $svc = app(DistrictingService::class);
            $districtId = (string) \Illuminate\Support\Str::uuid();
            DB::table('legislature_districts')->insert([
                'id' => $districtId, 'legislature_id' => $leg->id, 'jurisdiction_id' => $scopeId,
                'district_number' => 1, 'seats' => 4, 'fractional_seats' => 4.0,
                'floor_override' => true, 'target_population' => 400_000, 'actual_population' => 400_000,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([$s, $w, $n] as $jid) {
                DB::table('legislature_district_jurisdictions')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'district_id' => $districtId, 'jurisdiction_id' => $jid,
                ]);
            }
            $svc->recomputeDistrict($districtId, $leg->id, $leg, true);
            $this->assertTrue(
                (bool) DB::table('legislature_districts')->where('id', $districtId)->value('is_contiguous'),
                'pieces bordering only a giant (or nothing) are unavoidably separate — no flag'
            );

            // Counter-case: two pieces with an AVAILABLE connector between them
            // skipped — the break was avoidable, so the flag must stand.
            $x = $this->makeJurisdiction('zzdh-2-mid', 'Midpiece', 2, $scopeId, $this->square(-1, 1, 0, 2), 100_000);
            $district2 = (string) \Illuminate\Support\Str::uuid();
            DB::table('legislature_districts')->insert([
                'id' => $district2, 'legislature_id' => $leg->id, 'jurisdiction_id' => $scopeId,
                'district_number' => 2, 'seats' => 5, 'fractional_seats' => 5.0,
                'floor_override' => false, 'target_population' => 280_000, 'actual_population' => 280_000,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([$s, $w] as $jid) {
                DB::table('legislature_district_jurisdictions')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'district_id' => $district2, 'jurisdiction_id' => $jid,
                ]);
            }
            $svc->recomputeDistrict($district2, $leg->id, $leg, true);
            $this->assertFalse(
                (bool) DB::table('legislature_districts')->where('id', $district2)->value('is_contiguous'),
                'skipping an available connector is an avoidable break — flag stands'
            );
        });
    }

    // ─── (15) Real border length leads the shape cluster ────────────────────

    public function test_real_border_length_leads_the_shape_cluster(): void
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'scoreBeats');
        $m->setAccessible(true);

        $base = [
            'non_contiguous_count' => 0, 'fragment_gap' => 0.0,
            'avg_droop_threshold' => 0.12,
        ];

        // The Iran inversion (round-10 probe, real numbers): the fat 3-district
        // 8+7+7 with one pinch and a SHORT border must beat the spindly
        // 4-district plan the old comparator picked on neck_count alone.
        $fatShortBorder = $base + [
            'avg_deviation_pct' => 0.70, 'max_deviation_pct' => 0.98,
            'seat_spread' => 1, 'cut_length' => 33.3, 'neck_count' => 1, 'avg_rg_sq' => 10.5,
        ];
        $spindlyNeckless = $base + [
            'avg_deviation_pct' => 0.75, 'max_deviation_pct' => 1.57,
            'seat_spread' => 1, 'cut_length' => 51.2, 'neck_count' => 0, 'avg_rg_sq' => 9.7,
        ];
        $this->assertTrue(
            $m->invoke($svc, $fatShortBorder, $spindlyNeckless),
            'a shorter real border must beat a neckless plan bought with a longer one (Iran)'
        );
        $this->assertFalse($m->invoke($svc, $spindlyNeckless, $fatShortBorder));

        // The Sichuan inversion (round-10 probe, real numbers): population-
        // weighted Rg² actively preferred the stringy plan; real border length
        // must overrule it within the same equality band.
        $blocky = $base + [
            'avg_deviation_pct' => 0.07, 'max_deviation_pct' => 0.10,
            'seat_spread' => 0, 'cut_length' => 10.7, 'neck_count' => 0, 'avg_rg_sq' => 1.88,
        ];
        $stringy = $base + [
            'avg_deviation_pct' => 0.09, 'max_deviation_pct' => 0.14,
            'seat_spread' => 0, 'cut_length' => 16.2, 'neck_count' => 0, 'avg_rg_sq' => 1.58,
        ];
        $this->assertTrue(
            $m->invoke($svc, $blocky, $stringy),
            'real border length overrules the centroid Rg² proxy (Sichuan)'
        );

        // Shape never buys reps-per-district equality (the São Paulo guard):
        // a blocky 7+5 must LOSE to a snaky 6+6.
        $blockyUnevenMix = $base + [
            'avg_deviation_pct' => 0.10, 'max_deviation_pct' => 0.16,
            'seat_spread' => 2, 'cut_length' => 6.9, 'neck_count' => 0, 'avg_rg_sq' => 1.51,
        ];
        $snakyEvenMix = $base + [
            'avg_deviation_pct' => 0.08, 'max_deviation_pct' => 0.08,
            'seat_spread' => 0, 'cut_length' => 16.5, 'neck_count' => 5, 'avg_rg_sq' => 1.63,
        ];
        $this->assertTrue(
            $m->invoke($svc, $snakyEvenMix, $blockyUnevenMix),
            'a shorter border never buys a seat-spread increase (São Paulo)'
        );

        // Shape never buys a full 1pp equality band (round-4 tuning stands).
        $blockyBandWorse = $base + [
            'avg_deviation_pct' => 1.40, 'max_deviation_pct' => 1.90,
            'seat_spread' => 0, 'cut_length' => 4.0, 'neck_count' => 0, 'avg_rg_sq' => 1.4,
        ];
        $wigglyBandBetter = $base + [
            'avg_deviation_pct' => 0.30, 'max_deviation_pct' => 0.60,
            'seat_spread' => 0, 'cut_length' => 9.0, 'neck_count' => 0, 'avg_rg_sq' => 1.9,
        ];
        $this->assertTrue(
            $m->invoke($svc, $wigglyBandBetter, $blockyBandWorse),
            'a shorter border never buys a full 1pp equality band'
        );

        // Shape never buys a contiguity break. (Overrides on the LEFT of `+` —
        // the base's non_contiguous_count would otherwise win the key collision.)
        $brokenShortBorder = [
            'non_contiguous_count' => 1, 'fragment_gap' => 2.0,
            'avg_deviation_pct' => 0.30, 'max_deviation_pct' => 0.60,
            'seat_spread' => 0, 'cut_length' => 4.0, 'neck_count' => 0, 'avg_rg_sq' => 1.4,
        ] + $base;
        $contiguousLongBorder = $base + [
            'avg_deviation_pct' => 0.30, 'max_deviation_pct' => 0.60,
            'seat_spread' => 0, 'cut_length' => 14.0, 'neck_count' => 1, 'avg_rg_sq' => 2.4,
        ];
        $this->assertTrue(
            $m->invoke($svc, $contiguousLongBorder, $brokenShortBorder),
            'a shorter border never buys a contiguity break'
        );

        // Backward compatibility: score arrays WITHOUT cut_length (the pin-5
        // style) default to a neutral 0.0 and fall through to neck_count.
        $legacyClean   = $base + [
            'avg_deviation_pct' => 1.3, 'max_deviation_pct' => 3.8,
            'seat_spread' => 0, 'neck_count' => 0, 'avg_rg_sq' => 2.0,
        ];
        $legacyPinched = $base + [
            'avg_deviation_pct' => 1.3, 'max_deviation_pct' => 3.8,
            'seat_spread' => 0, 'neck_count' => 2, 'avg_rg_sq' => 2.0,
        ];
        $this->assertTrue(
            $m->invoke($svc, $legacyClean, $legacyPinched),
            'cut-less score arrays stay neutral on cut and decide on necks as before'
        );
    }

    public function test_step7_measures_real_border_lengths_for_the_scorer(): void
    {
        $this->onLivePg(function () {
            // End-to-end wiring: the Step-7 edge query must measure each shared
            // border, the service must retain the lengths, and scoreConfiguration
            // must price a partition's total cut from them. 4×4 unit grid: 24
            // internal borders of exactly 1.0° each; a half-split cuts 4 of them,
            // an L-hook split cuts 7.
            $cells = [];
            for ($gy = 0; $gy < 4; $gy++) {
                for ($gx = 0; $gx < 4; $gx++) $cells[] = [$gx, $gy];
            }
            [$leg, $scopeId, $cellById] = $this->makeGridFixture('zzdi', $cells, 100_000, 10);

            $svc    = app(DistrictingService::class);
            $result = $svc->runAutoCompositeForScope($leg->id, $leg, $scopeId, false, 10, null);
            $this->assertNull($result['error']);

            $prop = new \ReflectionProperty($svc, 'borderLen');
            $prop->setAccessible(true);
            $borderLen = $prop->getValue($svc);
            $this->assertCount(24, $borderLen, 'a 4×4 grid has 24 internal borders');
            foreach ($borderLen as $len) {
                $this->assertEqualsWithDelta(1.0, $len, 0.001, 'each unit-square border measures 1.0');
            }

            // Hand partitions through the scorer on the SAME service instance.
            $byCell = [];
            foreach ($cellById as $id => [$cx, $cy]) $byCell[($cx - 0.5) . ',' . ($cy - 0.5)] = $id;
            $childById = []; $adj = [];
            foreach ($cellById as $id => [$cx, $cy]) {
                $childById[$id] = (object) [
                    'population' => 100_000, 'fractional_seats' => 0.625,
                    'centroid_x' => $cx, 'centroid_y' => $cy,
                ];
                $adj[$id] = [];
            }
            foreach ($byCell as $key => $id) {
                [$gx, $gy] = array_map('intval', explode(',', $key));
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $nb = $byCell[($gx + $dx) . ',' . ($gy + $dy)] ?? null;
                    if ($nb !== null) $adj[$id][] = $nb;
                }
            }
            $pick  = fn(array $coords) => array_map(fn($c) => $byCell[$c], $coords);
            $block = [
                $pick(['0,0', '1,0', '0,1', '1,1', '0,2', '1,2', '0,3', '1,3']),
                $pick(['2,0', '3,0', '2,1', '3,1', '2,2', '3,2', '2,3', '3,3']),
            ];
            $hook  = [
                $pick(['0,0', '0,1', '0,2', '0,3', '1,3', '2,3', '3,3', '1,0']),
                $pick(['1,1', '2,1', '3,1', '1,2', '2,2', '3,2', '2,0', '3,0']),
            ];

            $sc = new \ReflectionMethod($svc, 'scoreConfiguration');
            $sc->setAccessible(true);
            $sBlock = $sc->invoke($svc, $block, $childById, $adj, 1_600_000.0, 10, 5, 9, 5.0);
            $sHook  = $sc->invoke($svc, $hook, $childById, $adj, 1_600_000.0, 10, 5, 9, 5.0);
            $this->assertEqualsWithDelta(4.0, $sBlock['cut_length'], 0.01, 'the half split cuts 4 unit borders');
            $this->assertEqualsWithDelta(7.0, $sHook['cut_length'], 0.01, 'the L-hook split cuts 7 unit borders');

            $beats = new \ReflectionMethod($svc, 'scoreBeats');
            $beats->setAccessible(true);
            $this->assertTrue(
                $beats->invoke($svc, $sBlock, $sHook),
                'at identical balance and mix, the shorter real border wins'
            );
        });
    }

    // ─── (16) The seating law: nearest rounding, no total-forcing ───────────

    public function test_drawn_districts_round_to_nearest_with_no_total_forcing(): void
    {
        $this->onLivePg(function () {
            // UNDER-seat direction (the Puducherry case, distilled): three
            // indivisible atoms at 5.4 + 5.4 + 6.2 fracs of a 17-seat pool.
            // No legal 2-way grouping exists (either grouping makes a >9.5
            // bin), so the engine must seat the atoms as three districts.
            // Nearest rounding gives 5 + 5 + 6 = 16 of 17 — the drift is
            // deliberate law, not a defect: no loop may force the total by
            // handing a 5.4 district a sixth seat.
            [$leg, $scopeId] = $this->makeScopeFixture('zzdj', [54, 54, 62], 10_000, 17);
            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 17, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(3, $result['districts_created']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'fractional_seats']);
            foreach ($districts as $d) {
                $this->assertSame(
                    (int) round((float) $d->fractional_seats),
                    (int) $d->seats,
                    'every drawn district seats exactly its nearest rounding'
                );
            }
            $this->assertSame([5, 5, 6], $districts->pluck('seats')->sort()->values()->all());
            $this->assertSame(16, (int) $districts->sum('seats'),
                'the pool seats 16 of 17 — the missing seat is the drawing\'s defect, never redistributed');
        });

        $this->onLivePg(function () {
            // OVER-seat direction (the China 7.529 case, distilled): three
            // atoms at 5.667 fracs each of a 17-seat pool. Nearest rounding
            // gives 6 + 6 + 6 = 18 — one over, equally deliberate.
            [$leg, $scopeId] = $this->makeScopeFixture('zzdk', [58, 58, 58], 10_000, 17);
            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 17, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(3, $result['districts_created']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'fractional_seats']);
            $this->assertSame([6, 6, 6], $districts->pluck('seats')->sort()->values()->all());
            $this->assertSame(18, (int) $districts->sum('seats'),
                'nearest rounding may seat one over the pool — no seat is clawed back to force the total');
        });
    }

    // ─── (17) Budget exactness: drifted drawings are excluded ───────────────

    public function test_drifted_drawings_lose_to_any_budget_exact_drawing(): void
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'scoreBeats');
        $m->setAccessible(true);

        // The Draft-9 India story as scores: the drifted 60/61 plan is better
        // on EVERY doctrine key — balance, spread, shape — and must still lose
        // to the budget-exact plan.
        $drifted = [
            'seat_drift' => 1, 'non_contiguous_count' => 0, 'fragment_gap' => 0.0,
            'avg_deviation_pct' => 0.4, 'max_deviation_pct' => 0.9,
            'seat_spread' => 0, 'cut_length' => 5.0, 'neck_count' => 0,
            'avg_rg_sq' => 1.2, 'avg_droop_threshold' => 0.12,
        ];
        $exact = [
            'seat_drift' => 0, 'non_contiguous_count' => 0, 'fragment_gap' => 0.0,
            'avg_deviation_pct' => 3.6, 'max_deviation_pct' => 6.0,
            'seat_spread' => 2, 'cut_length' => 14.0, 'neck_count' => 2,
            'avg_rg_sq' => 2.8, 'avg_droop_threshold' => 0.14,
        ];
        $this->assertTrue(
            $m->invoke($svc, $exact, $drifted),
            'a budget-exact drawing beats a drifted one on any terms (exclusion)'
        );
        $this->assertFalse($m->invoke($svc, $drifted, $exact));

        // Among drifted drawings (no exact exists — the pin-16 fallback),
        // smaller drift wins first, then the normal doctrine.
        $driftedByTwo = $drifted;
        $driftedByTwo['seat_drift'] = 2;
        $this->assertTrue(
            $m->invoke($svc, $drifted, $driftedByTwo),
            'when no exact drawing exists, the closest-to-budget one wins'
        );

        // Legacy score arrays without the key stay neutral (drift 0).
        $legacy = $exact;
        unset($legacy['seat_drift']);
        $this->assertTrue(
            $m->invoke($svc, $legacy, $drifted),
            'drift-less score arrays read as exact — backward compatible'
        );
    }

    public function test_generator_lands_the_budget_when_an_exact_drawing_exists(): void
    {
        $this->onLivePg(function () {
            // 22 equal atoms of 0.5 fracs each, budget 11. The lazy halving
            // (11 atoms each) reads 5.5 + 5.5 → rounds 6 + 6 = 12, one over.
            // An exact drawing exists two doors down: 12 + 10 atoms = 6.0 +
            // 5.0 → 6 + 5 = 11 on the nose. The exactness rule must steer
            // generation there — the pool budget is landed by DRAWING, never
            // by seat arithmetic.
            [$leg, $scopeId] = $this->makeScopeFixture('zzdl', array_fill(0, 22, 50), 1_000, 11);
            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 11, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, $result['districts_created']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'fractional_seats']);
            $this->assertSame(11, (int) $districts->sum('seats'),
                'the pool budget is landed exactly by the drawing');
            $this->assertSame([5, 6], $districts->pluck('seats')->sort()->values()->all());
            foreach ($districts as $d) {
                $this->assertSame(
                    (int) round((float) $d->fractional_seats),
                    (int) $d->seats,
                    'every district still seats exactly its nearest rounding'
                );
            }
        });
    }

    // ─── (18) Scattered pools land the budget via break-tolerant repair ─────

    public function test_scattered_component_pools_land_the_budget_exactly(): void
    {
        $this->onLivePg(function () {
            // The India structure, distilled: three mutually NON-adjacent
            // clusters of small cells (5.4 + 5.4 + 6.2 fracs of a 17-seat
            // pool). Each cluster is its own component below the giant
            // threshold → each auto-bins with no k-loop, no comparator, no
            // competition. Nearest-rounding the auto-bins gives 5+5+6 = 16 —
            // and the clean rebalance cannot fix it because contiguity-
            // preserving transfers cannot cross the gaps. The final-bin
            // repair must escalate to break-tolerant transfers (fragments
            // kept close) and land 17 on the nose.
            $rootId  = $this->makeJurisdiction('zzdm-0-root', 'Test Root', 0, null, $this->square(-2, -2, 40, 26), 1_700_000);
            $scopeId = $this->makeJurisdiction('zzdm-1-scope', 'Test Scope', 1, $rootId, $this->square(-1, -1, 36, 24), 1_700_000);
            $mk = function (string $tag, int $i, float $x, float $y) use ($scopeId) {
                return $this->makeJurisdiction(
                    "zzdm-2-{$tag}-{$i}", strtoupper($tag) . " {$i}", 2, $scopeId,
                    $this->square($x, $y, $x + 1, $y + 1), 20_000
                );
            };
            for ($i = 0; $i < 27; $i++) $mk('a', $i, $i, 0.0);   // cluster A: 5.4 fracs
            for ($i = 0; $i < 27; $i++) $mk('b', $i, $i, 10.0);  // cluster B: 5.4 fracs
            for ($i = 0; $i < 31; $i++) $mk('c', $i, $i, 20.0);  // cluster C: 6.2 fracs
            $leg = $this->makeLegislature($rootId, 17);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 17, null
            );
            $this->assertNull($result['error']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'fractional_seats']);
            $this->assertSame(17, (int) $districts->sum('seats'),
                'the scattered pool lands its budget exactly via break-tolerant regrouping');
            foreach ($districts as $d) {
                $this->assertSame(
                    (int) round((float) $d->fractional_seats),
                    (int) $d->seats,
                    'every district still seats exactly its nearest rounding'
                );
            }
        });
    }

    // ─── (19) The nudge routes around indivisible districts ─────────────────

    public function test_budget_repair_routes_around_single_member_districts(): void
    {
        $this->onLivePg(function () {
            // The China +1 class, distilled: three non-adjacent clusters at
            // 7.56 + 8.65 + 5.79 fracs of a 22-seat pool. Nearest rounds give
            // 8 + 9 + 6 = 23, one over. The CHEAPEST correction by pure
            // arithmetic is the 7.56 district → 7 (only 0.12 of distortion) —
            // but that district is a single indivisible member, exactly like
            // China's 7.560 province that shipped Draft 9 one seat over. The
            // repair must take the feasible correction instead: the 8.65
            // cluster donates its 0.6-frac member, dropping to 8.05 → 8, and
            // the pool lands 22 exactly.
            $rootId  = $this->makeJurisdiction('zzdn-0-root', 'Test Root', 0, null, $this->square(-2, -2, 12, 26), 2_200_000);
            $scopeId = $this->makeJurisdiction('zzdn-1-scope', 'Test Scope', 1, $rootId, $this->square(-1, -1, 10, 24), 2_200_000);
            $this->makeJurisdiction('zzdn-2-a', 'Solo Fat A', 2, $scopeId, $this->square(0, 0, 1, 1), 756_000);
            $this->makeJurisdiction('zzdn-2-b1', 'Cluster B1', 2, $scopeId, $this->square(0, 10, 1, 11), 805_000);
            $this->makeJurisdiction('zzdn-2-b2', 'Cluster B2', 2, $scopeId, $this->square(1, 10, 2, 11), 60_000);
            $this->makeJurisdiction('zzdn-2-c', 'Solo C', 2, $scopeId, $this->square(0, 20, 1, 21), 579_000);
            $leg = $this->makeLegislature($rootId, 22);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 22, null
            );
            $this->assertNull($result['error']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'fractional_seats']);
            $this->assertSame(22, (int) $districts->sum('seats'),
                'the repair routes around the indivisible cheapest correction and lands the budget');
            foreach ($districts as $d) {
                $this->assertSame(
                    (int) round((float) $d->fractional_seats),
                    (int) $d->seats,
                    'every district still seats exactly its nearest rounding'
                );
            }
        });
    }

    // ─── (20) The nudge exchanges when no single move exists ────────────────

    public function test_budget_repair_exchanges_when_no_single_move_exists(): void
    {
        $this->onLivePg(function () {
            // The Draft-10 Ethiopia case, distilled to three non-adjacent
            // clusters of a 19-seat pool: 6.69 (6.57 + 0.12), 7.69 (6.20 +
            // 1.40 + 0.09), 4.62 (1.46 + 1.59 + 0.54 + 0.31 + 0.72). Nearest
            // rounds give 7 + 8 + 5 = 20, one over — and NO single move can
            // fix it: every candidate either changes both bins' rounds (net
            // zero) or strands a sub-4.5 remainder. The exchange arm must
            // find the two-way trade (the 1.40 out of the 7.69 bin for a
            // small member of the 4.62 bin: 6.83 rounds 7 while the other
            // side holds 5) and land 19 exactly.
            $rootId  = $this->makeJurisdiction('zzdo-0-root', 'Test Root', 0, null, $this->square(-2, -2, 12, 26), 1_900_000);
            $scopeId = $this->makeJurisdiction('zzdo-1-scope', 'Test Scope', 1, $rootId, $this->square(-1, -1, 10, 24), 1_900_000);
            $mk = function (string $tag, int $i, float $x, float $y, int $pop) use ($scopeId) {
                return $this->makeJurisdiction(
                    "zzdo-2-{$tag}-{$i}", strtoupper($tag) . " {$i}", 2, $scopeId,
                    $this->square($x, $y, $x + 1, $y + 1), $pop
                );
            };
            foreach ([657_000, 12_000] as $i => $p)                       $mk('a', $i, $i, 0.0, $p);
            foreach ([620_000, 140_000, 9_000] as $i => $p)               $mk('b', $i, $i, 10.0, $p);
            foreach ([146_000, 159_000, 54_000, 31_000, 72_000] as $i => $p) $mk('c', $i, $i, 20.0, $p);
            $leg = $this->makeLegislature($rootId, 19);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 19, null
            );
            $this->assertNull($result['error']);

            $districts = DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')
                ->get(['seats', 'fractional_seats']);
            $this->assertSame(19, (int) $districts->sum('seats'),
                'the exchange arm lands the pool budget when no single move can');
            foreach ($districts as $d) {
                $this->assertSame(
                    (int) round((float) $d->fractional_seats),
                    (int) $d->seats,
                    'every district still seats exactly its nearest rounding'
                );
            }
        });
    }

    // ─── (21) The canonical-mix landing pass ─────────────────────────────────

    public function test_seat_vector_landing_reaches_canonical_by_clean_moves(): void
    {
        // The Hubei class, distilled: a chain of 28 half-frac cells drawn as
        // 6.0 + 8.0 (rounds 6+8, spread 2) when the canonical 7+7 sits two
        // clean border moves away. The landing must walk exactly there.
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'landSeatVector');
        $m->setAccessible(true);

        $childById = []; $centroids = []; $adj = []; $ids = [];
        for ($i = 0; $i < 28; $i++) {
            $id = sprintf('c%02d', $i);
            $childById[$id] = (object) ['population' => 50_000, 'centroid_x' => $i + 0.5, 'centroid_y' => 0.5];
            $centroids[$id] = ['x' => $i + 0.5, 'y' => 0.5];
            $adj[$id] = [];
            $ids[] = $id;
        }
        for ($i = 1; $i < 28; $i++) {
            $adj[$ids[$i - 1]][] = $ids[$i];
            $adj[$ids[$i]][]     = $ids[$i - 1];
        }
        $bins = [array_slice($ids, 0, 12), array_slice($ids, 12)];   // 6.0 + 8.0 fracs

        $out = $m->invoke($svc, $bins, [7, 7], $childById, $centroids, $adj, 100_000.0, 5, 9, 9.5, 5.0);

        $sizes = array_map('count', $out);
        sort($sizes);
        $this->assertSame([14, 14], $sizes, 'two clean border moves land the canonical 7+7');
        foreach ($out as $b) {
            $frac = array_sum(array_map(fn($j) => $childById[$j]->population, $b)) / 100_000.0;
            $this->assertEqualsWithDelta(7.0, $frac, 0.001);
            // both bins stay contiguous — the moves were tier-1 clean joins
            $set = array_flip($b);
            $seen = [$b[0] => true]; $q = [$b[0]];
            while ($q) {
                $cur = array_pop($q);
                foreach ($adj[$cur] as $nb) {
                    if (isset($set[$nb]) && !isset($seen[$nb])) { $seen[$nb] = true; $q[] = $nb; }
                }
            }
            $this->assertCount(count($b), $seen, 'clean landing never fragments a chain bin');
        }
    }

    public function test_seat_vector_landing_crosses_gaps_when_no_clean_move_exists(): void
    {
        // The Oromia/Ethiopia class, distilled to the real Draft-10 numbers:
        // three mutually non-adjacent clusters at 6.69 + 7.69 + 4.62 of a
        // 19-seat pool (rounds 7+8+5, spread 3). The canonical 7+6+6 is one
        // break-tolerant move away — the 1.40 member crosses from the 7.69
        // cluster to the 4.62 one (6.29 rounds 6, 6.02 rounds 6, and the
        // 6.69 cluster rounds 7).
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'landSeatVector');
        $m->setAccessible(true);

        $childById = []; $centroids = []; $adj = [];
        $mkCluster = function (string $tag, float $y, array $pops) use (&$childById, &$centroids, &$adj) {
            $prev = null; $members = [];
            foreach ($pops as $i => $p) {
                $id = "{$tag}{$i}";
                $childById[$id] = (object) ['population' => $p, 'centroid_x' => $i + 0.5, 'centroid_y' => $y + 0.5];
                $centroids[$id] = ['x' => $i + 0.5, 'y' => $y + 0.5];
                $adj[$id] = [];
                if ($prev !== null) { $adj[$prev][] = $id; $adj[$id][] = $prev; }
                $prev = $id; $members[] = $id;
            }
            return $members;
        };
        $a = $mkCluster('a', 0, [657_000, 12_000]);
        $b = $mkCluster('b', 10, [620_000, 140_000, 9_000]);
        $c = $mkCluster('c', 20, [146_000, 159_000, 54_000, 31_000, 72_000]);

        $out = $m->invoke($svc, [$a, $b, $c], [7, 6, 6], $childById, $centroids, $adj, 100_000.0, 5, 9, 9.5, 5.0);

        $rounds = array_map(function ($bin) use ($childById) {
            return max(5, min(9, (int) round(array_sum(array_map(fn($j) => $childById[$j]->population, $bin)) / 100_000.0)));
        }, $out);
        rsort($rounds);
        $this->assertSame([7, 6, 6], $rounds, 'the break-tolerant landing reaches the canonical mix across the gaps');
        $this->assertSame(19, array_sum($rounds), 'budget exactness survives the landing');
    }

    // ─── (22) Fragment proximity decides in classes, not millimeters ────────

    public function test_fragment_proximity_decides_in_classes_not_millimeters(): void
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'scoreBeats');
        $m->setAccessible(true);

        $base = [
            'seat_drift' => 0, 'non_contiguous_count' => 2, 'neck_count' => 0,
            'cut_length' => 8.0, 'avg_rg_sq' => 2.0, 'avg_droop_threshold' => 0.12,
        ];
        // The real Vietnam numbers: same break count, detached pieces 3.95° vs
        // 4.13° (same distance class) — the map with the better mix and twice
        // the balance must WIN, not lose over 20 km of already-detached water.
        $betterMixSlightlyFarther = $base + [
            'fragment_gap' => 4.13, 'seat_spread' => 1,
            'avg_deviation_pct' => 0.57, 'max_deviation_pct' => 0.87,
        ];
        $tighterPiecesWorseMix = $base + [
            'fragment_gap' => 3.95, 'seat_spread' => 2,
            'avg_deviation_pct' => 1.22, 'max_deviation_pct' => 1.81,
        ];
        $this->assertTrue(
            $m->invoke($svc, $betterMixSlightlyFarther, $tighterPiecesWorseMix),
            'same-class detachment ties — the spread and balance wins decide (Vietnam)'
        );

        // Jumping a distance CLASS still blocks: the same better map with its
        // pieces flung to 8.5° loses to the tight 3.95° map.
        $betterMixFlungFar = $betterMixSlightlyFarther;
        $betterMixFlungFar['fragment_gap'] = 8.5;
        $this->assertTrue(
            $m->invoke($svc, $tighterPiecesWorseMix, $betterMixFlungFar),
            'a genuine scattering still loses regardless of mix and balance'
        );

        // And an EXTRA break still loses absolutely — banding never touches
        // the count key.
        $extraBreak = $betterMixSlightlyFarther;
        $extraBreak['non_contiguous_count'] = 3;
        $extraBreak['fragment_gap'] = 0.5;
        $this->assertTrue(
            $m->invoke($svc, $tighterPiecesWorseMix, $extraBreak),
            'more broken districts always lose, however close their pieces sit'
        );

        // Within the same band AND same spread/balance/shape, closer pieces
        // still win — the raw gap is the final tiebreak.
        $identicalButCloser = $betterMixSlightlyFarther;
        $identicalButCloser['fragment_gap'] = 4.01;
        $this->assertTrue(
            $m->invoke($svc, $identicalButCloser, $betterMixSlightlyFarther),
            'all else equal, closer pieces still win on the raw gap'
        );
    }

    // ─── (23) Border-first bisection candidates ──────────────────────────────

    public function test_bisection_generator_produces_clean_half_splits(): void
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'bisectionCandidates');
        $m->setAccessible(true);

        // 6×4 uniform grid, 24 cells of 0.5 fracs, 12 seats at k=2: the
        // canonical 6+6 is a straight half-split. At least one sweep must
        // deliver it — both sides contiguous, populations exactly even.
        $childById = []; $centroids = []; $adj = []; $byCell = [];
        for ($gy = 0; $gy < 4; $gy++) {
            for ($gx = 0; $gx < 6; $gx++) {
                $id = sprintf('g%d%d', $gx, $gy);
                $byCell["$gx,$gy"] = $id;
                $childById[$id] = (object) ['population' => 50_000, 'fractional_seats' => 0.5,
                                            'centroid_x' => $gx + 0.5, 'centroid_y' => $gy + 0.5];
                $centroids[$id] = ['x' => $gx + 0.5, 'y' => $gy + 0.5];
                $adj[$id] = [];
            }
        }
        foreach ($byCell as $key => $id) {
            [$gx, $gy] = array_map('intval', explode(',', $key));
            foreach ([[1, 0], [0, 1]] as [$dx, $dy]) {
                $nb = $byCell[($gx + $dx) . ',' . ($gy + $dy)] ?? null;
                if ($nb !== null) { $adj[$id][] = $nb; $adj[$nb][] = $id; }
            }
        }

        $cands = $m->invoke($svc, array_values($byCell), $childById, $adj, $centroids, 12, 2, 100_000.0, 5, 9);
        $this->assertNotEmpty($cands, 'the sweep produces candidates');

        $connected = function (array $bin) use ($adj): bool {
            $set = array_flip($bin);
            $seen = [$bin[0] => true]; $q = [$bin[0]];
            while ($q) {
                $cur = array_pop($q);
                foreach ($adj[$cur] as $nb) {
                    if (isset($set[$nb]) && !isset($seen[$nb])) { $seen[$nb] = true; $q[] = $nb; }
                }
            }
            return count($seen) === count($bin);
        };
        $foundClean = false;
        foreach ($cands as $bins) {
            if (count($bins) !== 2) continue;
            $pops = array_map(fn($b) => array_sum(array_map(fn($j) => $childById[$j]->population, $b)), $bins);
            if ($pops[0] === $pops[1] && $connected($bins[0]) && $connected($bins[1])) {
                $foundClean = true;
                break;
            }
        }
        $this->assertTrue($foundClean, 'at least one sweep lands the exact contiguous half-split');
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
