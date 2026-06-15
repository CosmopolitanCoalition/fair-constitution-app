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

    /** POST /api/legislatures/{legislature_id}/split-probe — READ-ONLY bisection readout. */
    public function splitProbe(Request $request, string $legislature_id): JsonResponse
    {
        $scopeId = (string) $request->input('scope_id', '');
        $line    = $request->input('line');
        $year    = (int) $request->input('population_year', 2023);
        if (is_array($line)) {
            $line = json_encode($line);
        }
        if (!is_string($line) || $line === '' || $scopeId === '') {
            return response()->json(['error' => 'scope_id and line are required'], 422);
        }

        $ctx = $this->giantContext($legislature_id, $scopeId);
        if ($ctx === null) {
            return response()->json(['error' => 'Not a districtable leaf giant at this scope'], 422);
        }

        $blade = $this->bladeEndpoints($line);
        if ($blade === null) {
            return response()->json(['error' => 'A split line needs at least two points'], 422);
        }

        // Fast path: classify the giant's cached pixel grid by the blade — no
        // PostGIS scan, so the readout keeps up with the operator dragging.
        $grid = $this->raster->pixelGrid($scopeId, $year);
        [$popA, $popB] = PopulationRaster::splitByBlade($grid, $blade[0], $blade[1], $blade[2], $blade[3]);

        $sides = [];
        $bothInBand = true;
        foreach (['a' => $popA, 'b' => $popB] as $pop) {
            $pop   = (int) round($pop);
            $frac  = $this->raster->impliedSeats($pop, $ctx['quota']);
            $seats = (int) round($frac);
            $inBand = $seats >= $ctx['floor'] && $seats <= $ctx['ceiling'];
            $bothInBand = $bothInBand && $inBand;
            $sides[] = [
                'population'              => $pop,
                'implied_fractional_seats'=> round($frac, 3),
                'implied_seats'           => $seats,
                'in_band'                 => $inBand,
            ];
        }

        return response()->json([
            'sides'             => $sides,
            'both_in_band'      => $bothInBand,
            'total'             => $sides[0]['population'] + $sides[1]['population'],
            'floor'             => $ctx['floor'],
            'ceiling'           => $ctx['ceiling'],
            'giant_seat_budget' => $ctx['budget'],
        ]);
    }

    /** POST /api/legislatures/{legislature_id}/split-commit — bisect into TWO districts. */
    public function splitCommit(Request $request, string $legislature_id): JsonResponse
    {
        $validated = $request->validate([
            'scope_id' => ['required', 'uuid'],
            'map_id'   => ['required', 'uuid'],
            'line'     => ['required'],
        ]);
        $line = is_array($validated['line']) ? json_encode($validated['line']) : $validated['line'];

        $ctx = $this->giantContext($legislature_id, $validated['scope_id']);
        if ($ctx === null) {
            return response()->json(['error' => 'Not a districtable leaf giant at this scope'], 422);
        }

        $sides = $this->splitSidesByLine($validated['scope_id'], $line, $ctx['quota']);
        if ($sides === null) {
            return response()->json(['error' => 'The line does not cleanly divide this jurisdiction into two parts'], 422);
        }
        // Pre-validate both sides so we never half-commit a bisection.
        foreach ($sides as $s) {
            if ($s['seats'] < $ctx['floor'] || $s['seats'] > $ctx['ceiling']) {
                return response()->json([
                    'error' => 'A side of the cut is out of band ('.$s['seats'].' seats, band ['.$ctx['floor'].','.$ctx['ceiling'].']). Move the line.',
                    'citation' => 'Art. II §2',
                ], 422);
            }
        }

        $jurisdictionId = (string) DB::table('legislatures')->where('id', $legislature_id)->value('jurisdiction_id');

        try {
            // Atomic: both sides file F-ELB-008 (the pinned handler), or neither.
            $districts = DB::transaction(function () use ($sides, $legislature_id, $jurisdictionId, $validated, $request) {
                $out = [];
                foreach ($sides as $i => $s) {
                    $res = $this->engine->file('F-ELB-008', $request->user(), [
                        'legislature_id'  => $legislature_id,
                        'jurisdiction_id' => $jurisdictionId,
                        'scope_id'        => $validated['scope_id'],
                        'map_id'          => $validated['map_id'],
                        'geojson'         => $s['geojson'],
                        'label'           => null,
                    ]);
                    $out[] = $res->recorded;
                }
                return $out;
            });
        } catch (ConstitutionalViolation $e) {
            return response()->json(['error' => $e->getMessage(), 'citation' => $e->citation], 422);
        }

        Cache::tags(["revealed.{$legislature_id}"])->flush();

        return response()->json(['ok' => true, 'districts' => $districts]);
    }

    /**
     * Split a giant's polygon by a (straight) blade through the drawn line's
     * endpoints, extended to fully cross it, and group the pieces into the two
     * sides of the line. Returns [sideA, sideB] each with geojson/population/
     * fractional/seats/parts, or null if the line does not yield two parts.
     */
    /**
     * The straight cutting blade through a drawn line's first/last vertex,
     * extended 2° each way so it always fully crosses a (sub-degree) giant.
     * Returns [ax, ay, bx, by] or null.
     */
    private function bladeEndpoints(string $lineJson): ?array
    {
        $line = json_decode($lineJson, true);
        $coords = $line['coordinates'] ?? [];
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }
        [$x1, $y1] = $coords[0];
        [$x2, $y2] = $coords[count($coords) - 1];
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len == 0.0) {
            return null;
        }
        $ux = $dx / $len;
        $uy = $dy / $len;
        $ext = 2.0; // degrees — giants/castelli are << 1°, so this always over-crosses

        return [$x1 - $ux * $ext, $y1 - $uy * $ext, $x2 + $ux * $ext, $y2 + $uy * $ext];
    }

    private function splitSidesByLine(string $scopeId, string $lineJson, float $quota, int $year = 2023): ?array
    {
        $blade = $this->bladeEndpoints($lineJson);
        if ($blade === null) {
            return null;
        }
        [$ax, $ay, $bx, $by] = $blade;
        $bladeWkt = sprintf('LINESTRING(%.8f %.8f, %.8f %.8f)', $ax, $ay, $bx, $by);
        $dirx = $bx - $ax;
        $diry = $by - $ay;

        $rows = DB::select(
            "WITH g AS (SELECT ST_MakeValid(geom) AS geom FROM jurisdictions WHERE id = :id),
                  blade AS (SELECT ST_SetSRID(ST_GeomFromText(:blade), 4326) AS l),
                  parts AS (
                      SELECT (ST_Dump(ST_Split((SELECT geom FROM g), (SELECT l FROM blade)))).geom AS piece
                  ),
                  sided AS (
                      SELECT piece,
                             CASE WHEN (:dirx * (ST_Y(ST_PointOnSurface(piece)) - :ay)
                                      - :diry * (ST_X(ST_PointOnSurface(piece)) - :ax)) >= 0
                                  THEN 'a' ELSE 'b' END AS side
                        FROM parts
                  )
             SELECT side,
                    ST_AsGeoJSON(ST_Multi(ST_Union(piece))) AS gj,
                    ST_NumGeometries(ST_Multi(ST_Union(piece))) AS parts
               FROM sided GROUP BY side ORDER BY side",
            ['id' => $scopeId, 'blade' => $bladeWkt, 'dirx' => $dirx, 'diry' => $diry, 'ax' => $ax, 'ay' => $ay]
        );

        if (count($rows) !== 2) {
            return null; // the line didn't cut it into exactly two sides
        }

        $ctx = null;
        $out = [];
        foreach ($rows as $row) {
            $pop  = $this->raster->populationWithinMulti($row->gj, $year);
            $out[] = [
                'side'       => $row->side,
                'geojson'    => $row->gj,
                'population' => $pop,
                'parts'      => (int) $row->parts,
                '_pop'       => $pop,
            ];
        }
        foreach ($out as &$s) {
            $s['fractional'] = $this->raster->impliedSeats($s['population'], $quota);
            $s['seats']      = (int) round($s['fractional']);
            unset($s['_pop']);
        }

        return $out;
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
