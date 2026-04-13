<?php

namespace App\Http\Controllers;

use App\Models\Jurisdiction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JurisdictionController extends Controller
{
    /**
     * Searchable, filterable, paginated list of all jurisdictions.
     * Replaces the old world-map index — legislative data is visible here
     * without needing to navigate into each jurisdiction.
     */
    public function index(Request $request): Response
    {
        $jurisdictions = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->when($request->filled('adm_level'), fn ($q) => $q->where('adm_level', (int) $request->adm_level))
            ->orderBy('adm_level')
            ->orderBy('name')
            ->select(
                'id', 'name', 'slug', 'adm_level', 'population', 'population_year',
                'type_a_apportioned', 'type_b_apportioned'
            )
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Jurisdictions/Index', [
            'jurisdictions' => $jurisdictions,
            'filters'       => $request->only(['search', 'adm_level']),
        ]);
    }

    /**
     * Jurisdiction detail page — shows metadata + child map for any jurisdiction.
     */
    public function show(Jurisdiction $jurisdiction): Response
    {
        $childCount = $jurisdiction->children()->count();

        // Sum of children's apportioned seats — the composition of this jurisdiction's legislature
        $childrenTypeATotal = $childCount > 0
            ? (int) DB::table('jurisdictions')
                ->where('parent_id', $jurisdiction->id)
                ->whereNull('deleted_at')
                ->sum('type_a_apportioned')
            : null;

        $childrenTypeBTotal = $childCount > 0
            ? (int) DB::table('jurisdictions')
                ->where('parent_id', $jurisdiction->id)
                ->whereNull('deleted_at')
                ->sum('type_b_apportioned')
            : null;

        // Treat 0 sums (not yet seeded) as null so the UI omits the card
        if ($childrenTypeATotal === 0) { $childrenTypeATotal = null; }
        if ($childrenTypeBTotal === 0) { $childrenTypeBTotal = null; }

        // Legislature for this jurisdiction (if any) — drives the "View Legislature & Districts" link
        $legislatureId = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->value('id');

        return Inertia::render('Jurisdictions/Show', [
            'jurisdiction' => [
                'id'                 => $jurisdiction->id,
                'name'               => $jurisdiction->name,
                'slug'               => $jurisdiction->slug,
                'iso_code'           => $jurisdiction->iso_code,
                'adm_level'          => $jurisdiction->adm_level,
                'adm_label'          => $jurisdiction->adm_label,
                'population'         => $jurisdiction->population,
                'population_year'    => $jurisdiction->population_year,
                'timezone'           => $jurisdiction->timezone,
                'source'             => $jurisdiction->source,
                'official_languages' => $jurisdiction->official_languages ?? [],
            ],
            'ancestors'            => $jurisdiction->ancestors,
            'childCount'           => $childCount,
            'hasChildren'          => $childCount > 0,
            // This jurisdiction's allocation in its parent's legislature
            'type_a_apportioned'   => $jurisdiction->type_a_apportioned,
            'type_b_apportioned'   => $jurisdiction->type_b_apportioned,
            // Sum of children's allocations — this jurisdiction's own legislature composition
            'children_type_a_total' => $childrenTypeATotal,
            'children_type_b_total' => $childrenTypeBTotal,
            // Legislature for this jurisdiction (drives "View Legislature & Districts" link)
            'legislature_id'        => $legislatureId,
        ]);
    }

    /**
     * GeoJSON FeatureCollection of a jurisdiction's direct children.
     * Uses ST_Simplify with level-appropriate tolerance for performance.
     * Geometries are returned in WGS84 (SRID 4326).
     */
    public function childrenGeoJson(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $zoom      = (int) $request->query('zoom', 6);
        $tolerance = $this->toleranceForZoom($zoom);
        $cacheKey  = "geojson.children.{$jurisdiction->id}.z{$zoom}";

        $data = Cache::remember($cacheKey, 86400, function () use ($jurisdiction, $tolerance) {
            $rows = DB::select("
                SELECT
                    j.id,
                    j.name,
                    j.slug,
                    j.adm_level,
                    j.population,
                    j.iso_code,
                    j.type_a_apportioned,
                    j.type_b_apportioned,
                    COALESCE(cc.child_count, 0) AS child_count,
                    ST_AsGeoJSON(ST_Simplify(j.geom, :tolerance)) AS geojson,
                    ST_Y(COALESCE(j.centroid, ST_PointOnSurface(j.geom))) AS centroid_lat,
                    ST_X(COALESCE(j.centroid, ST_PointOnSurface(j.geom))) AS centroid_lng
                FROM jurisdictions j
                LEFT JOIN (
                    SELECT parent_id, COUNT(*) AS child_count
                    FROM jurisdictions
                    WHERE deleted_at IS NULL
                    GROUP BY parent_id
                ) cc ON cc.parent_id = j.id
                WHERE j.parent_id = :parent_id
                  AND j.deleted_at IS NULL
                  AND j.geom IS NOT NULL
                ORDER BY j.name
            ", [
                'tolerance' => $tolerance,
                'parent_id' => $jurisdiction->id,
            ]);

            $features = array_map(function ($row) {
                return [
                    'type'       => 'Feature',
                    'id'         => $row->id,
                    'geometry'   => json_decode($row->geojson),
                    'properties' => [
                        'id'                  => $row->id,
                        'name'                => $row->name,
                        'slug'                => $row->slug,
                        'adm_level'           => $row->adm_level,
                        'population'          => (int) $row->population,
                        'iso_code'            => $row->iso_code,
                        'child_count'         => (int) $row->child_count,
                        'centroid_lat'        => (float) $row->centroid_lat,
                        'centroid_lng'        => (float) $row->centroid_lng,
                        'type_a_apportioned'  => $row->type_a_apportioned !== null ? (int) $row->type_a_apportioned : null,
                        'type_b_apportioned'  => $row->type_b_apportioned !== null ? (int) $row->type_b_apportioned : null,
                    ],
                ];
            }, $rows);

            return ['type' => 'FeatureCollection', 'features' => $features];
        });

        return response()->json($data)->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GeoJSON FeatureCollection of a jurisdiction's siblings (parent's other children).
     * Used to render geographic context behind the current jurisdiction's children.
     */
    public function siblingsGeoJson(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        if (!$jurisdiction->parent_id) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $zoom      = (int) $request->query('zoom', 6);
        $tolerance = $this->toleranceForZoom($zoom);
        $cacheKey  = "geojson.siblings.{$jurisdiction->id}.z{$zoom}";

        $data = Cache::remember($cacheKey, 86400, function () use ($jurisdiction, $tolerance) {
            $rows = DB::select("
                SELECT
                    j.id,
                    j.name,
                    j.slug,
                    j.adm_level,
                    j.population,
                    j.iso_code,
                    j.type_a_apportioned,
                    j.type_b_apportioned,
                    (SELECT COUNT(*) FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS child_count,
                    ST_AsGeoJSON(ST_Simplify(j.geom, :tolerance)) AS geojson,
                    ST_Y(COALESCE(j.centroid, ST_PointOnSurface(j.geom))) AS centroid_lat,
                    ST_X(COALESCE(j.centroid, ST_PointOnSurface(j.geom))) AS centroid_lng
                FROM jurisdictions j
                WHERE j.parent_id = :parent_id
                  AND j.id != :self_id
                  AND j.deleted_at IS NULL
                  AND j.geom IS NOT NULL
                ORDER BY j.name
            ", [
                'tolerance' => $tolerance,
                'parent_id' => $jurisdiction->parent_id,
                'self_id'   => $jurisdiction->id,
            ]);

            $features = array_map(function ($row) {
                return [
                    'type'       => 'Feature',
                    'id'         => $row->id,
                    'geometry'   => json_decode($row->geojson),
                    'properties' => [
                        'id'                  => $row->id,
                        'name'                => $row->name,
                        'slug'                => $row->slug,
                        'adm_level'           => $row->adm_level,
                        'population'          => (int) $row->population,
                        'iso_code'            => $row->iso_code,
                        'child_count'         => (int) $row->child_count,
                        'centroid_lat'        => (float) $row->centroid_lat,
                        'centroid_lng'        => (float) $row->centroid_lng,
                        'type_a_apportioned'  => $row->type_a_apportioned !== null ? (int) $row->type_a_apportioned : null,
                        'type_b_apportioned'  => $row->type_b_apportioned !== null ? (int) $row->type_b_apportioned : null,
                    ],
                ];
            }, $rows);

            return ['type' => 'FeatureCollection', 'features' => $features];
        });

        return response()->json($data)->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GeoJSON for a single jurisdiction's own geometry (used as reference outline).
     */
    public function selfGeoJson(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $zoom      = (int) $request->query('zoom', 6);
        $tolerance = $this->toleranceForZoom($zoom);
        $cacheKey  = "geojson.self.{$jurisdiction->id}.z{$zoom}";

        $data = Cache::remember($cacheKey, 86400, function () use ($jurisdiction, $tolerance) {
            $row = DB::selectOne("
                SELECT
                    ST_AsGeoJSON(ST_Simplify(geom, :tolerance)) AS geojson,
                    ST_Y(COALESCE(centroid, ST_PointOnSurface(geom))) AS centroid_lat,
                    ST_X(COALESCE(centroid, ST_PointOnSurface(geom))) AS centroid_lng
                FROM jurisdictions
                WHERE id = :id AND geom IS NOT NULL
            ", ['id' => $jurisdiction->id, 'tolerance' => $tolerance]);

            if (!$row || !$row->geojson) {
                return ['type' => 'FeatureCollection', 'features' => []];
            }

            return [
                'type'     => 'FeatureCollection',
                'features' => [[
                    'type'       => 'Feature',
                    'geometry'   => json_decode($row->geojson),
                    'properties' => [
                        'id'           => $jurisdiction->id,
                        'name'         => $jurisdiction->name,
                        'centroid_lat' => (float) $row->centroid_lat,
                        'centroid_lng' => (float) $row->centroid_lng,
                    ],
                ]],
            ];
        });

        return response()->json($data)->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GeoJSON for all legislature districts belonging to this jurisdiction's legislature.
     * Used by the District overlay toggle in the viewer.
     */
    public function districtsGeoJson(Jurisdiction $jurisdiction): JsonResponse
    {
        $legislature = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->first();

        if (!$legislature) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        // Simplification tolerance mirrors childrenGeoJson: coarser at global level,
        // finer at sub-national level where districts are smaller.
        $tolerance = match (true) {
            $jurisdiction->adm_level === 0 => 0.05,
            $jurisdiction->adm_level === 1 => 0.01,
            $jurisdiction->adm_level === 2 => 0.005,
            default                        => 0.001,
        };

        $rows = DB::select("
            SELECT
                ld.id,
                ld.seats,
                ld.floor_override,
                ld.status,
                ST_AsGeoJSON(ST_Simplify(ld.geom, :tolerance)) AS geom_json,
                COUNT(ldj.id) AS member_count
            FROM legislature_districts ld
            LEFT JOIN legislature_district_jurisdictions ldj ON ldj.district_id = ld.id
            WHERE ld.legislature_id = :legislature_id
              AND ld.deleted_at IS NULL
              AND ld.geom IS NOT NULL
            GROUP BY ld.id, ld.seats, ld.floor_override, ld.status, ld.geom
            ORDER BY ld.seats DESC
        ", ['legislature_id' => $legislature->id, 'tolerance' => $tolerance]);

        $features = array_map(fn($d) => [
            'type'       => 'Feature',
            'geometry'   => json_decode($d->geom_json),
            'properties' => [
                'id'             => $d->id,
                'seats'          => $d->seats,
                'floor_override' => (bool) $d->floor_override,
                'member_count'   => (int) $d->member_count,
                'status'         => $d->status,
            ],
        ], $rows);

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }

    private function toleranceForZoom(int $zoom): float
    {
        // One pixel in degrees at the given Leaflet zoom (tile size 256px, WGS84).
        // zoom 8 → ~0.0055°   zoom 10 → ~0.0014°   zoom 14 → ~0.000085°
        // Capped at 0.01° (the original fixed tolerance) so that zoom-adaptive never
        // degrades quality below the baseline — it can only improve it at zoom ≥ 8.
        // At zoom ≤ 7 the formula gives ≥ 0.011°, so the cap always applies there.
        return max(min(360.0 / (256.0 * (2 ** $zoom)), 0.01), 0.00005);
    }
}
