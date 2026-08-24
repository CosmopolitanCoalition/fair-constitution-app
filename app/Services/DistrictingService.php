<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WI-B3 — DistrictingService: the district auto-composite algorithm,
 * extracted MECHANICALLY from LegislatureController (2026-06-12) so the
 * election scheduling path (F-ELB-001 / ScheduleGeneralElectionJob, design
 * PHASE_B_DESIGN_schema_lifecycle §B.4 — San Marino initial-map
 * auto-generation, WI-B7) can run it without an HTTP controller.
 *
 * EVERY method body below is byte-identical to its controller original —
 * battle-tested code, moved not refactored. The controller now delegates
 * (thresholds / computeSeatBudget / computeNonGiantQuota /
 * recomputeDistrict / runAutoCompositeForScope / publishMassProgress keep
 * their exact signatures there). Constitutional thresholds resolve through
 * ConstitutionalDefaults exactly as before.
 *
 * Per-request memos (legislature rows, seat budgets) live on the instance;
 * the controller holds one instance per request (constructor injection),
 * preserving the original memoization scope.
 *
 * 2026-07-08 DOCTRINE REWORK (constitutional review: operator-sanctioned) —
 * the auto-composite search no longer matches the controller originals.
 * The objective function now encodes the operator's manual-districting
 * doctrine, validated against his Manual Draft 1 (50/81 scopes better on
 * equality, worst district 8.27% vs auto's 32.32%):
 *   floor/ceiling inviolable → population balance (banded) → contiguity
 *   (breaks are purchasable; fragments kept close) → compactness →
 *   seat-mix/UPD optimality (abandoned first).
 * Mechanisms: integer seat-quota targets with dynamic retargeting
 * (optimalIntegerTargets), population-anchor seeding, deliberate-break
 * rebalancing (breakRebalance), fragment-proximity scoring (fragment_gap),
 * and the scoreRank()/scoreBeats() comparator.
 *
 * APPORTIONMENT LAW (operator ruling 2026-07-13 — there is NO Webster /
 * Sainte-Laguë / largest-remainder method anywhere in this legislature):
 * the root's cube-root total splits to children by population share with
 * the CHILDREN-SUM as denominator (geodata noise means parent pop ≠
 * Σchildren); children whose share would round past the ceiling (≥
 * ceiling+0.5) round to NEAREST WHOLE immediately and lock; the budget
 * minus the locked giants redistributes among the rest, repeating down the
 * layers (computeSeatBudget + Steps 2-5). Drawn districts then round to
 * nearest INDEPENDENTLY (Step 11) — no total-forcing, no rebudgeting after
 * the giant split. A pool whose drawn districts miss whole multiples seats
 * a drifted total; that is the drawing's defect to fix by redrawing.
 */
class DistrictingService
{
    /**
     * Resolve the three constitutional fractional-seats thresholds used
     * throughout the district-mapper. With default 5/9 settings these
     * return 9.5 / 5.0 / 4.5 (matching the historical hardcoded literals).
     * With operator-set 3/7 they return 7.5 / 3.0 / 2.5.
     *
     * @return array{giant: float, floor: float, override: float}
     *   giant    — fractional ≥ giant must be split (ceiling + 0.5)
     *   floor    — composite fractional sum ≥ floor rounds to ≥ floor
     *   override — fractional < override triggers a floor-override flag
     */
    public function thresholds(string $jurisdictionId): array
    {
        return [
            'giant'    => ConstitutionalDefaults::giantThreshold($jurisdictionId),
            'floor'    => ConstitutionalDefaults::floorBoundary($jurisdictionId),
            'override' => ConstitutionalDefaults::floorOverrideBoundary($jurisdictionId),
        ];
    }

    /** Per-request memo for getLegislature(). */
    private array $legislatureMemo = [];

    /**
     * Shared-border lengths for the scope currently being autoseeded, keyed
     * "j1|j2" with j1 < j2 (the Step-7 adjacency query's ordering). Feeds the
     * cut_length shape signal in scoreConfiguration() (round 10). Populated per
     * runAutoCompositeForScope() invocation; empty means the signal is neutral
     * (reflection-driven scoring in tests stays byte-compatible).
     */
    private array $borderLen = [];

    /**
     * Monotonic ns of the last progress beat — the heartbeat throttle's clock
     * (2026-08-09, the re-run loop). hrtime, never microtime: a wall-clock jump
     * mid-scope would either silence the beat or flood it, and this signal is
     * what stands between a long scope and being reclaimed as dead.
     */
    private float $lastBeatNs = 0.0;

    /** Resolved heartbeat gap in ns — config() is too costly for a 639-iteration loop. */
    private ?int $beatGapNs = null;

    /** Accumulated ms per labelled step, and the call count behind each. */
    private array $stepMs = [];

    private array $stepN = [];

    /** Open step timers, label => start ns. */
    private array $stepOpen = [];

    /** Resolved once — the timers sit in hot loops. */
    private ?bool $stepTimingsOn = null;

    /** Per-request memo for computeSeatBudget(). Keyed "{legId}:{jid}". */
    private array $seatBudgetMemo = [];

    /**
     * Per-request memo for giantChildrenForScope(). Keyed "{legId}:{scopeId}".
     * The fixpoint loop is cheap but not free, and computeSeatBudget now asks
     * the parent's table for every child it resolves.
     */
    private array $giantScopeMemo = [];

    /**
     * Memoized legislature row loader. Same row may be needed by several
     * computeSeatBudget() walks during one request.
     */
    private function getLegislature(string $legislatureId): ?object
    {
        if (array_key_exists($legislatureId, $this->legislatureMemo)) {
            return $this->legislatureMemo[$legislatureId];
        }
        $row = DB::table('legislatures')
            ->where('id', $legislatureId)
            ->whereNull('deleted_at')
            ->first();
        return $this->legislatureMemo[$legislatureId] = $row;
    }

    /**
     * Returns the seat count for a jurisdiction at its own scope.
     *
     * Exit paths (first match wins):
     *   1. ROOT     — jurisdiction is the legislature's root jurisdiction:
     *                 return legislatures.type_a_seats.
     *   2. LOOKUP   — jurisdiction is already a member of a non-deleted
     *                 legislature_districts row in this legislature:
     *                 return that row's seats. Cheap gate that covers
     *                 ~all non-giant jurisdictions after autoseed.
     *   3. CASCADE  — only fires when the lookup misses (jurisdiction is
     *                 a GIANT at its parent's scope, so Step 12 never
     *                 inserts a district for it; or parent scope hasn't
     *                 been autoseeded yet). Recursively compute parent's
     *                 budget, then apply Calc A at parent scope:
     *                   Q(parent) = sum_children_pop(parent) / S(parent)
     *                   frac(self) = self.pop / Q(parent)
     *                 Return max(floor, round(frac)).
     *
     * Memoized per request. Recursion depth ≤ ADM hierarchy depth (~5).
     * Works at N layers of nested giants (Earth → China → Guangzhou
     * → Shenzhenxian → …) without code change.
     *
     * The lookup gate (Path 2) keeps the helper bounded when called
     * across many jurisdictions: only giants reach Path 3, and giants
     * are a small fraction of the table.
     *
     * @return int|null  null when chain breaks (no legislature, no
     *                   parent, zero children pop, etc.)
     */
    public function computeSeatBudget(string $jurisdictionId, string $legislatureId, ?string $mapId = null): ?int
    {
        $key = "{$legislatureId}:{$jurisdictionId}:" . ($mapId ?? '-');
        if (array_key_exists($key, $this->seatBudgetMemo)) {
            return $this->seatBudgetMemo[$key];
        }

        $leg = $this->getLegislature($legislatureId);
        if (!$leg) return $this->seatBudgetMemo[$key] = null;

        // ── Path 1: ROOT ─────────────────────────────────────────────
        if ($jurisdictionId === $leg->jurisdiction_id) {
            return $this->seatBudgetMemo[$key] = (int) $leg->type_a_seats;
        }

        // ── Path 1.5: A LOCKED GIANT — THE CASCADE OUTRANKS MEMBERSHIP ──
        // (2026-08-09, the Ukraine "+1" flag.) Path 2 below is a shortcut with
        // an unstated precondition: "if this jurisdiction is already seated in
        // a district, that district's seats ARE its budget." True for an
        // ordinary child. FALSE for a giant — a giant's seats are locked by the
        // cascade and its districts live one scope DOWN, drawn to that lock, so
        // a membership row for a giant is either stale or somebody else's
        // scope. Asking membership first let an old seating outrank the law:
        // Ukraine, seated at 9 under the pre-fixpoint classification, kept
        // reporting 9 while its own scope correctly drew 10 — surfacing as
        // "Ukraine: districts total 10 seats (budget 9, +1)", a constitutional
        // flag raised against a map that was right.
        //
        // So the cascade is asked FIRST, for exactly the case it owns, and the
        // membership shortcut still handles everything else.
        $self = DB::table('jurisdictions')
            ->where('id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first(['id', 'parent_id', 'population']);
        if ($self !== null
            && $self->parent_id !== null
            && (string) $self->parent_id !== $jurisdictionId) {   // self-parent data guard
            $parentGiants = $this->giantChildrenForScope((string) $self->parent_id, $legislatureId);
            if (isset($parentGiants[$jurisdictionId])) {
                return $this->seatBudgetMemo[$key] = $parentGiants[$jurisdictionId];
            }
        }

        // ── Path 2: LOOKUP (cheap; gates the recursion) ──────────────
        // If this jurisdiction is already a member of a district in this
        // legislature, return that district's seats. Avoids any cascade
        // work for the common non-giant case.
        //
        // THE MAP MATTERS (2026-08-09, the Serravalle 9-vs-10). This lookup
        // carried no map filter, so it answered from district membership in
        // ANY map of the legislature — including RETIRED ones. The moment a
        // second map exists (and cloning a map to hand-tweak it is the normal
        // way to get one), a jurisdiction seated in an archived draft returned
        // that dead map's seat count and the live cascade never ran: San
        // Marino's cascade locked Serravalle at 10 while this returned the
        // archived bootstrap's 9. A budget that depends on which retired maps
        // happen to still exist is not a budget.
        //
        // An explicit $mapId answers from that map alone. Unqualified callers
        // get every NON-archived map, which preserves the load-bearing in-run
        // behaviour — a sweep building a draft must keep seeing the districts
        // it is inserting — while retired maps stop voting.
        $sql = "
            SELECT ld.seats
              FROM legislature_districts ld
              JOIN legislature_district_jurisdictions ldj
                ON ldj.district_id = ld.id
              LEFT JOIN legislature_district_maps m
                ON m.id = ld.map_id
             WHERE ldj.jurisdiction_id = ?
               AND ld.legislature_id  = ?
               AND ld.deleted_at IS NULL
               AND " . ($mapId !== null
                    ? 'ld.map_id = ?'
                    // LEFT JOIN, and map-less districts still count: a district
                    // with no map_id predates the versioned-map era (and is what
                    // the reflection fixtures build). Excluding it would not fix
                    // a stale budget, it would erase a live one.
                    : "(m.id IS NULL OR (m.deleted_at IS NULL AND m.status <> 'archived'))") . "
             ORDER BY ld.seats DESC LIMIT 1
        ";
        $bindings = $mapId !== null
            ? [$jurisdictionId, $legislatureId, $mapId]
            : [$jurisdictionId, $legislatureId];
        $row = DB::selectOne($sql, $bindings);
        if ($row) {
            return $this->seatBudgetMemo[$key] = (int) $row->seats;
        }

        // ── Path 3: CASCADE — only when lookup missed ────────────────
        // Reaches here when an ORDINARY child has no district membership yet:
        // the parent scope's autoseed hasn't run (first-time-create path), or
        // it sits outside every drawn district. Giants never reach here —
        // Path 1.5 answers them from the cascade, which is the whole point.
        // ($self was already loaded by Path 1.5 — giants are resolved there.)
        if (!$self || !$self->parent_id) {
            return $this->seatBudgetMemo[$key] = null;
        }

        $parentBudget = $this->computeSeatBudget($self->parent_id, $legislatureId);
        if ($parentBudget === null) {
            return $this->seatBudgetMemo[$key] = null;
        }

        // Calc A: Q(parent) = Σ children pop / S(parent);
        //         frac(self) = self.pop / Q(parent).
        // Sum of all parent-children fracs equals S(parent) exactly,
        // so chaining stays budget-exact at every level.
        $parentChildrenPop = (int) DB::table('jurisdictions')
            ->where('parent_id', $self->parent_id)
            ->whereNull('deleted_at')
            ->sum('population');
        if ($parentChildrenPop <= 0) {
            return $this->seatBudgetMemo[$key] = null;
        }

        $parentLocalQuota = $parentChildrenPop / max($parentBudget, 1);
        $frac  = ((int) $self->population) / max($parentLocalQuota, 1);
        $floor = ConstitutionalDefaults::floor($leg->jurisdiction_id);

        return $this->seatBudgetMemo[$key] = max($floor, (int) round($frac));
    }

    /**
     * THE ONE-FRAME LAW (2026-07-19 — the share-base unification one layer
     * up): gianthood at a scope is judged in the SCOPE'S OWN frame — the
     * same children-sum denominator and cascade budget computeSeatBudget()
     * locks giants with — never the root's flat share. Every surface that
     * enumerates scopes (the mass sweep's walk, the wizard stepper, the
     * autoscale completeness assessor, the F-ELB-008 leaf-giant test) reads
     * THIS helper, so a child that dominates its parent (Kozhikode/Kerala,
     * Saint-Pierre/Réunion) can never again be a giant to the composite yet
     * invisible to the scope list. READ-ONLY EXPOSURE of the settled
     * cascade — no seat math changes (Calc-A frac, ≥ ceiling+0.5, nearest,
     * floor clamp — identical arithmetic to the cascade path above).
     *
     * @return array<string, int> geometry-bearing giant child id => cascade budget
     */
    public function giantChildrenForScope(string $scopeId, string $legislatureId): array
    {
        $memoKey = "{$legislatureId}:{$scopeId}";
        if (array_key_exists($memoKey, $this->giantScopeMemo)) {
            return $this->giantScopeMemo[$memoKey];
        }

        $budget = $this->computeSeatBudget($scopeId, $legislatureId);
        if ($budget === null || $budget <= 0) {
            return $this->giantScopeMemo[$memoKey] = [];
        }

        $leg = $this->getLegislature($legislatureId);
        if (! $leg) {
            return [];
        }
        $threshold = ConstitutionalDefaults::giantThreshold($leg->jurisdiction_id);
        $floor     = ConstitutionalDefaults::floor($leg->jurisdiction_id);

        // Denominator: ALL live children (matching the cascade's
        // parentChildrenPop); output: geometry-bearing giants only — a
        // geomless giant cannot be a scope (it stays the assessor's honest
        // review flag, never silently dropped from the math).
        $children = DB::table('jurisdictions')
            ->where('parent_id', $scopeId)
            ->whereNull('deleted_at')
            ->get(['id', 'population', DB::raw('(geom IS NOT NULL) AS has_geom')]);

        $childSum = (int) $children->sum('population');
        if ($childSum <= 0) {
            return [];
        }

        // ── THE GIANT SPLIT ITERATES (apportionment law, step 4) ─────────────
        // "Budget minus locked giants redistributes among the rest; repeat down
        // the layers. If redistribution pushes a share past the ceiling, the
        // giant split repeats until no layer has an unsplit giant."
        //
        // Classifying ONCE against the pre-redistribution quota misses exactly
        // the band that sentence exists to catch. Earth, live: Ukraine is
        // 9.4809 of the full quota — not a giant — but once the real giants
        // lock their 1,642 seats and the remainder redistributes, the quota
        // falls from 4,010,325 to 3,991,987 and Ukraine is 9.5244: past the
        // ceiling, owed a split it never got. It surfaced as a jurisdiction the
        // sidebar drew with 10 seats and a drill arrow the server refused to
        // open, because the two sides were reading different rounds of the same
        // law (operator, 2026-08-09: "Are you saying that Ukraine gets turned
        // into a Giant after the initial round of Giant Rounding?" — yes).
        //
        // Terminates: a pass only ever ADDS giants and the child set is finite.
        // It converges fast because a promoted giant usually rounds UP, which
        // RAISES the quota for the remaining pool and pulls the other
        // borderline children down rather than cascading.
        //
        // Geomless giants are locked for the MATH (they consume budget) but
        // stay out of the returned set, which is the existing contract: a
        // geomless giant cannot be a scope, it is the assessor's review flag.
        $locked = [];
        for ($pass = 0, $maxPasses = $children->count() + 1; $pass < $maxPasses; $pass++) {
            $lockedPop = 0;
            $lockedSeats = 0;
            foreach ($children as $c) {
                if (isset($locked[(string) $c->id])) {
                    $lockedPop   += (int) $c->population;
                    $lockedSeats += $locked[(string) $c->id];
                }
            }
            $poolPop    = $childSum - $lockedPop;
            $poolBudget = $budget - $lockedSeats;
            if ($poolPop <= 0 || $poolBudget <= 0) {
                break;
            }

            $poolQuota = $poolPop / $poolBudget;
            $promoted  = false;
            foreach ($children as $c) {
                $id = (string) $c->id;
                if (isset($locked[$id])) {
                    continue;
                }
                $frac = ((int) $c->population) / max($poolQuota, 1);
                if ($frac >= $threshold) {
                    $locked[$id] = max($floor, (int) round($frac));
                    $promoted = true;
                }
            }
            if (! $promoted) {
                break;
            }
        }

        $out = [];
        foreach ($children as $c) {
            $id = (string) $c->id;
            if (isset($locked[$id]) && $c->has_geom) {
                $out[$id] = $locked[$id];
            }
        }

        return $this->giantScopeMemo[$memoKey] = $out;
    }

    /**
     * Core auto-composite algorithm for a single scope.
     *
     * Caller is responsible for the DB transaction boundary. Colors are
     * computed at read time by colorIndicesForDistricts() (scope-local greedy
     * adjacency 7-coloring), so no recompute step is needed here.
     * Returns ['districts_created' => int, 'error' => string|null].
     * 'error' is non-null for recoverable no-op cases (e.g. no compositable children).
     * Throws on genuine exceptions — caller should catch and roll back.
     *
     * @param int $seatBudget  Exact integer seat allocation for this scope
     *                         (leg->type_a_seats at root; type_a_apportioned at sub-scopes).
     */
    public function runAutoCompositeForScope(
        string  $legislature_id,
        object  $leg,
        string  $scopeId,
        bool    $clearExisting,
        int     $seatBudget,
        ?string $mapId = null
    ): array {
        // Constitutional thresholds — substituted throughout the algorithm
        // for the legacy 9.5 / 5.0 / 4.5 / 9 / 5 literals.
        ['giant' => $giantThreshold, 'floor' => $floorBoundary] = $this->thresholds($leg->jurisdiction_id);
        $floor   = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);

        // Fresh timing record per scope — the service is resolved once per
        // worker and drains many scopes, so a carried-over record would
        // attribute one scope's cost to the next.
        $this->stepMs = $this->stepN = $this->stepOpen = [];

        // ── Step 1: Fetch ALL direct children with geometry ──────────────────
        $this->publishMassProgress($legislature_id, [
            'phase'       => 'loading',
            'phase_label' => 'Loading children + centroids',
        ]);
        // Pull-engine read path (2026-07-19, mechanics only): the run-level
        // precompute stores ST_Centroid(geom) per jurisdiction — the EXACT
        // expression below, so COALESCE preserves byte-identity. NEVER the
        // stored jurisdictions.centroid column (mixed provenance —
        // childrenGeoJson falls back to ST_PointOnSurface); a different
        // centroid would move BFS seeds and violate the Draft-4/5
        // byte-identity property.
        $allChildrenRows = DB::select("
            SELECT
                j.id, j.name, j.population,
                COALESCE(pc.x, ST_X(ST_Centroid(j.geom))) AS centroid_x,
                COALESCE(pc.y, ST_Y(ST_Centroid(j.geom))) AS centroid_y
            FROM jurisdictions j
            LEFT JOIN jurisdiction_centroids pc ON pc.jurisdiction_id = j.id
            WHERE j.parent_id = :scope_id
              AND j.deleted_at IS NULL
              AND j.geom IS NOT NULL
            ORDER BY j.population DESC, j.id
        ", ['scope_id' => $scopeId]);

        if (empty($allChildrenRows)) {
            return ['districts_created' => 0, 'error' => 'No children with geometry found at this scope'];
        }

        // ── Step 2: Level-local quota + fractional seats ──────────────────────
        $totalChildPop = array_sum(array_map(fn($c) => (int) $c->population, $allChildrenRows));
        $localQuota    = $totalChildPop / max($seatBudget, 1);

        foreach ($allChildrenRows as $c) {
            $c->fractional_seats = (float) $c->population / max($localQuota, 1);
        }

        // ── Step 3: Classify giants vs non-giants ─────────────────────────────
        $giantRows    = [];
        $nonGiantRows = [];
        foreach ($allChildrenRows as $c) {
            if ($c->fractional_seats >= $giantThreshold) {
                $giantRows[] = $c;
            } else {
                $nonGiantRows[] = $c;
            }
        }
        $this->publishMassProgress($legislature_id, [
            'phase'         => 'classified',
            'phase_label'   => sprintf(
                'Classified %d children: %d giant + %d compositable, budget %d seats',
                count($allChildrenRows), count($giantRows), count($nonGiantRows), $seatBudget,
            ),
            'phase_current' => 0,
            'phase_total'   => count($nonGiantRows),
        ]);

        // ── Step 4: Lock giant seat allocations ───────────────────────────────
        // Each giant's locked seat count is the round-up of its fractional
        // seats. The value isn't persisted to a column — downstream readers
        // (computeSeatBudget Path 3) recompute it on demand by walking the
        // parent cascade. Step 12 below only inserts non-giant bin
        // districts; giants have no row at this scope and their budget is
        // derived through the cascade when sub-scopes need it.
        $giantSeats = [];
        foreach ($giantRows as $g) {
            $seats = max($floor, (int) round($g->fractional_seats));
            $giantSeats[$g->id] = $seats;
        }

        // ── ONE-FRAME GIANT LOCK (operator ruling 2026-07-26: drift is always
        // wrong). Two independent giant computations existed and could
        // DISAGREE: the local one above (fractional from this call's own
        // quota, no geometry test) and giantChildrenForScope() — which every
        // downstream reader uses, requires geometry, and derives its quota
        // from computeSeatBudget. When they differed, this scope reserved a
        // different number of seats than the cascade had allotted its giants,
        // so the composite pool was sized wrong and the scope seated a wrong
        // total. Rheinland-Pfalz was the specimen: the cascade allotted its
        // giants 11 of 22, this code locked 9, the pool seated 13, and
        // Germany's chamber came out 442 against a constitutional 439.
        // The cascade's answer is the authoritative one — everything
        // downstream reads it — so adopt it whenever it is available and
        // agrees about the budget being divided.
        $cascadeGiants = $this->giantChildrenForScope($scopeId, $legislature_id);
        if ($cascadeGiants !== []
            && $this->computeSeatBudget($scopeId, $legislature_id) === $seatBudget) {
            $giantSeats   = [];
            $regrouped    = [];
            $regroupedNon = [];
            foreach ($allChildrenRows as $c) {
                if (isset($cascadeGiants[$c->id])) {
                    $giantSeats[$c->id] = (int) $cascadeGiants[$c->id];
                    $regrouped[] = $c;
                } else {
                    $regroupedNon[] = $c;
                }
            }
            // A geometry-less child the local test called giant is NOT a
            // cascade giant (it cannot hold a district); it stays out of the
            // giant lock and the completeness assessor flags it honestly.
            $giantRows    = $regrouped;
            $nonGiantRows = $regroupedNon;
        }

        // ── Step 5: Non-giant seat budget ─────────────────────────────────────
        $nonGiantBudget = $seatBudget - array_sum($giantSeats);

        if (empty($nonGiantRows)) {
            return ['districts_created' => 0, 'error' => 'No compositable (non-giant) children found at this scope'];
        }

        // ── Step 6: Filter already-assigned non-giants (when not clearing) ────
        if (!$clearExisting) {
            $nonGiantIds  = array_column($nonGiantRows, 'id');
            $assignedQuery = DB::table('legislature_district_jurisdictions as ldj')
                ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
                ->where('ld.legislature_id', $legislature_id)
                ->whereNull('ld.deleted_at')
                ->whereIn('ldj.jurisdiction_id', $nonGiantIds);
            if ($mapId !== null) {
                $assignedQuery->where('ld.map_id', $mapId);
            }
            $assignedIds  = $assignedQuery->pluck('ldj.jurisdiction_id')->toArray();
            $nonGiantRows = array_values(array_filter($nonGiantRows, fn($c) => !in_array($c->id, $assignedIds)));
        }

        if (empty($nonGiantRows)) {
            return ['districts_created' => 0, 'error' => 'No unassigned compositable children found at this scope'];
        }

        // Build childById + centroids for BFS (non-giants only)
        $childById = [];
        $centroids  = [];
        foreach ($nonGiantRows as $c) {
            $childById[$c->id] = $c;
            $centroids[$c->id] = ['x' => (float) $c->centroid_x, 'y' => (float) $c->centroid_y];
        }
        $childIds = array_column($nonGiantRows, 'id');

        $this->stepBegin('step7.edges');
        // ── Step 7: Adjacency + BFS connected components ──────────────────────
        // Two-tier conditional simplify on huge geoms. ST_Intersection on raw
        // multipart polygons (Quebec, Russian oblasts, Nunavut) takes 30-180s
        // per pair and is uninterruptible; on simplified geoms it's seconds.
        //
        // Tier 1 (>1M vertices, e.g. Nunavut at 5.4M): 0.01° ≈ 1.1km. Even
        // with single-tier 0.001° simplification, Nunavut still emerges at
        // 434k vertices, and the simplify call alone takes ~55s. The 0.01°
        // tier brings Nunavut to ~40k, completing in <10s for all of Canada.
        // Tier 2 (>50k vertices): 0.001° ≈ 110m, finer than geoBoundaries'
        // real border precision.
        //
        // ST_MakeValid wraps each simplify because simplifying complex
        // coastlines can introduce self-intersections (e.g. James Bay coast)
        // that crash ST_Intersection with a GEOS topology exception.
        // Each edge also carries the LENGTH of the shared border (round 10): the
        // linework of the already-computed intersection, summed per pair. This is
        // the real-geometry compactness currency — a partition's total cut length
        // (borders between districts) is what the operator's eye reads as
        // stringiness, where centroid Rg² is blind to low-population tendrils
        // (the São Paulo snake carried 7× Manual's border length at equal Rg²).
        // The inner subquery computes ST_Intersection once per pair.
        $idsStr = '{' . implode(',', $childIds) . '}';
        // Pull-engine read path (2026-07-19, mechanics only): sibling
        // adjacency + border lengths are stable topology keyed on the parent.
        // When the run-level precompute has fully materialized this parent
        // (jurisdiction_adjacency_parents = done), read the table —
        // byte-identical by construction: same simplify tiers, same
        // a.id < b.id orientation, same ST_Dimension >= 1 filter, and the
        // same load-bearing ORDER BY. Otherwise the live query below runs
        // UNCHANGED and its edges are written back (marked complete only
        // when the pool covered ALL geometry-bearing children).
        $adjacencyPre = app(\App\Services\Autoscale\AdjacencyPrecompute::class);
        if ($adjacencyPre->isDone($scopeId)) {
            $edges = DB::select("
                SELECT j1, j2, border_len
                  FROM jurisdiction_adjacency
                 WHERE parent_id = :scope_id
                   AND dim >= 1
                   AND j1 = ANY(:ids::uuid[])
                   AND j2 = ANY(:ids2::uuid[])
                 ORDER BY j1, j2
            ", ['scope_id' => $scopeId, 'ids' => $idsStr, 'ids2' => $idsStr]);
        } else {
        $edges  = DB::select("
            WITH g AS (
                SELECT id,
                       CASE
                           WHEN ST_NPoints(geom) > 1000000
                                THEN ST_MakeValid(ST_Simplify(geom, 0.01))
                           WHEN ST_NPoints(geom) > 50000
                                THEN ST_MakeValid(ST_Simplify(geom, 0.001))
                           ELSE geom
                       END AS geom
                FROM jurisdictions
                WHERE id = ANY(:ids::uuid[])
                  AND deleted_at IS NULL
                  AND geom IS NOT NULL
            ),
            pair AS (
                SELECT a.id AS j1, b.id AS j2,
                       ST_Intersection(a.geom, b.geom) AS ix
                FROM g a
                JOIN g b ON a.id < b.id
                    AND a.geom && b.geom
                    AND ST_Intersects(a.geom, b.geom)
            )
            SELECT j1, j2,
                   ST_Length(ST_CollectionExtract(ix, 2)) AS border_len
            FROM pair
            WHERE ST_Dimension(ix) >= 1
            ORDER BY j1, j2
        ", ['ids' => $idsStr]);
            $adjacencyPre->writeBack($scopeId, $childIds, array_map(
                static fn ($e) => [
                    'j1'         => (string) $e->j1,
                    'j2'         => (string) $e->j2,
                    // Live rows passed the dim>=1 filter; the table's only
                    // consumer reads dim >= 1, so 1 is the faithful stamp.
                    'dim'        => 1,
                    'border_len' => $e->border_len !== null ? (float) $e->border_len : null,
                ],
                $edges,
            ));
        }
        // ORDER BY is LOAD-BEARING (the Draft-10 Ethiopia lottery): without it
        // Postgres returns join rows in plan/heap order, which shifts whenever a
        // jurisdictions tuple is rewritten — the adjacency lists inherit that
        // order, BFS growth inherits it from them, and the candidate pool tilts.
        // The scope-by-scope walk and the recursive sweep then disagree on the
        // SAME data (Draft 9 drew Ethiopia 8+6+5 exact; Draft 10 drew 8+7+5,
        // +1). Determinism of the drawing is a settled property (the Draft-4/5
        // byte-identity proof) — never remove this clause.

        $adj = [];
        $this->borderLen = [];
        foreach ($childIds as $id) $adj[$id] = [];
        foreach ($edges as $edge) {
            $adj[$edge->j1][] = $edge->j2;
            $adj[$edge->j2][] = $edge->j1;
            $this->borderLen[$edge->j1 . '|' . $edge->j2] = (float) $edge->border_len;
        }

        $visited    = [];
        $components = [];
        foreach ($childIds as $id) {
            if (isset($visited[$id])) continue;
            // HEAD CURSOR, NOT array_shift (2026-08-09, the São Paulo runtime).
            // array_shift reindexes the whole array on every pop, so a BFS over
            // n nodes costs O(n²) in zval moves alone — on a 638-municipality
            // component that is ~200k pointless memmoves per traversal, and the
            // refinement passes run thousands of traversals. A cursor yields the
            // same elements in the same order (the queues here are append-only
            // FIFOs, never spliced), so every drawn map is bit-identical; only
            // peak memory differs, bounded by total pushes. Do NOT "simplify"
            // any of these to array_pop: this one sets $component's order, which
            // is load-bearing — it seeds the Phase-A scan.
            $component = [];
            $queue     = [$id];
            $qh        = 0;
            $visited[$id] = true;
            while (isset($queue[$qh])) {
                $curr        = $queue[$qh++];
                $component[] = $curr;
                foreach ($adj[$curr] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $visited[$neighbor] = true;
                        $queue[] = $neighbor;
                    }
                }
            }
            $components[] = $component;
        }

        // ── Sub-floor component budget ACCOUNTING (round-9) ──────────────────────
        // Round 8 physically pre-merged tiny island components into their hosts;
        // that fixed France's wrong-budget k-choice but reshuffled every
        // multi-component scope and degraded Draft 7 (operator verdict: avoidable
        // breaks, worse spread, the 14.5% India-root district). Retraction keeps
        // ONLY the arithmetic: a component too small to ever round to a district
        // (< floorBoundary − 0.5 fracs) COUNTS toward its nearest host's budget,
        // quota, and split decision — France's mainland plans for 16 seats, not
        // 15 — but candidates are built on the real landmass, and islands attach
        // AFTER scoring by closest approach (the Draft-6 flow the operator
        // validated), with the post-attachment rebalance compensating.
        $ownFrac = [];
        $accountedPop = [];
        foreach ($components as $ci => $comp) {
            $ownFrac[$ci]      = array_sum(array_map(fn($j) => (float) $childById[$j]->fractional_seats, $comp));
            $accountedPop[$ci] = array_sum(array_map(fn($j) => (int) $childById[$j]->population, $comp));
        }
        $accountedFrac  = $ownFrac;
        $satelliteComps = [];   // hostIdx => [satellite member lists]
        if (count($components) > 1) {
            $satBoundary = $floorBoundary - 0.5;
            $hosts = array_keys(array_filter($ownFrac, fn($f) => $f >= $satBoundary));
            if (!empty($hosts)) {
                foreach ($components as $ci => $comp) {
                    if ($ownFrac[$ci] >= $satBoundary) continue;
                    $bestJ = -1; $bestD = PHP_FLOAT_MAX;
                    foreach ($hosts as $hj) {
                        $d = $this->closestApproachSq($comp, $components[$hj], $centroids);
                        if ($d < $bestD) { $bestD = $d; $bestJ = $hj; }
                    }
                    if ($bestJ >= 0) {
                        $accountedPop[$bestJ]    += $accountedPop[$ci];
                        $accountedFrac[$bestJ]   += $ownFrac[$ci];
                        $satelliteComps[$bestJ][] = $comp;
                    }
                }
            }
        }

        $this->stepEnd('step7.edges');

        // ── Step 8: Multi-attempt seed expansion — retain best by the operator's doctrine ────
        // For each component, tries every integer k in [kMin, min(kMax, kMin+7)]; for each k,
        // every component member seeds one attempt (far-point spread for the rest) plus one
        // population-anchor attempt (top-k most populous children as seeds, so lumpy
        // population — Canada-class — gets its own bins). A cheap BFS-only proxy scored
        // against INTEGER seat targets gates the top 20 into the full pipeline.
        //
        // Scoring priority (operator doctrine rulings 2026-07-08 + 2026-07-13, retuned to the
        // Good Maps standard 2026-08-23 — see scoreRank()):
        //   0. BUDGET EXACTNESS (seat_drift): drawings whose nearest-rounded seats miss the
        //      pool budget are EXCLUDED whenever any exact drawing exists
        //   1. Population balance as an ACCEPTABILITY THRESHOLD (≤4% avg / ≤10% max all tie —
        //      within it, deviation only returns as the LAST tiebreak)
        //   2. Contiguity (fewest breaks; then fragment_gap — broken pieces kept close)
        //   3. Seat-mix equality as EXCESS over the candidate k's canonical partition —
        //      an uneven canonical mix (budget % k ≠ 0) is arithmetic, not a defect,
        //      so fat-district plans compete across k on their real qualities
        //   4. Compactness (cut_length: REAL border length between districts — stringy = bad;
        //      then neck_count pinch points, then avg Rg² as the centroid fallback)
        //   5. Seat-mix / UPD diversity (avg Droop threshold) — sacrificed first
        // The constitutional floor/ceiling stay hard throughout (frac guards + Step-11 clamps).
        //
        // After the contiguity-preserving pipeline, any per-k winner still >2.5% off its
        // worst integer target spawns a deliberate-break variant (breakRebalance — transfers
        // without adjacency, fragments kept close) that competes under the same comparator:
        // population balance may BUY a contiguity break, never the reverse.
        // Zero DB queries; uses the adjacency graph already in memory.
        $allBins     = [];
        $totalBinPop = array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $childIds));

        foreach ($components as $componentIdx => $component) {
            // Accounted frac: this component plus the sub-floor satellites that
            // will attach to it after scoring (round-9 budget accounting).
            $compFrac = $accountedFrac[$componentIdx];

            // Single-district components need no splitting — skip multi-attempt overhead
            if ($compFrac < $giantThreshold) {
                $allBins[] = $component;
                continue;
            }

            // k range: constitutional ceiling (max seats) → constitutional floor (min seats)
            $kMin = max(2, (int) ceil($compFrac / (float) $ceiling));
            $kMax = max($kMin, (int) floor($compFrac / (float) $floor));

            $this->publishMassProgress($legislature_id, [
                'phase'         => 'binning',
                'phase_label'   => sprintf(
                    'Component %d/%d — %d children, %d seats, k∈[%d..%d]',
                    $componentIdx + 1, count($components), count($component),
                    (int) round($compFrac), $kMin, min($kMax, $kMin + 7)
                ),
                'phase_current' => $componentIdx + 1,
                'phase_total'   => count($components),
            ]);

            // Exhaustive integer range [kMin, min(kMax, kMin+7)].
            // Cap at kMin+7 so runtime stays bounded for very large budgets.
            // Full range ensures UPD-optimal k values (e.g. k=10 for a 61-seat budget) are never skipped.
            $kCandidates = range($kMin, min($kMax, $kMin + 7));

            // Component-level proportional budget (fixed regardless of k) — the
            // ACCOUNTED population, so the plan anticipates its satellites.
            $compBinPop = $accountedPop[$componentIdx];
            $compBudget = $totalBinPop > 0
                ? (int) round($compBinPop * $nonGiantBudget / $totalBinPop)
                : $nonGiantBudget;
            $quotaPopC  = $compBudget > 0 ? (float) $compBinPop / $compBudget : 0.0;

            // One edge cap for every generator on this component (2026-08-09).
            $compEdgeCapSq = $this->componentEdgeCapSq($component, $adj, $centroids);

            // Population-anchor seed ordering (deterministic: population desc, then id)
            $byPop = $component;
            usort($byPop, function ($a, $b) use ($childById) {
                return ((int) $childById[$b]->population <=> (int) $childById[$a]->population) ?: strcmp($a, $b);
            });

            // Virtual-attachment scoring (round-9): candidates are BUILT on the real
            // landmass but SCORED as they will exist after their satellites attach —
            // each satellite joins its closest-approach bin for scoring only. Without
            // this, pre-attachment bins run light by their islands' share against the
            // accounted quota and degenerate configs out-score honest ones.
            $mySats = $satelliteComps[$componentIdx] ?? [];
            $virtualize = function (array $bins) use ($mySats, $centroids): array {
                if (empty($mySats)) return $bins;
                $v = array_map(fn($b) => $b, $bins);
                foreach ($mySats as $sat) {
                    $bestJ = 0; $bestD = PHP_FLOAT_MAX;
                    foreach ($v as $j => $b) {
                        $d = $this->closestApproachSq($sat, $b, $centroids);
                        if ($d < $bestD) { $bestD = $d; $bestJ = $j; }
                    }
                    $v[$bestJ] = array_merge($v[$bestJ], $sat);
                }
                return $v;
            };

            $candidateConfigs = [];

            // ── Line-first fast path (round 13, the São Paulo runtime) ────────
            // The border-first generator already existed as candidate 25-of-36;
            // running it FIRST is what makes it useful, because its own cost is
            // twelve sorts of the member list while the growth search around it
            // scales with child count. When its polished winner is clean under
            // the UNTOUCHED ladder, the search has nothing left to win.
            // Default mode is 'shadow': compute, log against the winner the
            // search chose, adopt nothing — the flip to 'auto' is earned on the
            // real corpus, not argued. On any fall-through the original loop
            // runs with its original bindings; the fast path writes no instance
            // state, so 'off' and a refusal are byte-identical to before.
            $lfMode    = (string) config('cga.districting.line_first', 'shadow');
            $lineFirst = null;
            if ($lfMode !== 'off' && $this->lineFirstEngaged($component, $adj, $kCandidates, $lfMode)) {
                $this->beat($legislature_id, 'line-first: border sweep');
                $this->stepBegin('linefirst');
                $lineFirst = $this->lineFirstCandidate(
                    $component, $childById, $adj, $centroids, $kCandidates,
                    $compBudget, (float) $compBinPop, $quotaPopC,
                    $floor, $ceiling, $giantThreshold, $floorBoundary,
                    $virtualize, $compEdgeCapSq
                );
                $this->stepEnd('linefirst');
            }
            $adoptLineFirst = $lineFirst !== null
                && $lineFirst['clean']
                && ($lfMode === 'auto' || $lfMode === 'always');
            if ($adoptLineFirst) {
                $candidateConfigs[] = ['bins' => $lineFirst['bins'], 'score' => $lineFirst['score']];
            }

            foreach (($adoptLineFirst ? [] : $kCandidates) as $k) {
                $targetPopK = $compBinPop > 0 ? (float) $compBinPop / $k : 0.0;

                // ── Phase A: BFS-only scan — every jid as first seed + a population-anchor set ─
                // geographicSeedExpansion($bfsOnly=true) returns after BFS before passes.
                // The proxy scores each raw partition against the OPTIMAL integer seat targets
                // for its realized bins (operator method: "look at the optimal breakdown of
                // reps per district first" — districts should land ON whole seat multiples,
                // because nearest-rounded seating rewards nothing else).
                $seedSets = [];
                foreach ($component as $firstSeed) {
                    $seedSets[] = $this->farPointSeeds($firstSeed, $k, $component, $centroids);
                }
                $seedSets[] = array_slice($byPop, 0, min($k, count($byPop)));

                $bfsCandidates = [];
                $this->stepBegin("phaseA.k{$k}");
                foreach ($seedSets as $seedIdx => $seeds) {
                    $this->beat($legislature_id, sprintf(
                        'k=%d: seed scan %d/%d', $k, $seedIdx + 1, count($seedSets)
                    ));
                    $bfsBins  = $this->geographicSeedExpansion($component, $childById, $adj, $centroids, $seeds, $giantThreshold, $floorBoundary, true, $compBudget, null, $compEdgeCapSq);
                    $binPopsA = array_map(
                        fn($bin) => array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $bin)),
                        $bfsBins
                    );
                    // Satellites count toward their nearest bin in the proxy too.
                    foreach ($mySats as $sat) {
                        $satPop = array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $sat));
                        $bestBi = 0; $bestD = PHP_FLOAT_MAX;
                        foreach ($bfsBins as $bi => $b) {
                            $d = $this->closestApproachSq($sat, $b, $centroids);
                            if ($d < $bestD) { $bestD = $d; $bestBi = $bi; }
                        }
                        $binPopsA[$bestBi] += $satPop;
                    }
                    $devProxy = 0.0;
                    if ($quotaPopC > 0) {
                        $targetsA = $this->optimalIntegerTargets($binPopsA, $quotaPopC, $compBudget, $floor, $ceiling);
                        foreach ($binPopsA as $bi => $bp) {
                            $devProxy += abs($bp - $targetsA[$bi] * $quotaPopC);
                        }
                    } else {
                        foreach ($binPopsA as $bp) {
                            $devProxy += abs($bp - $targetPopK);
                        }
                    }
                    $bfsCandidates[] = ['seeds' => $seeds, 'dev' => $devProxy];
                }
                usort($bfsCandidates, fn($a, $b) => $a['dev'] <=> $b['dev']);
                $this->stepEnd("phaseA.k{$k}");

                // ── Phase B: Full pipeline (balance + compact + balance) on top 20 ────
                // Running the full pipeline on every seed set is unnecessary; the integer-
                // target proxy reliably ranks which starting configurations converge well.
                $topN = min(count($bfsCandidates), 20);
                $bestBinsK = null; $bestScoreK = null;
                $bestPhaseB = null; $bestPhaseBScore = null;

                // ── Land-then-compete (2026-07-18, the Texas strips probe) ──
                // The canonical-mix landing used to run only on each k's
                // ALREADY-SELECTED winner (compete-then-land) — but the
                // landing's biggest wins come from candidates that LOSE their
                // own k before landing. Texas: the k=10 sequential build
                // settles into 7 bins and, landed onto the 7-way canonical
                // [8,7,7,7,7,7,7], scores avg 1.95% / cut 4.02M m — beating
                // the shipped coastal strips (cut 4.45M m) under the UNTOUCHED
                // comparator; pre-landing it lost k=10 and was never landed.
                // Every built candidate now offers its landed variant to the
                // per-k competition. This pass proposes; scoreBeats disposes.
                $landedVariant = function (array $bins, array $score) use ($virtualize, $childById, $centroids, $adj, $quotaPopC, $compBudget, $compBinPop, $floor, $ceiling, $giantThreshold, $floorBoundary): ?array {
                    if ($quotaPopC <= 0) return null;
                    $live = array_values(array_filter($bins, fn($b) => !empty($b)));
                    if (count($live) < 2) return null;
                    $parts = $this->canonicalPartition($compBudget, count($live), $floor, $ceiling);
                    if ($parts === null) return null;
                    if (($score['seat_spread'] ?? 0) <= (max($parts) - min($parts)) && (($score['seat_drift'] ?? 0) <= 0)) {
                        return null;
                    }
                    $landed = $this->landSeatVector($live, $parts, $childById, $centroids, $adj, $quotaPopC, $floor, $ceiling, $giantThreshold, $floorBoundary);
                    if ($landed === $live) return null;
                    $effBudget = max(count($landed), $compBudget);

                    return [$landed, $this->scoreConfiguration($virtualize($landed), $childById, $adj, (float) $compBinPop, $effBudget, $floor, $ceiling, $floorBoundary)];
                };

                $this->stepBegin("phaseB.k{$k}");
                foreach (array_slice($bfsCandidates, 0, $topN) as $candIdx => $candidate) {
                    $this->beat($legislature_id, sprintf(
                        'k=%d: refining candidate %d/%d', $k, $candIdx + 1, $topN
                    ));
                    $bins = $this->geographicSeedExpansion($component, $childById, $adj, $centroids, $candidate['seeds'], $giantThreshold, $floorBoundary, false, $compBudget, null, $compEdgeCapSq);

                    $effectiveBudget = max(count($bins), $compBudget);

                    $score = $this->scoreConfiguration($virtualize($bins), $childById, $adj, (float) $compBinPop, $effectiveBudget, $floor, $ceiling, $floorBoundary);

                    if ($bestScoreK === null || $this->scoreBeats($score, $bestScoreK)) {
                        $bestBinsK  = $bins;
                        $bestScoreK = $score;
                    }
                    if ($bestPhaseBScore === null || $this->scoreBeats($score, $bestPhaseBScore)) {
                        $bestPhaseB      = $bins;
                        $bestPhaseBScore = $score;
                    }
                    if (($lv = $landedVariant($bins, $score)) !== null) {
                        if ($bestScoreK === null || $this->scoreBeats($lv[1], $bestScoreK)) {
                            $bestBinsK  = $lv[0];
                            $bestScoreK = $lv[1];
                        }
                        if ($bestPhaseBScore === null || $this->scoreBeats($lv[1], $bestPhaseBScore)) {
                            $bestPhaseB      = $lv[0];
                            $bestPhaseBScore = $lv[1];
                        }
                    }
                }

                $this->stepEnd("phaseB.k{$k}");

                // ── Sequential constructive candidates (round-5): the operator's
                // manual method as a generator. Build toward the most-equal
                // partition of this budget at this k, in both build orders
                // (big districts first / small first), polish with a clean-only
                // rebalance toward the same partition, and let them compete.
                // These reach the canonical configurations the transfer-walking
                // passes stall on (Egypt 7+7+7+7, Russia 9+9+9+9, Bihar 9+8+8+8).
                if ($quotaPopC > 0) {
                    $parts = $this->canonicalPartition($compBudget, $k, $floor, $ceiling);
                    if ($parts !== null) {
                        // Both builder flavors per order (round-8.1, the Mexico
                        // probe): the adaptive flavor wins archipelagos and fat
                        // atoms, the fixed-target flavor won Mexico's 0.84% —
                        // generate both, let the comparator pick per scope.
                        // Metro-seeded pair (Good Maps 2026-08-23): city cores
                        // first, big targets first — reaches the standard's
                        // metro districts (Michigan's Wayne+Oakland+Macomb)
                        // that corner seeding structurally splits.
                        $this->stepBegin("builders.k{$k}");
                        foreach ([[true, true, false], [true, false, false], [false, true, false], [false, false, false], [true, true, true], [true, false, true]] as [$bigFirst, $adaptive, $metroSeed]) {
                            $this->beat($legislature_id, sprintf('k=%d: sequential builders', $k));
                            $sBins = $this->sequentialBuild($component, $childById, $adj, $centroids, $compBudget, $k, $quotaPopC, $giantThreshold, $floor, $ceiling, $bigFirst, $adaptive, $metroSeed);
                            if ($sBins === null) continue;
                            $sBins = $this->breakRebalance($sBins, $childById, $centroids, $adj, $quotaPopC, $compBudget, $floor, $ceiling, $giantThreshold, $floorBoundary, $parts, true);
                            // Round-7: the built candidate gets the same shape passes
                            // Phase-B candidates get (compact exchanges + border
                            // smoothing under integer-target caps) — the remainder
                            // crescent becomes blocks at bounded balance cost.
                            $sBins = $this->geographicSeedExpansion($component, $childById, $adj, $centroids, [], $giantThreshold, $floorBoundary, false, $compBudget, $sBins, $compEdgeCapSq);
                            $effectiveBudget = max(count($sBins), $compBudget);
                            $sScore = $this->scoreConfiguration($virtualize($sBins), $childById, $adj, (float) $compBinPop, $effectiveBudget, $floor, $ceiling, $floorBoundary);
                            if ($bestScoreK === null || $this->scoreBeats($sScore, $bestScoreK)) {
                                $bestBinsK  = $sBins;
                                $bestScoreK = $sScore;
                            }
                            if (($lv = $landedVariant($sBins, $sScore)) !== null
                                && ($bestScoreK === null || $this->scoreBeats($lv[1], $bestScoreK))) {
                                $bestBinsK  = $lv[0];
                                $bestScoreK = $lv[1];
                            }
                        }

                        // ── Bisection candidates (round 12, the São Paulo snake):
                        // the BFS growers and the sequential builder both grow
                        // regions member-by-member, so the border is an emergent
                        // scar — on population-lopsided scopes it snakes, and no
                        // local pass can unwind it (the round-10 probe: greedy
                        // repair plateaued at 7× the manual border). The operator
                        // draws the BORDER first: a short line through the
                        // population field with whole seat multiples on each
                        // side. This generator mechanizes exactly that — sweep a
                        // line across 12 directions, cut the member list at the
                        // canonical population boundary, repair stray fragments,
                        // recurse for k > 2 — then each candidate takes the same
                        // polish as the sequential ones and competes under the
                        // full comparator (cut_length recognizes blocky borders
                        // on sight since round 10).
                        $this->stepEnd("builders.k{$k}");

                        $this->stepBegin("bisection.k{$k}");
                        foreach ($this->bisectionCandidates($component, $childById, $adj, $centroids, $compBudget, $k, $quotaPopC, $floor, $ceiling) as $bBins) {
                            $this->beat($legislature_id, sprintf('k=%d: bisection sweep', $k));
                            $bBins = $this->breakRebalance($bBins, $childById, $centroids, $adj, $quotaPopC, $compBudget, $floor, $ceiling, $giantThreshold, $floorBoundary, $parts, true);
                            $bBins = $this->geographicSeedExpansion($component, $childById, $adj, $centroids, [], $giantThreshold, $floorBoundary, false, $compBudget, $bBins, $compEdgeCapSq);
                            if (!$bBins) continue;
                            $effectiveBudget = max(count($bBins), $compBudget);
                            $bScore = $this->scoreConfiguration($virtualize($bBins), $childById, $adj, (float) $compBinPop, $effectiveBudget, $floor, $ceiling, $floorBoundary);
                            if ($bestScoreK === null || $this->scoreBeats($bScore, $bestScoreK)) {
                                $bestBinsK  = $bBins;
                                $bestScoreK = $bScore;
                            }
                            if (($lv = $landedVariant($bBins, $bScore)) !== null
                                && ($bestScoreK === null || $this->scoreBeats($lv[1], $bestScoreK))) {
                                $bestBinsK  = $lv[0];
                                $bestScoreK = $lv[1];
                            }
                        }
                    }
                }

                if ($bestBinsK !== null) {
                    $candidateConfigs[] = ['bins' => $bestBinsK, 'score' => $bestScoreK];
                    // Good Maps pool telemetry (2026-08-23): one line per k so a
                    // wrong across-k choice (the California probe: k=10 keeps
                    // beating the standard's fat k=9 class) is diagnosable from
                    // the run itself — which keys each k's best lost on, without
                    // re-running the scope. Same channel as the line-first shadow.
                    \Illuminate\Support\Facades\Log::info('districting pool k-best', [
                        'legislature_id' => $legislature_id,
                        'scope_id'       => $scopeId,
                        'component'      => $componentIdx,
                        'k'              => $k,
                        'bins'           => count($bestBinsK),
                        'rank'           => $this->scoreRank($bestScoreK),
                    ]);
                }
                // Round-8.1 (the Mexico probe): the per-k winner is often a built
                // candidate whose shape stalls the downstream walkers, while the
                // Phase-B best is a better BASE for the equalizer and break
                // variants (Draft 4's lost 0.84% Mexico was exactly a walked
                // Phase-B base). Keep the Phase-B best as its own candidate so
                // the variant machinery below walks BOTH.
                if ($bestPhaseB !== null && $bestPhaseB !== $bestBinsK) {
                    $candidateConfigs[] = ['bins' => $bestPhaseB, 'score' => $bestPhaseBScore];
                }
            }

            // ── Deliberate-break variants: balance may buy a contiguity break ─────────
            // The operator's last resort, mechanized: "Sometimes … I have to break
            // contiguity in order to be above the floor and below the ceiling."
            // Only candidates still >2.5% off a whole seat target on their worst district
            // spawn a variant, and the variant must WIN under scoreRank() (a full equality
            // band or better) to displace the contiguous configuration.
            $bestBins = null; $bestScore = null;
            $this->stepBegin('variants');
            foreach ($candidateConfigs as $cfg) {
                $this->beat($legislature_id, 'weighing break / equalization variants');
                if ($bestScore === null || $this->scoreBeats($cfg['score'], $bestScore)) {
                    $bestBins  = $cfg['bins'];
                    $bestScore = $cfg['score'];
                }
                // Seat-mix equalization variant (round-3 tuning: "it is giving up
                // reps per district balance long before it has to"; round-11
                // rework, the Draft-11 spread flags). When the most-equal legal
                // partition of this budget (6/6/6 for 18) is more even than the
                // winner's mix (7/6/5), or the winner's nearest-rounds miss the
                // budget (exactness rule), land the canonical vector with the
                // FEASIBILITY-AWARE pass — the old breakRebalance equalizer
                // chased arithmetic targets without checking which moves exist
                // and stalled on fat atoms and scattered pools (Hubei shipped
                // 8+6 with 7+7 one clean move away; Oromia 8+5; Vietnam 9+9+7;
                // Russia's 9+9+9+9 never materialized). scoreRank's seat_spread
                // slot still decides whether the balance it costs was worth it.
                if ($quotaPopC > 0) {
                    // NON-EMPTY bin count (2026-07-18): a candidate carrying
                    // empty bins aimed this landing at the wrong canonical
                    // partition, and landSeatVector's own count guard then
                    // turned the whole pass into a silent no-op.
                    $parts = $this->canonicalPartition($compBudget, count(array_filter($cfg['bins'], fn($b) => !empty($b))), $floor, $ceiling);
                    if ($parts !== null && ($cfg['score']['seat_spread'] > (max($parts) - min($parts))
                        || ($cfg['score']['seat_drift'] ?? 0) > 0)) {
                        $cfgLive = array_values(array_filter($cfg['bins'], fn($b) => !empty($b)));
                        $equalized = $this->landSeatVector($cfgLive, $parts, $childById, $centroids, $adj, $quotaPopC, $floor, $ceiling, $giantThreshold, $floorBoundary);
                        if ($equalized !== $cfgLive) {
                            $effectiveBudget = max(count($equalized), $compBudget);
                            $eScore = $this->scoreConfiguration($virtualize($equalized), $childById, $adj, (float) $compBinPop, $effectiveBudget, $floor, $ceiling, $floorBoundary);
                            if ($this->scoreBeats($eScore, $bestScore)) {
                                $bestBins  = $equalized;
                                $bestScore = $eScore;
                            }
                        }
                    }
                }
                if ($quotaPopC > 0 && $cfg['score']['max_deviation_pct'] > 2.5) {
                    $broken = $this->breakRebalance($cfg['bins'], $childById, $centroids, $adj, $quotaPopC, $compBudget, $floor, $ceiling, $giantThreshold, $floorBoundary);
                    if ($broken !== $cfg['bins']) {
                        $effectiveBudget = max(count($broken), $compBudget);
                        $bScore = $this->scoreConfiguration($virtualize($broken), $childById, $adj, (float) $compBinPop, $effectiveBudget, $floor, $ceiling, $floorBoundary);
                        if ($this->scoreBeats($bScore, $bestScore)) {
                            $bestBins  = $broken;
                            $bestScore = $bScore;
                        }
                    }
                }
            }

            $this->stepEnd('variants');

            // Shadow comparison: the border-first candidate did not compete, so
            // record how it WOULD have scored against the map the search chose.
            // One line per component; this is the evidence the 'auto' flip is
            // supposed to rest on.
            if ($lineFirst !== null && ! $adoptLineFirst && $bestScore !== null) {
                \Illuminate\Support\Facades\Log::info('districting line-first shadow', [
                    'legislature_id'  => $legislature_id,
                    'scope_id'        => $scopeId,
                    'children'        => count($component),
                    'budget'          => $compBudget,
                    'line_first_k'    => $lineFirst['k'],
                    'line_first_clean'=> $lineFirst['clean'],
                    'line_first_rank' => $this->scoreRank($lineFirst['score']),
                    'search_rank'     => $this->scoreRank($bestScore),
                    'line_first_wins' => $this->scoreBeats($lineFirst['score'], $bestScore),
                    'line_first_ms'   => $this->stepMs['linefirst'] ?? null,
                ]);
            }

            $allBins = array_merge($allBins, $bestBins ?? [$component]);
        }

        $this->publishMassProgress($legislature_id, [
            'phase'         => 'binning_done',
            'phase_label'   => sprintf('Bin partitioning complete: %d bins formed', count($allBins)),
            'phase_current' => count($allBins),
            'phase_total'   => count($allBins),
        ]);

        // Cross-component post-repair (the ATTACHMENT plane — runs AFTER scoring, so
        // it must be conservative). Merges bins that cannot legally round to the
        // floor (fractional < floorBoundary − 0.5) into their nearest absorbable
        // bin. Two round-2 rematch fixes live here (the Zhoushan mis-attachment):
        //   • Trigger is the OVERRIDE boundary, not the floor: a 4.79-frac mainland
        //     bin rounds to 5 seats legally (floor_override flags it for audit) and
        //     must NOT grab a far-away island as population ballast to cross 5.0.
        //   • Proximity is CLOSEST APPROACH (nearest member pair), never unweighted
        //     bin centroids — an island belongs to the nearest SHORE, and adjacent
        //     orphan islands thereby ride together to the same coast.
        $globalBinFracs = array_map(fn($bin) =>
            array_sum(array_map(fn($jid) => (float) $childById[$jid]->fractional_seats, $bin)),
            $allBins
        );

        $mergeBoundary = $floorBoundary - 0.5;
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($globalBinFracs as $i => $t) {
                if ($t >= $mergeBoundary || empty($allBins[$i])) continue;
                $bestJ    = -1;
                $bestDist = PHP_FLOAT_MAX;
                foreach ($globalBinFracs as $j => $tj) {
                    if ($j === $i || empty($allBins[$j])) continue;
                    if ($tj + $t >= $giantThreshold) continue;
                    $d = $this->closestApproachSq($allBins[$i], $allBins[$j], $centroids);
                    if ($d < $bestDist) { $bestDist = $d; $bestJ = $j; }
                }
                if ($bestJ >= 0) {
                    $allBins[$bestJ]        = array_merge($allBins[$bestJ], $allBins[$i]);
                    $globalBinFracs[$bestJ] += $globalBinFracs[$i];
                    $allBins[$i]            = [];
                    $globalBinFracs[$i]     = 0.0;
                    $changed = true;
                    break;
                }
            }
        }

        // Floor feasibility backstop: Step 11 needs binCount × floor ≤ budget. If
        // legal-but-small bins (override..floor) left too many bins standing, merge
        // the smallest into its closest absorbable neighbor until feasible — never
        // hand Step 11 a bin count that forces sub-floor seat vectors.
        while (true) {
            $live = array_keys(array_filter($allBins, fn($b) => !empty($b)));
            if (count($live) * $floor <= max($nonGiantBudget, $floor)) break;
            $minI = -1; $minT = PHP_FLOAT_MAX;
            foreach ($live as $li) {
                if ($globalBinFracs[$li] < $minT) { $minT = $globalBinFracs[$li]; $minI = $li; }
            }
            $bestJ = -1; $bestDist = PHP_FLOAT_MAX;
            foreach ($live as $lj) {
                if ($lj === $minI) continue;
                if ($globalBinFracs[$lj] + $minT >= $giantThreshold) continue;
                $d = $this->closestApproachSq($allBins[$minI], $allBins[$lj], $centroids);
                if ($d < $bestDist) { $bestDist = $d; $bestJ = $lj; }
            }
            if ($minI < 0 || $bestJ < 0) break;   // nothing mergeable — Step 11's safety paths take over
            $allBins[$bestJ]        = array_merge($allBins[$bestJ], $allBins[$minI]);
            $globalBinFracs[$bestJ] += $globalBinFracs[$minI];
            $allBins[$minI]         = [];
            $globalBinFracs[$minI]  = 0.0;
        }
        $allBins = array_values(array_filter($allBins, fn($b) => !empty($b)));

        // ── Post-attachment rebalance (round-5, mechanism 1) ──────────────────
        // Island attachment above shifts population AFTER every candidate was
        // scored and refined — Tanzania's 8+8 skeleton matched the operator's
        // exactly yet sat at 4.2%/4.2% vs his 0.1%/0.1%, purely because Zanzibar
        // landed on one side post-hoc. One CLEAN-ONLY rebalance over the FINAL
        // bin set (contiguity-preserving transfers only; islands never move)
        // lets the mainland compensate before Step 11 seats the bins.
        if (count($allBins) >= 2 && $nonGiantBudget > 0) {
            $popAll = 0.0;
            foreach ($allBins as $b) {
                foreach ($b as $jid) {
                    $popAll += (float) $childById[$jid]->population;
                }
            }
            $quotaPopAll = $popAll / max($nonGiantBudget, 1);
            if ($quotaPopAll > 0) {
                $allBins = $this->breakRebalance($allBins, $childById, $centroids, $adj, $quotaPopAll, $nonGiantBudget, $floor, $ceiling, $giantThreshold, $floorBoundary, null, true);

                // ── Budget-exactness repair over the FINAL bin set (operator
                // ruling 2026-07-13, the Draft-9 India rematch). The seat_drift
                // comparator key only referees candidate COMPETITIONS — but a
                // component below the giant threshold becomes a bin without ever
                // entering the k-loop, so a scattered-smalls pool (India's 23
                // non-giant states between the locked giants: four SINGLE-state
                // districts + attachment clusters) can round down a hair per bin
                // and drift with no scoring plane ever seeing the sum. The clean
                // rebalance above cannot fix it: the bins are separate
                // components, and contiguity-preserving transfers cannot cross
                // the gaps.
                //
                // Draft-9 rematch #2 (China +1, Earth +2): a break-tolerant walk
                // toward optimalIntegerTargets stalls here, because the target
                // optimizer is indivisibility-blind — it keeps demanding the
                // arithmetically-cheapest correction from a SINGLE-member
                // district (China's 7.560 province, Earth's 6.574/6.589 country
                // districts) that has nothing to give, while feasible
                // corrections sit one rank down the cost list. The repair is
                // therefore a direct feasibility-aware BOUNDARY NUDGE
                // (landPoolBudget): enumerate real (donor member → receiver)
                // moves that change the nearest-rounded sum by exactly one unit
                // while both bins stay in the round-to-legal window, prefer the
                // closest-fragment move (doctrine: breaks purchasable, pieces
                // close, islands never ballast), repeat per unit of drift. When
                // no feasible nudge exists (indivisible atoms), the drifted map
                // ships under the undercount flag, per the pin-16 fallback.
                $allBins = $this->landPoolBudget($allBins, $childById, $centroids, $adj, $quotaPopAll, $nonGiantBudget, $floor, $ceiling, $giantThreshold, $floorBoundary);

                // ── Canonical-mix landing over the FINAL bin set (round 11).
                // Scattered-pool scopes finalize here without any candidate
                // competition (auto-binned components + attachments), so the
                // k-loop equalizer never sees them — the Japan / Ethiopia /
                // Philippines spread class. When the final mix is less even
                // than the canonical partition, attempt the feasibility-aware
                // landing — and because this plane has no competition, the
                // result is COMPARATOR-GATED: it replaces the map only when
                // it wins under the full doctrine ladder (a landing that buys
                // its spread with too many breaks or too much deviation is
                // discarded). Canonical targets sum to the budget, so budget
                // exactness survives the landing by construction.
                $allBins = array_values(array_filter($allBins, fn($b) => !empty($b)));
                $finalParts = $this->canonicalPartition($nonGiantBudget, count($allBins), $floor, $ceiling);
                if ($finalParts !== null && count($allBins) >= 2) {
                    $roundsNow = array_map(
                        fn($b) => max($floor, min($ceiling, (int) round(
                            array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $b)) / $quotaPopAll
                        ))),
                        $allBins
                    );
                    $spreadNow   = max($roundsNow) - min($roundsNow);
                    $spreadCanon = max($finalParts) - min($finalParts);

                    // DRIFT IS ALWAYS WRONG (operator ruling 2026-07-26). The
                    // chamber size is FIXED by the cube-root law, so a total
                    // that misses the budget leaves seats unfillable or
                    // unallotted — never "close enough". Two gates here used to
                    // suppress the repair and were BOTH wrong:
                    //   (a) the landing only ran when SPREAD was worse than
                    //       canonical — a scope with a fine spread but a wrong
                    //       TOTAL never attempted it at all;
                    //   (b) even when it ran and landed exactly, the doctrine
                    //       comparator could discard it.
                    // Now: attempt whenever the total misses, and an EXACT
                    // landing is adopted unconditionally — exactness outranks
                    // spread, compactness and every other preference, because
                    // those are qualities and this is the law.
                    $sumNow     = array_sum($roundsNow);
                    $driftHere  = $sumNow !== $nonGiantBudget;
                    if ($spreadNow > $spreadCanon || $driftHere) {
                        $landed = $this->landSeatVector($allBins, $finalParts, $childById, $centroids, $adj, $quotaPopAll, $floor, $ceiling, $giantThreshold, $floorBoundary);
                        if ($landed !== $allBins) {
                            $landedSum = array_sum(array_map(
                                fn ($b) => max($floor, min($ceiling, (int) round(
                                    array_sum(array_map(fn ($jid) => (float) $childById[$jid]->population, $b)) / $quotaPopAll
                                ))),
                                $landed
                            ));
                            if ($driftHere && $landedSum === $nonGiantBudget) {
                                $allBins = $landed;   // exact beats everything
                            } else {
                                $before = $this->scoreConfiguration($allBins, $childById, $adj, (float) $popAll, $nonGiantBudget, $floor, $ceiling, $floorBoundary);
                                $after  = $this->scoreConfiguration($landed, $childById, $adj, (float) $popAll, $nonGiantBudget, $floor, $ceiling, $floorBoundary);
                                if ($this->scoreBeats($after, $before)) {
                                    $allBins = $landed;
                                }
                            }
                        }
                    }
                }
            }
        }

        // ── Step 8c: HULL REPAIR on the final drawing (Good Maps, 2026-08-23) ──
        // The in-loop shape currency is cut length; the standard is measured in
        // convex hull ratio, and on concave/coastal scopes the two anticorrelate
        // (iter-5: Ukraine's shorter-cut re-split dropped the REAL hull ratio
        // .738 → .662; West Java lost a tied .792 the same way). One bounded
        // round over the FINAL bins: per touching pair, hull-check the incumbent
        // split against the cut-best and Rg²-best of the pair's 12 bisection
        // candidates — the exact recomputeDistrict formula, so the pass
        // optimizes the reported number itself — and adopt a strictly better
        // mean under the standing guards. Final-config-only keeps the PostGIS
        // cost to 2-3 union calls per pair.
        // Contiguity outranks compactness (the operator's order), so the break
        // repair runs FIRST: consolidate avoidable fragments into the fewest
        // districts and re-split pairs whose mainland was broken by a balance
        // variant. Hull repair then polishes shape without re-introducing
        // breaks (its mainland-connectivity guard forbids them).
        $allBins = $this->breakRepairPass(
            $allBins, $childById, $adj, $centroids, $legislature_id,
            $nonGiantBudget, $floor, $ceiling, $giantThreshold, $floorBoundary
        );
        $allBins = $this->hullRepairPass(
            $allBins, $childById, $adj, $centroids, $legislature_id,
            $nonGiantBudget, $floor, $ceiling, $giantThreshold, $floorBoundary
        );

        // ── Step 9: Clear existing districts if requested ─────────────────────
        if ($clearExisting) {
            $this->publishMassProgress($legislature_id, [
                'phase'       => 'clearing',
                'phase_label' => 'Clearing existing districts at this scope',
            ]);
            // Clear null-jurisdiction composites whose members are direct children of this scope
            $nullClearQuery = DB::table('legislature_districts AS ld')
                ->join('legislature_district_jurisdictions AS ldj', 'ldj.district_id', '=', 'ld.id')
                ->join('jurisdictions AS j', 'j.id', '=', 'ldj.jurisdiction_id')
                ->where('ld.legislature_id', $legislature_id)
                ->whereNull('ld.jurisdiction_id')
                ->where('j.parent_id', $scopeId)
                ->whereNull('j.deleted_at');
            if ($mapId !== null) {
                $nullClearQuery->where('ld.map_id', $mapId);
            }
            $nullIds = $nullClearQuery->distinct()->pluck('ld.id')->toArray();
            foreach ($nullIds as $eid) {
                DB::table('legislature_district_jurisdictions')->where('district_id', $eid)->delete();
                DB::table('legislature_districts')->where('id', $eid)->delete();
            }

            // Clear districts scoped directly to this jurisdiction
            $existClearQuery = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('jurisdiction_id', $scopeId);
            if ($mapId !== null) {
                $existClearQuery->where('map_id', $mapId);
            }
            $existingIds = $existClearQuery->pluck('id');
            foreach ($existingIds as $eid) {
                DB::table('legislature_district_jurisdictions')->where('district_id', $eid)->delete();
                DB::table('legislature_districts')->where('id', $eid)->delete();
            }
        }

        // ── Step 10: Collect bin populations ─────────────────────────────────
        $binData = [];
        foreach ($allBins as $binJids) {
            if (empty($binJids)) continue;
            $pop = array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $binJids));
            $binData[] = [
                'jids'          => $binJids,
                'pop'           => $pop,
                'floor_override'=> false,   // set in Step 11
                'seats'         => 0,       // set in Step 11
                'fractional'    => 0.0,     // set in Step 11
            ];
        }

        // ── Step 10b: ZERO-POP ABSORPTION (2026-07-23, rotten-borough fix) ───
        // A bin holding zero people must never seat: territory has to be
        // covered, but a district over no electors is a rotten borough —
        // Step 11's minimum-seat clamp was minting 1-seat (floor-infeasible)
        // and 5-seat (floor-feasible) districts over zero-pop scattered
        // remainders. Each zero-pop bin merges into a LIVE sibling bin —
        // adjacency first (longest shared border, the compactness currency),
        // nearest-centroid fallback — adding zero population and zero seats,
        // so every surviving bin's share, rounding, and the pool budget are
        // untouched. A scope with no live sibling keeps its zero-pop bin
        // (nothing to absorb into); the completeness gate reviews it honestly.
        // CRUMB EXTENSION (2026-07-25, the De'an class): a bin whose seat
        // ENTITLEMENT rounds to ZERO (frac < 0.5 — 445 people against a
        // 4,160 quota) is as unseatable as a zero-pop bin: Step 11's clamp
        // would mint it 1 (or floor) unearned seats, over-representing its
        // few residents ~9x and drifting the pool. Crumbs merge like
        // zero-pop bins — but their PEOPLE ride along into the target
        // (represented by the neighbor's seats, one-person-one-vote served).
        // Bins with frac in [0.5, floorBoundary) keep the sanctioned
        // floor_override posture — only round-to-zero entitlements merge.
        $crumbCutoff = $nonGiantBudget > 0
            ? (array_sum(array_column($binData, 'pop')) / $nonGiantBudget) * 0.5
            : 0;
        $zeroIdx = [];
        $liveIdx = [];
        foreach ($binData as $i => $b) {
            if ($b['pop'] === 0 || ($crumbCutoff > 0 && $b['pop'] < $crumbCutoff)) {
                $zeroIdx[] = $i;
            } else {
                $liveIdx[] = $i;
            }
        }
        if ($zeroIdx !== [] && $liveIdx !== []) {
            foreach ($zeroIdx as $zi) {
                $target = null;
                $bestBorder = 0.0;
                foreach ($liveIdx as $li) {
                    $shared = 0.0;
                    foreach ($binData[$zi]['jids'] as $zj) {
                        foreach ($adj[$zj] ?? [] as $nb) {
                            if (in_array($nb, $binData[$li]['jids'], true)) {
                                $shared += $this->borderLen[$zj.'|'.$nb]
                                    ?? $this->borderLen[$nb.'|'.$zj] ?? 0.0;
                                $shared = max($shared, 1e-9); // adjacency with unrecorded length still counts
                            }
                        }
                    }
                    if ($shared > $bestBorder) {
                        $bestBorder = $shared;
                        $target = $li;
                    }
                }
                if ($target === null) {
                    // No adjacent live bin (scattered confetti): nearest live
                    // bin by minimum centroid pair distance, ties to the
                    // lowest bin index (determinism is a settled property).
                    $bestD = INF;
                    foreach ($liveIdx as $li) {
                        foreach ($binData[$zi]['jids'] as $zj) {
                            foreach ($binData[$li]['jids'] as $lj) {
                                $zc = $centroids[$zj] ?? null;
                                $lc = $centroids[$lj] ?? null;
                                if ($zc === null || $lc === null) continue;
                                $d = ($zc['x'] - $lc['x']) ** 2 + ($zc['y'] - $lc['y']) ** 2;
                                if ($d < $bestD) {
                                    $bestD = $d;
                                    $target = $li;
                                }
                            }
                        }
                    }
                    $target ??= $liveIdx[0];
                }
                $binData[$target]['jids'] = array_merge($binData[$target]['jids'], $binData[$zi]['jids']);
                // The crumb's people ride into the target (zero-pop bins add
                // nothing; De'an-class crumbs add their few hundred).
                $binData[$target]['pop'] += $binData[$zi]['pop'];
                $binData[$zi]['jids'] = [];
                $binData[$zi]['pop'] = 0;
            }
            $binData = array_values(array_filter($binData, fn($b) => $b['jids'] !== []));
        }

        // ── Step 11: Seat each drawn district by NEAREST ROUNDING ───────────
        // Operator ruling 2026-07-13 (settled law): "It always rounds to
        // nearest. … The rounding takes place in the giant splitting phase and
        // the district drawing phase. The district when drawn should round to
        // the nearest integer. There is no rebudgeting a district after giants
        // are split."
        //
        // Apportionment rounding therefore lives in exactly two places: the
        // giant-splitting phase (fracs ≥ ceiling+0.5 round to nearest and
        // lock; the remainder redistributes among what's left — Steps 2-5 and
        // computeSeatBudget()), and here, where each drawn district rounds to
        // nearest INDEPENDENTLY. When the drawn shares miss whole multiples,
        // the seated total may drift from the pool budget (a 5.46-frac
        // district seats 5, leaving a 61-seat pool seating 60; a 7.53-frac
        // district seats 8, pushing its pool one over). That drift is the
        // DRAWING's defect — visible, honest, and fixable by redrawing — and
        // is deliberately NOT papered over by any total-forcing loop. No
        // Webster / Sainte-Laguë / largest-remainder methods: those exist to
        // force a total, and forcing the total is exactly what the ruling
        // forbids.
        //
        // The constitutional floor/ceiling stay hard clamps: fracs in
        // [floorBoundary−0.5, floorBoundary) round up to the floor with
        // floor_override recorded for audit; out-of-range bins (possible only
        // through the attachment/backstop planes or degenerate budgets) are
        // clamped the same way. When the budget cannot support the floor for
        // every bin (degenerate tiny scopes), rounding still applies with a
        // 1-seat minimum, mirroring the old distribute-what-is-available
        // behavior.
        $totalBinPop     = array_sum(array_column($binData, 'pop'));
        $effectiveBudget = $nonGiantBudget;
        $binCount        = count($binData);
        $floorFeasible   = ($effectiveBudget >= $binCount * $floor);
        $minSeat         = $floorFeasible ? $floor : 1;
        $binQuota        = $totalBinPop / max($effectiveBudget, 1);

        foreach ($binData as &$b) {
            $b['fractional']     = $b['pop'] / max($binQuota, 1);
            $b['floor_override'] = $b['fractional'] < $floorBoundary;
            $b['seats']          = max($minSeat, min($ceiling, (int) round($b['fractional'])));
        }
        unset($b);

        // ── Step 11b: THE EXACTNESS RULE ON THE COMPOSITE PLANE ─────────────
        // (operator ruling 2026-07-24, "there is no such thing as a lawful
        // drift map" — seating law step 6 generalized from the drawn plane.)
        // When the chosen bins' nearest-rounded seats MISS the pool budget,
        // a drifting configuration is EXCLUDED if another configuration
        // lands exactly: for scopes with <= 10 live children, exhaustively
        // enumerate set partitions under Step 11's own arithmetic (partition
        // quota, minSeat, ceiling clamp), keep those whose seats sum to the
        // budget EXACTLY, and adopt the best by the standing doctrine ladder
        // (scoreConfiguration: banded balance > contiguity > compactness).
        // The Mbwe class proved the gap: 4 children with fracs summing 25.00
        // filed 24 while the trivial one-per-child partition lands 25. When
        // NO exact partition exists the current bins ship unchanged — a
        // PROVEN indivisible atom (Pinland pin 4 stays +1 by exhaustion).
        $seatSum = array_sum(array_column($binData, 'seats'));
        $allJids = array_merge(...array_map(fn ($b) => $b['jids'], $binData ?: [['jids' => []]]));
        // LARGE SCOPES GET A MOVE-BASED REPAIR (2026-07-26). The exhaustive
        // partition search below only runs at <= 10 children because set
        // partitions explode (Bell numbers) — and countries/states carry
        // 20-90 children, which is EXACTLY where drift concentrated (31% of
        // country population, 36% of state population). That limit was my
        // defect, not a property of the problem: closing a seat gap does not
        // need every partition, only a move that changes the rounded total by
        // one. Walk single-child moves between bins, take the one that
        // reduces |gap| while both bins stay inside the band, and repeat.
        if ($seatSum !== $effectiveBudget && count($allJids) > 10 && $totalBinPop > 0) {
            $binData = $this->repairSeatSumByMoves($binData, $childById, $adj, $binQuota, $effectiveBudget, $floor, $ceiling, $minSeat);
            $seatSum = array_sum(array_column($binData, 'seats'));
        }

        if ($seatSum !== $effectiveBudget && count($allJids) >= 2 && count($allJids) <= 10 && $totalBinPop > 0) {
            $exact = [];
            $groups = [];
            $enumerate = function (int $i) use (&$enumerate, &$groups, &$exact, $allJids, $childById, $totalBinPop, $effectiveBudget, $floor, $ceiling): void {
                if (count($exact) >= 2000) {
                    return; // plenty of exact candidates — scoring picks the best
                }
                if ($i === count($allJids)) {
                    $k = count($groups);
                    $min = ($effectiveBudget >= $floor * $k) ? $floor : 1;
                    $quota = $totalBinPop / max($effectiveBudget, 1);
                    $sum = 0;
                    foreach ($groups as $g) {
                        $gp = 0;
                        foreach ($g as $jid) {
                            $gp += (int) $childById[$jid]->population;
                        }
                        $sum += max($min, min($ceiling, (int) round($gp / max($quota, 1))));
                        if ($sum > $effectiveBudget) {
                            return;
                        }
                    }
                    if ($sum === $effectiveBudget) {
                        $exact[] = array_map(fn ($g) => array_values($g), $groups);
                    }
                    return;
                }
                foreach ($groups as $gi => $_) {
                    $groups[$gi][] = $allJids[$i];
                    $enumerate($i + 1);
                    array_pop($groups[$gi]);
                }
                $groups[] = [$allJids[$i]];
                $enumerate($i + 1);
                array_pop($groups);
            };
            $enumerate(0);

            if ($exact !== []) {
                $best = null;
                $bestScore = null;
                foreach ($exact as $candidate) {
                    $score = $this->scoreConfiguration($candidate, $childById, $adj, (float) max($totalBinPop, 1), $effectiveBudget, $floor, $ceiling, $floorBoundary);
                    if ($best === null || $this->scoreBeats($score, $bestScore)) {
                        $best = $candidate;
                        $bestScore = $score;
                    }
                }
                // Rebuild binData from the winning exact partition and re-seat
                // it under the same Step 11 arithmetic.
                $binData = [];
                foreach ($best as $g) {
                    $gp = 0;
                    foreach ($g as $jid) {
                        $gp += (int) $childById[$jid]->population;
                    }
                    $binData[] = ['jids' => $g, 'pop' => $gp, 'floor_override' => false, 'seats' => 0, 'fractional' => 0.0];
                }
                $binCount      = count($binData);
                $floorFeasible = ($effectiveBudget >= $binCount * $floor);
                $minSeat       = $floorFeasible ? $floor : 1;
                foreach ($binData as &$b) {
                    $b['fractional']     = $b['pop'] / max($binQuota, 1);
                    $b['floor_override'] = $b['fractional'] < $floorBoundary;
                    $b['seats']          = max($minSeat, min($ceiling, (int) round($b['fractional'])));
                }
                unset($b);
            }
        }

        // ── Step 12: Insert districts ──────────────────────────────────────────
        // The district's `seats` value is the canonical seat budget for any
        // composite member at this scope. When a downstream caller needs a
        // member's locked seat budget, computeSeatBudget()'s Path 2 lookup
        // returns this district's `seats`. Giants (skipped here — Step 12
        // only inserts non-giant bins) take Path 3 and recompute their
        // budget via the parent cascade.
        $districtsCreated = 0;
        $totalDistricts   = count($binData);
        $this->publishMassProgress($legislature_id, [
            'phase'         => 'inserting',
            'phase_label'   => "Inserting {$totalDistricts} districts (computing geometry per district)",
            'phase_current' => 0,
            'phase_total'   => $totalDistricts,
        ]);
        $this->stepBegin('step12.geometry');
        foreach ($binData as $binIdx => $bin) {
            // Per-district progress so the operator can tell whether a slow
            // scope is stuck on geometry computation (Step 12, dominant cost)
            // versus stuck in the bin-balancing inner loops (earlier steps).
            $this->publishMassProgress($legislature_id, [
                'phase'         => 'geometry',
                'phase_label'   => sprintf(
                    'District %d of %d — %d members, %d seats — running ST_Union…',
                    $binIdx + 1, $totalDistricts, count($bin['jids']), $bin['seats'],
                ),
                'phase_current' => $binIdx + 1,
                'phase_total'   => $totalDistricts,
            ]);

            $distNumQ = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('jurisdiction_id', $scopeId)
                ->whereNull('deleted_at');
            if ($mapId !== null) {
                $distNumQ->where('map_id', $mapId);
            }
            $districtNumber = 1 + (int) $distNumQ->max('district_number');

            $districtId = (string) \Illuminate\Support\Str::uuid();

            DB::table('legislature_districts')->insert([
                'id'               => $districtId,
                'legislature_id'   => $legislature_id,
                'map_id'           => $mapId,
                'jurisdiction_id'  => $scopeId,
                'district_number'  => $districtNumber,
                'seats'            => $bin['seats'],
                'fractional_seats' => $binQuota > 0 ? round($bin['pop'] / $binQuota, 6) : 0.0,
                'floor_override'   => $bin['floor_override'],
                'target_population'=> $bin['pop'],
                'actual_population'=> $bin['pop'],
                'status'           => 'active',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $memberships = array_map(fn($jid) => [
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'district_id'     => $districtId,
                'jurisdiction_id' => $jid,
            ], $bin['jids']);
            DB::table('legislature_district_jurisdictions')->insert($memberships);

            // Compute and cache spatial stats (convex_hull_ratio, num_geom_parts, is_contiguous)
            // so reseeded districts have stats immediately — same as manual create/update.
            // Pass $skipSeatsUpdate=true: Step 11 already seated the district against the
            // scope's pool quota; recomputeDistrict must not re-derive seats from a
            // different quota context.
            $this->recomputeDistrict($districtId, $legislature_id, $leg, true);

            $districtsCreated++;
        }
        $this->stepEnd('step12.geometry');

        // The one flush recomputeDistrict skipped per district above.
        Cache::tags(["revealed.{$legislature_id}"])->flush();

        // Final unthrottled beat so the scope's completed timing record lands
        // before the worker releases the claim — a throttled beat here could
        // be swallowed and the whole measurement lost.
        $this->publishMassProgress($legislature_id, [
            'phase'       => 'inserted',
            'phase_label' => sprintf('Inserted %d districts', $districtsCreated),
        ]);

        return ['districts_created' => $districtsCreated, 'error' => null];
    }

    /**
     * Compute the non-giant quota for a scope.
     *
     * When giants lock in integer seats (via computeSeatBudget() or round(frac)), the
     * remaining non-giant pool is apportioned over exactly (seatBudget − giantSeats) seats.
     * ngQuota = nonGiantPop / (seatBudget − giantSeats) guarantees SUM(non-giant fracs) is
     * exactly (seatBudget − giantSeats).  This mirrors what runAutoCompositeForScope() does
     * via $binQuota.  Returns $fullQuota unchanged when no giants are present.
     *
     * @param array  $allChildren     stdClass rows with ->population, ->fractional_seats
     *                                (full-quota), and optionally ->type_a_apportioned.
     * @param float  $fullQuota       effectivePop / seatBudget
     * @param int    $seatBudget      Total seat budget at this scope
     * @param int    $effectivePop    SUM(all direct children pops)
     * @param float  $giantThreshold  fractional ≥ this is a giant (ceiling + 0.5)
     * @param int    $floor           seat floor (composite minimum, e.g. 5)
     */
    public function computeNonGiantQuota(
        array $allChildren,
        float $fullQuota,
        int   $seatBudget,
        int   $effectivePop,
        float $giantThreshold,
        int   $floor
    ): float {
        $giantSeatsTotal = 0;
        $giantPopTotal   = 0;
        foreach ($allChildren as $c) {
            $frac = (float) ($c->fractional_seats ?? ((float) $c->population / max($fullQuota, 1)));
            if ($frac >= $giantThreshold) {
                $lockedSeats = isset($c->type_a_apportioned) && $c->type_a_apportioned !== null
                    ? (int) $c->type_a_apportioned
                    : max($floor, (int) round($frac));
                $giantSeatsTotal += $lockedSeats;
                $giantPopTotal   += (int) $c->population;
            }
        }
        if ($giantSeatsTotal === 0) return $fullQuota;
        $ngBudget = $seatBudget - $giantSeatsTotal;
        $ngPop    = $effectivePop - $giantPopTotal;
        if ($ngBudget <= 0 || $ngPop <= 0) {
            // ALL-GIANT SCOPE (2026-07-24): no non-giant pool exists, so there
            // is nothing to normalize — the old max(0,1)/max(0,1) degenerated
            // to quota 1, rendering 'Quota: 1 pop/seat' and garbage Rep/Dev%
            // on every single-child-chain and all-giant root (Nunavut,
            // Perpignan-3 class). The full quota is the honest basis.
            return $fullQuota;
        }
        return $ngPop / $ngBudget;
    }

    /**
     * SEAT-SUM REPAIR BY MOVES (operator ruling 2026-07-26: drift is always
     * wrong). Closes the gap between the bins' nearest-rounded seat total and
     * the pool budget WITHOUT enumerating partitions, so it scales to the
     * 20-90-child scopes where drift actually lives.
     *
     * Each step considers moving ONE child from a donor bin to a receiver bin
     * that touches it (adjacency preferred — a move across the map would buy
     * exactness with a scattered district), recomputes both bins' rounded
     * seats, and keeps the move that reduces |total - budget| the most while
     * leaving every bin non-empty and inside [minSeat, ceiling]. Deterministic
     * throughout: ties break on the lowest bin index, then the lowest child id.
     *
     * @param  array  $binData  [['jids'=>[], 'pop'=>int, 'seats'=>int, ...], ...]
     * @return array  the repaired bins (unchanged when no move helps)
     */
    private function repairSeatSumByMoves(
        array $binData,
        array $childById,
        array $adj,
        float $binQuota,
        int $effectiveBudget,
        int $floor,
        int $ceiling,
        int $minSeat
    ): array {
        $seatsOf = function (int $pop) use ($binQuota, $minSeat, $ceiling): int {
            return max($minSeat, min($ceiling, (int) round($pop / max($binQuota, 1))));
        };
        // Hashed membership, not a linear scan: this fires in the innermost
        // loop (donor bin × every member × receiver bin), so an in_array over a
        // 300-member bin was most of the repair's cost. array_flip on UUIDs
        // gives string keys — never numeric, so no key coercion — which makes
        // isset() exactly in_array(..., true).
        $touches = function (array $binSet, string $jid) use ($adj): bool {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (isset($binSet[$nb])) {
                    return true;
                }
            }
            return false;
        };

        $n = count($binData);
        for ($pass = 0; $pass < 200; $pass++) {
            $sum = 0;
            foreach ($binData as $b) {
                $sum += $seatsOf((int) $b['pop']);
            }
            $gap = $sum - $effectiveBudget;
            if ($gap === 0) {
                break;
            }

            // One flip per pass serves every adjacency test in it — $binData is
            // only mutated at the END of a pass, below.
            $binSets = [];
            foreach ($binData as $bi => $b) {
                $binSets[$bi] = array_flip($b['jids']);
            }

            $best = null;   // ['from'=>i,'to'=>j,'jid'=>x,'gain'=>int,'adjacent'=>bool]
            for ($i = 0; $i < $n; $i++) {
                if (count($binData[$i]['jids']) < 2) {
                    continue;   // never empty a bin — the district must survive
                }
                foreach ($binData[$i]['jids'] as $jid) {
                    $cp = (int) $childById[$jid]->population;
                    for ($j = 0; $j < $n; $j++) {
                        if ($i === $j) {
                            continue;
                        }
                        $newI = $seatsOf((int) $binData[$i]['pop'] - $cp);
                        $newJ = $seatsOf((int) $binData[$j]['pop'] + $cp);
                        $delta = ($newI + $newJ) - ($seatsOf((int) $binData[$i]['pop']) + $seatsOf((int) $binData[$j]['pop']));
                        $gain = abs($gap) - abs($gap + $delta);
                        if ($gain <= 0) {
                            continue;
                        }
                        $adjacent = $touches($binSets[$j], $jid);
                        $cand = ['from' => $i, 'to' => $j, 'jid' => $jid, 'gain' => $gain, 'adjacent' => $adjacent];
                        if ($best === null
                            || $cand['gain'] > $best['gain']
                            || ($cand['gain'] === $best['gain'] && $cand['adjacent'] && ! $best['adjacent'])) {
                            $best = $cand;
                        }
                    }
                }
            }
            if ($best === null) {
                break;   // no single move helps — the caller records it honestly
            }

            $cp = (int) $childById[$best['jid']]->population;
            $binData[$best['from']]['jids'] = array_values(array_filter(
                $binData[$best['from']]['jids'],
                fn ($x) => $x !== $best['jid']
            ));
            $binData[$best['from']]['pop'] -= $cp;
            $binData[$best['to']]['jids'][] = $best['jid'];
            $binData[$best['to']]['pop'] += $cp;
        }

        // Re-seat every bin under the same Step 11 arithmetic.
        foreach ($binData as &$b) {
            $b['fractional'] = $b['pop'] / max($binQuota, 1);
            $b['seats'] = $seatsOf((int) $b['pop']);
        }
        unset($b);

        return $binData;
    }

    /**
     * INERT CHILD LAYER (operator ruling 2026-07-23): a child layer whose
     * stored populations sum to ZERO under a scope that holds people cannot
     * apportion anything — border-off-raster ingestion left phantom or
     * empty children (Foammulah's polygon sits 9 km offshore of its parent
     * with 0% overlap while the parent's own geometry measures its stored
     * population exactly). Such a layer is inert for districting: the scope
     * is EFFECTIVELY CHILDLESS and draws itself over its own geometry and
     * raster (the root-leaf / leaf-giant law). The children remain as
     * territory records — they hold nobody, so no representation is lost.
     */
    public function childLayerIsInert(string $scopeId): bool
    {
        $row = DB::selectOne('
            SELECT (SELECT COALESCE(population, 0) FROM jurisdictions WHERE id = ?) AS scope_pop,
                   COUNT(*) AS n, COALESCE(SUM(c.population), 0) AS cs
              FROM jurisdictions c
             WHERE c.parent_id = ? AND c.deleted_at IS NULL
        ', [$scopeId, $scopeId]);

        return $row !== null && (int) $row->n > 0 && (int) $row->cs === 0 && (int) $row->scope_pop > 0;
    }

    /**
     * MAP-LEVEL ZERO-POP ABSORPTION (2026-07-23, rotten-borough class).
     *
     * Step 10b absorbs zero-pop bins inside one scope frame, but a
     * '(Scattered Remainder)' child whose siblings are ALL giants has no
     * live bin in-frame — its 1-seat district can only be healed once the
     * whole map is filed. This pass runs at finalize: every seated district
     * measuring zero people merges its members into the nearest live
     * district ON THE MAP (member-centroid distance, district_number ties —
     * deterministic), moving zero population and zero seats; the emptied
     * row soft-deletes via recomputeDistrict. No live district anywhere, or
     * a zero-pop root jurisdiction → nothing absorbs, and the completeness
     * gate reviews the map honestly. Each absorption appends to the audit
     * chain.
     *
     * @return int districts absorbed
     */
    public function absorbZeroPopDistricts(string $legislatureId, object $leg, string $mapId): int
    {
        $rootPop = (int) DB::table('jurisdictions')
            ->where('id', $leg->jurisdiction_id)->value('population');
        if ($rootPop <= 0) {
            return 0;
        }

        // Zero-pop rows AND entitlement-zero CRUMBS (2026-07-25, De'an
        // class): a composite district whose fractional rounds to zero
        // (fractional_seats < 0.5) holds unearned seats over a handful of
        // people — its own scope frame has no live sibling (all giants), so
        // only this map-level pass can merge it. The people transfer with
        // the members (the conservation arm below adds their stored pops to
        // the target). Drawn pieces are never crumbs (their fracs are
        // seat-scale); the selector keeps them out via subdivision links.
        $candidates = DB::table('legislature_districts as d')
            ->where('d.map_id', $mapId)->whereNull('d.deleted_at')
            ->where('d.seats', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('legislature_district_jurisdictions as ldx')
                    ->whereColumn('ldx.district_id', 'd.id')
                    ->whereNotNull('ldx.subdivision_id');
            })
            ->orderBy('d.district_number')
            ->get(['d.id', 'd.district_number', 'd.seats', 'd.actual_population', 'd.jurisdiction_id']);

        // Entitlement is recomputed from FIRST PRINCIPLES — the stored
        // fractional cannot be trusted for crumbs (De'an's 445-person
        // 1-seat district stored fractional 1.000000, computed against the
        // degenerate pool-of-1 quota). Crumb iff its people are under HALF
        // a seat's worth at its own scope's quota (scope pop / cascade
        // budget; legislature root quota as the fallback frame).
        // A populated crumb's seats are only unlawful when they are EXCESS:
        // in a floor-infeasible pool (distribute-what-is-available, 1-seat
        // minimum) sub-half fracs are the lawful norm and must stay. Guard:
        // absorb a populated crumb only while the map OVER-seats and the
        // absorption moves the total toward the budget (each absorption
        // strictly improves drift; De'an 66/65 -> 65/65). Zero-pop rows
        // absorb unconditionally — nobody loses representation.
        $rootQuota = $rootPop / max((int) $leg->type_a_seats, 1);
        $seatExcess = (int) DB::table('legislature_districts')
                ->where('map_id', $mapId)->whereNull('deleted_at')->sum('seats')
            - (int) $leg->type_a_seats;
        $zeroRows = collect();
        foreach ($candidates as $d) {
            $pop = (int) $d->actual_population;
            if ($pop === 0) {
                $zeroRows->push($d);
                continue;
            }
            $quota = $rootQuota;
            if ($d->jurisdiction_id !== null) {
                $scopePop = (int) DB::table('jurisdictions')
                    ->where('id', $d->jurisdiction_id)->value('population');
                $scopeBudget = $this->computeSeatBudget((string) $d->jurisdiction_id, $legislatureId);
                if ($scopePop > 0 && $scopeBudget !== null && $scopeBudget > 0) {
                    $quota = $scopePop / $scopeBudget;
                }
            }
            if ($quota > 0 && $pop < 0.5 * $quota && (int) $d->seats <= $seatExcess) {
                $zeroRows->push($d);
                $seatExcess -= (int) $d->seats;
            }
        }
        $zeroRows = $zeroRows->values();
        if ($zeroRows->isEmpty()) {
            return 0;
        }

        $absorbed = 0;
        foreach ($zeroRows as $z) {
            $target = DB::selectOne('
                WITH zc AS (
                    SELECT ST_Centroid(ST_Collect(ST_Centroid(j.geom))) AS g
                      FROM legislature_district_jurisdictions ldj
                      JOIN jurisdictions j ON j.id = ldj.jurisdiction_id
                     WHERE ldj.district_id = ? AND j.geom IS NOT NULL
                )
                SELECT ld.id
                  FROM legislature_districts ld
                  JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
                  JOIN jurisdictions j ON j.id = ldj.jurisdiction_id, zc
                 WHERE ld.map_id = ? AND ld.deleted_at IS NULL
                   AND ld.id <> ? AND ld.actual_population > 0
                   AND j.geom IS NOT NULL
                 GROUP BY ld.id, ld.district_number, zc.g
                 ORDER BY ST_Distance(ST_Centroid(ST_Collect(ST_Centroid(j.geom))), zc.g) ASC,
                          ld.district_number ASC
                 LIMIT 1
            ', [$z->id, $mapId, $z->id]);
            if ($target === null) {
                // Geometry-blind fallback: nearness is a preference, not an
                // eligibility rule (drawn-piece districts carry geomless
                // members — Perpignan-3 class). The largest live district
                // absorbs with the least relative distortion; ties resolve
                // by district_number (determinism is a settled property).
                $target = DB::table('legislature_districts')
                    ->where('map_id', $mapId)->whereNull('deleted_at')
                    ->where('id', '<>', $z->id)
                    ->where('actual_population', '>', 0)
                    ->orderByDesc('actual_population')
                    ->orderBy('district_number')
                    ->first(['id']);
            }
            if ($target === null) {
                continue;   // no live district anywhere — honest review instead
            }

            $memberIds = DB::table('legislature_district_jurisdictions')
                ->where('district_id', $z->id)->pluck('jurisdiction_id')->all();
            $preTarget = DB::table('legislature_districts')
                ->where('id', $target->id)->first(['actual_population', 'fractional_seats']);
            $movedStored = $memberIds === [] ? 0
                : (int) DB::table('jurisdictions')->whereIn('id', $memberIds)->sum('population');
            DB::table('legislature_district_jurisdictions')
                ->where('district_id', $z->id)
                ->update(['district_id' => $target->id]);

            // Target keeps its Step-11 seats (zero people moved); spatial
            // stats recompute. The emptied zero-pop district soft-deletes
            // itself inside recomputeDistrict.
            $this->recomputeDistrict((string) $target->id, $legislatureId, $leg, true);
            $this->recomputeDistrict((string) $z->id, $legislatureId, $leg, true);

            // POPULATION CONSERVATION (Perpignan-3 class): recomputeDistrict
            // re-derives population from member STORED pops — a drawn-piece
            // target (geomless / zero-stored members) would be clobbered to
            // 0, minting a NEW rotten borough. The target's measured
            // population is not a function of the members it absorbs: it
            // keeps its own value plus the stored people it actually gained.
            DB::table('legislature_districts')->where('id', $target->id)->update([
                'actual_population' => (int) ($preTarget->actual_population ?? 0) + $movedStored,
                'fractional_seats'  => $preTarget->fractional_seats,
                'updated_at'        => now(),
            ]);

            app(\App\Services\AuditService::class)->append(
                module: 'elections',
                event: 'district_map.zero_pop_absorbed',
                payload: [
                    'map_id'            => $mapId,
                    'legislature_id'    => $legislatureId,
                    'absorbed_district' => (string) $z->id,
                    'district_number'   => (int) $z->district_number,
                    'seats_removed'     => (int) $z->seats,
                    'into_district'     => (string) $target->id,
                    'member_count'      => count($memberIds),
                ],
                ref: 'WF-ELE-02',
                jurisdictionId: (string) $leg->jurisdiction_id,
            );
            $absorbed++;
        }

        return $absorbed;
    }

    /**
     * Recompute seats + geometry for a district based on its current members.
     * If the district has no remaining members, soft-delete it.
     */
    public function recomputeDistrict(
        string $districtId,
        string $legislatureId,
        object $leg,
        bool   $skipSeatsUpdate = false  // true when called from auto-composite: preserve Step-11 seats
    ): void
    {
        ['giant' => $giantThreshold, 'floor' => $floorBoundary] = $this->thresholds($leg->jurisdiction_id);
        $floor   = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);

        $jids = DB::table('legislature_district_jurisdictions as ldj')
            ->where('ldj.district_id', $districtId)
            ->pluck('ldj.jurisdiction_id')
            ->toArray();

        if (empty($jids)) {
            DB::table('legislature_districts')
                ->where('id', $districtId)
                ->update(['deleted_at' => now()]);
            return;
        }

        $totalPop = (int) DB::table('jurisdictions')->whereIn('id', $jids)->sum('population');

        // Use local quota from the district's scope rather than the root quota.
        $districtRow = DB::table('legislature_districts')->where('id', $districtId)->first();
        $distScopeId = $districtRow ? $districtRow->jurisdiction_id : null;
        if ($distScopeId) {
            $scopeChildrenPop = (int) DB::table('jurisdictions')
                ->where('parent_id', $distScopeId)
                ->whereNull('deleted_at')
                ->sum('population');
            $distScopeRow = DB::table('jurisdictions')->where('id', $distScopeId)->whereNull('deleted_at')->first();
            // Children-sum share base (Kentucky ruling 2026-07-18) — the
            // fallback denominator must match the cascade's own base.
            $reRootPop    = \App\Services\Districting\LeafGiantResolver::shareBase((string) $leg->jurisdiction_id);
            // Seat budget via the gated cascade. Falls back to proportional
            // approximation only in degenerate cases.
            $distSeatBudget = $this->computeSeatBudget($distScopeId, $legislatureId)
                ?? max($floor, (int) round((int) ($distScopeRow ? $distScopeRow->population : 0) * (int) $leg->type_a_seats / $reRootPop));
            $fullQuota = $scopeChildrenPop / max($distSeatBudget, 1);
            // Adjust to non-giant quota so stored fractional is comparable to
            // sibling fracs. `type_a_apportioned` here is the legacy property
            // name carried into computeNonGiantQuota() — populated via the
            // gated cascade.
            $distChildren = DB::table('jurisdictions')
                ->where('parent_id', $distScopeId)
                ->whereNull('deleted_at')
                ->get(['id', 'population']);
            $distChildStd = $distChildren->map(function ($c) use ($fullQuota, $legislatureId) {
                $obj = new \stdClass();
                $obj->id                 = $c->id;
                $obj->population         = $c->population;
                $obj->fractional_seats   = (float) $c->population / max($fullQuota, 1);
                $obj->type_a_apportioned = $this->computeSeatBudget($c->id, $legislatureId);
                return $obj;
            })->all();

            // Giant siblings at this scope — never available to the composite pool,
            // so a border to one of them cannot make a district "fixable"
            // (round-8 gbr ruling: flag non-contiguity only when contiguity was
            // possible among the AVAILABLE siblings).
            $giantSiblingIds = [];
            foreach ($distChildStd as $c) {
                if ((float) $c->fractional_seats >= $giantThreshold) {
                    $giantSiblingIds[$c->id] = true;
                }
            }
            $quota = $this->computeNonGiantQuota($distChildStd, $fullQuota, $distSeatBudget, $scopeChildrenPop, $giantThreshold, $floor);

            // Quota cap: when giants consume most of the seat budget, the remaining
            // non-giant pool may be < floor. effectiveFloor = min(floor, nonGiantBudget) so
            // that the constitutional floor yields to the quota cap (not the reverse).
            $giantSeatsForFloor = 0;
            foreach ($distChildStd as $c) {
                if ((float) $c->fractional_seats >= $giantThreshold) {
                    $giantSeatsForFloor += $c->type_a_apportioned !== null
                        ? (int) $c->type_a_apportioned
                        : max($floor, (int) round((float) $c->fractional_seats));
                }
            }
            $effectiveFloor = min($floor, max(1, $distSeatBudget - $giantSeatsForFloor));
        } else {
            $reRootPop = \App\Services\Districting\LeafGiantResolver::shareBase((string) $leg->jurisdiction_id);
            $quota = $reRootPop / max((int) $leg->type_a_seats, 1);
            $effectiveFloor = $floor;   // no scope context — cannot determine quota cap
            $giantSiblingIds = [];      // no scope context — treat every sibling as available
        }
        $fractional = $totalPop / max($quota, 1);
        $seats      = max($effectiveFloor, min($ceiling, (int) round($fractional)));
        $floorOverride = $seats < $floor;

        // Pre-compute spatial stats from member jurisdiction geometries.
        // Running per-district at write time (create/update) is fast — typically
        // 1–40 member jurisdictions per district.  This avoids the O(n) ST_Union
        // fan-out that timed out when computed for all 274 Earth districts on page load.
        // Compactness: convex hull ratio + centroid spread.
        // Both metrics are better suited than Polsby-Popper for admin-unit districting
        // because they do not penalise natural coastlines or water bodies.
        //
        // convex_hull_ratio = ST_Area(union) / ST_Area(ST_ConvexHull(union))  [0–1, higher=better]
        // Union first so shared borders cancel cleanly before deriving metrics.
        // Two-tier conditional simplify on huge geoms — same pattern as the
        // adjacency queries. ST_Union over multiple multipart polygons is
        // super-linear in total vertex count, so a Canadian district holding
        // Nunavut (5.4M verts) + Ontario (3.7M) + Quebec (2.2M) at raw
        // resolution can spend 5-10 min in this one call. Simplified inputs
        // make Union seconds. Compactness (area-ratio) and component count
        // are insensitive to ~110m boundary precision; the metric is robust.
        $jidPlaceholders = implode(',', array_fill(0, count($jids), '?'));
        // Pull-engine read path (2026-07-19, mechanics only): the simplify
        // cache holds the EXACT tier expression for >50k-vertex rows —
        // ST_Simplify is deterministic, so COALESCE preserves identical
        // outputs while skipping the expensive per-call simplify (Nunavut's
        // tier-1 pass alone is ~55 s, paid once at precompute).
        $spatialRow = DB::selectOne("
            WITH g AS (
                SELECT COALESCE(s.geom,
                           CASE
                               WHEN ST_NPoints(j.geom) > 1000000
                                    THEN ST_MakeValid(ST_Simplify(j.geom, 0.01))
                               WHEN ST_NPoints(j.geom) > 50000
                                    THEN ST_MakeValid(ST_Simplify(j.geom, 0.001))
                               ELSE ST_MakeValid(j.geom)
                           END
                       ) AS geom
                FROM jurisdictions j
                LEFT JOIN jurisdiction_simplified s ON s.jurisdiction_id = j.id
                WHERE j.id IN ({$jidPlaceholders})
                  AND j.geom IS NOT NULL AND j.deleted_at IS NULL
            ),
            union_cte AS (
                SELECT ST_MakeValid(ST_Union(g.geom)) AS geom FROM g
            )
            SELECT
                ST_Area(geom) / NULLIF(ST_Area(ST_ConvexHull(geom)), 0) AS convex_hull_ratio,
                ST_NumGeometries(geom)                                   AS num_geom_parts
            FROM union_cte
        ", $jids);

        // Contiguity: graph connectivity check via ST_Intersects adjacency + BFS.
        // Single-member districts are always contiguous by definition — their
        // internal island geography (Michigan UP, Hawaiian islands, etc.) is irrelevant.
        //
        // Multi-member districts: two members are "adjacent" if their geometries
        // actually intersect (share at least one point — i.e., a real land border).
        // We use the GiST index bbox operator && as a fast pre-filter, then confirm
        // with ST_Intersects.  This prevents coastal jurisdictions separated by water
        // (harbors, straits, bays) from being falsely declared adjacent; the old
        // approach used ST_Expand(geom, 1.35) which created ~150 km false adjacencies.
        //
        // BFS from the first member; if all N members are reachable the district is
        // contiguous.  FALSE means ≥1 member is isolated (not reachable via real borders).
        if (count($jids) <= 1) {
            $isContiguous = true;
        } else {
            // Two-tier conditional simplify — same pattern as Step 7. Tier 1
            // (>1M verts) at 0.01° (~1.1km) for Nunavut-class outliers; Tier 2
            // (>50k verts) at 0.001° (~110m) for normal large geoms.
            // ST_MakeValid handles self-intersections that simplification can
            // introduce on complex coastlines.
            $jidPh = implode(',', array_fill(0, count($jids), '?'));
            // Pull-engine read path (2026-07-19, mechanics only): same
            // simplify-cache COALESCE as the union CTE above — identical
            // outputs, simplify paid once.
            $adjPairs = DB::select("
                WITH g AS (
                    SELECT j.id,
                           COALESCE(s.geom,
                               CASE
                                   WHEN ST_NPoints(j.geom) > 1000000
                                        THEN ST_MakeValid(ST_Simplify(j.geom, 0.01))
                                   WHEN ST_NPoints(j.geom) > 50000
                                        THEN ST_MakeValid(ST_Simplify(j.geom, 0.001))
                                   ELSE j.geom
                               END
                           ) AS geom
                    FROM jurisdictions j
                    LEFT JOIN jurisdiction_simplified s ON s.jurisdiction_id = j.id
                    WHERE j.id IN ({$jidPh})
                      AND j.geom IS NOT NULL
                      AND j.deleted_at IS NULL
                )
                SELECT a.id AS a_id, b.id AS b_id
                FROM g a
                JOIN g b ON b.id > a.id
                    AND a.geom && b.geom
                    AND ST_Intersects(a.geom, b.geom)
            ", $jids);

            $adj       = [];
            $adjCounts = [];
            foreach ($adjPairs as $p) {
                $adj[$p->a_id][] = $p->b_id;
                $adj[$p->b_id][] = $p->a_id;
                $adjCounts[$p->a_id] = ($adjCounts[$p->a_id] ?? 0) + 1;
                $adjCounts[$p->b_id] = ($adjCounts[$p->b_id] ?? 0) + 1;
            }

            // Start BFS from the most-connected member (highest adjacency count).
            // This prevents the case where $jids[0] is a geographic island with zero
            // land borders (e.g. Nanaoxian in Guangzhou Province): if BFS starts at
            // the island it visits only 1 node, wrongly orphaning all mainland members
            // and causing the island-exemption loop to check mainland nodes (which all
            // have sibling borders), so the exemption never fires.
            // Starting from the most-connected node guarantees BFS finds the largest
            // mainland cluster first, leaving only true islands as orphans.
            $startNode = $jids[0];
            if (!empty($adjCounts)) {
                arsort($adjCounts);
                $startNode = (string) array_key_first($adjCounts);
            }

            $visited = [];
            $queue   = [$startNode];
            $qh      = 0;
            while (isset($queue[$qh])) {
                $node = $queue[$qh++];
                if (isset($visited[$node])) continue;
                $visited[$node] = true;
                foreach ($adj[$node] ?? [] as $nb) {
                    if (!isset($visited[$nb])) $queue[] = $nb;
                }
            }
            $isContiguous = count($visited) === count($jids);

            // If non-contiguous, check whether contiguity was even achievable.
            // Island jurisdictions (Hawaii, Puerto Rico, Guam…) can never be made
            // contiguous with mainland members — no map drawing can fix it.
            //
            // For each orphaned (BFS-unreachable) member, ask: does it share any
            // land border with ANY sibling jurisdiction (same parent_id)?
            // The GiST bbox pre-filter makes this near-instant for true islands —
            // Hawaii's bbox has zero overlap with any other US state → 0 candidates.
            // If ANY orphaned member has no sibling border at all, the non-contiguity
            // is geographic/unavoidable → override to contiguous (no flag).
            if (!$isContiguous) {
                // Round-8 (operator ruling, the gbr case): flag non-contiguity ONLY
                // when contiguity was POSSIBLE among the AVAILABLE siblings. A border
                // to a GIANT sibling doesn't count — the giant was never in the
                // composite pool (Scotland and Wales border only England, which is a
                // giant, so {Scotland, Wales, NI} is exempt). Exemption now requires
                // EVERY orphaned piece to be unavoidable, which also removes the old
                // start-node lottery (the exempt piece being the BFS start used to
                // flip the flag between otherwise-identical districts).
                $allUnavoidable = true;
                $orphanedJids = array_values(array_filter($jids, fn($j) => !isset($visited[$j])));
                foreach ($orphanedJids as $oj) {
                    // Ask: does this orphaned member share any spatial border with
                    // any sibling (same parent_id) jurisdiction at all?
                    // Uses ST_Intersects (not ST_Touches or ST_Dimension) because:
                    //   • Simplified geoBoundaries polygons sometimes share only a vertex
                    //     (dim=0) rather than a full edge; ST_Intersects still returns TRUE.
                    //   • The BFS start-node fix (most-connected node) is what correctly
                    //     orphans true islands. Once Nanaoxian-style islands ARE orphaned,
                    //     they have NO bbox-overlapping siblings at all → this query returns
                    //     nothing → $hasSiblingBorder = null → exemption fires correctly.
                    //   • ST_Intersects only fails for containment artifacts (coastal polygon
                    //     geometrically containing an island), but those islands have no bbox
                    //     overlap with any sibling anyway, so this query never reaches them.
                    $borderSiblings = DB::select("
                        SELECT b.id
                        FROM jurisdictions a
                        JOIN jurisdictions b
                            ON b.parent_id = a.parent_id
                            AND b.id != a.id
                            AND b.deleted_at IS NULL
                            AND b.geom IS NOT NULL
                            AND a.geom && b.geom
                            AND ST_Intersects(a.geom, b.geom)
                        WHERE a.id = ?
                          AND a.deleted_at IS NULL
                    ", [$oj]);
                    foreach ($borderSiblings as $bs) {
                        if (!isset($giantSiblingIds[$bs->id])) {
                            // This piece borders an AVAILABLE sibling — a better
                            // grouping existed, so the break was avoidable.
                            $allUnavoidable = false;
                            break 2;
                        }
                    }
                }
                if ($allUnavoidable) {
                    $isContiguous = true;
                }
            }
        }

        // No geometry stored on the district record itself —
        // the revealed layer renders member jurisdiction polygons directly.
        //
        // When $skipSeatsUpdate is true (called from auto-composite), Step 11 already seated
        // the district by nearest rounding against the SCOPE's pool quota — do NOT re-derive
        // seats here, where the quota context (this district's members alone) differs and
        // would shift the fractional. Only spatial stats are refreshed. Both paths obey the
        // same law: nearest rounding, no total-forcing (operator ruling 2026-07-13).
        // NOTE: polsby_popper column was dropped by migration
        // 2026_04_23_000003_drop_unused_district_and_jurisdiction_columns —
        // superseded by convex_hull_ratio. Do NOT add it back here.
        $distUpdate = [
            'actual_population' => $totalPop,
            'num_geom_parts'    => $spatialRow?->num_geom_parts !== null ? (int) $spatialRow->num_geom_parts : null,
            'convex_hull_ratio' => $spatialRow?->convex_hull_ratio !== null ? round((float) $spatialRow->convex_hull_ratio, 6) : null,
            'is_contiguous'     => $isContiguous,
            'updated_at'        => now(),
        ];
        if (!$skipSeatsUpdate) {
            $distUpdate['seats']            = $seats;
            $distUpdate['fractional_seats'] = $fractional;
            $distUpdate['floor_override']   = $floorOverride;
        }
        DB::table('legislature_districts')
            ->where('id', $districtId)
            ->update($distUpdate);

        // Flush all revealed GeoJSON caches for this legislature.
        // The broad tag "revealed.{$legislatureId}" was added to every revealedGeoJson()
        // cache entry, so one flush here invalidates every scope × map × zoom combination.
        //
        // NOT on the autoseed path ($skipSeatsUpdate): there this runs once per
        // district, and flushing the same tag N times leaves exactly the state
        // one flush leaves. runAutoCompositeForScope flushes once after Step 12
        // instead. The manual create/update path passes false and is untouched.
        if (! $skipSeatsUpdate) {
            Cache::tags(["revealed.{$legislatureId}"])->flush();
        }
    }

    /**
     * Build k seeds via greedy farthest-point from a given first seed.
     * Seeds 2..k are chosen iteratively as the jurisdiction whose minimum distance
     * to any already-chosen seed is maximised (maximises inter-seed spread).
     * Used by the Phase-A exhaustive scan to generate diverse BFS starting configurations
     * from every possible first seed.
     */
    private function farPointSeeds(string $firstSeed, int $k, array $jids, array $centroids): array
    {
        $seeds   = [$firstSeed];
        $seedSet = [$firstSeed => true];
        while (count($seeds) < $k) {
            $farthest   = null;
            $maxMinDist = -1.0;
            foreach ($jids as $jid) {
                if (isset($seedSet[$jid])) continue;
                $minDist = PHP_FLOAT_MAX;
                foreach ($seeds as $seed) {
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$seed]['x'] ?? 0.0);
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$seed]['y'] ?? 0.0);
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $minDist) $minDist = $d;
                }
                if ($minDist > $maxMinDist) { $maxMinDist = $minDist; $farthest = $jid; }
            }
            if ($farthest === null) break;
            $seeds[]            = $farthest;
            $seedSet[$farthest] = true;
        }
        return $seeds;
    }

    /**
     * Partition a connected component into geographically compact, contiguous districts
     * using distance-filtered BFS expansion from pre-selected starting jurisdictions.
     *
     * The adjacency table may contain false-positive long-distance edges (data artefacts).
     * All adjacency traversals in this function filter out edges longer than 4× the
     * 90th-percentile edge length for the component.  This prevents false edges from
     * pulling distant jids into a bin during BFS (root cause of geometric non-contiguity)
     * and from fooling the per-swap contiguity guard or post-swap full-revert check.
     *
     * The multi-attempt loop in runAutoCompositeForScope() calls this once per seed
     * set (every member as first seed + a population-anchor set) per k value and
     * keeps the best-scoring configuration.
     *
     * Algorithm:
     *  1. Distance threshold: compute p90 × 16 of adjacency edge lengths for the component
     *  2. BFS expansion (distance-filtered): round-robin from k seeds
     *  3. Isolated-jid assignment: adjacency-aware, distance-filtered, standalone fallback
     *  4. Population balance swaps: border transfers chasing per-bin INTEGER seat targets
     *     (minimax, dynamic retargeting — see optimalIntegerTargets)
     *  5. Post-swap contiguity validation: full revert if any swap broke contiguity
     *  6. Post-repair merge: merge undersized bins (< floor frac) into adjacent absorbers
     *
     * @param  array $seeds    Pre-computed seed jurisdiction IDs (one per desired district)
     * @param  array $jids     Jurisdiction IDs in this component
     * @param  array $childById Jurisdiction data keyed by ID (population, fractional_seats, …)
     * @param  array $adj      Adjacency map [jid => [neighbor_jid, …]]
     * @param  array $centroids ['x' => lon, 'y' => lat] keyed by jurisdiction ID
     * @return array           Array of bins; each bin = array of jurisdiction IDs
     */
    /**
     * The component's false-positive-edge cap: p90 of the squared centroid
     * distance over its adjacency edges, ×16 (= 4²). Any edge longer than 4×
     * the "typical longest real edge" is ignored in BFS queuing, isolated-jid
     * lookup, swap guards and the post-swap revert check — without it a single
     * bad adjacency row pulls a distant jurisdiction into a bin and the result
     * looks contiguous in the graph while being split on the ground.
     *
     * Extracted verbatim from geographicSeedExpansion (2026-08-09) so a k's
     * seed sets pay it once between them instead of once each.
     */
    private function componentEdgeCapSq(array $jids, array $adj, array $centroids): float
    {
        $jidSet     = array_flip($jids);
        $adjDistsSq = [];
        foreach ($jids as $jid) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($jidSet[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                $adjDistsSq[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($adjDistsSq);
        $p90Idx = max(0, (int) floor(count($adjDistsSq) * 0.90) - 1);

        return !empty($adjDistsSq) ? $adjDistsSq[$p90Idx] * 16.0 : PHP_FLOAT_MAX;
    }

    private function geographicSeedExpansion(
        array $jids,
        array $childById,
        array $adj,
        array $centroids,
        array $seeds,
        float $giantThreshold,
        float $floorBoundary,
        bool  $bfsOnly = false, // when true: return after BFS expansion, skip balance/compact passes
        int   $compBudget = 0,  // component seat budget — enables integer-quota targeting in the passes
        ?array $presetBins = null, // round-7: refine these bins through the passes, skipping seed+BFS
        ?float $edgeCapSq = null   // caller-hoisted componentEdgeCapSq() — identical value, computed once
    ): array {
        // Pre-compute the "BFS full" threshold (slightly below giant) used to gate
        // expansion. With default 5/9 this is 9.49 (giant=9.5 minus epsilon).
        $bfsFullThreshold = $giantThreshold - 0.01;
        if (empty($jids)) return [];

        // Degenerate: caller provided no seeds — return everything as one bin
        $k = $presetBins !== null ? count($presetBins) : count($seeds);
        if ($k < 1) return [$jids];

        $jidSet    = array_flip($jids); // O(1) membership test
        $totalPop  = array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $jids));
        $targetPop = $totalPop / $k;

        // Integer-quota targeting (operator doctrine): when the caller supplies the
        // component's seat budget, the refinement passes measure each bin against its own
        // whole-seat target (s_i × quota, Σs_i = budget, re-derived every iteration —
        // dynamic retargeting) instead of the uniform pop/k. Districts should land ON
        // whole seat multiples: that is what nearest-rounded seating rewards.
        // BFS itself still coarse-fills toward pop/k; only the passes chase integers.
        $quotaPop      = $compBudget > 0 ? $totalPop / $compBudget : 0.0;
        $intFloor      = (int) round($floorBoundary);
        $intCeiling    = (int) round($giantThreshold - 0.5);
        $useIntTargets = $quotaPop > 0.0;

        // ── Distance filter — computed once, used throughout all phases ──────────────
        // BFS expansion is the root cause of non-contiguous districts: when the adjacency
        // table contains a false-positive long-distance edge, BFS traverses it during
        // expansion and pulls a geographically distant jid into a bin.  The resulting bin
        // looks contiguous in the adjacency graph (reachable via the false edge) but is
        // geometrically non-contiguous.
        //
        // Fix: compute the 90th-percentile squared distance of all adjacency edges in this
        // component, multiply by 16 (= 4²).  Any edge longer than 4× the "typical longest
        // real edge" is ignored in BFS queuing, isolated-jid lookup, swap guards, and the
        // post-swap full-revert check.
        //
        // Hoisted (2026-08-09): this depends only on the component, the
        // adjacency map and the centroids — none of which change across a k's
        // seed sets — yet it was rebuilt and re-sorted on every one of the 639
        // Phase-A calls. The caller computes it once per component and passes
        // it in; the fallback keeps every other caller (and the reflection-
        // driven pins) on the original path with the identical value.
        $maxEdgeDistSq = $edgeCapSq ?? $this->componentEdgeCapSq($jids, $adj, $centroids);

        // ── Preset mode (round-7, the operator's five stringiness flags) ─────────
        // The sequential builder's winners never met the compact/smoothing passes —
        // their remainder district arrived as a crescent wrapped around the built
        // cores (Turkey's C, Yunnan's horseshoe, São Paulo's coastal band). Preset
        // mode drops caller-supplied bins straight into the full refinement
        // pipeline below — balance minimax, compact exchanges, border smoothing,
        // post-compact minimax — under the same integer-target deviation caps that
        // protect the equality wins.
        if ($presetBins !== null) {
            $bins     = array_map(fn($b) => array_values($b), array_values($presetBins));
            $binFracs = array_map(
                fn($b) => (float) array_sum(array_map(fn($jid) => (float) $childById[$jid]->fractional_seats, $b)),
                $bins
            );
            $assigned = [];
            foreach ($bins as $bi => $b) {
                foreach ($b as $jid) {
                    $assigned[$jid] = $bi;
                }
            }
        } else {
        // --- Initialize BFS bins ---
        $bins     = array_fill(0, $k, []);
        $binPops  = array_fill(0, $k, 0.0);
        $binFracs = array_fill(0, $k, 0.0);
        $assigned = [];
        $queues   = array_fill(0, $k, []);
        $qHead    = array_fill(0, $k, 0);

        foreach ($seeds as $i => $seed) {
            $bins[$i][]      = $seed;
            $binPops[$i]     = (float) $childById[$seed]->population;
            $binFracs[$i]    = (float) $childById[$seed]->fractional_seats;
            $assigned[$seed] = $i;
            foreach ($adj[$seed] ?? [] as $n) {
                if (!isset($jidSet[$n]) || isset($assigned[$n])) continue;
                $dx = ($centroids[$seed]['x'] ?? 0.0) - ($centroids[$n]['x'] ?? 0.0);
                $dy = ($centroids[$seed]['y'] ?? 0.0) - ($centroids[$n]['y'] ?? 0.0);
                if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                $queues[$i][] = $n;
            }
        }

        // --- BFS round-robin expansion (distance-filtered) ---
        // A bin is "full" when it exceeds the population target OR is at/near the 9.5
        // fractional cap.  Each iteration each bin BFS-grows by one adjacent jurisdiction.
        // Edges longer than $maxEdgeDistSq are skipped to prevent false-positive entries
        // from assigning geographically distant jids to the same bin.
        $maxIter = count($jids) * $k * 3;
        for ($iter = 0; $iter < $maxIter; $iter++) {
            $anyProgress = false;

            for ($i = 0; $i < $k; $i++) {
                $popFull  = $binPops[$i]  >= $targetPop  * 1.1;
                $fracFull = $binFracs[$i] >= $bfsFullThreshold;
                $binFull  = $popFull || $fracFull;

                $activeBins = 0;
                for ($j = 0; $j < $k; $j++) {
                    if ($binPops[$j] < $targetPop * 1.1 && $binFracs[$j] < $bfsFullThreshold) $activeBins++;
                }

                if ($binFull && $activeBins > 0) continue;

                while (isset($queues[$i][$qHead[$i]])) {
                    $next = $queues[$i][$qHead[$i]++];
                    if (isset($assigned[$next]) || !isset($jidSet[$next])) continue;

                    $nextFrac = (float) $childById[$next]->fractional_seats;

                    if ($binFracs[$i] + $nextFrac >= $giantThreshold) {
                        foreach ($adj[$next] ?? [] as $nbOfNext) {
                            if (!isset($assigned[$nbOfNext])) continue;
                            $adjJ = $assigned[$nbOfNext];
                            if ($adjJ !== $i && $binFracs[$adjJ] + $nextFrac < $giantThreshold) {
                                $queues[$adjJ][] = $next;
                            }
                        }
                        continue;
                    }

                    $bins[$i][]      = $next;
                    $binPops[$i]    += (float) $childById[$next]->population;
                    $binFracs[$i]   += $nextFrac;
                    $assigned[$next]  = $i;

                    foreach ($adj[$next] ?? [] as $n) {
                        if (!isset($jidSet[$n]) || isset($assigned[$n])) continue;
                        $dx = ($centroids[$next]['x'] ?? 0.0) - ($centroids[$n]['x'] ?? 0.0);
                        $dy = ($centroids[$next]['y'] ?? 0.0) - ($centroids[$n]['y'] ?? 0.0);
                        if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                        $queues[$i][] = $n;
                    }
                    $anyProgress = true;
                    break;
                }
            }

            if (!$anyProgress) break;
        }

        // --- Assign isolated jurisdictions ---
        // Jids not reached by BFS (their only adjacency paths exceeded the distance
        // threshold, or all neighbouring bins were full).  Distance filter applied here
        // too — prevents the same false edges from pulling them into non-adjacent bins.
        foreach ($jids as $jid) {
            if (isset($assigned[$jid])) continue;

            $jFrac      = (float) $childById[$jid]->fractional_seats;
            $nearestBin = -1;
            $minDist    = PHP_FLOAT_MAX;

            $adjacentBins = [];
            foreach ($adj[$jid] ?? [] as $neighbor) {
                if (!isset($assigned[$neighbor])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$neighbor]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$neighbor]['y'] ?? 0.0);
                if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                $adjacentBins[$assigned[$neighbor]] = true;
            }

            if (!empty($adjacentBins)) {
                foreach (array_keys($adjacentBins) as $i) {
                    if (!isset($binFracs[$i])) continue;
                    if ($binFracs[$i] + $jFrac >= $giantThreshold) continue;
                    $d = $this->closestApproachSq([$jid], $bins[$i], $centroids);
                    if ($d < $minDist) { $minDist = $d; $nearestBin = $i; }
                }
            } else {
                // No real adjacency or all filtered — nearest centroid fallback
                foreach (range(0, $k - 1) as $i) {
                    if (!isset($binFracs[$i])) continue;
                    if ($binFracs[$i] + $jFrac >= $giantThreshold) continue;
                    $d = $this->closestApproachSq([$jid], $bins[$i], $centroids);
                    if ($d < $minDist) { $minDist = $d; $nearestBin = $i; }
                }
            }

            if ($nearestBin >= 0) {
                $bins[$nearestBin][] = $jid;
                $binFracs[$nearestBin] += $jFrac;
                $assigned[$jid]       = $nearestBin;
            } else {
                $bins[]     = [$jid];
                $binFracs[] = $jFrac;
                $k++;
                $assigned[$jid] = $k - 1;
            }
        }
        } // end seed + BFS + isolated assignment (preset mode skips straight here)

        // --- Border swap refinement: improve population balance after BFS ---
        // Iteratively moves border jurisdictions between adjacent bins to minimise
        // population imbalance (sum of |binPop − targetPop| across all bins).
        // Each move must: (a) strictly reduce total imbalance, (b) keep the donor bin
        // at ≥ 5.0 fractional (constitutional floor), (c) keep the receiver bin
        // below 9.5 fractional (constitutional ceiling).
        //
        // The per-swap BFS contiguity guard and the post-swap full-revert both use
        // $maxEdgeDistSq (computed before BFS above) so false-positive edges cannot
        // trick them into allowing or missing a contiguity break.
        //
        // Runs at most count($jids) improvements (typically converges in much fewer).

        // Recompute $binPops — the isolated-jid section above does not update it.
        $binPops = array_map(
            fn($b) => (float) array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $b)),
            $bins
        );

        // BFS-only mode: return raw partition without running balance/compact passes.
        // Used by the Phase A exhaustive-seed scan in runAutoCompositeForScope() to
        // cheaply evaluate all N first-seed candidates before committing to the full pipeline.
        if ($bfsOnly) {
            return array_values(array_filter($bins, fn($b) => !empty($b)));
        }

        // Save pre-swap state so we can fully revert if the post-swap validation
        // detects that swaps created non-contiguous bins despite the per-swap guard.
        $preSwapBins  = array_map(fn($b) => array_values($b), $bins);
        $preSwapFracs = $binFracs;

        // Per-bin moment-of-inertia statistics for O(1) incremental Rg² computation.
        // Identity: Rg²_i = (Sx2_i + Sy2_i)/M_i − (Sx_i²+Sy_i²)/M_i²
        // Updated in O(1) after every swap by adding/subtracting the moved jid's contribution.
        $binSx  = array_fill(0, $k, 0.0); // sum(pop × lon)
        $binSy  = array_fill(0, $k, 0.0); // sum(pop × lat)
        $binSx2 = array_fill(0, $k, 0.0); // sum(pop × lon²)
        $binSy2 = array_fill(0, $k, 0.0); // sum(pop × lat²)
        for ($i = 0; $i < $k; $i++) {
            foreach ($bins[$i] as $sid) {
                $sp  = (float) $childById[$sid]->population;
                $sx  = $centroids[$sid]['x'] ?? 0.0;
                $sy  = $centroids[$sid]['y'] ?? 0.0;
                $binSx[$i]  += $sp * $sx;
                $binSy[$i]  += $sp * $sy;
                $binSx2[$i] += $sp * $sx * $sx;
                $binSy2[$i] += $sp * $sy * $sy;
            }
        }

        // --- Balance swap refinement: "best improvement" minimax steepest-descent ---
        // Each pass scans border-jid candidates and applies the single swap that most reduces
        // the MAXIMUM per-district deviation (Chebyshev / minimax norm), directly targeting
        // the user's goal of sub-2% deviation per district rather than sub-2% on average.
        //
        // Fast-skip: only swaps involving the current max-deviation bin can reduce the max.
        // New max is recomputed in O(k) per candidate (k ≤ 12 — negligible cost).
        // Constitutional constraints: donor bin stays ≥ 5.0 frac; receiver stays < 9.5 frac.
        $swapIter = 0;
        $swapMax  = count($jids) * 3;
        do {
            // Dynamic integer retargeting (operator doctrine): re-derive each bin's
            // whole-seat target every iteration — a bin drifting from 6.55 toward 8.12
            // retargets to the 8 ("I'm taking the 8.12 if an 8 is closer").
            $targets1 = $useIntTargets
                ? $this->optimalIntegerTargets($binPops, $quotaPop, $compBudget, $intFloor, $intCeiling)
                : null;
            $tpops1 = [];
            for ($ti = 0; $ti < $k; $ti++) {
                $tpops1[$ti] = $targets1 !== null ? max($targets1[$ti] * $quotaPop, 1.0) : max($targetPop, 1.0);
            }

            // Precompute current maximum deviation (normalized per-bin) — once per iteration, O(k)
            $currentMaxDev = 0.0;
            foreach ($binPops as $bi => $bp) {
                $d = abs($bp - $tpops1[$bi]) / $tpops1[$bi];
                if ($d > $currentMaxDev) $currentMaxDev = $d;
            }

            $bestImprovement = 0.0;
            $bestBI = -1; $bestBJ = -1;
            $bestBJid = null; $bestBRemainingI = null;
            $bestBJPop = 0.0; $bestBJFrac = 0.0;

            for ($i = 0; $i < $k; $i++) {
                if (empty($bins[$i])) continue;
                foreach ($bins[$i] as $jid) {
                    $jFrac = (float) $childById[$jid]->fractional_seats;
                    if ($binFracs[$i] - $jFrac < $floorBoundary) continue; // donor floor

                    $adjBins = [];
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (isset($assigned[$nb]) && $assigned[$nb] !== $i) {
                            $adjBins[$assigned[$nb]] = true;
                        }
                    }
                    if (empty($adjBins)) continue;

                    $jPop = (float) $childById[$jid]->population;
                    foreach (array_keys($adjBins) as $j) {
                        if ($binFracs[$j] + $jFrac >= $giantThreshold) continue;

                        // Fast skip: if neither bin i nor bin j holds the current max deviation,
                        // no swap between them can reduce the maximum.
                        $devI = abs($binPops[$i] - $tpops1[$i]) / $tpops1[$i];
                        $devJ = abs($binPops[$j] - $tpops1[$j]) / $tpops1[$j];
                        if ($devI < $currentMaxDev && $devJ < $currentMaxDev) continue;

                        // Compute new maximum if this swap were applied — O(k)
                        $newMaxDev = 0.0;
                        foreach ($binPops as $bi => $bp) {
                            $newPop = $bp;
                            if ($bi === $i) $newPop -= $jPop;
                            if ($bi === $j) $newPop += $jPop;
                            $d = abs($newPop - $tpops1[$bi]) / $tpops1[$bi];
                            if ($d > $newMaxDev) $newMaxDev = $d;
                        }
                        $improvement = $currentMaxDev - $newMaxDev;
                        if ($improvement <= $bestImprovement) continue; // not the global best yet

                        // Contiguity guard — only run BFS for genuinely better candidates
                        $remainingI = array_values(array_filter($bins[$i], fn($x) => $x !== $jid));
                        if (count($remainingI) >= 2) {
                            $remSet = array_flip($remainingI);
                            $vis    = [$remainingI[0] => true];
                            $bfsQ   = [$remainingI[0]];
                            $bfsQh  = 0;
                            while (isset($bfsQ[$bfsQh])) {
                                $cur = $bfsQ[$bfsQh++];
                                foreach ($adj[$cur] ?? [] as $nb) {
                                    if (!isset($remSet[$nb]) || isset($vis[$nb])) continue;
                                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                                    $vis[$nb] = true;
                                    $bfsQ[]   = $nb;
                                }
                            }
                            if (count($vis) < count($remainingI)) continue;
                        }

                        $bestImprovement  = $improvement;
                        $bestBI = $i; $bestBJ = $j; $bestBJid = $jid;
                        $bestBRemainingI  = $remainingI;
                        $bestBJPop = $jPop; $bestBJFrac = $jFrac;
                    }
                }
            }

            $swapMade = false;
            if ($bestBI >= 0) {
                $bx = $centroids[$bestBJid]['x'] ?? 0.0;
                $by = $centroids[$bestBJid]['y'] ?? 0.0;
                $bins[$bestBJ][]     = $bestBJid;
                $binPops[$bestBJ]   += $bestBJPop;
                $binFracs[$bestBJ]  += $bestBJFrac;
                $binSx[$bestBJ]     += $bestBJPop * $bx;
                $binSy[$bestBJ]     += $bestBJPop * $by;
                $binSx2[$bestBJ]    += $bestBJPop * $bx * $bx;
                $binSy2[$bestBJ]    += $bestBJPop * $by * $by;
                $bins[$bestBI]       = $bestBRemainingI;
                $binPops[$bestBI]   -= $bestBJPop;
                $binFracs[$bestBI]  -= $bestBJFrac;
                $binSx[$bestBI]     -= $bestBJPop * $bx;
                $binSy[$bestBI]     -= $bestBJPop * $by;
                $binSx2[$bestBI]    -= $bestBJPop * $bx * $bx;
                $binSy2[$bestBI]    -= $bestBJPop * $by * $by;
                $assigned[$bestBJid] = $bestBJ;
                $swapMade = true;
            }
            $swapIter++;
        } while ($swapMade && $swapIter < $swapMax);

        // --- Compactness refinement pass ---
        // After population balance converges, reshape bins toward compact forms by moving
        // border jids that reduce the sum of per-bin radius-of-gyration² (Rg²) across the
        // two affected bins, subject to:
        //   (a) constitutional floor/ceiling (≥5.0 frac donor; <9.5 frac receiver),
        //   (b) neither bin's population deviation worsens by more than $compactTol %pts,
        //   (c) donor bin remains contiguous after the move.
        // Uses the O(1) per-bin Sx/Sy/Sx2/Sy2 statistics maintained above.
        // Rg² formula: (Sx2+Sy2)/M − Sx²/M² − Sy²/M²   (in geographic degree² units)
        $compactTol  = 0.025; // absolute cap (2.5%) — leaves margin for post-compact minimax pass to reach sub-2%
        $compactIter = 0;
        $compactMax  = min(count($jids) * 2, 300);   // bounded for São Paulo-class scopes (637 children)
        do {
            // Integer targets for the deviation caps below (dynamic retargeting).
            $targetsC = $useIntTargets
                ? $this->optimalIntegerTargets($binPops, $quotaPop, $compBudget, $intFloor, $intCeiling)
                : null;

            $bestCGain = 0.0;
            $bestCI = -1; $bestCJ = -1;
            $bestCJid = null; $bestCRemainingI = null;
            $bestCJPop = 0.0; $bestCJFrac = 0.0;

            for ($i = 0; $i < $k; $i++) {
                if (count($bins[$i]) <= 1) continue;

                $iM  = $binPops[$i];
                $iRg = $iM > 0
                    ? ($binSx2[$i] + $binSy2[$i]) / $iM
                      - ($binSx[$i] * $binSx[$i] + $binSy[$i] * $binSy[$i]) / ($iM * $iM)
                    : 0.0;

                foreach ($bins[$i] as $jid) {
                    $jFrac = (float) $childById[$jid]->fractional_seats;
                    if ($binFracs[$i] - $jFrac < $floorBoundary) continue;

                    $jPop = (float) $childById[$jid]->population;
                    $jx   = $centroids[$jid]['x'] ?? 0.0;
                    $jy   = $centroids[$jid]['y'] ?? 0.0;

                    // Absolute deviation cap for donor bin i after removal.
                    // Using an absolute cap (not a per-swap delta) prevents successive compactness
                    // swaps from accumulating large deviations in a single bin.
                    $newIM = $iM - $jPop;
                    if ($newIM <= 0) continue;
                    $tpopCI = $targetsC !== null ? max($targetsC[$i] * $quotaPop, 1.0) : max($targetPop, 1.0);
                    $devIAfter = abs($newIM - $tpopCI) / $tpopCI;
                    if ($devIAfter > $compactTol) continue;

                    // Incremental Rg² for bin i after removing jid (O(1) via statistics)
                    $nISx  = $binSx[$i]  - $jPop * $jx;
                    $nISy  = $binSy[$i]  - $jPop * $jy;
                    $nISx2 = $binSx2[$i] - $jPop * $jx * $jx;
                    $nISy2 = $binSy2[$i] - $jPop * $jy * $jy;
                    $nIRg  = ($nISx2 + $nISy2) / $newIM
                           - ($nISx * $nISx + $nISy * $nISy) / ($newIM * $newIM);

                    $adjBins = [];
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (isset($assigned[$nb]) && $assigned[$nb] !== $i) {
                            $adjBins[$assigned[$nb]] = true;
                        }
                    }
                    if (empty($adjBins)) continue;

                    foreach (array_keys($adjBins) as $j) {
                        if ($binFracs[$j] + $jFrac >= $giantThreshold) continue;

                        $jM  = $binPops[$j];
                        $jRg = $jM > 0
                            ? ($binSx2[$j] + $binSy2[$j]) / $jM
                              - ($binSx[$j] * $binSx[$j] + $binSy[$j] * $binSy[$j]) / ($jM * $jM)
                            : 0.0;

                        // Absolute deviation cap for receiver bin j after addition.
                        $newJM = $jM + $jPop;
                        $tpopCJ = $targetsC !== null ? max($targetsC[$j] * $quotaPop, 1.0) : max($targetPop, 1.0);
                        $devJAfter = abs($newJM - $tpopCJ) / $tpopCJ;
                        if ($devJAfter > $compactTol) continue;

                        // Incremental Rg² for bin j after adding jid (O(1))
                        $nJSx  = $binSx[$j]  + $jPop * $jx;
                        $nJSy  = $binSy[$j]  + $jPop * $jy;
                        $nJSx2 = $binSx2[$j] + $jPop * $jx * $jx;
                        $nJSy2 = $binSy2[$j] + $jPop * $jy * $jy;
                        $nJRg  = ($nJSx2 + $nJSy2) / $newJM
                               - ($nJSx * $nJSx + $nJSy * $nJSy) / ($newJM * $newJM);

                        $cGain = ($iRg + $jRg) - ($nIRg + $nJRg); // positive = more compact
                        if ($cGain <= $bestCGain) continue;

                        // Contiguity guard for donor bin i
                        $remainingI = array_values(array_filter($bins[$i], fn($x) => $x !== $jid));
                        if (count($remainingI) >= 2) {
                            $remSet = array_flip($remainingI);
                            $vis    = [$remainingI[0] => true];
                            $bfsQ   = [$remainingI[0]];
                            $bfsQh  = 0;
                            while (isset($bfsQ[$bfsQh])) {
                                $cur = $bfsQ[$bfsQh++];
                                foreach ($adj[$cur] ?? [] as $nb) {
                                    if (!isset($remSet[$nb]) || isset($vis[$nb])) continue;
                                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                                    $vis[$nb] = true; $bfsQ[] = $nb;
                                }
                            }
                            if (count($vis) < count($remainingI)) continue;
                        }

                        $bestCGain = $cGain;
                        $bestCI = $i; $bestCJ = $j; $bestCJid = $jid;
                        $bestCRemainingI = $remainingI;
                        $bestCJPop = $jPop; $bestCJFrac = $jFrac;
                    }
                }
            }

            // Pairwise exchange scan (c∈i ↔ d∈j): reshapes at near-constant population.
            // Single moves cannot compact coarse-grained scopes — when each child is a
            // double-digit share of its bin's target, ANY single move breaches the
            // deviation cap (the São Paulo snake class). An exchange moves ~equal
            // population both ways, so it passes the caps and can straighten shapes.
            // Guards: same frac window, same deviation caps (vs integer targets), and
            // BOTH bins must remain contiguous after the exchange.
            $bestX = null;
            for ($i = 0; $i < $k; $i++) {
                if (count($bins[$i]) <= 1) continue;
                $iM = $binPops[$i];
                if ($iM <= 0) continue;
                $iRg = ($binSx2[$i] + $binSy2[$i]) / $iM
                     - ($binSx[$i] * $binSx[$i] + $binSy[$i] * $binSy[$i]) / ($iM * $iM);
                for ($j = $i + 1; $j < $k; $j++) {
                    if (count($bins[$j]) <= 1) continue;
                    $jM = $binPops[$j];
                    if ($jM <= 0) continue;
                    $jRg = ($binSx2[$j] + $binSy2[$j]) / $jM
                         - ($binSx[$j] * $binSx[$j] + $binSy[$j] * $binSy[$j]) / ($jM * $jM);

                    // Border cells only: c must touch bin j, d must touch bin i.
                    $iBorder = [];
                    foreach ($bins[$i] as $bc) {
                        foreach ($adj[$bc] ?? [] as $nb) {
                            if (($assigned[$nb] ?? -1) === $j) { $iBorder[] = $bc; break; }
                        }
                    }
                    $jBorder = [];
                    foreach ($bins[$j] as $bd) {
                        foreach ($adj[$bd] ?? [] as $nb) {
                            if (($assigned[$nb] ?? -1) === $i) { $jBorder[] = $bd; break; }
                        }
                    }
                    if (empty($iBorder) || empty($jBorder)) continue;
                    // Bound the pair scan for fine-grained scopes (São Paulo: 637
                    // children → borders in the hundreds; 64×64 is plenty).
                    $iBorder = array_slice($iBorder, 0, 64);
                    $jBorder = array_slice($jBorder, 0, 64);

                    $tpopXI = $targetsC !== null ? max($targetsC[$i] * $quotaPop, 1.0) : max($targetPop, 1.0);
                    $tpopXJ = $targetsC !== null ? max($targetsC[$j] * $quotaPop, 1.0) : max($targetPop, 1.0);

                    foreach ($iBorder as $cJid) {
                        $cPop  = (float) $childById[$cJid]->population;
                        $cFrac = (float) $childById[$cJid]->fractional_seats;
                        $cx = $centroids[$cJid]['x'] ?? 0.0;
                        $cy = $centroids[$cJid]['y'] ?? 0.0;
                        foreach ($jBorder as $dJid) {
                            $dPop  = (float) $childById[$dJid]->population;
                            $dFrac = (float) $childById[$dJid]->fractional_seats;

                            $newFracI = $binFracs[$i] - $cFrac + $dFrac;
                            $newFracJ = $binFracs[$j] - $dFrac + $cFrac;
                            if ($newFracI < $floorBoundary || $newFracI >= $giantThreshold) continue;
                            if ($newFracJ < $floorBoundary || $newFracJ >= $giantThreshold) continue;

                            $newIM = $iM - $cPop + $dPop;
                            $newJM = $jM - $dPop + $cPop;
                            if ($newIM <= 0 || $newJM <= 0) continue;
                            if (abs($newIM - $tpopXI) / $tpopXI > $compactTol) continue;
                            if (abs($newJM - $tpopXJ) / $tpopXJ > $compactTol) continue;

                            $dcx = $centroids[$dJid]['x'] ?? 0.0;
                            $dcy = $centroids[$dJid]['y'] ?? 0.0;

                            $nISx  = $binSx[$i]  - $cPop * $cx       + $dPop * $dcx;
                            $nISy  = $binSy[$i]  - $cPop * $cy       + $dPop * $dcy;
                            $nISx2 = $binSx2[$i] - $cPop * $cx * $cx + $dPop * $dcx * $dcx;
                            $nISy2 = $binSy2[$i] - $cPop * $cy * $cy + $dPop * $dcy * $dcy;
                            $nIRg  = ($nISx2 + $nISy2) / $newIM
                                   - ($nISx * $nISx + $nISy * $nISy) / ($newIM * $newIM);

                            $nJSx  = $binSx[$j]  + $cPop * $cx       - $dPop * $dcx;
                            $nJSy  = $binSy[$j]  + $cPop * $cy       - $dPop * $dcy;
                            $nJSx2 = $binSx2[$j] + $cPop * $cx * $cx - $dPop * $dcx * $dcx;
                            $nJSy2 = $binSy2[$j] + $cPop * $cy * $cy - $dPop * $dcy * $dcy;
                            $nJRg  = ($nJSx2 + $nJSy2) / $newJM
                                   - ($nJSx * $nJSx + $nJSy * $nJSy) / ($newJM * $newJM);

                            $xGain = ($iRg + $jRg) - ($nIRg + $nJRg);
                            if ($xGain <= $bestCGain || ($bestX !== null && $xGain <= $bestX['gain'])) continue;

                            // Contiguity guard: BOTH bins must stay connected post-exchange.
                            $setI   = array_values(array_filter($bins[$i], fn($x) => $x !== $cJid));
                            $setI[] = $dJid;
                            $setJ   = array_values(array_filter($bins[$j], fn($x) => $x !== $dJid));
                            $setJ[] = $cJid;
                            if (!$this->connectedSet($setI, $adj, $centroids, $maxEdgeDistSq)) continue;
                            if (!$this->connectedSet($setJ, $adj, $centroids, $maxEdgeDistSq)) continue;

                            $bestX = ['gain' => $xGain, 'i' => $i, 'j' => $j, 'c' => $cJid, 'd' => $dJid];
                        }
                    }
                }
            }

            $compactSwapMade = false;
            if ($bestX !== null && $bestX['gain'] > $bestCGain) {
                // Apply the exchange: c leaves i for j, d leaves j for i.
                foreach ([[$bestX['c'], $bestX['i'], $bestX['j']], [$bestX['d'], $bestX['j'], $bestX['i']]] as [$mJid, $from, $to]) {
                    $mPop  = (float) $childById[$mJid]->population;
                    $mFrac = (float) $childById[$mJid]->fractional_seats;
                    $mx = $centroids[$mJid]['x'] ?? 0.0;
                    $my = $centroids[$mJid]['y'] ?? 0.0;
                    $bins[$from]      = array_values(array_filter($bins[$from], fn($x) => $x !== $mJid));
                    $bins[$to][]      = $mJid;
                    $binPops[$from]  -= $mPop;            $binPops[$to]  += $mPop;
                    $binFracs[$from] -= $mFrac;           $binFracs[$to] += $mFrac;
                    $binSx[$from]  -= $mPop * $mx;        $binSx[$to]  += $mPop * $mx;
                    $binSy[$from]  -= $mPop * $my;        $binSy[$to]  += $mPop * $my;
                    $binSx2[$from] -= $mPop * $mx * $mx;  $binSx2[$to] += $mPop * $mx * $mx;
                    $binSy2[$from] -= $mPop * $my * $my;  $binSy2[$to] += $mPop * $my * $my;
                    $assigned[$mJid] = $to;
                }
                $compactSwapMade = true;
            } elseif ($bestCI >= 0) {
                $cx = $centroids[$bestCJid]['x'] ?? 0.0;
                $cy = $centroids[$bestCJid]['y'] ?? 0.0;
                $bins[$bestCJ][]     = $bestCJid;
                $binPops[$bestCJ]   += $bestCJPop;
                $binFracs[$bestCJ]  += $bestCJFrac;
                $binSx[$bestCJ]     += $bestCJPop * $cx;
                $binSy[$bestCJ]     += $bestCJPop * $cy;
                $binSx2[$bestCJ]    += $bestCJPop * $cx * $cx;
                $binSy2[$bestCJ]    += $bestCJPop * $cy * $cy;
                $bins[$bestCI]       = $bestCRemainingI;
                $binPops[$bestCI]   -= $bestCJPop;
                $binFracs[$bestCI]  -= $bestCJFrac;
                $binSx[$bestCI]     -= $bestCJPop * $cx;
                $binSy[$bestCI]     -= $bestCJPop * $cy;
                $binSx2[$bestCI]    -= $bestCJPop * $cx * $cx;
                $binSy2[$bestCI]    -= $bestCJPop * $cy * $cy;
                $assigned[$bestCJid] = $bestCJ;
                $compactSwapMade = true;
            }
            $compactIter++;
        } while ($compactSwapMade && $compactIter < $compactMax);

        // --- Border smoothing pass (round-3: Yunnan's interdigitated borders) ---
        // Rg² barely moves when adjacent border cells swap sides, so the passes
        // above converge with jagged, interlocking district lines on fine-grained
        // scopes. Cut edges — adjacency edges whose endpoints sit in different
        // bins, a discrete perimeter — see exactly that. Accept border single
        // moves that strictly REDUCE cut edges under the compact pass's guards
        // (dev caps vs integer targets, frac window, donor contiguity) plus an
        // Rg² non-worsening tolerance (2%), so smoothing never re-stretches a
        // district the compact pass just tightened.
        $degIn = function (string $jid, array $set) use ($adj, $centroids, $maxEdgeDistSq): int {
            $flip = array_flip($set);
            $n = 0;
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($flip[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                $n++;
            }
            return $n;
        };
        $smoothIter = 0;
        $smoothMax  = min(count($jids), 200);
        do {
            $targetsS = $useIntTargets
                ? $this->optimalIntegerTargets($binPops, $quotaPop, $compBudget, $intFloor, $intCeiling)
                : null;

            $bestSGain = 0;
            $bestS     = null;
            for ($i = 0; $i < $k; $i++) {
                if (count($bins[$i]) <= 1) continue;
                $iM = $binPops[$i];
                if ($iM <= 0) continue;
                $iRg = ($binSx2[$i] + $binSy2[$i]) / $iM
                     - ($binSx[$i] * $binSx[$i] + $binSy[$i] * $binSy[$i]) / ($iM * $iM);
                for ($j = 0; $j < $k; $j++) {
                    if ($j === $i || $binPops[$j] <= 0) continue;

                    $iBorder = [];
                    foreach ($bins[$i] as $bc) {
                        foreach ($adj[$bc] ?? [] as $nb) {
                            if (($assigned[$nb] ?? -1) === $j) { $iBorder[] = $bc; break; }
                        }
                    }
                    if (empty($iBorder)) continue;
                    $iBorder = array_slice($iBorder, 0, 64);

                    $tpopSI = $targetsS !== null ? max($targetsS[$i] * $quotaPop, 1.0) : max($targetPop, 1.0);
                    $tpopSJ = $targetsS !== null ? max($targetsS[$j] * $quotaPop, 1.0) : max($targetPop, 1.0);

                    $jM  = $binPops[$j];
                    $jRg = ($binSx2[$j] + $binSy2[$j]) / $jM
                         - ($binSx[$j] * $binSx[$j] + $binSy[$j] * $binSy[$j]) / ($jM * $jM);

                    foreach ($iBorder as $cJid) {
                        // Cut-edge gain: c's cross-edges to j become internal, its
                        // internal edges in i become cross.
                        $sGain = $degIn($cJid, $bins[$j]) - $degIn($cJid, $bins[$i]);
                        if ($sGain <= $bestSGain) continue;

                        $cPop  = (float) $childById[$cJid]->population;
                        $cFrac = (float) $childById[$cJid]->fractional_seats;
                        if ($binFracs[$i] - $cFrac < $floorBoundary) continue;
                        if ($binFracs[$j] + $cFrac >= $giantThreshold) continue;
                        $newIM = $iM - $cPop;
                        $newJM = $jM + $cPop;
                        if ($newIM <= 0) continue;
                        if (abs($newIM - $tpopSI) / $tpopSI > $compactTol) continue;
                        if (abs($newJM - $tpopSJ) / $tpopSJ > $compactTol) continue;

                        $cx = $centroids[$cJid]['x'] ?? 0.0;
                        $cy = $centroids[$cJid]['y'] ?? 0.0;
                        $nISx  = $binSx[$i]  - $cPop * $cx;
                        $nISy  = $binSy[$i]  - $cPop * $cy;
                        $nISx2 = $binSx2[$i] - $cPop * $cx * $cx;
                        $nISy2 = $binSy2[$i] - $cPop * $cy * $cy;
                        $nIRg  = ($nISx2 + $nISy2) / $newIM
                               - ($nISx * $nISx + $nISy * $nISy) / ($newIM * $newIM);
                        $nJSx  = $binSx[$j]  + $cPop * $cx;
                        $nJSy  = $binSy[$j]  + $cPop * $cy;
                        $nJSx2 = $binSx2[$j] + $cPop * $cx * $cx;
                        $nJSy2 = $binSy2[$j] + $cPop * $cy * $cy;
                        $nJRg  = ($nJSx2 + $nJSy2) / $newJM
                               - ($nJSx * $nJSx + $nJSy * $nJSy) / ($newJM * $newJM);
                        if (($nIRg + $nJRg) > ($iRg + $jRg) * 1.02 + 1e-12) continue;

                        $remainingI = array_values(array_filter($bins[$i], fn($x) => $x !== $cJid));
                        if (!$this->connectedSet($remainingI, $adj, $centroids, $maxEdgeDistSq)) continue;

                        $bestSGain = $sGain;
                        $bestS     = ['c' => $cJid, 'i' => $i, 'j' => $j, 'remI' => $remainingI,
                                      'pop' => $cPop, 'frac' => $cFrac, 'x' => $cx, 'y' => $cy];
                    }
                }
            }

            $smoothMade = false;
            if ($bestS !== null) {
                $si = $bestS['i']; $sj = $bestS['j'];
                $bins[$sj][]    = $bestS['c'];
                $binPops[$sj]  += $bestS['pop'];
                $binFracs[$sj] += $bestS['frac'];
                $binSx[$sj]    += $bestS['pop'] * $bestS['x'];
                $binSy[$sj]    += $bestS['pop'] * $bestS['y'];
                $binSx2[$sj]   += $bestS['pop'] * $bestS['x'] * $bestS['x'];
                $binSy2[$sj]   += $bestS['pop'] * $bestS['y'] * $bestS['y'];
                $bins[$si]      = $bestS['remI'];
                $binPops[$si]  -= $bestS['pop'];
                $binFracs[$si] -= $bestS['frac'];
                $binSx[$si]    -= $bestS['pop'] * $bestS['x'];
                $binSy[$si]    -= $bestS['pop'] * $bestS['y'];
                $binSx2[$si]   -= $bestS['pop'] * $bestS['x'] * $bestS['x'];
                $binSy2[$si]   -= $bestS['pop'] * $bestS['y'] * $bestS['y'];
                $assigned[$bestS['c']] = $sj;
                $smoothMade = true;
            }
            $smoothIter++;
        } while ($smoothMade && $smoothIter < $smoothMax);

        // --- Post-compact balance pass (minimax) ---
        // Re-optimises population equality after the compactness reshaping phase.
        // Uses the same minimax (Chebyshev) objective as the initial balance pass:
        // minimises max|dev| rather than sum|dev|, directly targeting sub-2% per district.
        // The $binSx/Sy/Sx2/Sy2 statistics are already up-to-date from the compact phase.
        $swapIter2 = 0;
        $swapMax2  = count($jids) * 2;
        do {
            // Dynamic integer retargeting — same treatment as the first balance pass.
            $targets2 = $useIntTargets
                ? $this->optimalIntegerTargets($binPops, $quotaPop, $compBudget, $intFloor, $intCeiling)
                : null;
            $tpops2 = [];
            for ($ti = 0; $ti < $k; $ti++) {
                $tpops2[$ti] = $targets2 !== null ? max($targets2[$ti] * $quotaPop, 1.0) : max($targetPop, 1.0);
            }

            // Precompute current maximum deviation (normalized per-bin) — once per iteration, O(k)
            $currentMaxDev2 = 0.0;
            foreach ($binPops as $bi => $bp) {
                $d = abs($bp - $tpops2[$bi]) / $tpops2[$bi];
                if ($d > $currentMaxDev2) $currentMaxDev2 = $d;
            }

            $bestImprovement2  = 0.0;
            $bestBI2 = -1; $bestBJ2 = -1;
            $bestBJid2 = null; $bestBRemainingI2 = null;
            $bestBJPop2 = 0.0; $bestBJFrac2 = 0.0;

            for ($i = 0; $i < $k; $i++) {
                if (empty($bins[$i])) continue;
                foreach ($bins[$i] as $jid) {
                    $jFrac = (float) $childById[$jid]->fractional_seats;
                    if ($binFracs[$i] - $jFrac < $floorBoundary) continue;

                    $adjBins = [];
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (isset($assigned[$nb]) && $assigned[$nb] !== $i) {
                            $adjBins[$assigned[$nb]] = true;
                        }
                    }
                    if (empty($adjBins)) continue;

                    $jPop = (float) $childById[$jid]->population;
                    foreach (array_keys($adjBins) as $j) {
                        if ($binFracs[$j] + $jFrac >= $giantThreshold) continue;

                        // Fast skip: neither bin involved = cannot reduce the max
                        $devI2 = abs($binPops[$i] - $tpops2[$i]) / $tpops2[$i];
                        $devJ2 = abs($binPops[$j] - $tpops2[$j]) / $tpops2[$j];
                        if ($devI2 < $currentMaxDev2 && $devJ2 < $currentMaxDev2) continue;

                        // Compute new maximum — O(k)
                        $newMaxDev2 = 0.0;
                        foreach ($binPops as $bi2 => $bp2) {
                            $newPop2 = $bp2;
                            if ($bi2 === $i) $newPop2 -= $jPop;
                            if ($bi2 === $j) $newPop2 += $jPop;
                            $d2 = abs($newPop2 - $tpops2[$bi2]) / $tpops2[$bi2];
                            if ($d2 > $newMaxDev2) $newMaxDev2 = $d2;
                        }
                        $improvement = $currentMaxDev2 - $newMaxDev2;
                        if ($improvement <= $bestImprovement2) continue;

                        // Contiguity guard — only run BFS for genuinely better candidates
                        $remainingI = array_values(array_filter($bins[$i], fn($x) => $x !== $jid));
                        if (count($remainingI) >= 2) {
                            $remSet = array_flip($remainingI);
                            $vis    = [$remainingI[0] => true];
                            $bfsQ   = [$remainingI[0]];
                            $bfsQh  = 0;
                            while (isset($bfsQ[$bfsQh])) {
                                $cur = $bfsQ[$bfsQh++];
                                foreach ($adj[$cur] ?? [] as $nb) {
                                    if (!isset($remSet[$nb]) || isset($vis[$nb])) continue;
                                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                                    $vis[$nb] = true;
                                    $bfsQ[]   = $nb;
                                }
                            }
                            if (count($vis) < count($remainingI)) continue;
                        }

                        $bestImprovement2 = $improvement;
                        $bestBI2 = $i; $bestBJ2 = $j; $bestBJid2 = $jid;
                        $bestBRemainingI2 = $remainingI;
                        $bestBJPop2 = $jPop; $bestBJFrac2 = $jFrac;
                    }
                }
            }

            $swapMade2 = false;
            if ($bestBI2 >= 0) {
                $bx = $centroids[$bestBJid2]['x'] ?? 0.0;
                $by = $centroids[$bestBJid2]['y'] ?? 0.0;
                $bins[$bestBJ2][]     = $bestBJid2;
                $binPops[$bestBJ2]   += $bestBJPop2;
                $binFracs[$bestBJ2]  += $bestBJFrac2;
                $binSx[$bestBJ2]     += $bestBJPop2 * $bx;
                $binSy[$bestBJ2]     += $bestBJPop2 * $by;
                $binSx2[$bestBJ2]    += $bestBJPop2 * $bx * $bx;
                $binSy2[$bestBJ2]    += $bestBJPop2 * $by * $by;
                $bins[$bestBI2]       = $bestBRemainingI2;
                $binPops[$bestBI2]   -= $bestBJPop2;
                $binFracs[$bestBI2]  -= $bestBJFrac2;
                $binSx[$bestBI2]     -= $bestBJPop2 * $bx;
                $binSy[$bestBI2]     -= $bestBJPop2 * $by;
                $binSx2[$bestBI2]    -= $bestBJPop2 * $bx * $bx;
                $binSy2[$bestBI2]    -= $bestBJPop2 * $by * $by;
                $assigned[$bestBJid2] = $bestBJ2;
                $swapMade2 = true;
            }
            $swapIter2++;
        } while ($swapMade2 && $swapIter2 < $swapMax2);

        // --- Post-swap full contiguity validation ---
        // Even with the per-swap BFS guard, a false-positive adjacency edge can trick
        // the guard into allowing a bridge-removal swap.  After ALL swaps settle, run
        // a second distance-filtered BFS over every bin.  If ANY bin fails the check,
        // revert the entire swap phase — the clean BFS layout is always contiguous.
        $swapValid = true;
        foreach ($bins as $checkBin) {
            if (count($checkBin) <= 1) continue;
            $cs = array_flip($checkBin);
            $cv = [$checkBin[0] => true];
            $cq = [$checkBin[0]];
            $cqh = 0;
            while (isset($cq[$cqh])) {
                $cur = $cq[$cqh++];
                foreach ($adj[$cur] ?? [] as $nb) {
                    if (!isset($cs[$nb]) || isset($cv[$nb])) continue;
                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                    $cv[$nb] = true;
                    $cq[]    = $nb;
                }
            }
            if (count($cv) < count($checkBin)) { $swapValid = false; break; }
        }
        if (!$swapValid) {
            // Revert bins and fracs; rebuild assigned map so post-repair uses correct data
            $bins     = $preSwapBins;
            $binFracs = $preSwapFracs;
            $assigned = [];
            foreach ($bins as $bi => $binJids) {
                foreach ($binJids as $bj) {
                    $assigned[$bj] = $bi;
                }
            }
        }

        // --- Cut-length descent (Good Maps retune, 2026-08-23) ---
        // scoreRank ranks real cut LENGTH (the round-10 stringiness currency)
        // but no pass above optimizes it: the compact exchanges climb Rg² and
        // the smoothing pass counts discrete cut EDGES. The Ohio specimen sat
        // one 2-for-1 exchange from the standard's cut (7.59 vs 8.94) with no
        // pass able to reach it. Descend the summed border length between bins
        // directly — border single moves, 1:1 exchanges, and 2:1 exchanges —
        // each accepted only on a strict cut-length decrease under the same
        // guards as the passes above (frac window, dev caps vs integer
        // targets, affected bins stay connected, no bin emptied). Same
        // borderLen the scorer sums, so the descent moves the ranked number
        // itself. Deterministic: stable scan order, strict improvement,
        // best-candidate-per-iteration. Empty borderLen (reflection-driven
        // tests) → the pass is inert.
        if (!empty($this->borderLen) && $k > 1) {
            $blOf = function (string $a, string $b): float {
                return $this->borderLen[$a . '|' . $b] ?? $this->borderLen[$b . '|' . $a] ?? 0.0;
            };
            // Δ cut length for reassigning $moves (jid => newBin): only edges
            // incident to a moved member can flip; count each pair once.
            $cutDelta = function (array $moves) use (&$assigned, $adj, $blOf): float {
                $delta = 0.0;
                $seenPair = [];
                foreach ($moves as $jid => $to) {
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (!isset($assigned[$nb])) continue;
                        $pk = strcmp((string) $jid, (string) $nb) < 0 ? $jid . '|' . $nb : $nb . '|' . $jid;
                        if (isset($seenPair[$pk])) continue;
                        $seenPair[$pk] = true;
                        $len = $blOf((string) $jid, (string) $nb);
                        if ($len <= 0.0) continue;
                        $oldCut = $assigned[$jid] !== $assigned[$nb];
                        $newCut = $to !== ($moves[$nb] ?? $assigned[$nb]);
                        if ($oldCut !== $newCut) $delta += $newCut ? $len : -$len;
                    }
                }
                return $delta;
            };
            // Full validity check for a candidate move set; returns false when
            // any guard fails. Bounded: at most 3 moved members per candidate.
            // No Rg² guard here (tried in iter-4, REVERTED): it cost Ohio's
            // better-than-standard cut (.890 → .824) while its justification —
            // the Pennsylvania hull regression — turned out to predate the
            // descent entirely. Cut length is the ranked currency; the
            // comparator, not this pass, arbitrates cut against Rg².
            $movesValid = function (array $moves, array $tpops) use (
                &$bins, &$binPops, &$binFracs, &$assigned, $childById, $adj, $centroids,
                $maxEdgeDistSq, $compactTol, $floorBoundary, $giantThreshold
            ): bool {
                $dPop = []; $dFrac = []; $affected = [];
                foreach ($moves as $jid => $to) {
                    $from = $assigned[$jid];
                    $p = (float) $childById[$jid]->population;
                    $f = (float) $childById[$jid]->fractional_seats;
                    $dPop[$from] = ($dPop[$from] ?? 0.0) - $p; $dPop[$to] = ($dPop[$to] ?? 0.0) + $p;
                    $dFrac[$from] = ($dFrac[$from] ?? 0.0) - $f; $dFrac[$to] = ($dFrac[$to] ?? 0.0) + $f;
                    $affected[$from] = true; $affected[$to] = true;
                }
                foreach (array_keys($affected) as $b) {
                    $newFrac = $binFracs[$b] + ($dFrac[$b] ?? 0.0);
                    if ($newFrac < $floorBoundary || $newFrac >= $giantThreshold) return false;
                    $newPop = $binPops[$b] + ($dPop[$b] ?? 0.0);
                    if ($newPop <= 0) return false;
                    if (abs($newPop - $tpops[$b]) / $tpops[$b] > $compactTol) return false;
                }
                foreach (array_keys($affected) as $b) {
                    $set = [];
                    foreach ($bins[$b] as $m) { if (!isset($moves[$m])) $set[] = $m; }
                    foreach ($moves as $jid => $to) { if ($to === $b) $set[] = $jid; }
                    if (empty($set)) return false;
                    if (!$this->connectedSet($set, $adj, $centroids, $maxEdgeDistSq)) return false;
                }
                return true;
            };

            $tpopsNow = function () use (&$binPops, $useIntTargets, $quotaPop, $compBudget, $intFloor, $intCeiling, $targetPop, $k): array {
                $targetsL = $useIntTargets
                    ? $this->optimalIntegerTargets($binPops, $quotaPop, $compBudget, $intFloor, $intCeiling)
                    : null;
                $tpopsL = [];
                for ($ti = 0; $ti < $k; $ti++) {
                    $tpopsL[$ti] = $targetsL !== null ? max($targetsL[$ti] * $quotaPop, 1.0) : max($targetPop, 1.0);
                }
                return $tpopsL;
            };
            $applyMoves = function (array $moves) use (
                &$bins, &$binPops, &$binFracs, &$binSx, &$binSy, &$binSx2, &$binSy2,
                &$assigned, $childById, $centroids
            ): void {
                foreach ($moves as $mJid => $to) {
                    $from = $assigned[$mJid];
                    $mPop  = (float) $childById[$mJid]->population;
                    $mFrac = (float) $childById[$mJid]->fractional_seats;
                    $mx = $centroids[$mJid]['x'] ?? 0.0;
                    $my = $centroids[$mJid]['y'] ?? 0.0;
                    $bins[$from]      = array_values(array_filter($bins[$from], fn($x) => $x !== $mJid));
                    $bins[$to][]      = $mJid;
                    $binPops[$from]  -= $mPop;            $binPops[$to]  += $mPop;
                    $binFracs[$from] -= $mFrac;           $binFracs[$to] += $mFrac;
                    $binSx[$from]  -= $mPop * $mx;        $binSx[$to]  += $mPop * $mx;
                    $binSy[$from]  -= $mPop * $my;        $binSy[$to]  += $mPop * $my;
                    $binSx2[$from] -= $mPop * $mx * $mx;  $binSx2[$to] += $mPop * $mx * $mx;
                    $binSy2[$from] -= $mPop * $my * $my;  $binSy2[$to] += $mPop * $my * $my;
                    $assigned[$mJid] = $to;
                }
            };
            // One scan over the move vocabulary; $bigTier=false → border
            // singles + 1:1 exchanges, true → 2:0 chains + 2:1 exchanges.
            $scanMoves = function (bool $bigTier, array $tpopsL) use (
                &$bins, &$binPops, &$assigned, $adj, $cutDelta, $movesValid, $k
            ): ?array {
                $bestDelta = -1e-12;
                $bestMoves = null;
                for ($i = 0; $i < $k; $i++) {
                    if (count($bins[$i]) <= 1) continue;
                    for ($j = 0; $j < $k; $j++) {
                        if ($j === $i || $binPops[$j] <= 0) continue;
                        $iBorder = [];
                        foreach ($bins[$i] as $bc) {
                            foreach ($adj[$bc] ?? [] as $nb) {
                                if (($assigned[$nb] ?? -1) === $j) { $iBorder[] = $bc; break; }
                            }
                        }
                        if (empty($iBorder)) continue;
                        $iBorder = array_slice($iBorder, 0, 48);

                        if (!$bigTier) {
                            // Border single moves i → j.
                            foreach ($iBorder as $c) {
                                $moves = [$c => $j];
                                $d = $cutDelta($moves);
                                if ($d >= $bestDelta) continue;
                                if (!$movesValid($moves, $tpopsL)) continue;
                                $bestDelta = $d; $bestMoves = $moves;
                            }
                        } else {
                            // 2:0 chain moves i → j: an adjacent border pair
                            // leaves together — crosses basins no single move
                            // or exchange spans.
                            foreach (array_slice($iBorder, 0, 24) as $c1) {
                                $mates20 = 0;
                                foreach ($adj[$c1] ?? [] as $c2) {
                                    if (($assigned[$c2] ?? -1) !== $i || $c2 === $c1) continue;
                                    if (++$mates20 > 8) break;
                                    $moves = [$c1 => $j, $c2 => $j];
                                    $d = $cutDelta($moves);
                                    if ($d >= $bestDelta) continue;
                                    if (!$movesValid($moves, $tpopsL)) continue;
                                    $bestDelta = $d; $bestMoves = $moves;
                                }
                            }
                        }

                        if ($j < $i) continue; // exchanges are symmetric — scan each pair once
                        $jBorder = [];
                        foreach ($bins[$j] as $bd) {
                            foreach ($adj[$bd] ?? [] as $nb) {
                                if (($assigned[$nb] ?? -1) === $i) { $jBorder[] = $bd; break; }
                            }
                        }
                        if (empty($jBorder)) continue;
                        $jBorder = array_slice($jBorder, 0, 48);

                        if (!$bigTier) {
                            // 1:1 exchanges.
                            foreach ($iBorder as $c) {
                                foreach ($jBorder as $dJ) {
                                    $moves = [$c => $j, $dJ => $i];
                                    $d = $cutDelta($moves);
                                    if ($d >= $bestDelta) continue;
                                    if (!$movesValid($moves, $tpopsL)) continue;
                                    $bestDelta = $d; $bestMoves = $moves;
                                }
                            }
                        } else {
                            // 2:1 exchanges (the Ohio move), both directions,
                            // second donor limited to in-bin neighbors of the first.
                            $twoForOne = function (array $fromBorder, int $from, int $to, array $toBorder) use (
                                &$assigned, $adj, $cutDelta, $movesValid, $tpopsL, &$bestDelta, &$bestMoves
                            ): void {
                                foreach (array_slice($fromBorder, 0, 24) as $c1) {
                                    $mates = 0;
                                    foreach ($adj[$c1] ?? [] as $c2) {
                                        if (($assigned[$c2] ?? -1) !== $from || $c2 === $c1) continue;
                                        if (++$mates > 8) break;
                                        foreach (array_slice($toBorder, 0, 24) as $dX) {
                                            if ($dX === $c1 || $dX === $c2) continue;
                                            $moves = [$c1 => $to, $c2 => $to, $dX => $from];
                                            $d = $cutDelta($moves);
                                            if ($d >= $bestDelta) continue;
                                            if (!$movesValid($moves, $tpopsL)) continue;
                                            $bestDelta = $d; $bestMoves = $moves;
                                        }
                                    }
                                }
                            };
                            $twoForOne($iBorder, $i, $j, $jBorder);
                            $twoForOne($jBorder, $j, $i, $iBorder);
                        }
                    }
                }
                return $bestMoves;
            };

            // TIERED convergence (iter-5 restructure): small moves to a fixed
            // point FIRST, then one big move, then small again. A flat scan let
            // a greedy big move divert a trajectory small moves would have
            // finished better (suspected in Ohio's iter-4 slip) — tiering makes
            // added vocabulary strictly additive.
            $cutApplied = 0;
            $cutMax     = min(count($jids) * 2, 240);
            do {
                do {
                    $mv = $scanMoves(false, $tpopsNow());
                    if ($mv !== null) { $applyMoves($mv); $cutApplied++; }
                } while ($mv !== null && $cutApplied < $cutMax);

                $big = $cutApplied < $cutMax ? $scanMoves(true, $tpopsNow()) : null;
                if ($big !== null) { $applyMoves($big); $cutApplied++; }
            } while ($big !== null && $cutApplied < $cutMax);

            // --- Pair re-bisection (Good Maps, 2026-08-23): the LARGE move ---
            // Local exchanges cannot cross regional basins (Texas stalled at
            // cut 28.6 against the standard's 24.1). The operator draws the
            // BORDER first — so re-draw each touching pair of districts from
            // scratch: the 12-direction bisection sweep over the pair's union
            // at the pair's canonical 2-part budget, adopted only when it
            // strictly shortens the pair's internal cut under the standing
            // guards (the union's outer boundary is fixed, so total cut moves
            // by exactly the internal delta). Canonicalizing a pair never
            // widens the plan's seat mix. Bounded: 3 stability rounds, unions
            // capped at 240 members.
            for ($pairRound = 0; $pairRound < 3; $pairRound++) {
                $pairImproved = false;
                $targetsP = $useIntTargets
                    ? $this->optimalIntegerTargets($binPops, $quotaPop, $compBudget, $intFloor, $intCeiling)
                    : null;
                for ($i = 0; $i < $k; $i++) {
                    for ($j = $i + 1; $j < $k; $j++) {
                        if (empty($bins[$i]) || empty($bins[$j])) continue;
                        $touching = false;
                        foreach ($bins[$i] as $m) {
                            foreach ($adj[$m] ?? [] as $nb) {
                                if (($assigned[$nb] ?? -1) === $j) { $touching = true; break 2; }
                            }
                        }
                        if (!$touching) continue;
                        $union = array_merge($bins[$i], $bins[$j]);
                        if (count($union) < 4 || count($union) > 240) continue;
                        $pairBudget = $targetsP !== null
                            ? (int) ($targetsP[$i] + $targetsP[$j])
                            : (int) round(($binPops[$i] + $binPops[$j]) / max($quotaPop, 1.0));
                        $parts2 = $this->canonicalPartition($pairBudget, 2, $intFloor, $intCeiling);
                        if ($parts2 === null) continue;

                        $curInternal = 0.0;
                        foreach ($bins[$i] as $a) {
                            foreach ($adj[$a] ?? [] as $nb) {
                                if (($assigned[$nb] ?? -1) === $j) $curInternal += $blOf((string) $a, (string) $nb);
                            }
                        }

                        $bestHalves   = null;
                        $bestInternal = $curInternal - 1e-12;
                        foreach ($this->bisectionCandidates($union, $childById, $adj, $centroids, $pairBudget, 2, $quotaPop, $intFloor, $intCeiling) as $halves) {
                            if (count($halves) !== 2) continue;
                            [$hA, $hB] = $halves;
                            if (empty($hA) || empty($hB)) continue;
                            $bSet = array_flip($hB);
                            $int = 0.0;
                            foreach ($hA as $a) {
                                foreach ($adj[$a] ?? [] as $nb) {
                                    if (isset($bSet[$nb])) $int += $blOf((string) $a, (string) $nb);
                                }
                            }
                            if ($int >= $bestInternal) continue;
                            $popA = 0.0; $fracA = 0.0;
                            foreach ($hA as $a) { $popA += (float) $childById[$a]->population; $fracA += (float) $childById[$a]->fractional_seats; }
                            $popB = 0.0; $fracB = 0.0;
                            foreach ($hB as $b) { $popB += (float) $childById[$b]->population; $fracB += (float) $childById[$b]->fractional_seats; }
                            if ($fracA < $floorBoundary || $fracA >= $giantThreshold) continue;
                            if ($fracB < $floorBoundary || $fracB >= $giantThreshold) continue;
                            // Larger half answers to the larger canonical part.
                            $pBig = max($parts2) * $quotaPop; $pSmall = min($parts2) * $quotaPop;
                            $tA = $popA >= $popB ? $pBig : $pSmall;
                            $tB = $popA >= $popB ? $pSmall : $pBig;
                            if (abs($popA - $tA) / max($tA, 1.0) > $compactTol) continue;
                            if (abs($popB - $tB) / max($tB, 1.0) > $compactTol) continue;
                            if (!$this->connectedSet($hA, $adj, $centroids, $maxEdgeDistSq)) continue;
                            if (!$this->connectedSet($hB, $adj, $centroids, $maxEdgeDistSq)) continue;
                            $bestInternal = $int;
                            $bestHalves   = [$hA, $hB];
                        }

                        if ($bestHalves !== null) {
                            foreach ([[$i, $bestHalves[0]], [$j, $bestHalves[1]]] as [$bIdx, $members]) {
                                $bins[$bIdx] = array_values($members);
                                $p = 0.0; $f = 0.0; $sx = 0.0; $sy = 0.0; $sx2 = 0.0; $sy2 = 0.0;
                                foreach ($members as $m) {
                                    $mp = (float) $childById[$m]->population;
                                    $mx = $centroids[$m]['x'] ?? 0.0;
                                    $my = $centroids[$m]['y'] ?? 0.0;
                                    $p += $mp; $f += (float) $childById[$m]->fractional_seats;
                                    $sx += $mp * $mx; $sy += $mp * $my;
                                    $sx2 += $mp * $mx * $mx; $sy2 += $mp * $my * $my;
                                    $assigned[$m] = $bIdx;
                                }
                                $binPops[$bIdx] = $p; $binFracs[$bIdx] = $f;
                                $binSx[$bIdx] = $sx; $binSy[$bIdx] = $sy;
                                $binSx2[$bIdx] = $sx2; $binSy2[$bIdx] = $sy2;
                            }
                            $pairImproved = true;
                        }
                    }
                }
                if (!$pairImproved) break;
            }
        }

        // --- Post-repair: merge bins that cannot round to the floor ---
        // $binFracs is already live-tracked throughout BFS — no need to recompute.
        // After swap refinement this path is rare (standalone isolated-jid bins only).
        // Trigger is the OVERRIDE boundary (floor − 0.5), not the floor: a 4.6-frac
        // bin rounds to the floor legally, and satellite-light components (round-9
        // budget accounting: the host's bins run light by their islands' share
        // until attachment) must not be force-collapsed back into one bin.
        //
        // Priority: merge into an ADJACENT absorber (shares a border in $adj) to preserve
        // contiguity.  Only fall back to nearest absorber when no adjacent one exists
        // (truly isolated jids with no adjacency data — unavoidable non-contiguity).
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($binFracs as $i => $t) {
                if ($t >= $floorBoundary - 0.5 || empty($bins[$i])) continue;

                // Collect bins that share at least one adjacency edge with bin i
                $adjBorderBins = [];
                foreach ($bins[$i] as $myJid) {
                    foreach ($adj[$myJid] ?? [] as $nb) {
                        if (isset($assigned[$nb])) {
                            $bj = $assigned[$nb];
                            if ($bj !== $i && !empty($bins[$bj])) {
                                $adjBorderBins[$bj] = true;
                            }
                        }
                    }
                }

                $bestJ    = -1;
                $bestDist = PHP_FLOAT_MAX;

                // Phase 1: adjacent absorbers only (contiguity-safe merge)
                foreach (array_keys($adjBorderBins) as $j) {
                    if ($binFracs[$j] + $t >= $giantThreshold) continue;
                    $d = $this->closestApproachSq($bins[$i], $bins[$j], $centroids);
                    if ($d < $bestDist) { $bestDist = $d; $bestJ = $j; }
                }

                // Phase 2: fallback to any absorber by closest approach (truly isolated jids only)
                if ($bestJ < 0) {
                    foreach ($binFracs as $j => $tj) {
                        if ($j === $i || empty($bins[$j])) continue;
                        if ($tj + $t >= $giantThreshold) continue;
                        $d = $this->closestApproachSq($bins[$i], $bins[$j], $centroids);
                        if ($d < $bestDist) { $bestDist = $d; $bestJ = $j; }
                    }
                }

                if ($bestJ >= 0) {
                    $bins[$bestJ]     = array_merge($bins[$bestJ], $bins[$i]);
                    $binFracs[$bestJ] += $binFracs[$i];
                    $bins[$i]         = [];
                    $binFracs[$i]     = 0.0;
                    $changed          = true;
                    break;
                }
            }
        }

        return array_values(array_filter($bins, fn($b) => !empty($b)));
    }

    /**
     * Score a candidate bin configuration against the operator's doctrine criteria
     * (ruling 2026-07-08). Fields feed scoreRank()/scoreBeats() for lexicographic
     * comparison (lower = better for all fields):
     *
     *   1. seat_drift         (|Σseats − budget|)          — budget exactness: drifted drawings excluded
     *   2. avg_deviation_pct  (acceptability threshold 4%) — balance leads, coarsely
     *   3. max_deviation_pct  (acceptability threshold 10%) — worst-district extreme
     *   4. non_contiguous_count                            — contiguity breaks
     *   5. fragment_gap                                    — broken pieces kept CLOSE
     *   6. cut_length                                      — REAL border length (stringy = bad)
     *   7. neck_count                                      — pinch points (barely-legal contiguity)
     *   8. avg_rg_sq                                       — compactness fallback (centroid proxy)
     *   9. avg_droop_threshold                             — seat-mix/UPD, abandoned first
     *
     * The banding means a fraction-of-a-band equality gain can never buy a snake
     * district or a pointless contiguity break; a full band (0.5pp avg) can. This
     * inverts the previous contiguity-first order per the operator's sacrifice
     * hierarchy: "Population balance last [to be given up]. Contiguity I'd give up
     * to make population balance work and to remain above the floor and below the
     * ceiling."  fragment_gap operationalizes "Even when I can't be contiguous I
     * try to keep the non-contiguous pieces as close together as I can."
     *
     * Simulates the nearest-rounding seating law in-memory (operator ruling
     * 2026-07-13, mirrors Step 11) to compute accurate per-district deviations.
     * Zero DB queries — uses the adjacency graph and the shared-border lengths
     * ($this->borderLen) already loaded in runAutoCompositeForScope().
     *
     * Note: hull-ratio compactness (convex_hull_ratio) requires ST_Union geometry —
     * scored post-insert by recomputeDistrict() for display. The IN-LOOP shape
     * signal is cut_length (round 10): real shared-border length between districts,
     * free at score time from the Step-7 edge query. Community integrity is
     * determined at classification time (giants pre-separated) — not scored here.
     */
    /**
     * Break repair on the final drawing (Good Maps, 2026-08-23; iter-8).
     * The reported is_contiguous flags a district only when its break was
     * AVOIDABLE — an orphaned piece bordering an AVAILABLE sibling (true
     * islands and giant-locked remainders are exempt, the Round-8 law).
     * Auto's residue against the standard was exactly avoidable structure:
     * balance variants split mainlands the standard keeps whole (Spain,
     * Viet Nam), and scattered detached pieces spread across many districts
     * where the standard concentrates them in ONE (China: three flagged
     * pairs — Jilin+Tibet among them — against the standard's single
     * three-province district). Contiguity outranks compactness, so this
     * runs before hullRepairPass.
     *
     *   Pass A — CONSOLIDATION: move whole avoidable fragments between bins
     *   so the number of FLAGGED bins strictly drops (the operator's China
     *   shape: all the loose pieces ride one district).
     *   Pass B — MAINLAND RE-SPLIT: for a bin still flagged, re-bisect it
     *   with each touching neighbor (satellite-aware, as hullRepairPass)
     *   and adopt a split that reduces the pair's flagged count; among
     *   flag-equal candidates the lower pair Rg² wins (no geometry calls).
     *
     * Deviation caps sit at the ACCEPTABILITY band edge (±4% of the integer
     * target — his order pays deviation for contiguity), which still
     * guarantees seat exactness: 0.04 × 9 quota = 0.36 quota < the 0.5
     * nearest-rounding boundary, so every bin still rounds to its target
     * and the landed budget is preserved.
     */
    private function breakRepairPass(
        array $bins,
        array $childById,
        array $adj,
        array $centroids,
        string $legislatureId,
        int   $budget,
        int   $floor,
        int   $ceiling,
        float $giantThreshold,
        float $floorBoundary
    ): array {
        $bins = array_values(array_filter($bins, fn ($b) => !empty($b)));
        $k = count($bins);
        if ($k < 2 || $budget <= 0) return $bins;

        $binPops  = array_map(fn ($b) => array_sum(array_map(fn ($j) => (int) $childById[$j]->population, $b)), $bins);
        $totalPop = array_sum($binPops);
        if ($totalPop <= 0) return $bins;
        $quotaPop = $totalPop / $budget;

        $assigned = [];
        foreach ($bins as $bi => $bj) { foreach ($bj as $jm) { $assigned[$jm] = $bi; } }

        // p90×16 edge cap, same recipe as everywhere else in the pipeline.
        $dists = [];
        foreach ($assigned as $jid => $_) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($assigned[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                $dists[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($dists);
        $p90 = max(0, (int) floor(count($dists) * 0.90) - 1);
        $maxEdgeDistSq = !empty($dists) ? $dists[$p90] * 16.0 : PHP_FLOAT_MAX;

        // Distance-filtered fragments of one bin's member set.
        $fragmentsOf = function (array $members) use ($adj, $centroids, $maxEdgeDistSq): array {
            $set = array_flip($members);
            $seen = []; $frags = [];
            foreach ($members as $start) {
                if (isset($seen[$start])) continue;
                $frag = []; $queue = [$start]; $qh = 0; $seen[$start] = true;
                while (isset($queue[$qh])) {
                    $cur = $queue[$qh++];
                    $frag[] = $cur;
                    foreach ($adj[$cur] ?? [] as $nb) {
                        if (!isset($set[$nb]) || isset($seen[$nb])) continue;
                        $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                        $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                        if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                        $seen[$nb] = true;
                        $queue[] = $nb;
                    }
                }
                $frags[] = $frag;
            }
            usort($frags, fn ($a, $b) => count($b) <=> count($a));
            return $frags;
        };
        // Avoidable fragments: everything beyond the largest fragment that has
        // at least one adjacency edge anywhere in the pool (an edge-less piece
        // is a true island — the Round-8 exemption; a piece with an edge had a
        // better grouping available).
        $avoidableFrags = function (array $members) use ($fragmentsOf, $adj, &$assigned): array {
            $frags = $fragmentsOf($members);
            $out = [];
            for ($f = 1, $fc = count($frags); $f < $fc; $f++) {
                $hasEdge = false;
                foreach ($frags[$f] as $m) {
                    foreach ($adj[$m] ?? [] as $nb) {
                        if (isset($assigned[$nb])) { $hasEdge = true; break 2; }
                    }
                }
                if ($hasEdge) $out[] = $frags[$f];
            }
            return $out;
        };
        $flaggedCount = function () use (&$bins, $avoidableFrags): int {
            $n = 0;
            foreach ($bins as $b) { if (!empty($avoidableFrags($b))) $n++; }
            return $n;
        };

        $low = ($budget >= $k * $floor) ? $floor : 1;
        // Acceptability-band caps that still guarantee exactness (see docblock).
        $devCap = 0.04;
        $capsOk = function (int $bIdx, float $newPop, float $newFrac, array $targets) use ($quotaPop, $devCap, $floorBoundary, $giantThreshold): bool {
            if ($newFrac < $floorBoundary || $newFrac >= $giantThreshold) return false;
            $t = max(($targets[$bIdx] ?? 0) * $quotaPop, 1.0);
            return $newPop > 0 && abs($newPop - $t) / $t <= $devCap;
        };
        $binFracsL = array_map(fn ($b) => array_sum(array_map(fn ($j) => (float) $childById[$j]->fractional_seats, $b)), $bins);

        // ── Pass A: fragment consolidation ─────────────────────────────────
        $before = $flaggedCount();
        for ($round = 0; $round < 6 && $before > 0; $round++) {
            $targets = $this->optimalIntegerTargets($binPops, $quotaPop, $budget, $low, $ceiling);
            if (count($targets) !== $k) break;
            $bestMove = null; $bestAfter = $before;
            for ($b = 0; $b < $k; $b++) {
                foreach ($avoidableFrags($bins[$b]) as $frag) {
                    $fragPop  = array_sum(array_map(fn ($m) => (float) $childById[$m]->population, $frag));
                    $fragFrac = array_sum(array_map(fn ($m) => (float) $childById[$m]->fractional_seats, $frag));
                    if (count($frag) >= count($bins[$b])) continue; // never empty the donor
                    // Destinations: bins the fragment borders, and bins already
                    // holding avoidable fragments (the consolidation target).
                    $dests = [];
                    foreach ($frag as $m) {
                        foreach ($adj[$m] ?? [] as $nb) {
                            $bn = $assigned[$nb] ?? -1;
                            if ($bn >= 0 && $bn !== $b) $dests[$bn] = true;
                        }
                    }
                    for ($c = 0; $c < $k; $c++) {
                        if ($c !== $b && !empty($avoidableFrags($bins[$c]))) $dests[$c] = true;
                    }
                    foreach (array_keys($dests) as $c) {
                        if (!$capsOk($b, $binPops[$b] - $fragPop, $binFracsL[$b] - $fragFrac, $targets)) continue;
                        if (!$capsOk($c, $binPops[$c] + $fragPop, $binFracsL[$c] + $fragFrac, $targets)) continue;
                        // Trial-apply, measure, revert.
                        $fragSet = array_flip($frag);
                        $savedB = $bins[$b]; $savedC = $bins[$c];
                        $bins[$b] = array_values(array_filter($bins[$b], fn ($x) => !isset($fragSet[$x])));
                        $bins[$c] = array_merge($bins[$c], $frag);
                        foreach ($frag as $m) { $assigned[$m] = $c; }
                        $after = $flaggedCount();
                        $bins[$b] = $savedB; $bins[$c] = $savedC;
                        foreach ($frag as $m) { $assigned[$m] = $b; }
                        if ($after < $bestAfter) {
                            $bestAfter = $after;
                            $bestMove  = ['frag' => $frag, 'from' => $b, 'to' => $c, 'pop' => $fragPop, 'frac' => $fragFrac];
                        }
                    }
                }
            }
            if ($bestMove === null) break;
            $fragSet = array_flip($bestMove['frag']);
            $bins[$bestMove['from']] = array_values(array_filter($bins[$bestMove['from']], fn ($x) => !isset($fragSet[$x])));
            $bins[$bestMove['to']]   = array_merge($bins[$bestMove['to']], $bestMove['frag']);
            foreach ($bestMove['frag'] as $m) { $assigned[$m] = $bestMove['to']; }
            $binPops[$bestMove['from']]  -= $bestMove['pop'];  $binPops[$bestMove['to']]  += $bestMove['pop'];
            $binFracsL[$bestMove['from']] -= $bestMove['frac']; $binFracsL[$bestMove['to']] += $bestMove['frac'];
            $before = $bestAfter;
            $this->publishMassProgress($legislatureId, [
                'phase'       => 'break_repair',
                'phase_label' => sprintf('Break repair: consolidated a fragment (%d flagged bins remain)', $before),
            ]);
        }

        // ── Pass B: mainland re-split of still-flagged pairs ───────────────
        for ($round = 0; $round < 2; $round++) {
            $targets = $this->optimalIntegerTargets($binPops, $quotaPop, $budget, $low, $ceiling);
            if (count($targets) !== $k) break;
            $improved = false;
            for ($i = 0; $i < $k; $i++) {
                if (empty($avoidableFrags($bins[$i]))) continue;
                for ($j = 0; $j < $k; $j++) {
                    if ($j === $i || empty($bins[$j])) continue;
                    $touching = false;
                    foreach ($bins[$i] as $m) {
                        foreach ($adj[$m] ?? [] as $nb) {
                            if (($assigned[$nb] ?? -1) === $j) { $touching = true; break 2; }
                        }
                    }
                    if (!$touching) continue;
                    $union = array_merge($bins[$i], $bins[$j]);
                    if (count($union) < 4 || count($union) > 240) continue;
                    $pairBudget = (int) (($targets[$i] ?? 0) + ($targets[$j] ?? 0));
                    $parts2 = $this->canonicalPartition($pairBudget, 2, $floor, $ceiling);
                    if ($parts2 === null) continue;

                    $pairFlagged = (empty($avoidableFrags($bins[$i])) ? 0 : 1) + (empty($avoidableFrags($bins[$j])) ? 0 : 1);
                    $comps = $fragmentsOf($union);
                    $mainland = $comps[0];
                    $satellites = array_slice($comps, 1);
                    if (count($mainland) < 4) continue;

                    $bestSplit = null; $bestFlag = $pairFlagged; $bestRgV = PHP_FLOAT_MAX;
                    foreach ($this->bisectionCandidates($mainland, $childById, $adj, $centroids, $pairBudget, 2, $quotaPop, $floor, $ceiling) as $halves) {
                        if (count($halves) !== 2 || empty($halves[0]) || empty($halves[1])) continue;
                        [$hA, $hB] = $halves;
                        // Closest-approach satellite attachment (as hullRepairPass).
                        foreach ($satellites as $sc) {
                            $bestD = PHP_FLOAT_MAX; $toA = true;
                            foreach ($sc as $sm) {
                                $sx = $centroids[$sm]['x'] ?? 0.0; $sy = $centroids[$sm]['y'] ?? 0.0;
                                foreach ([[true, $hA], [false, $hB]] as [$isA, $half]) {
                                    foreach ($half as $hm) {
                                        $dx = $sx - ($centroids[$hm]['x'] ?? 0.0);
                                        $dy = $sy - ($centroids[$hm]['y'] ?? 0.0);
                                        $d = $dx * $dx + $dy * $dy;
                                        if ($d < $bestD) { $bestD = $d; $toA = $isA; }
                                    }
                                }
                            }
                            if ($toA) { $hA = array_merge($hA, $sc); } else { $hB = array_merge($hB, $sc); }
                        }
                        $popA = 0.0; $fracA = 0.0;
                        foreach ($hA as $a) { $popA += (float) $childById[$a]->population; $fracA += (float) $childById[$a]->fractional_seats; }
                        $popB = 0.0; $fracB = 0.0;
                        foreach ($hB as $b2) { $popB += (float) $childById[$b2]->population; $fracB += (float) $childById[$b2]->fractional_seats; }
                        // Match halves to the pair's two targets (larger↔larger).
                        $tBig = max($parts2); $tSmall = min($parts2);
                        $tA = $popA >= $popB ? $tBig : $tSmall;
                        $tB = $popA >= $popB ? $tSmall : $tBig;
                        if ($fracA < $floorBoundary || $fracA >= $giantThreshold) continue;
                        if ($fracB < $floorBoundary || $fracB >= $giantThreshold) continue;
                        if (abs($popA - $tA * $quotaPop) / max($tA * $quotaPop, 1.0) > $devCap) continue;
                        if (abs($popB - $tB * $quotaPop) / max($tB * $quotaPop, 1.0) > $devCap) continue;
                        $flagA = empty($avoidableFrags($hA)) ? 0 : 1;
                        $flagB = empty($avoidableFrags($hB)) ? 0 : 1;
                        $flag  = $flagA + $flagB;
                        if ($flag > $bestFlag) continue;
                        $rg = 0.0;
                        foreach ([$hA, $hB] as $half) {
                            $M = 0.0; $sx = 0.0; $sy = 0.0; $sx2 = 0.0; $sy2 = 0.0;
                            foreach ($half as $hm) {
                                $p = (float) $childById[$hm]->population;
                                $x = $centroids[$hm]['x'] ?? 0.0; $y = $centroids[$hm]['y'] ?? 0.0;
                                $M += $p; $sx += $p * $x; $sy += $p * $y; $sx2 += $p * $x * $x; $sy2 += $p * $y * $y;
                            }
                            if ($M > 0) $rg += ($sx2 + $sy2) / $M - ($sx * $sx + $sy * $sy) / ($M * $M);
                        }
                        if ($flag < $bestFlag || ($flag === $bestFlag && $bestSplit !== null && $rg < $bestRgV)) {
                            $bestFlag = $flag; $bestRgV = $rg; $bestSplit = [$hA, $hB];
                        }
                    }
                    if ($bestSplit !== null && $bestFlag < $pairFlagged) {
                        [$hA, $hB] = $bestSplit;
                        $bins[$i] = array_values($hA);
                        $bins[$j] = array_values($hB);
                        foreach ($hA as $m) { $assigned[$m] = $i; }
                        foreach ($hB as $m) { $assigned[$m] = $j; }
                        $binPops[$i]  = array_sum(array_map(fn ($jm) => (int) $childById[$jm]->population, $hA));
                        $binPops[$j]  = array_sum(array_map(fn ($jm) => (int) $childById[$jm]->population, $hB));
                        $binFracsL[$i] = array_sum(array_map(fn ($jm) => (float) $childById[$jm]->fractional_seats, $hA));
                        $binFracsL[$j] = array_sum(array_map(fn ($jm) => (float) $childById[$jm]->fractional_seats, $hB));
                        $improved = true;
                        $this->publishMassProgress($legislatureId, [
                            'phase'       => 'break_repair',
                            'phase_label' => sprintf('Break repair: re-split districts %d+%d (pair flags %d→%d)', $i + 1, $j + 1, $pairFlagged, $bestFlag),
                        ]);
                    }
                }
            }
            if (!$improved) break;
        }

        return $bins;
    }

    /**
     * Hull repair on the final drawing (Good Maps, 2026-08-23). The in-loop
     * shape currency (cut length) anticorrelates with the REPORTED compactness
     * metric (convex hull ratio) on concave/coastal scopes — iter-5's shorter
     * cuts dropped Ukraine .738 → .662 and West Java .792 → .677. One bounded
     * round over the final bins: per touching pair, the incumbent split
     * defends against the cut-best and Rg²-best legal candidates from the
     * pair's 12-direction bisection sweep; the challengers are measured with
     * recomputeDistrict's EXACT hull formula (two-tier simplify + cache →
     * union → area/hull-area), so the pass optimizes the reported number
     * itself. Adoption needs a strictly better pair-mean hull ratio (or equal
     * hull with shorter cut) under the standing guards. 2-3 PostGIS union
     * calls per pair, final config only.
     */
    private function hullRepairPass(
        array $bins,
        array $childById,
        array $adj,
        array $centroids,
        string $legislatureId,
        int   $budget,
        int   $floor,
        int   $ceiling,
        float $giantThreshold,
        float $floorBoundary
    ): array {
        $bins = array_values(array_filter($bins, fn ($b) => !empty($b)));
        $k = count($bins);
        if ($k < 2 || $budget <= 0 || empty($this->borderLen)) return $bins;

        $binPops  = array_map(fn ($b) => array_sum(array_map(fn ($j) => (int) $childById[$j]->population, $b)), $bins);
        $totalPop = array_sum($binPops);
        if ($totalPop <= 0) return $bins;
        $quotaPop = $totalPop / $budget;

        $assigned = [];
        foreach ($bins as $bi => $bj) { foreach ($bj as $jm) { $assigned[$jm] = $bi; } }

        $low     = ($budget >= $k * $floor) ? $floor : 1;
        $targets = $this->optimalIntegerTargets($binPops, $quotaPop, $budget, $low, $ceiling);
        if (count($targets) !== $k) return $bins;

        // p90×16 edge cap, same recipe as the expansion passes.
        $dists = [];
        foreach ($assigned as $jid => $_) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($assigned[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                $dists[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($dists);
        $p90 = max(0, (int) floor(count($dists) * 0.90) - 1);
        $maxEdgeDistSq = !empty($dists) ? $dists[$p90] * 16.0 : PHP_FLOAT_MAX;

        $blOf = fn (string $a, string $b): float =>
            $this->borderLen[$a . '|' . $b] ?? $this->borderLen[$b . '|' . $a] ?? 0.0;

        $pairCut = function (array $A, array $B) use ($adj, $blOf): float {
            $bSet = array_flip($B);
            $cut  = 0.0;
            foreach ($A as $a) {
                foreach ($adj[$a] ?? [] as $nb) {
                    if (isset($bSet[$nb])) $cut += $blOf((string) $a, (string) $nb);
                }
            }
            return $cut;
        };
        $pairRg = function (array $A, array $B) use ($childById, $centroids): float {
            $rg = 0.0;
            foreach ([$A, $B] as $half) {
                $M = 0.0; $sx = 0.0; $sy = 0.0; $sx2 = 0.0; $sy2 = 0.0;
                foreach ($half as $jm) {
                    $p = (float) $childById[$jm]->population;
                    $x = $centroids[$jm]['x'] ?? 0.0;
                    $y = $centroids[$jm]['y'] ?? 0.0;
                    $M += $p; $sx += $p * $x; $sy += $p * $y;
                    $sx2 += $p * $x * $x; $sy2 += $p * $y * $y;
                }
                if ($M > 0) $rg += ($sx2 + $sy2) / $M - ($sx * $sx + $sy * $sy) / ($M * $M);
            }
            return $rg;
        };

        $pairsSeen = 0;
        for ($i = 0; $i < $k; $i++) {
            for ($j = $i + 1; $j < $k; $j++) {
                if (empty($bins[$i]) || empty($bins[$j])) continue;
                $touching = false;
                foreach ($bins[$i] as $m) {
                    foreach ($adj[$m] ?? [] as $nb) {
                        if (($assigned[$nb] ?? -1) === $j) { $touching = true; break 2; }
                    }
                }
                if (!$touching) continue;
                $union = array_merge($bins[$i], $bins[$j]);
                $n = count($union);
                if ($n < 4 || $n > 240) continue;
                $pairBudget = (int) ($targets[$i] + $targets[$j]);
                $parts2 = $this->canonicalPartition($pairBudget, 2, $floor, $ceiling);
                if ($parts2 === null) continue;

                // SATELLITE-AWARE bisection (iter-7 fix): island-carrying pairs
                // froze in iter-6 — any half holding a detached member failed
                // connectedSet, so every challenger was illegal and the
                // incumbent won by default (France, Spain, Philippines, Canada,
                // Russia, Ukraine's Crimea edge, the whole class). Mirror the
                // engine's own island doctrine (round-9 accounting, Draft-6
                // attach-after-scoring): bisect the union's MAINLAND, re-attach
                // each detached component to the closest half, and exempt
                // satellites from the connectivity test — the same exemption
                // the reported is_contiguous stat gives real islands.
                $unionSet = array_flip($union);
                $seenU    = [];
                $comps    = [];
                foreach ($union as $start) {
                    if (isset($seenU[$start])) continue;
                    $comp = []; $queue = [$start]; $qh = 0; $seenU[$start] = true;
                    while (isset($queue[$qh])) {
                        $cur = $queue[$qh++];
                        $comp[] = $cur;
                        foreach ($adj[$cur] ?? [] as $nb) {
                            if (!isset($unionSet[$nb]) || isset($seenU[$nb])) continue;
                            $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                            $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                            if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                            $seenU[$nb] = true;
                            $queue[] = $nb;
                        }
                    }
                    $comps[] = $comp;
                }
                usort($comps, fn ($a, $b) => count($b) <=> count($a));
                $mainland   = $comps[0];
                $satellites = array_slice($comps, 1);
                if (count($mainland) < 4) continue;
                $satSet = [];
                foreach ($satellites as $sc) { foreach ($sc as $sm) { $satSet[$sm] = true; } }

                $attach = function (array $hA, array $hB) use ($satellites, $centroids): array {
                    foreach ($satellites as $sc) {
                        $bestD = PHP_FLOAT_MAX; $toA = true;
                        foreach ($sc as $sm) {
                            $sx = $centroids[$sm]['x'] ?? 0.0; $sy = $centroids[$sm]['y'] ?? 0.0;
                            foreach ([[true, $hA], [false, $hB]] as [$isA, $half]) {
                                foreach ($half as $hm) {
                                    $dx = $sx - ($centroids[$hm]['x'] ?? 0.0);
                                    $dy = $sy - ($centroids[$hm]['y'] ?? 0.0);
                                    $d = $dx * $dx + $dy * $dy;
                                    if ($d < $bestD) { $bestD = $d; $toA = $isA; }
                                }
                            }
                        }
                        if ($toA) { $hA = array_merge($hA, $sc); } else { $hB = array_merge($hB, $sc); }
                    }
                    return [$hA, $hB];
                };

                $legal = function (array $A, array $B) use ($childById, $parts2, $quotaPop, $floorBoundary, $giantThreshold, $adj, $centroids, $maxEdgeDistSq, $satSet): bool {
                    $popA = 0.0; $fracA = 0.0;
                    foreach ($A as $a) { $popA += (float) $childById[$a]->population; $fracA += (float) $childById[$a]->fractional_seats; }
                    $popB = 0.0; $fracB = 0.0;
                    foreach ($B as $b) { $popB += (float) $childById[$b]->population; $fracB += (float) $childById[$b]->fractional_seats; }
                    if ($fracA < $floorBoundary || $fracA >= $giantThreshold) return false;
                    if ($fracB < $floorBoundary || $fracB >= $giantThreshold) return false;
                    $pBig = max($parts2) * $quotaPop; $pSmall = min($parts2) * $quotaPop;
                    $tA = $popA >= $popB ? $pBig : $pSmall;
                    $tB = $popA >= $popB ? $pSmall : $pBig;
                    if (abs($popA - $tA) / max($tA, 1.0) > 0.025) return false;
                    if (abs($popB - $tB) / max($tB, 1.0) > 0.025) return false;
                    // Connectivity judged on the mainland part only — attached
                    // satellites are the lawful island exemption.
                    $mA = array_values(array_filter($A, fn ($x) => !isset($satSet[$x])));
                    $mB = array_values(array_filter($B, fn ($x) => !isset($satSet[$x])));
                    if (empty($mA) || empty($mB)) return false;
                    if (!$this->connectedSet($mA, $adj, $centroids, $maxEdgeDistSq)) return false;
                    if (!$this->connectedSet($mB, $adj, $centroids, $maxEdgeDistSq)) return false;
                    return true;
                };

                // Cheap pre-rank of the sweeps: cut-best plus the top-3 by Rg²
                // (Rg² tracks hull compactness far better than cut — iter-6
                // trimmed to two challengers and dropped hull winners).
                $bestCut = null; $bestCutV = PHP_FLOAT_MAX;
                $rgRank  = [];
                foreach ($this->bisectionCandidates($mainland, $childById, $adj, $centroids, $pairBudget, 2, $quotaPop, $floor, $ceiling) as $halves) {
                    if (count($halves) !== 2 || empty($halves[0]) || empty($halves[1])) continue;
                    [$fA, $fB] = $attach($halves[0], $halves[1]);
                    if (!$legal($fA, $fB)) continue;
                    $cv = $pairCut($fA, $fB);
                    if ($cv < $bestCutV) { $bestCutV = $cv; $bestCut = [$fA, $fB]; }
                    $rgRank[] = ['rg' => $pairRg($fA, $fB), 'halves' => [$fA, $fB]];
                }
                if ($bestCut === null && empty($rgRank)) continue;
                usort($rgRank, fn ($a, $b) => $a['rg'] <=> $b['rg']);
                $challengers = [$bestCut];
                foreach (array_slice($rgRank, 0, 3) as $rr) { $challengers[] = $rr['halves']; }

                if (++$pairsSeen % 8 === 1) {
                    $this->publishMassProgress($legislatureId, [
                        'phase'       => 'hull_repair',
                        'phase_label' => sprintf('Hull repair: pair %d (districts %d+%d)', $pairsSeen, $i + 1, $j + 1),
                    ]);
                }

                $curHull = $this->pairHullMean($bins[$i], $bins[$j]);
                if ($curHull === null) continue;
                $curSig  = $this->pairSig($bins[$i], $bins[$j]);
                $best = ['halves' => null, 'hull' => $curHull, 'cut' => $pairCut($bins[$i], $bins[$j])];
                $tried = [$curSig => true];
                foreach ($challengers as $cand) {
                    if ($cand === null) continue;
                    $sig = $this->pairSig($cand[0], $cand[1]);
                    if (isset($tried[$sig])) continue;
                    $tried[$sig] = true;
                    $h = $this->pairHullMean($cand[0], $cand[1]);
                    if ($h === null) continue;
                    $cv = $pairCut($cand[0], $cand[1]);
                    if ($h > $best['hull'] + 1e-9
                        || (abs($h - $best['hull']) <= 1e-9 && $cv < $best['cut'] - 1e-12)) {
                        $best = ['halves' => $cand, 'hull' => $h, 'cut' => $cv];
                    }
                }

                if ($best['halves'] !== null) {
                    [$hA, $hB] = $best['halves'];
                    $bins[$i] = array_values($hA);
                    $bins[$j] = array_values($hB);
                    foreach ($hA as $m) { $assigned[$m] = $i; }
                    foreach ($hB as $m) { $assigned[$m] = $j; }
                    $binPops[$i] = array_sum(array_map(fn ($jm) => (int) $childById[$jm]->population, $hA));
                    $binPops[$j] = array_sum(array_map(fn ($jm) => (int) $childById[$jm]->population, $hB));
                }
            }
        }

        return $bins;
    }

    /** Orientation-free signature of a 2-way split (for dedupe in hullRepairPass). */
    private function pairSig(array $A, array $B): string
    {
        $a = $A; $b = $B;
        sort($a); sort($b);
        $sa = implode(',', $a);
        $sb = implode(',', $b);

        return strcmp($sa, $sb) <= 0 ? md5($sa . '||' . $sb) : md5($sb . '||' . $sa);
    }

    /**
     * Mean convex-hull ratio of two member sets, by recomputeDistrict's EXACT
     * formula (two-tier simplify + jurisdiction_simplified cache → union →
     * area / hull area) so hullRepairPass optimizes the reported stat itself.
     */
    private function pairHullMean(array $A, array $B): ?float
    {
        if (empty($A) || empty($B)) return null;
        $aStr = '{' . implode(',', $A) . '}';
        $bStr = '{' . implode(',', $B) . '}';
        try {
            $row = DB::selectOne("
                WITH ga AS (
                    SELECT COALESCE(s.geom,
                               CASE
                                   WHEN ST_NPoints(j.geom) > 1000000 THEN ST_MakeValid(ST_Simplify(j.geom, 0.01))
                                   WHEN ST_NPoints(j.geom) > 50000  THEN ST_MakeValid(ST_Simplify(j.geom, 0.001))
                                   ELSE ST_MakeValid(j.geom)
                               END) AS geom
                    FROM jurisdictions j
                    LEFT JOIN jurisdiction_simplified s ON s.jurisdiction_id = j.id
                    WHERE j.id = ANY(:a::uuid[]) AND j.geom IS NOT NULL AND j.deleted_at IS NULL
                ),
                ua AS (SELECT ST_MakeValid(ST_Union(geom)) AS geom FROM ga),
                gb AS (
                    SELECT COALESCE(s.geom,
                               CASE
                                   WHEN ST_NPoints(j.geom) > 1000000 THEN ST_MakeValid(ST_Simplify(j.geom, 0.01))
                                   WHEN ST_NPoints(j.geom) > 50000  THEN ST_MakeValid(ST_Simplify(j.geom, 0.001))
                                   ELSE ST_MakeValid(j.geom)
                               END) AS geom
                    FROM jurisdictions j
                    LEFT JOIN jurisdiction_simplified s ON s.jurisdiction_id = j.id
                    WHERE j.id = ANY(:b::uuid[]) AND j.geom IS NOT NULL AND j.deleted_at IS NULL
                ),
                ub AS (SELECT ST_MakeValid(ST_Union(geom)) AS geom FROM gb)
                SELECT
                    (SELECT ST_Area(geom) / NULLIF(ST_Area(ST_ConvexHull(geom)), 0) FROM ua) AS ra,
                    (SELECT ST_Area(geom) / NULLIF(ST_Area(ST_ConvexHull(geom)), 0) FROM ub) AS rb
            ", ['a' => $aStr, 'b' => $bStr]);
        } catch (\Throwable $e) {
            return null; // reflection-driven tests / degenerate geometry — pass stays inert
        }
        if ($row === null || $row->ra === null || $row->rb === null) return null;

        return ((float) $row->ra + (float) $row->rb) / 2.0;
    }

    private function scoreConfiguration(
        array $bins,
        array $childById,
        array $adj,
        float $totalBinPop,
        int   $nonGiantBudget,
        int   $floor,
        int   $ceiling,
        float $floorBoundary
    ): array {
        $binCount      = count($bins);
        $binQuota      = $totalBinPop / max($nonGiantBudget, 1);
        $floorFeasible = ($nonGiantBudget >= $binCount * $floor);
        $minSeat       = $floorFeasible ? $floor : 1;

        // Simulate the SEATING LAW in-memory (operator ruling 2026-07-13,
        // mirrors Step 11 exactly): each bin rounds to NEAREST independently,
        // clamped to the constitutional floor/ceiling — no total-forcing loop.
        // Deviations below are computed against these seats, so a partition
        // whose districts miss whole multiples is punished through the balance
        // keys: the comparator itself steers the generators toward
        // whole-multiple drawing instead of a redistribution loop hiding the
        // miss ("the rounding takes place in … the district drawing phase").
        $binPops  = array_map(
            fn($b) => array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $b)),
            $bins
        );
        $binSeats = array_map(
            fn($p) => max($minSeat, min($ceiling, (int) round($binQuota > 0 ? $p / $binQuota : 0.0))),
            $binPops
        );

        // Seat drift: |Σ nearest-rounded seats − pool budget|. The operator's
        // exactness rule (ruling 2026-07-13, the Draft-9 undercount): "generated
        // outcomes that dont arrive at the parent seat budget are excluded …
        // another configuration needs to be considered when generating." The
        // drawing must land the budget; rounding never forces it. scoreRank()
        // ranks this FIRST, so a drifted configuration can never beat ANY
        // budget-exact one — it survives only when no exact drawing exists
        // (indivisible-atom scopes), shipping closest-possible under the
        // undercount flag.
        $seatDrift = abs(array_sum($binSeats) - $nonGiantBudget);

        // Compute per-bin deviation percentages
        $deviations = [];
        foreach ($bins as $i => $binJids) {
            $pop   = $binPops[$i];
            $seats = $binSeats[$i];
            if ($seats <= 0 || $binQuota <= 0) { $deviations[] = 0.0; continue; }
            $deviations[] = abs($pop / $seats - $binQuota) / $binQuota * 100;
        }

        // Uniform Political Diversity — average Droop entry threshold across the
        // districts (lower = more diverse). A district of s seats has a Droop
        // threshold of 1/(s+1); larger magnitudes clear at a lower threshold, so
        // more factions win representation (more proportional → more diverse).
        // Averaging per district rewards larger districts AND punishes a lone
        // small outlier — a 3-seat district sits at 1/4, dragging the mean up far
        // more than the convex curve gives back — so the single scalar captures
        // "maximise political diversity, accounting for spread". This REPLACES the
        // former seat-variance uniformity proxy: for a 61-seat budget it now
        // prefers 5×9+2×8 (avg ≈ 0.103) over 9×6+1×7 (avg ≈ 0.141) — bigger
        // districts, lower thresholds, more diversity.
        $droopSum = 0.0;
        foreach ($binSeats as $s) {
            $droopSum += 1.0 / ($s + 1);
        }
        $avgDroopThreshold = $binCount > 0 ? $droopSum / $binCount : 1.0;

        // Reps-per-district equality: the spread of the simulated seat vector.
        // 6/6/6 → 0; 7/6/5 → 2. Ranked above compactness by scoreRank().
        $seatSpread = $binCount > 0 ? max($binSeats) - min($binSeats) : 0;

        // Spread EXCESS over this k's own canonical partition (Good Maps retune,
        // operator order 2026-08-23 — "arrive as close to my maps as possible or
        // Better on all counts"). budget % k ≠ 0 makes spread 1 an arithmetic
        // NECESSITY at that k, not a quality defect — raw spread punished every
        // k whose canonical mix is uneven and short-circuited the across-k
        // choice toward thin plans (the Texas specimen: 10×5 beat the operator's
        // 9+9+8+8+8+8 on raw spread alone, against a 2.3× cut-length cost, 12
        // necks and a worse Droop threshold). Excess preserves the within-k
        // doctrine byte-intact: a non-canonical mix at the same k still carries
        // its full penalty (7/6/5 where 6/6/6 exists → excess 2). No legal
        // canonical at this k (drifted / satellite-adjusted budgets) → excess
        // falls back to the raw spread.
        $canonForSpread   = $this->canonicalPartition($nonGiantBudget, $binCount, $floor, $ceiling);
        $seatSpreadExcess = $canonForSpread !== null
            ? max(0, $seatSpread - (max($canonForSpread) - min($canonForSpread)))
            : $seatSpread;

        // In-memory contiguity: BFS reachability within each bin using the adjacency graph.
        // Apply the same distance-based false-positive filter used in geographicSeedExpansion:
        // ignore adjacency edges whose centroid distance exceeds 4× the 90th-percentile edge
        // length for this component.  Without this, a false-positive long-distance edge in the
        // adjacency table lets two disconnected halves appear "reachable" and hides the
        // non-contiguous configuration from the scorer — causing it to win the competition.
        // Centroids are available as centroid_x / centroid_y on each $childById entry.
        $allJids      = array_merge(...$bins);
        $jidInComp    = array_flip($allJids);
        $scAdjDistsSq = [];
        foreach ($allJids as $jid) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($jidInComp[$nb])) continue;
                $dx = ($childById[$jid]->centroid_x ?? 0.0) - ($childById[$nb]->centroid_x ?? 0.0);
                $dy = ($childById[$jid]->centroid_y ?? 0.0) - ($childById[$nb]->centroid_y ?? 0.0);
                $scAdjDistsSq[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($scAdjDistsSq);
        $scP90Idx        = max(0, (int) floor(count($scAdjDistsSq) * 0.90) - 1);
        $scMaxEdgeDistSq = !empty($scAdjDistsSq) ? $scAdjDistsSq[$scP90Idx] * 16.0 : PHP_FLOAT_MAX;

        // Average radius of gyration² (compactness proxy, lower = more compact).
        // Computed in-memory using centroid_x/y; no PostGIS required.
        // Formula: Rg²_i = (Sx2+Sy2)/M − (Sx²+Sy²)/M²  where M=total pop, Sx=sum(pop×lon), etc.
        $totalRgSq = 0.0;
        foreach ($bins as $i => $binJids) {
            $M = (float) $binPops[$i];
            if ($M <= 0.0) continue;
            $sx = 0.0; $sy = 0.0; $sx2 = 0.0; $sy2 = 0.0;
            foreach ($binJids as $jid) {
                $p  = (float) $childById[$jid]->population;
                $x  = $childById[$jid]->centroid_x ?? 0.0;
                $y  = $childById[$jid]->centroid_y ?? 0.0;
                $sx  += $p * $x;   $sy  += $p * $y;
                $sx2 += $p * $x * $x; $sy2 += $p * $y * $y;
            }
            $totalRgSq += ($sx2 + $sy2) / $M - ($sx * $sx + $sy * $sy) / ($M * $M);
        }
        $avgRgSq = $binCount > 0 ? $totalRgSq / $binCount : 0.0;

        // Contiguity + fragment proximity. Each bin's members are decomposed into
        // connected fragments (distance-filtered BFS). A bin with >1 fragment counts as
        // non-contiguous, and every fragment beyond the largest contributes its
        // closest-approach distance to the largest fragment — the operator's "keep the
        // non-contiguous pieces as close together as I can", made scoreable.
        $nonContiguousCount = 0;
        $fragmentGap        = 0.0;
        $binFragCounts      = [];
        foreach ($bins as $bIdx => $binJids) {
            if (count($binJids) <= 1) continue; // single-member bins are trivially contiguous
            $binSet    = array_flip($binJids);
            $seen      = [];
            $fragments = [];
            foreach ($binJids as $start) {
                if (isset($seen[$start])) continue;
                $frag         = [];
                $queue        = [$start];
                $qh           = 0;
                $seen[$start] = true;
                while (isset($queue[$qh])) {
                    $curr   = $queue[$qh++];
                    $frag[] = $curr;
                    foreach ($adj[$curr] ?? [] as $nb) {
                        if (!isset($binSet[$nb]) || isset($seen[$nb])) continue;
                        $dx = ($childById[$curr]->centroid_x ?? 0.0) - ($childById[$nb]->centroid_x ?? 0.0);
                        $dy = ($childById[$curr]->centroid_y ?? 0.0) - ($childById[$nb]->centroid_y ?? 0.0);
                        if ($dx * $dx + $dy * $dy > $scMaxEdgeDistSq) continue;
                        $seen[$nb] = true;
                        $queue[]   = $nb;
                    }
                }
                $fragments[] = $frag;
            }
            $binFragCounts[$bIdx] = count($fragments);
            if (count($fragments) > 1) {
                $nonContiguousCount++;
                usort($fragments, fn($a, $b) => count($b) <=> count($a));
                $main = $fragments[0];
                for ($f = 1, $fc = count($fragments); $f < $fc; $f++) {
                    $minSq = PHP_FLOAT_MAX;
                    foreach ($fragments[$f] as $aJid) {
                        foreach ($main as $mJid) {
                            $dx = ($childById[$aJid]->centroid_x ?? 0.0) - ($childById[$mJid]->centroid_x ?? 0.0);
                            $dy = ($childById[$aJid]->centroid_y ?? 0.0) - ($childById[$mJid]->centroid_y ?? 0.0);
                            $d  = $dx * $dx + $dy * $dy;
                            if ($d < $minSq) $minSq = $d;
                        }
                    }
                    if ($minSq < PHP_FLOAT_MAX) $fragmentGap += sqrt($minSq);
                }
            }
        }

        // Neck (pinch-point) detection — operator flag class, round-2 rematch:
        // a contiguous district pinched to a single cut member whose removal splits
        // it into two SUBSTANTIAL pieces meets the letter of contiguity but not its
        // spirit, and Rg² cannot see it. Tarjan articulation points over the bin's
        // distance-filtered subgraph, O(n+edges) per bin; a member counts as a neck
        // when the second-largest piece after its removal holds ≥ max(2, 25% of the
        // remaining members). Only contiguous bins are scored — broken bins are
        // already penalized harder by non_contiguous_count.
        $neckCount = 0;
        foreach ($bins as $bIdx => $binJids) {
            $n = count($binJids);
            if ($n < 4 || ($binFragCounts[$bIdx] ?? 1) > 1) continue;

            $inBin = array_flip($binJids);
            $ladj  = [];
            foreach ($binJids as $jid) {
                $ladj[$jid] = [];
                foreach ($adj[$jid] ?? [] as $nb) {
                    if (!isset($inBin[$nb])) continue;
                    $dx = ($childById[$jid]->centroid_x ?? 0.0) - ($childById[$nb]->centroid_x ?? 0.0);
                    $dy = ($childById[$jid]->centroid_y ?? 0.0) - ($childById[$nb]->centroid_y ?? 0.0);
                    if ($dx * $dx + $dy * $dy > $scMaxEdgeDistSq) continue;
                    $ladj[$jid][] = $nb;
                }
            }

            $disc = []; $low = []; $sub = []; $timer = 0;
            $minPiece = max(2, (int) ceil(0.25 * ($n - 1)));
            $dfs = function ($u, $parent) use (&$dfs, &$disc, &$low, &$sub, &$timer, &$neckCount, $ladj, $n, $minPiece) {
                $disc[$u] = $low[$u] = ++$timer;
                $sub[$u]  = 1;
                $sepSizes   = [];
                $childCount = 0;
                foreach ($ladj[$u] as $v) {
                    if (!isset($disc[$v])) {
                        $childCount++;
                        $dfs($v, $u);
                        $sub[$u] += $sub[$v];
                        if ($low[$v] < $low[$u]) $low[$u] = $low[$v];
                        if ($parent === null || $low[$v] >= $disc[$u]) {
                            $sepSizes[] = $sub[$v];
                        }
                    } elseif ($v !== $parent && $disc[$v] < $low[$u]) {
                        $low[$u] = $disc[$v];
                    }
                }
                // Pieces if u were removed: root → its child subtrees; non-root →
                // each separated subtree plus the remainder holding the parent side.
                if ($parent === null) {
                    $pieces = $childCount > 1 ? $sepSizes : [];
                } elseif (!empty($sepSizes)) {
                    $pieces   = $sepSizes;
                    $pieces[] = $n - 1 - array_sum($sepSizes);
                } else {
                    $pieces = [];
                }
                if (count($pieces) > 1) {
                    rsort($pieces);
                    if ($pieces[1] >= $minPiece) $neckCount++;
                }
            };
            $dfs($binJids[0], null);
        }

        // Total cut length (round 10, the 5-scope stringiness probe): the summed
        // REAL border length between districts, from the Step-7 edge query. This
        // is what the operator's eye reads as stringiness — a snake or wiggly
        // border is a long internal border regardless of where the population
        // sits, which is exactly the case centroid Rg² cannot see (São Paulo's
        // snake: 7× Manual's cut length at near-equal Rg²; Sichuan: Rg² actively
        // preferred the stringy plan). Satellites virtualized into bins have no
        // edges and contribute nothing; giant-locked members are absent from the
        // owner map and skip. Empty borderLen (reflection-driven tests) → 0.0
        // for every configuration → the signal is neutral.
        $cutLength = 0.0;
        if (!empty($this->borderLen)) {
            $binOf = [];
            foreach ($bins as $i => $binJids) {
                foreach ($binJids as $jid) $binOf[$jid] = $i;
            }
            foreach ($this->borderLen as $pair => $len) {
                [$pa, $pb] = explode('|', $pair);
                if (isset($binOf[$pa], $binOf[$pb]) && $binOf[$pa] !== $binOf[$pb]) {
                    $cutLength += $len;
                }
            }
        }

        return [
            'seat_drift'           => $seatDrift,
            'non_contiguous_count' => $nonContiguousCount,
            'fragment_gap'         => $fragmentGap,
            'neck_count'           => $neckCount,
            'seat_spread'          => $seatSpread,
            'seat_spread_excess'   => $seatSpreadExcess,
            'cut_length'           => $cutLength,
            'avg_rg_sq'            => $avgRgSq,
            'avg_droop_threshold'  => $avgDroopThreshold,
            'avg_deviation_pct'    => empty($deviations) ? 0.0 : array_sum($deviations) / count($deviations),
            'max_deviation_pct'    => empty($deviations) ? 0.0 : max($deviations),
        ];
    }

    /**
     * Optimal integer seat targets for a set of bins — the operator's manual method,
     * mechanized: "What I do manually is look at the optimal breakdown of reps per
     * district first … Example 6.55 vs 8.12, I'm taking the 8.12 if an 8 is closer
     * and has the least distortion."
     *
     * Given realized bin populations, finds the integer seat vector s_i in
     * [floor, ceiling] with Σs_i = budget minimizing Σ|pop_i − s_i×quota|.
     * Greedy marginal-cost adjustment is exact here: each bin's cost |pop_i − s×quota|
     * is convex in s, so repeatedly applying the cheapest single-step correction
     * toward the budget reaches the global optimum of this separable convex program.
     *
     * When the budget cannot support the floor for every bin, the lower bound relaxes
     * to 1 (mirrors Step 11's floor_override posture). When the budget exceeds
     * ceiling×bins the vector saturates at the ceiling and the sum falls short
     * (Step 11's safety loop faces the same wall).
     *
     * @param  array $binPops float population per bin, sequential integer keys
     * @return array int seat target per bin (same order); empty when unusable input
     */
    private function optimalIntegerTargets(array $binPops, float $quota, int $budget, int $floor, int $ceiling): array
    {
        $k = count($binPops);
        if ($k === 0 || $quota <= 0) return [];
        $low = ($budget >= $floor * $k) ? $floor : 1;

        $targets = [];
        foreach ($binPops as $p) {
            $targets[] = max($low, min($ceiling, (int) round(((float) $p) / $quota)));
        }

        $cost = fn(float $p, int $s): float => abs($p - $s * $quota);
        $sum  = array_sum($targets);
        while ($sum > $budget) {
            $bestI = -1; $bestDelta = PHP_FLOAT_MAX;
            foreach ($targets as $i => $s) {
                if ($s <= $low) continue;
                $delta = $cost((float) $binPops[$i], $s - 1) - $cost((float) $binPops[$i], $s);
                if ($delta < $bestDelta) { $bestDelta = $delta; $bestI = $i; }
            }
            if ($bestI < 0) break;
            $targets[$bestI]--; $sum--;
        }
        while ($sum < $budget) {
            $bestI = -1; $bestDelta = PHP_FLOAT_MAX;
            foreach ($targets as $i => $s) {
                if ($s >= $ceiling) continue;
                $delta = $cost((float) $binPops[$i], $s + 1) - $cost((float) $binPops[$i], $s);
                if ($delta < $bestDelta) { $bestDelta = $delta; $bestI = $i; }
            }
            if ($bestI < 0) break;
            $targets[$bestI]++; $sum++;
        }
        return $targets;
    }

    /**
     * Comparator rank vector encoding the operator's sacrifice hierarchy
     * (ruling 2026-07-08): floor/ceiling are inviolable (enforced upstream by frac
     * guards and Step-11 clamps), then population balance, then contiguity (breaks are
     * purchasable; fragments kept close), then compactness, then seat-mix/UPD
     * optimality — "I'm normally quick to abandon the optimal reps per district
     * balance first. Population balance last."
     *
     * Balance leads as an ACCEPTABILITY THRESHOLD, not a fine gradient: below
     * 4% average / 10% worst-district, every configuration ties on balance and
     * contiguity + shape decide. Above the threshold, 2pp/5pp bands grade how bad
     * it is. This makes breaks a LAST RESORT exactly as practiced: a break can
     * never be bought by nudging 2.1% down to 1.5% across a band edge (the West
     * Bengal north/south teleport, round-2 rematch), only by escaping genuinely
     * unacceptable balance (Canada's ±32%, Ethiopia's 8%). Raw avg deviation
     * returns as the final tiebreak, so within-threshold equality still matters
     * once contiguity and shape are settled.
     *
     * seat_drift ranks FIRST (operator ruling 2026-07-13, the Draft-9 India
     * undercount): under the seating law each district rounds to nearest, so
     * hitting the pool budget is the DRAWING's job — "generated outcomes that
     * dont arrive at the parent seat budget are excluded … another
     * configuration needs to be considered when generating." Ranking |Σseats −
     * budget| ahead of everything implements the exclusion: a drifted drawing
     * can never beat ANY budget-exact one, on any lower key, and survives only
     * when no exact drawing exists at all (indivisible-atom scopes, which ship
     * closest-possible under the undercount flag).
     *
     * cut_length LEADS the shape cluster (round 10, the 5-scope stringiness
     * probe): the real border length between districts is what the operator's
     * eye reads as stringiness, and the prior proxies were blind or backwards
     * on exactly his flagged scopes — population-weighted Rg² cannot see a
     * snake through low-population territory (São Paulo: 7× Manual's border
     * length at near-equal Rg²; Sichuan: Rg² actively preferred the stringy
     * plan) and neck_count steered Iran to a spindly 4-district plan and
     * vetoed Yunnan's clean split over a single pinch the operator's own hand
     * had accepted. Positioned BELOW the 1pp equality band (round-4 tuning
     * stands: shape never buys a band) and below spread/contiguity — it can
     * only decide among configurations the higher doctrine keys call equal.
     *
     * neck_count rides with the SHAPE cluster (round-6 Egypt probe): a pinch
     * point — one member whose removal splits the district into two substantial
     * halves — is the operator's "meets the letter of contiguity but could be
     * more compact" flag, i.e. shape spirit, not break spirit. Ranked above it,
     * a single unavoidable Nile-chain articulation vetoed a 7+7+7+7 Egypt at
     * 0.81% in favor of a spread-4 mix at 1.40%. It still decides ahead of Rg²
     * (Rg² cannot see necks), but never ahead of reps-equality or an equality
     * band — and since round 10 never ahead of real border length either (a
     * neckless plan bought with a longer, wigglier border is the Yunnan/Iran
     * failure class).
     *
     * seat_spread (max − min seats) sits above compactness (round-3 tuning: "it
     * is giving up reps per district balance long before it has to"): within
     * acceptable balance and equal contiguity, the most-equal seat mix wins —
     * 6/6/6 at 2.6% beats 7/6/5 at 0.4%. It can never buy a break or push
     * balance past the acceptability threshold; the UPD/Droop diversity metric
     * remains the last-ranked tiebreak.
     *
     * Round-4 tuning (operator, after the full-81 review): compactness RELAXED —
     * within acceptability and at equal mix, equality gets 1pp sub-bands that
     * OUTRANK shape ("the key may be laxing the avg hull ratio a bit … to open
     * up possibilities to improve the other stats"). A configuration a full
     * point better on average deviation now beats a more compact one; within
     * the same point, compactness still decides — so a snake can still never
     * be bought with a fraction of a point, and the neck detector plus the
     * border-smoothing pass hold the shape floor that used to be compactness's
     * job alone.
     */
    private function scoreRank(array $s): array
    {
        $avgExcess = $s['avg_deviation_pct'] <= 4.0 ? 0
            : 1 + (int) floor(($s['avg_deviation_pct'] - 4.0) / 2.0);
        $maxExcess = $s['max_deviation_pct'] <= 10.0 ? 0
            : 1 + (int) floor(($s['max_deviation_pct'] - 10.0) / 5.0);
        // fragment_gap rides in DOUBLING bands (round 11.1, the Vietnam veto):
        // raw-float ordering let a 20 km shift of an already-detached piece
        // outvote a halving of deviation AND a spread win (439→459 km killed a
        // 9+8+8 at 0.57% in favor of 9+9+7 at 1.22%). Band edges sit at ~1°,
        // 3°, 7°, 15° of total detachment (~110/330/780/1650 km): pieces that
        // stay in the same distance CLASS tie and the lower rules decide;
        // jumping a class still blocks. Near pieces get fine scrutiny (the
        // first bands are narrow), far pieces are all just "far" — the same
        // proportionality the operator gave balance (1pp bands) and necks.
        // EXTRA breaks still lose absolutely at the count key above; the raw
        // gap returns as the very last tiebreak.
        $gap     = max(0.0, (float) $s['fragment_gap']);
        $gapBand = (int) floor(log(1.0 + $gap) / log(2.0));

        // Good Maps retune (operator order 2026-08-23, the standard-map campaign):
        //   • key 6 is now spread EXCESS over the candidate's own canonical
        //     partition — raw spread made 5×10 beat the operator's 9+9+8+8+8+8
        //     in Texas purely because 50%10=0, an across-k artifact (the
        //     within-k mix doctrine is unchanged: non-canonical mixes at the
        //     same k carry the same penalty as before). Raw spread remains in
        //     the score array for the Phase-B gates and as the fallback when a
        //     score predates the excess field.
        //   • the former 1pp equality sub-band key is GONE: the 2026-07-08
        //     ruling makes balance an ACCEPTABILITY THRESHOLD ("within it all
        //     tie"), and the operator's standard maps spend a full band for
        //     large shape wins (South Carolina +1.08pp for −21% cut length,
        //     Pennsylvania +0.87pp for −23%). Within acceptability, shape
        //     decides; raw deviation returns as the late tiebreak (key 11).
        //     This supersedes the round-4 relaxation — pinned the new way in
        //     DistrictingDoctrineTest.
        return [
            $s['seat_drift'] ?? 0,                       //  1. BUDGET EXACTNESS — drifted drawings are excluded
            $avgExcess,                                  //  2. balance beyond acceptability (2pp bands)
            $maxExcess,                                  //  3. worst district beyond acceptability (5pp bands)
            $s['non_contiguous_count'],                  //  4. contiguity breaks (absolute — never banded)
            $gapBand,                                    //  5. break quality: fragments close, in doubling bands
            $s['seat_spread_excess'] ?? $s['seat_spread'], //  6. reps-per-district equality beyond this k's canonical mix
            $s['cut_length'] ?? 0.0,                     //  7. compactness lead: real border length (round 10)
            $s['neck_count'],                            //  8. pinch points (shape spirit, within cut ties)
            $s['avg_rg_sq'],                             //  9. compactness fallback (centroid proxy)
            $s['avg_droop_threshold'],                   // 10. seat-mix / UPD — abandoned first
            $s['avg_deviation_pct'],                     // 11. raw equality tiebreak
            $s['fragment_gap'],                          // 12. raw proximity — the very last word
        ];
    }

    /**
     * Most-equal legal partition of a seat budget into k parts (each within
     * [floor, ceiling]): rem parts of base+1, k−rem parts of base. Returns null
     * when no such partition exists at this k. The operator's "optimal breakdown
     * of reps per district", computed FIRST — exactly as he does by hand.
     */
    private function canonicalPartition(int $budget, int $k, int $floor, int $ceiling): ?array
    {
        if ($k < 1 || $budget < 1) return null;
        $base = intdiv($budget, $k);
        $rem  = $budget % $k;
        if ($base < $floor || $base > $ceiling) return null;
        if ($rem > 0 && $base + 1 > $ceiling) return null;
        return array_merge(array_fill(0, $rem, $base + 1), array_fill(0, $k - $rem, $base));
    }

    /**
     * Sequential constructive builder — the operator's manual method as a
     * generator (round-5): "look at the optimal breakdown of reps per district
     * first. Then I pick something on an edge or in a pocket or stuck in a
     * corner and connect jurisdictions compactly trying to reach close to a
     * whole number."
     *
     * Builds districts ONE AT A TIME: seed each district at the most-constrained
     * unassigned child (fewest unassigned neighbors — corners and pockets first),
     * then grow through the frontier, always taking the nearest-to-centroid child
     * that moves the bin CLOSER to its whole-seat population target, stopping at
     * the turning point. The last district absorbs the remainder — exactly where
     * the operator parks the noise by hand.
     *
     * Round-6 (Draft-4 autopsy — "the builder lost its battles"): the naive
     * last-district-absorbs rule degenerated on fat-atom geographies, so two of
     * the operator's hand habits are now mechanized:
     *   • DYNAMIC RETARGETING — each district's target is re-derived from the
     *     REMAINING budget and remaining district count ("if I find myself far
     *     off … I may try a different whole number target"): close a district at
     *     7.6 fractional and the next targets adapt to the 8 it will actually
     *     round to, instead of chasing a stale plan.
     *   • REMAINDER AWARENESS — before committing an addition, prefer the
     *     candidate that does NOT fragment the unassigned remainder (the hand
     *     never eats the junction that strands an arm); falls back softly when
     *     geography forces it.
     *
     * This reaches configurations the transfer-walking passes cannot: fat-atom
     * scopes (Egypt's 2.5-seat governorates on a Nile chain, where every walked
     * transfer overshoots) and near-ceiling districts (Russia's 9+9+9+9, where
     * round-robin growth keeps tripping the giant guard). O(n²·deg) worst case —
     * cheaper than one refinement pass over the same component.
     *
     * Returns null when construction degenerates (an empty district, or the
     * remaining budget admits no legal partition).
     */
    private function sequentialBuild(
        array $jids,
        array $childById,
        array $adj,
        array $centroids,
        int   $budget,
        int   $k,
        float $quotaPop,
        float $giantThreshold,
        int   $floor,
        int   $ceiling,
        bool  $bigFirst,
        bool  $adaptive = true, // false = round-5 flavor: fixed canonical targets,
                                // plain nearest choice (the Mexico probe: each
                                // flavor wins geographies the other loses)
        // Good Maps retune (2026-08-23): seed each district at the most
        // POPULOUS unassigned child instead of the most-constrained corner.
        // The Michigan specimen: the standard's metro district (Wayne +
        // Oakland + Macomb = exactly 7 seats) is unreachable from corner
        // seeds — every population-anchor attempt splits the metro across
        // k bins as SEEDS, and corner growth arrives with its budget spent.
        // A population seed grows outward from the metro core and closes at
        // the turning point, exactly how the operator draws a city district.
        bool  $metroSeed = false
    ): ?array {
        if ($k < 1 || $quotaPop <= 0 || count($jids) < $k) return null;

        $fixedParts = null;
        if (!$adaptive) {
            $fixedParts = $this->canonicalPartition($budget, $k, $floor, $ceiling);
            if ($fixedParts === null) return null;
            if ($bigFirst) { rsort($fixedParts); } else { sort($fixedParts); }
        }

        // Same p90×16 false-edge suppression as the rest of the pipeline.
        $jidSet = array_flip($jids);
        $dists  = [];
        foreach ($jids as $jid) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($jidSet[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                $dists[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($dists);
        $p90Idx        = max(0, (int) floor(count($dists) * 0.90) - 1);
        $maxEdgeDistSq = !empty($dists) ? $dists[$p90Idx] * 16.0 : PHP_FLOAT_MAX;

        $unassigned      = array_flip($jids);
        $bins            = [];
        $remainingBudget = $budget;
        for ($ti = 0; $ti < $k; $ti++) {
            if ($ti === $k - 1) {
                $bins[] = array_keys($unassigned);
                $unassigned = [];
                break;
            }
            if (empty($unassigned)) return null;

            // Dynamic retargeting: the target is the biggest (or smallest) part
            // of the most-equal partition of what REMAINS — not a stale plan.
            // (Non-adaptive flavor: the fixed part list, in order.)
            if ($adaptive) {
                $parts = $this->canonicalPartition($remainingBudget, $k - $ti, $floor, $ceiling);
                if ($parts === null) return null;
                $t = $bigFirst ? max($parts) : min($parts);
            } else {
                $t = $fixedParts[$ti];
            }
            $T = $t * $quotaPop;

            // Most-constrained seed: fewest unassigned neighbors (corners and
            // pockets first), deterministic tie-break by id. Metro flavor:
            // the most populous unassigned child seeds instead — city cores
            // first, each district closing around its population center.
            $seed = null;
            if ($metroSeed) {
                $seedPop = -1.0;
                foreach ($unassigned as $jid => $_) {
                    $p = (float) $childById[$jid]->population;
                    if ($seed === null || $p > $seedPop
                        || ($p === $seedPop && strcmp((string) $jid, (string) $seed) < 0)) {
                        $seedPop = $p; $seed = $jid;
                    }
                }
            } else {
                $seedDeg = PHP_INT_MAX;
                foreach ($unassigned as $jid => $_) {
                    $deg = 0;
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (isset($unassigned[$nb])) $deg++;
                    }
                    if ($seed === null || $deg < $seedDeg
                        || ($deg === $seedDeg && strcmp((string) $jid, (string) $seed) < 0)) {
                        $seedDeg = $deg; $seed = $jid;
                    }
                }
            }

            $bin = [$seed];
            unset($unassigned[$seed]);
            $binPop  = (float) $childById[$seed]->population;
            $binFrac = (float) $childById[$seed]->fractional_seats;
            $sx = $centroids[$seed]['x'] ?? 0.0;
            $sy = $centroids[$seed]['y'] ?? 0.0;
            $nBin = 1;

            while (true) {
                $curErr = abs($binPop - $T);
                $cx = $sx / $nBin;
                $cy = $sy / $nBin;
                $improving = [];
                $seen      = [];
                foreach ($bin as $m) {
                    foreach ($adj[$m] ?? [] as $nb) {
                        if (!isset($unassigned[$nb]) || isset($seen[$nb])) continue;
                        $seen[$nb] = true;
                        $dx = ($centroids[$m]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                        $dy = ($centroids[$m]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                        if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                        $p = (float) $childById[$nb]->population;
                        $f = (float) $childById[$nb]->fractional_seats;
                        if ($binFrac + $f >= $giantThreshold) continue;
                        // The turning-point rule: only additions that move the
                        // bin CLOSER to its whole-seat target.
                        if (abs($binPop + $p - $T) >= $curErr) continue;
                        $ddx = ($centroids[$nb]['x'] ?? 0.0) - $cx;
                        $ddy = ($centroids[$nb]['y'] ?? 0.0) - $cy;
                        $improving[] = ['jid' => $nb, 'd' => $ddx * $ddx + $ddy * $ddy];
                    }
                }
                if (empty($improving)) break;
                usort($improving, fn($a, $b) => ($a['d'] <=> $b['d']) ?: strcmp($a['jid'], $b['jid']));

                // Remainder awareness: among the nearest candidates, prefer one
                // whose removal does not fragment the unassigned remainder — the
                // hand never eats the junction that strands an arm. Soft: when
                // every option fragments (a forced junction), take the nearest.
                // (Non-adaptive flavor: plain nearest, as in round 5.)
                $bestC = null;
                if (!$adaptive || count($improving) === 1) {
                    $bestC = $improving[0]['jid'];
                } else {
                    $remainderList = array_keys($unassigned);
                    $remFrags = $this->fragmentCount($remainderList, $adj, $centroids, $maxEdgeDistSq);
                    foreach (array_slice($improving, 0, 8) as $cand) {
                        $without = array_values(array_filter($remainderList, fn($x) => $x !== $cand['jid']));
                        if ($this->fragmentCount($without, $adj, $centroids, $maxEdgeDistSq) <= $remFrags) {
                            $bestC = $cand['jid'];
                            break;
                        }
                    }
                    if ($bestC === null) $bestC = $improving[0]['jid'];
                }

                $bin[] = $bestC;
                unset($unassigned[$bestC]);
                $binPop  += (float) $childById[$bestC]->population;
                $binFrac += (float) $childById[$bestC]->fractional_seats;
                $sx += $centroids[$bestC]['x'] ?? 0.0;
                $sy += $centroids[$bestC]['y'] ?? 0.0;
                $nBin++;
            }
            $bins[] = $bin;

            // Deduct what this district will actually round to, so the plan for
            // the remaining districts stays honest (7.6 closed → an 8 leaves).
            $seatsEst = min($ceiling, max($floor, (int) round($binPop / $quotaPop)));
            $remainingBudget -= $seatsEst;
        }

        foreach ($bins as $b) {
            if (empty($b)) return null;
        }
        return $bins;
    }

    /** True when score $a strictly beats score $b under scoreRank() lexicographic order. */
    private function scoreBeats(array $a, array $b): bool
    {
        $ra = $this->scoreRank($a);
        $rb = $this->scoreRank($b);
        foreach ($ra as $i => $v) {
            if ($v < $rb[$i]) return true;
            if ($v > $rb[$i]) return false;
        }
        return false;
    }

    /**
     * Deliberate-break rebalance — the operator's last resort, mechanized:
     * "Sometimes that doesn't work either and I have to break contiguity in order
     * to be above the floor and below the ceiling … Even when I can't be contiguous
     * I try to keep the non-contiguous pieces as close together as I can."
     *
     * Transfers children between bins — single moves and pairwise exchanges
     * (exchanges cross balance humps single moves cannot, e.g. Canada's
     * Quebec↔Prairies class) — chasing the per-bin integer seat targets, re-derived
     * every step (dynamic retargeting). Breaks stay MINIMAL two ways:
     *   (a) every step PREFERS contiguity-preserving transfers — a transfer that
     *       keeps both bins connected always wins over a teleport at that step;
     *       teleports fire only when no clean transfer improves the balance;
     *   (b) the loop stops as soon as every bin is within 2% of a whole seat
     *       target — this pass exists to escape BAD balance, never to polish a
     *       decent map (the Uttar Pradesh shatter regression).
     * Among near-best gains the geographically closest transfer wins, so
     * unavoidable fragments stay tight; fragment_gap then judges the result.
     *
     * The caller scores the returned configuration against the contiguous original
     * under scoreBeats(): coarse-banded equality decides whether the break was worth
     * it (a ±32% Canada → yes; polishing 1.3% to 0.1% → no).
     *
     * Frac guards keep every bin inside the round-to-legal window: each bin
     * stays ≥ floorBoundary−0.5 (still rounds to ≥ floor) and < giantThreshold
     * (still rounds to ≤ ceiling). Bins are never emptied.
     *
     * EQUALIZATION MODE ($forcedTargets + $cleanOnly, round-3 tuning): instead of
     * fitting integer targets to the realized bins, chase a caller-supplied seat
     * partition (the canonical most-equal one), rank-matched to bins by population
     * each step. cleanOnly restricts every transfer to contiguity-preserving ones —
     * reps-per-district equality never buys a break.
     */
    private function breakRebalance(
        array  $bins,
        array  $childById,
        array  $centroids,
        array  $adj,
        float  $quotaPop,
        int    $budget,
        int    $floor,
        int    $ceiling,
        float  $giantThreshold,
        float  $floorBoundary,
        ?array $forcedTargets = null,
        bool   $cleanOnly = false
    ): array {
        $k = count($bins);
        if ($k < 2 || $quotaPop <= 0) return $bins;

        $bins     = array_map(fn($b) => array_values($b), $bins);
        $binPops  = array_map(fn($b) => array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $b)), $bins);
        $binFracs = array_map(fn($b) => array_sum(array_map(fn($jid) => (float) $childById[$jid]->fractional_seats, $b)), $bins);
        $overrideBoundary = $floorBoundary - 0.5;

        // Distance filter for the contiguity-preservation checks — same p90×16
        // false-positive-edge suppression as everywhere else in the pipeline.
        $allJids     = array_merge(...$bins);
        $jidSetAll   = array_flip($allJids);
        $adjDistsSq  = [];
        foreach ($allJids as $jid) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($jidSetAll[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                $adjDistsSq[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($adjDistsSq);
        $p90Idx        = max(0, (int) floor(count($adjDistsSq) * 0.90) - 1);
        $maxEdgeDistSq = !empty($adjDistsSq) ? $adjDistsSq[$p90Idx] * 16.0 : PHP_FLOAT_MAX;

        // True when the child touches (within the distance filter) any member of the set.
        $touchesSet = function (string $jid, array $set) use ($adj, $centroids, $maxEdgeDistSq): bool {
            $flip = array_flip($set);
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($flip[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                return true;
            }
            return false;
        };

        // Step cap (round-3 São Paulo hang): each step moves one child, and even a
        // badly split scope needs a few dozen transfers, not thousands. 3n steps
        // on a 637-child scope was a runaway budget.
        $maxSteps = min(array_sum(array_map('count', $bins)) * 3, 200);
        for ($step = 0; $step < $maxSteps; $step++) {
            // Dynamic retargeting: the integer targets follow the bins as they change.
            if ($forcedTargets !== null && count($forcedTargets) === $k) {
                // Equal-mix flavor: rank-match the forced parts to bins by
                // population — the biggest part chases the biggest bin.
                $order = array_keys($binPops);
                usort($order, fn($a, $b) => $binPops[$b] <=> $binPops[$a]);
                $parts = $forcedTargets;
                rsort($parts);
                $targets = [];
                foreach ($order as $rank => $bi) {
                    $targets[$bi] = $parts[$rank];
                }
            } else {
                $targets = $this->optimalIntegerTargets($binPops, $quotaPop, $budget, $floor, $ceiling);
            }
            if (empty($targets)) break;
            $tpops = [];
            foreach ($targets as $i => $t) $tpops[$i] = max($t * $quotaPop, 1.0);

            $maxDevOf = function (array $pops) use ($tpops): float {
                $worst = 0.0;
                foreach ($pops as $i => $p) {
                    $d = abs($p - $tpops[$i]) / $tpops[$i];
                    if ($d > $worst) $worst = $d;
                }
                return $worst;
            };
            $currentMax = $maxDevOf($binPops);
            if ($currentMax <= 0.02) break;   // every bin within 2% of a whole seat target — done, never polish

            // ── Phase 1 (CHEAP): collect improving transfers by balance gain only ──
            // Round-3 São Paulo hang fix: at 637 children the old exhaustive
            // exchange scan ran O(n²) pairs × per-candidate BFS = minutes per step.
            // Singles stay exhaustive (each trial is O(k)); exchanges only involve
            // the current WORST bin (only they can reduce the max) with member
            // lists capped at 64 largest movers. The expensive checks (contiguity
            // BFS, closest approach, island rule) run in Phase 2 on the top 48.
            $worstBin = 0; $worstDev = -1.0;
            foreach ($binPops as $bi => $bp) {
                $d = abs($bp - $tpops[$bi]) / $tpops[$bi];
                if ($d > $worstDev) { $worstDev = $d; $worstBin = $bi; }
            }
            $capMembers = function (array $b) use ($childById): array {
                if (count($b) <= 64) return $b;
                $sorted = $b;
                usort($sorted, fn($x, $y) =>
                    (((float) $childById[$y]->population) <=> ((float) $childById[$x]->population)) ?: strcmp($x, $y));
                return array_slice($sorted, 0, 64);
            };

            $cands = [];
            for ($i = 0; $i < $k; $i++) {
                for ($j = 0; $j < $k; $j++) {
                    if ($j === $i) continue;

                    // Single move c: i → j — exhaustive, each trial is O(k)
                    if (count($bins[$i]) >= 2) {
                        foreach ($bins[$i] as $cJid) {
                            $cPop  = (float) $childById[$cJid]->population;
                            $cFrac = (float) $childById[$cJid]->fractional_seats;
                            if ($binFracs[$i] - $cFrac < $overrideBoundary) continue;
                            if ($binFracs[$j] + $cFrac >= $giantThreshold) continue;
                            $trial      = $binPops;
                            $trial[$i] -= $cPop;
                            $trial[$j] += $cPop;
                            $gain = $currentMax - $maxDevOf($trial);
                            if ($gain > 1e-12) {
                                $cands[] = ['gain' => $gain, 'i' => $i, 'j' => $j, 'c' => $cJid, 'd' => null];
                            }
                        }
                    }

                    // Pairwise exchange c ↔ d — worst-bin pairs only, capped members
                    if ($i < $j && ($i === $worstBin || $j === $worstBin)) {
                        foreach ($capMembers($bins[$i]) as $cJid) {
                            $cPop  = (float) $childById[$cJid]->population;
                            $cFrac = (float) $childById[$cJid]->fractional_seats;
                            foreach ($capMembers($bins[$j]) as $dJid) {
                                $dPop  = (float) $childById[$dJid]->population;
                                $dFrac = (float) $childById[$dJid]->fractional_seats;
                                $newFracI = $binFracs[$i] - $cFrac + $dFrac;
                                $newFracJ = $binFracs[$j] - $dFrac + $cFrac;
                                if ($newFracI < $overrideBoundary || $newFracI >= $giantThreshold) continue;
                                if ($newFracJ < $overrideBoundary || $newFracJ >= $giantThreshold) continue;
                                $trial      = $binPops;
                                $trial[$i] += $dPop - $cPop;
                                $trial[$j] += $cPop - $dPop;
                                $gain = $currentMax - $maxDevOf($trial);
                                if ($gain > 1e-12) {
                                    $cands[] = ['gain' => $gain, 'i' => $i, 'j' => $j, 'c' => $cJid, 'd' => $dJid];
                                }
                            }
                        }
                    }
                }
            }
            if (empty($cands)) break;

            // ── Phase 2 (HEAVY, bounded): vet the top 48 gains ────────────────────
            usort($cands, fn($a, $b) =>
                ($b['gain'] <=> $a['gain']) ?: strcmp($a['c'] . ($a['d'] ?? ''), $b['c'] . ($b['d'] ?? '')));
            $eligible = [];
            foreach (array_slice($cands, 0, 48) as $cand) {
                $i = $cand['i']; $j = $cand['j']; $cJid = $cand['c']; $dJid = $cand['d'];
                $setI = array_values(array_filter($bins[$i], fn($x) => $x !== $cJid));
                if ($dJid === null) {
                    $dist = $this->closestApproachSq([$cJid], $bins[$j], $centroids);
                    // Edge-less members (true islands) may only move CLOSER — never
                    // ride as balance ballast to a farther bin ("keep the
                    // non-contiguous pieces as close together as I can").
                    if (empty($adj[$cJid]) && $dist >= $this->closestApproachSq([$cJid], $setI, $centroids)) continue;
                    // Clean = neither bin's fragment count grows: c touches its new
                    // bin, and the donor keeps (or improves) its piece count — a bin
                    // already carrying an island can still donate cleanly.
                    $clean = $touchesSet($cJid, $bins[$j])
                        && $this->fragmentCount($setI, $adj, $centroids, $maxEdgeDistSq)
                            <= $this->fragmentCount($bins[$i], $adj, $centroids, $maxEdgeDistSq);
                } else {
                    $setJ = array_values(array_filter($bins[$j], fn($x) => $x !== $dJid));
                    $dc = $this->closestApproachSq([$cJid], $setJ, $centroids);
                    $dd = $this->closestApproachSq([$dJid], $setI, $centroids);
                    // Island monotone rule, both directions.
                    if (empty($adj[$cJid]) && $dc >= $this->closestApproachSq([$cJid], $setI, $centroids)) continue;
                    if (empty($adj[$dJid]) && $dd >= $this->closestApproachSq([$dJid], $setJ, $centroids)) continue;
                    $dist = $dc + $dd;
                    // Clean = neither post-exchange bin's fragment count grows.
                    $setI[] = $dJid;
                    $setJ[] = $cJid;
                    $clean = $this->fragmentCount($setI, $adj, $centroids, $maxEdgeDistSq)
                            <= $this->fragmentCount($bins[$i], $adj, $centroids, $maxEdgeDistSq)
                        && $this->fragmentCount($setJ, $adj, $centroids, $maxEdgeDistSq)
                            <= $this->fragmentCount($bins[$j], $adj, $centroids, $maxEdgeDistSq);
                }
                $eligible[] = $cand + ['dist' => $dist, 'clean' => $clean];
            }
            if (empty($eligible)) break;

            // Contiguity-preserving transfers always outrank teleports at each step —
            // breaks fire only when NO clean transfer improves the balance.
            $cleanCands = array_values(array_filter($eligible, fn($c) => $c['clean']));
            if ($cleanOnly && empty($cleanCands)) break;   // equal-mix mode never buys a break
            if (!empty($cleanCands)) {
                $eligible = $cleanCands;
            }

            // Best balance gain wins; among near-best (≥95% of the best gain) the
            // geographically closest transfer wins — fragments stay tight.
            $maxGain = 0.0;
            foreach ($eligible as $c) {
                if ($c['gain'] > $maxGain) $maxGain = $c['gain'];
            }
            $chosen = null;
            foreach ($eligible as $c) {
                if ($c['gain'] < 0.95 * $maxGain) continue;
                if ($chosen === null || $c['dist'] < $chosen['dist']) $chosen = $c;
            }

            // Apply the chosen transfer
            $i = $chosen['i']; $j = $chosen['j']; $cJid = $chosen['c'];
            $cPop  = (float) $childById[$cJid]->population;
            $cFrac = (float) $childById[$cJid]->fractional_seats;
            $bins[$i]      = array_values(array_filter($bins[$i], fn($x) => $x !== $cJid));
            $bins[$j][]    = $cJid;
            $binPops[$i]  -= $cPop;  $binPops[$j]  += $cPop;
            $binFracs[$i] -= $cFrac; $binFracs[$j] += $cFrac;
            if ($chosen['d'] !== null) {
                $dJid  = $chosen['d'];
                $dPop  = (float) $childById[$dJid]->population;
                $dFrac = (float) $childById[$dJid]->fractional_seats;
                $bins[$j]      = array_values(array_filter($bins[$j], fn($x) => $x !== $dJid));
                $bins[$i][]    = $dJid;
                $binPops[$j]  -= $dPop;  $binPops[$i]  += $dPop;
                $binFracs[$j] -= $dFrac; $binFracs[$i] += $dFrac;
            }
        }

        return $bins;
    }

    /**
     * Land the pool budget exactly — the feasibility-aware boundary nudge
     * (operator exactness rule 2026-07-13; Draft-9 China/Earth rematch).
     *
     * Under the seating law every bin seats round(frac) independently, so the
     * pool's seated total is Σ round(frac_i). When drawn shares miss whole
     * multiples that sum drifts off the pool budget. This pass repairs the
     * DRAWING: per unit of drift it enumerates every real move of one member
     * from a multi-member donor to any other bin such that the two bins'
     * combined rounded seats change by exactly the needed unit — a donor
     * crossing DOWN through its .5 boundary while the receiver's round holds,
     * or a receiver crossing UP while the donor's holds — with both bins kept
     * inside the round-to-legal window (≥ floorBoundary−0.5, < giantThreshold).
     * Among feasible nudges the closest-fragment one wins (breaks are
     * purchasable, pieces stay close), and edge-less members (islands) only
     * ever move CLOSER — never as ballast.
     *
     * It cannot chase impossible targets by construction — that was the
     * failure of walking toward optimalIntegerTargets here: the optimizer is
     * indivisibility-blind and kept demanding the arithmetically-cheapest
     * correction from single-member districts (China's 7.560 province, +1
     * shipped; Earth's single-country districts, +2 shipped) while feasible
     * corrections sat one rank down the cost list. When no feasible nudge
     * exists at all (indivisible-atom pools), the drift ships honestly under
     * the undercount flag — the pin-16 fallback.
     */
    private function landPoolBudget(
        array $bins,
        array $childById,
        array $centroids,
        array $adj,
        float $quotaPop,
        int   $budget,
        int   $floor,
        int   $ceiling,
        float $giantThreshold,
        float $floorBoundary
    ): array {
        if ($quotaPop <= 0) return $bins;
        $bins = array_values(array_filter($bins, fn($b) => !empty($b)));
        $binCount      = count($bins);
        $floorFeasible = ($budget >= $binCount * $floor);
        $minSeat       = $floorFeasible ? $floor : 1;
        $guardLo       = $floorBoundary - 0.5;

        $seatOf = fn(float $f) => max($minSeat, min($ceiling, (int) round($f)));
        $fracs  = array_map(
            fn($b) => array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $b)) / $quotaPop,
            $bins
        );
        $seats = array_map($seatOf, $fracs);
        $drift = array_sum($seats) - $budget;

        for ($step = 0; $drift !== 0 && $step < 8; $step++) {
            $need = $drift > 0 ? -1 : 1;
            $best = null; // [distSq, donorIdx, jid, recvIdx, donorFrac', recvFrac']
            foreach ($bins as $di => $donor) {
                if (count($donor) < 2) continue;             // a bin is never emptied
                foreach ($donor as $jid) {
                    $mf = ((float) $childById[$jid]->population) / $quotaPop;
                    if ($mf <= 0.0) continue;
                    $df = $fracs[$di] - $mf;
                    if ($df < $guardLo) continue;            // donor stays round-to-legal
                    $dSeat = $seatOf($df);
                    foreach ($bins as $ri => $recv) {
                        if ($ri === $di) continue;
                        $rf = $fracs[$ri] + $mf;
                        if ($rf >= $giantThreshold) continue; // receiver stays round-to-legal
                        $rSeat = $seatOf($rf);
                        if (($dSeat - $seats[$di]) + ($rSeat - $seats[$ri]) !== $need) continue;
                        $dist = $this->closestApproachSq([$jid], $recv, $centroids);
                        if (empty($adj[$jid])) {
                            // island monotone rule: edge-less members only move closer
                            $rest = array_values(array_filter($donor, static fn($x) => $x !== $jid));
                            if (!empty($rest) && $dist >= $this->closestApproachSq([$jid], $rest, $centroids)) continue;
                        }
                        if ($best === null || $dist < $best[0]) {
                            $best = [$dist, $di, $jid, $ri, $df, $rf];
                        }
                    }
                }
            }
            // Exchange arm (the Draft-10 Ethiopia class): when no single move
            // can cross exactly one boundary — every candidate either flips
            // BOTH bins' rounds (net 0) or strands a sub-window remainder —
            // a pairwise exchange still can (real case: Addis Ababa out of
            // the 7.69 bin for Afar out of the 4.62 bin → donor 6.83 rounds
            // 7, receiver 5.48 holds 5, net −1). Single moves stay preferred:
            // less displacement.
            if ($best === null) {
                foreach ($bins as $di => $donor) {
                    if (count($donor) < 2) continue;
                    foreach ($donor as $jid) {
                        $mf = ((float) $childById[$jid]->population) / $quotaPop;
                        if ($mf <= 0.0 || empty($adj[$jid])) continue; // islands never swap as ballast
                        foreach ($bins as $ri => $recv) {
                            if ($ri === $di || count($recv) < 2) continue;
                            foreach ($recv as $backJid) {
                                $bf = ((float) $childById[$backJid]->population) / $quotaPop;
                                if ($bf <= 0.0 || $bf >= $mf || empty($adj[$backJid])) continue;
                                $df = $fracs[$di] - $mf + $bf;
                                $rf = $fracs[$ri] + $mf - $bf;
                                if ($df < $guardLo || $df >= $giantThreshold) continue;
                                if ($rf < $guardLo || $rf >= $giantThreshold) continue;
                                if (($seatOf($df) - $seats[$di]) + ($seatOf($rf) - $seats[$ri]) !== $need) continue;
                                $dist = $this->closestApproachSq([$jid], $recv, $centroids)
                                      + $this->closestApproachSq([$backJid], $donor, $centroids);
                                if ($best === null || $dist < $best[0]) {
                                    $best = [$dist, $di, $jid, $ri, $df, $rf, $backJid];
                                }
                            }
                        }
                    }
                }
            }
            if ($best === null) break;                        // no feasible nudge — ships under the flag
            $backJid = $best[6] ?? null;
            [, $di, $jid, $ri, $df, $rf] = $best;
            $bins[$di] = array_values(array_diff($bins[$di], [$jid]));
            $bins[$ri][] = $jid;
            if ($backJid !== null) {
                $bins[$ri] = array_values(array_diff($bins[$ri], [$backJid]));
                $bins[$di][] = $backJid;
            }
            $fracs[$di] = $df;
            $fracs[$ri] = $rf;
            $seats[$di] = $seatOf($df);
            $seats[$ri] = $seatOf($rf);
            $drift = array_sum($seats) - $budget;
        }

        return $bins;
    }

    /**
     * Land a target seat VECTOR — the canonical-mix landing pass (round 11,
     * the operator's Draft-11 spread flags: Hubei 8+6 where 7+7 exists,
     * Oromia 8+5 where a clean 7+6 is one border-zone away, Vietnam 9+9+7,
     * Russia's k=4 9+9+9+9 lost to null builders, Japan/Ethiopia/Philippines
     * archipelago mixes).
     *
     * The old equalizer (breakRebalance toward canonical parts) chased
     * arithmetic targets without checking which moves EXIST and stalled —
     * the same target-fixation disease landPoolBudget cured for budget
     * drift. This is the generalization: given per-bin integer targets
     * (rank-matched to bins by fractional, biggest to biggest, re-matched
     * after every applied step), walk only feasible member moves that
     * strictly reduce the total seat mismatch Σ|round(frac_i) − target_i|,
     * until every bin rounds to its target or no feasible step remains.
     *
     * Move preference mirrors the doctrine: tier 1 = the member has an
     * adjacency edge into the receiver (joins contiguously); tier 2 = any
     * move (breaks purchasable, closest fragment first, islands only ever
     * move closer); tier 3 = pairwise exchange (only when no single move
     * improves; islands never swap). Both bins stay inside the
     * round-to-legal window at every step, and bins are never emptied.
     * Because canonical targets sum to the pool budget, landing the vector
     * preserves budget exactness. The caller judges the result under the
     * full comparator — this pass proposes, scoreBeats disposes.
     */
    private function landSeatVector(
        array $bins,
        array $targets,
        array $childById,
        array $centroids,
        array $adj,
        float $quotaPop,
        int   $floor,
        int   $ceiling,
        float $giantThreshold,
        float $floorBoundary
    ): array {
        if ($quotaPop <= 0 || count($targets) !== count(array_filter($bins, fn($b) => !empty($b)))) {
            return $bins;
        }
        $bins    = array_values(array_filter($bins, fn($b) => !empty($b)));
        $guardLo = $floorBoundary - 0.5;
        $seatOf  = fn(float $f) => max($floor, min($ceiling, (int) round($f)));

        $fracs = array_map(
            fn($b) => array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $b)) / $quotaPop,
            $bins
        );

        // Rank-match: biggest target to biggest bin (by fractional).
        $matchTargets = function (array $fracs) use ($targets): array {
            $tSorted = $targets;
            rsort($tSorted);
            $order = array_keys($fracs);
            usort($order, fn($a, $b) => $fracs[$b] <=> $fracs[$a]);
            $out = [];
            foreach ($order as $rank => $bi) $out[$bi] = $tSorted[$rank];
            return $out;
        };

        $binHasEdgeFrom = function (string $jid, array $bin) use ($adj): bool {
            $set = array_flip($bin);
            foreach ($adj[$jid] ?? [] as $nb) {
                if (isset($set[$nb])) return true;
            }
            return false;
        };

        // TWO PHASES (the Hubei receiver-break lesson): phase 1 attempts the
        // entire landing BREAK-FREE — only tier-1 moves (member joins the
        // receiver contiguously) that pass the donor-integrity veto, so a
        // contiguous scope lands its canonical mix with zero new fragments
        // when such a path exists. Only if the mix is still unlanded does
        // phase 2 open the cross-gap moves (tier 2) and exchanges that
        // scattered pools genuinely need — and the comparator gate judges
        // whatever those cost.
        foreach ([false, true] as $allowBreaks) {
        for ($step = 0; $step < 48; $step++) {
            $tgt   = $matchTargets($fracs);
            $seats = array_map($seatOf, $fracs);
            $mismatch = 0;
            foreach ($seats as $i => $s) $mismatch += abs($s - $tgt[$i]);
            if ($mismatch === 0) break 2;

            // Improvement is LEXICOGRAPHIC: first the integer seat mismatch,
            // then the continuous distance Σ|frac − target|. The second term
            // walks plateaus (the Hubei class: counties of 0.1–0.36 fracs can
            // never cross a 0.9 gap in one hop — every early step is
            // integer-neutral and only the continuous distance shows progress
            // until the boundary finally flips).
            $cands = []; // [dMismatch, tier, distSq, di, jid, ri, df, rf, backJid|null]
            foreach ($bins as $di => $donor) {
                if (count($donor) < 2) continue;
                foreach ($donor as $jid) {
                    $mf = ((float) $childById[$jid]->population) / $quotaPop;
                    if ($mf <= 0.0) continue;
                    $df = $fracs[$di] - $mf;
                    if ($df < $guardLo) continue;
                    foreach ($bins as $ri => $recv) {
                        if ($ri === $di) continue;
                        $rf = $fracs[$ri] + $mf;
                        if ($rf >= $giantThreshold) continue;
                        $dMis = (abs($seatOf($df) - $tgt[$di]) + abs($seatOf($rf) - $tgt[$ri]))
                              - (abs($seats[$di] - $tgt[$di]) + abs($seats[$ri] - $tgt[$ri]));
                        $dCont = (abs($df - $tgt[$di]) + abs($rf - $tgt[$ri]))
                               - (abs($fracs[$di] - $tgt[$di]) + abs($fracs[$ri] - $tgt[$ri]));
                        if ($dMis > 0 || ($dMis === 0 && $dCont >= -1e-9)) continue;
                        $dist = $this->closestApproachSq([$jid], $recv, $centroids);
                        if (empty($adj[$jid])) {
                            $rest = array_values(array_filter($donor, static fn($x) => $x !== $jid));
                            if (!empty($rest) && $dist >= $this->closestApproachSq([$jid], $rest, $centroids)) continue;
                            $tier = 2;                        // islands never count as clean joins
                        } else {
                            $tier = $binHasEdgeFrom($jid, $recv) ? 1 : 2;
                        }
                        if (!$allowBreaks && $tier !== 1) continue;   // phase 1: break-free only
                        $cands[] = [$dMis, $tier, $dist, $di, $jid, $ri, $df, $rf, null];
                    }
                }
            }
            // Donor-integrity veto (the Hubei/Japan pre-flight lesson): tier 1
            // guarantees the member JOINS the receiver contiguously but says
            // nothing about what it leaves behind — pulling a border county
            // can detach a corner of the donor, and the comparator gate then
            // rightly discards the whole landing for the new break. Try
            // candidates best-first and veto any single move that increases
            // the donor's fragment count.
            usort($cands, fn($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]));
            $best = null;
            // The donor's BEFORE count is invariant across this loop — $bins is
            // not mutated until after $best is chosen — so it is computed once
            // per donor instead of once per candidate. At k=2 the 40 candidates
            // share at most two donors, which is 40 full graph traversals per
            // step collapsing to two. Pure memoization: fragmentCount reads only
            // its arguments, so the veto reaches the identical verdict.
            $beforeMemo = [];
            foreach (array_slice($cands, 0, 40) as $cand) {
                [, , , $di, $jid] = $cand;
                $before = $beforeMemo[$di] ??= $this->fragmentCount($bins[$di], $adj, $centroids, PHP_FLOAT_MAX);
                $rest   = array_values(array_filter($bins[$di], static fn($x) => $x !== $jid));
                $after  = $this->fragmentCount($rest, $adj, $centroids, PHP_FLOAT_MAX);
                if ($after > $before) continue;
                $best = $cand;
                break;
            }
            if ($best === null && $allowBreaks) {
                // Exchange fallback — no single move improves (the Draft-10
                // Ethiopia lesson, generalized): trade a bigger member out
                // for a smaller one back. Islands never swap as ballast.
                // Exchanges stay strict on the INTEGER mismatch — plateau
                // exchanges would churn without converging.
                foreach ($bins as $di => $donor) {
                    if (count($donor) < 2) continue;
                    foreach ($donor as $jid) {
                        $mf = ((float) $childById[$jid]->population) / $quotaPop;
                        if ($mf <= 0.0 || empty($adj[$jid])) continue;
                        foreach ($bins as $ri => $recv) {
                            if ($ri === $di || count($recv) < 2) continue;
                            foreach ($recv as $backJid) {
                                $bf = ((float) $childById[$backJid]->population) / $quotaPop;
                                if ($bf <= 0.0 || $bf >= $mf || empty($adj[$backJid])) continue;
                                $df = $fracs[$di] - $mf + $bf;
                                $rf = $fracs[$ri] + $mf - $bf;
                                if ($df < $guardLo || $df >= $giantThreshold) continue;
                                if ($rf < $guardLo || $rf >= $giantThreshold) continue;
                                $delta = (abs($seatOf($df) - $tgt[$di]) + abs($seatOf($rf) - $tgt[$ri]))
                                       - (abs($seats[$di] - $tgt[$di]) + abs($seats[$ri] - $tgt[$ri]));
                                if ($delta >= 0) continue;
                                $dist = $this->closestApproachSq([$jid], $recv, $centroids)
                                      + $this->closestApproachSq([$backJid], $donor, $centroids);
                                if ($best === null || $dist < $best[2]) {
                                    $best = [$delta, 3, $dist, $di, $jid, $ri, $df, $rf, $backJid];
                                }
                            }
                        }
                    }
                }
            }
            if ($best === null) break;                        // phase exhausted — next phase or ship partial
            [, , , $di, $jid, $ri, $df, $rf, $backJid] = $best;
            $bins[$di] = array_values(array_diff($bins[$di], [$jid]));
            $bins[$ri][] = $jid;
            if ($backJid !== null) {
                $bins[$ri] = array_values(array_diff($bins[$ri], [$backJid]));
                $bins[$di][] = $backJid;
            }
            $fracs[$di] = $df;
            $fracs[$ri] = $rf;
        }
        }

        // Post-landing balance polish (the Oromia lesson): crossing a rounding
        // boundary with whatever member EXISTS can leave the landed mix at
        // ugly deviations (a 1.2-frac zone where the ideal transfer was 0.6).
        // The old clean equalizer is the right FINISHER — walk balance toward
        // the landed targets with contiguity-preserving transfers only. It
        // needs fractional_seats on the children (present on every engine
        // path; unit fixtures without it skip the polish).
        $sample = null;
        foreach ($bins as $b) { if (!empty($b)) { $sample = $childById[$b[0]] ?? null; break; } }
        if ($sample !== null && property_exists($sample, 'fractional_seats')) {
            $tSorted = $targets;
            rsort($tSorted);
            $bins = $this->breakRebalance(
                $bins, $childById, $centroids, $adj, $quotaPop, array_sum($targets),
                $floor, $ceiling, $giantThreshold, $floorBoundary, $tSorted, true
            );
        }

        return $bins;
    }

    /**
     * Should the border-first fast path run on this component? (round 13, the
     * São Paulo runtime.)
     *
     * The decision is a deterministic function of the GRAPH, never of elapsed
     * time. A wall-clock gate would make a Raspberry Pi and a server draw
     * DIFFERENT maps from identical data — the ETL law's "derive from host"
     * governs SIZING (lanes, memory, chunk width); the drawing itself must stay
     * host-invariant. The projection mirrors what the search actually costs:
     * n seed sets, each an O(n + e) traversal, once per k.
     */
    private function lineFirstEngaged(array $component, array $adj, array $kCandidates, string $mode): bool
    {
        if ($mode === 'always') return true;
        if ($mode !== 'auto' && $mode !== 'shadow') return false;

        // Shadow uses the SAME gate as auto — measuring a population you would
        // never have acted on tells you nothing about the flip.
        $n      = count($component);
        $inComp = array_flip($component);
        $e      = 0;
        foreach ($component as $jid) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (isset($inComp[$nb])) $e++;
            }
        }

        return ($n * ($n + $e) * max(1, count($kCandidates)))
            >= (float) config('cga.districting.line_first_ops', 2000000);
    }

    /**
     * The best polished border-first candidate across every k (round 13).
     *
     * This is the operator's own method, run FIRST instead of twelfth-of-
     * thirty-six: sweep a line, cut the MEMBER LIST at the whole-seat
     * population boundary — never the land, so real borders are honoured for
     * free and no jurisdiction is ever split — polish, and score. It uses the
     * same generators, the same polish and the same comparator as the k-loop;
     * it only changes the ORDER in which they run.
     *
     * `clean` reports whether the result satisfies the doctrine's own prefix
     * keys (exact budget, inside the acceptability band, whole, and a seat mix
     * no worse than canonical). Reading scoreRank rather than restating 4.0 /
     * 10.0 means the gate can never drift from the ladder: re-tune the bands
     * and this follows automatically. cut_length, necks and Rg² are
     * deliberately NOT gated — those are qualities, and the premise of the
     * whole path is that a chosen border beats a grown one on exactly those.
     *
     * @return array{bins: array<int, list<string>>, score: array<string, mixed>, clean: bool, k: int}|null
     */
    private function lineFirstCandidate(
        array    $component,
        array    $childById,
        array    $adj,
        array    $centroids,
        array    $kCandidates,
        int      $compBudget,
        float    $compBinPop,
        float    $quotaPopC,
        int      $floor,
        int      $ceiling,
        float    $giantThreshold,
        float    $floorBoundary,
        callable $virtualize,
        ?float   $compEdgeCapSq
    ): ?array {
        if ($quotaPopC <= 0.0) return null;

        $bestBins = null; $bestScore = null; $bestK = null;
        $polishN  = max(1, (int) config('cga.districting.line_first_polish', 3));

        foreach ($kCandidates as $k) {
            $parts = $this->canonicalPartition($compBudget, $k, $floor, $ceiling);
            if ($parts === null) continue;
            $raw = $this->bisectionCandidates($component, $childById, $adj, $centroids, $compBudget, $k, $quotaPopC, $floor, $ceiling);
            if ($raw === []) continue;

            // Score the raw cuts (cheap — no polish) and take only the best few
            // into the expensive refinement.
            $ranked = [];
            foreach ($raw as $bins) {
                $eff      = max(count($bins), $compBudget);
                $ranked[] = [$bins, $this->scoreConfiguration($virtualize($bins), $childById, $adj, $compBinPop, $eff, $floor, $ceiling, $floorBoundary)];
            }
            usort($ranked, fn ($a, $b) => $this->scoreBeats($a[1], $b[1]) ? -1 : ($this->scoreBeats($b[1], $a[1]) ? 1 : 0));

            foreach (array_slice($ranked, 0, $polishN) as [$bins, $rawScore]) {
                $bins = $this->breakRebalance($bins, $childById, $centroids, $adj, $quotaPopC, $compBudget, $floor, $ceiling, $giantThreshold, $floorBoundary, $parts, true);
                $bins = $this->geographicSeedExpansion($component, $childById, $adj, $centroids, [], $giantThreshold, $floorBoundary, false, $compBudget, $bins, $compEdgeCapSq);
                if (!$bins) continue;
                $eff   = max(count($bins), $compBudget);
                $score = $this->scoreConfiguration($virtualize($bins), $childById, $adj, $compBinPop, $eff, $floor, $ceiling, $floorBoundary);
                if ($bestScore === null || $this->scoreBeats($score, $bestScore)) {
                    $bestBins = $bins; $bestScore = $score; $bestK = $k;
                }
            }
        }
        if ($bestBins === null || $bestScore === null) return null;

        $rank      = $this->scoreRank($bestScore);
        $live      = array_values(array_filter($bestBins, fn ($b) => !empty($b)));
        $livePart  = $this->canonicalPartition($compBudget, count($live), $floor, $ceiling);
        $clean     = $rank[0] === 0        // BUDGET EXACTNESS — drift is always wrong
            && $rank[1] === 0              // average balance inside acceptability
            && $rank[2] === 0              // worst district inside acceptability
            && $rank[3] === 0              // whole: no contiguity breaks
            && $livePart !== null
            && $bestScore['seat_spread'] <= (max($livePart) - min($livePart));

        return ['bins' => $bestBins, 'score' => $bestScore, 'clean' => $clean, 'k' => (int) $bestK];
    }

    /**
     * Border-first bisection candidates (round 12, the São Paulo snake).
     *
     * Growth-based generators leave the border as an emergent scar; this one
     * CHOOSES the border, the way the operator draws: sweep a straight line
     * across the scope in 12 directions, sort members by projection, cut the
     * prefix at the canonical population boundary (the whole-seat multiple),
     * hand stray fragments to the side that keeps each bin one piece, and
     * recurse on the halves for k > 2. Every candidate is a full k-way
     * partition; the caller polishes and scores it like any other — the
     * cut_length key recognizes a genuinely short border on sight.
     *
     * @return list<array<int, list<string>>>  up to 12 candidate bin sets
     */
    private function bisectionCandidates(
        array $component,
        array $childById,
        array $adj,
        array $centroids,
        int   $budget,
        int   $k,
        float $quotaPop,
        int   $floor,
        int   $ceiling
    ): array {
        if ($k < 2 || $quotaPop <= 0 || count($component) < $k) return [];
        $parts = $this->canonicalPartition($budget, $k, $floor, $ceiling);
        if ($parts === null) return [];

        $candidates = [];
        for ($d = 0; $d < 12; $d++) {
            $theta = M_PI * $d / 12.0;
            $ux = cos($theta);
            $uy = sin($theta);
            $bins = $this->bisectRecurse($component, $parts, $childById, $adj, $centroids, $quotaPop, $ux, $uy);
            if ($bins !== null && count($bins) === $k) {
                $candidates[] = $bins;
            }
        }
        return $candidates;
    }

    /**
     * One recursive level of the bisection: split the target seat vector into
     * two halves, cut the member list at the halves' population boundary along
     * the sweep direction, repair strays so each side is one piece per side
     * where possible, recurse. Sub-levels reuse the top-level direction — the
     * candidate DIVERSITY comes from the 12 top-level sweeps, and the
     * comparator picks among the finished partitions.
     */
    private function bisectRecurse(
        array $members,
        array $parts,
        array $childById,
        array $adj,
        array $centroids,
        float $quotaPop,
        float $ux,
        float $uy
    ): ?array {
        if (count($parts) === 1) {
            return [array_values($members)];
        }
        $k1 = intdiv(count($parts), 2);
        $partsA = array_slice($parts, 0, $k1);
        $partsB = array_slice($parts, $k1);
        $targetA = array_sum($partsA) * $quotaPop;

        // Sort by projection onto the sweep direction (stable via id).
        $sorted = array_values($members);
        usort($sorted, function ($a, $b) use ($centroids, $ux, $uy) {
            $pa = $centroids[$a]['x'] * $ux + $centroids[$a]['y'] * $uy;
            $pb = $centroids[$b]['x'] * $ux + $centroids[$b]['y'] * $uy;
            return ($pa <=> $pb) ?: strcmp($a, $b);
        });

        // Cut where the running population crosses the canonical boundary,
        // choosing the nearer of the two adjacent cuts.
        $acc = 0.0;
        $cutIdx = count($sorted) - 1;
        foreach ($sorted as $i => $jid) {
            $next = $acc + (float) $childById[$jid]->population;
            if ($next >= $targetA) {
                $cutIdx = (($targetA - $acc) < ($next - $targetA)) ? $i - 1 : $i;
                break;
            }
            $acc = $next;
        }
        if ($cutIdx < 0 || $cutIdx >= count($sorted) - 1) return null; // degenerate cut
        $sideA = array_slice($sorted, 0, $cutIdx + 1);
        $sideB = array_slice($sorted, $cutIdx + 1);

        // Stray repair: a straight cut through a jagged adjacency graph leaves
        // slivers marooned on the wrong side. Keep each side's LARGEST
        // connected piece and hand every smaller fragment to the other side —
        // one pass each way, majority piece wins.
        [$sideA, $sideB] = $this->keepLargestPiece($sideA, $sideB, $adj, $centroids);
        [$sideB, $sideA] = $this->keepLargestPiece($sideB, $sideA, $adj, $centroids);
        if (count($sideA) === 0 || count($sideB) === 0) return null;

        $left  = $this->bisectRecurse($sideA, $partsA, $childById, $adj, $centroids, $quotaPop, $ux, $uy);
        $right = $this->bisectRecurse($sideB, $partsB, $childById, $adj, $centroids, $quotaPop, $ux, $uy);
        if ($left === null || $right === null) return null;
        return array_merge($left, $right);
    }

    /** Keep $side's largest connected fragment; smaller fragments join $other. */
    private function keepLargestPiece(array $side, array $other, array $adj, array $centroids): array
    {
        if (count($side) <= 1) return [array_values($side), array_values($other)];
        $inSide = array_flip($side);
        $seen = []; $fragments = [];
        foreach ($side as $start) {
            if (isset($seen[$start])) continue;
            $frag = []; $q = [$start]; $seen[$start] = true;
            while ($q) {
                $cur = array_pop($q);
                $frag[] = $cur;
                foreach ($adj[$cur] ?? [] as $nb) {
                    if (isset($inSide[$nb]) && !isset($seen[$nb])) { $seen[$nb] = true; $q[] = $nb; }
                }
            }
            $fragments[] = $frag;
        }
        if (count($fragments) <= 1) return [array_values($side), array_values($other)];
        usort($fragments, fn($a, $b) => count($b) <=> count($a));
        $keep = $fragments[0];
        for ($f = 1, $n = count($fragments); $f < $n; $f++) {
            $other = array_merge($other, $fragments[$f]);
        }
        return [array_values($keep), array_values($other)];
    }

    /**
     * Closest approach between two bins: the minimum squared centroid distance
     * over member pairs. THE proximity metric for every attachment decision —
     * an orphan belongs to the bin whose SHORE is nearest, not whose unweighted
     * centroid is nearest (a spread-out bin's centroid sits far from its own
     * coastline: the Zhoushan-to-west-Zhejiang mis-attachment, round-2 rematch).
     */
    private function closestApproachSq(array $aJids, array $bJids, array $centroids): float
    {
        $best = PHP_FLOAT_MAX;
        foreach ($aJids as $a) {
            $ax = $centroids[$a]['x'] ?? 0.0;
            $ay = $centroids[$a]['y'] ?? 0.0;
            foreach ($bJids as $b) {
                $dx = $ax - ($centroids[$b]['x'] ?? 0.0);
                $dy = $ay - ($centroids[$b]['y'] ?? 0.0);
                $d  = $dx * $dx + $dy * $dy;
                if ($d < $best) $best = $d;
            }
        }
        return $best;
    }

    /**
     * Distance-filtered fragment count for a set of jids (connected components
     * of the induced subgraph). The "clean transfer" test compares fragment
     * counts before and after: a bin that already carries an island is
     * permanently disconnected, and demanding full connectivity would freeze
     * it out of every clean rebalance (the France/Tanzania post-attachment
     * class) — non-worsening is the honest criterion.
     */
    private function fragmentCount(array $jids, array $adj, array $centroids, float $maxEdgeDistSq): int
    {
        $n = count($jids);
        if ($n <= 1) return $n;
        $set   = array_flip($jids);
        $seen  = [];
        $frags = 0;
        foreach ($jids as $start) {
            if (isset($seen[$start])) continue;
            $frags++;
            $q  = [$start];
            $qh = 0;
            $seen[$start] = true;
            while (isset($q[$qh])) {
                $cur = $q[$qh++];
                foreach ($adj[$cur] ?? [] as $nb) {
                    if (!isset($set[$nb]) || isset($seen[$nb])) continue;
                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                    $seen[$nb] = true;
                    $q[] = $nb;
                }
            }
        }
        return $frags;
    }

    /**
     * Distance-filtered BFS connectivity test for a set of jids.
     * Same false-positive edge filter as everywhere else in the expansion pipeline.
     */
    private function connectedSet(array $jids, array $adj, array $centroids, float $maxEdgeDistSq): bool
    {
        $n = count($jids);
        if ($n <= 1) return true;
        $set = array_flip($jids);
        $vis = [$jids[0] => true];
        $q   = [$jids[0]];
        $qh  = 0;
        while (isset($q[$qh])) {
            $cur = $q[$qh++];
            foreach ($adj[$cur] ?? [] as $nb) {
                if (!isset($set[$nb]) || isset($vis[$nb])) continue;
                $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                $vis[$nb] = true;
                $q[]      = $nb;
            }
        }
        return count($vis) === $n;
    }

    /**
     * Compute the average centroid of a set of jurisdictions.
     * Used for cross-component post-repair merging (e.g. joining island bins to mainland bins).
     */
    private function binCentroid(array $jids, array $centroids): array
    {
        $x = 0.0;
        $y = 0.0;
        $n = count($jids);
        foreach ($jids as $jid) {
            $x += $centroids[$jid]['x'] ?? 0.0;
            $y += $centroids[$jid]['y'] ?? 0.0;
        }
        return [
            'x' => $n > 0 ? $x / $n : 0.0,
            'y' => $n > 0 ? $y / $n : 0.0,
        ];
    }

    /**
     * Publish granular phase progress for an in-flight mass operation.
     * The Vue side polls /mass-status every 2.5 s and displays the latest
     * snapshot. Cache::put is independent of any open DB transaction, so
     * progress is visible to other backends even mid-tx.
     *
     * Pass a partial array — keys are merged into the existing snapshot so
     * a phase change doesn't clobber unrelated fields like `completed`.
     *
     * Pass `$reset = true` when starting a fresh operation so stale fields
     * from a previous run (e.g., scope_started_at, current_scope, phase_total)
     * don't leak through the merge. Without this flag the UI shows a confusing
     * "Queued — waiting for worker" paired with "5m 12s on scope" leftover
     * from a previous Sudan run.
     */
    /**
     * Start a named step timer (2026-08-09, the São Paulo runtime). Nestable by
     * label, monotonic, and cheap enough to sit in a generator loop: a
     * re-opened label keeps the earliest start, so a timer opened per iteration
     * measures the whole span rather than the last lap.
     */
    private function stepBegin(string $label): void
    {
        if ($this->stepTimingsOn ??= (bool) config('cga.districting.step_timings', true)) {
            $this->stepOpen[$label] ??= hrtime(true);
        }
    }

    /** Close a named step timer and fold its elapsed ms into the record. */
    private function stepEnd(string $label): void
    {
        if (! isset($this->stepOpen[$label])) {
            return;
        }
        $this->stepMs[$label] = round(($this->stepMs[$label] ?? 0.0)
            + (hrtime(true) - $this->stepOpen[$label]) / 1_000_000, 1);
        $this->stepN[$label]  = ($this->stepN[$label] ?? 0) + 1;
        unset($this->stepOpen[$label]);
    }

    /**
     * A throttled liveness beat from inside the Step-8 search (2026-08-09, the
     * re-run loop). The search used to publish NOTHING between 'classified'
     * and 'binning_done' — minutes to hours of silence — so the pump could not
     * tell a working scope from a dead one, and the mapper showed a dead
     * spinner. Every generator loop now beats; publishMassProgress collapses
     * them to one write per heartbeat_seconds.
     */
    private function beat(string $legislature_id, string $label): void
    {
        $this->publishMassProgress($legislature_id, [
            'phase'       => 'binning',
            'phase_label' => $label,
        ], false, true);
    }

    public function publishMassProgress(string $legislature_id, array $patch, bool $reset = false, bool $throttled = false): void
    {
        // THROTTLE (2026-08-09, the re-run loop): Step 8 beats from inside its
        // generator loops so the pump can see the search is alive. Phase
        // transitions publish unconditionally ($throttled = false); in-search
        // beats collapse to at most one write per heartbeat_seconds, so
        // liveness costs a bounded trickle instead of becoming its own load.
        if ($throttled) {
            $gapNs = $this->beatGapNs ??= max(1, (int) config('cga.districting.heartbeat_seconds', 5)) * 1_000_000_000;
            if ($this->lastBeatNs > 0.0 && (hrtime(true) - $this->lastBeatNs) < $gapNs) {
                return;
            }
        }
        $this->lastBeatNs = (float) hrtime(true);

        // Pull-engine context (2026-07-19, mechanics only): a claimed scope
        // worker heartbeats its OWN claim rows — never the whole legislature.
        // Two scope workers can lawfully share one legislature (Earth root +
        // China in parallel); a legislature-wide touch would keep a DEAD
        // sibling's lease fresh forever (reclaim never fires), and a shared
        // mass_progress cache key would garble the two workers' phase
        // streams. The interactive mapper path below is unchanged.
        if (\App\Support\AutoscaleContext::active()) {
            try {
                if (\App\Support\AutoscaleContext::$scopeId !== null) {
                    // The timings ride the beat that was already going to be
                    // written — measurement costs zero extra round trips.
                    $scopePatch = ['updated_at' => now()];
                    if ($this->stepMs !== []) {
                        $scopePatch['step_timings'] = json_encode([
                            'ms' => $this->stepMs, 'n' => $this->stepN,
                        ]);
                    }
                    \Illuminate\Support\Facades\DB::table('autoscale_scopes')
                        ->where('id', \App\Support\AutoscaleContext::$scopeId)
                        ->where('status', 'running')
                        ->update($scopePatch);
                }
                if (\App\Support\AutoscaleContext::$itemId !== null) {
                    \Illuminate\Support\Facades\DB::table('autoscale_items')
                        ->where('id', \App\Support\AutoscaleContext::$itemId)
                        ->update(['updated_at' => now()]);
                }
                // THE LEASE IS THE LIVENESS SIGNAL (2026-08-09, the re-run
                // loop). The worker stamped last_seen_at only at claim
                // BOUNDARIES, so a worker inside one long scope looked dead
                // to the pump: its lease was pruned at 10 minutes, a
                // replacement worker was dispatched beside it, and the scope
                // was reclaimed at 30 and redrawn from scratch — forever,
                // because the redraw takes just as long. Touching the lease
                // from the same beat makes BUSY distinguishable from DEAD.
                if (\App\Support\AutoscaleContext::$workerToken !== null) {
                    \Illuminate\Support\Facades\DB::table('autoscale_worker_leases')
                        ->where('id', \App\Support\AutoscaleContext::$workerToken)
                        ->update(['last_seen_at' => now()]);
                }
            } catch (\Throwable) {
                // Transient DB hiccup — the pump's reclaim margin absorbs it.
            }

            return;
        }

        $key = "legislature.{$legislature_id}.mass_progress";
        $existing = $reset ? [] : (Cache::get($key, []) ?: []);
        if (! is_array($existing)) $existing = [];
        Cache::put($key, array_merge($existing, $patch, [
            'last_update_at' => time(),
        ], $this->stepMs !== [] ? ['step_timings' => ['ms' => $this->stepMs, 'n' => $this->stepN]] : []), 7200);

        // Liveness lease (interactive sweeps, non-law mechanics): a sweep
        // that is publishing progress is alive — extend its mass_running flag
        // so a multi-hour sweep never sheds it mid-run.
        $runKey = "legislature.{$legislature_id}.mass_running";
        if (Cache::get($runKey)) {
            Cache::put($runKey, true, 14400);
        }
    }
}
