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

        $floor          = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling        = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);
        $giantThreshold = ConstitutionalDefaults::giantThreshold($leg->jurisdiction_id);

        $rootPop    = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
        $totalSeats = max((int) $leg->type_a_seats, 1);
        $giantPop   = (int) $giant->population;
        $giantFrac  = $giantPop * $totalSeats / $rootPop;
        $childCount = (int) DB::table('jurisdictions')->where('parent_id', $scopeId)->whereNull('deleted_at')->count();

        if ($giantFrac < $giantThreshold || $childCount > 0) {
            return null;
        }

        $budget = max($floor, (int) round($giantFrac));

        return [
            'floor'   => $floor,
            'ceiling' => $ceiling,
            'budget'  => $budget,
            'quota'   => $giantPop / max($budget, 1),
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
        try {
            $plan = $this->autoseed->plan($scopeId, $ctx, $year, $template);
        } catch (PlanRefused $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new PlanRefused($e->getMessage(), previous: $e);
        }

        if ($expectedPlanHash !== null && ! hash_equals($plan['plan_hash'], $expectedPlanHash)) {
            throw new PlanRefused('Plan changed — run the preview again.');
        }

        $jurisdictionId = (string) DB::table('legislatures')->where('id', $legislatureId)->value('jurisdiction_id');

        // A replace retires the scope's live drawn set before the first
        // filing — inside the caller's transaction, so a failed plan rolls
        // the old districts back too, never leaving the scope emptied.
        $replaced = $replace ? $this->retireDrawnDistricts($legislatureId, $scopeId, $mapId) : 0;

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
            ]);
            $ids[] = $res->recorded['district_id'];
        }

        return [
            'districts_created' => count($ids),
            'replaced'          => $replaced,
            'district_ids'      => $ids,
        ];
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
