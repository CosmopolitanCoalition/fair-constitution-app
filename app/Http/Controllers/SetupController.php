<?php

namespace App\Http\Controllers;

use App\Jobs\PrewarmRasterTilesJob;
use App\Models\CosmicAddress;
use App\Models\InstanceSettings;
use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup wizard — WordPress-style install flow that takes a fresh instance
 * from `docker compose up` through a configured Earth legislature.
 *
 * Step 0 — Welcome + Cosmic Address (map mode, time mode, instance name)
 * Step 1 — Per Jurisdiction Constitutional Defaults (founder authors the constitution)
 * Step 2 — Load GeoBoundaries + WorldPop Data (apportionment fires on activation)
 * Step 3 — Build Districts (handoff to existing district mapper)
 * Step 4 — Confirm + Seat Institutions (executives + judiciaries scaffolded,
 *          setup_completed_at set, "Ready Player One" landing message)
 *
 * Ordering note: Constitutional Defaults runs BEFORE Map Data so apportionment
 * can execute as soon as data injection completes. When the founder submits
 * defaults, the planet row (adm_level = 0) may not exist yet — in that case
 * the payload is stashed on instance_settings.pending_constitutional_defaults
 * and applied when Map Data activation resolves. Apportionment runs synchronously
 * inside activateStep1 via the apportionment:seed Artisan command.
 */
class SetupController extends Controller
{
    /**
     * Router entry point. Sends the user to the highest step they haven't
     * completed. If setup is done, redirects home.
     *
     * Phase M: also gates on schema readiness — if migrations haven't been
     * applied yet, redirect to /setup/bootstrap so the operator can run
     * them from the UI before any constitutional setup.
     */
    public function index(): RedirectResponse
    {
        if ($this->needsBootstrap()) {
            return redirect('/setup/bootstrap');
        }

        $settings = InstanceSettings::current();

        if ($settings->isSetupComplete()) {
            return redirect('/');
        }

        // Convention: setup_step_completed = n  →  steps 0..n-1 done, next is step n.
        $next = min(4, max(0, (int) $settings->setup_step_completed));

        return redirect("/setup/step/{$next}");
    }

    /**
     * Render a specific wizard step. Each page has its own Vue component.
     *
     * Phase M: schema-readiness gate — same as index(). Stops the user from
     * landing on a 500 error when tables don't exist yet.
     */
    public function step(int $n): Response|RedirectResponse
    {
        if ($this->needsBootstrap()) {
            return redirect('/setup/bootstrap');
        }

        if ($n < 0 || $n > 4) {
            return redirect('/setup');
        }

        $settings = InstanceSettings::current();

        // Gate forward progression: step n is reachable iff steps 0..n-1 are done.
        if ((int) $settings->setup_step_completed < $n) {
            return redirect('/setup');
        }

        $pages = [
            0 => 'Setup/Step0_CosmicAddress',
            1 => 'Setup/Step1_Constants',
            2 => 'Setup/Step2_MapData',
            3 => 'Setup/Step3_Districts',
            4 => 'Setup/Step4_Confirm',
        ];

        $extra = [];
        if ($n === 1) {
            // Seed the Step 1 form with the operator's previously-saved values
            // so revisits show the actual saved state, not the template defaults.
            // Priority: live constitutional_settings on the planet row
            //   → pending_constitutional_defaults stash (pre-map-data)
            //   → Fair Constitution template defaults.
            $extra['constants'] = $this->currentConstitutionalDefaults($settings);
        }

        if ($n === 3) {
            $root = $this->resolveRootJurisdiction();
            $extra['root_jurisdiction'] = $root ? [
                'id'   => $root->id,
                'name' => $root->name,
                'slug' => $root->slug ?? null,   // canonical legislature address
            ] : null;
            $extra['root_legislature_id'] = $root
                ? DB::table('legislatures')
                    ->where('jurisdiction_id', $root->id)
                    ->whereNull('deleted_at')
                    ->value('id')
                : null;
        }

        if ($n === 4) {
            $extra['summary'] = $this->buildStep4Summary();
            // Note: data-quality review lives in Step 2 (post-ETL,
            // pre-apportionment). Step 4 has nothing to review beyond
            // confirming institutions can be seated. The review snapshot
            // still gets captured into setup_completion_notes when the
            // operator clicks Finish — see completeStep4().
        }

        return Inertia::render($pages[$n], array_merge([
            'step'     => $n,
            'settings' => $this->serializeSettings($settings),
        ], $extra));
    }

    /**
     * GET /api/setup/state — used by Home.vue + AppLayout.vue to decide
     * whether to redirect into the wizard / hide nav.
     */
    public function state(): JsonResponse
    {
        $settings = InstanceSettings::current();
        return response()->json([
            'settings' => $this->serializeSettings($settings),
            'complete' => $settings->isSetupComplete(),
        ]);
    }

    // ─── Phase M — WordPress-style self-bootstrap ─────────────────────────────
    //
    // The wizard handles its own schema management. On a fresh git clone +
    // docker compose up, the user lands on /setup/bootstrap (gated by
    // needsBootstrap() in index() / step()) where they apply migrations and
    // create the founder account, then proceed into the rest of the wizard.
    // After the initial install the same page handles delta migrations from
    // future code drops — surfaced as a banner everywhere via SchemaUpdateBanner.

    /**
     * GET /setup/bootstrap — render the install / update page.
     *
     * Always reachable, even when the schema is empty (we don't touch any
     * Eloquent models that depend on un-migrated tables).
     */
    public function bootstrapPage(): Response
    {
        return Inertia::render('Setup/Bootstrap', [
            'status' => $this->bootstrapStatus(),
        ]);
    }

    /**
     * GET /api/setup/bootstrap/status — schema + founder readiness snapshot.
     *
     * Frontend polls this both on the bootstrap page and from the global
     * SchemaUpdateBanner. Cheap (~5 ms): one Schema::hasTable + one query
     * against the migrations table.
     */
    public function bootstrapStatusEndpoint(): JsonResponse
    {
        return response()->json($this->bootstrapStatus());
    }

    /**
     * POST /api/setup/bootstrap/migrate — apply pending migrations.
     *
     * Synchronous: the existing migration suite runs in seconds. If we ever
     * land a long backfill we'll move that specific migration to a queued job;
     * the rest stay on the simple Artisan::call path used elsewhere in this
     * controller (e.g. apportionment:seed).
     */
    public function runMigrations(): JsonResponse
    {
        // Refuse to migrate during an ETL run — schema changes mid-load would
        // break in-flight queries against tables the ETL is writing to.
        if (is_file($this->etlControlDir().'/running.json')) {
            return response()->json([
                'error' => 'An ETL run is in progress. Wait for it to finish before applying schema updates.',
            ], 409);
        }

        // Lock so two concurrent wizard tabs can't both fire `migrate` at once.
        $lock = Cache::lock('setup:run-migrations', 300);
        if (! $lock->get()) {
            return response()->json([
                'error' => 'A migration run is already in progress.',
            ], 409);
        }

        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            $output   = Artisan::output();

            return response()->json([
                'exit_code' => $exitCode,
                'output'    => $output,
                'status'    => $this->bootstrapStatus(),
            ]);
        } catch (\Throwable $exc) {
            Log::error('Setup bootstrap migrate failed: '.$exc->getMessage(), [
                'exception' => get_class($exc),
            ]);
            return response()->json([
                'exit_code' => 1,
                'output'    => $exc->getMessage(),
                'status'    => $this->bootstrapStatus(),
            ], 500);
        } finally {
            $lock->release();
        }
    }

    /**
     * POST /api/setup/bootstrap/create-founder — create the first user.
     *
     * Idempotent guard: refuses if any User row already exists. The wizard
     * UI hides the form once a user exists, but a hostile client could still
     * POST here directly — the 409 keeps that contained.
     */
    public function createFounder(Request $request): JsonResponse
    {
        if (User::query()->exists()) {
            return response()->json([
                'error' => 'A founder account already exists.',
            ], 409);
        }

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // The founder is the operator account and accepts the terms by
        // creating the instance (WI-3 users schema: terms_accepted_at is
        // NOT NULL, is_operator unlocks dev tooling like impersonation).
        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $founder = User::create([
                'name'              => $data['name'],
                'email'             => $data['email'],
                'password'          => Hash::make($data['password']),
                'terms_accepted_at' => now(),
                'is_operator'       => true,
            ]);

            // G-OP: the founder is ALSO the first OPERATOR — a separate plane (no
            // FK to `users`, its own auth:operator guard). Reuses the founder's
            // email + password for the local operator login; mesh-linking is
            // opt-in later. Created in the same transaction as the citizen row.
            app(\App\Services\Identity\OperatorIdentityService::class)
                ->register($data['email'], $data['password']);

            return $founder;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'status' => $this->bootstrapStatus(),
        ]);
    }

    /**
     * Schema + founder readiness snapshot, used by the bootstrap page,
     * the global SchemaUpdateBanner, and internal gates.
     *
     * @return array{
     *   schema_state:string,
     *   pending_migrations:array<int,string>,
     *   pending_count:int,
     *   has_founder:bool,
     *   etl_running:bool,
     *   ready:bool
     * }
     */
    private function bootstrapStatus(): array
    {
        // Schema state: classify the DB into one of three states.
        // - 'uninitialised': not even the migrations table exists yet
        // - 'pending':       table(s) exist, but new migrations are on disk
        // - 'up_to_date':    every migration on disk is recorded as applied
        $schemaState = 'up_to_date';
        $pending     = [];

        if (! Schema::hasTable('migrations')) {
            $schemaState = 'uninitialised';
            // List every migration on disk so the UI can show "N to apply".
            $files = glob(database_path('migrations').'/*.php') ?: [];
            $pending = array_values(array_map(
                fn ($p) => pathinfo($p, PATHINFO_FILENAME),
                $files,
            ));
            sort($pending);
        } else {
            // Migrator service exposes the same applied/on-disk diff that
            // `php artisan migrate:status` uses internally.
            $migrator = app('migrator');
            $migrator->setConnection(config('database.default'));

            $applied = $migrator->getRepository()->getRan();
            $onDisk  = $migrator->getMigrationFiles([database_path('migrations')]);

            // getMigrationFiles() returns ['name' => '/full/path']; we only
            // care about the names. array_diff against the applied list to
            // find what's left to apply.
            $pending = array_values(array_diff(array_keys($onDisk), $applied));
            sort($pending);

            if (! empty($pending)) {
                $schemaState = 'pending';
            }
        }

        // Founder state — kept as informational only. The first user is
        // intentionally NOT created at bootstrap time; per the constitutional
        // model the founder is registered as the FINAL step of the wizard,
        // not the first. This flag is reported for diagnostics / future use
        // but is no longer a precondition for leaving the bootstrap page.
        $hasFounder = false;
        if (Schema::hasTable('users')) {
            $hasFounder = User::query()->exists();
        }

        // ETL running flag — used by the UI to disable the migrate button
        // while a long-running ETL is in flight (schema changes mid-load
        // would corrupt in-flight queries).
        $etlRunning = is_file($this->etlControlDir().'/running.json');

        // Composite "ready to leave bootstrap" flag — only schema readiness
        // gates this. has_founder is reported separately for diagnostics.
        $ready = $schemaState === 'up_to_date';

        return [
            'schema_state'       => $schemaState,
            'pending_migrations' => $pending,
            'pending_count'      => count($pending),
            'has_founder'        => $hasFounder,
            'etl_running'        => $etlRunning,
            'ready'              => $ready,
        ];
    }

    /**
     * Fast pre-check used by index() / step() to decide whether to redirect
     * into /setup/bootstrap. Defensive: any unexpected error (e.g. DB
     * unreachable) is treated as "needs bootstrap" so the user lands on the
     * page that explains what's going on, rather than a Laravel 500.
     *
     * Triggers redirect when the migrations table or instance_settings table
     * doesn't exist, or any migrations are pending. Founder presence is NOT
     * a gate — first-user registration happens at the END of the wizard,
     * not before.
     */
    private function needsBootstrap(): bool
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return true;
            }
            if (! Schema::hasTable('instance_settings')) {
                return true;
            }

            $status = $this->bootstrapStatus();
            return ! $status['ready'];
        } catch (\Throwable $exc) {
            Log::warning('needsBootstrap probe failed: '.$exc->getMessage());
            return true;
        }
    }

    // ─── End of Phase M block ────────────────────────────────────────────────

    /**
     * POST /api/setup/cosmic-address — save Step 0.
     * Writes cosmic_address_id + time_mode + instance_name to the instance_settings
     * singleton, derives map_mode from the cascader path, advances setup_step_completed
     * to 1 ("Step 0 fully completed, next up is Step 1 — Constitutional Defaults").
     */
    public function saveCosmicAddress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_name'               => ['required', 'string', 'max:255'],
            'cosmic_address_id'           => ['required', 'uuid', 'exists:cosmic_addresses,id'],
            'time_mode'                   => ['required', Rule::in(['real', 'accelerated'])],
            'time_scale_seconds_per_year' => ['nullable', 'integer', 'min:1'],
        ]);

        $addr = CosmicAddress::findOrFail($data['cosmic_address_id']);
        $mapMode = $this->resolveMapMode($addr);

        // v1: only physical_earth is fully supported. Others are UI-disabled
        // via cosmic_addresses.enabled; we guard the endpoint too so future
        // unlocks go through a real code change, not a rogue payload.
        if ($mapMode !== 'physical_earth') {
            return response()->json([
                'error' => 'Only physical_earth instances are supported in this version.',
            ], 422);
        }

        if ($addr->type !== 'world') {
            return response()->json([
                'error' => 'cosmic_address_id must reference a world-level node.',
            ], 422);
        }

        if ($data['time_mode'] === 'accelerated' && empty($data['time_scale_seconds_per_year'])) {
            return response()->json([
                'error' => 'time_scale_seconds_per_year is required in accelerated mode.',
            ], 422);
        }

        $settings = InstanceSettings::current();
        $settings->fill([
            'instance_name'               => $data['instance_name'],
            'cosmic_address_id'           => $data['cosmic_address_id'],
            'map_mode'                    => $mapMode,
            'time_mode'                   => $data['time_mode'],
            'time_scale_seconds_per_year' => $data['time_mode'] === 'accelerated'
                ? (int) $data['time_scale_seconds_per_year']
                : null,
            'setup_step_completed'        => max((int) $settings->setup_step_completed, 1),
        ])->save();

        return response()->json([
            'settings' => $this->serializeSettings($settings->fresh()),
            'next'     => '/setup/step/1',
        ]);
    }

    /**
     * Derive map_mode from the cosmic-address chain. The cascader position
     * encodes the spatial model — there's no longer a separate radio selector.
     *
     *   no_map universe              → no_map
     *   world = Earth                → physical_earth
     *   world = other observable     → elsewhere
     */
    private function resolveMapMode(CosmicAddress $addr): string
    {
        $chain = $addr->pathFromRoot();
        $universe = collect($chain)->firstWhere(
            fn ($row) => in_array($row['type'] ?? null, ['observable_universe', 'no_map'], true)
        );

        if (($universe['type'] ?? null) === 'no_map') {
            return 'no_map';
        }

        if ($addr->type === 'world' && $addr->slug === 'earth') {
            return 'physical_earth';
        }

        return 'elsewhere';
    }

    /**
     * POST /api/setup/constants — save Step 1.
     *
     * The founder is authoring the instance's constitution here. Values are
     * NOT locked against Fair Constitution Template defaults — Template values
     * are shown as "defaults of defaults" in the UI. Only logical invariants
     * (supermajority > 1/2, min ≤ max, positive durations) are enforced.
     *
     * If the planet row (adm_level = 0) exists (Map Data already loaded): write
     * the constitutional_settings row immediately. Otherwise stash the payload
     * on instance_settings and let activateMapData apply it once the planet
     * row lands.
     */
    public function saveConstants(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legislature_min_seats'             => ['required', 'integer', 'min:1'],
            'legislature_max_seats'             => ['required', 'integer', 'min:1'],
            'legislature_sizing_law'            => ['required', Rule::in(['cube_root'])],
            'election_interval_months'          => ['required', 'integer', 'min:1', 'max:1200'],
            'voting_method'                     => ['required', Rule::in(['stv_droop'])],
            'special_election_min_days'         => ['required', 'integer', 'min:1'],
            'special_election_max_days'         => ['required', 'integer', 'min:1'],
            'supermajority_numerator'           => ['required', 'integer', 'min:1'],
            'supermajority_denominator'         => ['required', 'integer', 'min:2'],
            'max_days_between_meetings'         => ['required', 'integer', 'min:1'],
            'emergency_powers_max_days'         => ['required', 'integer', 'min:1'],
            'civil_appointment_years'           => ['required', 'integer', 'min:1'],
            'judicial_appointment_years'        => ['required', 'integer', 'min:1'],
            'judiciary_min_judges_per_race'     => ['required', 'integer', 'min:1'],
            'judiciary_is_elected'              => ['required', 'boolean'],
            'worker_rep_min_employees'          => ['required', 'integer', 'min:1'],
            'worker_rep_parity_employees'       => ['required', 'integer', 'min:1'],
            'residency_confirmation_days'       => ['required', 'integer', 'min:1'],
            'initiative_petition_threshold_pct' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        // Logical invariants that aren't amendable by any legislative act.
        if (($data['supermajority_numerator'] / $data['supermajority_denominator']) <= 0.5) {
            return response()->json(['error' => 'supermajority must exceed 1/2.'], 422);
        }
        if ($data['legislature_max_seats'] < $data['legislature_min_seats']) {
            return response()->json(['error' => 'legislature_max_seats must be ≥ legislature_min_seats.'], 422);
        }
        if ($data['special_election_max_days'] < $data['special_election_min_days']) {
            return response()->json(['error' => 'special_election_max_days must be ≥ special_election_min_days.'], 422);
        }
        if ($data['worker_rep_parity_employees'] < $data['worker_rep_min_employees']) {
            return response()->json(['error' => 'worker_rep_parity_employees must be ≥ worker_rep_min_employees.'], 422);
        }

        $settings = InstanceSettings::current();
        $root     = $this->resolveRootJurisdiction();

        if ($root) {
            // Map data already loaded — write directly.
            $this->writeConstitutionalSettings($root->id, $data);
            $settings->pending_constitutional_defaults = null;
        } else {
            // No planet row yet — stash for activateMapData to pick up later.
            $settings->pending_constitutional_defaults = $data;
        }

        $settings->setup_step_completed = max((int) $settings->setup_step_completed, 2);
        $settings->save();

        \App\Services\ConstitutionalDefaults::flush();

        return response()->json([
            'settings' => $this->serializeSettings($settings->fresh()),
            'next'     => '/setup/step/2',
        ]);
    }

    /**
     * Read the current constitutional defaults for the Step 1 form.
     *
     * Three sources, in priority order:
     *   1. The planet row's `constitutional_settings` (post-Map-Data state)
     *   2. `instance_settings.pending_constitutional_defaults` stash (pre-Map-Data:
     *      Step 1 saved values that activateStep1 will apply when the planet
     *      row eventually exists)
     *   3. Fair Constitution Template defaults (fresh wizard, no edits yet)
     *
     * Returned shape matches the saveConstants() request payload so the Vue
     * form can plug it straight into its refs.
     */
    private function currentConstitutionalDefaults(InstanceSettings $settings): array
    {
        $defaults = [
            'legislature_min_seats'             => 5,
            'legislature_max_seats'             => 9,
            'legislature_sizing_law'            => 'cube_root',
            'election_interval_months'          => 60,
            'voting_method'                     => 'stv_droop',
            'special_election_min_days'         => 90,
            'special_election_max_days'         => 180,
            'supermajority_numerator'           => 2,
            'supermajority_denominator'         => 3,
            'max_days_between_meetings'         => 90,
            'emergency_powers_max_days'         => 90,
            'civil_appointment_years'           => 10,
            'judicial_appointment_years'        => 10,
            'judiciary_min_judges_per_race'     => 5,
            'judiciary_is_elected'              => false,
            'worker_rep_min_employees'          => 100,
            'worker_rep_parity_employees'       => 2000,
            'residency_confirmation_days'       => 30,
            'initiative_petition_threshold_pct' => 5.00,
        ];

        $root = $this->resolveRootJurisdiction();
        if ($root) {
            $row = DB::table('constitutional_settings')
                ->where('jurisdiction_id', $root->id)
                ->first();
            if ($row) {
                foreach (array_keys($defaults) as $k) {
                    if (property_exists($row, $k) && $row->$k !== null) {
                        // Cast to match the form-input types so Vue's number
                        // inputs don't coerce a string "5" into the numeric 5
                        // each refocus.
                        $defaults[$k] = match ($k) {
                            'judiciary_is_elected'              => (bool) $row->$k,
                            'initiative_petition_threshold_pct' => (float) $row->$k,
                            'legislature_sizing_law',
                            'voting_method'                     => (string) $row->$k,
                            default                             => (int) $row->$k,
                        };
                    }
                }
                return $defaults;
            }
        }

        // No planet row yet — fall through to the stash if one exists.
        $pending = $settings->pending_constitutional_defaults;
        if (is_array($pending)) {
            return array_merge($defaults, $pending);
        }

        return $defaults;
    }

    /**
     * Upsert the constitutional_settings row for a jurisdiction.
     */
    private function writeConstitutionalSettings(string $jurisdictionId, array $data): void
    {
        $now      = now();
        $existing = DB::table('constitutional_settings')
            ->where('jurisdiction_id', $jurisdictionId)
            ->first();

        $payload = array_merge($data, [
            'jurisdiction_id' => $jurisdictionId,
            'updated_at'      => $now,
        ]);

        if ($existing) {
            DB::table('constitutional_settings')
                ->where('id', $existing->id)
                ->update($payload);
        } else {
            DB::table('constitutional_settings')->insert(array_merge($payload, [
                'id'         => (string) Str::uuid(),
                'created_at' => $now,
            ]));
        }
    }

    /**
     * POST /api/setup/wizard/step1/detect — classify the current map-data
     * state so the wizard can decide what UI to show.
     *
     * Returns one of: EMPTY | ADM0_ONLY | PARTIAL | FULLY_LOADED | IN_PROGRESS.
     * Includes live ETL run state from the supervisor control files.
     *
     * State naming note — "ADM0_ONLY" here is a legacy state identifier meaning
     * "only the planet row (adm_level = 0, Earth) exists — no countries loaded
     * yet." The value is kept as-is so the wizard frontend doesn't churn.
     */
    public function detectStep1(): JsonResponse
    {
        $counts = $this->jurisdictionsCounts();

        $running = $this->readEtlControlFile('running.json');
        $state   = 'EMPTY';
        if ($running !== null) {
            $state = 'IN_PROGRESS';
        } elseif ($counts['adm0'] > 0 && $counts['adm1'] === 0 && $counts['adm2'] === 0) {
            $state = 'ADM0_ONLY';
        } elseif ($counts['adm0'] > 0 && ($counts['adm1'] > 0 || $counts['adm2'] > 0)) {
            $state = 'FULLY_LOADED';
        }

        return response()->json([
            'state'  => $state,
            'counts' => $counts,
        ]);
    }

    /**
     * POST /api/setup/wizard/step2/start — submit an ETL job to the supervisor.
     *
     * Writes /etl/control/request.json with the requested options. The
     * supervisor (running inside the etl container) polls that path and
     * launches seed_database.py. Rejects if a run is already in flight.
     */
    public function startMapData(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Phase P.8: source kinds.
            //   'archive'  — bind-mounted /archive (current default)
            //   'folder'   — operator-supplied path inside the ETL container
            //                (typically a sub-folder of /archive). Validated
            //                to be a non-empty absolute path; existence is
            //                checked at run-time by the supervisor.
            //   'download' — placeholder for fetch-from-URL (not wired yet).
            //   'upload'   — placeholder for browser-upload (not wired yet).
            'source'              => ['required', Rule::in(['archive', 'folder', 'download', 'upload'])],
            'data_root'           => ['nullable', 'string', 'max:512'],
            'skip_population'     => ['nullable', 'boolean'],
            'fresh'               => ['nullable', 'boolean'],
            // Both names accepted: pause_on_exception is the new wizard label,
            // stop_on_exception is kept so any older client form values keep
            // working through the rename. Either truthy → pause-and-ask flow.
            'pause_on_exception'  => ['nullable', 'boolean'],
            'stop_on_exception'   => ['nullable', 'boolean'],
            'countries'           => ['nullable', 'array'],
            'countries.*'         => ['string', 'size:3'],
            'adm_levels'          => ['nullable', 'array'],
            'adm_levels.*'        => ['integer', 'min:0', 'max:5'],
        ]);

        $isFresh = (bool) ($data['fresh'] ?? false);

        // Coalesce both flags into a single canonical pause_on_exception.
        $pauseOnException = (bool) (($data['pause_on_exception'] ?? false)
            || ($data['stop_on_exception'] ?? false));

        // P.8 — only `archive` and `folder` are wired today. URL download and
        // browser upload remain placeholders the picker exposes for forward
        // compatibility but the backend rejects until pre-fetch / upload
        // handlers ship.
        if ($data['source'] === 'download') {
            return response()->json([
                'error' => 'Fresh download is not yet wired. Use the local archive or a custom folder for now.',
            ], 422);
        }
        if ($data['source'] === 'upload') {
            return response()->json([
                'error' => 'Browser upload is not yet wired. Use the local archive or a custom folder for now.',
            ], 422);
        }

        // For `folder`, validate the data_root: must look like an absolute
        // path. Existence is verified by the ETL at run-time (we can't always
        // see container paths from the Laravel host).
        $dataRoot = null;
        if ($data['source'] === 'folder') {
            $dataRoot = trim((string) ($data['data_root'] ?? ''));
            if ($dataRoot === '' || $dataRoot[0] !== '/') {
                return response()->json([
                    'error' => 'Custom data root must be an absolute container path (e.g. /archive/snapshots/2026-05).',
                ], 422);
            }
        }

        $controlDir = $this->etlControlDir();
        if (! is_dir($controlDir) && ! @mkdir($controlDir, 0777, true)) {
            return response()->json(['error' => 'Could not create ETL control directory.'], 500);
        }

        if (is_file($controlDir.'/running.json')) {
            return response()->json(['error' => 'An ETL run is already in progress.'], 409);
        }

        // Phase M: refuse to start an ETL while schema updates are pending.
        // Mirror of the gate inside runMigrations() that refuses to migrate
        // during an ETL — never let the two collide. Also catches the case
        // where someone applied a partial schema and forgot to finish.
        $bootstrap = $this->bootstrapStatus();
        if (! empty($bootstrap['pending_migrations'])) {
            return response()->json([
                'error' => 'Schema updates are pending. Apply them at /setup/bootstrap before starting an ETL run.',
                'pending_count' => $bootstrap['pending_count'],
            ], 409);
        }

        $payload = [
            'submitted_at' => now()->toIso8601String(),
            'source'       => $data['source'],
            'options'      => [
                'fresh'              => $isFresh,
                'resume'             => ! $isFresh,
                'skip_population'    => (bool)  ($data['skip_population'] ?? false),
                'pause_on_exception' => $pauseOnException,
                'countries'          => array_values($data['countries']   ?? []),
                'adm_levels'         => array_values($data['adm_levels']  ?? []),
                // P.8 — supervisor.py forwards as `--data-root <path>` to
                // seed_database.py. Null when source=archive (the default
                // /archive bind mount stays in effect).
                'data_root'          => $dataRoot,
            ],
        ];

        // Clear the legacy jurisdiction-raster preview cache. The
        // per-jurisdiction ImageOverlay endpoint that wrote into this dir was
        // retired when RasterTileController took over; the directory just
        // holds leftover PNGs from prior runs. Harmless to keep but tidier
        // to clear so the storage tree stays accurate.
        $previewDir = storage_path('app/public/jurisdiction-previews');
        if (is_dir($previewDir)) {
            foreach (glob($previewDir.'/*.png') ?: [] as $png) {
                @unlink($png);
            }
        }

        // Tile cache invalidation on Fresh. The WorldPop raster TileLayer
        // (served by RasterTileController) caches each generated tile at
        // storage/app/tile-cache/{z}/{x}/{y}.png. Tiles are deterministic
        // given the contents of worldpop_rasters — and Fresh wipes that
        // table inside seed_database.py's purge_geoboundaries_data() — so
        // we wipe the disk cache alongside to ensure tiles served after
        // the next ETL reflect the new data, not the prior run's pixels.
        // Resume runs keep their cache (worldpop_rasters contents are
        // additive, not replaced).
        if ($isFresh) {
            $tileCacheDir = storage_path('app/tile-cache');
            if (is_dir($tileCacheDir)) {
                $cleared = 0;
                $rii = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tileCacheDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($rii as $f) {
                    if ($f->isFile() && $f->getExtension() === 'png') {
                        @unlink($f->getPathname());
                        $cleared++;
                    } elseif ($f->isDir()) {
                        @rmdir($f->getPathname());
                    }
                }
                \Illuminate\Support\Facades\Log::info("Fresh: cleared {$cleared} cached raster tiles.");
            }
        }

        // Phase L: clear the cache-warmup sentinel so the post-ETL warmup runs
        // again at the end of this fresh run. The viewer caches themselves
        // remain valid until a tagged invalidation fires (district edits,
        // explicit cache flush) — we just need to re-warm the most-visited
        // entry points so the first user after this ETL hits warm cache.
        @unlink($controlDir.'/caches_warmed.json');

        // Phase T.2: same idea for the raster-tile pre-warm dispatch
        // sentinel. The tile cache itself was already wiped above when
        // --fresh is set; clearing the sentinel ensures dispatchRasterPrewarmIfNeeded
        // re-fires at the end of this new fresh run.
        @unlink($controlDir.'/raster_prewarm_dispatched.json');

        // mapDataProgress caches DataReviewService::summary() under this key
        // (5 min TTL) so routine /progress polls don't redo the 14-second
        // aggregate after the ETL finishes. A new run is about to mutate the
        // jurisdictions table, so the cached summary is stale — drop it now
        // and let the next ?include=review request rebuild against the new
        // state.
        Cache::forget('setup.review.summary');

        file_put_contents(
            $controlDir.'/request.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return response()->json(['accepted' => true, 'request' => $payload]);
    }

    /**
     * POST /api/setup/wizard/step2/control — halt / pause / resume the ETL run.
     *
     * Writes a sentinel file under /etl/control/ that the supervisor consumes
     * on its next poll tick (≤ POLL_SECONDS). Rejects if no run is in flight.
     *
     *   halt          → supervisor sends SIGTERM; run exits, failed.json has halted=true
     *   pause         → supervisor sends SIGSTOP; child frozen, memory + DB conn held
     *   resume        → supervisor sends SIGCONT
     *
     * Error-pause resolutions (these resolve a paused_on_error.json by writing
     * error_resolution.json — the ETL child process polls and resumes on its own,
     * the supervisor is NOT involved):
     *
     *   error_skip    → ETL marks current country as skipped, moves to next
     *   error_retry   → ETL re-runs the same country
     *   error_abort   → ETL exits with code 2, run ends
     */
    public function controlMapData(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in([
                'halt', 'pause', 'resume',
                'error_skip', 'error_retry', 'error_abort',
            ])],
        ]);

        $controlDir = $this->etlControlDir();
        if (! is_file($controlDir.'/running.json')) {
            return response()->json(['error' => 'No ETL run is in progress.'], 409);
        }

        // Error-resolution actions: write error_resolution.json with the
        // operator's choice. The ETL child process is polling for this file.
        if (str_starts_with($data['action'], 'error_')) {
            $resolution = match ($data['action']) {
                'error_skip'  => 'skip',
                'error_retry' => 'retry',
                'error_abort' => 'abort',
            };
            $payload = [
                'action'       => $resolution,
                'requested_at' => now()->toIso8601String(),
            ];
            $written = @file_put_contents(
                $controlDir.'/error_resolution.json',
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            if ($written === false) {
                return response()->json(['error' => 'Could not write error_resolution.json.'], 500);
            }
            return response()->json(['accepted' => true, 'action' => $data['action']]);
        }

        // Standard pause/resume/halt control file.
        $file = match ($data['action']) {
            'halt'   => 'halt.request',
            'pause'  => 'pause.request',
            'resume' => 'resume.request',
        };

        $payload = ['requested_at' => now()->toIso8601String()];
        $written = @file_put_contents(
            $controlDir.'/'.$file,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        if ($written === false) {
            return response()->json(['error' => 'Could not write control file.'], 500);
        }

        return response()->json(['accepted' => true, 'action' => $data['action']]);
    }

    /**
     * GET /api/setup/wizard/step2/progress — snapshot of current ETL state.
     *
     * Returns: lifecycle (idle | running | done | failed), the parsed
     * progress.json, the tail of etl.log, and ADM-level counts so the
     * UI can render a live dashboard without separate endpoints.
     */
    public function mapDataProgress(Request $request): JsonResponse
    {
        $tailLines    = max(20, min(500, (int) $request->query('tail', 120)));
        $includeDebug = $request->boolean('include_debug');
        // The `review` block runs 6+ aggregate queries over the jurisdictions
        // table (DataReviewService::summary()) and costs 10-15s warm, much
        // more cold. It's only meaningful in terminal lifecycle states, and
        // even then a single fetch per page-load is plenty. The Vue page asks
        // for it once with ?include=review when lifecycle flips to done/failed;
        // routine 2-second polls (during a running ETL) skip it entirely.
        $includeReview = $request->query('include') === 'review';

        $running    = $this->readEtlControlFile('running.json');
        $done       = $this->readEtlControlFile('done.json');
        $failed     = $this->readEtlControlFile('failed.json');
        $current    = $this->readEtlControlFile('current.json');
        // Phase P.1: stacked progress bars file. Written by the Python ETL
        // via heartbeat.bar_start / bar_update / bar_complete /
        // worldpop_advance_country. Shape: { phase, geoboundaries_bars,
        // cleanup_bars, worldpop_country_summary, worldpop_current_country_bars,
        // active_key }. Frontend renders the new <StackedProgressBars /> from
        // it. Returns null when bars.json doesn't yet exist (start of run /
        // pre-Phase-P DBs).
        $bars       = $this->readEtlControlFile('bars.json');
        // The ETL child process writes paused_on_error.json when it hits a
        // per-country error in --pause-on-exception mode. The wizard renders
        // an error card with skip/retry/abort buttons; the ETL polls
        // error_resolution.json for the operator's choice.
        $errorPause = $this->readEtlControlFile('paused_on_error.json');

        $lifecycle = 'idle';
        if ($running !== null)       $lifecycle = 'running';
        elseif ($failed !== null)    $lifecycle = 'failed';
        elseif ($done !== null)      $lifecycle = 'done';

        // Phase L: post-ETL viewer cache warmup. When the ETL has just
        // completed successfully, prime the most-visited GeoJSON endpoints
        // (Earth's children + every legislature's revealed view) so the first
        // real user navigation hits warm cache instead of paying the cold-path
        // ST_Simplify cost on native (non-simplified) jurisdictions.geom.
        // Idempotent — uses a sentinel file in the control dir to guarantee
        // it runs at most once per ETL run, with a Cache::lock to handle the
        // 2-second poll cadence safely.
        //
        // Phase T.2: similarly, dispatch the raster-tile pre-warm onto the
        // long-running Horizon queue after a fresh ETL run. Tiles are server-
        // side disk cache shared across every visitor, so warming them once
        // benefits all subsequent loads. The poll-driven hook lives here
        // (instead of inside seed_database.py) because the ETL container has
        // no PHP runtime; supervisor.py writes done.json, and the wizard
        // picks it up here on the next poll. Sentinel-protected like the
        // viewer cache warm-up so it dispatches at most once per ETL run.
        if ($lifecycle === 'done') {
            $this->warmViewerCachesIfNeeded();
            $this->dispatchRasterPrewarmIfNeeded($done);
        }

        $progressPath = base_path('scripts/etl/progress.json');
        $progress = null;
        if (is_file($progressPath)) {
            $raw = @file_get_contents($progressPath);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $progress = $decoded;
                }
            }
        }

        // Frontend's PhaseSummary tile reads progress.{geoboundaries,worldpop}.{
        // countries_done, in_progress_country}. The ETL writes per-key statuses
        // like "USA-ADM0": {status: "done"} but never builds those summary
        // arrays — derive them on the fly here so the UI shows real numbers
        // instead of always reading 0.
        if (is_array($progress)) {
            foreach (['geoboundaries', 'worldpop'] as $bucket) {
                $entries = is_array($progress[$bucket] ?? null) ? $progress[$bucket] : [];
                $progress[$bucket] = is_array($progress[$bucket] ?? null)
                    ? $progress[$bucket]
                    : [];
                $progress[$bucket]['countries_done'] = $this->deriveCountriesDone($entries);

                // The currently processing country only counts as "in progress"
                // for whichever phase the heartbeat is currently in.
                $inProgressIso = null;
                if (is_array($current)
                    && ($current['phase'] ?? null) === $bucket
                    && !empty($current['iso_code'])) {
                    $inProgressIso = (string) $current['iso_code'];
                }
                $progress[$bucket]['in_progress_country'] = $inProgressIso;
            }
        }

        $controlDir = $this->etlControlDir();
        $pending    = [
            'halt'   => is_file($controlDir.'/halt.request'),
            'pause'  => is_file($controlDir.'/pause.request'),
            'resume' => is_file($controlDir.'/resume.request'),
        ];

        $logTail = $this->tailEtlLog($tailLines, $includeDebug);
        $events  = $this->extractEvents($logTail);

        return response()->json([
            'lifecycle'            => $lifecycle,
            'running'              => $running,
            'done'                 => $done,
            'failed'               => $failed,
            'current'              => $current,
            'bars'                 => $bars,         // Phase P.1 stacked bars
            'progress'             => $progress,
            'error_pause'          => $errorPause,   // null when no pause active
            'log_tail'             => $logTail,
            'events'               => $events,       // Phase P.3 structured events
            'jurisdictions_counts' => $this->jurisdictionsCounts(),
            'pending_control'      => $pending,
            // Data-quality review surfaces after the run terminates so the
            // operator can audit populations + boundaries BEFORE clicking
            // Continue (which fires apportionment in activateStep1).
            //
            // OPT-IN: the heavy aggregate (DataReviewService::summary, ~14 s
            // on world-scale data) only runs when the client passes
            // ?include=review. Routine 2-second polls skip it — they're just
            // tracking progress bars, which is cheap. Cached for 5 minutes
            // because the underlying tables only change when the ETL runs,
            // and startMapData() forgets the key when a new run kicks off.
            'review'               => ($includeReview && in_array($lifecycle, ['done', 'failed'], true))
                ? Cache::remember(
                    'setup.review.summary',
                    300,
                    fn () => app(\App\Services\DataReviewService::class)->summary()
                )
                : null,
        ]);
    }

    /**
     * Grouped count per adm_level with human labels. Preserves the legacy
     * adm0/adm1/adm2/total keys for detectStep1 and any other callers that
     * read them, and adds a by_level map so the UI can render a card per
     * ADM level that's actually present in the DB.
     *
     * NAMING NOTE — the legacy "adm0"/"adm1"/"adm2" keys below count this
     * app's adm_level = 0/1/2 respectively (Earth / country / state). They
     * do NOT refer to geoBoundaries' ADM0/ADM1/ADM2 source files, which use
     * the opposite convention (their ADM0 = country = our adm_level 1). See
     * import_geoboundaries.ADM_LEVEL_MAP for the translation.
     *
     * adm_level conventions used throughout this app:
     *   0 = Earth / planet (synthetic root)
     *   1 = country / nation                (geoBoundaries ADM0)
     *   2 = state / province / region       (geoBoundaries ADM1)
     *   3 = county / district               (geoBoundaries ADM2)
     *   4 = municipality / local            (geoBoundaries ADM3)
     *   5 = sub-local                       (geoBoundaries ADM4)
     *   6 = ADM5
     */
    /**
     * Extract sorted unique ISO3 prefixes from progress.json keys whose
     * status === 'done'. Keys look like "USA-ADM0", "USA-ADM1", "MEX-ADM0";
     * we explode on '-' and take index 0.
     *
     * Skips meta keys like "started_at", "earth_inserted", "earth_uuid" that
     * sit alongside the per-country entries.
     *
     * @param  array<string, mixed>  $entries
     * @return array<int, string>
     */
    private function deriveCountriesDone(array $entries): array
    {
        $isos = [];
        foreach ($entries as $key => $entry) {
            if (! is_string($key)) continue;
            if (! is_array($entry)) continue;
            if (($entry['status'] ?? null) !== 'done') continue;
            // Per-country keys are "<ISO3>-ADM<N>"; everything else is meta.
            if (! preg_match('/^([A-Z]{3})-ADM\d+$/', $key, $m)) continue;
            $isos[$m[1]] = true;
        }
        $out = array_keys($isos);
        sort($out);
        return $out;
    }

    private function jurisdictionsCounts(): array
    {
        // Single grouped query: totals *and* "has population" count per level
        // in one pass. Since geoBoundaries always runs before WorldPop, the
        // ratio `with_pop / count` is an accurate indicator of how much of
        // the WorldPop phase has finished at any given ADM level.
        //
        // We treat `population > 0` as "has been computed" rather than
        // `population IS NOT NULL`. After 2026_04_27 the column is nullable
        // and arrives as NULL on fresh inserts, so IS NOT NULL would suffice;
        // adding `> 0` is a belt-and-braces guard so any legacy 0 values
        // (e.g. from the old DEFAULT 0 schema) don't get counted as "done."
        $rows = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->select(
                'adm_level',
                DB::raw('count(*) as c'),
                DB::raw('count(*) FILTER (WHERE population IS NOT NULL AND population > 0) as with_pop'),
                DB::raw('COALESCE(SUM(population) FILTER (WHERE population > 0), 0) as sum_pop'),
            )
            ->groupBy('adm_level')
            ->orderBy('adm_level')
            ->get();

        // Canonical natural-language labels. The Python ETL has a sibling
        // mapping at the top of import_geoboundaries.py / import_worldpop.py
        // — keep them in sync. No "ADM" jargon anywhere user-facing.
        $labels = [
            0 => 'Planet',
            1 => 'Countries',
            2 => 'States / Provinces',
            3 => 'Counties',
            4 => 'Municipalities',
            5 => 'Townships',
            6 => 'Neighborhoods',
        ];

        // List (not object) so PHP doesn't reindex numeric keys and the JSON
        // always serializes as an array the frontend can iterate safely.
        $byLevel = [];
        $byLevelMap = [];
        $total = 0;
        $totalWithPop = 0;
        $totalSumPop  = 0;
        foreach ($rows as $r) {
            $lvl     = (int) $r->adm_level;
            $count   = (int) $r->c;
            $withPop = (int) $r->with_pop;
            $sumPop  = (int) $r->sum_pop;
            $entry = [
                'level'    => $lvl,
                'count'    => $count,
                'with_pop' => $withPop,
                'sum_pop'  => $sumPop,
                'label'    => $labels[$lvl] ?? ('Level ' . $lvl),
            ];
            $byLevel[]          = $entry;
            $byLevelMap[$lvl]   = $count;
            $total             += $count;
            $totalWithPop      += $withPop;
            $totalSumPop       += $sumPop;
        }

        return [
            // Legacy keys kept so detectStep1 and other callers don't break.
            'adm0'           => $byLevelMap[0] ?? 0,
            'adm1'           => $byLevelMap[1] ?? 0,
            'adm2'           => $byLevelMap[2] ?? 0,
            'total'          => $total,
            'total_with_pop' => $totalWithPop,
            'total_sum_pop'  => $totalSumPop,
            'by_level'       => $byLevel,
        ];
    }

    private function etlControlDir(): string
    {
        return base_path('scripts/etl/control');
    }

    private function readEtlControlFile(string $name): ?array
    {
        $path = $this->etlControlDir().'/'.$name;
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function tailEtlLog(int $lines, bool $includeDebug = false): array
    {
        $path = base_path('scripts/etl/etl.log');
        if (! is_file($path)) {
            return [];
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            return [];
        }

        // Always read a fixed 512 KB tail. The frontend accumulates lines
        // across polls and filters DEBUG client-side, so we no longer
        // differentiate by the includeDebug flag (kept in the signature
        // for backwards compatibility — caller may still pass it, ignored).
        $chunk = 512 * 1024;
        return $this->readTailChunk($path, $size, $chunk, $lines);
    }

    /**
     * P.3: extract structured `[EVT] {...json...}` markers from ETL log lines.
     * The Python heartbeat.emit_event() helper writes one of these per
     * operator-relevant event (orphan flagged, raster load failed,
     * post-pass summary, etc.). Frontend's <EventToasts /> renders new
     * errors as persistent banners, warnings as auto-dismissing toasts,
     * info events as a feed.
     *
     * Returns the event payload list with a synthesised `ts` (epoch
     * seconds) parsed from the line's leading timestamp when present, so
     * the frontend can dedupe across polls.
     *
     * @param  array<int,string>  $lines
     * @return array<int,array<string,mixed>>
     */
    private function extractEvents(array $lines): array
    {
        $events = [];
        foreach ($lines as $line) {
            // Marker is the literal "[EVT] " followed by a JSON object.
            $pos = strpos($line, '[EVT] ');
            if ($pos === false) {
                continue;
            }
            $json    = substr($line, $pos + 6);
            $payload = json_decode($json, true);
            if (! is_array($payload)) {
                continue;
            }
            // Best-effort timestamp parse from the log line prefix
            // (format: "YYYY-MM-DD HH:MM:SS [LEVEL] ..."). Falls back to
            // null — the frontend can use insert order if absent.
            $ts = null;
            if (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/', $line, $m)) {
                $ts = strtotime($m[1]) ?: null;
            }
            $payload['ts'] = $ts;
            // Cheap, deterministic event id for dedup across polls.
            $payload['id'] = ($ts ? (string) $ts : 'nots').':'
                . substr(md5($json), 0, 12);
            $events[] = $payload;
        }
        return $events;
    }

    /**
     * @return array<int, string>
     */
    private function readTailChunk(string $path, int $size, int $chunk, int $lines): array
    {
        $chunk = (int) min($size, max(1, $chunk));
        $fh    = @fopen($path, 'rb');
        if (! $fh) {
            return [];
        }
        fseek($fh, -$chunk, SEEK_END);
        $buf = fread($fh, $chunk);
        fclose($fh);

        $all = preg_split("/\r\n|\n|\r/", (string) $buf);
        if ($all === false) {
            return [];
        }
        if ($chunk < $size && count($all) > 0) {
            array_shift($all); // partial first line
        }
        while (count($all) > 0 && end($all) === '') {
            array_pop($all);
        }

        return array_slice($all, -$lines);
    }

    /**
     * Phase L — post-ETL viewer cache warmup gate.
     *
     * Native (non-simplified) `jurisdictions.geom` makes the first cold-cache
     * hit on `childrenGeoJson` / `revealedGeoJson` substantially slower than
     * before — `ST_Simplify` per zoom now operates on dense input. To prevent
     * the first real user from paying that cold-path cost, we warm the most-
     * visited entry points once after every successful ETL run.
     *
     * Idempotency: a sentinel file `caches_warmed.json` in the ETL control
     * dir guarantees the warmup runs at most once per ETL run. The sentinel
     * is cleared in `startMapData` when a new run is dispatched, so the next
     * completion re-warms.
     *
     * Concurrency: `mapDataProgress` is polled every 2 seconds by the wizard
     * UI, so multiple polls could race after the very first detection of
     * `lifecycle === 'done'`. We use `Cache::lock(...)` (non-blocking) so only
     * one request actually does the warmup; concurrent polls return
     * immediately and let the holder finish.
     *
     * Failure mode: warmup is best-effort. Any exception is swallowed and
     * logged — the ETL is already complete, the user can still proceed.
     */
    /**
     * Phase T.2 — dispatch the raster-tile pre-warm to Horizon's long-running
     * supervisor after a fresh ETL run completes.
     *
     * Why poll-driven rather than seed_database.py-driven: the ETL container
     * has Python only, no PHP runtime. supervisor.py writes done.json on
     * subprocess exit; this method runs whenever the wizard polls and the
     * lifecycle has flipped to 'done'. Sentinel + lock pattern matches
     * warmViewerCachesIfNeeded — once-per-ETL-run, race-safe under the
     * 2-second poll cadence.
     *
     * Dispatch conditions (both required):
     *   - The ETL ran with --fresh. Resumed runs already had a valid cache
     *     before they began (resume can only mean the prior fresh has
     *     finished, and tile-cache wipe only happens at fresh-start).
     *   - The run was NOT --skip-population. Without WorldPop loaded the
     *     tile pipeline returns all transparent PNGs; pre-warming would
     *     just fill the cache with empty tiles that the next real ETL
     *     would have to invalidate anyway.
     *
     * Failure mode: if dispatch raises, the sentinel doesn't get written
     * and the next poll retries. The operator can also manually invoke
     * `php artisan rasters:prewarm --queue` at any time.
     *
     * @param array|null $done The parsed done.json payload.
     */
    private function dispatchRasterPrewarmIfNeeded(?array $done): void
    {
        if (! is_array($done)) {
            return;
        }

        $sentinel = $this->etlControlDir().'/raster_prewarm_dispatched.json';
        if (is_file($sentinel)) {
            return;
        }

        // Pull the original request options out of done.json. supervisor.py
        // attaches the full request payload as `request` on the lifecycle
        // status files, so we recover the operator's flags here.
        $options = $done['request']['options'] ?? [];
        if (! is_array($options)) {
            $options = [];
        }
        $wasFresh   = (bool) ($options['fresh'] ?? false);
        $skippedPop = (bool) ($options['skip_population'] ?? false);

        if (! $wasFresh || $skippedPop) {
            // Either a resume (cache still valid) or skip-population (no
            // rasters to render). Write the sentinel so we don't re-check
            // every poll for the rest of the run.
            @file_put_contents($sentinel, json_encode([
                'dispatched' => false,
                'reason'     => $skippedPop ? 'skip_population' : 'resume_not_fresh',
                'observed_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));
            return;
        }

        $lock = Cache::lock('setup:dispatch-raster-prewarm', 60);
        if (! $lock->get()) {
            // Another poll holds the lock — let them finish. The next poll
            // will see the sentinel they wrote and skip cleanly.
            return;
        }

        try {
            // Re-check inside the lock (TOCTOU safety).
            if (is_file($sentinel)) {
                return;
            }

            PrewarmRasterTilesJob::dispatch(
                minZoom:  0,
                maxZoom:  12,
                landOnly: true,
            );

            @file_put_contents($sentinel, json_encode([
                'dispatched'  => true,
                'min_zoom'    => 0,
                'max_zoom'    => 12,
                'land_only'   => true,
                'queue'       => 'long-running',
                'dispatched_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));

            Log::info('Setup post-ETL raster-tile pre-warm dispatched', [
                'min_zoom'  => 0,
                'max_zoom'  => 12,
                'land_only' => true,
            ]);
        } catch (\Throwable $exc) {
            // Don't surface to the wizard — the ETL is already done, the
            // operator can manually re-run the prewarm. Log and move on.
            Log::warning('Setup post-ETL raster-tile pre-warm dispatch failed (non-fatal): '.$exc->getMessage(), [
                'exception' => get_class($exc),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function warmViewerCachesIfNeeded(): void
    {
        $sentinel = $this->etlControlDir().'/caches_warmed.json';
        if (is_file($sentinel)) {
            return;
        }

        $lock = Cache::lock('setup:warm-viewer-caches', 120);
        if (! $lock->get()) {
            // Another request holds the lock — let them finish. The next poll
            // will see the sentinel they wrote and skip cleanly.
            return;
        }

        try {
            // Re-check the sentinel inside the lock (TOCTOU safety in case
            // another process wrote it between our outer check and the lock).
            if (is_file($sentinel)) {
                return;
            }

            $startedAt = microtime(true);
            $stats     = $this->warmViewerCaches();
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            @file_put_contents($sentinel, json_encode([
                'warmed_at' => now()->toIso8601String(),
                'elapsed_ms' => $elapsedMs,
                'stats'     => $stats,
            ], JSON_PRETTY_PRINT));

            Log::info('Setup post-ETL cache warmup complete', [
                'elapsed_ms' => $elapsedMs,
                'stats'      => $stats,
            ]);
        } catch (\Throwable $exc) {
            Log::warning('Setup post-ETL cache warmup failed (non-fatal): '.$exc->getMessage(), [
                'exception' => get_class($exc),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Phase L — actual cache priming work. Called from
     * warmViewerCachesIfNeeded under a lock with sentinel protection.
     *
     * Scope is intentionally minimal — just the most-visited cold paths:
     *   1. Earth's `childrenGeoJson` at zoom 6 (~195 ADM0 features)
     *   2. Each legislature's `revealedGeoJson` at default scope + zoom 6
     *
     * Individual ADM0 country children warm naturally as users navigate
     * (cold load is fast enough at that scope; ~50 ms per country).
     *
     * @return array<string, int>
     */
    private function warmViewerCaches(): array
    {
        $stats = [
            'jurisdictions_warmed' => 0,
            'legislatures_warmed'  => 0,
        ];

        // 1. Earth's children — the global jurisdiction view that's the
        // entry point for almost every map navigation in the app.
        try {
            $earth = Jurisdiction::where('adm_level', 0)
                ->whereNull('deleted_at')
                ->first();

            if ($earth) {
                $controller = app(\App\Http\Controllers\JurisdictionController::class);
                $req = Request::create(
                    '/api/jurisdictions/'.$earth->id.'/children.geojson',
                    'GET',
                    ['zoom' => 6]
                );
                // Fire the controller method directly. We don't care about
                // the response — the side effect (Cache::remember populating
                // Redis) is what we want.
                $controller->childrenGeoJson($req, $earth);
                $stats['jurisdictions_warmed'] = 1;
            }
        } catch (\Throwable $exc) {
            Log::warning('Cache warmup: Earth childrenGeoJson failed: '.$exc->getMessage());
        }

        // 2. Every active legislature's revealed view at default scope. The
        // legislatures table is small (today: just Earth's; one per parent-
        // with-children jurisdiction once apportionment runs). Loop is generic
        // so it warms whatever exists.
        try {
            $legislatures = DB::table('legislatures')
                ->whereNull('deleted_at')
                ->select('id', 'jurisdiction_id')
                ->get();

            $legController = app(\App\Http\Controllers\LegislatureController::class);
            foreach ($legislatures as $leg) {
                if (empty($leg->jurisdiction_id)) {
                    continue;
                }
                try {
                    $req = Request::create(
                        '/api/legislatures/'.$leg->id.'/revealed.geojson',
                        'GET',
                        [
                            'scope' => $leg->jurisdiction_id,
                            'zoom'  => 6,
                        ]
                    );
                    $legController->revealedGeoJson($req, $leg->id);
                    $stats['legislatures_warmed']++;
                } catch (\Throwable $innerExc) {
                    Log::warning("Cache warmup: legislature {$leg->id} revealedGeoJson failed: ".$innerExc->getMessage());
                }
            }
        } catch (\Throwable $exc) {
            Log::warning('Cache warmup: legislature loop failed: '.$exc->getMessage());
        }

        return $stats;
    }

    /**
     * POST /api/setup/wizard/step3/complete — mark districts-built.
     * Called from the district mapper when the user clicks "Back to Setup →"
     * after activating a map. Advances setup_step_completed past Step 3 so
     * the wizard considers the build-districts step done.
     */
    public function completeStep3(): JsonResponse
    {
        $settings = InstanceSettings::current();
        $settings->setup_step_completed = max((int) $settings->setup_step_completed, 4);
        $settings->save();

        return response()->json([
            'settings' => $this->serializeSettings($settings->fresh()),
            'next'     => '/setup/step/4',
        ]);
    }

    /**
     * GET /api/setup/wizard/step3/summary — apportionment headline numbers.
     *
     * Powers the summary block on Step 3 (Build Districts): how many
     * legislatures got sized, total seats apportioned, and the largest
     * legislature. Reads directly from the `legislatures` table populated
     * by `apportionment:seed` during activateStep1.
     */
    public function step3Summary(): JsonResponse
    {
        $row = DB::table('legislatures')
            ->whereNull('deleted_at')
            ->selectRaw('count(*) as legislatures, coalesce(sum(type_a_seats + type_b_seats), 0) as total_seats')
            ->first();

        $largest = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->orderByDesc(DB::raw('l.type_a_seats + l.type_b_seats'))
            ->limit(1)
            ->first(['j.name as jurisdiction_name', 'l.type_a_seats', 'l.type_b_seats']);

        // WI-9: enumerate the legislatures themselves (jurisdiction name +
        // slug for the mapper link, seats per chamber type). During setup
        // there is exactly one (the root's); after CLK-06 activations there
        // are N — capped at 25 rows for the panel, with `legislatures`
        // remaining the authoritative count.
        $rows = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->orderBy('j.adm_level')
            ->orderByDesc(DB::raw('l.type_a_seats + l.type_b_seats'))
            ->limit(25)
            ->get([
                'j.name as jurisdiction_name',
                'j.slug as jurisdiction_slug',
                'j.adm_level',
                'l.type_a_seats',
                'l.type_b_seats',
            ])
            ->map(fn ($r) => [
                'name'         => $r->jurisdiction_name,
                'slug'         => $r->jurisdiction_slug,
                'adm_level'    => (int) $r->adm_level,
                'type_a_seats' => (int) $r->type_a_seats,
                'type_b_seats' => (int) $r->type_b_seats,
            ])
            ->values();

        return response()->json([
            'legislatures' => (int) ($row->legislatures ?? 0),
            'total_seats'  => (int) ($row->total_seats ?? 0),
            'largest'      => $largest ? [
                'name'  => $largest->jurisdiction_name,
                'seats' => (int) $largest->type_a_seats + (int) $largest->type_b_seats,
            ] : null,
            'rows'         => $rows,
        ]);
    }

    /**
     * POST /api/setup/wizard/step4/complete — finish setup.
     *
     * Generates institution stub rows (one executives row + one judiciaries
     * row per jurisdiction that has a legislature), records confirmation,
     * and flips setup_completed_at. From this point /setup redirects home.
     */
    public function completeStep4(): JsonResponse
    {
        $stubs = DB::transaction(function () {
            return $this->generateInstitutionStubs();
        });

        $settings = InstanceSettings::current();
        $settings->setup_districts_confirmed_at = now();
        $settings->setup_step_completed         = max((int) $settings->setup_step_completed, 5);
        $settings->setup_completed_at           = now();
        // Capture the data-quality review snapshot at completion time so a
        // future audit can see what issues were outstanding when the
        // operator finished setup. Top-level summary only — no row drill.
        $settings->setup_completion_notes       = $this->buildStep4Review();
        $settings->save();

        return response()->json([
            'settings' => $this->serializeSettings($settings->fresh()),
            'stubs'    => $stubs,
            'next'     => '/',
        ]);
    }

    /**
     * Snapshot used by the Step 4 page on initial render.
     */
    private function buildStep4Summary(): array
    {
        $legislatures = (int) DB::table('legislatures')
            ->whereNull('deleted_at')
            ->count();

        $districts = (int) DB::table('legislature_districts')
            ->whereNull('deleted_at')
            ->count();

        $existingExecs   = (int) DB::table('executives')->whereNull('deleted_at')->count();
        $existingJudges  = (int) DB::table('judiciaries')->whereNull('deleted_at')->count();

        return [
            'legislatures'        => $legislatures,
            'districts'           => $districts,
            'existing_executives' => $existingExecs,
            'existing_judiciaries'=> $existingJudges,
        ];
    }

    /**
     * Data-quality review snapshot used by the Step 4 page on initial render
     * (sibling of buildStep4Summary). Surfaces categorized post-ETL issues the
     * operator may want to inspect before clicking Finish Setup. See
     * App\Services\DataReviewService for the SQL behind each category.
     */
    private function buildStep4Review(): array
    {
        return app(\App\Services\DataReviewService::class)->summary();
    }

    // ─── Step 4 review drill endpoints ──────────────────────────────────────
    //
    // Each endpoint returns:
    //   { rows: [...], total: int, next_offset: int|null }
    //
    // The frontend lazily fetches when the operator expands a category card.

    /**
     * GET /api/setup/wizard/step4/review/population_gaps
     *      ?adm_level=N&limit=50&offset=0
     */
    public function reviewPopulationGaps(Request $request): JsonResponse
    {
        $admLevel = (int) $request->query('adm_level', 6);
        $limit    = max(1, min(200, (int) $request->query('limit', 50)));
        $offset   = max(0, (int) $request->query('offset', 0));

        return response()->json(
            app(\App\Services\DataReviewService::class)
                ->populationGapsRows($admLevel, $limit, $offset)
        );
    }

    /**
     * GET /api/setup/wizard/step4/review/aggregation_discrepancies
     *      ?limit=50&offset=0
     */
    public function reviewAggregationDiscrepancies(Request $request): JsonResponse
    {
        $limit  = max(1, min(200, (int) $request->query('limit', 50)));
        $offset = max(0, (int) $request->query('offset', 0));

        return response()->json(
            app(\App\Services\DataReviewService::class)
                ->aggregationDiscrepancyRows($limit, $offset)
        );
    }

    /**
     * GET /api/setup/wizard/step4/review/orphans
     *      ?adm_level=N&limit=50&offset=0     (adm_level optional)
     */
    public function reviewOrphans(Request $request): JsonResponse
    {
        $admLevel = $request->query('adm_level');
        $admLevel = ($admLevel === null || $admLevel === '') ? null : (int) $admLevel;
        $limit    = max(1, min(200, (int) $request->query('limit', 50)));
        $offset   = max(0, (int) $request->query('offset', 0));

        return response()->json(
            app(\App\Services\DataReviewService::class)
                ->orphanRows($admLevel, $limit, $offset)
        );
    }

    /**
     * GET /api/setup/wizard/step4/review/sovereign_territories
     *      ?sovereign=ISO&limit=50&offset=0   (sovereign optional)
     */
    public function reviewSovereignTerritories(Request $request): JsonResponse
    {
        $sovereign = $request->query('sovereign');
        $sovereign = ($sovereign === null || $sovereign === '') ? null : (string) $sovereign;
        $limit     = max(1, min(200, (int) $request->query('limit', 50)));
        $offset    = max(0, (int) $request->query('offset', 0));

        return response()->json(
            app(\App\Services\DataReviewService::class)
                ->sovereignTerritoryRows($sovereign, $limit, $offset)
        );
    }

    // ─── Per-row detail + decision endpoints ────────────────────────────────
    //
    // Each detail endpoint returns a row's full review context (parent,
    // siblings, candidate parents, raster availability, etc) so the
    // operator can make a manual decision without flipping between tools.
    //
    // The decision endpoint persists the operator's choice — no autofix
    // happens server-side. Decisions are recorded for any future
    // remediation flow to consume.

    /**
     * GET /api/setup/wizard/step2/review/parent_assignment_audit?strategy=X&limit=N&offset=N
     *      Phase JK: distribution of parent_assigned_via values; drill-down per strategy
     */
    public function reviewParentAssignmentAudit(Request $request): JsonResponse
    {
        $strategy = (string) $request->query('strategy', '');
        $limit    = max(1, min(200, (int) $request->query('limit', 50)));
        $offset   = max(0, (int) $request->query('offset', 0));
        if ($strategy === '') {
            return response()->json(['error' => 'strategy query param required'], 422);
        }

        return response()->json(
            app(\App\Services\DataReviewService::class)
                ->parentAssignmentAuditRows($strategy, $limit, $offset)
        );
    }

    /**
     * GET /api/setup/wizard/step2/review/population_assignment_audit?source=X&limit=N&offset=N
     *      Phase JK: distribution of population_assigned_via values; drill-down per source
     */
    public function reviewPopulationAssignmentAudit(Request $request): JsonResponse
    {
        $source = (string) $request->query('source', '');
        $limit  = max(1, min(200, (int) $request->query('limit', 50)));
        $offset = max(0, (int) $request->query('offset', 0));
        if ($source === '') {
            return response()->json(['error' => 'source query param required'], 422);
        }

        return response()->json(
            app(\App\Services\DataReviewService::class)
                ->populationAssignmentAuditRows($source, $limit, $offset)
        );
    }

    /**
     * GET /api/setup/wizard/step4/review/{category}/{jurisdiction}/detail
     */
    public function reviewDetail(string $category, string $jurisdiction): JsonResponse
    {
        $svc = app(\App\Services\DataReviewService::class);
        $detail = match ($category) {
            'population_gaps'           => $svc->detailForPopulationGap($jurisdiction),
            'aggregation_discrepancies' => $svc->detailForAggregationDiscrepancy($jurisdiction),
            'orphans'                   => $svc->detailForOrphan($jurisdiction),
            'sovereign_territories'     => $svc->detailForSovereignTerritory($jurisdiction),
            default                     => null,
        };
        if ($detail === null) {
            return response()->json(['error' => 'Not found'], 404);
        }
        return response()->json($detail);
    }

    /**
     * POST /api/setup/wizard/step4/review/{category}/{jurisdiction}/decision
     *      body: { decision: <string>, note: <string|null> }
     */
    public function reviewDecision(string $category, string $jurisdiction, Request $request): JsonResponse
    {
        $allowedCategories = [
            'population_gaps',
            'aggregation_discrepancies',
            'orphans',
            'sovereign_territories',
        ];
        if (! in_array($category, $allowedCategories, true)) {
            return response()->json(['error' => 'Unknown review category'], 422);
        }

        $data = $request->validate([
            'decision' => ['required', 'string', 'max:128'],
            'note'     => ['nullable', 'string', 'max:4000'],
        ]);

        $svc = app(\App\Services\DataReviewService::class);
        $payload = $svc->recordDecision(
            $category,
            $jurisdiction,
            $data['decision'],
            $data['note'] ?? null,
        );
        return response()->json($payload);
    }

    /**
     * Insert one executives + judiciaries stub row per jurisdiction that has
     * a legislature, skipping any that already exist (idempotent on re-run).
     *
     * Extracted to InstitutionStubService (WI-7) so the activation engine
     * (ActivationService) shares the same implementation; this method is
     * the Setup Step 4 delegate (all legislatures, no jurisdiction scope).
     *
     * @return array{executives_created:int, judiciaries_created:int}
     */
    private function generateInstitutionStubs(): array
    {
        return app(\App\Services\InstitutionStubService::class)->generate();
    }

    /**
     * POST /api/setup/wizard/step1/activate — advance past the Map Data step (step 2).
     *
     * (Kept at the historical /wizard/step1 URL so bookmarks and the Vue call sites
     * don't churn; the semantics moved from "Map Data is step 1" to "Map Data is
     * step 2" when the wizard was reordered.)
     *
     * If saveConstants stashed a pending_constitutional_defaults payload (because
     * the planet row didn't exist yet), apply it to the now-present planet row
     * (adm_level = 0) and clear the stash. Then advance setup_step_completed to 3.
     *
     * Apportionment is no longer run inline here — the canonical trigger is the
     * planet-scope "Accept Map Data & Continue" button on
     * /jurisdictions/earth-0-earth, which queues `apportionment:seed
     * --jurisdiction=earth` via Horizon. That command stamps the
     * apportionment_completed_at timestamp on completion. This handler keeps
     * the pending_constitutional_defaults apply logic and the step-completion
     * advance; apportionment is decoupled.
     */
    public function activateStep1(): JsonResponse
    {
        $settings = InstanceSettings::current();

        $pending = $settings->pending_constitutional_defaults;
        if (is_array($pending)) {
            $root = $this->resolveRootJurisdiction();
            if ($root) {
                $this->writeConstitutionalSettings($root->id, $pending);
                $settings->pending_constitutional_defaults = null;
                \App\Services\ConstitutionalDefaults::flush();
            }
            // If the planet row still doesn't exist, leave the stash intact —
            // the user is likely advancing without having loaded data yet.
        }

        $settings->setup_step_completed = max((int) $settings->setup_step_completed, 3);
        $settings->save();

        return response()->json([
            'settings' => $this->serializeSettings($settings->fresh()),
            'next'     => '/setup/step/3',
        ]);
    }

    /**
     * Shared serializer — embeds the cosmic-address chain so the frontend can
     * render "Multiverse ▸ Observable Universe ▸ ... ▸ Earth" without extra fetches.
     */
    private function serializeSettings(InstanceSettings $settings): array
    {
        $addressPath = [];
        if ($settings->cosmic_address_id) {
            $leaf = CosmicAddress::find($settings->cosmic_address_id);
            if ($leaf) {
                $addressPath = $leaf->pathFromRoot();
            }
        }

        return [
            'id'                            => $settings->id,
            'instance_name'                 => $settings->instance_name,
            'cosmic_address_id'             => $settings->cosmic_address_id,
            'cosmic_address_path'           => $addressPath,
            'map_mode'                      => $settings->map_mode,
            'time_mode'                     => $settings->time_mode,
            'time_scale_seconds_per_year'   => $settings->time_scale_seconds_per_year,
            'setup_step_completed'          => (int) $settings->setup_step_completed,
            'setup_completed_at'            => optional($settings->setup_completed_at)->toIso8601String(),
            'apportionment_completed_at'    => optional($settings->apportionment_completed_at)->toIso8601String(),
            'setup_districts_confirmed_at'  => optional($settings->setup_districts_confirmed_at)->toIso8601String(),
        ];
    }

    /**
     * Returns the instance's root jurisdiction — currently the single planet
     * row (adm_level = 0, "Earth"). When multi-world support lands
     * (cosmic_address_id → planet scope), this will scope by
     * instance_settings.cosmic_address_id.
     */
    private function resolveRootJurisdiction(): ?object
    {
        // slug included so Step 3's mapper handoff can address the
        // legislature canonically (/legislatures/{slug}) instead of
        // falling back to the UUID + redirect (WI-9).
        return DB::table('jurisdictions')
            ->where('adm_level', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first(['id', 'name', 'slug']);
    }

}
