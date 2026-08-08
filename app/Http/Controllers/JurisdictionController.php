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
            // Activation filter (operator tour, 2026-08-08): activated =
            // holds a live legislature.
            ->when($request->filled('active'), function ($q) use ($request) {
                $exists = fn ($qq) => $qq->from('legislatures')
                    ->whereColumn('legislatures.jurisdiction_id', 'jurisdictions.id')
                    ->whereNull('legislatures.deleted_at');
                $request->boolean('active') ? $q->whereExists($exists) : $q->whereNotExists($exists);
            })
            ->orderBy('adm_level')
            ->orderBy('name')
            ->select(
                'id', 'name', 'slug', 'adm_level', 'population', 'population_year'
            )
            // Per-row legislature presence drives the Activate / Districts
            // action column (manual-first arc, operator 2026-08-06).
            ->selectSub(function ($q) {
                $q->from('legislatures')
                  ->whereColumn('legislatures.jurisdiction_id', 'jurisdictions.id')
                  ->whereNull('legislatures.deleted_at')
                  ->select('legislatures.id')
                  ->limit(1);
            }, 'legislature_id')
            // HALF-ACTIVATED detector (operator-caught 2026-08-08): a place can
            // hold a legislature (seat math) with NO active election board —
            // the R-08 substrate the mapper's F-ELB-008 filing needs. The row
            // then offers "Finish activation" instead of pretending it is done.
            ->selectRaw("EXISTS (SELECT 1 FROM election_boards eb
                                  WHERE eb.jurisdiction_id = jurisdictions.id
                                    AND eb.status = 'active' AND eb.deleted_at IS NULL) AS has_board")
            // The ancestor CHAIN per row (operator tour, 2026-08-08): same-named
            // places are only tellable apart by their lineage — "Earth › USA ›
            // Illinois". Scalar recursive subquery, ≤6 hops, page-bounded.
            ->selectRaw("(
                WITH RECURSIVE up AS (
                    SELECT p.id, p.parent_id, p.name, 1 AS depth
                      FROM jurisdictions p
                     WHERE p.id = jurisdictions.parent_id AND p.deleted_at IS NULL
                    UNION ALL
                    SELECT a.id, a.parent_id, a.name, up.depth + 1
                      FROM jurisdictions a JOIN up ON a.id = up.parent_id
                     WHERE a.deleted_at IS NULL
                )
                SELECT string_agg(up.name, ' › ' ORDER BY up.depth DESC) FROM up
            ) AS chain")
            ->paginate(50)
            ->withQueryString();

        // Activation-mode context for the list's operator controls
        // (2026-08-08 — the harmonized activation surface).
        $instance = \App\Models\InstanceSettings::query()->whereNull('deleted_at')->first();

        return Inertia::render('Jurisdictions/Index', [
            'jurisdictions' => $jurisdictions,
            'filters' => $request->only(['search', 'adm_level', 'active']),
            'scale' => [
                'mode'            => (string) ($instance?->institution_scale_mode ?? 'eager'),
                'map_accepted_at' => $instance?->map_accepted_at?->toIso8601String(),
                // Sandbox worlds get the per-row Simulate control.
                'is_sandbox'      => $instance?->game_mode === 'sandbox',
                // Half-activated backlog (legislature, no board) — the bulk
                // boot's badge. Cheap: an anti-join over activated places.
                'half_activated'  => $this->halfActivatedCount(),
            ],
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

        // S-grade addition (V3 gap matrix — jurisdiction-browser row): the
        // Reach & participation block. Reads the nightly SUPPRESSED snapshot
        // exactly as ReachController::gauge() does — the LegitimacyService
        // k-anonymity rail (CI-1: a gauge, never a lever). NEVER a live
        // headcount: a live COUNT here would hand an observer sub-minute
        // resolution on a number the snapshot publishes once a day and let
        // them defeat suppression by differencing. `state` is the contract —
        // the frontend switches on it and never infers from the raw numbers;
        // no snapshot is "not measured yet", never "zero reach".
        $reachRow = DB::table('legitimacy_snapshots')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->orderByDesc('as_of_date')
            ->first(['state', 'as_of_date', 'verified_residents', 'population_estimate', 'ratio_micro']);
        $reach = $reachRow !== null
            ? [
                'state'               => $reachRow->state,
                'as_of_date'          => $reachRow->as_of_date,
                'verified_residents'  => $reachRow->verified_residents !== null ? (int) $reachRow->verified_residents : null,
                'population_estimate' => $reachRow->population_estimate !== null ? (int) $reachRow->population_estimate : null,
                'ratio_micro'         => $reachRow->ratio_micro !== null ? (int) $reachRow->ratio_micro : null,
            ]
            : [
                'state'               => \App\Services\LegitimacyService::STATE_UNMEASURABLE,
                'as_of_date'          => null,
                'verified_residents'  => null,
                'population_estimate' => null,
                'ratio_micro'         => null,
            ];

        return Inertia::render('Jurisdictions/Show', [
            // Phase 3e reshape: the viewer joins the v2 shell + PageScaffold,
            // which reads the surface record for eyebrow/citation.
            'surface' => \App\Support\SurfaceMeta::for('jurisdictions/viewer'),
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
                // Authoritative "are we still inside setup" flag. setup_step_completed
                // alone cannot answer it — it keeps its last value after setup
                // finishes — and the viewer needs the answer to decide whether
                // "Accept Map Data & Continue" has anywhere to continue TO.
                'setup_completed_at' => $instanceSettings?->setup_completed_at?->toIso8601String(),
            ],
            // S-grade addition — the Reach & participation gauge (see the
            // snapshot read above). Feeds the sidebar block that links out to
            // the full /reach panel.
            'reach' => $reach,
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
     * Phase P.9 / Workstream C — return the FULL portable-table suite so the
     * UI can render an accurate, self-maintaining chooser. The list is
     * schema-derived (every public BASE table minus the framework / infra /
     * privacy / key-material denylist) and topologically ordered parent →
     * child, so it stays correct as tables are added instead of drifting
     * from a hand-maintained constant.
     *
     * Response shape (backward compatible — `tables` is still a flat ordered
     * array and `raster_tables` still lists the heavy raster store):
     *   - tables         : full derived export order (flat, parent → child)
     *   - default_tables : the curated default-export subset (what a plain
     *                       export ships when no selection is made)
     *   - raster_tables  : heavy raster tables (skip_rasters target)
     *   - groups         : { domainPrefix: [tables…] } for categorized UI,
     *                       preserving the derived order within each group
     */
    public function exportMapsTables(Request $request): JsonResponse
    {
        $svc     = app(\App\Services\MapDataExportService::class);
        $derived = $svc->deriveExportableTables();

        // Cheap categorization for the UI: group by a leading domain token.
        // Purely cosmetic — the flat `tables` list is authoritative for order.
        $groups = [];
        foreach ($derived as $t) {
            $groups[$this->tableDomain($t)][] = $t;
        }

        return response()->json([
            'tables'         => $derived,
            'default_tables' => \App\Services\MapDataExportService::TABLES,
            'raster_tables'  => \App\Services\MapDataExportService::RASTER_TABLES,
            'groups'         => $groups,
        ]);
    }

    /**
     * Map a table name to a coarse UI domain bucket for the export chooser.
     * Best-effort prefix matching — grouping only affects presentation.
     */
    private function tableDomain(string $table): string
    {
        static $prefixMap = [
            'cosmic'      => 'cosmos',
            'instance'    => 'cosmos',
            'jurisdiction'=> 'geography',
            'geoboundary' => 'geography',
            'worldpop'    => 'geography',
            'data_review' => 'geography',
            'legislature' => 'legislature',
            'chamber'     => 'legislature',
            'committee'   => 'legislature',
            'bill'        => 'legislature',
            'motion'      => 'legislature',
            'law'         => 'legislature',
            'executive'   => 'executive',
            'department'  => 'executive',
            'board'       => 'executive',
            'appropriation'=> 'executive',
            'grant'       => 'executive',
            'judicial'    => 'judiciary',
            'judiciar'    => 'judiciary',
            'case'        => 'judiciary',
            'panel'       => 'judiciary',
            'jury'        => 'judiciary',
            'juries'      => 'judiciary',
            'opinion'     => 'judiciary',
            'verdict'     => 'judiciary',
            'warrant'     => 'judiciary',
            'sentencing'  => 'judiciary',
            'constitutional'=> 'judiciary',
            'election'    => 'elections',
            'ballot'      => 'elections',
            'candidac'    => 'elections',
            'endorsement' => 'elections',
            'tabulation'  => 'elections',
            'vacanc'      => 'elections',
            'referendum'  => 'elections',
            'petition'    => 'elections',
            'vote'        => 'elections',
            'approval'    => 'elections',
            'org'         => 'organizations',
            'residency'   => 'civic',
            'social'      => 'social',
            'matrix'      => 'social',
            'cluster'     => 'federation',
            'federation'  => 'federation',
            'peer'        => 'federation',
            'sync'        => 'federation',
            'partition'   => 'federation',
            'mesh'        => 'federation',
            'audit'       => 'audit',
            'public_records'=> 'audit',
        ];

        foreach ($prefixMap as $prefix => $domain) {
            if (str_starts_with($table, $prefix)) {
                return $domain;
            }
        }
        return 'other';
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
     * advances `setup_step_completed` to 2, and starts the full-scale
     * AUTOSCALE run (pull engine: pump + claim workers): every jurisdiction
     * gets a sized legislature and a founding district map.
     *
     * Idempotent: re-clicking after acceptance no-ops on a live run and
     * RESUMES a stalled or halted one.
     */
    public function acceptMaps(Request $request): JsonResponse
    {
        // Same operator posture as the geodata repair POSTs: acceptance flips
        // the repair-window gate, so an unauthenticated LAN visitor must not
        // be able to slam it shut (or, via reopen, swing it open).
        abort_unless((bool) $request->user()?->is_operator, 403);

        // Locked check-then-stamp: repairs take the same instance_settings row
        // lock as the FIRST statement of their transactions, so acceptance
        // serializes against an in-flight repair instead of racing it.
        $gate = DB::transaction(function () use ($request) {
            $instance = \App\Models\InstanceSettings::query()->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $instance) {
                return ['response' => response()->json([
                    'ok' => false,
                    'error' => 'Instance settings row is missing — bootstrap not complete.',
                ], 422)];
            }

            if ($instance->map_accepted_at) {
                // Autoscale (2026-07-18): a re-POST after acceptance RESUMES an
                // unfinished full-scale run instead of 409ing — the operator's
                // recovery path after a box reboot or a halt. A live run
                // (fresh heartbeat) is left alone.
                $unfinished = \App\Models\AutoscaleRun::unfinished();
                if ($unfinished !== null) {
                    if ($unfinished->status !== 'halted' && ! $unfinished->haltRequested()) {
                        // Pull engine: an active run needs no revival — the
                        // pump is its liveness root. Nothing to dispatch.
                        return ['response' => response()->json([
                            'ok' => true,
                            'already_accepted' => true,
                            'autoscale_run_id' => (string) $unfinished->id,
                            'autoscale_status' => $unfinished->status,
                        ])];
                    }
                    $unfinished->forceFill(['halt_requested_at' => null])->save();
                    // Pump kick happens AFTER this locked transaction commits
                    // (see below) — never hold the instance_settings row lock
                    // through pump work.
                    return ['response' => response()->json([
                        'ok' => true,
                        'already_accepted' => true,
                        'autoscale_resumed' => true,
                        'autoscale_run_id' => (string) $unfinished->id,
                    ]), 'kick_pump' => true];
                }

                // THE RE-HOOK (operator 2026-08-06, the manual-first arc):
                // acceptance may have DEFERRED the planet-wide build; the
                // "Start planet-wide generation" control re-posts with
                // start_autoscale=true and the run is created below through
                // the same path as a fresh acceptance. An unfinished run was
                // already handled above; a completed one is deliberately left
                // alone — re-running the planet is the mass-reseed sweep's
                // job, not acceptance's.
                if ($request->boolean('start_autoscale')) {
                    return ['instance' => $instance, 'rehook' => true,
                            'open_flags' => ['critical' => 0, 'warning' => 0, 'info' => 0]];
                }

                return ['response' => response()->json([
                    'ok' => true,
                    'already_accepted' => true,
                    'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
                    'apportionment_completed_at' => $instance->apportionment_completed_at?->toIso8601String(),
                ])];
            }

            // Repair-plane acknowledgment gate: accepting the map CLOSES the
            // repair window, so open geodata flags must be surfaced first. Any
            // open flag requires an explicit acknowledge_open_flags=true from
            // the confirm dialog before acceptance proceeds; the counts snapshot
            // rides the success response (and the log) either way.
            $openRow = DB::table('geodata_flags')
                ->whereNull('deleted_at')
                ->where('status', 'open')
                ->selectRaw("
                    COUNT(*) FILTER (WHERE severity = 'critical') AS critical,
                    COUNT(*) FILTER (WHERE severity = 'warning')  AS warning,
                    COUNT(*) FILTER (WHERE severity = 'info')     AS info
                ")
                ->first();
            $openFlags = [
                'critical' => (int) ($openRow->critical ?? 0),
                'warning'  => (int) ($openRow->warning ?? 0),
                'info'     => (int) ($openRow->info ?? 0),
            ];
            if (array_sum($openFlags) > 0 && ! $request->boolean('acknowledge_open_flags')) {
                return ['response' => response()->json([
                    'requires_acknowledgment' => true,
                    'open_flags' => $openFlags,
                ], 422)];
            }

            // THE THREE ACTIVATION MODES (operator, 2026-08-08). The mode is
            // chosen at acceptance and stored; everything downstream reads it:
            //   eager      → the full-scale build starts below (autoscale),
            //                and its completion chains institution
            //                provisioning (AutoscalePumpCommand done-flip).
            //   population → nothing starts; CLK-06 boots each place as
            //                verified residents cross its threshold.
            //   manual     → nothing starts; the Activate controls and the
            //                governance forms build the world by hand.
            // simulate_at_scale is dev-only (game_mode sandbox) and only
            // meaningful under eager. Legacy defer_autoscale (no mode sent)
            // maps to manual.
            $mode = (string) $request->input('scale_mode', '');
            if (! in_array($mode, ['eager', 'population', 'manual'], true)) {
                $mode = $request->boolean('defer_autoscale') ? 'manual' : 'eager';
            }
            $simulate = $mode === 'eager'
                && $request->boolean('simulate_at_scale')
                && $instance->game_mode === 'sandbox';

            $instance->forceFill([
                'map_accepted_at' => now(),
                'setup_step_completed' => max((int) $instance->setup_step_completed, 2),
                'institution_scale_mode' => $mode,
                'simulate_at_scale' => $simulate,
            ])->save();

            return ['instance' => $instance, 'open_flags' => $openFlags, 'mode' => $mode];
        });

        if (isset($gate['response'])) {
            if ($gate['kick_pump'] ?? false) {
                try {
                    \Illuminate\Support\Facades\Artisan::call('autoscale:pump');
                } catch (\Throwable) {
                    // The scheduler's next pump minute resumes it anyway.
                }
            }

            return $gate['response'];
        }
        $instance  = $gate['instance'];
        $openFlags = $gate['open_flags'];

        // MANUAL-FIRST MODE (operator 2026-08-06: "I want to return to
        // building One Jurisdiction at a time manually"): acceptance stamps
        // and closes the repair window exactly as always, but the planet-
        // wide build does NOT start. The operator maps manually
        // (apportionment:seed --jurisdiction=… + the mapper), then the
        // Start-planet-wide-generation control re-hooks the full build via
        // the re-hook branch above.
        $mode = $gate['mode'] ?? 'eager';
        if ($mode !== 'eager' && ! ($gate['rehook'] ?? false)) {
            \Illuminate\Support\Facades\Log::info(sprintf(
                'Map data accepted — mode %s: planet-wide autoscale DEFERRED. '.
                'Open flags at acceptance: %d critical, %d warning, %d info.',
                $mode, $openFlags['critical'], $openFlags['warning'], $openFlags['info'],
            ));

            return response()->json([
                'ok' => true,
                'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
                'open_flags_at_acceptance' => $openFlags,
                'institution_scale_mode' => $mode,
                'autoscale_deferred' => true,
            ]);
        }

        // AUTOSCALE (pull engine, 2026-07-19): acceptance kicks off
        // governance for ALL jurisdictions — sizing every legislature (TRUE
        // ALL SCALE, adm6 villages included) and district-mapping every one
        // (48k mixed-autoseed sweeps + ~903k set-based single-district leaf
        // councils). The run row + items/scopes are the durable state; the
        // scheduler's every-minute pump is the run's liveness root, and the
        // inline pump call below just skips the first minute of waiting.
        try {
            // Accept → reopen → repairs → accept-again must not mint a SECOND
            // run: an unfinished run (paused by reopenMaps' halt) resumes
            // instead. The pump's oldest-wins dedupe backstops the remaining
            // ms-window against a racing CLI start.
            $run = \App\Models\AutoscaleRun::unfinished();
            if ($run === null) {
                $run = \App\Models\AutoscaleRun::create([
                    'status'            => 'queued',
                    'adm_max'           => (int) config('cga.autoscale_adm_max', 6),
                    'initiator_user_id' => $request->user()?->getKey(),
                    'template'          => null, // constitutional default per legislature
                ]);
            } else {
                $run->forceFill(['halt_requested_at' => null])->save();
            }
            \Illuminate\Support\Facades\Artisan::call('autoscale:pump');
        } catch (\Throwable $e) {
            // Don't fail the acceptance — the scheduler's next pump minute
            // starts the run anyway.
            \Illuminate\Support\Facades\Log::warning(
                'Autoscale pump kick failed (acceptance still recorded): '.$e->getMessage()
            );
            $run = null;
        }

        \Illuminate\Support\Facades\Log::info(sprintf(
            'Map data accepted — open geodata flags at acceptance: %d critical, %d warning, %d info.',
            $openFlags['critical'],
            $openFlags['warning'],
            $openFlags['info'],
        ));

        return response()->json([
            'ok' => true,
            'map_accepted_at' => $instance->map_accepted_at->toIso8601String(),
            'open_flags_at_acceptance' => $openFlags,
            'autoscale_run_id' => $run?->id,
        ]);
    }

    /**
     * POST /api/jurisdictions/{jurisdiction}/activate-legislature — the
     * MANUAL-FIRST arc (operator, 2026-08-06): size THIS one jurisdiction's
     * legislature via the cube-root law so the mapper buttons light up —
     * the UI face of `apportionment:seed --jurisdiction=<slug>`, one
     * jurisdiction at a time while the planet-wide build stays un-hooked.
     *
     * Operator-only. Idempotent: an existing legislature short-circuits.
     */
    public function activateLegislature(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        // RECURSIVE (operator, 2026-08-08): this jurisdiction AND its whole
        // subtree, as a queued chunked job — one seed per node, resumable.
        // Big subtrees are refused toward Activate All: the autoscale engine
        // builds those set-based instead of node-by-node.
        if ($request->boolean('recursive')) {
            $count = (int) DB::selectOne(<<<'SQL'
                WITH RECURSIVE t AS (
                    SELECT id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                    UNION ALL
                    SELECT c.id FROM jurisdictions c JOIN t ON c.parent_id = t.id
                     WHERE c.deleted_at IS NULL
                )
                SELECT count(*) AS n FROM t
            SQL, [$jurisdiction->id])->n;

            $max = (int) config('cga.activate_recursive_max', 5000);
            if ($count > $max) {
                return response()->json([
                    'ok' => false,
                    'error' => sprintf(
                        'Subtree holds %s jurisdictions (cap %s) — use Activate All (the planet-wide build) for trees this size.',
                        number_format($count), number_format($max),
                    ),
                ], 422);
            }

            \App\Jobs\ActivateSubtreeJob::dispatch((string) $jurisdiction->id);

            return response()->json([
                'ok' => true, 'queued' => true, 'subtree_count' => $count,
            ]);
        }

        $existing = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->select('id', 'total_seats')
            ->first();
        if ($existing !== null) {
            // HEAL (operator-caught 2026-08-08: seats without the BOOT left
            // the mapper refusing F-ELB-008 — no bootstrap board, no R-08).
            // Re-clicking Activate runs the full WF-JUR-01 activation, which
            // adopts the existing legislature (never resizes) and constitutes
            // the bootstrap board. Idempotent on an already-booted place.
            \Illuminate\Support\Facades\Artisan::call('jurisdiction:activate', [
                'slug' => $jurisdiction->slug, '--force' => true,
            ]);

            return response()->json([
                'ok' => true, 'already_active' => true,
                'legislature_id' => $existing->id,
                'total_seats'    => (int) $existing->total_seats,
                'has_board'      => DB::table('election_boards')
                    ->where('jurisdiction_id', $jurisdiction->id)
                    ->where('status', 'active')->whereNull('deleted_at')->exists(),
            ]);
        }

        $exit = \Illuminate\Support\Facades\Artisan::call('apportionment:seed', [
            '--jurisdiction' => $jurisdiction->slug,
        ]);
        $leg = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->select('id', 'total_seats')
            ->first();
        if ($exit !== 0 || $leg === null) {
            $tail = substr(trim(\Illuminate\Support\Facades\Artisan::output()), -400);
            return response()->json([
                'ok' => false,
                'error' => 'apportionment:seed did not produce a legislature: '.$tail,
            ], 422);
        }

        // THE FULL BOOT (operator-caught 2026-08-08): sizing alone is not
        // activation. WF-JUR-01 adopts the seeded legislature (never
        // resizes) and constitutes the bootstrap election board — the R-08
        // substrate the mapper's F-ELB-008 filing requires (RoleService:
        // the operator holds R-08 while an active bootstrap board exists).
        // Leaves get the clamp posture (no map minted — the manual canvas
        // stays blank); over-ceiling parents ensure an initial map when
        // none exists, the offer-to-generate alternative.
        $bootExit = \Illuminate\Support\Facades\Artisan::call('jurisdiction:activate', [
            'slug' => $jurisdiction->slug, '--force' => true,
        ]);
        if ($bootExit !== 0) {
            \Illuminate\Support\Facades\Log::warning(sprintf(
                'Activate %s: seed OK but activation exited %d — %s',
                $jurisdiction->slug, $bootExit,
                substr(trim(\Illuminate\Support\Facades\Artisan::output()), -300),
            ));
        }

        $hasBoard = DB::table('election_boards')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('status', 'active')->whereNull('deleted_at')->exists();

        \Illuminate\Support\Facades\Log::info(sprintf(
            'Manual legislature activation — %s (%s): legislature %s, %d seats, board %s.',
            $jurisdiction->name, $jurisdiction->slug, $leg->id, (int) $leg->total_seats,
            $hasBoard ? 'constituted' : 'MISSING',
        ));

        return response()->json([
            'ok' => true,
            'legislature_id' => $leg->id,
            'total_seats'    => (int) $leg->total_seats,
            'has_board'      => $hasBoard,
        ]);
    }

    /**
     * POST /api/jurisdictions/finish-activations — boot every half-activated
     * jurisdiction (legislature, no election board) in one queued pass.
     * Operator-only; idempotent; safe to re-run.
     */
    public function finishActivations(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $n = $this->halfActivatedCount();
        if ($n === 0) {
            return response()->json(['ok' => true, 'queued' => false, 'count' => 0]);
        }

        \App\Jobs\FinishActivationsJob::dispatch();

        return response()->json(['ok' => true, 'queued' => true, 'count' => $n]);
    }

    /** Jurisdictions holding a legislature but no active election board. */
    private function halfActivatedCount(): int
    {
        return DB::table('jurisdictions as j')
            ->join('legislatures as l', function ($join) {
                $join->on('l.jurisdiction_id', '=', 'j.id')->whereNull('l.deleted_at');
            })
            ->whereNull('j.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('election_boards as eb')
                ->whereColumn('eb.jurisdiction_id', 'j.id')
                ->where('eb.status', 'active')->whereNull('eb.deleted_at'))
            ->count();
    }

    /**
     * POST /api/jurisdictions/{jurisdiction}/simulate — the narrow co-test
     * (operator, 2026-08-08): simulate THIS jurisdiction's subtree — people,
     * elections, seated chambers, committees, courts, census civics — after
     * its map is drawn. Operator-only, sandbox worlds only, and the place
     * must be activated first (the sim elects into chambers that exist).
     * Queued: enumeration is bulk work.
     */
    public function simulateJurisdiction(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $instance = \App\Models\InstanceSettings::query()->whereNull('deleted_at')->first();
        if ($instance === null || $instance->game_mode !== 'sandbox') {
            return response()->json([
                'ok' => false,
                'error' => 'Simulation runs only on a sandbox world (game_mode).',
            ], 422);
        }

        $hasLegislature = DB::table('legislatures')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->whereNull('deleted_at')
            ->exists();
        if (! $hasLegislature) {
            return response()->json([
                'ok' => false,
                'error' => 'Activate this jurisdiction first — the sim elects into chambers that exist.',
            ], 422);
        }

        // THE MAP GATE (operator, 2026-08-08): elections need districts to
        // elect FROM — simulating a chamber with no active district map would
        // have no races to run. Draw one (Districts →) or autoseed it in the
        // mapper first.
        $hasActiveMap = DB::table('legislature_district_maps')
            ->join('legislatures', 'legislatures.id', '=', 'legislature_district_maps.legislature_id')
            ->where('legislatures.jurisdiction_id', $jurisdiction->id)
            ->whereNull('legislatures.deleted_at')
            ->where('legislature_district_maps.status', 'active')
            ->exists();
        if (! $hasActiveMap) {
            return response()->json([
                'ok' => false,
                'error' => 'No active district map — open Districts → and draw or autoseed one first. Elections need districts to elect from.',
            ], 422);
        }

        \App\Jobs\SimulateJurisdictionJob::dispatch((string) $jurisdiction->slug);

        return response()->json(['ok' => true, 'queued' => true]);
    }

    /**
     * POST /api/jurisdictions/reopen-maps — clear map_accepted_at so the
     * geodata repair window reopens. Only legal while instance setup is
     * still incomplete: once setup_completed_at is stamped the accepted
     * dataset is the constitutional substrate and the gate locks for good.
     *
     * Idempotent: reopening an already-open gate is a no-op success.
     */
    public function reopenMaps(Request $request): JsonResponse
    {
        // Operator-only, like acceptMaps and the repair POSTs — this swings
        // the repair-window gate open.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $instance = \App\Models\InstanceSettings::current();

        if ($instance->isSetupComplete()) {
            return response()->json([
                'ok' => false,
                'error' => 'Setup is complete — the accepted map data is locked and cannot be reopened.',
            ], 403);
        }

        if ($instance->map_accepted_at === null) {
            return response()->json(['ok' => true, 'already_open' => true]);
        }

        $instance->forceFill(['map_accepted_at' => null])->save();

        // Reopening the repair window PAUSES a live autoscale run: repairs
        // merge/soft-delete jurisdictions, and sizing/sweeps racing that
        // would build on rows the operator is retiring. The next acceptance
        // clears the flag and resumes the run.
        $halted = false;
        $unfinished = \App\Models\AutoscaleRun::unfinished();
        if ($unfinished !== null) {
            $unfinished->forceFill(['halt_requested_at' => now()])->save();
            try {
                \Illuminate\Support\Facades\Artisan::call('autoscale:pump'); // park it now
            } catch (\Throwable) {
                // The scheduler's next pump minute parks it anyway.
            }
            $halted = true;
        }

        \Illuminate\Support\Facades\Log::info('Map acceptance reopened — the geodata repair window is open again.'
            . ($halted ? ' (live autoscale run signalled to halt)' : ''));

        return response()->json(['ok' => true, 'autoscale_halted' => $halted]);
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
