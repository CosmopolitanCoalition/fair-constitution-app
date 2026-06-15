<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Services\ConstitutionalDefaults;
use App\Services\Districting\PopulationRaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase H (H1) — the manual district-drawing surface for a CHILDLESS LEAF GIANT.
 *
 * Two endpoints, both scoped to a giant (Serravalle, etc.):
 *  - probe()  : READ-ONLY. The live readout behind the draw tool — population
 *               (aggregate worldpop), implied fractional seats, in-band, and
 *               contiguity for a polygon the operator is dragging. No state change.
 *  - draw()   : files F-ELB-008 (Manual District Draw) through the engine, which
 *               enforces every hard gate and audits the result. On a gate failure
 *               the engine throws a ConstitutionalViolation → 422 + citation.
 */
class SubdivisionDrawController extends Controller
{
    public function __construct(
        private readonly PopulationRaster $raster,
        private readonly ConstitutionalEngine $engine,
    ) {
    }

    /** POST /api/legislatures/{legislature_id}/population-probe */
    public function probe(Request $request, string $legislature_id): JsonResponse
    {
        $scopeId = (string) $request->input('scope_id', '');
        $geoJson = $request->input('geojson');
        $year    = (int) $request->input('population_year', 2023);

        if (is_array($geoJson)) {
            $geoJson = json_encode($geoJson);
        }
        if (!is_string($geoJson) || $geoJson === '' || $scopeId === '') {
            return response()->json(['error' => 'scope_id and geojson are required'], 422);
        }

        $ctx = $this->giantContext($legislature_id, $scopeId);
        if ($ctx === null) {
            return response()->json(['error' => 'Not a districtable leaf giant at this scope'], 422);
        }

        $geo = DB::selectOne(
            'WITH d AS (SELECT ST_MakeValid(ST_GeomFromGeoJSON(?)) AS g),
                  gi AS (SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = ?)
             SELECT ST_NumGeometries(ST_Multi((SELECT g FROM d))) AS parts,
                    ST_CoveredBy((SELECT g FROM d), (SELECT g FROM gi)) AS within,
                    ST_IsEmpty((SELECT g FROM d)) AS empty',
            [$geoJson, $scopeId]
        );

        if ($geo === null || (bool) $geo->empty) {
            return response()->json(['error' => 'The drawn polygon is empty or invalid'], 422);
        }

        $pop        = $this->raster->populationWithinMulti($geoJson, $year);
        $fractional = $this->raster->impliedSeats($pop, $ctx['quota']);
        $seats      = (int) round($fractional);
        $contiguous = (int) $geo->parts === 1;
        $within     = (bool) $geo->within;

        return response()->json([
            'population'              => $pop,
            'implied_fractional_seats'=> round($fractional, 3),
            'implied_seats'           => $seats,
            'in_band'                 => $seats >= $ctx['floor'] && $seats <= $ctx['ceiling'],
            'contiguous'              => $contiguous,
            'within_giant'            => $within,
            'floor'                   => $ctx['floor'],
            'ceiling'                 => $ctx['ceiling'],
            'giant_seat_budget'       => $ctx['budget'],
            'quota'                   => round($ctx['quota'], 1),
        ]);
    }

    /** POST /api/legislatures/{legislature_id}/subdivisions/draw */
    public function draw(Request $request, string $legislature_id): JsonResponse
    {
        $validated = $request->validate([
            'scope_id' => ['required', 'uuid'],
            'map_id'   => ['required', 'uuid'],
            'geojson'  => ['required'],
            'label'    => ['nullable', 'string', 'max:120'],
        ]);

        $jurisdictionId = (string) DB::table('legislatures')
            ->where('id', $legislature_id)->value('jurisdiction_id');

        try {
            $result = $this->engine->file('F-ELB-008', $request->user(), [
                'legislature_id'  => $legislature_id,
                'jurisdiction_id' => $jurisdictionId,
                'scope_id'        => $validated['scope_id'],
                'map_id'          => $validated['map_id'],
                'geojson'         => $validated['geojson'],
                'label'           => $validated['label'] ?? null,
            ]);
        } catch (ConstitutionalViolation $e) {
            return response()->json(['error' => $e->getMessage(), 'citation' => $e->citation], 422);
        }

        // Repaint: drop the revealed-layer cache for this legislature so the new
        // sub-district appears on the next map fetch.
        Cache::tags(["revealed.{$legislature_id}"])->flush();

        return response()->json(['ok' => true, 'district' => $result->recorded]);
    }

    /**
     * Resolve a giant scope's seat budget + local quota, or null if the scope is
     * not a childless leaf giant. Mirrors the F-ELB-008 handler's resolution.
     */
    private function giantContext(string $legislatureId, string $scopeId): ?array
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
}
