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
     * AUTOSCALE run (AutoscaleOrchestratorJob): every jurisdiction gets a
     * sized legislature and a founding district map.
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
                    $heartbeatFresh = $unfinished->updated_at !== null
                        && $unfinished->updated_at->gt(now()->subMinutes(15));
                    if ($unfinished->status !== 'halted' && $heartbeatFresh) {
                        return ['response' => response()->json([
                            'ok' => true,
                            'already_accepted' => true,
                            'autoscale_run_id' => (string) $unfinished->id,
                            'autoscale_status' => $unfinished->status,
                        ])];
                    }
                    \Illuminate\Support\Facades\Cache::forget(\App\Models\AutoscaleRun::HALT_CACHE_KEY);
                    \App\Jobs\AutoscaleOrchestratorJob::dispatch((string) $unfinished->id);
                    return ['response' => response()->json([
                        'ok' => true,
                        'already_accepted' => true,
                        'autoscale_resumed' => true,
                        'autoscale_run_id' => (string) $unfinished->id,
                    ])];
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

            $instance->forceFill([
                'map_accepted_at' => now(),
                'setup_step_completed' => max((int) $instance->setup_step_completed, 2),
            ])->save();

            return ['instance' => $instance, 'open_flags' => $openFlags];
        });

        if (isset($gate['response'])) {
            return $gate['response'];
        }
        $instance  = $gate['instance'];
        $openFlags = $gate['open_flags'];

        // AUTOSCALE (operator ruling 2026-07-18): acceptance kicks off
        // governance for ALL jurisdictions — the orchestrator sizes every
        // legislature (TRUE ALL SCALE, adm6 villages included) and
        // district-maps every one (48k mixed-autoseed sweeps + ~903k
        // set-based single-district leaf councils). This replaces the old
        // bare Artisan::queue('apportionment:seed') dispatch, which (a) only
        // seeded the planet root's direct children and (b) rode the DEFAULT
        // queue whose 60 s timeout would SIGKILL any full-scale run.
        //
        // The run row + autoscale_items are the durable state: the Step-3
        // dashboard polls it, and a re-POST here resumes an interrupted run.
        try {
            // Accept → reopen → repairs → accept-again must not mint a SECOND
            // run: two runs' waves would concurrently _all-sweep the same
            // Founding Maps. An unfinished run (paused by reopenMaps' halt)
            // resumes instead. The orchestrator's newest-yields dedupe
            // backstops the remaining ms-window against a racing CLI start.
            $run = \App\Models\AutoscaleRun::unfinished();
            if ($run === null) {
                $run = \App\Models\AutoscaleRun::create([
                    'status'            => 'queued',
                    'adm_max'           => (int) config('cga.autoscale_adm_max', 6),
                    'initiator_user_id' => $request->user()?->getKey(),
                    'template'          => null, // constitutional default per legislature
                ]);
            }
            \Illuminate\Support\Facades\Cache::forget(\App\Models\AutoscaleRun::HALT_CACHE_KEY);
            \App\Jobs\AutoscaleOrchestratorJob::dispatch((string) $run->id);
        } catch (\Throwable $e) {
            // Don't fail the acceptance — the operator can start the run
            // manually (`php artisan districting:autoscale`) if Horizon is down.
            \Illuminate\Support\Facades\Log::warning(
                'Autoscale dispatch failed (acceptance still recorded): '.$e->getMessage()
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
        if (\App\Models\AutoscaleRun::unfinished() !== null) {
            \Illuminate\Support\Facades\Cache::put(\App\Models\AutoscaleRun::HALT_CACHE_KEY, true, 86400);
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
