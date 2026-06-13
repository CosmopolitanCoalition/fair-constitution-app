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
                'id', 'name', 'slug', 'adm_level', 'population', 'population_year'
            )
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Jurisdictions/Index', [
            'jurisdictions' => $jurisdictions,
            'filters' => $request->only(['search', 'adm_level']),
        ]);
    }

    /**
     * Jurisdiction detail page — Phase P.6 redesign. Becomes the place where
     * the operator reviews imported map data per-jurisdiction and accepts
     * the global dataset (at planet scope).
     *
     * Adopted from Legislature/Show.vue's sidebar layout pattern (header,
     * breadcrumb, quick stats, collapsible meta panel, review-issue badges,
     * children list). The map pane gets WorldPop raster overlay toggle and
     * Protomaps base tiles (via P.7).
     *
     * Per the P.4 constraint, this viewer does NOT echo `type_a_seats`
     * or `type_b_seats` — those stay in the legislature browser
     * (`/legislatures/{id}`) where the district mapper owns the
     * seat-budget concern. (The previous per-jurisdiction
     * `type_a_apportioned` / `type_b_apportioned` columns were dropped
     * by migration 2026_05_22_000002_apportionment_cleanup.php —
     * apportionment lives only at the district level now.)
     */
    public function show(Jurisdiction $jurisdiction): Response
    {
        $childCount = $jurisdiction->children()->count();

        // Legislature for this jurisdiction (if any) — drives the legislature-
        // related button state machine. Every parent jurisdiction gets a
        // legislature post-apportionment, but the legislature isn't
        // meaningfully viewable until at least one district map exists.
        $legislatureId = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->value('id');

        // FE-D0 cross-link: the public entry to the executive surfaces (the
        // Executive nav section is officeholder-gated). Every activated
        // jurisdiction gets a forming executive stub; the CTA renders when
        // one exists (public read — Art. II §2 · Art. III).
        $executiveId = DB::table('executives')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->value('id');

        // FE-E0 cross-link: the public entry to the court surfaces. Renders
        // once the judiciary is past `forming` (a real court — Art. II §2).
        $judiciaryId = DB::table('judiciaries')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'forming')
            ->value('id');

        // P.6.x.3: has-district-map gate. When true, "View Legislature &
        // Districts" button shows; when false (but legislature exists),
        // "Create first district map" shows instead.
        $hasDistrictMap = $legislatureId
            ? DB::table('legislature_district_maps')
                ->where('legislature_id', $legislatureId)
                ->whereNull('deleted_at')
                ->exists()
            : false;

        // FE-C2 — seated chamber gate: when current members exist the CTA
        // splits into "Chamber" + "District map"
        // (PHASE_C_DESIGN_frontend.md §B nav integration).
        $chamberSeated = $legislatureId
            ? DB::table('legislature_members')
                ->where('legislature_id', $legislatureId)
                ->whereIn('status', ['elected', 'seated'])
                ->whereNull('deleted_at')
                ->exists()
            : false;

        // Current election for this jurisdiction's legislature (if any) —
        // renders an "Election" CTA next to the legislature link. Latest
        // non-cancelled; live phases rank ahead of certified/final.
        $currentElection = $legislatureId
            ? DB::table('elections')
                ->where('legislature_id', $legislatureId)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')
                ->orderByRaw("CASE WHEN status IN ('certified', 'final') THEN 1 ELSE 0 END")
                ->orderByDesc('created_at')
                ->first(['id', 'status'])
            : null;

        // P.6: pull supplementary metadata from the geoboundary_metadata table.
        // Joined here rather than via the model's row to keep the show()
        // response shape independent of the import-time meta dict — operator
        // sees continent/region/income-group context from the same source
        // the import script wrote.
        $meta = DB::selectOne(
            '
            SELECT name AS boundary_name, continent, unsdg_region, unsdg_subregion,
                   world_bank_income_group, year_represented, boundary_canonical
            FROM   geoboundary_metadata
            WHERE  iso_code = :iso AND adm_level = 0
            LIMIT 1
            ',
            ['iso' => $jurisdiction->iso_code]
        );

        // P.6: orphan-children counter for the badge ("N orphans under this scope")
        $orphanChildrenCount = (int) DB::table('jurisdictions')
            ->where('parent_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')   // unreachable, just defensive
            ->count();
        // The above guard is structural — really we want orphans nested somewhere
        // beneath this jurisdiction; the badge counts them only at depth 1.
        $directChildOrphans = (int) DB::table('jurisdictions')
            ->where('parent_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->whereRaw('COALESCE(population, 0) = 0')
            ->count();

        // P.6: review-issue summary for this specific jurisdiction
        $reviewSummary = app(\App\Services\DataReviewService::class)
            ->summaryForJurisdiction($jurisdiction->id);

        // P.6: instance setup state — drives the "Accept Map Data" button
        // gating at planet scope. The button is enabled only when the ETL
        // is done AND map_accepted_at is null.
        $instanceSettings = \App\Models\InstanceSettings::current();

        // WI-9: activation status (WF-JUR-01 bootstrap tracker). No row =
        // dormant boundary — the frontend renders "Dormant — activates at
        // critical population" for that case (with a founded-at-setup
        // special case for the planet root, whose legislature is built by
        // the setup wizard rather than the activation engine).
        $activation = DB::table('jurisdiction_activations')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->first(['state', 'critical_population_at', 'activated_at']);

        return Inertia::render('Jurisdictions/Show', [
            'jurisdiction' => [
                'id' => $jurisdiction->id,
                'name' => $jurisdiction->name,
                'slug' => $jurisdiction->slug,
                'iso_code' => $jurisdiction->iso_code,
                'adm_level' => $jurisdiction->adm_level,
                'adm_label' => $jurisdiction->adm_label,
                'population' => $jurisdiction->population,
                'population_year' => $jurisdiction->population_year,
                'timezone' => $jurisdiction->timezone,
                'source' => $jurisdiction->source,
                'parent_assigned_via' => $jurisdiction->parent_assigned_via ?? null,
                'population_assigned_via' => $jurisdiction->population_assigned_via ?? null,
                'official_languages' => $jurisdiction->official_languages ?? [],
            ],
            'ancestors' => $jurisdiction->ancestors,
            'childCount' => $childCount,
            'hasChildren' => $childCount > 0,
            'directChildOrphans' => $directChildOrphans,
            'meta' => $meta ? (array) $meta : null,
            'review' => $reviewSummary,
            'legislature_id' => $legislatureId,
            'executive_id' => $executiveId !== null ? (string) $executiveId : null,
            'judiciary_id' => $judiciaryId !== null ? (string) $judiciaryId : null,
            'has_district_map' => $hasDistrictMap,
            'chamber_seated' => $chamberSeated,
            'current_election' => $currentElection ? [
                'id' => (string) $currentElection->id,
                'status' => $currentElection->status,
            ] : null,
            'activation' => $activation ? [
                'state' => $activation->state,
                'critical_population_at' => $activation->critical_population_at
                    ? \Illuminate\Support\Carbon::parse($activation->critical_population_at)->toIso8601String()
                    : null,
                'activated_at' => $activation->activated_at
                    ? \Illuminate\Support\Carbon::parse($activation->activated_at)->toIso8601String()
                    : null,
            ] : null,
            // Map-acceptance gate (only meaningful at planet scope, but
            // always sent so the frontend can hide the button at sub-scopes
            // without an extra round-trip). Named map_acceptance — NOT
            // 'instance' — so it can't shadow the Inertia shared 'instance'
            // prop (HandleInertiaRequests) that the AppShell footer reads.
            'map_acceptance' => [
                'is_planet_scope' => (int) $jurisdiction->adm_level === 0,
                'map_accepted_at' => $instanceSettings?->map_accepted_at?->toIso8601String(),
                'apportionment_completed_at' => $instanceSettings?->apportionment_completed_at?->toIso8601String(),
                'setup_step_completed' => $instanceSettings?->setup_step_completed,
            ],
        ]);
    }

    /**
     * Phase P.9 — Export full map-data state as a portable tarball. Streams
     * the tar.gz directly to the operator's browser as a download.
     *
     * Two modes:
     *   - Synchronous (default): pg_dump runs inline; browser holds the
     *     connection until the file streams out. Fine for small instances
     *     (single-country fresh runs) or skip_rasters=1 exports.
     *   - Async (?async=1): dispatches ExportMapDataJob, returns the
     *     export_id; operator polls /api/export/jurisdictions/list and
     *     downloads via /api/export/jurisdictions/download/{filename} when
     *     the status flips to "done".
     *
     * `?skip_rasters=1` drops worldpop_rasters from the dump (~7 GB saved;
     * useful when the receiving instance will run WorldPop separately).
     */
    public function exportMaps(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $skipRasters = $request->boolean('skip_rasters');
        $async = $request->boolean('async');

        // Selective tables[]: optional explicit subset of
        // MapDataExportService::TABLES. Accepts the array natively or as a
        // JSON-encoded string (the new Vue export panel sends JSON-string
        // inside multipart/form-data because FormData can't carry an array
        // value directly).
        $tables = $request->input('tables');
        if (is_string($tables)) {
            $decoded = json_decode($tables, true);
            $tables = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $tables)));
        }
        if ($tables !== null && ! is_array($tables)) {
            return response()->json(['ok' => false, 'error' => 'tables must be an array'], 422);
        }

        if ($async) {
            $exportId = 'map-data-'.now()->format('Ymd-His').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
            \App\Jobs\ExportMapDataJob::dispatch($exportId, $skipRasters, $tables);

            return response()->json([
                'ok' => true,
                'mode' => 'async',
                'export_id' => $exportId,
                'skip_rasters' => $skipRasters,
                'tables' => $tables,
                'status_url' => '/api/export/jurisdictions/list',
            ]);
        }

        try {
            $path = app(\App\Services\MapDataExportService::class)
                ->export(skipRasters: $skipRasters, tables: $tables);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('export failed: '.$e->getMessage());

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        // deleteFileAfterSend: cleans up the tmp tarball after the browser
        // finishes downloading. Operators can re-export anytime.
        return response()->download($path)->deleteFileAfterSend(true);
    }

    /**
     * Phase P.9 — return the curated list of bundle tables so the UI knows
     * which checkboxes to render. Lightweight (no DB query) — the values
     * live on MapDataExportService::TABLES.
     */
    public function exportMapsTables(Request $request): JsonResponse
    {
        return response()->json([
            'tables' => \App\Services\MapDataExportService::TABLES,
            'raster_tables' => \App\Services\MapDataExportService::RASTER_TABLES,
        ]);
    }

    /**
     * Phase P.9 — list all on-disk export status files + archives. Drives
     * the wizard's "past exports" panel. One row per status file; presence
     * of an archive_filename means the tarball is ready to download.
     */
    public function exportMapsList(Request $request): JsonResponse
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir)) {
            return response()->json(['exports' => []]);
        }

        $exports = [];
        foreach (glob("{$dir}/*.status.json") ?: [] as $statusFile) {
            $raw = @file_get_contents($statusFile);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($decoded)) {
                continue;
            }

            // Confirm the archive is actually present (could've been deleted
            // out from under us). If status is "done" but the file is gone,
            // surface as "expired".
            $archiveName = $decoded['archive_filename'] ?? null;
            $archiveOk = $archiveName !== null && is_file("{$dir}/{$archiveName}");
            $surface = $decoded['status'] ?? 'unknown';
            if ($surface === 'done' && ! $archiveOk) {
                $surface = 'expired';
            }

            $exports[] = [
                'export_id' => $decoded['export_id'] ?? basename($statusFile, '.status.json'),
                'status' => $surface,
                'skip_rasters' => (bool) ($decoded['skip_rasters'] ?? false),
                'started_at' => $decoded['started_at'] ?? null,
                'completed_at' => $decoded['completed_at'] ?? null,
                'error' => $decoded['error'] ?? null,
                'archive_filename' => $archiveOk ? $archiveName : null,
                'size_bytes' => $decoded['size_bytes'] ?? null,
                // Live progress snapshot from ExportMapDataJob's onProgress
                // callback (null until pg_dump has emitted its first tick).
                'progress' => $decoded['progress'] ?? null,
            ];
        }
        // Newest first
        usort($exports, fn ($a, $b) => strcmp((string) $b['started_at'], (string) $a['started_at']));

        return response()->json(['exports' => $exports]);
    }

    /**
     * Phase P.9 — download a previously-built export tarball. Filename is
     * validated against the same `storage/app/exports/` directory to
     * prevent path traversal.
     */
    public function exportMapsDownload(Request $request, string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $dir = storage_path('app/exports');
        // Disallow `..`, slashes, or any non-tarball pattern.
        if (! preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', $filename)) {
            return response()->json(['error' => 'invalid filename'], 400);
        }
        $path = "{$dir}/{$filename}";
        if (! is_file($path)) {
            return response()->json(['error' => 'not found'], 404);
        }

        return response()->download($path);
    }

    /**
     * Phase P.9 — request that a running export job halt.
     *
     * Sets a cache flag the ExportMapDataJob's polling loop checks every
     * ~0.5s inside MapDataExportService::runPgDump(). On detection, pg_dump
     * is SIGTERM'd, the partial dump file is unlinked, and the job records
     * `status: halted` (vs `failed`). Idempotent — calling on an already-
     * halted or finished export is a no-op success.
     */
    public function exportMapsHalt(Request $request, string $exportId): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $exportId)) {
            return response()->json(['error' => 'invalid export_id'], 400);
        }
        \Illuminate\Support\Facades\Cache::put(
            "export.{$exportId}.halt",
            true,
            3600,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Phase P.9 — delete a previously-built export tarball + status file.
     * Operator-facing cleanup so the listing doesn't accumulate forever.
     */
    public function exportMapsDelete(Request $request, string $exportId): JsonResponse
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $exportId)) {
            return response()->json(['error' => 'invalid export_id'], 400);
        }
        $dir = storage_path('app/exports');
        @unlink("{$dir}/{$exportId}.status.json");
        @unlink("{$dir}/{$exportId}.tar.gz");

        return response()->json(['ok' => true]);
    }

    /**
     * Phase P.9 — Import a tarball produced by exportMaps into a fresh
     * instance. Truncates target tables and runs pg_restore.
     *
     * Refuses to import while an ETL run is active (control file present)
     * to avoid clobbering in-flight data.
     */
    public function importMaps(Request $request): JsonResponse
    {
        $request->validate([
            'archive' => ['required', 'file', 'mimetypes:application/gzip,application/x-gzip,application/octet-stream'],
        ]);

        $controlDir = base_path('scripts/etl/control');
        if (is_file($controlDir.'/running.json')) {
            return response()->json([
                'ok' => false,
                'error' => 'An ETL run is in progress; import would clobber its in-flight data.',
            ], 409);
        }

        // Selective tables[]: optional. The form's checkboxes encode either a
        // JSON array under "tables" or comma-separated names under "tables".
        // Both are normalised to an array here; null = restore everything in
        // the bundle (matching the legacy behaviour).
        $tables = $request->input('tables');
        if (is_string($tables)) {
            $decoded = json_decode($tables, true);
            $tables = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $tables)));
        }
        if ($tables !== null && ! is_array($tables)) {
            return response()->json(['ok' => false, 'error' => 'tables must be an array'], 422);
        }

        try {
            $result = app(\App\Services\MapDataImportService::class)
                ->importFromUpload($request->file('archive'), $tables);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('import failed: '.$e->getMessage());

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $result);
    }

    /**
     * Phase P.6 — operator clicks "Accept Map Data & Continue" on the
     * planet-scope viewer. Stamps `instance_settings.map_accepted_at`,
     * advances `setup_step_completed` to 2, and dispatches the
     * `apportionment:seed` artisan command (Horizon-queued).
     *
     * Idempotent: re-clicking after acceptance is a no-op.
     */
    public function acceptMaps(Request $request): JsonResponse
    {
        $instance = \App\Models\InstanceSettings::current();
        if (! $instance) {
            return response()->json([
                'ok' => false,
                'error' => 'Instance settings row is missing — bootstrap not complete.',
            ], 422);
        }

        if ($instance->map_accepted_at) {
            return response()->json([
                'ok' => true,
                'already_accepted' => true,
                'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
                'apportionment_completed_at' => $instance->apportionment_completed_at?->toIso8601String(),
            ]);
        }

        $instance->forceFill([
            'map_accepted_at' => now(),
            'setup_step_completed' => max((int) $instance->setup_step_completed, 2),
        ])->save();

        // Dispatch apportionment as a queued artisan command. Horizon picks
        // it up; the UI polls instance_settings.apportionment_completed_at
        // (which ApportionmentSeedCommand now stamps on completion) to
        // flip the banner from "running…" → "completed".
        //
        // WI-9: the apportionment scope is derived from the jurisdiction
        // whose maps are being accepted (the caller sends its UUID), falling
        // back to the planet root for the legacy setup path (the button has
        // only ever been rendered at planet scope, so the fallback IS the
        // common case today). Acceptance itself remains a single global gate
        // — map_accepted_at / setup_step_completed live on instance_settings
        // — so the planet-only-gate semantics are unchanged; only the
        // seeding call is parameterized.
        $planetId = DB::table('jurisdictions')
            ->where('adm_level', 0)
            ->whereNull('deleted_at')
            ->value('id');
        $scopeId = $request->input('jurisdiction_id');
        if ($scopeId !== null) {
            $scopeId = DB::table('jurisdictions')
                ->where('id', $scopeId)
                ->whereNull('deleted_at')
                ->value('id');   // validate existence; null falls back below
        }
        $scopeId ??= $planetId;
        try {
            \Illuminate\Support\Facades\Artisan::queue('apportionment:seed', [
                '--jurisdiction' => $scopeId,
                // Setup-wizard path (planet scope): this run IS the canonical
                // apportionment, so it stamps
                // instance_settings.apportionment_completed_at. Non-planet
                // scopes (future per-jurisdiction acceptance) and
                // activation-engine runs omit the flag — WI-7/WI-9.
                '--stamp-instance' => $scopeId === $planetId,
            ]);
        } catch (\Throwable $e) {
            // Don't fail the acceptance — the operator can re-run the command
            // manually if Horizon is down.
            \Illuminate\Support\Facades\Log::warning(
                'apportionment:seed dispatch failed (acceptance still recorded): '.$e->getMessage()
            );
        }

        return response()->json([
            'ok' => true,
            'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
        ]);
    }

    /**
     * GeoJSON FeatureCollection of a jurisdiction's direct children.
     * Uses ST_Simplify with level-appropriate tolerance for performance.
     * Geometries are returned in WGS84 (SRID 4326).
     */
    public function childrenGeoJson(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $zoom = (int) $request->query('zoom', 6);
        $tolerance = $this->toleranceForZoom($zoom);
        $cacheKey = "geojson.children.{$jurisdiction->id}.z{$zoom}";

        // Persist-until-invalidated: boundary geometry only changes on a fresh
        // ETL / restore (flushed there), never on a district redraw. Prewarmed
        // entries must not silently expire on a 24h TTL, so cache forever.
        $data = Cache::rememberForever($cacheKey, function () use ($jurisdiction, $tolerance) {
            $rows = DB::select('
                SELECT
                    j.id,
                    j.name,
                    j.slug,
                    j.adm_level,
                    j.population,
                    j.iso_code,
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
            ', [
                'tolerance' => $tolerance,
                'parent_id' => $jurisdiction->id,
            ]);

            $features = array_map(function ($row) {
                return [
                    'type' => 'Feature',
                    'id' => $row->id,
                    'geometry' => json_decode($row->geojson),
                    'properties' => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'slug' => $row->slug,
                        'adm_level' => $row->adm_level,
                        'population' => (int) $row->population,
                        'iso_code' => $row->iso_code,
                        'child_count' => (int) $row->child_count,
                        'centroid_lat' => (float) $row->centroid_lat,
                        'centroid_lng' => (float) $row->centroid_lng,
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
        if (! $jurisdiction->parent_id) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $zoom = (int) $request->query('zoom', 6);
        $tolerance = $this->toleranceForZoom($zoom);
        $cacheKey = "geojson.siblings.{$jurisdiction->id}.z{$zoom}";

        // Persist-until-invalidated (see childrenGeoJson note).
        $data = Cache::rememberForever($cacheKey, function () use ($jurisdiction, $tolerance) {
            $rows = DB::select('
                SELECT
                    j.id,
                    j.name,
                    j.slug,
                    j.adm_level,
                    j.population,
                    j.iso_code,
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
            ', [
                'tolerance' => $tolerance,
                'parent_id' => $jurisdiction->parent_id,
                'self_id' => $jurisdiction->id,
            ]);

            $features = array_map(function ($row) {
                return [
                    'type' => 'Feature',
                    'id' => $row->id,
                    'geometry' => json_decode($row->geojson),
                    'properties' => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'slug' => $row->slug,
                        'adm_level' => $row->adm_level,
                        'population' => (int) $row->population,
                        'iso_code' => $row->iso_code,
                        'child_count' => (int) $row->child_count,
                        'centroid_lat' => (float) $row->centroid_lat,
                        'centroid_lng' => (float) $row->centroid_lng,
                    ],
                ];
            }, $rows);

            return ['type' => 'FeatureCollection', 'features' => $features];
        });

        return response()->json($data)->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GeoJSON for a single jurisdiction's own geometry (used as reference outline).
     *
     * Query params:
     *   - precise=1   Return the unsimplified geom. Used by the wizard MiniMap so
     *                 the outline matches the population raster PNG (which is
     *                 always clipped at the full-resolution polygon) pixel-for-
     *                 pixel during visual verification of the ETL output.
     *                 Larger payload but cached, so cold-start is the only cost.
     *   - zoom=N      (Default mode) Apply ST_Simplify with a per-zoom tolerance
     *                 from toleranceForZoom(). Used by the public jurisdictions
     *                 page where the page-level map is much wider than the
     *                 minimap and a coarser outline keeps payloads small.
     */
    public function selfGeoJson(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $precise = $request->boolean('precise');
        $zoom = (int) $request->query('zoom', 6);
        $tolerance = $this->toleranceForZoom($zoom);
        $cacheKey = $precise
            ? "geojson.self.{$jurisdiction->id}.precise"
            : "geojson.self.{$jurisdiction->id}.z{$zoom}";

        // Persist-until-invalidated (see childrenGeoJson note).
        $data = Cache::rememberForever($cacheKey, function () use ($jurisdiction, $tolerance, $precise) {
            $sql = $precise
                ? 'SELECT
                       ST_AsGeoJSON(geom) AS geojson,
                       ST_Y(COALESCE(centroid, ST_PointOnSurface(geom))) AS centroid_lat,
                       ST_X(COALESCE(centroid, ST_PointOnSurface(geom))) AS centroid_lng
                   FROM jurisdictions
                   WHERE id = :id AND geom IS NOT NULL'
                : 'SELECT
                       ST_AsGeoJSON(ST_Simplify(geom, :tolerance)) AS geojson,
                       ST_Y(COALESCE(centroid, ST_PointOnSurface(geom))) AS centroid_lat,
                       ST_X(COALESCE(centroid, ST_PointOnSurface(geom))) AS centroid_lng
                   FROM jurisdictions
                   WHERE id = :id AND geom IS NOT NULL';

            $bindings = $precise
                ? ['id' => $jurisdiction->id]
                : ['id' => $jurisdiction->id, 'tolerance' => $tolerance];

            $row = DB::selectOne($sql, $bindings);

            if (! $row || ! $row->geojson) {
                return ['type' => 'FeatureCollection', 'features' => []];
            }

            return [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => json_decode($row->geojson),
                    'properties' => [
                        'id' => $jurisdiction->id,
                        'name' => $jurisdiction->name,
                        'centroid_lat' => (float) $row->centroid_lat,
                        'centroid_lng' => (float) $row->centroid_lng,
                    ],
                ]],
            ];
        });

        return response()->json($data)->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Ancestry chain from this jurisdiction up to the planet root (adm_level = 0).
     *
     * Returned root-first so the UI can render a breadcrumb:
     *   [{ id, name, adm_level }, ...]  → Earth › USA › Alabama › Madison County.
     *
     * Used by the Setup wizard's CurrentJurisdictionCard to give the user
     * context during the ETL — seeing "Madison County" alone is meaningless;
     * seeing the chain tells them which state + country it belongs to.
     */
    public function ancestors(Jurisdiction $jurisdiction): JsonResponse
    {
        $cacheKey = "ancestors.{$jurisdiction->id}";

        $chain = Cache::remember($cacheKey, 86400, function () use ($jurisdiction) {
            $rows = DB::select('
                WITH RECURSIVE chain AS (
                    SELECT id, name, adm_level, parent_id, 0 AS depth
                    FROM jurisdictions
                    WHERE id = :id AND deleted_at IS NULL
                    UNION ALL
                    SELECT j.id, j.name, j.adm_level, j.parent_id, c.depth + 1
                    FROM jurisdictions j
                    INNER JOIN chain c ON j.id = c.parent_id
                    WHERE j.deleted_at IS NULL
                )
                SELECT id, name, adm_level
                FROM chain
                ORDER BY depth DESC
            ', ['id' => $jurisdiction->id]);

            return array_map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'adm_level' => (int) $r->adm_level,
            ], $rows);
        });

        return response()->json(['chain' => $chain])
            ->header('Cache-Control', 'public, max-age=3600');
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

    // rasterPng() removed: the WorldPop overlay is now served as a Leaflet
    // TileLayer from RasterTileController::tile at GET /api/rasters/{z}/{x}/{y}.png.
    // See that controller's docblock for the rationale (alignment, Earth-zoom
    // coverage, country-zoom resolution all resolved by the tile architecture).
}
