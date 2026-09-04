<?php

namespace App\Services\Districting;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\User;
use App\Services\ConstitutionalDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mixed-autoseed unification (2026-07-17) — the shared home for the two
 * childless-leaf-giant primitives that used to live only behind HTTP in
 * SubdivisionDrawController:
 *
 *  - context()  : IS this scope a childless leaf giant, and with what seat
 *                 budget? One detector for the HTTP draw/probe/autoseed
 *                 endpoints AND the mass-reseed sweep, so the two flows can
 *                 never disagree about what needs a line split.
 *  - commit()   : the plan-recompute + per-leaf F-ELB-008 filing loop —
 *                 Request-free, so a Horizon worker (the method-aware mass
 *                 sweep) can file the same audited districts the mapper's
 *                 Accept button does. It runs inside withScopeService():
 *                 ONE DistrictingService for the length of one leaf scope,
 *                 shared by every piece's context() and the filed-scope
 *                 beat, forgotten when the scope finishes or fails.
 *
 * NOT part of the PROTECTED DistrictingService: this is orchestration around
 * the existing SubdivisionAutoseedService plan and the existing F-ELB-008
 * engine handler. Every hard gate (R-08 authorship, geometry proofs, seat
 * bands) still lives in the handler; nothing here bypasses it.
 */
class LeafGiantResolver
{
    public function __construct(
        private readonly SubdivisionAutoseedService $autoseed,
        private readonly ConstitutionalEngine $engine,
    ) {
    }

    /**
     * THE share base for flat proportional entitlements under a legislature
     * root: the SUM of the root's children's populations (Kentucky ruling
     * 2026-07-18, "one base, everywhere"; THE ONE-MASS LAW, operator ruling
     * 2026-08-30: population at every layer = the recursive descendant
     * children-sum, a leaf supplying its own attributed figure, and that
     * one mass supplies every numerator and every denominator). Every
     * classification and the binding cascade read this same base, so every
     * frame agrees on every scope's seats. A leaf-rooted legislature takes
     * the leaf's own attributed figure as its mass (the one-mass leaf rule).
     */
    public static function shareBase(string $rootJurisdictionId): int
    {
        // THE STATIC MEMO IS GONE (the Bayern 70/71 verdict, 2026-08-30):
        // it lived for the whole PHP process, spanning every claim, map and
        // legislature a worker touched, and a denominator pinned before a
        // population write survived to later draws — a fraction-of-a-percent
        // stale base flips a .49 to a .50 and mints a phantom seat. One
        // indexed SUM per ask is the honest price of a truthful base.
        // THE LEVEL LAW (operator ruling 2026-08-30, his walk): the share
        // base is the sum of the DIRECT children's own rows — the same one
        // level every split reads. A leaf-rooted legislature takes the
        // leaf's own row.
        $sum = (int) DB::table('jurisdictions')
            ->where('parent_id', $rootJurisdictionId)
            ->whereNull('deleted_at')
            ->sum('population');
        if ($sum <= 0) {
            $sum = (int) DB::table('jurisdictions')
                ->where('id', $rootJurisdictionId)
                ->value('population');
        }

        return max($sum, 1);
    }

    /**
     * Resolve a giant scope's seat budget + local quota, or null if the scope
     * is not a childless leaf giant. Mirrors the F-ELB-008 handler's
     * resolution. (Moved verbatim from SubdivisionDrawController::giantContext
     * — the controller now delegates here.)
     *
     * @return array{floor:int, ceiling:int, budget:int, quota:float}|null
     */
    public function context(string $legislatureId, string $scopeId): ?array
    {
        $leg = DB::table('legislatures')->where('id', $legislatureId)->whereNull('deleted_at')->first();
        if ($leg === null) {
            return null;
        }
        $giant = DB::table('jurisdictions')->where('id', $scopeId)->whereNull('deleted_at')->first();
        if ($giant === null || $giant->geom === null) {
            return null;
        }

        // Cycle-2 leaf law (operator ruling 2026-07-19): a scope that IS this
        // legislature's own jurisdiction is the ROOT — never a leaf giant in
        // the parent-frame sense. A CHILDLESS root whose lawful size exceeds
        // the district ceiling line-splits ITSELF: budget = its OWN
        // type_a_seats (the sizing law's number — leaves follow the same law
        // as parents, floor-clamp only), never a parent-frame share. An
        // in-band childless root stays null (the at-large singles shape); a
        // child-bearing root stays null (the composite path). Because the
        // mapper panel, the F-ELB-008 handler, and the mass sweep all read
        // THIS one detector, they inherit root-leaf support together (the
        // one-frame law's no-disagreement guarantee).
        if ($scopeId === (string) $leg->jurisdiction_id) {
            // INERT CHILD LAYER (operator ruling 2026-07-23): a child-bearing
            // root whose children sum to ZERO stored population while it
            // holds people is EFFECTIVELY CHILDLESS — the layer cannot
            // apportion anything (border-off-raster phantoms) and the root
            // line-splits itself over its own geometry and raster.
            $rootChildren = (int) DB::table('jurisdictions')
                ->where('parent_id', $scopeId)->whereNull('deleted_at')->count();
            $inertLayer = $rootChildren > 0
                && app(\App\Services\DistrictingService::class)->childLayerIsInert($scopeId);
            if ($rootChildren > 0 && ! $inertLayer) {
                return null;
            }
            $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);
            $budget  = (int) $leg->type_a_seats;
            // A genuinely childless IN-BAND root stays null (the at-large
            // singles shape; the mapper must not offer line-split UI for a
            // village). An INERT-collapsed in-band root proceeds instead:
            // ceil(budget/ceiling) = 1 drawn district covering the whole
            // root = the at-large shape, through the same audited path.
            if ($budget <= $ceiling && ! $inertLayer) {
                return null;
            }

            return [
                'floor'   => ConstitutionalDefaults::floor($leg->jurisdiction_id),
                'ceiling' => $ceiling,
                'budget'  => $budget,
                'quota'   => ((int) $giant->population) / max($budget, 1),
            ];
        }

        if ($giant->parent_id === null) {
            return null; // a planet root under a foreign legislature — never a leaf giant
        }

        $childCount = (int) DB::table('jurisdictions')->where('parent_id', $scopeId)->whereNull('deleted_at')->count();
        if ($childCount > 0
            && ! app(\App\Services\DistrictingService::class)->childLayerIsInert($scopeId)) {
            return null;   // a live child layer composites; an INERT one line-splits (ruling 2026-07-23)
        }

        // ONE-FRAME LAW (2026-07-19): gianthood + budget come from the
        // PARENT scope's local frame — the cascade's own classification
        // (giantChildrenForScope) — never the root flat share, which went
        // blind to any child dominating a sub-scope (Saint-Pierre/Réunion).
        // For direct children of the root the two frames coincide, so every
        // previously working case is unchanged.
        $giants = app(\App\Services\DistrictingService::class)
            ->giantChildrenForScope((string) $giant->parent_id, $legislatureId);
        if (! isset($giants[$scopeId])) {
            return null;
        }

        $floor   = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);
        $budget  = (int) $giants[$scopeId];

        return [
            'floor'   => $floor,
            'ceiling' => $ceiling,
            'budget'  => $budget,
            'quota'   => ((int) $giant->population) / max($budget, 1),
        ];
    }

    /**
     * Recompute the deterministic line-split plan for a leaf giant and file
     * one F-ELB-008 per leaf district as $actor — the exact loop the mapper's
     * Accept button runs, factored out of SubdivisionDrawController so the
     * mass sweep can call it without a Request.
     *
     * Transaction contract: the CALLER owns the transaction (the sweep's
     * per-scope beginTransaction/commit; the HTTP path's DB::transaction).
     * This method never opens its own, and never flushes the revealed cache
     * — callers own both, exactly as before the extraction.
     *
     * $expectedPlanHash: the HTTP path passes the previewed hash and gets the
     * "Plan changed" refusal on mismatch; the sweep passes null (there is no
     * client echo to distrust — the recompute IS the plan).
     *
     * @return array{districts_created:int, replaced:int, district_ids:array<int,string>}
     *
     * @throws PlanRefused on plan failure or hash mismatch (the 422 class)
     * @throws \App\Domain\Engine\ConstitutionalViolation on any handler gate
     */
    public function commit(
        string $legislatureId,
        string $scopeId,
        string $mapId,
        ?User $actor,
        array $ctx,
        int $year,
        string $template,
        bool $replace,
        ?string $expectedPlanHash = null,
    ): array {
        // The recompute runs under the SAME template as any preview — the
        // template is inside the hashed identity, so a swapped or omitted
        // template fails the hash_equals below (fails closed). Plan failures
        // are re-thrown as PlanRefused so the HTTP path can 422 EXACTLY these
        // (matching its pre-extraction behavior) while any other
        // RuntimeException from the filing loop still bubbles as a 500.
        //
        // FALLBACK LADDER (operator sanction 2026-07-18, "ladder first,
        // manual for the residue"): only when NO client hash is being
        // verified (the sweep path — the recompute IS the plan) a refused
        // template falls through the remaining templates in registry order
        // before giving up to the review list. A previewed commit
        // ($expectedPlanHash set) never ladders — silently swapping the
        // template a human previewed would betray the hash contract.
        // ONE DistrictingService PER LEAF SCOPE (2026-09-02): for the length
        // of this call every app(DistrictingService::class) resolution (each
        // piece's context() through ManualDistrictDraw, the filed-scope
        // beat) is the same instance, so the parent giant cascade runs once
        // per scope instead of once per piece. The instance is forgotten
        // when the scope finishes or fails (withScopeService).
        return self::withScopeService(fn () => $this->commitWithinScope(
            $legislatureId, $scopeId, $mapId, $actor, $ctx, $year, $template, $replace, $expectedPlanHash,
        ));
    }

    /** The commit body, run inside one scope service (withScopeService). */
    private function commitWithinScope(
        string $legislatureId,
        string $scopeId,
        string $mapId,
        ?User $actor,
        array $ctx,
        int $year,
        string $template,
        bool $replace,
        ?string $expectedPlanHash,
    ): array {
        $jurisdictionId = (string) DB::table('legislatures')->where('id', $legislatureId)->value('jurisdiction_id');

        // THE SCOPE'S DISSOLVED PARTS (2026-09-02) are filled once on first
        // need (SubdivisionAutoseedService::scopePartsRef) and dropped when
        // the scope finishes or fails, whichever path ran.
        try {
            // PREVIEWED PATH (hash set): one template, no ladder, violations
            // bubble raw — byte-identical to the pre-extraction behavior.
            if ($expectedPlanHash !== null) {
                $planned = $this->planWithFallback($scopeId, $ctx, $year, $template, false);
                if (! hash_equals($planned['plan']['plan_hash'], $expectedPlanHash)) {
                    throw new PlanRefused('Plan changed — run the preview again.');
                }
                $this->assertScopeOwned();
                $replaced = $replace ? $this->retireDrawnDistricts($legislatureId, $scopeId, $mapId) : 0;
                $ids = $this->fileDistricts($legislatureId, $jurisdictionId, $scopeId, $mapId, $actor, $planned['plan'], $year, false);

                return [
                    'districts_created' => count($ids),
                    'replaced'          => $replaced,
                    'district_ids'      => $ids,
                    'template'          => $planned['template'],
                    'template_fallback' => false,
                ];
            }

            return $this->commitLadder($legislatureId, $jurisdictionId, $scopeId, $mapId, $actor, $ctx, $year, $template, $replace);
        } finally {
            self::forgetScopePartsQuietly($scopeId);
        }
    }

    /**
     * Drop the scope's stored parts without replacing a pending diagnosis.
     * Callers with an outer per-scope transaction (leafScopeTx=true, the
     * HTTP DB::transaction) reach this finally with that transaction
     * ABORTED after a failed statement: every further statement answers
     * 25P02, and an exception thrown from a finally block would replace the
     * primary template's refusal (first failure wins). The rollback drops
     * the rows anyway, so a failed cleanup is logged and the diagnosis
     * stands. The sweep path (leafScopeTx=false) cleans up normally.
     */
    private static function forgetScopePartsQuietly(string $scopeId): void
    {
        try {
            SubdivisionAutoseedService::forgetScopeParts($scopeId);
        } catch (\Throwable $e) {
            Log::debug('scope parts cleanup skipped (the pending diagnosis stands)', [
                'scope' => $scopeId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ONE DistrictingService PER LEAF SCOPE (2026-09-02). Registers one
     * instance in the container for the length of $fn and forgets it after,
     * whichever way $fn ends. Inside, every app(DistrictingService::class)
     * resolution is that instance: the pieces of one scope share the giant
     * cascade memos and the step-timing collector, and the filed-scope beat
     * publishes that collector. Outside, resolution builds a fresh instance,
     * as it always did.
     *
     * The lifetime is ONE SCOPE by design. A process-lifetime instance
     * carried one scope's step timings onto the next scope's ledger row
     * (the controller beats scope_start before commit) and pinned the
     * population memos across population writes, the class the Bayern
     * 70/71 verdict removed from shareBase. A container owner that already
     * shares the service (a registered instance, a shared binding) keeps its
     * own lifetime; this method then registers and forgets nothing.
     */
    public static function withScopeService(callable $fn): mixed
    {
        $container = app();
        $abstract  = \App\Services\DistrictingService::class;
        if ($container->isShared($abstract)) {
            return $fn();
        }
        $svc = $container->make($abstract);
        $container->instance($abstract, $svc);
        try {
            return $fn();
        } finally {
            if ($container->make($abstract) === $svc) {
                $container->forgetInstance($abstract);
            }
        }
    }

    /**
     * AUTOSEED PATH (the recompute IS the plan): the FULL ladder —
     * "ladder first, manual for the residue" survives FILING-stage refusals
     * too, not just planning-stage ones. A template whose planned piece
     * violates a filing gate retires its partial set and the next template
     * tries; only when every template has refused at either stage does the
     * scope land on the review list.
     *
     * ONE BLADE POOL PER SCOPE (2026-09-02, the Tumaco grind): the cutting
     * rungs share one counter opened here; when it is spent every remaining
     * rung but the box is skipped. Each rung is timed as 'leaf.<template>'
     * into the composites' step collector; a filed scope beats once so the
     * timings reach the ledger row before the sweep marks it done.
     *
     * @return array{districts_created:int, replaced:int, district_ids:array<int,string>, template:string, template_fallback:bool}
     */
    private function commitLadder(
        string $legislatureId,
        string $jurisdictionId,
        string $scopeId,
        string $mapId,
        ?User $actor,
        array $ctx,
        int $year,
        string $template,
        bool $replace,
    ): array {
        $order = $this->ladderOrder($scopeId, $template);
        $first = null;
        $this->autoseed->openBladePool($scopeId);
        try {
            foreach ($order as $i => $tpl) {
                if ($tpl !== SubdivisionAutoseedService::TEMPLATE_BOX
                    && $this->autoseed->bladeBudgetRemaining() <= 0) {
                    Log::info('ladder blade pool exhausted, skipping to box', ['scope' => $scopeId, 'template' => $tpl]);
                    continue;
                }
                try {
                    self::stepBegin("leaf.{$tpl}");
                    $plan = $this->autoseed->plan($scopeId, $ctx, $year, $tpl);
                } catch (PlanRefused $e) {
                    // FIRST failure wins (2026-07-21): the ladder's PRIMARY
                    // template holds the scope's real diagnosis. Later rungs'
                    // refusals previously overwrote it, so every review row
                    // displayed the LAST cutter's noise ("Community cell N clips
                    // to M disjoint parts") over the shortest template's honest
                    // NoContiguousCut — an entire review class was misdiagnosed
                    // behind that mask during run 6.
                    Log::info('ladder template refused (plan)', ['scope' => $scopeId, 'template' => $tpl, 'error' => $e->getMessage()]);
                    $first ??= $e;
                    continue;
                } catch (RuntimeException $e) {
                    Log::info('ladder template refused (runtime)', ['scope' => $scopeId, 'template' => $tpl, 'error' => $e->getMessage()]);
                    $first ??= new PlanRefused($e->getMessage(), previous: $e);
                    continue;
                } finally {
                    self::stepEnd("leaf.{$tpl}");
                }

                // Each attempt owns the scope's drawn set: retire whatever is
                // live (the caller's $replace on the first attempt; a previous
                // attempt's partial filings on later ones).
                $this->assertScopeOwned();
                $replaced = ($replace || $i > 0) ? $this->retireDrawnDistricts($legislatureId, $scopeId, $mapId) : 0;

                try {
                    $ids = $this->fileDistricts($legislatureId, $jurisdictionId, $scopeId, $mapId, $actor, $plan, $year, true);
                    $this->beatFiled($legislatureId, $tpl, count($ids));

                    return [
                        'districts_created' => count($ids),
                        'replaced'          => $replaced,
                        'district_ids'      => $ids,
                        'template'          => $tpl,
                        'template_fallback' => $i > 0,
                    ];
                } catch (\App\Domain\Engine\ConstitutionalViolation $e) {
                    // A filing gate refused this template's pieces — clean up the
                    // partial set and ladder on. The chain honestly records the
                    // attempted filings; the retirement is the same plan-editing
                    // posture as the delete endpoint. First failure wins here
                    // too: the primary template's filing refusal (a band-gate
                    // number, a fragment count) is the diagnosis worth keeping.
                    $this->assertScopeOwned();
                    $this->retireDrawnDistricts($legislatureId, $scopeId, $mapId);
                    $first ??= new PlanRefused($e->getMessage(), previous: $e);
                }
            }

            throw $first ?? new PlanRefused('No districting template produced a filable plan.');
        } finally {
            $this->autoseed->closeBladePool();
        }
    }

    /** File one F-ELB-008 per planned district; returns the district ids. */
    private function fileDistricts(
        string $legislatureId,
        string $jurisdictionId,
        string $scopeId,
        string $mapId,
        ?User $actor,
        array $plan,
        int $year,
        bool $floorPosture,
    ): array {
        $ids = [];
        foreach ($plan['districts'] as $d) {
            self::stepBegin('leaf.file');
            try {
                $res = $this->fileOne($legislatureId, $jurisdictionId, $scopeId, $mapId, $actor, $d, $plan, $year, $floorPosture);
            } finally {
                self::stepEnd('leaf.file');
            }
            $ids[] = $res->recorded['district_id'];
        }

        return $ids;
    }

    /** One F-ELB-008 filing (timed as 'leaf.file' by the caller). */
    private function fileOne(
        string $legislatureId,
        string $jurisdictionId,
        string $scopeId,
        string $mapId,
        ?User $actor,
        array $d,
        array $plan,
        int $year,
        bool $floorPosture,
    ): object {
        $res = $this->engine->file('F-ELB-008', $actor, [
            'legislature_id'  => $legislatureId,
            'jurisdiction_id' => $jurisdictionId,
            'scope_id'        => $scopeId,
            'map_id'          => $mapId,
            // INDEXED PARTS (2026-07-22): splitline and components leaves
            // carry their geometry as a RAW GeoJSON string — a monster
            // leaf decoded into PHP arrays was a 768M fatal. Cells leaves
            // (small by construction) still carry decoded geometry.
            'geojson'         => $d['geometry_json'] ?? json_encode($d['geometry']),
            'label'           => null,
            'population_year' => $year,
            // Autoseed only: a marginally sub-floor piece records the
            // floor_override posture instead of refusing — pixel
            // granularity, not unlawfulness.
            'floor_posture'   => $floorPosture,
            // Machine-cut pieces carry their half-plane chain (operator
            // ruling 2026-07-22) — the handler re-applies the planner's
            // own per-point rule instead of geometric measurement.
            // Null (hand-drawn, components, cells, absorb) keeps the
            // geometric path.
            'cut_path'        => $d['cut_path'] ?? null,
            // PLANNED SEATS (operator ruling 2026-07-26, drift is always
            // wrong): the plan's seat vector sums to the giant's budget
            // BY CONSTRUCTION (seatGroups → sizes → a blade balanced to
            // those sizes). Re-deriving each piece's seats from a fresh
            // measurement at filing re-introduces rounding-edge noise, and
            // a single piece landing one seat off drifts the whole
            // chamber — Germany filed Berlin 20 + Hamburg 10 against
            // budgets summing to 27. This is the AUTOSEED path: the plan
            // was computed server-side in this same process from this
            // same raster, so there is no client to distrust. The handler
            // still measures, still gates the band, and still refuses a
            // real mismatch — it only stops overruling the plan on a
            // one-seat rounding disagreement.
            'planned_seats'   => $d['seats'] ?? null,
            // ISLANDS COUNT (operator law 2026-09-02): the mass of the
            // parts riding this piece whole, so the chain measurement
            // sums to the scope (Kujalleq 9+5 on 16 lost its islands).
            'island_pop'      => (float) ($d['island_pop'] ?? 0),
            // THE ORIGINAL COUNT (operator ruling 2026-09-02, the
            // Okhotsky 2,724/1,464 display): the plan's pixel partition
            // is the recount — every pixel on exactly one side, summing
            // to the scope. The piece records THESE numbers; the
            // handler's polygon re-measurement is a logged witness.
            'plan_pop'        => (int) round((float) ($d['pop'] ?? 0)),
            'plan_total_pop'  => (int) ($plan['total_pop'] ?? 0),
        ]);

        return $res;
    }

    /**
     * Ladder order for an autoseed (fallback-allowed) attempt: the stored
     * part count is read once (ST_NumGeometries on the stored geometry, no
     * dissolve) and the order is the pure orderTemplates() rule.
     */
    private function ladderOrder(string $scopeId, string $template): array
    {
        $row = DB::selectOne(
            'SELECT ST_NumGeometries(ST_CollectionExtract(geom, 3)) AS parts FROM jurisdictions WHERE id = ?',
            [$scopeId]
        );

        return self::orderTemplates($template, (int) ($row->parts ?? 1));
    }

    /**
     * SHORTEST LEADS, THE BOX IS THE GENERAL FALLBACK (operator ruling
     * 2026-09-03). The box-first-for-multi-part rule (2026-09-02, the Tumaco
     * grind) drew rectangles where shortest draws compact districts on every
     * island scope — Guernsey split Sark into two, Corfu filed non-contiguous,
     * both compact under shortest. The order is now the same for every scope:
     *   shortest -> box -> community_cells -> strips -> components.
     * Shortest wins the overwhelming majority (207,268 of 211,931 leaf scopes);
     * the box catches the fragmented remainder it cannot draw (Natales, 3,798
     * parts). The box basically always plans, so the three trailing cutting
     * methods are a near-dead tail (0, 0 and 1 win across the whole run) — kept
     * only as a fallback for the degenerate case where the box itself refuses
     * (no raster, or a piece clipping to no land). The Tumaco grind stays
     * bounded by the blade budget (SubdivisionAutoseedService::openBladePool),
     * which skips a spent cutting ladder straight to the box.
     *
     * $parts is no longer consulted (kept for the call signature).
     *
     * @return list<string>
     */
    public static function orderTemplates(string $template, int $parts): array
    {
        $ladder = [
            SubdivisionAutoseedService::TEMPLATE_SHORTEST,
            SubdivisionAutoseedService::TEMPLATE_BOX,
            SubdivisionAutoseedService::TEMPLATE_COMMUNITY_CELLS,
            SubdivisionAutoseedService::TEMPLATE_VERTICAL_STRIPS,
            SubdivisionAutoseedService::TEMPLATE_HORIZONTAL_STRIPS,
            SubdivisionAutoseedService::TEMPLATE_COMPONENTS,
        ];

        return array_values(array_unique(array_merge([$template], $ladder)));
    }

    /**
     * Try the requested template; when $allowFallback and it refuses, walk
     * the remaining templates in registry order (shortest → vertical_strips
     * → horizontal_strips → community_cells) and take the first that plans.
     * All refused → the LAST refusal bubbles (the review-list reason).
     *
     * @return array{plan: array, template: string, fallback: bool}
     *
     * @throws PlanRefused when every attempted template refuses
     */
    public function planWithFallback(string $scopeId, array $ctx, int $year, string $template, bool $allowFallback): array
    {
        $order = $allowFallback
            ? $this->ladderOrder($scopeId, $template)
            : array_values(array_unique(array_merge([$template], SubdivisionAutoseedService::TEMPLATES)));
        $last  = null;

        // ONE BLADE POOL PER SCOPE (2026-09-02): a fallback walk shares one
        // counter across its cutting rungs and skips to the box when it is
        // spent. A single-template ask lets plan() own its counter.
        if ($allowFallback) {
            $this->autoseed->openBladePool($scopeId);
        }
        try {
            foreach ($order as $i => $tpl) {
                if ($allowFallback
                    && $tpl !== SubdivisionAutoseedService::TEMPLATE_BOX
                    && $this->autoseed->bladeBudgetRemaining() <= 0) {
                    continue;
                }
                try {
                    self::stepBegin("leaf.{$tpl}");
                    $plan = $this->autoseed->plan($scopeId, $ctx, $year, $tpl);

                    return ['plan' => $plan, 'template' => $tpl, 'fallback' => $i > 0];
                } catch (PlanRefused $e) {
                    // A last-resort rung's refusal (components, mask) never masks
                    // a cutting template's reason (same posture as the commit
                    // ladder).
                    if ($last === null || ! in_array($tpl, [SubdivisionAutoseedService::TEMPLATE_COMPONENTS, SubdivisionAutoseedService::TEMPLATE_MASK], true)) {
                        $last = $e;
                    }
                } catch (RuntimeException $e) {
                    if ($last === null || ! in_array($tpl, [SubdivisionAutoseedService::TEMPLATE_COMPONENTS, SubdivisionAutoseedService::TEMPLATE_MASK], true)) {
                        $last = new PlanRefused($e->getMessage(), previous: $e);
                    }
                } finally {
                    self::stepEnd("leaf.{$tpl}");
                }

                if (! $allowFallback) {
                    throw $last;
                }
            }

            throw $last ?? new PlanRefused('No districting template produced a plan.');
        } finally {
            if ($allowFallback) {
                $this->autoseed->closeBladePool();
            }
        }
    }

    // ── step timings: the composites' collector, shared with the leaf path ──

    /**
     * Per method: whether DistrictingService exposes it publicly (resolved
     * once per process). The collector's methods are called only when they
     * are public; otherwise the leaf timers are no-ops, so this class never
     * reaches into the PROTECTED service's private state.
     */
    private static array $collectorExposed = [];

    /**
     * The collector is the SHARED DistrictingService: the scope service
     * withScopeService registered (or another container owner). With no
     * shared instance there is no record to persist, so the call is a
     * no-op rather than a timer on a throwaway instance.
     */
    private static function collectorCall(string $method, array $args = []): void
    {
        if (! array_key_exists($method, self::$collectorExposed)) {
            try {
                self::$collectorExposed[$method] = (new \ReflectionMethod(\App\Services\DistrictingService::class, $method))->isPublic();
            } catch (\ReflectionException) {
                self::$collectorExposed[$method] = false;
            }
        }
        if (self::$collectorExposed[$method] && app()->isShared(\App\Services\DistrictingService::class)) {
            app(\App\Services\DistrictingService::class)->{$method}(...$args);
        }
    }

    /**
     * Open a leaf-path step timer on the composites' collector
     * (DistrictingService::stepBegin) — 'leaf.<template>', 'leaf.dissolve',
     * 'leaf.file', 'leaf.census'. The record lives on the scope service
     * (one per leaf scope, so it starts empty) and reaches
     * apportionment_ledger_scopes.step_timings through the filed-scope beat.
     */
    public static function stepBegin(string $label): void
    {
        self::collectorCall('stepBegin', [$label]);
    }

    public static function stepEnd(string $label): void
    {
        self::collectorCall('stepEnd', [$label]);
    }

    /**
     * One unthrottled beat after a leaf scope files: the sweep beats at
     * scope start and on failure only, and the ledger row leaves 'running'
     * right after commit() returns, so without this beat a filed leaf's
     * timings never reach step_timings. Sweep context only; the interactive
     * mapper has no ledger row to carry them.
     */
    private function beatFiled(string $legislatureId, string $template, int $created): void
    {
        if (! \App\Support\AutoscaleContext::active()) {
            return;
        }
        app(\App\Services\DistrictingService::class)->publishMassProgress($legislatureId, [
            'phase'       => 'leaf_filed',
            'phase_label' => "Line-split filed ({$template}, {$created} districts)",
        ]);
    }

    /** Live drawn districts at a scope+plan — the set a whole-scope autoseed would displace. */
    public function liveDrawnCount(string $scopeId, string $mapId): int
    {
        return (int) DB::table('district_subdivisions')
            ->where('map_id', $mapId)
            ->where('parent_jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Retire every live DRAWN district at a scope+plan — the delete-endpoint
     * semantics (LegislatureController::deleteDistrict), applied to the whole
     * drawn set: soft-delete the subdivisions AND their legislature_districts,
     * hard-delete the membership rows. Same audit posture as the delete
     * endpoint (a plan-editing operation on a draft, not an engine filing) —
     * the replacement districts each file an audited F-ELB-008 right after.
     * Caller supplies the transaction.
     */
    /**
     * A lane that lost its claim (reclaimed or killed) stops before the
     * destructive step. Two lanes on one scope retired each other's filings
     * in turn on 2026-09-02; this is the check that ends it inside the draw.
     */
    private function assertScopeOwned(): void
    {
        if (! \App\Support\AutoscaleContext::ownsScope()) {
            throw new \RuntimeException('scope lost: claim_token no longer this lane');
        }
    }

    public function retireDrawnDistricts(string $legislatureId, string $scopeId, string $mapId): int
    {
        // Keyed off the SUBDIVISIONS — the exact basis the Art. II §8 overlap
        // gate reads — never through live-district joins: a ghost subdivision
        // whose district was already hard-deleted (the old Clear path) has no
        // join row, and a replace that cannot reach it can never clear the
        // gate. Each subdivision retires WITH its live district/memberships
        // when they exist; a districtless ghost still retires.
        $subdivisionIds = DB::table('district_subdivisions')
            ->where('map_id', $mapId)
            ->where('parent_jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        // Stale COMPOSITE rows filed AT this scope retire too (2026-07-23):
        // an INERT-child-layer scope previously composited zero-pop bins
        // here; the line-split replace must clear them or they double-cover
        // the territory. Normal leaf giants never composite at their own
        // scope, so this arm is a no-op for them.
        $compositeIds = DB::table('legislature_districts')
            ->where('legislature_id', $legislatureId)
            ->where('map_id', $mapId)
            ->where('jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        if (empty($subdivisionIds) && empty($compositeIds)) {
            return 0;
        }

        $districtIds = empty($subdivisionIds) ? [] : DB::table('legislature_district_jurisdictions AS ldj')
            ->join('legislature_districts AS ld', 'ld.id', '=', 'ldj.district_id')
            ->whereIn('ldj.subdivision_id', $subdivisionIds)
            ->where('ld.legislature_id', $legislatureId)
            ->whereNull('ld.deleted_at')
            ->distinct()
            ->pluck('ld.id')
            ->all();
        $districtIds = array_values(array_unique(array_merge($districtIds, $compositeIds)));

        if (! empty($districtIds)) {
            DB::table('legislature_district_jurisdictions')->whereIn('district_id', $districtIds)->delete();
            DB::table('legislature_districts')->whereIn('id', $districtIds)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        DB::table('district_subdivisions')->whereIn('id', $subdivisionIds)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        return count($subdivisionIds);
    }
}
