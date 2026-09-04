<?php

namespace App\Services\Districting;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5 (5a) — the shortest-splitline AUTOSEED for a childless leaf giant.
 *
 * One call proposes a COMPLETE in-band cut plan for a giant's whole seat
 * budget: a recursive population bisection where every cut is the SHORTEST
 * balanced straight blade (the classic splitline criterion — short cuts hug
 * natural compactness, never snake). The plan is a PREVIEW — committing it
 * files one F-ELB-008 per leaf district through the engine, exactly like a
 * hand-drawn piece; nothing here touches the PROTECTED DistrictingService.
 *
 * DETERMINISM is load-bearing (design §3b): fixed angle sets, exact
 * prefix-sum offset placement (no iterative search), and total tie-break
 * orders mean the same scope + the same rasters reproduce the identical plan
 * on any mesh node. `plan_hash` is that receipt — commit recomputes the plan
 * server-side and refuses when the hash no longer matches the preview.
 *
 * All population arithmetic runs over PopulationRaster::pixelGrid (cached
 * WorldPop centroids — aggregate-only, never individual records); PostGIS is
 * consulted only for geometry (blade clipping, ST_Split, hull ratios).
 *
 * THE LEVEL LAW (operator ruling 2026-08-30): the seat budgets this
 * service cuts against derive from one level at a time — each split reads
 * the direct children's own population rows, numerator and denominator
 * alike. The raster grid supplies the spatial distribution of people
 * inside a polygon for cutting.
 */
class SubdivisionAutoseedService
{
    /**
     * The districting TEMPLATES (Phase 5 template wave). All four emit the
     * same plan contract and commit through the identical recompute→hash→
     * F-ELB-008 path; the template is part of the hashed plan identity.
     *  - shortest           : the full shortest-splitline angle sweep (default)
     *  - vertical_strips    : one fixed north–south blade per cut (θ = 90°)
     *  - horizontal_strips  : one fixed east–west blade per cut (θ = 0°)
     *  - community_cells    : the balanced power diagram (SubdivisionCellSeedService)
     *  - components         : whole detached parts as districts — NO cutting
     *                         (run-6 watch fix 2026-07-19; the LA-islands
     *                         doctrine taken to its limit for scopes whose
     *                         every straight cut strands a fragment).
     *  - mask               : the masked-blob rule (operator ruling
     *                         2026-08-29, extending his 2026-07-22 half-plane
     *                         order). The scope's parts are ONE pixel cloud;
     *                         the blade cuts EVERYTHING by sign — detached
     *                         parts included — and empty space between parts
     *                         costs nothing because nobody lives there. Built
     *                         for the big-detached-part class (a lobe worth
     *                         more seats than one lawful district: the
     *                         Chintamanpur two-part village, away lobe 9.82
     *                         quotas), where islands-ride-whole and
     *                         components both lawfully refuse. Every cut side
     *                         still honors the Art. II §8 one-fragment law
     *                         (at most one landmass cut, its territory one
     *                         chunk), checked per candidate in
     *                         splitRegionMask. Rides LAST in the registry so
     *                         the ladder only reaches it when every other
     *                         template has refused — scopes that draw today
     *                         never execute this code.
     */
    public const TEMPLATE_SHORTEST = 'shortest';

    public const TEMPLATE_VERTICAL_STRIPS = 'vertical_strips';

    public const TEMPLATE_HORIZONTAL_STRIPS = 'horizontal_strips';

    public const TEMPLATE_COMMUNITY_CELLS = 'community_cells';

    public const TEMPLATE_COMPONENTS = 'components';

    public const TEMPLATE_MASK = 'mask';

    /** The operator's box method (2026-09-02) — see SubdivisionBoxSeedService. */
    public const TEMPLATE_BOX = 'box';

    // The mask stays callable by name but leaves the ladder (operator,
    // 2026-09-02: its per-piece dissolve of thousand-part scopes was the
    // memory event, and its arithmetic disagreed with its geometry on
    // Alamudun); the box is the last-resort rung now.
    public const TEMPLATES = [
        self::TEMPLATE_SHORTEST,
        self::TEMPLATE_VERTICAL_STRIPS,
        self::TEMPLATE_HORIZONTAL_STRIPS,
        self::TEMPLATE_COMMUNITY_CELLS,
        self::TEMPLATE_COMPONENTS,
        self::TEMPLATE_BOX,
    ];

    /** Blade over-extension in degrees — giants/castelli are << 1°, so this always fully crosses. */
    private const EXTENSION_DEG = 2.0;

    /**
     * Backtracking bounds (2026-07-25). Per node: how many ranked bisections
     * a failing node may try before giving its parent the refusal. Per plan:
     * a global blade-call budget so a pathological giant degrades to the
     * honest hand-draw refusal instead of grinding. Neither bound touches a
     * scope that draws on its first bisection — the historical plan for every
     * currently-working map is byte-identical.
     */
    private const MAX_BISECTIONS_PER_NODE = 3;

    /**
     * ONE BLADE POOL PER SCOPE (2026-09-02, the Tumaco grind): the ladder
     * opens the pool once per scope (openBladePool) and every cutting
     * template rung draws from the same counter. When the pool is spent the
     * ladder goes straight to the box. A plan() call outside an open pool
     * (a single-template preview) owns a fresh counter of the same size.
     */
    private const BLADE_BUDGET_PER_SCOPE = 240;

    /**
     * WALL-CLOCK GRIND CAP (operator ruling 2026-09-03, the Tumaco grind):
     * the cutting ladder gets this many SECONDS per scope, not just a call
     * count. A coastal scope whose blades each cost seconds (Tumaco) could
     * spend the 240-call budget over ten minutes before falling to the box;
     * the time cap makes it fall in ~60 s. Env-overridable
     * (cga.districting.leaf_time_budget_seconds); the box catches it either
     * way (shortest leads, box is the general fallback — orderTemplates).
     */
    private const BLADE_TIME_BUDGET_SECONDS = 60;

    /**
     * PER-QUERY GRIND CAP (operator ruling 2026-09-03). The wall-clock cap
     * above fires only BETWEEN the leaf's PostGIS queries; a single ST_Split /
     * ST_Intersection on a raw coastal geometry can run uninterrupted for
     * minutes inside ONE query, past the wall cap (Tumaco at 60 s). A Postgres
     * statement_timeout cancels such a query at the DB level — the same
     * backend-cancel the auto-kill uses, but per query and precise — and the
     * resulting QueryException routes the scope to the box
     * (planWithFallback's RuntimeException arm). Env-overridable
     * (cga.districting.leaf_query_timeout_ms). 0 disables it.
     *
     * DISABLED (operator finding 2026-09-03, the Falklands review): it never
     * caught the raster grind (that PostGIS query ignores the cancel — proven
     * on Tumaco), and it CANCELLED the mask's legitimate long query on a
     * far-apart archipelago (Falklands, 1,198 parts), sending the scope to
     * review. The grind is handled by the grind shunt (backend terminate),
     * not by a per-query cancel. Kept at 0 as the default; the backend kill is
     * the real bound.
     */
    private const LEAF_QUERY_TIMEOUT_MS = 0;

    /**
     * How many EXTRA districts the composition ladder may add above the
     * minimum lawful count before a scope is honestly refused. Each step
     * shrinks the districts and buys slack; the first (k_min) rung is the
     * historical, most-proportional composition.
     */
    private const MAX_COMPOSITION_STEPS = 3;

    /** Remaining findBlade calls in the pool (or the standalone plan) in flight. */
    private int $bladeBudget = self::BLADE_BUDGET_PER_SCOPE;

    /** Wall-clock start of the current pool/plan blade search (microtime), or null. */
    private ?float $bladeStartedAt = null;

    /** Scope id whose blade pool is open (the ladder owns it); null = no pool. */
    private ?string $bladePoolScope = null;

    /**
     * THE SCOPE PARTS STORE (2026-09-02): a session scratch table holding
     * the scope's dissolved parts, filled once per scope on first need.
     * Every site that used to run ST_Dump(ST_UnaryUnion(ST_MakeValid(geom)))
     * live (plan entry, componentsPlan, the group loop, the leaf assembly,
     * the filing census) reads these rows instead. Session-local, dropped
     * when the scope finishes or fails (forgetScopeParts) and at session end.
     */
    public const SCOPE_PARTS_TABLE = 'cga_scope_parts';

    private const SCOPE_PARTS_DDL = 'CREATE TEMP TABLE IF NOT EXISTS cga_scope_parts
                       (scope text, idx int, g geometry, area_m2 float8, perim_m float8,
                        PRIMARY KEY (scope, idx))';

    /** Scope id of the mask-template plan in flight; null on every other template. */
    private ?string $maskScopeId = null;

    /** Candidate blade angle counts over 180°: the coarse pass, then the fine retry. */
    private const ANGLE_PASSES = [24, 48];

    /** Per-seat deviation guard on each accepted cut side (the search makes it ~exact). */
    private const MAX_PER_SEAT_DEVIATION = 0.05;

    public function __construct(
        private readonly PopulationRaster $raster,
        private readonly SubdivisionCellSeedService $cells,
        private readonly SubdivisionBoxSeedService $box,
    ) {
    }

    // ── the per-scope blade pool (the ladder owns it) ───────────────────────

    /** Open one blade pool for a scope: every rung the ladder runs shares it. */
    public function openBladePool(string $scopeId): void
    {
        $this->bladePoolScope = $scopeId;
        $this->bladeBudget = self::BLADE_BUDGET_PER_SCOPE;
        $this->bladeStartedAt = microtime(true);
        // Per-query grind cap: a single uninterruptible ST_Split /
        // ST_Intersection is cancelled by Postgres and routed to the box.
        $ms = (int) config('cga.districting.leaf_query_timeout_ms', self::LEAF_QUERY_TIMEOUT_MS);
        if ($ms > 0) {
            DB::statement('SET statement_timeout = '.$ms);
        }
    }

    /** Close the pool; the next standalone plan() owns a fresh counter. */
    public function closeBladePool(): void
    {
        $this->bladePoolScope = null;
        // Restore the session default so the timeout never leaks to the lane's
        // next scope or its write phase. A dropped session cannot be reset;
        // the next checkout is clean.
        try {
            DB::statement('RESET statement_timeout');
        } catch (\Throwable) {
        }
    }

    public function bladePoolOpenFor(string $scopeId): bool
    {
        return $this->bladePoolScope === $scopeId;
    }

    /** findBlade calls left in the pool (or in the standalone plan in flight). */
    public function bladeBudgetRemaining(): int
    {
        return max(0, $this->bladeBudget);
    }

    /**
     * The blade search is exhausted when its CALL budget is spent OR its
     * WALL-CLOCK cap has elapsed (operator ruling 2026-09-03, the Tumaco
     * grind). Either way the cutting ladder stops and the box catches the
     * scope. The time cap is what bounds a scope whose individual blades are
     * each slow, where the call count alone would grind for minutes.
     */
    private function bladeExhausted(): bool
    {
        if ($this->bladeBudget <= 0) {
            return true;
        }
        if ($this->bladeStartedAt !== null) {
            $cap = (float) config('cga.districting.leaf_time_budget_seconds', self::BLADE_TIME_BUDGET_SECONDS);
            if ($cap > 0.0 && (microtime(true) - $this->bladeStartedAt) > $cap) {
                return true;
            }
        }

        return false;
    }

    // ── the scope parts store: dissolve once per scope ──────────────────────

    /**
     * Fill the session scratch table with the scope's dissolved parts when
     * it holds none: ST_Dump(ST_UnaryUnion(ST_MakeValid(geom))) with the
     * same idx every site derived live before (COALESCE(path[1], 1)), plus
     * the geography area and perimeter the ribbon filters read. One
     * statement, atomic: a set is either complete or absent. ST_UnaryUnion
     * is deterministic, so rows left by an interrupted session name the
     * same parts a fresh dissolve would.
     */
    public static function scopePartsRef(string $scopeId): void
    {
        if (self::hasScopeParts($scopeId)) {
            return;
        }
        LeafGiantResolver::stepBegin('leaf.dissolve');
        try {
            DB::statement(
                'INSERT INTO '.self::SCOPE_PARTS_TABLE.' (scope, idx, g, area_m2, perim_m)
                 SELECT ?, COALESCE(d.path[1], 1), d.geom,
                        ST_Area(d.geom::geography), ST_Perimeter(d.geom::geography)
                   FROM jurisdictions j,
                        LATERAL ST_Dump(ST_UnaryUnion(ST_MakeValid(j.geom))) d
                  WHERE j.id = ?
                     ON CONFLICT (scope, idx) DO NOTHING',
                [$scopeId, $scopeId]
            );
        } finally {
            LeafGiantResolver::stepEnd('leaf.dissolve');
        }
    }

    /** True when the session table already holds this scope's parts. */
    public static function hasScopeParts(string $scopeId): bool
    {
        DB::statement(self::SCOPE_PARTS_DDL);

        return DB::selectOne('SELECT 1 AS x FROM '.self::SCOPE_PARTS_TABLE.' WHERE scope = ? LIMIT 1', [$scopeId]) !== null;
    }

    /**
     * Drop a scope's parts when the scope finishes or fails. Throws like any
     * statement; LeafGiantResolver::commit calls it through a guard so a
     * cleanup that fails under an aborted outer transaction never replaces
     * the scope's own diagnosis.
     */
    public static function forgetScopeParts(string $scopeId): void
    {
        DB::statement(self::SCOPE_PARTS_DDL);
        DB::delete('DELETE FROM '.self::SCOPE_PARTS_TABLE.' WHERE scope = ?', [$scopeId]);
    }

    /**
     * Compute the full deterministic cut plan for a leaf giant. $ctx is the
     * controller's giantContext (floor/ceiling/budget/quota). Read-only.
     *
     * The strip templates ride the SAME recursion as shortest: parallel
     * balanced cuts commute, so the exact prefix-sum placement needs only the
     * one fixed angle per pass. Contiguity validation still applies and can
     * legitimately fail on a non-convex giant — the error then points back at
     * 'shortest'.
     *
     * @throws RuntimeException when no in-band plan exists (with a plain reason)
     */
    public function plan(string $scopeId, array $ctx, int $year = 2023, string $template = self::TEMPLATE_SHORTEST): array
    {
        if (! in_array($template, self::TEMPLATES, true) && $template !== self::TEMPLATE_MASK) {
            throw new RuntimeException("Unknown districting template '{$template}'.");
        }
        if ($template === self::TEMPLATE_BOX) {
            return $this->box->plan($scopeId, $ctx, $year);
        }
        if ($template === self::TEMPLATE_COMMUNITY_CELLS) {
            return $this->cells->plan($scopeId, $ctx, $year);
        }
        if ($template === self::TEMPLATE_COMPONENTS) {
            return $this->componentsPlan($scopeId, $ctx, $year);
        }
        // THE BLADE POOL IS PER SCOPE (2026-09-02): under an open pool the
        // rungs share one counter and the ladder skips to the box when it
        // is spent. A plan call outside a pool (a one-template preview)
        // owns a fresh counter for itself.
        if ($this->bladePoolScope !== $scopeId) {
            $this->bladeBudget = self::BLADE_BUDGET_PER_SCOPE;
            $this->bladeStartedAt = microtime(true);
        }

        // MASK MODE (see the template docblock): the scope id rides on the
        // service so findBlade's mask splitter can run the Art. II §8
        // component census against the scope's own dissolved landmasses.
        // Reset per plan call, same as bladeBudget.
        $this->maskScopeId = $template === self::TEMPLATE_MASK ? $scopeId : null;

        // THE REGION CACHE (operator diagnosis 2026-08-31, "it should take
        // seconds"): the candidate loop used to ship the FULL region
        // GeoJSON to Postgres — megabytes for a 4,404-part French
        // Polynesia — and re-run ST_MakeValid on it for EVERY candidate
        // blade's length score and split test. The geometry never changes
        // between candidates, so it validates ONCE into a session temp
        // table and every candidate query references it by key.
        // Byte-identical results; the shipping and re-validation vanish.
        // Truncated per plan call so a long-lived worker session stays
        // bounded.
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS cga_region_cache (k text PRIMARY KEY, g geometry)');
        DB::statement('TRUNCATE cga_region_cache');

        // Cycle-2 (2026-07-19): zero-raster-coverage scopes (a geometry
        // outside its iso's tiles) fall back to the area-proportional grid —
        // same shape, deterministic from geometry + stored population. Only
        // a scope with no geometry or no population still refuses.
        $pixels = $this->raster->gridWithFallback($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }

        $S = (int) $ctx['budget'];
        $sizes = self::seatGroups($S, (int) $ctx['floor'], (int) $ctx['ceiling']);

        $region = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_MakeValid(geom), 15) AS gj FROM jurisdictions WHERE id = ?',
            [$scopeId]
        );
        if ($region === null || $region->gj === null) {
            throw new RuntimeException('The scope has no geometry.');
        }

        // NON-CONTIGUOUS GIANTS (2026-07-17 — the LA-County islands fix): a
        // giant with detached parts (LA = mainland + Santa Catalina + San
        // Clemente) can never satisfy "each blade side is a single polygon" —
        // every straight cut leaves the islands stranded, so all candidates
        // were refused. Doctrine now matches the composite side: ISLANDS RIDE
        // WHOLE with the blade side their position dictates; only
        // blade-created fragments of a single landmass are refused. The blade
        // search runs on the MOST POPULOUS part (the mainland — by people,
        // not area: only a mainland holding the divisible mass can balance);
        // each island joins the search as ONE super-pixel at its
        // representative point carrying its whole population, so balance
        // stays exact and deterministic.
        // TRUE landmasses (2026-07-20, the Penamaluru class): UnaryUnion
        // dissolves loosely-touching rings and overlapping duplicate slivers
        // BEFORE the island partition — otherwise two stored halves of one
        // landmass can ride opposite blade sides, cutting the landmass by
        // assignment (the census refuses exactly that at filing).
        // EVERY part rides (2026-07-21, the Shenkottai class): the census's
        // sub-meter degenerate-ribbon filter is deliberately NOT applied
        // here. Dropping a part from this decomposition left the "mainland"
        // a MULTIPOLYGON (Shenkottai: 1 of 24 parts survived the filter) —
        // and a multipolygon mainland can never satisfy "each blade side is
        // a single polygon", so every candidate at every angle refused.
        // Ribbons are harmless as islands: they hold no pixels (so they can
        // never lead the most-populous mainland pick) and the filing census
        // ignores them as de-minimis on the same footing as the shave.
        // INDEXED PARTS (2026-07-22, the monster-assembly rework): each part
        // carries its ST_Dump path index into the DISSOLVED decomposition, so
        // the leaf assembly can rebuild island parts from the LIVE geometry
        // by index — no island GeoJSON ever round-trips through PHP (the
        // 768M island-decode fatal) and no whole-scope ST_Intersection runs
        // against a monster collection (the pg clip kill). ST_UnaryUnion is
        // deterministic, so the same index set names the same parts in every
        // later query against this scope.
        // MASK MODE skips the per-part GeoJSON payload (2026-08-31, the
        // archipelago grind): no island partition happens there, so
        // serializing thousands of island polygons to PHP bought nothing.
        // The strict path keeps the full payload for
        // partitionPixelsByPolygon, unchanged.
        // DISSOLVE ONCE PER SCOPE (2026-09-02): the parts come from the
        // session store, filled on first need; the group loop, the leaf
        // assembly and the filing census read the same rows.
        self::scopePartsRef($scopeId);
        $compGjCol = $template === self::TEMPLATE_MASK ? "'' AS gj" : 'ST_AsGeoJSON(g, 15) AS gj';
        $comps = DB::select(
            "SELECT {$compGjCol},
                    idx,
                    area_m2 AS area,
                    ST_X(ST_PointOnSurface(g)) AS cx,
                    ST_Y(ST_PointOnSurface(g)) AS cy
               FROM ".self::SCOPE_PARTS_TABLE."
              WHERE scope = ?
              ORDER BY area_m2 DESC, ST_X(ST_PointOnSurface(g)), ST_Y(ST_PointOnSurface(g))",
            [$scopeId]
        );

        $mainlandGj = $region->gj;
        $mainPartIdx = count($comps) === 1 ? (int) $comps[0]->idx : 0;
        $islands = [];
        if ($template === self::TEMPLATE_MASK) {
            // MASK MODE — THE BOX IS WHAT GETS SLICED (operator order
            // 2026-08-31, "draw a giant mask and slice up the circle"):
            // the recursion's region is the scope's expanded ENVELOPE, a
            // five-vertex polygon. Every cut halves that convex cell in
            // pure PHP arithmetic (splitCellMask) — no coastline geometry
            // is touched per cut. Land enters exactly once, at the leaf
            // assembly, which already intersects the leaf cell against
            // ALL dissolved parts (sentinel -1). Population accounting is
            // pixel-sign throughout, unchanged.
            $mainPartIdx = -1;
            $mainlandGj = (string) DB::selectOne(
                'SELECT ST_AsGeoJSON(ST_Expand(ST_Envelope(geom), 0.05), 15) AS gj FROM jurisdictions WHERE id = ?',
                [$scopeId]
            )->gj;
        } elseif (count($comps) > 1) {
            // Partition the grid across ALL parts first (boundary-ambiguous
            // cells stay with the largest-area part, as ever) …
            $rest = $pixels;
            $partPixels = [];
            $partPops = [0 => 0.0];
            foreach (array_slice($comps, 1) as $i => $comp) {
                $poly = json_decode((string) $comp->gj, true);
                [$inside, $rest] = self::partitionPixelsByPolygon($rest, $poly);
                $pop = 0.0;
                foreach ($inside as $p) {
                    $pop += $p[2];
                }
                $partPixels[$i + 1] = $inside;
                $partPops[$i + 1] = $pop;
            }
            $partPixels[0] = $rest;
            foreach ($rest as $p) {
                $partPops[0] += $p[2];
            }

            // … then the blade MAINLAND is the part holding the MOST PEOPLE,
            // not the most area (run-6 watch fix 2026-07-19, the Chiboo Gaon
            // class: a village whose population lives on the smaller-area
            // part gave the blade search a near-empty mainland — no balanced
            // cut can exist there). Only the mainland is ever cut; every
            // other part rides whole as an island. Ties keep the
            // largest-area part (index order is area DESC — deterministic).
            $mainIdx = 0;
            foreach ($partPops as $i => $pop) {
                if ($pop > $partPops[$mainIdx]) {
                    $mainIdx = $i;
                }
            }

            $mainlandGj = (string) $comps[$mainIdx]->gj;
            $mainPartIdx = (int) $comps[$mainIdx]->idx;
            foreach ($comps as $i => $comp) {
                if ($i === $mainIdx) {
                    continue;
                }
                $islands[] = [
                    'gj'       => (string) $comp->gj,
                    'part_idx' => (int) $comp->idx,
                    'cx'       => (float) $comp->cx,
                    'cy'       => (float) $comp->cy,
                    'pop'      => $partPops[$i],
                    'pixels'   => $partPixels[$i],
                ];
            }
            $pixels = $partPixels[$mainIdx];
        }

        // The plan's quota is PIXEL-derived so the deviation figures measure the
        // search's own balance (the stored jurisdiction population can drift a
        // little from the raster sum via correction passes).
        $total = 0.0;
        foreach ($pixels as $p) {
            $total += $p[2];
        }
        foreach ($islands as $isl) {
            $total += $isl['pop'];
        }
        $quota = $total / max($S, 1);

        $cuts = [];
        $districts = [];
        $order = 0;

        // ISLANDS BREAK THE HALF-PLANE REPLAY (2026-07-22, the band re-fail
        // cohort: Naantali, Shinkami Gotou, the scattered remainders): the
        // blade assigns each island WHOLE by its representative point, but
        // measureByCutPath cascades every grid point by its OWN sign — an
        // island straddling the infinite half-plane splits sub-island and its
        // mass drifts across the cut (Naantali D1: plan 6,475 vs replay 7,054
        // → 10 seats, band-refused). The filed geometry is the assignment
        // truth, so any island carrying pixel mass poisons the whole plan's
        // chain and every leaf measures by the ray-cast oracle instead.
        // Pixel-less ribbon islands can't move mass and keep the exact replay.
        $initialCutPath = [];
        foreach ($islands as $isl) {
            if ($isl['pixels'] !== []) {
                $initialCutPath = null;
                break;
            }
        }

        // ── THE COMPOSITION LADDER (2026-07-25, the zero-slack class) ───────
        // seatGroups() takes the FEWEST lawful districts (k = ceil(S/ceiling))
        // — the most proportional shape, and the right first choice. But it is
        // also the most BRITTLE: at k_min the sizes crowd the ceiling, and a
        // pair of ceiling-sized districts has ZERO slack (an 18-seat node with
        // ceiling 9 admits exactly one sizing, 9:9). When no blade splits it,
        // no amount of re-splitting an ancestor helps — every node in that
        // composition is equally rigid — and a 152-seat scope filed NOTHING
        // (Abu Dhabi, Sharjah, Ajman, Fujairah, Transnistria, Sermersooq).
        // So on total refusal, step k up: more districts → smaller sizes →
        // real slack at every node (152 at k=19 is nineteen 8s, and a 16-seat
        // node admits 7:9, 8:8 and 9:7). Proportionality is preserved by
        // ORDER: the most proportional composition that can actually be drawn
        // wins, and a scope that draws at k_min today is untouched.
        $floorC   = (int) $ctx['floor'];
        $ceilingC = (int) $ctx['ceiling'];
        $kMin     = intdiv($S + $ceilingC - 1, $ceilingC);
        $kMax     = min(intdiv($S, max($floorC, 1)), $kMin + self::MAX_COMPOSITION_STEPS);
        $lastRefusal = null;
        $drawn = false;

        for ($k = $kMin; $k <= $kMax; $k++) {
            $candidateSizes = self::seatGroupsForK($S, $k, $floorC, $ceilingC);
            if ($candidateSizes === null) {
                continue;
            }
            $cuts = [];
            $districts = [];
            $order = 0;
            // The blade budget spans the WHOLE SCOPE: one pool across every
            // composition rung and every cutting template the ladder runs
            // (2026-09-02). A scope that exhausts the pool reaches its
            // honest refusal here, and the ladder goes straight to the box.
            if ($this->bladeExhausted()) {
                break;
            }
            try {
                $this->subdivide($scopeId, 'root', $mainlandGj, $pixels, $islands, $candidateSizes, $quota, $cuts, $districts, $order, $template, $floorC, $ceilingC, $initialCutPath, $mainPartIdx);
                $sizes = $candidateSizes;
                $drawn = true;
                break;
            } catch (NoContiguousCut $e) {
                $lastRefusal = $e;
            }
        }
        if (! $drawn) {
            throw $lastRefusal ?? new NoContiguousCut('No lawful composition could be drawn for this scope — cut it by hand.');
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return [
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'seat_budget'     => $S,
            'sizes'           => $sizes,
            'total_pop'       => (int) round($total),
            'quota'           => round($quota, 1),
            'template'        => $template,
            'cuts'            => $cuts,
            'districts'       => $districts,
            'plan_hash'       => self::planHash($scopeId, $year, $sizes, $cuts, $template),
        ];
    }

    /**
     * The COMPONENTS template (run-6 watch fix 2026-07-19): a multipart scope
     * whose every straight cut strands a fragment (two detached villages, a
     * population-heavy "island") is districted WITHOUT cutting — the detached
     * parts themselves become the districts, grouped LPT-greedy into
     * k = ceil(S/ceiling) population-balanced districts when there are more
     * parts than districts. Seats follow the drawn-district law exactly as
     * the F-ELB-008 handler will re-derive them: nearest-round of measured
     * population over the quota, sub-floor filed under the autoseed floor
     * posture, seats < 1 or > ceiling refused. Σ-seat drift is the
     * indivisible-atom case (no exact drawing exists once every cutting
     * template refused) and ships honestly — never total-forced.
     *
     * Deterministic: components ordered by area DESC then point-on-surface,
     * pixel partition by the same ray-cast the islands doctrine uses
     * (boundary-ambiguous cells stay with the largest part), LPT ties broken
     * by component index.
     *
     * @throws RuntimeException single landmass / too few parts / a group out of band
     */
    private function componentsPlan(string $scopeId, array $ctx, int $year): array
    {
        $S = (int) $ctx['budget'];
        $ceiling = (int) $ctx['ceiling'];
        $k = intdiv($S + $ceiling - 1, $ceiling);

        // TRUE landmasses — same dissolve as the splitline island partition
        // and the filing census (one bookkeeping everywhere).
        self::scopePartsRef($scopeId);
        $comps = DB::select(
            'SELECT ST_AsGeoJSON(g, 15) AS gj,
                    idx,
                    area_m2 AS area,
                    ST_X(ST_PointOnSurface(g)) AS cx,
                    ST_Y(ST_PointOnSurface(g)) AS cy
               FROM '.self::SCOPE_PARTS_TABLE.'
              WHERE scope = ?
                AND 2 * area_m2 / NULLIF(perim_m, 0) >= 0.5
              ORDER BY area_m2 DESC, ST_X(ST_PointOnSurface(g)), ST_Y(ST_PointOnSurface(g))',
            [$scopeId]
        );
        if (count($comps) < 2) {
            throw new RuntimeException('This scope is a single landmass — the components template needs detached parts.');
        }
        if (count($comps) < $k) {
            throw new RuntimeException(
                count($comps)." detached parts cannot fill {$k} whole-component districts — a cut is required."
            );
        }

        $pixels = $this->raster->gridWithFallback($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }

        // Population per part: pull each smaller part's pixels out of the
        // grid; the remainder (boundary-ambiguous cells included) stays with
        // the largest part — the exact posture of the islands partition.
        $partPops = [0 => 0.0];
        $rest = $pixels;
        foreach (array_slice($comps, 1) as $i => $comp) {
            $poly = json_decode((string) $comp->gj, true);
            [$inside, $rest] = self::partitionPixelsByPolygon($rest, $poly);
            $pop = 0.0;
            foreach ($inside as $p) {
                $pop += $p[2];
            }
            $partPops[$i + 1] = $pop;
        }
        foreach ($rest as $p) {
            $partPops[0] += $p[2];
        }
        $total = array_sum($partPops);
        if ($total <= 0.0) {
            throw new RuntimeException('No population found across the detached parts.');
        }

        // MEASUREMENT PARITY (run-6 watch fix 2026-07-19, the Maniari
        // sliver): seats gate on the SAME oracle F-ELB-008 will re-derive
        // from — measureWithFallback over each clipped district against the
        // stored-population quota the handler divides by. The ray-cast part
        // populations above remain the GROUPING heuristic only; a
        // boundary-dominated sliver the handler would measure to 0 seats now
        // refuses here at plan stage instead of dying at filing.
        // THE PLAN'S FRAME (operator law 2026-09-02): the quota is the pixel
        // sum over the budget — the sum of the children — never the stored
        // row. The handler now seats in this frame through plan_quota, so
        // the parity this line once bought by adopting the row quota is kept
        // the other way round: both sides divide by the same pixel mass.
        $quota = $total / max($S, 1);

        // LPT-greedy into k districts: heaviest part first, always onto the
        // lightest district so far; every tie breaks by index. With
        // parts == k this degenerates to one part per district.
        // EMPTY-GROUP GUARD (2026-07-22, the Batan/Sermersooq min() fatal):
        // on a LOAD TIE an EMPTY group wins before a non-empty one — with
        // many zero-pop islet parts the strict-< scan piled every one onto
        // the first zero-load group and later groups came out EMPTY (k >= 3),
        // crashing min([]) below and killing the whole sweep (ValueError
        // escapes the ladder's RuntimeException catch). Ties between two
        // empty or two non-empty groups keep the lowest index, so every
        // previously-passing grouping is unchanged; with parts >= k
        // (guarded above) every group now seats at least one part.
        $byWeight = array_keys($partPops);
        usort($byWeight, fn (int $a, int $b) => $partPops[$b] <=> $partPops[$a] ?: $a <=> $b);
        $groups = array_fill(0, $k, ['pop' => 0.0, 'members' => []]);
        foreach ($byWeight as $ci) {
            $g = 0;
            for ($j = 1; $j < $k; $j++) {
                if ($groups[$j]['pop'] < $groups[$g]['pop']
                    || ($groups[$j]['pop'] === $groups[$g]['pop']
                        && $groups[$j]['members'] === []
                        && $groups[$g]['members'] !== [])) {
                    $g = $j;
                }
            }
            $groups[$g]['pop'] += $partPops[$ci];
            $groups[$g]['members'][] = $ci;
        }
        foreach ($groups as $group) {
            if ($group['members'] === []) {
                throw new RuntimeException(
                    'A component group came out empty — too few populated parts for '.$k.' whole-component districts.'
                );
            }
        }
        usort($groups, fn (array $a, array $b) => min($a['members']) <=> min($b['members']));

        $sizes = [];
        $districts = [];
        foreach ($groups as $n => $group) {
            sort($group['members']);

            // INDEXED PARTS (2026-07-22, the monster-assembly rework): the
            // group's parts are re-collected IN SQL from the live dissolved
            // geometry by index — no per-part GeoJSON ever decodes in PHP
            // (the 768M fatal) and no whole-scope ST_Intersection runs (the
            // pg clip kill). A live part is exactly covered by its scope
            // already, so the old clip-and-shave round-trip armor is
            // unnecessary here by construction — these parts never round-trip.
            $idxs = array_map(fn (int $ci) => (int) $comps[$ci]->idx, $group['members']);
            $row = DB::selectOne(
                'WITH parts AS (
                          SELECT g, idx FROM '.self::SCOPE_PARTS_TABLE.' WHERE scope = :scope
                      ),
                      -- Per-part shave: the filed GeoJSON text still
                      -- round-trips through the parser at filing, so the
                      -- 1 mm interior margin remains the ST_CoveredBy armor.
                      leaf AS (
                          SELECT ST_CollectionExtract(ST_Collect(sg), 3) AS g
                            FROM (SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                             g, -0.00000001)), 3) AS sg
                                    FROM parts WHERE idx = ANY(:idxs::int[])) s
                           WHERE NOT ST_IsEmpty(sg)
                      )
                 SELECT ST_AsGeoJSON((SELECT g FROM leaf), 15) AS gj,
                        ST_Area((SELECT g FROM leaf))
                            / NULLIF(ST_Area(ST_ConvexHull((SELECT g FROM leaf))), 0) AS chr',
                ['scope' => $scopeId, 'idxs' => '{'.implode(',', $idxs).'}']
            );
            if ($row?->gj === null) {
                throw new RuntimeException("Component district c{$n} collapsed to an empty geometry — cut it by hand.");
            }

            $pop = (float) $this->raster->measureWithFallback($scopeId, (string) $row->gj, $year)['pop'];
            $seats = (int) round($pop / max($quota, 1e-9));
            if ($seats < 1) {
                throw new RuntimeException(
                    'A group of detached parts holds too little population for a seat — cut this scope by hand.'
                );
            }
            if ($seats > $ceiling) {
                throw new RuntimeException(
                    "A detached part holds {$seats} seats of population — above the ceiling {$ceiling}; cut it by hand."
                );
            }

            $sizes[] = $seats;
            $districts[] = [
                'path'                   => "root.c{$n}",
                'seats'                  => $seats,
                'pop'                    => (int) round($pop),
                'per_seat_deviation_pct' => round(abs($pop / $seats - $quota) / $quota * 100, 2),
                'convex_hull_ratio'      => round((float) ($row->chr ?? 0.0), 3),
                'geometry_json'          => (string) $row->gj,
            ];
        }

        return [
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'seat_budget'     => $S,
            'sizes'           => $sizes,
            'total_pop'       => (int) round($total),
            'quota'           => round($quota, 1),
            'template'        => self::TEMPLATE_COMPONENTS,
            'cuts'            => [],
            'districts'       => $districts,
            'plan_hash'       => self::planHash($scopeId, $year, $sizes, [], self::TEMPLATE_COMPONENTS),
        ];
    }

    /**
     * Snap-to-balance for a hand-placed line: keep its angle, slide it along
     * its own normal to the seat split a:b (a+b=S, both in band) whose ratio is
     * nearest the line's current population split. One angle of the plan's
     * inner loop. $blade is the extended [ax, ay, bx, by] the controller built.
     *
     * @return array{line: array, angle_deg: float, seat_split: array{int,int}, pops: array{int,int}}
     *
     * @throws RuntimeException when no single straight cut is feasible for S
     */
    public function balanceLine(string $scopeId, array $ctx, array $blade, int $year = 2023): array
    {
        $S = (int) $ctx['budget'];
        $floor = (int) $ctx['floor'];
        $ceiling = (int) $ctx['ceiling'];

        $aMin = max($floor, $S - $ceiling);
        $aMax = min($ceiling, $S - $floor);
        if ($aMin > $aMax) {
            throw new RuntimeException(
                "No single straight cut can serve {$S} seats — one cut makes exactly two districts, "
                ."which together hold ".(2 * $floor)."–".(2 * $ceiling)." seats (band [{$floor}, {$ceiling}] each). "
                .'Use the autoseed for a full multi-cut plan.'
            );
        }

        // Cycle-2 (2026-07-19): zero-raster-coverage scopes (a geometry
        // outside its iso's tiles) fall back to the area-proportional grid —
        // same shape, deterministic from geometry + stored population. Only
        // a scope with no geometry or no population still refuses.
        $pixels = $this->raster->gridWithFallback($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }
        [$total, $lon0, $lat0, $cosLat] = self::gridFrame($pixels);

        // The line's angle in the local equirectangular frame (Δlon honest in
        // meters), normalized to [0, π) — the balanced blade keeps it exactly.
        [$ax, $ay, $bx, $by] = $blade;
        $theta = atan2($by - $ay, ($bx - $ax) * $cosLat);
        if ($theta < 0) {
            $theta += M_PI;
        }
        if ($theta >= M_PI) {
            $theta -= M_PI;
        }
        $nx = -sin($theta);
        $ny = cos($theta);

        // Where the hand-placed line sits now (both endpoints project equally
        // onto the normal — average them for robustness), and its current split.
        $c0 = ((($ax - $lon0) * $cosLat) * $nx + ($ay - $lat0) * $ny
             + (($bx - $lon0) * $cosLat) * $nx + ($by - $lat0) * $ny) / 2;
        $popA0 = 0.0;
        foreach ($pixels as $p) {
            if (($p[0] - $lon0) * $cosLat * $nx + ($p[1] - $lat0) * $ny < $c0) {
                $popA0 += $p[2];
            }
        }
        $frac0 = $popA0 / $total;

        $a = $aMin;
        for ($cand = $aMin + 1; $cand <= $aMax; $cand++) {
            if (abs($cand / $S - $frac0) < abs($a / $S - $frac0)) {
                $a = $cand;
            }
        }

        $found = self::bladeOffsetSearch($pixels, $nx, $ny, $lon0, $lat0, $cosLat, $a / $S * $total);
        if ($found === null) {
            throw new RuntimeException('This line cannot be slid to a balanced cut — too little population lies across it.');
        }
        [$c, $popA, $popB] = $found;

        $region = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_MakeValid(geom), 15) AS gj FROM jurisdictions WHERE id = ?',
            [$scopeId]
        );
        // Full-crossing law here too: a hand-placed line on a multi-degree
        // scope must out-span it, or the clipped display line stops short.
        $minX = INF; $maxX = -INF; $minY = INF; $maxY = -INF;
        foreach ($pixels as [$qx, $qy]) {
            if ($qx < $minX) { $minX = $qx; }
            if ($qx > $maxX) { $maxX = $qx; }
            if ($qy < $minY) { $minY = $qy; }
            if ($qy > $maxY) { $maxY = $qy; }
        }
        $extensionDeg = max(self::EXTENSION_DEG, 2.0 * hypot($maxX - $minX, $maxY - $minY));
        $line = $region?->gj !== null
            ? $this->clippedLine($region->gj, self::bladeThrough($c, $theta, $lon0, $lat0, $cosLat, $extensionDeg), $cosLat)
            : null;
        if ($line === null) {
            throw new RuntimeException('The balanced line no longer crosses the jurisdiction — place it nearer the middle.');
        }

        return [
            'line'       => $line,
            'angle_deg'  => rad2deg($theta),
            'seat_split' => [$a, $S - $a],
            'pops'       => [(int) round($popA), (int) round($popB)],
        ];
    }

    // ── deterministic seat arithmetic (pure, no DB) ─────────────────────────

    /**
     * Group a seat budget S into in-band district sizes: k = ceil(S/ceiling)
     * districts, as even as possible, smaller sizes first. 10→[5,5], 13→[6,7],
     * 21→[7,7,7], 32→[8,8,8,8].
     *
     * @return int[] each in [floor, ceiling], summing to $S
     */
    public static function seatGroups(int $S, int $floor = 5, int $ceiling = 9): array
    {
        $k = intdiv($S + $ceiling - 1, $ceiling);
        $q = intdiv($S, $k);
        $r = $S % $k;

        // q+1 > ceiling is impossible (q = ceiling forces r = 0), so only the
        // floor can fail — a band too tight for this budget.
        if ($q < $floor) {
            throw new RuntimeException(
                "A {$S}-seat budget cannot be grouped into districts of {$floor}–{$ceiling} seats."
            );
        }

        return array_merge(array_fill(0, $k - $r, $q), array_fill(0, $r, $q + 1));
    }

    /**
     * The same even grouping for an EXPLICIT district count k — the
     * composition ladder's rung (2026-07-25). k = ceil(S/ceiling) reproduces
     * seatGroups() exactly; larger k trades a little proportionality for the
     * slack a rigid scope needs to be drawable at all. Returns null when k
     * cannot hold S in band (every district must stay in [floor, ceiling]).
     *
     * @return int[]|null each in [floor, ceiling], summing to $S
     */
    public static function seatGroupsForK(int $S, int $k, int $floor = 5, int $ceiling = 9): ?array
    {
        if ($k < 1 || $S < $k * $floor || $S > $k * $ceiling) {
            return null;
        }
        $q = intdiv($S, $k);
        $r = $S % $k;

        return array_merge(array_fill(0, $k - $r, $q), array_fill(0, $r, $q + 1));
    }

    /**
     * TIER 1 (2026-07-21) — the lawful two-split fallback order. When the
     * balanced grouping's single cut strands a fragment at every angle, a
     * 2-district node may still be cut at ANY in-band sizing (each side in
     * [floor, ceiling]). This returns every OTHER lawful low-side seat count
     * a — i.e. a ∈ [max(floor, S−ceiling), min(ceiling, S−floor)] minus the
     * balanced $balancedSeatsA already tried — ordered MOST-BALANCED-FIRST
     * (|a − S/2| asc, then a asc). The caller takes the first a whose cut is
     * contiguous, so the least-unbalanced lawful split that works is chosen —
     * honoring the autoseed doctrine's balance-over-compactness ordering.
     * Per-seat balance is not sacrificed: a 5:7 split of proportional
     * population deviates ~0 per seat (the 7-seat side rightly holds more
     * people). Pure, deterministic; pinned in AutoscalePinTest.
     *
     * @return int[] low-side seat counts to try, in order (empty if none)
     */
    public static function lawfulTwoSplitFallback(int $S, int $floor, int $ceiling, int $balancedSeatsA): array
    {
        $lo = max($floor, $S - $ceiling);
        $hi = min($ceiling, $S - $floor);
        $alts = [];
        for ($a = $lo; $a <= $hi; $a++) {
            if ($a !== $balancedSeatsA) {
                $alts[] = $a;
            }
        }
        usort($alts, fn (int $x, int $y) => abs($x - $S / 2) <=> abs($y - $S / 2) ?: $x <=> $y);

        return $alts;
    }

    /**
     * Split a sizes multiset into the two groups a cut separates, minimizing
     * |sumA − sumB|; ties prefer fewer elements in A, then the lexicographically
     * greatest sorted-descending A. k is small — full enumeration.
     *
     * @param  int[]  $sizes  at least two entries
     * @return array{int[], int[]} [A, B]
     */
    /**
     * The bisections of a sizes multiset, BEST FIRST under the same
     * comparator bisectSizes() uses — element 0 is byte-identical to
     * bisectSizes()'s answer, so every scope that draws today keeps its
     * exact historical plan. The tail feeds the backtracking search
     * (2026-07-25): when a child subtree cannot be drawn, the parent
     * re-splits rather than aborting the whole giant.
     *
     * @param  int[] $sizes at least two entries
     * @return array<array{int[], int[]}> ranked [A, B] pairs
     */
    public static function bisectionAlternatives(array $sizes): array
    {
        $sizes = array_values($sizes);
        rsort($sizes);
        $n = count($sizes);
        $total = array_sum($sizes);

        $cands = [];
        $seen = [];
        for ($mask = 1; $mask < (1 << $n) - 1; $mask++) {
            $a = [];
            $b = [];
            $sumA = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $a[] = $sizes[$i];
                    $sumA += $sizes[$i];
                } else {
                    $b[] = $sizes[$i];
                }
            }
            // A and B are interchangeable — keep one orientation per split.
            $key = implode(',', $a).'|'.implode(',', $b);
            $mirror = implode(',', $b).'|'.implode(',', $a);
            if (isset($seen[$key]) || isset($seen[$mirror])) {
                continue;
            }
            $seen[$key] = true;
            $cands[] = ['diff' => abs($total - 2 * $sumA), 'count' => count($a), 'a' => $a, 'b' => $b, 'mask' => $mask];
        }

        usort($cands, fn (array $x, array $y) => self::bisectionBeats($x, $y) ? -1 : (self::bisectionBeats($y, $x) ? 1 : 0));

        return array_map(fn (array $c) => [$c['a'], $c['b']], $cands);
    }

    public static function bisectSizes(array $sizes): array
    {
        $sizes = array_values($sizes);
        rsort($sizes);
        $n = count($sizes);
        $total = array_sum($sizes);

        $best = null;
        for ($mask = 1; $mask < (1 << $n) - 1; $mask++) {
            $a = [];
            $sumA = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $a[] = $sizes[$i];       // subsequence of a desc sort — already desc
                    $sumA += $sizes[$i];
                }
            }
            $cand = ['diff' => abs($total - 2 * $sumA), 'count' => count($a), 'a' => $a, 'mask' => $mask];
            if ($best === null || self::bisectionBeats($cand, $best)) {
                $best = $cand;
            }
        }

        $b = [];
        for ($i = 0; $i < $n; $i++) {
            if (! ($best['mask'] & (1 << $i))) {
                $b[] = $sizes[$i];
            }
        }

        return [$best['a'], $b];
    }

    private static function bisectionBeats(array $x, array $y): bool
    {
        if ($x['diff'] !== $y['diff']) {
            return $x['diff'] < $y['diff'];
        }
        if ($x['count'] !== $y['count']) {
            return $x['count'] < $y['count'];
        }
        foreach ($x['a'] as $i => $v) {
            if ($v !== $y['a'][$i]) {
                return $v > $y['a'][$i];
            }
        }

        return false;
    }

    /**
     * Exact one-angle balance: project every pixel onto the blade normal
     * (n = (nx, ny), unit, in the cosLat-scaled local frame), sort, prefix-sum,
     * and place the blade at the midpoint between the two pixels where the
     * cumulative population crosses $target. No iteration. Returns
     * [offset, popA, popB] (side A = projection < offset) or null when the
     * target cannot be crossed with both sides non-empty.
     */
    public static function bladeOffsetSearch(
        array $pixels,
        float $nx,
        float $ny,
        float $lon0,
        float $lat0,
        float $cosLat,
        float $target,
    ): ?array {
        $proj = [];
        $total = 0.0;
        foreach ($pixels as [$x, $y, $v]) {
            $proj[] = [($x - $lon0) * $cosLat * $nx + ($y - $lat0) * $ny, $v];
            $total += $v;
        }
        if ($target <= 0.0 || $target >= $total) {
            return null;
        }

        usort($proj, fn (array $p, array $q) => $p[0] <=> $q[0]);

        $n = count($proj);
        $cum = 0.0;
        for ($j = 0; $j < $n; $j++) {
            $cum += $proj[$j][1];
            if ($cum >= $target) {
                break;
            }
        }
        if ($j >= $n - 1) {
            return null;                       // all pixels would land on one side
        }

        $c = ($proj[$j][0] + $proj[$j + 1][0]) / 2;

        // Recount by the strict side predicate every consumer uses (t < c), so
        // tied projections at the boundary never desynchronize pop from geometry.
        $popA = 0.0;
        foreach ($proj as [$t, $v]) {
            if ($t < $c) {
                $popA += $v;
            }
        }

        return [$c, $popA, $total - $popA];
    }

    /**
     * Partition a pixel grid by containment in a GeoJSON Polygon/MultiPolygon
     * (an island component): returns [insidePixels, outsidePixels]. Pure PHP
     * ray casting with hole support and a bbox pre-filter — deterministic, and
     * cheap at binned-grid scale (tens of thousands of cells × a few islands).
     * A boundary-ambiguous cell defaults OUTSIDE (it stays with the mainland);
     * at cell scale that is noise against a ~500k-person quota, and every
     * filed piece is re-measured at full raster resolution by the handler.
     *
     * @param  array<int, array{0: float, 1: float, 2: float}>  $pixels
     * @return array{0: array, 1: array} [inside, outside]
     */
    public static function partitionPixelsByPolygon(array $pixels, array $geometry): array
    {
        $polys = match ($geometry['type'] ?? '') {
            'Polygon'      => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default        => [],
        };
        if ($polys === []) {
            return [[], $pixels];
        }

        // bbox pre-filter across all rings.
        $minX = INF; $minY = INF; $maxX = -INF; $maxY = -INF;
        foreach ($polys as $rings) {
            foreach ($rings[0] as [$x, $y]) {
                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
            }
        }

        $inside = [];
        $outside = [];
        foreach ($pixels as $p) {
            [$px, $py] = $p;
            $in = false;
            if ($px >= $minX && $px <= $maxX && $py >= $minY && $py <= $maxY) {
                foreach ($polys as $rings) {
                    if (! self::pointInRing($px, $py, $rings[0])) {
                        continue;
                    }
                    $inHole = false;
                    for ($r = 1; $r < count($rings); $r++) {
                        if (self::pointInRing($px, $py, $rings[$r])) {
                            $inHole = true;
                            break;
                        }
                    }
                    if (! $inHole) {
                        $in = true;
                        break;
                    }
                }
            }
            if ($in) {
                $inside[] = $p;
            } else {
                $outside[] = $p;
            }
        }

        return [$inside, $outside];
    }

    /** Standard even-odd ray cast against one linear ring ([[x,y], ...], closed or not). */
    private static function pointInRing(float $px, float $py, array $ring): bool
    {
        $in = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $py) !== ($yj > $py))
                && ($px < ($xj - $xi) * ($py - $yi) / (($yj - $yi) ?: 1e-300) + $xi)) {
                $in = ! $in;
            }
        }

        return $in;
    }

    // ── the recursive bisection tree ────────────────────────────────────────

    private function subdivide(
        string $scopeId,
        string $path,
        string $gj,
        array $pixels,
        array $islands,
        array $sizes,
        float $quota,
        array &$cuts,
        array &$districts,
        int &$order,
        string $template,
        int $floor,
        int $ceiling,
        ?array $cutPath = [],
        int $mainPartIdx = 0,
    ): void {
        if (count($sizes) === 1) {
            $pop = 0.0;
            foreach ($pixels as $p) {
                $pop += $p[2];
            }
            foreach ($islands as $isl) {
                $pop += $isl['pop'];
            }
            $seats = (int) $sizes[0];

            // A LEAF is what F-ELB-008 will file, and the handler proves exact
            // ST_CoveredBy against the giant — but the CUT piece has
            // round-tripped through decimal GeoJSON, whose serialization
            // epsilon (~1e-15°) can nudge a boundary vertex just outside.
            // Clip it against the LIVE MAINLAND PART (a blade side always
            // descends from the mainland — islands are never cut) and shave
            // 1e-8° (~1 mm) inward so the interior margin dwarfs any
            // round-trip error. Deterministic; invisible.
            //
            // INDEXED PARTS (2026-07-22): islands riding this side are
            // re-assembled IN SQL from the live dissolved geometry by part
            // index — they never round-trip through PHP (the 768M
            // island-decode fatal on 700-part monsters) and need no clip or
            // shave (a live part is exactly covered by its scope already).
            // The leaf geometry stays a RAW GeoJSON string end-to-end
            // (geometry_json); decoding a monster leaf into PHP arrays was
            // the third memory bomb.
            $islandIdxs = array_values(array_map(
                fn (array $isl) => (int) $isl['part_idx'],
                $islands,
            ));

            // MASK MODE (mainPartIdx -1): a mask cut descends from the WHOLE
            // scope, not a mainland part — clip against every dissolved part
            // collected. No islands exist, so the isl CTE receives an empty
            // index set and contributes nothing.
            $mainTarget = $mainPartIdx === -1
                ? '(SELECT ST_CollectionExtract(ST_Collect(g), 3) FROM parts)'
                : '(SELECT g FROM parts WHERE idx = :main_idx)';

            self::scopePartsRef($scopeId);
            $row = DB::selectOne(
                'WITH parts AS (
                          SELECT g, idx FROM '.self::SCOPE_PARTS_TABLE.' WHERE scope = :scope
                      ),
                      ix AS (
                          SELECT ST_CollectionExtract(ST_Intersection(
                                     ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(:gj)), 3),
                                     '.$mainTarget.'), 3) AS g
                      ),
                      -- PER-PART shave (2026-07-20, the Penamaluru merge): a
                      -- collection-level negative buffer re-nodes lattice-
                      -- adjacent parts together; shaving each dumped part on
                      -- its own can never merge parts; parts the shave
                      -- empties drop out and the null-geometry refusal
                      -- catches a fully-vanished piece.
                      shaved AS (
                          SELECT ST_CollectionExtract(ST_Collect(sg), 3) AS g
                            FROM (SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                             (ST_Dump((SELECT g FROM ix))).geom,
                                             -0.00000001)), 3) AS sg) s
                           WHERE NOT ST_IsEmpty(sg)
                      ),
                      -- Island parts get the SAME per-part shave: the filed
                      -- GeoJSON text still round-trips through the parser at
                      -- filing, so the 1 mm interior margin stays the proof
                      -- armor — and ribbon islands rightly shave to nothing
                      -- and drop out, exactly as the old collection path did.
                      isl AS (
                          SELECT ST_CollectionExtract(ST_Collect(sg), 3) AS g
                            FROM (SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                             g, -0.00000001)), 3) AS sg
                                    FROM parts WHERE idx = ANY(:idxs::int[])) s
                           WHERE NOT ST_IsEmpty(sg)
                      ),
                      leaf AS (
                          SELECT ST_CollectionExtract(ST_Collect(x.g), 3) AS g
                            FROM (SELECT (SELECT g FROM shaved) AS g
                                  UNION ALL
                                  SELECT (SELECT g FROM isl)) x
                           WHERE x.g IS NOT NULL AND NOT ST_IsEmpty(x.g)
                      )
                 SELECT ST_AsGeoJSON((SELECT g FROM leaf), 15) AS gj,
                        ST_Area((SELECT g FROM leaf))
                            / NULLIF(ST_Area(ST_ConvexHull((SELECT g FROM leaf))), 0) AS chr',
                array_merge(
                    [
                        'scope' => $scopeId,
                        'gj'    => $gj,
                        'idxs'  => '{'.implode(',', $islandIdxs).'}',
                    ],
                    $mainPartIdx === -1 ? [] : ['main_idx' => $mainPartIdx],
                )
            );
            if ($row?->gj === null) {
                throw new RuntimeException("District {$path} collapsed to an empty geometry — cut it by hand.");
            }

            $districts[] = [
                'path'                   => $path,
                'seats'                  => $seats,
                'pop'                    => (int) round($pop),
                'per_seat_deviation_pct' => round(abs($pop / $seats - $quota) / $quota * 100, 2),
                'convex_hull_ratio'      => round((float) ($row->chr ?? 0.0), 3),
                'geometry_json'          => (string) $row->gj,
                // The half-plane chain from the root to this leaf ([] = the
                // whole scope, null = an absorb level broke the chain — the
                // measurement falls back to the geometric path).
                'cut_path'               => $cutPath,
                // Island mass riding this side whole — the chain measurement
                // must add it (2026-09-02, the Kujalleq class).
                'island_pop'             => array_sum(array_map(fn (array $isl) => (float) $isl['pop'], $islands)),
            ];

            return;
        }

        // ── BACKTRACKING SEARCH (2026-07-25, the "cut it by hand" class) ────
        // The recursion used to commit to ONE bisection and let any deep
        // failure abort the entire giant: an 18-seat node has exactly one
        // lawful sizing (9:9), so when no blade split it, a 152-seat scope
        // (Abu Dhabi) produced ZERO districts even though re-splitting an
        // ancestor avoids the doomed node entirely. Now each node walks its
        // ranked bisections; element 0 is the historical balanced choice, so
        // every scope that draws today keeps its byte-identical plan, and a
        // child's NoContiguousCut rolls the shared state back and tries the
        // next split. Bounded per node and per plan — a genuinely
        // undrawable region still reaches the honest hand-draw refusal.
        $bisections = count($sizes) === 2
            ? [self::bisectSizes($sizes)]
            : array_slice(self::bisectionAlternatives($sizes), 0, self::MAX_BISECTIONS_PER_NODE);
        $lastFailure = null;

        foreach ($bisections as $bIdx => [$aSizes, $bSizes]) {
            $cutsMark      = count($cuts);
            $districtsMark = count($districts);
            $orderMark     = $order;
            try {
                $this->subdivideOnce(
                    $scopeId, $path, $gj, $pixels, $islands, $aSizes, $bSizes, $quota,
                    $cuts, $districts, $order, $template, $floor, $ceiling, $cutPath, $mainPartIdx
                );

                return;
            } catch (NoContiguousCut $e) {
                // Roll the shared plan state back to this node's entry state
                // and try the next split (the last failure is re-thrown when
                // every alternative is exhausted).
                array_splice($cuts, $cutsMark);
                array_splice($districts, $districtsMark);
                $order = $orderMark;
                $lastFailure = $e;
                if ($this->bladeExhausted()) {
                    break;
                }
            }
        }

        throw $lastFailure ?? new NoContiguousCut("No lawful split found for {$path} — cut it by hand.");
    }

    /**
     * One bisection attempt: cut this node into the given seat groups and
     * recurse. Extracted from subdivide() so the backtracking loop above can
     * retry a different grouping when a DEEP child refuses (the whole
     * subtree's state is rolled back by the caller).
     *
     * @param int[] $aSizes
     * @param int[] $bSizes
     */
    private function subdivideOnce(
        string $scopeId, string $path, string $gj, array $pixels, array $islands,
        array $aSizes, array $bSizes, float $quota, array &$cuts, array &$districts,
        int &$order, string $template, int $floor, int $ceiling, ?array $cutPath,
        ?int $mainPartIdx
    ): void {
        $seatsA = (int) array_sum($aSizes);
        $seatsB = (int) array_sum($bSizes);
        $sizes  = array_merge($aSizes, $bSizes);

        // Budget exhausted → refuse THIS node honestly rather than keep
        // grinding; the refusal unwinds to the plan's hand-draw verdict.
        if ($this->bladeExhausted()) {
            throw new NoContiguousCut(
                "The blade search budget was exhausted for this scope at {$path} — cut it by hand."
            );
        }
        $this->bladeBudget--;

        try {
            $cut = $this->findBlade($gj, $pixels, $islands, $seatsA, $seatsB, $quota, $template);
        } catch (NoContiguousCut $e) {
            // TIER 1 (operator-sanctioned 2026-07-21, the concave-residue fix):
            // the balanced grouping's single cut stranded a fragment at every
            // angle. But a 2-district node may lawfully split at ANY in-band
            // sizing — each side only needs seats in [floor, ceiling]. The
            // balanced pair is the FIRST choice (tried above, preserving the
            // deterministic map for every scope where it works), not the ONLY
            // one. On its failure, try each other lawful low-side seat count,
            // most-balanced-first, and take the first that yields a contiguous
            // cut. Per-seat balance is preserved — a 5:7 split of proportional
            // population deviates ~0 per seat, because the 7-seat side rightly
            // holds more people. Only the TERMINAL 2-way cut gets this
            // fallback; deeper (k>2) nodes keep the original throw so a giant's
            // upper-level balance is never traded away silently.
            // THE VECTOR IS LAW (operator ruling 2026-09-02, Kujalleq 9/7 on a
            // 16 head): the head fixes the seat vector in advance (16 -> 8/8)
            // and contiguity is a hope, not a gate. Trading the vector for a
            // contiguous cut (the 2026-07-21 two-split fallback: 9:7 because
            // 8:8 stranded a fragment) is therefore no longer lawful. The
            // balanced cut's refusal bubbles; the ladder falls to the box,
            // which keeps 8/8 and records the parts. lawfulTwoSplitFallback
            // stays as a pure helper (pinned) but is no longer called here.
            throw $e;
        }

        $cuts[] = [
            'order'       => $order++,
            'parent_path' => $path,
            'line'        => $cut['line'],
            'angle_deg'   => $cut['angle_deg'],
            'sides'       => [
                ['pop' => (int) round($cut['pop_a']), 'seats' => $seatsA],
                ['pop' => (int) round($cut['pop_b']), 'seats' => $seatsB],
            ],
        ];

        // Extend the half-plane chain: side 0 = the t < c side ('a'), side
        // 1 = 'b'. A frameless cut (absorb regrouping) poisons the chain —
        // its subtree measures geometrically.
        $frame = $cut['frame'] ?? null;
        $pathA = ($cutPath === null || $frame === null) ? null : array_merge($cutPath, [array_merge($frame, [0])]);
        $pathB = ($cutPath === null || $frame === null) ? null : array_merge($cutPath, [array_merge($frame, [1])]);

        $this->subdivide($scopeId, "{$path}.0", $cut['gj_a'], $cut['pixels_a'], $cut['islands_a'], $aSizes, $quota, $cuts, $districts, $order, $template, $floor, $ceiling, $pathA, $mainPartIdx);
        $this->subdivide($scopeId, "{$path}.1", $cut['gj_b'], $cut['pixels_b'], $cut['islands_b'], $bSizes, $quota, $cuts, $districts, $order, $template, $floor, $ceiling, $pathB, $mainPartIdx);
    }

    /**
     * The shortest valid balanced blade for one tree node: sweep the
     * template's angle set (theta = BLADE DIRECTION: 0° = an east–west blade,
     * 90° = a north–south blade), place each blade exactly by prefix-sum,
     * score by IN-REGION blade length (geography meters), then validate
     * winners shortest-first — ST_Split must leave each side a SINGLE polygon
     * (a U-shaped region can strand a fragment) and each side within the
     * per-seat deviation guard. The strip templates offer a single fixed
     * angle, so "shortest" degenerates to "the one candidate".
     */
    private function findBlade(string $regionGj, array $pixels, array $islands, int $seatsA, int $seatsB, float $quota, string $template): array
    {
        // Islands join the balance search as ONE super-pixel each — their
        // whole population at their representative point — so the prefix-sum
        // placement accounts for them exactly, and the SAME strict t < c
        // predicate that recounts the sides also decides which side each
        // island rides. The blade itself only ever cuts the mainland.
        $searchPixels = $pixels;
        foreach ($islands as $isl) {
            $searchPixels[] = [(float) $isl['cx'], (float) $isl['cy'], (float) $isl['pop']];
        }

        [$total, $lon0, $lat0, $cosLat] = self::gridFrame($searchPixels);
        if (count($searchPixels) < 2 || $total <= 0.0) {
            throw new RuntimeException('Too few populated pixels remain to cut this region.');
        }
        $target = $seatsA / ($seatsA + $seatsB) * $total;

        // FULL-CROSSING BLADE (2026-07-22, the arctic scrap-cut class): the
        // fixed 2° over-extension assumed sub-degree scopes — but an arctic
        // mega spans 10°+ (Avannaata, Cochrane, Northern Rockies), so the
        // blade SEGMENT ended inside the region: ST_Split clipped off the
        // corner it happened to cross while the pixel balance used the
        // infinite half-plane. The plan then filed a scrap piece against
        // the rest of the scope — "balanced" by blade-sign, all-or-nothing
        // by geometry, band-refused at filing (18.00-quota pieces). The
        // extension now out-spans the populated bbox's diagonal from any
        // interior point, so every candidate blade fully crosses.
        $minX = INF; $maxX = -INF; $minY = INF; $maxY = -INF;
        foreach ($searchPixels as [$px, $py]) {
            if ($px < $minX) { $minX = $px; }
            if ($px > $maxX) { $maxX = $px; }
            if ($py < $minY) { $minY = $py; }
            if ($py > $maxY) { $maxY = $py; }
        }
        $extensionDeg = max(self::EXTENSION_DEG, 2.0 * hypot($maxX - $minX, $maxY - $minY));

        // FRAGMENT ABSORPTION (operator-sanctioned 2026-07-21, the concave-
        // residue endgame): the strict sweep runs FIRST and EXHAUSTIVELY
        // (every pass, every candidate — "each side one polygon"), so every
        // scope a strict cut can split keeps its byte-identical historical
        // plan. Only when no strict candidate anywhere validates does the
        // absorb sweep re-walk the same candidate lists, accepting a cut
        // whose stranded fragments regroup contiguously onto the two sides
        // (splitRegionAbsorb) — with population RECOUNTED from the assigned
        // geometry and the per-seat guard re-applied, so a rescue never
        // ships an unlawful balance.
        // Mask mode never absorbs: sign assignment IS the doctrine, and the
        // frame chain stays exact because nothing regroups geometrically.
        $absorbModes = $template === self::TEMPLATE_MASK ? [false] : [false, true];
        foreach ($absorbModes as $absorb) {
            foreach (self::anglePasses($template) as $pass) {
                // WALL-CLOCK CAP inside the angle sweep (operator ruling
                // 2026-09-03): one findBlade call can grind for minutes here on
                // a coastal scope, and the between-node budget check never runs
                // until it returns. BladeBudgetExhausted unwinds PAST the
                // recursion's NoContiguousCut catches to the box.
                if ($this->bladeExhausted()) {
                    throw new BladeBudgetExhausted('Leaf blade search hit its wall-clock cap mid-angle-sweep.');
                }
                $candidates = [];
                foreach ($pass as $i => $angleDeg) {
                    // Per-ANGLE cap: bladeOffsetSearch below is pure-PHP pixel
                    // work with no DB query, so neither statement_timeout nor a
                    // pass-level check can bound a heavy angle sweep (Tumaco).
                    if ($this->bladeExhausted()) {
                        throw new BladeBudgetExhausted('Leaf blade search hit its wall-clock cap mid-angle.');
                    }
                    $theta = deg2rad($angleDeg);
                    $nx = -sin($theta);
                    $ny = cos($theta);

                    $found = self::bladeOffsetSearch($searchPixels, $nx, $ny, $lon0, $lat0, $cosLat, $target);
                    if ($found === null) {
                        continue;
                    }
                    [$c, $popA, $popB] = $found;
                    if (abs($popA / $seatsA - $quota) / $quota > self::MAX_PER_SEAT_DEVIATION
                     || abs($popB / $seatsB - $quota) / $quota > self::MAX_PER_SEAT_DEVIATION) {
                        continue;
                    }

                    $candidates[] = [
                        'i' => $i, 'angle_deg' => $angleDeg,
                        'nx' => $nx, 'ny' => $ny, 'c' => $c,
                        'pop_a' => $popA, 'pop_b' => $popB,
                        'blade' => self::bladeThrough($c, $theta, $lon0, $lat0, $cosLat, $extensionDeg),
                    ];
                }

                // MASK MODE scores nothing: every blade halves the convex
                // cell, so the first pixel-balanced candidate wins. The
                // blade's land-length is meaningless against a box.
                if ($template === self::TEMPLATE_MASK) {
                    foreach ($candidates as &$cand) {
                        $cand['len'] = (float) $cand['i'];
                    }
                    unset($cand);
                } else {
                // In-region blade length summed over bbox-hit PARTS (gist
                // index) — identical to intersecting the whole region
                // (parts partition it) without touching untouched islands.
                $regionKey = $this->regionPartsRef($regionGj);
                foreach ($candidates as &$cand) {
                    // The PostGIS scoring query below is the per-candidate cost
                    // that grinds; check the cap before each (operator ruling
                    // 2026-09-03).
                    if ($this->bladeExhausted()) {
                        throw new BladeBudgetExhausted('Leaf blade search hit its wall-clock cap mid-scoring.');
                    }
                    $seqs = $this->bladeCrossedSeqs($regionKey, $cand, $lon0, $lat0, $cosLat);
                    if ($seqs === []) {
                        $cand['len'] = 0.0;
                        continue;
                    }
                    $row = DB::selectOne(
                        'WITH blade AS (SELECT ST_SetSRID(ST_MakeLine(ST_MakePoint(?, ?), ST_MakePoint(?, ?)), 4326) AS l)
                         SELECT COALESCE(sum(ST_Length(ST_Intersection((SELECT l FROM blade), p.g)::geography)), 0) AS len
                           FROM cga_region_parts p
                          WHERE p.k = ? AND p.seq IN ('.implode(',', $seqs).')',
                        [...$cand['blade'], $regionKey]
                    );
                    $cand['len'] = (float) ($row->len ?? 0.0);
                }
                unset($cand);
                }
                usort($candidates, fn (array $a, array $b) => $a['len'] <=> $b['len'] ?: $a['i'] <=> $b['i']);

                foreach ($candidates as $cand) {
                    if ($template === self::TEMPLATE_MASK) {
                        $sides = $this->splitCellMask($regionGj, $cand, $lon0, $lat0, $cosLat);
                    } else {
                        $sides = $absorb
                            ? $this->splitRegionAbsorb($regionGj, $cand, $lon0, $lat0, $cosLat)
                            : $this->splitRegion($regionGj, $cand, $lon0, $lat0, $cosLat);
                    }
                    if ($sides === null) {
                        continue;
                    }
                    $line = $this->clippedLine($regionGj, $cand['blade'], $cosLat);
                    if ($line === null) {
                        continue;
                    }

                    if ($absorb) {
                        // An absorbed fragment sits geometrically on one blade
                        // side but WAS ASSIGNED to the other — the pixel split
                        // must follow the assigned geometry, not the blade
                        // sign, or population and territory desynchronize.
                        [$pixelsA, $pixelsB] = self::partitionPixelsByPolygon($pixels, json_decode($sides['a'], true));
                    } else {
                        $pixelsA = [];
                        $pixelsB = [];
                        foreach ($pixels as $p) {
                            if (($p[0] - $lon0) * $cosLat * $cand['nx'] + ($p[1] - $lat0) * $cand['ny'] < $cand['c']) {
                                $pixelsA[] = $p;
                            } else {
                                $pixelsB[] = $p;
                            }
                        }
                    }

                    // Each island rides WHOLE by its super-pixel's side — the same
                    // strict predicate as the recount, so population and geometry
                    // can never disagree about where an island went.
                    $islandsA = [];
                    $islandsB = [];
                    foreach ($islands as $isl) {
                        $t = ((float) $isl['cx'] - $lon0) * $cosLat * $cand['nx'] + ((float) $isl['cy'] - $lat0) * $cand['ny'];
                        if ($t < $cand['c']) {
                            $islandsA[] = $isl;
                        } else {
                            $islandsB[] = $isl;
                        }
                    }

                    $popA = $cand['pop_a'];
                    $popB = $cand['pop_b'];
                    if ($absorb) {
                        // Recount by assignment and re-apply the guard: the
                        // prefix-sum balance no longer describes the sides once
                        // a fragment crossed the blade.
                        $popA = 0.0;
                        foreach ($pixelsA as $p) {
                            $popA += $p[2];
                        }
                        foreach ($islandsA as $isl) {
                            $popA += $isl['pop'];
                        }
                        $popB = 0.0;
                        foreach ($pixelsB as $p) {
                            $popB += $p[2];
                        }
                        foreach ($islandsB as $isl) {
                            $popB += $isl['pop'];
                        }
                        if ($popA <= 0.0 || $popB <= 0.0
                         || abs($popA / $seatsA - $quota) / $quota > self::MAX_PER_SEAT_DEVIATION
                         || abs($popB / $seatsB - $quota) / $quota > self::MAX_PER_SEAT_DEVIATION) {
                            continue;
                        }
                    }

                    return [
                        'angle_deg' => $cand['angle_deg'],
                        'line'      => $line,
                        'pop_a'     => $popA,
                        'pop_b'     => $popB,
                        'gj_a'      => $sides['a'],
                        'gj_b'      => $sides['b'],
                        'pixels_a'  => $pixelsA,
                        'pixels_b'  => $pixelsB,
                        'islands_a' => $islandsA,
                        'islands_b' => $islandsB,
                        // THE CUT'S MACHINE FRAME (operator ruling 2026-07-22,
                        // "a simpler strategy to cut the line"): the exact
                        // half-plane parameters this blade balanced with. A
                        // filed piece carries its frame chain so the F-ELB-008
                        // measurement can re-apply the planner's own total
                        // per-point rule — pure arithmetic, no geometry SQL.
                        // Absorb-accepted cuts are NOT a half-plane (fragments
                        // regrouped geometrically) — no frame, geometric
                        // measurement stays their path.
                        'frame'     => $absorb
                            ? null
                            : [$cand['nx'], $cand['ny'], $cand['c'], $lon0, $lat0, $cosLat],
                    ];
                }
            }
        }

        // NoContiguousCut (2026-07-21) — the GEOMETRY-exhausted sentinel: the
        // sweep ran to completion and no angle yields a contiguous in-band cut
        // for THIS seat ratio. The Tier-1 fallback catches exactly this (and
        // not a transient DB QueryException) to retry other lawful ratios.
        throw new NoContiguousCut(
            "No contiguous in-band straight cut found for a {$seatsA}:{$seatsB} split of this region "
            .match ($template) {
                self::TEMPLATE_VERTICAL_STRIPS   => "(the vertical_strips template's single 90° blade tried) — try the 'shortest' template or cut it by hand.",
                self::TEMPLATE_HORIZONTAL_STRIPS => "(the horizontal_strips template's single 0° blade tried) — try the 'shortest' template or cut it by hand.",
                default                          => '(48 candidate angles tried) — cut it by hand.',
            }
        );
    }

    /**
     * The candidate blade-angle passes for a template. Shortest sweeps a
     * coarse then a fine fan; each strip template is ONE fixed angle (the
     * prefix-sum placement is exact, so no retry pass exists to offer).
     *
     * @return float[][] passes of blade-direction angles in degrees
     */
    private static function anglePasses(string $template): array
    {
        return match ($template) {
            self::TEMPLATE_VERTICAL_STRIPS   => [[90.0]],
            self::TEMPLATE_HORIZONTAL_STRIPS => [[0.0]],
            default => array_map(
                fn (int $steps) => array_map(fn (int $i) => 180.0 * $i / $steps, range(0, $steps - 1)),
                self::ANGLE_PASSES,
            ),
        };
    }

    /**
     * ST_Split the region by the blade and union the pieces by side of the
     * blade (normal projection of each piece's point-on-surface vs the offset).
     * Returns ['a' => geojson, 'b' => geojson] only when BOTH sides exist and
     * each is a single polygon; null otherwise (try the next candidate).
     */
    private function splitRegion(string $regionGj, array $cand, float $lon0, float $lat0, float $cosLat): ?array
    {
        [$ax, $ay, $bx, $by] = $cand['blade'];

        // LOSERS DIE ON THE COUNT (operator recycle 2026-08-31, the New
        // Caledonia 65-second candidate): "each side one polygon" is a
        // PIECE COUNT — one piece per side IS one polygon per side, no
        // union needed, and a failing candidate (three bay fragments on a
        // ragged coast) refuses without ever unioning or serializing its
        // multi-megabyte sides. Acceptance is byte-identical: the winning
        // sides each hold exactly one piece, and that piece is what the
        // union used to return.
        $rows = DB::select(
            "WITH r AS (SELECT g FROM cga_region_cache WHERE k = :gj),
                  blade AS (SELECT ST_SetSRID(ST_MakeLine(ST_MakePoint(:ax, :ay), ST_MakePoint(:bx, :by)), 4326) AS l),
                  parts AS (
                      SELECT (ST_Dump(ST_Split((SELECT g FROM r), (SELECT l FROM blade)))).geom AS piece
                  ),
                  sided AS (
                      SELECT piece,
                             CASE WHEN :nx * ((ST_X(ST_PointOnSurface(piece)) - :lon0) * :coslat)
                                     + :ny * (ST_Y(ST_PointOnSurface(piece)) - :lat0) < :c
                                  THEN 'a' ELSE 'b' END AS side
                        FROM parts
                  )
             SELECT side,
                    count(*) AS parts,
                    CASE WHEN count(*) = 1 THEN max(ST_AsGeoJSON(piece, 15)) END AS gj
               FROM sided GROUP BY side ORDER BY side",
            [
                'gj' => $this->regionRef($regionGj), 'ax' => $ax, 'ay' => $ay, 'bx' => $bx, 'by' => $by,
                'nx' => $cand['nx'], 'lon0' => $lon0, 'coslat' => $cosLat,
                'ny' => $cand['ny'], 'lat0' => $lat0, 'c' => $cand['c'],
            ]
        );

        if (count($rows) !== 2) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            if ((int) $row->parts !== 1 || $row->gj === null) {
                return null;                   // a stranded fragment — side not contiguous
            }
            $out[$row->side] = $row->gj;
        }

        return isset($out['a'], $out['b']) ? $out : null;
    }

    /**
     * THE CELL SPLITTER (operator order 2026-08-31, "slice up the circle,
     * then remove the mask"). Mask-mode regions are convex cells descended
     * from the scope's envelope, so a blade cut is a convex polygon halved
     * by a line — pure PHP arithmetic (Sutherland–Hodgman against each
     * half-plane), no database, microseconds. Population stays pixel-sign
     * as ever; land is only touched at the leaf assembly's single
     * intersection per district, and legality is proven there by the
     * filing handler's own census.
     *
     * @return array{a: string, b: string}|null
     */
    private function splitCellMask(string $regionGj, array $cand, float $lon0, float $lat0, float $cosLat): ?array
    {
        $poly = json_decode($regionGj, true);
        $ring = $poly['coordinates'][0] ?? null;
        if (($poly['type'] ?? '') !== 'Polygon' || ! is_array($ring) || count($ring) < 4) {
            return null;
        }

        $f = fn (array $p): float => $cand['nx'] * (($p[0] - $lon0) * $cosLat) + $cand['ny'] * ($p[1] - $lat0) - $cand['c'];

        $clip = function (bool $keepNegative) use ($ring, $f): ?array {
            $out = [];
            $n = count($ring) - 1;             // ring is closed; walk open edges
            for ($i = 0; $i < $n; $i++) {
                $cur = $ring[$i];
                $nxt = $ring[$i + 1];
                $fc = $f($cur);
                $fn = $f($nxt);
                $curIn = $keepNegative ? $fc < 0 : $fc >= 0;
                $nxtIn = $keepNegative ? $fn < 0 : $fn >= 0;
                if ($curIn) {
                    $out[] = $cur;
                }
                if ($curIn !== $nxtIn && abs($fn - $fc) > 1e-30) {
                    $t = $fc / ($fc - $fn);
                    $out[] = [$cur[0] + $t * ($nxt[0] - $cur[0]), $cur[1] + $t * ($nxt[1] - $cur[1])];
                }
            }
            if (count($out) < 3) {
                return null;
            }
            $out[] = $out[0];

            return $out;
        };

        $a = $clip(true);
        $b = $clip(false);
        if ($a === null || $b === null) {
            return null;
        }

        return [
            'a' => json_encode(['type' => 'Polygon', 'coordinates' => [$a]]),
            'b' => json_encode(['type' => 'Polygon', 'coordinates' => [$b]]),
        ];
    }

    /**
     * THE MASK SPLITTER (operator ruling 2026-08-29 — the masked-blob rule).
     * Split every part of the region by the blade and assign each piece by
     * the SAME half-plane sign predicate the pixel balance used. Any part
     * count per side is accepted: the empty space between detached parts is
     * unpopulated, so a line crossing it costs nothing, and a side made of
     * several pieces is the mask doctrine working as intended.
     *
     * The one bound is constitutional, not geometric: each side must pass
     * the Art. II §8 one-fragment census (at most ONE of the scope's
     * landmasses cut, its cut territory ONE connected chunk) — checked with
     * the FILING HANDLER'S OWN arithmetic (ManualDistrictDraw::partCensus),
     * so a cut accepted here can never be refused at filing on fragment
     * grounds. A candidate whose line severs two detached landmasses at
     * once, or chops one landmass into two chunks on the same side, returns
     * null and the sweep tries the next candidate.
     *
     * @return array{a: string, b: string}|null
     */
    private function splitRegionMask(string $regionGj, array $cand, float $lon0, float $lat0, float $cosLat): ?array
    {
        [$ax, $ay, $bx, $by] = $cand['blade'];

        // TOUCH ONLY WHAT THE BLADE TOUCHES (operator model 2026-08-31,
        // "draw a circle and use the mask — it should take seconds"): the
        // parts are dumped ONCE per region into the session parts cache
        // with their point-on-surface precomputed. A candidate splits only
        // the parts its line actually crosses; every untouched island
        // takes its side from the precomputed point — arithmetic, not
        // geometry. Identical sides to splitting everything (ST_Split of a
        // missed part returns the part; its point-on-surface is constant).
        if ($this->maskScopeId !== null) {
            $this->scopeLandmassesRef($this->maskScopeId);
        } else {
            DB::statement('CREATE TEMP TABLE IF NOT EXISTS cga_scope_landmasses
                           (scope text, id serial, g geometry, area float8)');
        }
        $pk = $this->regionPartsRef($regionGj);
        $crossSeqs = $this->bladeCrossedSeqs($pk, $cand, $lon0, $lat0, $cosLat);
        $seqList = $crossSeqs === [] ? '-1' : implode(',', $crossSeqs);
        $rows = DB::select(
            "WITH blade AS (SELECT ST_SetSRID(ST_MakeLine(ST_MakePoint(:ax, :ay), ST_MakePoint(:bx, :by)), 4326) AS l),
                  pieces AS (
                      SELECT d.geom AS piece,
                             ST_X(ST_PointOnSurface(d.geom)) AS px,
                             ST_Y(ST_PointOnSurface(d.geom)) AS py,
                             p.lm, ST_Area(d.geom) AS area
                        FROM cga_region_parts p,
                             LATERAL ST_Dump(ST_Split(p.g, (SELECT l FROM blade))) d
                       WHERE p.k = :k1 AND p.seq IN ({$seqList})
                         AND ST_Intersects(p.g, (SELECT l FROM blade))
                      UNION ALL
                      SELECT p.g, p.px, p.py, p.lm, p.area
                        FROM cga_region_parts p
                       WHERE p.k = :k2 AND (p.seq NOT IN ({$seqList})
                         OR NOT ST_Intersects(p.g, (SELECT l FROM blade)))
                  ),
                  sided AS (
                      SELECT piece, lm, area,
                             CASE WHEN :nx * ((px - :lon0) * :coslat)
                                     + :ny * (py - :lat0) < :c
                                  THEN 'a' ELSE 'b' END AS side
                        FROM pieces
                  ),
                  -- Art. II §8 census by AGGREGATION (the handler's own
                  -- thresholds): pieces partition the landmasses, so a
                  -- landmass's area on a side is the SUM of its pieces'
                  -- areas — no geometry intersections per candidate at all.
                  lmtot AS (
                      SELECT lm, side, area,
                             sum(area) OVER (PARTITION BY lm, side) AS sarea
                        FROM sided WHERE lm >= 0
                  ),
                  lmside AS (
                      SELECT lm, side, max(sarea) AS sarea,
                             count(*) FILTER (WHERE area > 0.01 * sarea) AS big_pieces
                        FROM lmtot GROUP BY lm, side
                  ),
                  cutlms AS (
                      SELECT l.side, l.lm, l.big_pieces
                        FROM lmside l
                        JOIN cga_scope_landmasses s ON s.scope = :scope AND s.id = l.lm
                       WHERE l.sarea > 0.02 * s.area AND l.sarea < 0.98 * s.area
                  ),
                  agg AS (
                      SELECT side, ST_CollectionExtract(ST_Collect(piece), 3) AS g
                        FROM sided GROUP BY side
                  )
             SELECT a.side, ST_AsGeoJSON(a.g, 15) AS gj,
                    ST_IsEmpty(a.g) AS empty,
                    (SELECT count(*) FROM cutlms c WHERE c.side = a.side) AS cut_components,
                    (SELECT COALESCE(sum(c.big_pieces), 0) FROM cutlms c WHERE c.side = a.side) AS fragment_pieces
               FROM agg a ORDER BY a.side",
            [
                'ax' => $ax, 'ay' => $ay, 'bx' => $bx, 'by' => $by,
                'k1' => $pk, 'k2' => $pk,
                'nx' => $cand['nx'], 'lon0' => $lon0, 'coslat' => $cosLat,
                'ny' => $cand['ny'], 'lat0' => $lat0, 'c' => $cand['c'],
                'scope' => $this->maskScopeId ?? '',
            ]
        );

        if (count($rows) !== 2) {
            return null;                       // every piece fell on one side
        }
        $out = [];
        foreach ($rows as $row) {
            if ($row->gj === null) {
                return null;
            }
            // The census columns rode along in the same statement — the
            // handler's own thresholds. The filing handler still runs the
            // real partCensus, so this gate can only refuse early, never
            // admit what filing would refuse.
            if ($this->maskScopeId !== null
                && ((bool) $row->empty
                    || (int) $row->cut_components > 1
                    || (int) $row->fragment_pieces > 1)) {
                return null;
            }
            $out[(string) $row->side] = (string) $row->gj;
        }
        if (! isset($out['a'], $out['b'])) {
            return null;
        }

        return $out;
    }

    /**
     * Dump a cached region's parts once per session, point-on-surface
     * precomputed — the mask splitter's per-candidate work then touches
     * only blade-crossed parts.
     */
    /** Session-once DDL for the region-parts scratch tables (leaf audit 2026-09-02). */
    private bool $regionPartsDdlDone = false;

    /** Landmass count per scope, so a one-landmass scope skips the per-part ST_Covers. */
    private array $landmassCount = [];

    private function regionPartsRef(string $regionGj): string
    {
        $k = $this->regionRef($regionGj);
        // THE REGION-PARTS BUILD (leaf audit 2026-09-02: 46% of lane SQL time
        // on one-second leaf scopes). Three cuts, same rows out:
        //  1. the scratch-table DDL runs once per session, not per candidate
        //     (regionPartsRef is called inside the blade-candidate loop);
        //  2. ST_PointOnSurface runs once per part, not twice (X and Y);
        //  3. a scope with a single dissolved landmass skips the per-part
        //     ST_Covers: every part belongs to that landmass.
        if (! $this->regionPartsDdlDone) {
            DB::statement('CREATE TEMP TABLE IF NOT EXISTS cga_scope_landmasses
                           (scope text, id serial, g geometry, area float8)');
            DB::statement('CREATE TEMP TABLE IF NOT EXISTS cga_region_parts
                           (k text, seq int, g geometry, px float8, py float8,
                            lm int, area float8, PRIMARY KEY (k, seq))');
            DB::statement('CREATE INDEX IF NOT EXISTS cga_region_parts_gix
                           ON cga_region_parts USING gist (g)');
            $this->regionPartsDdlDone = true;
        }
        if (DB::selectOne('SELECT 1 AS x FROM cga_region_parts WHERE k = ? LIMIT 1', [$k]) === null) {
            $scope = $this->maskScopeId ?? '';
            if (! array_key_exists($scope, $this->landmassCount)) {
                $this->landmassCount[$scope] = (int) DB::scalar(
                    'SELECT count(*) FROM cga_scope_landmasses WHERE scope = ?', [$scope]
                );
            }
            // Each part carries its landmass id (which dissolved landmass
            // its point-on-surface falls in; -1 = ribbon-class residue the
            // census ignores) and its planar area — the per-candidate
            // census then needs NO geometry at all for untouched parts.
            $lmExpr = $this->landmassCount[$scope] === 1
                ? '(SELECT lm.id FROM cga_scope_landmasses lm WHERE lm.scope = ? LIMIT 1)'
                : '(SELECT lm.id FROM cga_scope_landmasses lm
                     WHERE lm.scope = ?
                       AND lm.g && d.geom
                       AND ST_Covers(lm.g, pp.p)
                     LIMIT 1)';
            DB::statement(
                "INSERT INTO cga_region_parts (k, seq, g, px, py, lm, area)
                 SELECT c.k, d.path[1], d.geom,
                        ST_X(pp.p), ST_Y(pp.p),
                        COALESCE({$lmExpr}, -1),
                        ST_Area(d.geom)
                   FROM cga_region_cache c,
                        LATERAL ST_Dump(ST_Multi(c.g)) d,
                        LATERAL (SELECT ST_PointOnSurface(d.geom) AS p) pp
                  WHERE c.k = ?
                     ON CONFLICT (k, seq) DO NOTHING",
                [$scope, $k]
            );
        }

        if (! isset($this->partBoxes[$k])) {
            $this->partBoxes[$k] = DB::select(
                'SELECT seq, ST_XMin(g) AS x0, ST_YMin(g) AS y0, ST_XMax(g) AS x1, ST_YMax(g) AS y1
                   FROM cga_region_parts WHERE k = ?', [$k]
            );
        }

        return $k;
    }

    /** Per-region part bboxes, loaded once — the PHP-side blade prune. */
    private array $partBoxes = [];

    /**
     * Part seqs whose bbox the candidate's infinite line crosses — pure
     * arithmetic (4 corner signs). The blade over-extends past the whole
     * region (full-crossing law), so its own bbox prunes nothing; THIS
     * prune is what keeps a candidate's split work proportional to the
     * parts the line touches, not the archipelago's size.
     *
     * @return list<int>
     */
    private function bladeCrossedSeqs(string $k, array $cand, float $lon0, float $lat0, float $cosLat): array
    {
        $out = [];
        foreach ($this->partBoxes[$k] ?? [] as $b) {
            $min = INF;
            $max = -INF;
            foreach ([[$b->x0, $b->y0], [$b->x1, $b->y0], [$b->x0, $b->y1], [$b->x1, $b->y1]] as [$x, $y]) {
                $t = $cand['nx'] * (((float) $x - $lon0) * $cosLat) + $cand['ny'] * ((float) $y - $lat0) - $cand['c'];
                if ($t < $min) { $min = $t; }
                if ($t > $max) { $max = $t; }
            }
            if ($min <= 0.0 && $max >= 0.0) {
                $out[] = (int) $b->seq;
            }
        }

        return $out;
    }

    /**
     * Dissolve + ribbon-filter a scope\'s landmasses once per session —
     * byte-identical to partCensus\'s gcomps CTE, paid once instead of per
     * candidate side.
     */
    private function scopeLandmassesRef(string $scopeId): void
    {
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS cga_scope_landmasses
                       (scope text, id serial, g geometry, area float8)');
        if (DB::selectOne('SELECT 1 AS x FROM cga_scope_landmasses WHERE scope = ? LIMIT 1', [$scopeId]) === null) {
            self::scopePartsRef($scopeId);
            DB::statement(
                'INSERT INTO cga_scope_landmasses (scope, g, area)
                 SELECT ?, g, ST_Area(g) FROM '.self::SCOPE_PARTS_TABLE.'
                  WHERE scope = ?
                    AND 2 * area_m2 / NULLIF(perim_m, 0) >= 0.5
                  ORDER BY idx',
                [$scopeId, $scopeId]
            );
            DB::statement('CREATE INDEX IF NOT EXISTS cga_scope_landmasses_gix
                           ON cga_scope_landmasses USING gist (g)');
        }
    }

    /**
     * FRAGMENT ABSORPTION (2026-07-21, the concave-residue endgame): split by
     * the blade like splitRegion, but accept a cut that leaves MORE than two
     * pieces — the concave class where every straight blade at every angle
     * strands a fragment, which Tier 1's ratio retries cannot rescue. Each
     * side is seeded with its largest-area piece (by the same point-on-surface
     * side predicate); the remaining pieces then fix-point-attach, a piece
     * joining a side only when their union stays ONE polygon. The OPPOSITE
     * geometric side is tried first — a stranded fragment usually connects
     * only across the blade (that is what stranded it). Deterministic: pieces
     * ordered area DESC then point-on-surface x/y, fixed side order, fixed
     * sweep order. Probed live on the run-6 review cohort: 67/71 concave
     * items regroup with recounted per-seat deviation ≤ 4.9%.
     *
     * The caller RECOUNTS population from the assigned geometry and re-applies
     * the per-seat deviation guard — this method only settles territory.
     * Both returned sides are single polygons partitioning the region, so the
     * downstream leaf clip+shave and the Art. II §8 one-fragment census hold
     * by construction.
     *
     * @return array{a: string, b: string}|null
     */
    private function splitRegionAbsorb(string $regionGj, array $cand, float $lon0, float $lat0, float $cosLat): ?array
    {
        [$ax, $ay, $bx, $by] = $cand['blade'];

        $rows = DB::select(
            "WITH r AS (SELECT g FROM cga_region_cache WHERE k = :gj),
                  blade AS (SELECT ST_SetSRID(ST_MakeLine(ST_MakePoint(:ax, :ay), ST_MakePoint(:bx, :by)), 4326) AS l),
                  parts AS (
                      SELECT (ST_Dump(ST_Split((SELECT g FROM r), (SELECT l FROM blade)))).geom AS piece
                  )
             SELECT ST_AsGeoJSON(piece, 15) AS gj,
                    CASE WHEN :nx * ((ST_X(ST_PointOnSurface(piece)) - :lon0) * :coslat)
                            + :ny * (ST_Y(ST_PointOnSurface(piece)) - :lat0) < :c
                         THEN 'a' ELSE 'b' END AS side
               FROM parts
              ORDER BY ST_Area(piece) DESC, ST_X(ST_PointOnSurface(piece)), ST_Y(ST_PointOnSurface(piece))",
            [
                'gj' => $this->regionRef($regionGj), 'ax' => $ax, 'ay' => $ay, 'bx' => $bx, 'by' => $by,
                'nx' => $cand['nx'], 'lon0' => $lon0, 'coslat' => $cosLat,
                'ny' => $cand['ny'], 'lat0' => $lat0, 'c' => $cand['c'],
            ]
        );

        if (count($rows) < 3) {
            return null;                       // ≤ 2 pieces is the strict case — nothing to absorb
        }

        $sideGj = ['a' => null, 'b' => null];
        $pending = [];
        foreach ($rows as $row) {
            $s = (string) $row->side;
            if ($sideGj[$s] === null) {
                $sideGj[$s] = (string) $row->gj;   // largest piece per side seeds it (area DESC order)
            } else {
                $pending[] = ['gj' => (string) $row->gj, 'side' => $s];
            }
        }
        if ($sideGj['a'] === null || $sideGj['b'] === null) {
            return null;                       // every piece fell on one side — nothing to balance
        }

        // Fix-point attach: a piece can become attachable only after a
        // neighbor lands, so the sweep repeats until it stalls. A stall with
        // pieces unattached refuses the candidate (the next blade tries).
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $k => $piece) {
                foreach ($piece['side'] === 'a' ? ['b', 'a'] : ['a', 'b'] as $s) {
                    $row = DB::selectOne(
                        'SELECT ST_AsGeoJSON(u, 15) AS gj, ST_NumGeometries(ST_Multi(u)) AS parts
                           FROM (SELECT ST_UnaryUnion(ST_Collect(
                                     ST_MakeValid(ST_GeomFromGeoJSON(?)),
                                     ST_MakeValid(ST_GeomFromGeoJSON(?)))) AS u) t',
                        [$sideGj[$s], $piece['gj']]
                    );
                    if ((int) ($row->parts ?? 0) === 1 && $row->gj !== null) {
                        $sideGj[$s] = (string) $row->gj;
                        unset($pending[$k]);
                        $progress = true;
                        break;
                    }
                }
            }
            if (! $progress) {
                return null;
            }
        }

        return $sideGj;
    }

    /**
     * The 2-point display line: the extremes of the blade clipped to the
     * region, along the blade's own direction. Null when the blade misses.
     *
     * @return array{type: string, coordinates: array}|null
     */
    /**
     * Validate a region into the session cache once; return its key. Every
     * candidate-loop query references the cached geometry by this key
     * instead of shipping and re-validating the GeoJSON (the archipelago
     * ten-minute grind, 2026-08-31). The PK probe is a single indexed
     * lookup, so a cache hit costs microseconds even after a reconnect
     * empties the temp table.
     */
    private function regionRef(string $regionGj): string
    {
        $k = md5($regionGj);
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS cga_region_cache (k text PRIMARY KEY, g geometry)');
        if (DB::selectOne('SELECT 1 AS x FROM cga_region_cache WHERE k = ?', [$k]) === null) {
            DB::statement(
                'INSERT INTO cga_region_cache (k, g)
                 VALUES (?, ST_MakeValid(ST_GeomFromGeoJSON(?)))
                     ON CONFLICT (k) DO NOTHING',
                [$k, $regionGj]
            );
        }

        return $k;
    }

    private function clippedLine(string $regionGj, array $blade, float $cosLat): ?array
    {
        [$ax, $ay, $bx, $by] = $blade;
        $row = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_Intersection(
                 ST_SetSRID(ST_MakeLine(ST_MakePoint(?, ?), ST_MakePoint(?, ?)), 4326),
                 (SELECT g FROM cga_region_cache WHERE k = ?)
             ), 15) AS gj',
            [$ax, $ay, $bx, $by, $this->regionRef($regionGj)]
        );

        $coords = self::collectCoordinates($row?->gj !== null ? json_decode($row->gj, true) : null);
        if (count($coords) < 2) {
            return null;
        }

        $dux = ($bx - $ax) * $cosLat;
        $duy = $by - $ay;
        $lo = null;
        $hi = null;
        $loT = INF;
        $hiT = -INF;
        foreach ($coords as $pt) {
            $t = ($pt[0] - $ax) * $cosLat * $dux + ($pt[1] - $ay) * $duy;
            if ($t < $loT) {
                $loT = $t;
                $lo = $pt;
            }
            if ($t > $hiT) {
                $hiT = $t;
                $hi = $pt;
            }
        }

        return [
            'type'        => 'LineString',
            'coordinates' => [[(float) $lo[0], (float) $lo[1]], [(float) $hi[0], (float) $hi[1]]],
        ];
    }

    /**
     * The extended blade [ax, ay, bx, by] through offset $c along the normal of
     * angle $theta, mapped back from the scaled local frame to lon/lat. The
     * extension must OUT-SPAN the region (full-crossing law, 2026-07-22) —
     * callers pass a bbox-derived value; the 2° floor keeps sub-degree scopes
     * byte-identical to every plan shipped before the arctic band.
     */
    private static function bladeThrough(float $c, float $theta, float $lon0, float $lat0, float $cosLat, float $extensionDeg = self::EXTENSION_DEG): array
    {
        $px = $lon0 + ($c * -sin($theta)) / $cosLat;
        $py = $lat0 + $c * cos($theta);

        $dx = cos($theta) / $cosLat;           // undo the equirectangular scale
        $dy = sin($theta);
        $len = sqrt($dx * $dx + $dy * $dy);
        $ux = $dx / $len;
        $uy = $dy / $len;

        return [
            $px - $ux * $extensionDeg, $py - $uy * $extensionDeg,
            $px + $ux * $extensionDeg, $py + $uy * $extensionDeg,
        ];
    }

    /**
     * The scaled local frame every consumer (splitline AND the cell seeder)
     * projects into: equirectangular about the pixel centroid, Δlon scaled by
     * cos(meanLat) so distances are honest in meters.
     *
     * @return array{float, float, float, float} [totalPop, meanLon, meanLat, cos(meanLat)]
     */
    public static function gridFrame(array $pixels): array
    {
        $total = 0.0;
        $sumLon = 0.0;
        $sumLat = 0.0;
        foreach ($pixels as [$x, $y, $v]) {
            $total += $v;
            $sumLon += $x;
            $sumLat += $y;
        }
        $n = max(count($pixels), 1);
        $lat0 = $sumLat / $n;

        return [$total, $sumLon / $n, $lat0, max(cos(deg2rad($lat0)), 1e-9)];
    }

    /** All coordinate pairs of any GeoJSON geometry (collections included). */
    private static function collectCoordinates(?array $geom): array
    {
        if ($geom === null) {
            return [];
        }
        if (isset($geom['geometries'])) {
            $out = [];
            foreach ($geom['geometries'] as $g) {
                $out = array_merge($out, self::collectCoordinates($g));
            }

            return $out;
        }

        return self::flattenCoordinates($geom['coordinates'] ?? []);
    }

    private static function flattenCoordinates(array $coords): array
    {
        if ($coords === []) {
            return [];
        }
        if (is_numeric($coords[0] ?? null)) {
            return [$coords];
        }
        $out = [];
        foreach ($coords as $c) {
            $out = array_merge($out, self::flattenCoordinates($c));
        }

        return $out;
    }

    /**
     * The determinism receipt: sha256 over the canonical plan identity —
     * scope, raster year, TEMPLATE, seat grouping, and every cut line's
     * coordinates rounded to 7 decimals (~1 cm). Commit recomputes and
     * compares, so a template swap between preview and commit fails closed.
     */
    private static function planHash(string $scopeId, int $year, array $sizes, array $cuts, string $template): string
    {
        $lines = array_map(
            fn (array $cut) => array_map(
                fn (array $pt) => [round($pt[0], 7), round($pt[1], 7)],
                $cut['line']['coordinates'],
            ),
            $cuts,
        );

        return hash('sha256', json_encode([
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'sizes'           => array_values($sizes),
            'template'        => $template,
            'lines'           => $lines,
        ]));
    }
}
