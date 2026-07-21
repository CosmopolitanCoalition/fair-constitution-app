<?php

namespace App\Services\Districting;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\User;
use App\Services\ConstitutionalDefaults;
use Illuminate\Support\Facades\DB;
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
 *                 Accept button does.
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
     * root: the SUM of the root's direct children's populations, never the
     * root's own stored figure (Kentucky ruling 2026-07-18; seating law step
     * 2 — "children-sum as denominator, never the parent's stored population:
     * geodata noise"). The stored figure and the children-sum drift apart
     * (USA: 342.35M stored vs 346.04M children-sum), and any classification
     * computed on one base while the binding cascade runs on the other
     * manufactures phantom giants — Kentucky displayed as an undrawn 10-seat
     * scope while the law had already seated it as a 9-seat district, and
     * every drill attempt bounced. One base, everywhere. Falls back to the
     * stored population only when the root has no children (a leaf-rooted
     * legislature). Memoized per process; populations do not move mid-request
     * or mid-sweep.
     */
    public static function shareBase(string $rootJurisdictionId): int
    {
        static $memo = [];
        if (! isset($memo[$rootJurisdictionId])) {
            $sum = (int) DB::table('jurisdictions')
                ->where('parent_id', $rootJurisdictionId)
                ->whereNull('deleted_at')
                ->sum('population');
            if ($sum <= 0) {
                $sum = (int) DB::table('jurisdictions')
                    ->where('id', $rootJurisdictionId)
                    ->value('population');
            }
            $memo[$rootJurisdictionId] = max($sum, 1);
        }

        return $memo[$rootJurisdictionId];
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
            $rootChildren = (int) DB::table('jurisdictions')
                ->where('parent_id', $scopeId)->whereNull('deleted_at')->count();
            if ($rootChildren > 0) {
                return null;
            }
            $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);
            $budget  = (int) $leg->type_a_seats;
            if ($budget <= $ceiling) {
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
        if ($childCount > 0) {
            return null;
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
        $jurisdictionId = (string) DB::table('legislatures')->where('id', $legislatureId)->value('jurisdiction_id');

        // PREVIEWED PATH (hash set): one template, no ladder, violations
        // bubble raw — byte-identical to the pre-extraction behavior.
        if ($expectedPlanHash !== null) {
            $planned = $this->planWithFallback($scopeId, $ctx, $year, $template, false);
            if (! hash_equals($planned['plan']['plan_hash'], $expectedPlanHash)) {
                throw new PlanRefused('Plan changed — run the preview again.');
            }
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

        // AUTOSEED PATH (the recompute IS the plan): the FULL ladder —
        // "ladder first, manual for the residue" now survives FILING-stage
        // refusals too, not just planning-stage ones. A template whose
        // planned piece violates a filing gate (e.g. the Art. II §8
        // one-fragment rule against a concave boundary) retires its partial
        // set and the next template tries; only when every template has
        // refused at either stage does the scope land on the review list.
        $order = array_values(array_unique(array_merge([$template], SubdivisionAutoseedService::TEMPLATES)));
        $last  = null;
        foreach ($order as $i => $tpl) {
            try {
                $plan = $this->autoseed->plan($scopeId, $ctx, $year, $tpl);
            } catch (PlanRefused $e) {
                // The components template's plan-stage refusal ("single
                // landmass") never masks a cutting template's reason — the
                // review list keeps the diagnosis that matters.
                if ($last === null || $tpl !== SubdivisionAutoseedService::TEMPLATE_COMPONENTS) {
                    $last = $e;
                }
                continue;
            } catch (RuntimeException $e) {
                if ($last === null || $tpl !== SubdivisionAutoseedService::TEMPLATE_COMPONENTS) {
                    $last = new PlanRefused($e->getMessage(), previous: $e);
                }
                continue;
            }

            // Each attempt owns the scope's drawn set: retire whatever is
            // live (the caller's $replace on the first attempt; a previous
            // attempt's partial filings on later ones).
            $replaced = ($replace || $i > 0) ? $this->retireDrawnDistricts($legislatureId, $scopeId, $mapId) : 0;

            try {
                $ids = $this->fileDistricts($legislatureId, $jurisdictionId, $scopeId, $mapId, $actor, $plan, $year, true);

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
                // posture as the delete endpoint.
                $this->retireDrawnDistricts($legislatureId, $scopeId, $mapId);
                $last = new PlanRefused($e->getMessage(), previous: $e);
            }
        }

        throw $last ?? new PlanRefused('No districting template produced a filable plan.');
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
            $res = $this->engine->file('F-ELB-008', $actor, [
                'legislature_id'  => $legislatureId,
                'jurisdiction_id' => $jurisdictionId,
                'scope_id'        => $scopeId,
                'map_id'          => $mapId,
                'geojson'         => json_encode($d['geometry']),
                'label'           => null,
                'population_year' => $year,
                // Autoseed only: a marginally sub-floor piece records the
                // floor_override posture instead of refusing — pixel
                // granularity, not unlawfulness.
                'floor_posture'   => $floorPosture,
            ]);
            $ids[] = $res->recorded['district_id'];
        }

        return $ids;
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
        $order = array_values(array_unique(array_merge([$template], SubdivisionAutoseedService::TEMPLATES)));
        $last  = null;

        foreach ($order as $i => $tpl) {
            try {
                $plan = $this->autoseed->plan($scopeId, $ctx, $year, $tpl);

                return ['plan' => $plan, 'template' => $tpl, 'fallback' => $i > 0];
            } catch (PlanRefused $e) {
                // Components' refusal never masks a cutting template's reason
                // (same posture as the commit ladder).
                if ($last === null || $tpl !== SubdivisionAutoseedService::TEMPLATE_COMPONENTS) {
                    $last = $e;
                }
            } catch (RuntimeException $e) {
                if ($last === null || $tpl !== SubdivisionAutoseedService::TEMPLATE_COMPONENTS) {
                    $last = new PlanRefused($e->getMessage(), previous: $e);
                }
            }

            if (! $allowFallback) {
                throw $last;
            }
        }

        throw $last ?? new PlanRefused('No districting template produced a plan.');
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

        if (empty($subdivisionIds)) {
            return 0;
        }

        $districtIds = DB::table('legislature_district_jurisdictions AS ldj')
            ->join('legislature_districts AS ld', 'ld.id', '=', 'ldj.district_id')
            ->whereIn('ldj.subdivision_id', $subdivisionIds)
            ->where('ld.legislature_id', $legislatureId)
            ->whereNull('ld.deleted_at')
            ->distinct()
            ->pluck('ld.id')
            ->all();

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
