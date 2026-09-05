<?php

namespace App\Services\Legislature;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE TYPE B DISTRICT MAPPER — stage two of the Type B ladder (Wave 3, lane 1).
 *
 * When even 2-per-constituent overflows Type A (TypeBSeatLadder returns
 * needs_districting=true), whole sibling constituents are clumped into shared
 * representative PANELS. Each panel is elected in its OWN at-large STV race for
 * its own seat count among its own constituents' residents (operator ruling
 * 2026-07-29 — one at-large race PER CLUMP, never one pooled race). This is a
 * balanced partition over the adjacency graph, never a cut through geometry.
 *
 * OPERATOR RULINGS B1–B7 (brief docs/plans/scaling/TYPE_B_DISTRICT_MAPPER_DESIGN.md):
 *  B1 — one at-large race PER PANEL (clump). Every panel seats rep_floor — there
 *       is NO bonus seat. The chamber total is p × rep_floor ≤ bound; the odd
 *       leftover seat under an odd bound goes UNUSED, exactly as the at-large
 *       ladder leaves spare seats unused (Type B's total is a ceiling, not a
 *       target). (racePlan/createRaces emit the per-panel races, keyed by
 *       election_races.type_b_panel_id.)
 *  B2 — panels are as EQUAL as possible in MEMBER COUNT (base, base+1); with
 *       equal seats per panel, equal member counts give equal representation.
 *       The remainder members land on the base+1 panels.
 *  B3 — EVERY constituent is a clump member, zero-population parts INCLUDED
 *       (operator ruling 2026-09-05). A part with no residents holds territory
 *       and votes with its clump the instant someone lives there. Population is
 *       never consulted in the grouping; STV over the clump absorbs any
 *       lopsidedness. The combined cap (in TypeBSeatLadder) floors seats to people.
 *  B4 — islands: a disconnected constituent set falls back to centroid
 *       nearest-approach ("nearest islands clump"; see B4_HULL_ISLAND_VALIDATION.md).
 *       Populated here by $centroids; the land-border graph is used wherever an
 *       edge exists. This is the ONLY path that admits a non-contiguous member.
 *  B5 — most-neighbour compactness: MAX SHARED-BORDER LENGTH with the panel,
 *       final fallback lowest member id. The tie-break after contiguity.
 *  B6 — NEVER cross-parent: the constituent set is exactly one parent's direct
 *       children; no edge or panel ever spans parents.
 *  B7 — a grouping is VERSIONED (draft/active/archived); a geodata change drafts
 *       a fresh grouping while sitting members serve out.
 *
 * NO BONUS SEAT (operator ruling 2026-09-05, corrected). An earlier build gave
 * the odd leftover seat to one panel as a "bonus" (3 seats, ~1.5x members). That
 * was wrong: Type A is the CEILING, not a target, and the at-large ladder gives
 * each child exactly rep_floor and leaves spare seats unused. Clumping matches —
 * every panel seats rep_floor, the total is p × rep_floor ≤ bound, and the odd
 * spare seat goes UNUSED. What looked like a "bonus" is only the remainder
 * MEMBERS: n constituents rarely divide evenly into p panels, so (n mod p)
 * panels hold one more member (base+1). The bonus_seats column is vestigial
 * (always 0).
 *
 * CLUMP PRIORITY (operator ruling 2026-09-05, DEFINITIVE — population dropped):
 *   1. CONTIGUITY — the HARD gate. Clumps must be contiguous wherever the
 *      geography allows (multi-source Voronoi + connectivity-safe rebalance); a
 *      non-contiguous member appears only on a disconnected graph (island
 *      fallback, B4).
 *   2. EQUAL MEMBERS — member counts are as even as possible (base, base+1);
 *      with equal seats per panel this keeps representation equal (B2).
 *   3. MOST NEIGHBOUR (compactness) — the diffusion prefers the least-filled
 *      neighbour region; ties by id.
 *   4. id — deterministic final tie-break.
 * POPULATION IS NOT A DRIVER (operator ruling 2026-09-05: "throw population
 * equality out for this seat type since STV should deal with it"). Contiguity
 * overtakes any internal population balance; STV absorbs the residual.
 *
 * NO geometry is ever cut: this is a balanced grouping over the constituent
 * adjacency graph, not a drawing operation. Seats are exact by construction
 * (p × rep_floor ≤ bound; the odd spare seat unused), never total-forced.
 *
 * computePanels() is pure (no DB) and pinned like TypeBSeatLadder; apply()
 * loads the constituent graph, persists the grouping trio, recomputes
 * type_b_seats and clears type_b_needs_districting — the R-A guard un-blocks the
 * Type B race the instant the flag clears.
 */
class TypeBDistrictMapper
{
    public const TIE_BREAK_KEY = 'contiguity_then_compact';

    /**
     * Pure grouping computation. Deterministic; no database, no geometry cut.
     *
     * THE MODEL (operator rulings 2026-09-05):
     *   - EVERY constituent is a clump member, zero-population parts included (B3).
     *   - p = floor(bound / rep_floor) panels. EVERY panel seats rep_floor — no
     *     bonus seat; the total is p × rep_floor ≤ bound and the odd spare seat
     *     under an odd bound goes unused (the at-large ladder does the same).
     *   - Member counts are as EVEN as possible (base, base+1); the remainder
     *     members land on base+1 panels. Population is not consulted.
     *   - Panels are CONTIGUOUS by construction (the hard gate): multi-source BFS
     *     Voronoi (each member joins its nearest seed, region inherited from its
     *     BFS parent) then a connectivity-safe excess-diffusion rebalance toward
     *     the targets. Islands (a disconnected graph) are the only non-contiguous
     *     path (B4).
     *
     * @param array<string,int>                 $populations  constituent id => population
     * @param array<string,array<string,float>> $adjacency  id => [neighbourId => border_len] (land borders, dim ≥ 1)
     * @param array<string,array{x:float,y:float}> $centroids id => centroid (island / disconnected fallback)
     * @return array{
     *   panels: list<list<string>>, rep_floor:int, panel_count:int,
     *   panel_seats: list<int>, seats:int, group_size:int,
     *   bound:int, undercount:bool, tie_break_key:string
     * }
     */
    public static function computePanels(
        array $populations,
        array $adjacency,
        int $typeA,
        int $population,
        int $repFloor,
        array $centroids = [],
    ): array {
        $repFloor = max($repFloor, TypeBSeatLadder::MIN_REP);

        // ALL constituents are clump members — zero-population parts included
        // (B3, operator ruling 2026-09-05). Population never drives the grouping.
        $ids = array_map('strval', array_keys($populations));
        $n   = count($ids);

        // B3 combined cap mirrored here so the mapper's bound matches the ladder.
        $bound = min($typeA, max(0, $population - $typeA));

        if ($n === 0) {
            return self::result([], $repFloor, [], $bound, false);
        }

        // THE UNGROUPED MAP (operator order 2026-09-05, Type B as the last
        // scope of every composite map): when the ladder already fits at
        // rep_floor — every constituent seated as TypeBSeatLadder::sumAt seats
        // it (rep_floor, or min(population, rep_floor) for a tiny constituent
        // ≤ TINY_POP) sums within the bound — the panel map IS the at-large
        // ladder: one panel per constituent, in constituent order, each with
        // that seat count. A hamlet of three never holds five seats; an empty
        // part holds none. No clumping. Clumped maps keep the settled formula
        // below: every panel rep_floor.
        $ungroupedSeats = 0;
        $ungroupedReps  = [];
        foreach ($ids as $id) {
            $pop = (int) ($populations[$id] ?? 0);
            $ungroupedReps[] = $pop <= TypeBSeatLadder::TINY_POP ? max(0, min($pop, $repFloor)) : $repFloor;
            $ungroupedSeats += end($ungroupedReps);
        }
        if ($ungroupedSeats <= $bound) {
            return self::result(array_map(static fn ($id) => [$id], $ids), $repFloor, $ungroupedReps, $bound, false);
        }

        // Maximum panels the bound allows; each panel seats at least rep_floor (B1).
        $pMax = intdiv($bound, $repFloor);
        if ($pMax < 1) {
            // The B3 combined cap leaves less than one full panel's worth of
            // population headroom (bound < rep_floor). Seat ZERO panels — never a
            // full panel that would put type_a + type_b over population.
            return self::result([], $repFloor, [], $bound, true);
        }
        $p = min($pMax, $n);

        // SEATS PER PANEL. Every panel elects rep_floor — there is NO bonus seat
        // (operator ruling 2026-09-05, corrected). Type B's total is a CEILING,
        // not a target: exactly like the at-large ladder, which gives each child
        // rep_floor and leaves spare seats UNUSED. So the chamber total is
        // p × rep_floor ≤ bound, and the odd leftover seat under an odd bound
        // simply goes unused (not drift — the bound is a ceiling, not a fixed
        // size like Type A's cube-root law).
        $reps = array_fill(0, $p, $repFloor);

        // MEMBER COUNTS as even as possible (base, base+1). With equal seats this
        // is a plain balanced split; the remainder members land on base+1 panels
        // ("equal except one" for a small remainder). Population is not consulted.
        $sizes = self::allocateSizes($n, $reps);

        $panels = self::growPanels($ids, $adjacency, $centroids, $sizes);

        return self::result($panels, $repFloor, $reps, $bound, false);
    }

    /**
     * Distribute $n members across the panels in proportion to their seat counts,
     * so members-per-seat is as uniform as the integers allow (largest-remainder
     * over reps[i] * n / seats). Σ sizes == $n; larger-seat panels get
     * proportionally more members. This is a MEMBER grouping, not a seat
     * apportionment (the DRIFT / no-Webster law binds SEATS, which are fixed at
     * $reps and exact by construction).
     *
     * @param list<int> $reps
     * @return list<int>
     */
    private static function allocateSizes(int $n, array $reps): array
    {
        $totalReps = array_sum($reps);
        if ($totalReps <= 0) {
            return array_fill(0, count($reps), 0);
        }

        $sizes    = [];
        $fracs    = [];
        $assigned = 0;
        foreach ($reps as $i => $r) {
            $exact      = $r * $n / $totalReps;
            $floor      = (int) floor($exact);
            $sizes[$i]  = $floor;
            $fracs[$i]  = $exact - $floor;
            $assigned  += $floor;
        }

        // Hand the remaining members to the largest fractional parts, ties to the
        // lower index — the larger-seat (bonus) panels come first, so they win
        // ties and grow biggest, keeping the weight uniform.
        $remainder = $n - $assigned;
        $order     = array_keys($fracs);
        usort($order, function (int $a, int $b) use ($fracs): int {
            if ($fracs[$a] === $fracs[$b]) {
                return $a <=> $b;
            }
            return $fracs[$b] <=> $fracs[$a];
        });
        for ($k = 0; $k < $remainder; $k++) {
            $sizes[$order[$k]]++;
        }

        return array_values($sizes);
    }

    /**
     * Partition the constituents into $sizes panels that are CONTIGUOUS (operator
     * ruling 2026-09-05: the HARD priority) AND close to the ∝-reps target sizes
     * (equal representation weight, the Type B purpose). Two stages:
     *   1. SEED p panels spread across the graph (farthest-first) and grow a
     *      MULTI-SOURCE BFS VORONOI: every member joins the region of its nearest
     *      seed, inheriting the region from its BFS parent — so each region is one
     *      connected patch BY CONSTRUCTION (a member's parent shares its region;
     *      there is no stall and no member is ever placed non-adjacently).
     *   2. REBALANCE toward the targets with connectivity-safe boundary moves: a
     *      member on the border of an OVER-target region moves to an adjacent
     *      UNDER-target region only when its removal keeps the source region
     *      connected. Each move strictly cuts the total size error, so it
     *      terminates; no move ever breaks contiguity.
     * A member in a separate component with no path to any seed (a true island,
     * B4) is placed last by centroid — the ONLY non-contiguous path, taken only
     * when the geography makes contiguity impossible. Population is never consulted.
     *
     * @param list<string>                         $ids
     * @param array<string,array<string,float>>    $adjacency
     * @param array<string,array{x:float,y:float}> $centroids
     * @param list<int>                            $sizes
     * @return list<list<string>>
     */
    private static function growPanels(array $ids, array $adjacency, array $centroids, array $sizes): array
    {
        $p = count($sizes);
        if ($p === 0) {
            return [];
        }
        $ids   = array_map('strval', $ids);
        // SEEDS PER COMPONENT (2026-09-05, the Yap class): a disconnected
        // constituent graph gets its seeds in proportion to component size,
        // farthest-first inside each component. Plain farthest-first took
        // every isolated constituent first (infinite distance) and starved
        // the mainland — Yap's 8-member component sat in ONE panel beside
        // nine singleton panels. A component that earns no seed becomes
        // islands for the B4 distributor below.
        $seeds = self::pickSeedsByComponent($ids, $adjacency, $p);

        // Stage 1: multi-source BFS Voronoi — connected regions by construction.
        [$region, $islands] = self::voronoiRegions($ids, $adjacency, $seeds);

        // Stage 2: connectivity-safe rebalance toward the even target sizes.
        $region = self::rebalanceRegions($region, $adjacency, $sizes);

        // Stage 3: flow the residual excess along region paths to distant
        // deficits, so the member sizes land as EVEN as the connected graph
        // allows ("equal except one"). Diffusion (stage 2) closes local gaps but
        // leaves a gradient when surplus and deficit are far apart; this pushes a
        // member hop by hop from the most-over region to the most-under region,
        // each hop connectivity-safe, until no over/under pair remains.
        $region = self::flowBalance($region, $adjacency, $sizes);

        $panels = array_fill(0, $p, []);
        foreach ($region as $id => $pi) {
            $panels[$pi][] = (string) $id;
        }

        // ISLANDS (disconnected from every seed, B4): distribute so the panels
        // still approach their ∝-reps targets. Prefer UNDER-target panels; among
        // the pool, nearest by centroid when both have one, then most room, then
        // lowest index. With NO adjacency at all every member is an island, so
        // this is the sole distributor and MUST spread evenly to the targets, not
        // pile onto panel 0. This is the ONLY non-contiguous placement, taken only
        // when the geography makes contiguity impossible.
        if ($islands !== []) {
            sort($islands);
            foreach ($islands as $id) {
                $id    = (string) $id;
                $under = [];
                for ($i = 0; $i < $p; $i++) {
                    if (count($panels[$i]) < $sizes[$i]) {
                        $under[] = $i;
                    }
                }
                $pool    = $under !== [] ? $under : range(0, $p - 1);
                $bestI   = null; $bestKey = null;
                foreach ($pool as $i) {
                    $room = count($panels[$i]) - $sizes[$i];
                    $d    = INF;
                    $pc   = self::panelCentroid($panels[$i], $centroids);
                    if ($pc !== null && isset($centroids[$id])) {
                        $dx = $pc['x'] - $centroids[$id]['x'];
                        $dy = $pc['y'] - $centroids[$id]['y'];
                        $d  = $dx * $dx + $dy * $dy;
                    }
                    $key = [$d, $room, $i];
                    if ($bestI === null || $key < $bestKey) {
                        $bestI = $i; $bestKey = $key;
                    }
                }
                $panels[$bestI ?? 0][] = $id;
            }
        }

        foreach ($panels as &$pnl) {
            sort($pnl);
        }
        unset($pnl);

        return $panels;
    }

    /**
     * Multi-source BFS from $seeds: every reachable member joins the region of
     * its nearest seed, inheriting the region from its BFS parent — so each region
     * is connected by construction. Ties (equal distance from two seeds) go to the
     * seed whose wave arrives first; seeds are enqueued in index order, so the
     * lower seed index wins a same-distance tie deterministically. Only members in
     * $ids are traversed. Returns [region (id => panel index), islands (ids
     * reachable from no seed)].
     *
     * @param list<string>                      $ids
     * @param array<string,array<string,float>> $adjacency
     * @param list<string>                      $seeds
     * @return array{0:array<string,int>,1:list<string>}
     */
    private static function voronoiRegions(array $ids, array $adjacency, array $seeds): array
    {
        $set    = array_flip($ids);
        $region = [];
        $queue  = [];
        $head   = 0;
        foreach ($seeds as $i => $s) {
            $s = (string) $s;
            $region[$s] = $i;
            $queue[]    = $s;
        }
        while ($head < count($queue)) {
            $cur = $queue[$head++];
            $ri  = $region[$cur];
            foreach ($adjacency[$cur] ?? [] as $nbr => $_) {
                $nbr = (string) $nbr;
                if (! isset($set[$nbr]) || isset($region[$nbr])) {
                    continue;
                }
                $region[$nbr] = $ri;
                $queue[]      = $nbr;
            }
        }

        $out     = [];
        $islands = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (isset($region[$id])) {
                $out[$id] = $region[$id];
            } else {
                $islands[] = $id;
            }
        }

        return [$out, $islands];
    }

    /**
     * Balance region sizes toward the ∝-reps targets by EXCESS DIFFUSION: a
     * boundary member moves from its region R to an adjacent region S whenever R's
     * excess (count − target) is at least TWO more than S's, and removing the
     * member keeps R connected. Because a member only ever joins an ADJACENT
     * region, contiguity is preserved; because the move needs an excess gap of at
     * least two, it strictly reduces Σ excess² and terminates. Diffusion lets a
     * deficit pull surplus across the WHOLE graph (a chain of at-target regions
     * passes the shortfall along), so a single big-target panel far from the
     * surplus still fills — the single-hop push could not do that. Deterministic:
     * members swept in id order, ties to the lowest-excess (then lowest-index)
     * neighbour region.
     *
     * @param array<string,int>                 $region id => panel index
     * @param array<string,array<string,float>> $adjacency
     * @param list<int>                         $sizes  target per panel
     * @return array<string,int>
     */
    private static function rebalanceRegions(array $region, array $adjacency, array $sizes): array
    {
        $p       = count($sizes);
        $members = array_fill(0, $p, []);
        foreach ($region as $id => $pi) {
            $members[$pi][(string) $id] = true;
        }
        $counts = [];
        for ($i = 0; $i < $p; $i++) {
            $counts[$i] = count($members[$i]);
        }

        $ids = array_keys($region);
        sort($ids);

        $pass      = 0;
        $maxPasses = 2 * array_sum($sizes) + 32;
        do {
            $moved = false;
            foreach ($ids as $m) {
                $m = (string) $m;
                $R = $region[$m];
                // Adjacent region with the least excess (most room), tie low index.
                $bestS = null; $bestE = PHP_INT_MAX;
                foreach ($adjacency[$m] ?? [] as $nbr => $_) {
                    $nbr = (string) $nbr;
                    if (! isset($region[$nbr])) {
                        continue;
                    }
                    $S = $region[$nbr];
                    if ($S === $R) {
                        continue;
                    }
                    $eS = $counts[$S] - $sizes[$S];
                    if ($eS < $bestE || ($eS === $bestE && ($bestS === null || $S < $bestS))) {
                        $bestS = $S; $bestE = $eS;
                    }
                }
                if ($bestS === null) {
                    continue;
                }
                $eR = $counts[$R] - $sizes[$R];
                if ($eR - $bestE < 2) {
                    continue; // gap too small — moving would not improve the balance
                }
                if ($counts[$R] <= 1) {
                    continue; // never drain a region to empty — every panel keeps a member
                }
                // THE TRANSFER (2026-09-05, the chain class): a boundary
                // member that is a cut vertex of R does not move alone — it
                // moves WITH the smaller pieces it separates, and R keeps its
                // largest piece. Chain-like geography (a member with a tail
                // behind it) blocked every single-member move and left one
                // seed holding 38 members beside seventeen singletons
                // (Northwest / Nord-ouest). The pieces hang off the moved
                // member, so S ∪ {m} ∪ pieces stays connected and so does the
                // kept piece. Allowed only while Σ excess² strictly falls
                // (t < eR − eS), which also keeps the loop terminating.
                $pieces = self::piecesWithout($members[$R], $adjacency, $m);
                $moveSet = [$m];
                if (count($pieces) > 1) {
                    usort($pieces, static fn (array $x, array $y) => (count($y) <=> count($x)) ?: strcmp((string) $x[0], (string) $y[0]));
                    array_shift($pieces);   // R keeps its largest piece
                    foreach ($pieces as $piece) {
                        foreach ($piece as $pm) {
                            $moveSet[] = (string) $pm;
                        }
                    }
                }
                $t = count($moveSet);
                if ($t >= $eR - $bestE || $counts[$R] - $t < 1) {
                    continue; // the transfer would not improve the balance, or would empty R
                }
                foreach ($moveSet as $mm) {
                    unset($members[$R][$mm]);
                    $members[$bestS][$mm] = true;
                    $region[$mm]          = $bestS;
                }
                $counts[$R] -= $t; $counts[$bestS] += $t;
                $moved = true;
            }
        } while ($moved && ++$pass < $maxPasses);

        return $region;
    }

    /**
     * FLOW the residual excess to distant deficits so the sizes land even. Each
     * round takes the MOST-over region and the MOST-under region, finds a path
     * between them over ADMISSIBLE hops, and pushes ONE member along it
     * (processed from the deficit end back, so every intermediate region nets
     * zero and only the two endpoints change size). Each hop moves a boundary
     * member whose removal keeps its region connected — contiguity is preserved.
     * Each successful round cuts total |excess| by two, so it terminates.
     *
     * ADMISSIBLE HOPS (2026-09-05, the 142 connected stalls): a hop a → b is
     * usable only when some member of a bordering b can leave a without
     * splitting it. The old search took the SHORTEST region path and gave up
     * when one hop on it was blocked by a cut vertex, though a longer path
     * around the block existed (Minas Gerais: 210 pairs, every shortest path
     * blocked, spread 4..7 against 6/7). The path search now runs over the
     * admissible hops, judged lazily in the current state; a hop that still
     * fails at push time (the state moved under it) is dropped for the round
     * and the search repeats, so every round either pushes or proves no
     * admissible route exists.
     *
     * @param array<string,int>                 $region id => panel index
     * @param array<string,array<string,float>> $adjacency
     * @param list<int>                         $sizes  target per panel
     * @return array<string,int>
     */
    private static function flowBalance(array $region, array $adjacency, array $sizes): array
    {
        $p       = count($sizes);
        $members = array_fill(0, $p, []);
        foreach ($region as $id => $pi) {
            $members[$pi][(string) $id] = true;
        }
        $counts = [];
        for ($i = 0; $i < $p; $i++) {
            $counts[$i] = count($members[$i]);
        }

        $guard    = 0;
        $maxRound = 4 * array_sum($sizes) + 64;
        while ($guard++ < $maxRound) {
            // Over- and under-target regions, most-extreme first. Try every
            // over→under pair until one admits a connectivity-safe push, so a
            // single un-pushable pair (all-cut-vertex bottleneck) does not abort
            // the whole balancing.
            $overs = []; $unders = [];
            for ($i = 0; $i < $p; $i++) {
                $e = $counts[$i] - $sizes[$i];
                if ($e > 0) { $overs[$i] = $e; } elseif ($e < 0) { $unders[$i] = $e; }
            }
            if ($overs === [] || $unders === []) {
                break; // sizes already match the targets exactly
            }
            arsort($overs); // largest surplus first
            asort($unders);  // deepest deficit first

            $moved = false;
            foreach (array_keys($overs) as $o) {
                foreach (array_keys($unders) as $u) {
                    // Two hop-dropping strategies per pair, each on its own
                    // blocked set: first drop the last hop the exact search
                    // ACCEPTED (the entry that brought the wrong member —
                    // {x,y} entered on x had to give x; entering on y works),
                    // then drop the hop the forward push FAILED on (the exit
                    // that has no safe member). Different blocks, different
                    // detours; a pair is given up only when both run dry.
                    foreach ([true, false] as $dropEntry) {
                        $blocked = [];   // "a:b" hops dropped for this attempt
                        while (true) {
                            $path = self::admissiblePath($o, $u, $members, $adjacency, $region, $blocked);
                            if ($path === null) {
                                break;
                            }
                            // THE EXACT PUSH first: the members for every hop
                            // are chosen together so each region's final set
                            // is connected (a region may hand on the very
                            // member it received). The forward push with the
                            // re-bisection fallback is the second attempt.
                            $deepest = 0;
                            if (self::pushAlongPathExact($path, $members, $counts, $region, $adjacency, $deepest)) {
                                $moved = true;
                                break 4;
                            }
                            $failedHop = self::pushAlongPath($path, $members, $counts, $region, $adjacency);
                            if ($failedHop === null) {
                                $moved = true;
                                break 4;
                            }
                            $drop = $dropEntry ? $deepest : $failedHop;
                            $blocked[$path[$drop] . ':' . $path[$drop + 1]] = true;
                        }
                    }
                }
            }
            if (! $moved) {
                break; // no over→under pair admits a connectivity-safe route
            }
        }

        return $region;
    }

    /**
     * Shortest path from region $src to region $dst over ADMISSIBLE hops in the
     * region-adjacency graph: a → b when a member of a borders b AND that hop
     * admits a connectivity-safe boundary member in the current state
     * (boundaryMember), excluding hops in $blocked. Admissibility is judged
     * lazily on the regions the search reaches, memoised per call. Returns the
     * region indices, or null when no admissible route exists.
     *
     * @param array<int,array<string,bool>>     $members  region index => member set
     * @param array<string,array<string,float>> $adjacency
     * @param array<string,int>                 $region
     * @param array<string,bool>                $blocked  "a:b" hops to skip
     * @return list<int>|null
     */
    private static function admissiblePath(int $src, int $dst, array $members, array $adjacency, array $region, array $blocked): ?array
    {
        $prev  = [$src => -1];
        $queue = [$src];
        $head  = 0;
        while ($head < count($queue)) {
            $cur = $queue[$head++];
            if ($cur === $dst) {
                $path = [];
                for ($x = $dst; $x !== -1; $x = $prev[$x]) {
                    $path[] = $x;
                }
                return array_reverse($path);
            }
            $nbrRegions = [];
            foreach (array_keys($members[$cur]) as $m) {
                foreach ($adjacency[(string) $m] ?? [] as $nbr => $_) {
                    $nbr = (string) $nbr;
                    if (isset($region[$nbr]) && $region[$nbr] !== $cur) {
                        $nbrRegions[$region[$nbr]] = true;
                    }
                }
            }
            ksort($nbrRegions);
            foreach (array_keys($nbrRegions) as $rn) {
                if (isset($prev[$rn]) || isset($blocked[$cur . ':' . $rn])) {
                    continue;
                }
                // The FIRST hop is judged exactly: the source gives from its
                // current members, so it needs a bordering member whose
                // removal keeps it connected. An INTERMEDIATE gives only after
                // it has received, so its state at push time differs from now
                // (a single-member region holds two by then and can always
                // give one); it is admitted on bordering alone, and a hop that
                // still fails at push time is dropped by the caller and the
                // search repeats without it.
                if ($cur === $src && self::boundaryMember($cur, $rn, $members, $region, $adjacency) === null) {
                    continue;
                }
                $prev[$rn] = $cur;
                $queue[]   = $rn;
            }
        }

        return null;
    }

    /**
     * Push one member along a region path FORWARD (the source gives first, then
     * each intermediate receives before it gives), so the first region loses a
     * member, the last gains one, and every intermediate nets zero WITHOUT ever
     * dropping below its starting count (an intermediate would empty if it gave
     * before receiving). Returns null on success, or the index of the hop that
     * had no connectivity-safe boundary member — the partial push is undone
     * atomically, so the caller resumes from a clean contiguous partition.
     *
     * @param list<int>                         $path
     * @param array<int,array<string,bool>>     $members  (mutated)
     * @param array<int,int>                    $counts   (mutated)
     * @param array<string,int>                 $region   (mutated)
     * @param array<string,array<string,float>> $adjacency
     */
    private static function pushAlongPath(array $path, array &$members, array &$counts, array &$region, array $adjacency): ?int
    {
        $hops    = count($path) - 1;
        $applied = [];
        for ($i = 0; $i < $hops; $i++) {
            $a = $path[$i];
            $b = $path[$i + 1];
            $m = self::boundaryMember($a, $b, $members, $region, $adjacency);
            if ($m === null) {
                // THE RE-BISECTION HOP (2026-09-05): every member of $a that
                // borders $b is a cut vertex, so no single member can cross.
                // The two regions are re-cut as ONE union into two contiguous
                // parts of the hop's sizes (|a| − 1, |b| + 1); members may
                // change sides in both directions, which a one-member move
                // cannot do. Recorded as moves so the atomic undo below holds.
                if (self::rebisectPair($a, $b, $members, $counts, $region, $adjacency, $applied)) {
                    continue;
                }
                // ATOMIC: undo the partial push so the caller can try another
                // route from a clean state (a stuck member left mid-path would
                // create a fresh over-target region and could oscillate).
                foreach (array_reverse($applied) as [$mm, $aa, $bb]) {
                    unset($members[$bb][$mm]);
                    $members[$aa][$mm] = true;
                    $region[$mm]       = $aa;
                    $counts[$bb]--;
                    $counts[$aa]++;
                }
                return $i;
            }
            unset($members[$a][$m]);
            $members[$b][$m] = true;
            $region[$m]      = $b;
            $counts[$a]--;
            $counts[$b]++;
            $applied[] = [$m, $a, $b];
        }

        return null;
    }

    /**
     * THE EXACT PATH PUSH (2026-09-05): choose the member that crosses each hop
     * of $path so that EVERY region's final set is connected — the source
     * minus its giver, each intermediate minus its giver plus what it
     * received (it may hand on the received member itself), the sink plus
     * what it received. A depth-first search over the hop candidates (members
     * bordering the next region, id order), pruned by the connectivity of each
     * region as soon as its two choices are fixed, bounded by a node budget.
     * The forward push chose greedily hop by hop and stalled where the
     * received member attached to the very member that had to leave (Brasov:
     * {x,y} receives m on x, must give x, {y,m} falls apart). On success the
     * moves are applied in path order; $deepest reports the farthest hop the
     * search reached (the caller drops it when nothing works).
     *
     * @param list<int>                         $path
     * @param array<int,array<string,bool>>     $members  (mutated on success)
     * @param array<int,int>                    $counts   (mutated on success)
     * @param array<string,int>                 $region   (mutated on success)
     * @param array<string,array<string,float>> $adjacency
     */
    private static function pushAlongPathExact(array $path, array &$members, array &$counts, array &$region, array $adjacency, int &$deepest): bool
    {
        $k = count($path) - 1;
        if ($k < 1) {
            return false;
        }
        $orig = [];
        for ($i = 0; $i <= $k; $i++) {
            $orig[$i] = $members[$path[$i]];
        }
        $choice  = array_fill(0, $k, null);
        $budget  = 4000;
        $deepest = 0;
        $dfs = function (int $i, ?string $received) use (&$dfs, &$choice, &$budget, &$deepest, $k, $orig, $adjacency): bool {
            if (--$budget < 0) {
                return false;
            }
            if ($i === $k) {
                $final = $orig[$k];
                $final[$received] = true;
                return self::connectedWithout($final, $adjacency, '');
            }
            $pool = $orig[$i];
            if ($received !== null) {
                $pool[$received] = true;
            }
            $next  = $orig[$i + 1];
            $cands = [];
            foreach (array_keys($pool) as $m) {
                $m = (string) $m;
                foreach ($adjacency[$m] ?? [] as $nbr => $_) {
                    if (isset($next[(string) $nbr])) {
                        $cands[] = $m;
                        break;
                    }
                }
            }
            sort($cands);
            foreach ($cands as $m) {
                $final = $pool;
                unset($final[$m]);
                if ($final === [] || ! self::connectedWithout($final, $adjacency, '')) {
                    continue;
                }
                $deepest    = max($deepest, $i);
                $choice[$i] = $m;
                if ($dfs($i + 1, $m)) {
                    return true;
                }
            }

            return false;
        };
        if (! $dfs(0, null)) {
            return false;
        }
        for ($i = 0; $i < $k; $i++) {
            $m    = (string) $choice[$i];
            $from = $path[$i];
            $to   = $path[$i + 1];
            unset($members[$from][$m]);
            $members[$to][$m] = true;
            $region[$m]       = $to;
            $counts[$from]--;
            $counts[$to]++;
        }

        return true;
    }

    /**
     * Re-cut the union of adjacent regions $a and $b into two CONTIGUOUS parts
     * of sizes |a| − 1 and |b| + 1. The new $a grows by BFS inside the union
     * from a seed of the old $a, seeds tried farthest-from-$b first (ties by
     * id), neighbours in id order; the remainder must be connected. On
     * success every member that changed side is applied and appended to
     * $applied as [member, from, to]. Deterministic. Returns false when no
     * seed yields two connected parts.
     *
     * @param array<int,array<string,bool>>     $members  (mutated)
     * @param array<int,int>                    $counts   (mutated)
     * @param array<string,int>                 $region   (mutated)
     * @param array<string,array<string,float>> $adjacency
     * @param list<array{0:string,1:int,2:int}> $applied  (appended)
     */
    private static function rebisectPair(int $a, int $b, array &$members, array &$counts, array &$region, array $adjacency, array &$applied): bool
    {
        $sizeA = count($members[$a]) - 1;
        if ($sizeA < 1 || $members[$b] === []) {
            return false;
        }
        $union = $members[$a] + $members[$b];

        // Hop distance from $b's members, inside the union.
        $dist = [];
        foreach (array_keys($union) as $id) {
            $dist[(string) $id] = PHP_INT_MAX;
        }
        $queue = [];
        foreach (array_keys($members[$b]) as $id) {
            $dist[(string) $id] = 0;
            $queue[]            = (string) $id;
        }
        $head = 0;
        while ($head < count($queue)) {
            $cur = $queue[$head++];
            foreach ($adjacency[$cur] ?? [] as $nbr => $_) {
                $nbr = (string) $nbr;
                if (isset($union[$nbr]) && $dist[$cur] + 1 < $dist[$nbr]) {
                    $dist[$nbr] = $dist[$cur] + 1;
                    $queue[]    = $nbr;
                }
            }
        }
        $seeds = array_map('strval', array_keys($members[$a]));
        usort($seeds, static fn (string $x, string $y) => ($dist[$y] <=> $dist[$x]) ?: strcmp($x, $y));

        foreach ($seeds as $s) {
            $newA  = [$s => true];
            $queue = [$s];
            $head  = 0;
            while ($head < count($queue) && count($newA) < $sizeA) {
                $cur  = $queue[$head++];
                $nbrs = array_map('strval', array_keys($adjacency[$cur] ?? []));
                sort($nbrs);
                foreach ($nbrs as $nbr) {
                    if (! isset($union[$nbr]) || isset($newA[$nbr])) {
                        continue;
                    }
                    $newA[$nbr] = true;
                    $queue[]    = $nbr;
                    if (count($newA) === $sizeA) {
                        break;
                    }
                }
            }
            if (count($newA) !== $sizeA) {
                continue;
            }
            $newB = array_diff_key($union, $newA);
            if (! self::connectedWithout($newB, $adjacency, '')) {
                continue;
            }
            foreach (array_keys($union) as $id) {
                $id     = (string) $id;
                $target = isset($newA[$id]) ? $a : $b;
                $from   = $region[$id];
                if ($from === $target) {
                    continue;
                }
                unset($members[$from][$id]);
                $members[$target][$id] = true;
                $region[$id]           = $target;
                $counts[$from]--;
                $counts[$target]++;
                $applied[] = [$id, $from, $target];
            }

            return true;
        }

        return false;
    }

    /**
     * The lowest-id member of region $a that borders region $b and whose removal
     * keeps $a connected; null when none qualifies.
     *
     * @param array<int,array<string,bool>>     $members
     * @param array<string,int>                 $region
     * @param array<string,array<string,float>> $adjacency
     */
    private static function boundaryMember(int $a, int $b, array $members, array $region, array $adjacency): ?string
    {
        if (count($members[$a]) <= 1) {
            return null; // never give a region's last member — it would empty
        }
        $cands = [];
        foreach (array_keys($members[$a]) as $m) {
            $m = (string) $m;
            foreach ($adjacency[$m] ?? [] as $nbr => $_) {
                if (($region[(string) $nbr] ?? null) === $b) {
                    $cands[] = $m;
                    break;
                }
            }
        }
        sort($cands);
        foreach ($cands as $m) {
            if (self::connectedWithout($members[$a], $adjacency, $m)) {
                return $m;
            }
        }

        return null;
    }

    /**
     * The connected pieces of a region ($members: id => true) after removing
     * $exclude, each a sorted list of ids; one piece when $exclude is not a cut
     * vertex. Intra-region edges only.
     *
     * @param array<string,bool>                $members
     * @param array<string,array<string,float>> $adjacency
     * @return list<list<string>>
     */
    private static function piecesWithout(array $members, array $adjacency, string $exclude): array
    {
        unset($members[$exclude]);
        $pieces = [];
        $seen   = [];
        $ids    = array_map('strval', array_keys($members));
        sort($ids);
        foreach ($ids as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $piece         = [];
            $stack         = [$start];
            $seen[$start]  = true;
            while ($stack !== []) {
                $cur     = array_pop($stack);
                $piece[] = $cur;
                foreach ($adjacency[$cur] ?? [] as $nbr => $_) {
                    $nbr = (string) $nbr;
                    if (isset($members[$nbr]) && ! isset($seen[$nbr])) {
                        $seen[$nbr] = true;
                        $stack[]    = $nbr;
                    }
                }
            }
            sort($piece);
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * Is the region ($members: id => true) still connected after removing
     * $exclude? True when <= 1 member remains. BFS over the remaining members via
     * intra-region edges only.
     *
     * @param array<string,bool>                $members
     * @param array<string,array<string,float>> $adjacency
     */
    private static function connectedWithout(array $members, array $adjacency, string $exclude): bool
    {
        unset($members[$exclude]);
        $n = count($members);
        if ($n <= 1) {
            return true;
        }
        $start = (string) array_key_first($members);
        $seen  = [$start => true];
        $stack = [$start];
        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach ($adjacency[$cur] ?? [] as $nbr => $_) {
                $nbr = (string) $nbr;
                if (isset($members[$nbr]) && ! isset($seen[$nbr])) {
                    $seen[$nbr] = true;
                    $stack[]    = $nbr;
                }
            }
        }

        return count($seen) === $n;
    }

    /**
     * Choose $p seeds spread across the graph (farthest-first / k-center
     * heuristic): the first is a low-degree corner; each next is the member with
     * the greatest graph distance to the nearest existing seed (tie: lowest id;
     * an unreached member in a separate component, distance INF, is taken first).
     * Spread seeds let the panels grow into separate regions and stay contiguous.
     *
     * @param list<string>                      $ids
     * @param array<string,array<string,float>> $adjacency
     * @return list<string>
     */
    private static function pickSpreadSeeds(array $ids, array $adjacency, int $p): array
    {
        if ($ids === []) {
            return [];
        }
        $first  = self::minDegreeNode($ids, $adjacency);
        $seeds  = [$first];
        $isSeed = [$first => true];

        $dist = [];
        foreach ($ids as $id) {
            $dist[(string) $id] = PHP_INT_MAX;
        }
        $dist[$first] = 0;
        self::bfsRelax($first, $adjacency, $dist);

        while (count($seeds) < $p && count($seeds) < count($ids)) {
            $pick = null; $pickD = -1;
            foreach ($ids as $id) {
                $id = (string) $id;
                if (isset($isSeed[$id])) {
                    continue;
                }
                $d = $dist[$id];
                if ($pick === null || $d > $pickD || ($d === $pickD && $id < $pick)) {
                    $pick = $id; $pickD = $d;
                }
            }
            if ($pick === null) {
                break;
            }
            $seeds[]       = $pick;
            $isSeed[$pick] = true;
            $dist[$pick]   = 0;
            self::bfsRelax($pick, $adjacency, $dist);
        }

        return $seeds;
    }

    /**
     * SEEDS PER COMPONENT (2026-09-05): split the members into connected
     * components (an isolated member is a component of one), give each
     * component its largest-remainder share of the $p seeds (size × p / n,
     * never more seeds than members; ties to the larger component, then the
     * lower first id), and seed each component farthest-first within itself.
     * A component with no seed is left unseeded: its members become islands
     * for the centroid distributor (B4). A connected graph is unchanged: one
     * component, all $p seeds, the plain farthest-first order.
     *
     * @param list<string>                      $ids
     * @param array<string,array<string,float>> $adjacency
     * @return list<string>
     */
    private static function pickSeedsByComponent(array $ids, array $adjacency, int $p): array
    {
        if ($ids === [] || $p <= 0) {
            return [];
        }
        $set    = array_flip($ids);
        $sorted = $ids;
        sort($sorted);
        $seen       = [];
        $components = [];
        foreach ($sorted as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $comp      = [];
            $stack     = [$id];
            $seen[$id] = true;
            while ($stack !== []) {
                $cur    = array_pop($stack);
                $comp[] = $cur;
                foreach ($adjacency[$cur] ?? [] as $nbr => $_) {
                    $nbr = (string) $nbr;
                    if (isset($set[$nbr]) && ! isset($seen[$nbr])) {
                        $seen[$nbr] = true;
                        $stack[]    = $nbr;
                    }
                }
            }
            sort($comp);
            $components[] = $comp;
        }
        if (count($components) === 1) {
            return self::pickSpreadSeeds($ids, $adjacency, $p);
        }

        // Largest remainder over component sizes; larger component first.
        usort($components, static fn (array $a, array $b) => (count($b) <=> count($a)) ?: strcmp($a[0], $b[0]));
        $n     = count($ids);
        $alloc = [];
        $rem   = [];
        $given = 0;
        foreach ($components as $i => $comp) {
            $q         = count($comp) * $p / $n;
            $alloc[$i] = (int) floor($q);
            $rem[$i]   = $q - $alloc[$i];
            $given    += $alloc[$i];
        }
        $order = array_keys($rem);
        usort($order, static fn (int $a, int $b) => ($rem[$b] <=> $rem[$a]) ?: ($a <=> $b));
        for ($left = $p - $given; $left > 0;) {
            $placed = false;
            foreach ($order as $i) {
                if ($left <= 0) {
                    break;
                }
                if ($alloc[$i] < count($components[$i])) {
                    $alloc[$i]++;
                    $left--;
                    $placed = true;
                }
            }
            if (! $placed) {
                break; // every component is saturated (p > n cannot happen; guard)
            }
        }

        $seeds = [];
        foreach ($components as $i => $comp) {
            if ($alloc[$i] <= 0) {
                continue;
            }
            foreach (self::pickSpreadSeeds($comp, $adjacency, $alloc[$i]) as $s) {
                $seeds[] = $s;
            }
        }

        return $seeds;
    }

    /**
     * Lowest-degree member (fewest same-set neighbours), tie lowest id — a corner
     * to anchor the farthest-first seeding.
     *
     * @param list<string>                      $ids
     * @param array<string,array<string,float>> $adjacency
     */
    private static function minDegreeNode(array $ids, array $adjacency): string
    {
        $set     = array_flip(array_map('strval', $ids));
        $best    = null;
        $bestDeg = PHP_INT_MAX;
        foreach ($ids as $id) {
            $id  = (string) $id;
            $deg = 0;
            foreach ($adjacency[$id] ?? [] as $nbr => $_) {
                if (isset($set[(string) $nbr])) {
                    $deg++;
                }
            }
            if ($best === null || $deg < $bestDeg || ($deg === $bestDeg && $id < $best)) {
                $best = $id; $bestDeg = $deg;
            }
        }

        return (string) $best;
    }

    /**
     * BFS from $src (unit edge weights), relaxing $dist to the minimum hop count
     * to any seed relaxed so far. Only members present in $dist (this parent's
     * child set) are traversed.
     *
     * @param array<string,array<string,float>> $adjacency
     * @param array<string,int>                 $dist  id => min hops (mutated)
     */
    private static function bfsRelax(string $src, array $adjacency, array &$dist): void
    {
        $queue = [$src];
        $head  = 0;
        while ($head < count($queue)) {
            $cur = $queue[$head++];
            $cd  = $dist[$cur];
            foreach ($adjacency[$cur] ?? [] as $nbr => $_) {
                $nbr = (string) $nbr;
                if (! array_key_exists($nbr, $dist)) {
                    continue;
                }
                if ($cd + 1 < $dist[$nbr]) {
                    $dist[$nbr] = $cd + 1;
                    $queue[]    = $nbr;
                }
            }
        }
    }

    /**
     * Mean centroid of the panel members that have one; null if none do.
     *
     * @param list<string>                         $panel
     * @param array<string,array{x:float,y:float}> $centroids
     * @return array{x:float,y:float}|null
     */
    private static function panelCentroid(array $panel, array $centroids): ?array
    {
        $sx = 0.0; $sy = 0.0; $k = 0;
        foreach ($panel as $m) {
            if (isset($centroids[$m])) {
                $sx += $centroids[$m]['x'];
                $sy += $centroids[$m]['y'];
                $k++;
            }
        }

        return $k > 0 ? ['x' => $sx / $k, 'y' => $sy / $k] : null;
    }

    /**
     * @param list<list<string>> $panels
     * @param list<int>          $panelReps  seats per panel, aligned to $panels
     */
    private static function result(array $panels, int $repFloor, array $panelReps, int $bound, bool $undercount): array
    {
        $groupSize = 0;
        foreach ($panels as $panel) {
            $groupSize = max($groupSize, count($panel));
        }

        return [
            'panels'        => $panels,
            'rep_floor'     => $repFloor,
            'panel_count'   => count($panels),
            'panel_seats'   => array_values($panelReps),
            'seats'         => array_sum($panelReps),
            'group_size'    => $groupSize,
            'bound'         => $bound,
            'undercount'    => $undercount,
            'tie_break_key' => self::TIE_BREAK_KEY,
        ];
    }

    /**
     * Load one flagged legislature's constituent graph, compute the grouping,
     * persist the trio, recompute type_b_seats and CLEAR type_b_needs_districting.
     * Idempotent per (legislature, status): a fresh grouping archives the prior
     * active one. Returns a summary; null when the legislature is not groupable.
     *
     * @return array{legislature_id:string, grouping_id:string, panel_count:int, seats:int, undercount:bool}|null
     */
    public function apply(string $legislatureId, string $status = 'active', bool $dryRun = false): ?array
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislatureId)
            ->whereNull('deleted_at')
            ->first(['id', 'jurisdiction_id', 'type_a_seats', 'type_b_rep_floor', 'total_seats']);
        if (! $leg) {
            return null;
        }

        $children = DB::table('jurisdictions')
            ->where('parent_id', $leg->jurisdiction_id)
            ->whereNull('deleted_at')
            ->get(['id', 'population']);
        if ($children->isEmpty()) {
            return null; // a leaf — no Type B (B6: its rep lives in the parent's chamber)
        }

        $populations = [];
        $popSum = 0;
        foreach ($children as $c) {
            $populations[(string) $c->id] = (int) $c->population;
            $popSum += max((int) $c->population, 0);
        }

        // B6: adjacency keyed on THIS parent only — never cross-parent.
        $adjacency = [];
        $edges = DB::table('jurisdiction_adjacency')
            ->where('parent_id', $leg->jurisdiction_id)
            ->where('dim', '>=', 1)
            ->get(['j1', 'j2', 'border_len']);
        foreach ($edges as $e) {
            $len = (float) $e->border_len;
            $adjacency[(string) $e->j1][(string) $e->j2] = $len;
            $adjacency[(string) $e->j2][(string) $e->j1] = $len;
        }

        $centroids = [];
        DB::table('jurisdiction_centroids')
            ->whereIn('jurisdiction_id', array_keys($populations))
            ->get(['jurisdiction_id', 'x', 'y'])
            ->each(function ($row) use (&$centroids) {
                $centroids[(string) $row->jurisdiction_id] = ['x' => (float) $row->x, 'y' => (float) $row->y];
            });

        $repFloor = (int) ($leg->type_b_rep_floor ?? TypeBSeatLadder::MIN_REP);

        $plan = self::computePanels($populations, $adjacency, (int) $leg->type_a_seats, $popSum, $repFloor, $centroids);

        if ($dryRun) {
            return [
                'legislature_id' => (string) $leg->id,
                'grouping_id'    => '(dry-run)',
                'panel_count'    => $plan['panel_count'],
                'seats'          => $plan['seats'],
                'undercount'     => $plan['undercount'],
            ];
        }

        return DB::transaction(function () use ($leg, $plan, $status, $popSum) {
            // B7: only one active grouping — archive any prior active plan.
            if ($status === 'active') {
                DB::table('legislature_type_b_groupings')
                    ->where('legislature_id', $leg->id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->update(['status' => 'archived', 'effective_end' => now()->toDateString(), 'updated_at' => now()]);
            }

            $groupingId = (string) Str::uuid();
            DB::table('legislature_type_b_groupings')->insert([
                'id'              => $groupingId,
                'legislature_id'  => (string) $leg->id,
                'status'          => $status,
                'rep_floor'       => $plan['rep_floor'],
                'group_size'      => $plan['group_size'],
                'panel_count'     => $plan['panel_count'],
                'seats_total'     => $plan['seats'],
                'type_a_bound'    => (int) $leg->type_a_seats,
                'tie_break_key'   => $plan['tie_break_key'],
                'signature'       => self::signature($plan['panels']),
                'effective_start' => $status === 'active' ? now()->toDateString() : null,
                'notes'           => $plan['undercount'] ? 'population-capped undercount: bound below one full panel' : null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            self::writePanels($groupingId, (string) $leg->id, $plan);

            // B7: only an ACTIVE plan takes effect THIS term. Recompute the
            // chamber and CLEAR the flag ONLY for an active grouping — the R-A
            // guard un-blocks the Type B race the instant type_b_needs_districting
            // is false. A DRAFT is a next-term plan: it persists as a grouping
            // (above) but MUST NOT resize or un-flag the sitting chamber — sitting
            // members serve out; the draft seats only when it is later activated.
            if ($status === 'active') {
                $totalSeats = (int) $leg->type_a_seats + $plan['seats'];
                DB::table('legislatures')->where('id', $leg->id)->update([
                    'type_b_seats'             => $plan['seats'],
                    'type_b_needs_districting' => false,
                    'total_seats'              => $totalSeats,
                    'quorum_required'          => max(3, (int) ceil($totalSeats / 2)),
                    'updated_at'               => now(),
                ]);
            }

            app(AuditService::class)->append(
                module: 'elections',
                event: 'type_b.grouping_applied',
                payload: [
                    'legislature_id' => (string) $leg->id,
                    'grouping_id'    => $groupingId,
                    'panel_count'    => $plan['panel_count'],
                    'rep_floor'      => $plan['rep_floor'],
                    'seats'          => $plan['seats'],
                    'type_a'         => (int) $leg->type_a_seats,
                    'population'     => $popSum,
                    'undercount'     => $plan['undercount'],
                    'tie_break'      => $plan['tie_break_key'],
                ],
            );

            return [
                'legislature_id' => (string) $leg->id,
                'grouping_id'    => $groupingId,
                'panel_count'    => $plan['panel_count'],
                'seats'          => $plan['seats'],
                'undercount'     => $plan['undercount'],
            ];
        });
    }

    /**
     * Write a plan's panels + panel_jurisdictions into a grouping. EVERY
     * constituent is a panel member (zero-population parts included, B3
     * 2026-09-05). Each panel stores its OWN seat count from the plan
     * (plan['panel_seats'][i]); bonus_seats records the excess over rep_floor
     * (the Type A bonus mechanism), so seats − bonus_seats == rep_floor is the
     * identity. Shared by apply() (fresh grouping) and applyInto() (existing draft).
     */
    private static function writePanels(string $groupingId, string $legId, array $plan): void
    {
        foreach ($plan['panels'] as $i => $members) {
            $panelNum = $i + 1;
            $seats    = (int) ($plan['panel_seats'][$i] ?? $plan['rep_floor']);
            $bonus    = max(0, $seats - (int) $plan['rep_floor']);
            $panelId  = (string) Str::uuid();
            DB::table('legislature_type_b_panels')->insert([
                'id'             => $panelId,
                'grouping_id'    => $groupingId,
                'legislature_id' => $legId,
                'panel_number'   => $panelNum,
                'seats'          => $seats,
                'bonus_seats'    => $bonus,
                'member_count'   => count($members),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            $rows = [];
            foreach ($members as $jid) {
                $rows[] = [
                    'id'              => (string) Str::uuid(),
                    'panel_id'        => $panelId,
                    'grouping_id'     => $groupingId,
                    'jurisdiction_id' => $jid,
                ];
            }
            if ($rows !== []) {
                DB::table('legislature_type_b_panel_jurisdictions')->insert($rows);
            }
        }
    }

    /**
     * Fill an EXISTING draft grouping with a freshly computed clumping, REPLACING
     * its panels but KEEPING the grouping row (its id / name / status). This is
     * the mapper VIEW's Autoseed: it fills the map the operator is on (like the
     * Type A autoseed), never minting a new grouping. Only a draft is fillable.
     * Returns a summary, or null when the legislature / grouping is not groupable.
     *
     * @return array{legislature_id:string, grouping_id:string, panel_count:int, seats:int, undercount:bool}|null
     */
    public function applyInto(string $legislatureId, string $groupingId): ?array
    {
        $leg = DB::table('legislatures')
            ->where('id', $legislatureId)->whereNull('deleted_at')
            ->first(['id', 'jurisdiction_id', 'type_a_seats', 'type_b_rep_floor']);
        if (! $leg) {
            return null;
        }
        $grouping = DB::table('legislature_type_b_groupings')
            ->where('id', $groupingId)->where('legislature_id', $legislatureId)->whereNull('deleted_at')
            ->first(['id', 'status']);
        if (! $grouping || $grouping->status !== 'draft') {
            return null; // only a draft may be (re)filled; active/archived are immutable
        }

        $children = DB::table('jurisdictions')
            ->where('parent_id', $leg->jurisdiction_id)->whereNull('deleted_at')->get(['id', 'population']);
        if ($children->isEmpty()) {
            return null;
        }
        $populations = [];
        $popSum = 0;
        foreach ($children as $c) {
            $populations[(string) $c->id] = (int) $c->population;
            $popSum += max((int) $c->population, 0);
        }
        $adjacency = [];
        foreach (DB::table('jurisdiction_adjacency')
            ->where('parent_id', $leg->jurisdiction_id)->where('dim', '>=', 1)
            ->get(['j1', 'j2', 'border_len']) as $e) {
            $len = (float) $e->border_len;
            $adjacency[(string) $e->j1][(string) $e->j2] = $len;
            $adjacency[(string) $e->j2][(string) $e->j1] = $len;
        }
        $centroids = [];
        DB::table('jurisdiction_centroids')
            ->whereIn('jurisdiction_id', array_keys($populations))->get(['jurisdiction_id', 'x', 'y'])
            ->each(function ($row) use (&$centroids) {
                $centroids[(string) $row->jurisdiction_id] = ['x' => (float) $row->x, 'y' => (float) $row->y];
            });
        $repFloor = (int) ($leg->type_b_rep_floor ?? TypeBSeatLadder::MIN_REP);

        $plan = self::computePanels($populations, $adjacency, (int) $leg->type_a_seats, $popSum, $repFloor, $centroids);

        return DB::transaction(function () use ($grouping, $leg, $plan, $popSum) {
            DB::table('legislature_type_b_panel_jurisdictions')->where('grouping_id', $grouping->id)->delete();
            DB::table('legislature_type_b_panels')->where('grouping_id', $grouping->id)->delete();

            self::writePanels((string) $grouping->id, (string) $leg->id, $plan);

            DB::table('legislature_type_b_groupings')->where('id', $grouping->id)->update([
                'rep_floor'     => $plan['rep_floor'],
                'group_size'    => $plan['group_size'],
                'panel_count'   => $plan['panel_count'],
                'seats_total'   => $plan['seats'],
                'tie_break_key' => $plan['tie_break_key'],
                'signature'     => self::signature($plan['panels']),
                'notes'         => $plan['undercount'] ? 'population-capped undercount: bound below one full panel' : null,
                'updated_at'    => now(),
            ]);

            app(AuditService::class)->append(
                module: 'elections',
                event: 'type_b.grouping_autoseeded',
                payload: [
                    'legislature_id' => (string) $leg->id,
                    'grouping_id'    => (string) $grouping->id,
                    'panel_count'    => $plan['panel_count'],
                    'seats'          => $plan['seats'],
                    'population'     => $popSum,
                    'undercount'     => $plan['undercount'],
                ],
            );

            return [
                'legislature_id' => (string) $leg->id,
                'grouping_id'    => (string) $grouping->id,
                'panel_count'    => $plan['panel_count'],
                'seats'          => $plan['seats'],
                'undercount'     => $plan['undercount'],
            ];
        });
    }

    /** A deterministic fingerprint of the membership for B7 change detection. */
    private static function signature(array $panels): string
    {
        $canon = array_map(function (array $members): array {
            sort($members);
            return $members;
        }, $panels);
        usort($canon, fn (array $a, array $b): int => ($a[0] ?? '') <=> ($b[0] ?? ''));

        return substr(hash('sha256', json_encode($canon)), 0, 64);
    }
}
