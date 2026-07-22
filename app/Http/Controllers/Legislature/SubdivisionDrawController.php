<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Services\Districting\PopulationRaster;
use App\Services\Districting\SubdivisionAutoseedService;
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
        private readonly SubdivisionAutoseedService $autoseed,
        // Mixed-autoseed unification (2026-07-17): the leaf-giant detector +
        // the Request-free F-ELB-008 commit loop now live in this shared
        // service so the mass-reseed sweep runs the identical logic.
        private readonly \App\Services\Districting\LeafGiantResolver $leafGiants,
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

        // Auto-clip: the operator's real gesture is a big rectangle dragged
        // across the giant's boundary — trim it to the giant BEFORE measuring
        // so the readout prices the shape that would actually file, instead of
        // refusing with ✗outside. Fully-inside draws pass through untouched.
        $clip = $this->clipToGiant($scopeId, $geoJson);
        if (isset($clip['error'])) {
            return response()->json(['error' => $clip['error']], 422);
        }

        $pop        = $this->raster->populationWithinMulti($clip['geojson'], $year);
        $fractional = $this->raster->impliedSeats($pop, $ctx['quota']);
        $seats      = (int) round($fractional);

        return response()->json([
            'population'              => $pop,
            'implied_fractional_seats'=> round($fractional, 3),
            'implied_seats'           => $seats,
            'in_band'                 => $seats >= $ctx['floor'] && $seats <= $ctx['ceiling'],
            // Contiguity in the Art. II §8 sense the handler enforces: at most
            // one CUT FRAGMENT — whole detached components of the giant
            // (islands the shape captured entire) ride without penalty.
            'contiguous'              => ($clip['fragments'] ?? $clip['parts']) <= 1,
            // Compatibility key: kept for older clients, but the clip makes it
            // always-true on a 200 — a crossing draw is trimmed, a fully
            // outside draw 422s above.
            'within_giant'            => true,
            // Whether the clip actually trimmed anything, plus the measured
            // geometry — ALWAYS echoed so the client can redraw its pending
            // layer as the shape that would really file.
            'clipped'                 => $clip['clipped'],
            'geometry'                => json_decode($clip['geojson']),
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

        // Clip exactly like probe() does before filing: the handler's exact
        // ST_CoveredBy proof then passes BY CONSTRUCTION — the same reason the
        // autoseed leaves are pre-clipped. The operator's label passes through
        // unchanged; a fully-inside draw files byte-identical (never clipped).
        $geoJson = $validated['geojson'];
        if (is_array($geoJson)) {
            $geoJson = json_encode($geoJson);
        }
        $clip = $this->clipToGiant($validated['scope_id'], (string) $geoJson);
        if (isset($clip['error'])) {
            return response()->json(['error' => $clip['error']], 422);
        }

        try {
            $result = $this->engine->file('F-ELB-008', $request->user(), [
                'legislature_id'  => $legislature_id,
                'jurisdiction_id' => $jurisdictionId,
                'scope_id'        => $validated['scope_id'],
                'map_id'          => $validated['map_id'],
                'geojson'         => $clip['geojson'],
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
     * POST /api/legislatures/{legislature_id}/autoseed-lines/preview — READ-ONLY.
     * The full deterministic shortest-splitline plan for a leaf giant: ordered
     * cuts + final districts + plan_hash. No draft map required; nothing persists.
     */
    public function autoseedPreview(Request $request, string $legislature_id): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['nullable', 'string', 'in:'.implode(',', SubdivisionAutoseedService::TEMPLATES)],
            'map_id'   => ['nullable', 'uuid'],
        ]);
        $template = $validated['template'] ?? SubdivisionAutoseedService::TEMPLATE_SHORTEST;
        $scopeId  = (string) $request->input('scope_id', '');
        $year     = (int) $request->input('population_year', 2023);
        if ($scopeId === '') {
            return response()->json(['error' => 'scope_id is required'], 422);
        }

        $ctx = $this->giantContext($legislature_id, $scopeId);
        if ($ctx === null) {
            return response()->json(['error' => 'Not a districtable leaf giant at this scope'], 422);
        }

        try {
            $plan = $this->autoseed->plan($scopeId, $ctx, $year, $template);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // How many live drawn districts the plan would displace — the accept
        // button reads this to offer replace up front instead of letting the
        // commit 422. Counted on the plan the UI displays (its map_id when
        // sent; the same active→newest-draft fallback the mapper uses when not).
        $mapId = $this->resolveMapId($legislature_id, $validated['map_id'] ?? null);
        $existing = $mapId !== null ? $this->liveDrawnCount($scopeId, $mapId) : 0;

        // INDEXED PARTS (2026-07-22): splitline/components leaves carry raw
        // GeoJSON strings (geometry_json) so monster plans never decode in
        // PHP; the mapper wire format keeps the decoded 'geometry' object.
        $plan['districts'] = array_map(function (array $d) {
            if (! isset($d['geometry']) && isset($d['geometry_json'])) {
                $d['geometry'] = json_decode($d['geometry_json'], true);
            }
            unset($d['geometry_json']);

            return $d;
        }, $plan['districts']);

        // $plan echoes the template back (part of the hashed plan identity).
        return response()->json($plan + [
            'floor'              => $ctx['floor'],
            'ceiling'            => $ctx['ceiling'],
            'existing_districts' => $existing,
        ]);
    }

    /**
     * POST /api/legislatures/{legislature_id}/autoseed-lines/commit — file the
     * previewed plan. The plan is RECOMPUTED server-side (determinism makes that
     * sound — client geometry is never trusted) and refused on a hash mismatch.
     * Only the tree's LEAVES become districts (each one an audited F-ELB-008,
     * all-or-nothing) — intermediate cuts are scaffolding, never replayed
     * pairwise through split-commit.
     */
    public function autoseedCommit(Request $request, string $legislature_id): JsonResponse
    {
        $validated = $request->validate([
            'scope_id'  => ['required', 'uuid'],
            'map_id'    => ['required', 'uuid'],
            'plan_hash' => ['required', 'string'],
            'template'  => ['nullable', 'string', 'in:'.implode(',', SubdivisionAutoseedService::TEMPLATES)],
            'replace'   => ['nullable', 'boolean'],
        ]);
        $template = $validated['template'] ?? SubdivisionAutoseedService::TEMPLATE_SHORTEST;
        $replace  = (bool) ($validated['replace'] ?? false);
        $year = (int) $request->input('population_year', 2023);

        $ctx = $this->giantContext($legislature_id, $validated['scope_id']);
        if ($ctx === null) {
            return response()->json(['error' => 'Not a districtable leaf giant at this scope'], 422);
        }

        // The same map guard F-ELB-008 enforces per filing — checked up
        // front so a wrong map fails before the recomputation is paid for.
        $map = DB::table('legislature_district_maps')
            ->where('id', $validated['map_id'])->whereNull('deleted_at')->first();
        if ($map === null || $map->legislature_id !== $legislature_id) {
            return response()->json(['error' => 'Unknown district map for this legislature'], 422);
        }
        if ($map->status !== 'draft') {
            // Mirror of the handler's SETUP-context posture: the FOUNDING (v1)
            // map IS the active map, drawn directly during Initial Setup —
            // drafts-only binds once a standing government exists (map v2+).
            $legJurisdiction = (string) DB::table('legislatures')
                ->where('id', $legislature_id)->value('jurisdiction_id');
            $activeFoundingMap = $map->status === 'active'
                && \App\Domain\Forms\Support\BoardProvenance::inSetupContext($legJurisdiction);
            if (! $activeFoundingMap) {
                return response()->json([
                    'error' => "District map is not a draft (status: {$map->status}) — "
                        .'a standing government drafts new plans and votes them active.',
                ], 422);
            }
        }

        // A whole-scope plan over live drawn districts can only end one of two
        // ways: retire them first (replace=true), or refuse the WHOLE plan
        // here — plainly, before the recompute is paid for — so the operator
        // never hits the per-piece Art. II §8 overlap citation from a plan
        // they meant to accept wholesale.
        $liveDrawn = $this->liveDrawnCount($validated['scope_id'], $validated['map_id']);
        if ($liveDrawn > 0 && ! $replace) {
            return response()->json([
                'error' => "This scope already holds {$liveDrawn} drawn district"
                    .($liveDrawn === 1 ? '' : 's')
                    .' — accept with replace, or clear them first.',
            ], 422);
        }

        try {
            // Atomic: every leaf district files, or none do. The resolver's
            // commit runs the recompute → hash check → optional retire →
            // per-leaf F-ELB-008 loop inside THIS transaction (extracted to
            // LeafGiantResolver 2026-07-17 so the method-aware mass sweep
            // files the identical districts without a Request). Districts
            // are in deterministic (path) order from the plan service; a
            // replace retires the scope's live drawn set before the first
            // filing, so a failed plan rolls the old districts back too.
            $result = DB::transaction(fn () => $this->leafGiants->commit(
                $legislature_id,
                $validated['scope_id'],
                $validated['map_id'],
                $request->user(),
                $ctx,
                $year,
                $template,
                $replace,
                (string) $validated['plan_hash'],
            ));
        } catch (ConstitutionalViolation $e) {
            return response()->json(['error' => $e->getMessage(), 'citation' => $e->citation], 422);
        } catch (\App\Services\Districting\PlanRefused $e) {
            // Plan failure, or "Plan changed — run the preview again." — the
            // EXACT pre-extraction 422 set; any other RuntimeException from
            // inside the filing transaction keeps propagating as a 500 and
            // never leaks internals to the client.
            return response()->json(['error' => $e->getMessage()], 422);
        }

        Cache::tags(["revealed.{$legislature_id}"])->flush();

        return response()->json([
            'ok'                 => true,
            'districts_created'  => $result['districts_created'],
            'district_ids'       => $result['district_ids'],
            'districts_replaced' => $result['replaced'],
        ]);
    }

    /**
     * POST /api/legislatures/{legislature_id}/subdivisions/remainder — READ-ONLY.
     * The giant MINUS the union of its live drawn districts (scope+map): the
     * shape the operator still has to district. Feeds the UI's Fill-remainder,
     * which stages the returned polygon exactly like a hand draw and commits it
     * through the NORMAL draw endpoint — this probe never files anything.
     */
    public function remainder(Request $request, string $legislature_id): JsonResponse
    {
        $validated = $request->validate([
            'scope_id' => ['required', 'uuid'],
            'map_id'   => ['nullable', 'uuid'],
        ]);
        $year   = (int) $request->input('population_year', 2023);
        $scopeId = $validated['scope_id'];

        $ctx = $this->giantContext($legislature_id, $scopeId);
        if ($ctx === null) {
            return response()->json(['error' => 'Not a districtable leaf giant at this scope'], 422);
        }

        $mapId = $this->resolveMapId($legislature_id, $validated['map_id'] ?? null);
        if ($mapId === null) {
            return response()->json(['error' => 'No district plan exists for this legislature yet'], 422);
        }

        // Giant minus the drawn union, with the PROVEN posture: a 1e-8° (~1 mm)
        // inward shave so the decimal-GeoJSON round trip through the draw
        // endpoint's exact ST_CoveredBy gate never nudges a shared boundary
        // vertex outside (the same epsilon defense splitSidesByLine carries).
        $row = DB::selectOne(
            'WITH gi AS (
                 SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = :scope
             ),
             drawn AS (
                 SELECT ST_Union(ds.geom) AS g
                   FROM district_subdivisions ds
                  WHERE ds.map_id = :map
                    AND ds.parent_jurisdiction_id = :scope2
                    AND ds.deleted_at IS NULL
             ),
             rem AS (
                 SELECT CASE
                            WHEN (SELECT g FROM drawn) IS NULL THEN (SELECT g FROM gi)
                            ELSE ST_Difference((SELECT g FROM gi), (SELECT g FROM drawn))
                        END AS g
             ),
             shaved AS (
                 SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer((SELECT g FROM rem), -0.00000001)), 3) AS g
             ),
             -- A committed shape whose straight edges weave across the jagged
             -- giant outline leaves hair-thin slivers along the shared border
             -- when differenced — a real district piece is km²-scale. Classify
             -- each dumped piece by geography area and keep only substantive
             -- ones (>= 1 hectare); the shreds are counted, never returned.
             pieces AS (
                 SELECT (ST_Dump((SELECT g FROM shaved))).geom AS p
             ),
             classed AS (
                 SELECT p, ST_Area(p::geography) AS area_m2 FROM pieces
             )
             SELECT COUNT(*) FILTER (WHERE area_m2 >= 10000)  AS parts,
                    COUNT(*) FILTER (WHERE area_m2 < 10000)   AS slivers_dropped,
                    ST_AsGeoJSON(ST_Multi(ST_Union(p) FILTER (WHERE area_m2 >= 10000)), 15) AS gj
               FROM classed',
            ['scope' => $scopeId, 'map' => $mapId, 'scope2' => $scopeId]
        );

        $parts   = $row === null ? 0 : (int) $row->parts;
        $slivers = $row === null ? 0 : (int) $row->slivers_dropped;
        if ($parts === 0 || $row->gj === null) {
            return response()->json([
                'error' => 'Nothing remains to draw — the whole area is already districted.',
            ], 422);
        }
        if ($parts !== 1) {
            return response()->json([
                'error' => "The remainder is split into {$parts} pieces — draw those separately.",
                'slivers_dropped' => $slivers,
            ], 422);
        }

        $pop        = $this->raster->populationWithinMulti($row->gj, $year);
        $fractional = $this->raster->impliedSeats($pop, $ctx['quota']);
        $seats      = (int) round($fractional);

        // Seats left in the giant's budget after the live drawn set — what the
        // remainder SHOULD hold if the operator is closing the scope out.
        $drawnSeats = (int) DB::table('district_subdivisions')
            ->where('map_id', $mapId)
            ->where('parent_jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->sum('seats');

        return response()->json([
            'geometry'                 => json_decode($row->gj),
            'population'               => $pop,
            'implied_fractional_seats' => round($fractional, 3),
            'implied_seats'            => $seats,
            'in_band'                  => $seats >= $ctx['floor'] && $seats <= $ctx['ceiling'],
            'remaining_seats'          => $ctx['budget'] - $drawnSeats,
            'slivers_dropped'          => $slivers,
            'floor'                    => $ctx['floor'],
            'ceiling'                  => $ctx['ceiling'],
            'giant_seat_budget'        => $ctx['budget'],
        ]);
    }

    /**
     * POST /api/legislatures/{legislature_id}/split-balance — READ-ONLY assist
     * for a hand-placed line: keep its angle, slide it to the nearest in-band
     * seat balance. Superset of the split-probe response shape.
     */
    public function splitBalance(Request $request, string $legislature_id): JsonResponse
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

        try {
            $balanced = $this->autoseed->balanceLine($scopeId, $ctx, $blade, $year);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $sides = [];
        $bothInBand = true;
        foreach ($balanced['pops'] as $i => $pop) {
            $frac   = $this->raster->impliedSeats($pop, $ctx['quota']);
            $seats  = (int) round($frac);
            $inBand = $seats >= $ctx['floor'] && $seats <= $ctx['ceiling'];
            $bothInBand = $bothInBand && $inBand;
            $sides[] = [
                'population'              => $pop,
                'implied_fractional_seats'=> round($frac, 3),
                'implied_seats'           => $seats,
                'target_seats'            => $balanced['seat_split'][$i],
                'in_band'                 => $inBand,
            ];
        }

        return response()->json([
            'line'              => $balanced['line'],
            'angle_deg'         => round($balanced['angle_deg'], 3),
            'seat_split'        => $balanced['seat_split'],
            'sides'             => $sides,
            'both_in_band'      => $bothInBand,
            'total'             => $sides[0]['population'] + $sides[1]['population'],
            'floor'             => $ctx['floor'],
            'ceiling'           => $ctx['ceiling'],
            'giant_seat_budget' => $ctx['budget'],
        ]);
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
                  ),
                  -- Each side is INSIDE the giant by construction (pieces of an
                  -- ST_Split of the giant itself), but the F-ELB-008 handler
                  -- proves EXACT ST_CoveredBy after a decimal-GeoJSON round
                  -- trip, whose serialization epsilon can nudge a boundary
                  -- vertex just outside — a diagonal cut then refuses with the
                  -- Art. II §8 outside-the-boundary citation. Shave 1e-8°
                  -- (~1 mm) inward so the interior margin dwarfs the round-trip
                  -- error — the same proven posture as the autoseed leaves.
                  merged AS (
                      SELECT side,
                             ST_CollectionExtract(ST_MakeValid(ST_Buffer(ST_Union(piece), -0.00000001)), 3) AS geom
                        FROM sided GROUP BY side
                  )
             SELECT side,
                    ST_AsGeoJSON(ST_Multi(geom), 15) AS gj,
                    ST_NumGeometries(ST_Multi(geom)) AS parts
               FROM merged ORDER BY side",
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

    /** Live drawn districts at a scope+plan — delegates to LeafGiantResolver (shared with the mass sweep). */
    private function liveDrawnCount(string $scopeId, string $mapId): int
    {
        return $this->leafGiants->liveDrawnCount($scopeId, $mapId);
    }

    /**
     * The map a request means when it names none — the same resolution the
     * mapper page uses (LegislatureController::getMapId): a valid explicit id
     * wins, else the active map, else the newest non-archived one.
     */
    private function resolveMapId(string $legislatureId, ?string $requestedMapId): ?string
    {
        if ($requestedMapId !== null) {
            $valid = DB::table('legislature_district_maps')
                ->where('id', $requestedMapId)
                ->where('legislature_id', $legislatureId)
                ->whereNull('deleted_at')
                ->exists();
            if ($valid) {
                return $requestedMapId;
            }
        }

        return DB::table('legislature_district_maps')
                ->where('legislature_id', $legislatureId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->value('id')
            ?? DB::table('legislature_district_maps')
                ->where('legislature_id', $legislatureId)
                ->where('status', '!=', 'archived')
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->value('id');
    }

    /**
     * Retire every live DRAWN district at a scope+plan — delegates to
     * LeafGiantResolver (shared with the mass sweep). Caller supplies the
     * transaction, exactly as before the extraction.
     */
    private function retireDrawnDistricts(string $legislatureId, string $scopeId, string $mapId): int
    {
        return $this->leafGiants->retireDrawnDistricts($legislatureId, $scopeId, $mapId);
    }

    /**
     * Clip a drawn polygon to its giant — but ONLY when it actually crosses the
     * boundary. A fully-inside draw passes through byte-identical, so the
     * proven behavior of interior draws (probe measurement, the handler's
     * exact ST_CoveredBy proof) never shifts by a re-serialization.
     *
     * The clip itself is the proven leaf posture: extract the polygonal part
     * of the intersection, then shave 1e-8° (~1 mm) inward so the decimal-
     * GeoJSON round trip through F-ELB-008's exact ST_CoveredBy gate can never
     * nudge a shared boundary vertex outside — the same epsilon defense the
     * autoseed leaves and splitSidesByLine carry.
     *
     * @return array{geojson:string, clipped:bool, parts:int}|array{error:string}
     *         geojson = the shape to measure/file (original when untrimmed).
     */
    private function clipToGiant(string $scopeId, string $geoJson): array
    {
        $row = DB::selectOne(
            'WITH d AS (SELECT ST_MakeValid(ST_GeomFromGeoJSON(:gj)) AS g),
                  gi AS (SELECT ST_MakeValid(geom) AS g
                           FROM jurisdictions
                          WHERE id = :scope AND geom IS NOT NULL AND deleted_at IS NULL),
                  clip AS (
                      SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                 ST_CollectionExtract(
                                     ST_Intersection((SELECT g FROM d), (SELECT g FROM gi)), 3),
                                 -0.00000001)), 3) AS g
                  ),
                  cparts AS (SELECT (ST_Dump((SELECT g FROM clip))).geom AS p),
                  gcomps AS (SELECT (ST_Dump((SELECT g FROM gi))).geom AS c),
                  whole AS (
                      SELECT count(*) AS n
                        FROM cparts cp
                       WHERE EXISTS (
                           SELECT 1 FROM gcomps gc
                            WHERE ST_Intersects(cp.p, gc.c)
                              AND ST_Area(ST_Intersection(cp.p, gc.c)) >= 0.98 * ST_Area(gc.c)
                              AND ST_Area(ST_Intersection(cp.p, gc.c)) >= 0.98 * ST_Area(cp.p)
                       )
                  )
             SELECT ST_IsEmpty((SELECT g FROM d))                              AS src_empty,
                    ST_NumGeometries(ST_Multi((SELECT g FROM d)))              AS src_parts,
                    (SELECT COUNT(*) FROM gi)                                  AS has_giant,
                    COALESCE(ST_CoveredBy((SELECT g FROM d), (SELECT g FROM gi)), false) AS within,
                    COALESCE(ST_IsEmpty((SELECT g FROM clip)), true)           AS clip_empty,
                    ST_NumGeometries(ST_Multi((SELECT g FROM clip)))           AS clip_parts,
                    (SELECT n FROM whole)                                      AS clip_whole_parts,
                    ST_AsGeoJSON(ST_Multi((SELECT g FROM clip)), 15)           AS clip_gj,
                    (SELECT name FROM jurisdictions WHERE id = :scope2)        AS giant_name',
            ['gj' => $geoJson, 'scope' => $scopeId, 'scope2' => $scopeId]
        );

        if ($row === null || (bool) $row->src_empty) {
            return ['error' => 'The drawn polygon is empty or invalid'];
        }
        if ((int) $row->has_giant === 0 || (bool) $row->within) {
            // No boundary to clip against (the engine will cite the unknown
            // jurisdiction) or already inside — pass the original through.
            // A hand-drawn polygon is one part, so its cut-fragment count is
            // its part count.
            return ['geojson' => $geoJson, 'clipped' => false, 'parts' => (int) $row->src_parts, 'fragments' => (int) $row->src_parts];
        }
        $name = (string) ($row->giant_name ?? 'the target jurisdiction');
        if ((bool) $row->clip_empty) {
            return ['error' => "The polygon lies entirely outside {$name}."];
        }
        // Multi-part clips are admissible when the extra parts are WHOLE
        // detached components of the giant (islands the lasso captured
        // entire) — the handler's Art. II §8 rule, mirrored here so a lasso
        // over mainland + Catalina measures and files instead of refusing.
        // Two-plus cut fragments of a single landmass still refuse.
        $clipParts = (int) $row->clip_parts;
        $fragmentParts = $clipParts - (int) ($row->clip_whole_parts ?? 0);
        if ($fragmentParts > 1) {
            return ['error' => "Clipping to the boundary splits your polygon into {$fragmentParts} separate cut pieces — draw inside."];
        }

        return ['geojson' => (string) $row->clip_gj, 'clipped' => true, 'parts' => $clipParts, 'fragments' => $fragmentParts];
    }

    /**
     * Resolve a giant scope's seat budget + local quota, or null if the scope is
     * not a childless leaf giant. Delegates to LeafGiantResolver — ONE detector
     * for these HTTP handlers and the mass-reseed sweep.
     */
    private function giantContext(string $legislatureId, string $scopeId): ?array
    {
        return $this->leafGiants->context($legislatureId, $scopeId);
    }
}
