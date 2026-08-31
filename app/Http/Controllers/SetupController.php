<?php

namespace App\Http\Controllers;

use App\Jobs\PrewarmRasterTilesJob;
use App\Models\CosmicAddress;
use App\Models\InstanceSettings;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\Federation\FederationDiscoveryService;
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
 * and applied when Map Data activation resolves. Apportionment + districting
 * run as the full-scale AUTOSCALE (AutoscaleOrchestratorJob), kicked off by
 * "Accept Map Data & Continue" on the planet viewer.
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

        // ORDER (operator ruling 2026-07-05): JOIN-or-START is the FIRST question
        // after schema — before the operator account, before the cosmic address.
        // Joining means the mesh already constrains most settings, so the fork
        // gates everything downstream.
        if ($settings->setup_mode === null) {
            return redirect('/setup/mode');
        }
        if ($settings->setup_mode === 'join') {
            return redirect('/setup/join');
        }

        // START (solo): the operator account + node identity + roles is the FIRST
        // build step (the "operator console at bootstrap"). Until it exists, land
        // there. THEN the cosmic address and the rest.
        if (User::query()->doesntExist()) {
            return redirect('/setup/operator');
        }

        // SOLO. Convention: setup_step_completed = n  →  steps 0..n-1 done, next is step n.
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

        // Roles-campaign Phase 1 — the build steps belong to SOLO. An undecided instance goes to the
        // fork; a JOIN instance never walks these (its foundation + institutions sync in from the host).
        if ($settings->setup_mode === null) {
            return redirect('/setup/mode');
        }
        if ($settings->setup_mode === 'join') {
            return redirect('/setup');
        }

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

        if ($n === 2) {
            // THE THREE ACTIVATION MODES dropdown (operator, 2026-08-08):
            // the simulate sub-option only renders on a sandbox world.
            $extra['is_dev_world'] = $settings->game_mode === 'sandbox';
            $extra['scale_mode']   = (string) ($settings->institution_scale_mode ?? 'eager');
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

    // ─── Roles-campaign Phase 1 — SOLO/JOIN fork ──────────────────────────────

    /**
     * Render the SOLO/JOIN fork — the FIRST question after schema (before the
     * operator account). SOLO starts a new world (you ARE the canonical game,
     * federating to yourself); JOIN connects to an existing mesh, which already
     * constrains most settings.
     */
    public function mode(): Response|RedirectResponse
    {
        if ($this->needsBootstrap()) {
            return redirect('/setup/bootstrap'); // schema first
        }
        $settings = InstanceSettings::current();
        if ($settings->isSetupComplete()) {
            return redirect('/');
        }
        if ($settings->setup_mode !== null) {
            return redirect('/setup'); // already chosen — index routes to the right path
        }

        return Inertia::render('Setup/ModeFork', [
            'settings' => $this->serializeSettings($settings),
        ]);
    }

    /**
     * GET /setup/operator — the START-path operator bootstrap step: create the
     * operator/founder account, name the node + set its address, and establish
     * the founding operator roles (all self-asserted). This is the "operator
     * console at bootstrap." Comes AFTER the fork, BEFORE the cosmic address.
     */
    public function operatorSetupPage(): Response|RedirectResponse
    {
        if ($this->needsBootstrap()) {
            return redirect('/setup/bootstrap');
        }
        $settings = InstanceSettings::current();
        if ($settings->isSetupComplete()) {
            return redirect('/');
        }
        // The fork must be settled first (both paths need the operator account,
        // but the fork decides what comes AFTER this step).
        if ($settings->setup_mode === null) {
            return redirect('/setup/mode');
        }
        // Once the account exists, a JOIN instance belongs on the join screen.
        if ($settings->setup_mode === 'join' && User::query()->exists()) {
            return redirect('/setup/join');
        }

        $hasFounder = User::query()->exists();

        // The nine capability channels + their founding state (only meaningful
        // once an operator exists to hold them). Founding → every one is
        // self-assertable; needs_setup flags an on-but-unconfigured infra role.
        $channels = $hasFounder ? $this->channelStates() : [];

        return Inertia::render('Setup/OperatorSetup', [
            'settings'    => $this->serializeSettings($settings),
            'has_founder' => $hasFounder,
            'channels'    => $channels,
            'founding'    => \App\Support\FoundingContext::isFounding(),
            // A sensible default address suggestion the browser fills client-side
            // (window.location) — the peer address is optional for a solo node.
            'self_url'    => config('cga.federation_self_url'),
        ]);
    }

    /**
     * POST /api/setup/mode — record the SOLO/JOIN choice. One-way: the fork is settled once chosen
     * (re-running setup from scratch is `down -v`). JOIN mints the federation identity now so the
     * operator can read their server_id and a host can pin it during adoption.
     */
    public function setMode(Request $request): JsonResponse
    {
        // The fork is the FIRST question after schema — no operator account is
        // required yet (that's the next step on the START path). A fresh box's
        // first visitor settles the fork.
        $data = $request->validate([
            'setup_mode' => ['required', Rule::in(['solo', 'join'])],
        ]);

        // Settle the fork ATOMICALLY: only the request that flips NULL → mode wins; a concurrent second
        // tab sees 0 rows updated → 409. (A check-then-save would let two requests both pass the guard.)
        $won = DB::table('instance_settings')
            ->whereNull('setup_mode')
            ->whereNull('deleted_at')
            ->update(['setup_mode' => $data['setup_mode']]);
        if (! $won) {
            return response()->json(['error' => 'Setup mode is already chosen.'], 409);
        }

        if ($data['setup_mode'] === 'join') {
            // Mint THIS box's federation identity before it talks to a host (idempotent).
            app(\App\Services\Federation\InstanceIdentityService::class)->ensureIdentity();
        }

        return response()->json([
            'settings' => $this->serializeSettings(InstanceSettings::current()->fresh()),
            // START → the operator-setup step (account + node + roles). JOIN → the
            // mirror-onboarding screen.
            'next'     => $data['setup_mode'] === 'join' ? '/setup/join' : '/setup/operator',
        ]);
    }

    /**
     * Render the JOIN screen (mirror onboarding). Shows this box's server_id so the operator can hand
     * it to a host, and collects the host URL + optional join key.
     */
    public function join(): Response|RedirectResponse
    {
        if ($this->needsBootstrap()) {
            return redirect('/setup/bootstrap');
        }
        $settings = InstanceSettings::current();
        if ($settings->isSetupComplete()) {
            return redirect('/');
        }
        if ($settings->setup_mode !== 'join') {
            return redirect('/setup');
        }
        // The operator account is created at the operator-setup step (post-fork),
        // for the join path too — a node still needs a local operator to run it.
        if (User::query()->doesntExist()) {
            return redirect('/setup/operator');
        }

        return Inertia::render('Setup/JoinHost', [
            'settings'  => $this->serializeSettings($settings),
            'server_id' => $settings->server_id,
        ]);
    }

    /**
     * POST /api/setup/join — connect to a host and become a read-only mirror. Reuses MirrorService
     * (keyed joinHost / keyless requestJoin) — which, since Phase 0b, pulls the host's geodata seed
     * BEFORE draining the audit corpus, so the foundation + replayed institutions all sync in. A LIVE
     * mirror IS "ready player one" for a join: the operator account is connected to a federation account
     * on the mesh. A keyless request the host hasn't vouched yet returns 'pending_host_approval'.
     */
    public function joinFromSetup(Request $request, \App\Services\Mirror\MirrorService $mirror): JsonResponse
    {
        $settings = InstanceSettings::current();
        if ($settings->setup_mode !== 'join') {
            return response()->json(['error' => 'This instance is not in join mode.'], 409);
        }

        // Already joined — a re-POST after completion is a no-op, not another adoption.
        if ($settings->isSetupComplete()) {
            return response()->json([
                'state'    => 'ready',
                'settings' => $this->serializeSettings($settings),
                'next'     => '/',
            ]);
        }

        try {
            if ($mirror->isMirror()) {
                // A prior attempt already pinned us as a mirror of this host. Do NOT run the drain inline —
                // United Earth's foundation is a multi-GB seed + ~951k-row drain that far outlasts any HTTP
                // connection, and a client disconnect would abort the handler before seeded_at is stamped
                // (the synchronous-wizard blocker). If the background drain already caught up, finalize;
                // otherwise (re-)dispatch the resumable, idempotent job and let the page poll progress.
                $membership = $mirror->activeMirrorMembership();
                if ($membership === null) {
                    throw new \RuntimeException('No active mirror membership to resume — re-join from the host.');
                }
                if ($membership->state !== \App\Models\ClusterMembership::STATE_LIVE) {
                    \App\Jobs\Federation\ClusterJoinJob::dispatch((string) $membership->id);
                }
            } else {
                $data = $request->validate([
                    'host_url' => ['required', 'url'],
                    'join_key' => ['nullable', 'string'],
                ]);
                // Admit synchronously (so a bad/exhausted join key fails fast, in-band) but defer the long
                // seed + drain to ClusterJoinJob — exactly what the federation console does. sync: false.
                if (! empty($data['join_key'])) {
                    $membership = $mirror->joinHost($data['host_url'], $data['join_key'], [], sync: false);
                } else {
                    $membership = $mirror->requestJoin($data['host_url'], [], sync: false);
                    if ($membership === null) {
                        return response()->json([
                            'state'    => 'pending_host_approval',
                            'settings' => $this->serializeSettings($settings->fresh()),
                        ]);
                    }
                }
                \App\Jobs\Federation\ClusterJoinJob::dispatch((string) $membership->id);
            }
        } catch (\Throwable $e) {
            // Already pinned as a mirror = the sync merely didn't finish; it's resumable, not a fresh
            // failure. Say so distinctly so the operator re-submits (which now resumes) rather than reading
            // a dead end.
            $msg = $mirror->isMirror()
                ? 'Connected, but the sync did not finish — re-submit to resume: '.$e->getMessage()
                : 'Join failed: '.$e->getMessage();

            return response()->json(['error' => $msg], 422);
        }

        // The seed + drain runs OFF the request thread (the ETL-wizard pattern): the page shows live
        // per-table progress (SyncProgressService) and re-POSTs to finalize when the membership goes LIVE.
        // A LIVE membership = the corpus drained to catch-up → ready player one ("ready"); SYNCING = the
        // background job is pulling ("syncing", the page polls + auto-finalizes on catch-up).
        $live = $membership->state === \App\Models\ClusterMembership::STATE_LIVE;
        $settings->refresh();
        if ($live && ! $settings->isSetupComplete()) {
            $settings->forceFill([
                'setup_completed_at'   => now(),
                'setup_step_completed' => 5,
            ])->save();
        }

        return response()->json([
            'state'    => $live ? 'ready' : 'syncing',
            'settings' => $this->serializeSettings($settings->fresh()),
            'next'     => $live ? '/' : '/setup/join',
        ]);
    }

    /**
     * POST /api/setup/discover — find an existing federation with no foreknowledge of an address. Runs the
     * public front-door probe always, and (opt-in) an operator-triggered LAN sweep of the operator's OWN
     * private subnet. Returns advisory candidates the operator can pick to populate the host URL; admission
     * is still the signed adopt handshake. Gated to a node actually in join mode (same trust class as
     * joinFromSetup, which already dials an operator-supplied host_url during setup).
     */
    public function discover(Request $request, FederationDiscoveryService $discovery): JsonResponse
    {
        $settings = InstanceSettings::current();
        if ($this->needsBootstrap() || User::query()->doesntExist()) {
            return response()->json(['error' => 'Finish bootstrap first.'], 409);
        }
        if ($settings->setup_mode !== 'join') {
            return response()->json(['error' => 'This instance is not in join mode.'], 409);
        }

        $data = $request->validate([
            'lan'  => ['nullable', 'boolean'],
            'cidr' => ['nullable', 'string', 'max:64'],
        ]);

        $includeLan = (bool) ($data['lan'] ?? false);
        $cidr = $data['cidr'] ?? null;

        // The front-door probe always runs; a bad LAN CIDR comes back as `lan_error` WITHOUT discarding
        // the front-door results (the SSRF guard rejects an out-of-bounds range inside discover()).
        $result = $discovery->discover($includeLan, $includeLan ? $cidr : null);

        return response()->json([
            'federations'   => $result['federations'],
            'lan_error'     => $result['lan_error'],
            'lan_available' => (bool) config('cga.federation_lan_discovery'),
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
    public function runMigrations(Request $request): JsonResponse
    {
        // Pre-founder this MUST stay open (nobody can log in to an empty box —
        // the bootstrap page is how the schema gets there). Once a founder
        // exists, only the operator may apply schema updates from the web: on
        // a public box the bootstrap page is reachable by anyone, and
        // `migrate --force` is a state change a guest must never trigger.
        // (Schema guard first: on an uninitialised box the users table itself
        // does not exist yet.)
        if (Schema::hasTable('users') && User::query()->exists() && ! (bool) $request->user()?->is_operator) {
            abort(403);
        }

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
        $operatorAccount = null;
        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data, &$operatorAccount) {
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
            $operatorAccount = app(\App\Services\Identity\OperatorIdentityService::class)
                ->register($data['email'], $data['password']);

            return $founder;
        });

        Auth::login($user);
        // Establish the OPERATOR session alongside the citizen one so a single-operator box is not asked
        // to sign in a second time to reach the host controls (adoption approvals, roles). The two guards
        // share the session store under different keys (OperatorSessionController), and this couples
        // NOTHING into role derivation — OperatorPlaneSeparationTest still holds (an operator session
        // confers no governance role). A separate /operator/login remains for a returning/expired session.
        if ($operatorAccount !== null) {
            Auth::guard('operator')->login($operatorAccount);
        }
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

        // Operator-profile pre-fill (so the "Name this node" card isn't blank on
        // revisit). Only meaningful once the schema exists.
        $instanceName = null;
        if (Schema::hasTable('instance_settings')) {
            $instanceName = optional(InstanceSettings::query()->first())->instance_name;
        }

        return [
            'schema_state'       => $schemaState,
            'pending_migrations' => $pending,
            'pending_count'      => count($pending),
            // Flattened-baseline marker: a fresh install loads this dump in one
            // step (plus any migrations newer than it), so the UI never needs
            // to enumerate history to a first-time user.
            'has_schema_dump'    => is_file(database_path('schema/pgsql-schema.sql')),
            'has_founder'        => $hasFounder,
            'etl_running'        => $etlRunning,
            'ready'              => $ready,
            // Operator onboarding pre-fill.
            'instance_name'      => $instanceName === 'Unnamed Instance' ? null : $instanceName,
            'self_url'           => config('cga.federation_self_url'),
            'game_mode'          => (Schema::hasTable('instance_settings') && Schema::hasColumn('instance_settings', 'game_mode'))
                ? optional(InstanceSettings::query()->first())->game_mode
                : null,
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
        // Operator-only (route is auth-gated). Step 0 comes AFTER the founder
        // account by construction (index() sends a userless box to
        // /setup/operator first), so this never locks a founder out; it stops a
        // guest on a public box renaming the instance or re-pointing its address.
        abort_unless((bool) $request->user()?->is_operator, 403);

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
        // Operator-only (the route is auth-gated; require the operator flag so a
        // self-registered citizen can't author the world's constitution). The
        // founder account is created BEFORE any wizard step by design — index()
        // sends a userless instance to /setup/operator first — so this can never
        // lock a legitimate founder out of step 1.
        abort_unless((bool) $request->user()?->is_operator, 403);

        // Founding property: LOCK once setup completes. Constitutional settings
        // are amendable ONLY through F-LEG-031 → EnactmentService, which writes
        // the setting_changes ledger, the audit chain and the enacting act. This
        // path writes constitutional_settings RAW (writeConstitutionalSettings),
        // so leaving it open after founding would let every governed key —
        // including judiciary_is_elected, the sole DUAL_DOOR_KEYS entry — be
        // rewritten with no act, no ledger row and no audit entry, defeating the
        // dual door entirely. Mirrors saveGameMode's founding lock.
        if (InstanceSettings::current()->isSetupComplete()) {
            return response()->json([
                'error' => 'The constitution is authored at founding. Once setup is complete, settings change only through an act of a legislature (F-LEG-031).',
            ], 409);
        }

        $data = $request->validate([
            'legislature_min_seats'             => ['required', 'integer', 'min:1'],
            'legislature_max_seats'             => ['required', 'integer', 'min:1'],
            'legislature_sizing_law'            => ['required', Rule::in(['cube_root'])],
            // Default line-split method for childless leaf giants (mixed
            // autoseed, 2026-07-17). Mirrors SubdivisionAutoseedService::TEMPLATES.
            'districting_autoseed_template'     => ['required', Rule::in(\App\Services\Districting\SubdivisionAutoseedService::TEMPLATES)],
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
            // Economy defaults (v3 mockup — Constitution & Economy). The
            // currency's existence is root-reserved (Art. V §5); these founding
            // defaults are amendable, so they live in constitutional_settings.
            'currency_name'                     => ['required', 'string', 'max:64'],
            'currency_code'                     => ['required', 'string', 'max:8'],
            'currency_symbol'                   => ['required', 'string', 'max:8'],
            'civic_stipend_floor'               => ['required', 'integer', 'min:0'],
            'stipend_bump_cap'                  => ['required', 'integer', 'min:0'],
            'pay_node_operator'                 => ['required', 'integer', 'min:0'],
            'pay_social_moderator'              => ['required', 'integer', 'min:0'],
            'pay_office_holder'                 => ['required', 'integer', 'min:0'],
            'stipend_interval'                  => ['required', Rule::in(['monthly', 'quarterly', 'per_cycle'])],
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
            'districting_autoseed_template'     => 'shortest',
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
            // Economy defaults (v3 mockup).
            'currency_name'                     => 'Civic Value Unit',
            'currency_code'                     => 'CVU',
            'currency_symbol'                   => 'ç',
            'civic_stipend_floor'               => 50,
            'stipend_bump_cap'                  => 20,
            'pay_node_operator'                 => 8,
            'pay_social_moderator'              => 5,
            'pay_office_holder'                 => 12,
            'stipend_interval'                  => 'monthly',
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
                            'districting_autoseed_template',
                            'voting_method',
                            'currency_name',
                            'currency_code',
                            'currency_symbol',
                            'stipend_interval'                  => (string) $row->$k,
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
        // Operator-only (route is auth-gated) — same posture as pull-start: an
        // ETL run is a state change a guest must never be able to trigger.
        abort_unless((bool) $request->user()?->is_operator, 403);

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
            // For source=download: which official datasets to fetch first.
            'download_datasets'   => ['nullable', 'array'],
            'download_datasets.*' => [Rule::in(['geoboundaries', 'worldpop', 'protomaps'])],
            // Download dataset variants (all optional — sensible defaults if unset).
            // WorldPop and geoBoundaries publish several products; let the operator pick.
            'wp_year'             => ['nullable', 'integer', 'min:2000', 'max:2030'],
            'wp_variant'          => ['nullable', Rule::in(['constrained', 'unconstrained'])],
            'wp_resolution'       => ['nullable', Rule::in(['100m', '1km'])],
            'wp_un_adjusted'      => ['nullable', 'boolean'],
            'gb_release'          => ['nullable', Rule::in(['gbOpen', 'gbHumanitarian', 'gbAuthoritative'])],
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

        // `download` fetches the official open datasets into the etl volume
        // first, then ingests (the supervisor runs download_datasets.py, then
        // seed_database.py against the freshly-downloaded /data). At least one
        // dataset must be selected. `upload` (browser multipart) is still a
        // forward-compat placeholder.
        $downloadDatasets = [];
        if ($data['source'] === 'download') {
            $downloadDatasets = array_values(array_unique($data['download_datasets'] ?? []));
            if (empty($downloadDatasets)) {
                return response()->json([
                    'error' => 'Choose at least one dataset to download (jurisdiction boundaries and/or population).',
                ], 422);
            }
            // WorldPop population requires the boundaries to attribute to — if
            // population is requested, boundaries must come along.
            if (in_array('worldpop', $downloadDatasets, true) && ! in_array('geoboundaries', $downloadDatasets, true)) {
                $downloadDatasets[] = 'geoboundaries';
            }
        }
        if ($data['source'] === 'upload') {
            return response()->json([
                'error' => 'Browser upload is not yet wired. Use the local archive, a custom folder, or a fresh download for now.',
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
                // source=download: the supervisor runs download_datasets.py for
                // these before seeding. Empty for archive/folder. An EMPTY
                // countries list means ALL countries (a full-world download) —
                // the downloader honors that; the UI warns about the size.
                'download_datasets'  => $downloadDatasets,
                // Download dataset variants (null = the downloader's default).
                'wp_year'            => $data['wp_year']        ?? null,
                'wp_variant'         => $data['wp_variant']     ?? null,
                'wp_resolution'      => $data['wp_resolution']  ?? null,
                'wp_un_adjusted'     => (bool) ($data['wp_un_adjusted'] ?? false),
                'gb_release'         => $data['gb_release']     ?? null,
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
     * POST /api/setup/wizard/step2/pull-start — start a GEODATA PULL ENGINE run
     * (GEODATA_PULL_ENGINE_PLAN.md): multithreaded, incrementally reprocessable,
     * per-worker-visible ingestion. Creates the geodata_runs row + the manifest
     * item, then writes request.json with mode="pull" so the supervisor enters
     * pool mode. The legacy single-threaded startMapData path is untouched.
     */
    public function startGeodataPull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source'       => ['nullable', Rule::in(['archive', 'folder'])],
            'data_root'    => ['nullable', 'string', 'max:512'],
            'countries'    => ['nullable', 'array'],
            'countries.*'  => ['string', 'size:3'],
            'adm_levels'   => ['nullable', 'array'],
            'adm_levels.*' => ['integer', 'min:0', 'max:5'],
            'dry_run'      => ['nullable', 'boolean'],
            // FRESH means FRESH (operator-caught 2026-08-05: the dropdown's
            // "Fresh run" started a new run OVER the existing planet — an
            // idempotent warm re-pass wearing a fresh label). fresh=true
            // purges the geodata domain first so the run reproduces the
            // planet FROM SOURCE: no inherited rows, no surviving half-state,
            // a benchmark that means something.
            'fresh'        => ['nullable', 'boolean'],
            // Operator ruling 2026-08-29: the acceptance scan is OPTIONAL.
            // true (default) = the scan runs automatically after finalize;
            // false = the run completes without it and the panel offers a
            // run-scan button. Toggleable mid-run via pull-option below.
            'auto_scan'    => ['nullable', 'boolean'],
        ]);

        $controlDir = $this->etlControlDir();
        if (! is_dir($controlDir) && ! @mkdir($controlDir, 0777, true)) {
            return response()->json(['error' => 'Could not create ETL control directory.'], 500);
        }
        // FRESH TAKES OVER, FROM ANY STATE (operator, 2026-08-05, verbatim:
        // "IF A RUN IS FUCKED UP THEN WE NEED TO USE THE BUTTON" — the Fresh
        // button IS the escape hatch, so it can never be blocked by the very
        // run it exists to escape). No refusals: any unfinished run is
        // abandoned on the spot and its stale control file cleared. Straggler
        // workers self-terminate: the purge below truncates geodata_items
        // FIRST, so every held claim vanishes and the next ~20s heartbeat
        // returns not-ours, killing the child. A straggler's last inserts
        // race the new planet only with IDENTICAL source rows (ON CONFLICT
        // slug no-ops) — accepted, and the scan would flag anything odd.
        if ((bool) ($data['fresh'] ?? false)) {
            $stale = \App\Models\GeodataRun::unfinished();
            if ($stale !== null) {
                $stale->forceFill(['status' => 'abandoned', 'updated_at' => now()])->save();
                @unlink($controlDir.'/running.json');
            }
        }

        if (is_file($controlDir.'/running.json')) {
            return response()->json(['error' => 'An ETL run is already in progress.'], 409);
        }
        if (\App\Models\GeodataRun::unfinished() !== null) {
            return response()->json(['error' => 'A geodata run is already active. Halt it before starting a new one.'], 409);
        }

        $bootstrap = $this->bootstrapStatus();
        if (! empty($bootstrap['pending_migrations'])) {
            return response()->json([
                'error' => 'Schema updates are pending. Apply them at /setup/bootstrap before starting an ETL run.',
                'pending_count' => $bootstrap['pending_count'],
            ], 409);
        }

        $source   = $data['source'] ?? 'archive';
        $dataRoot = null;
        if ($source === 'folder') {
            $dataRoot = trim((string) ($data['data_root'] ?? ''));
            if ($dataRoot === '' || $dataRoot[0] !== '/') {
                return response()->json([
                    'error' => 'Custom data root must be an absolute container path (e.g. /archive/snapshots/2026-05).',
                ], 422);
            }
        }

        // THE FRESH PURGE. Only during setup: after map acceptance the planet
        // is load-bearing for governance rows and a wipe is FreshImage's job,
        // not a button's. TRUNCATE is the right tool here — an O(1) metadata
        // operation, not a planet-wide row statement — and CASCADE is safe
        // precisely because the gate guarantees the downstream civic tables
        // are empty at this stage. Reference rows that ride the schema dump
        // (cosmic_addresses, instance_settings, audit genesis) are untouched.
        if ((bool) ($data['fresh'] ?? false)) {
            $accepted = \App\Models\InstanceSettings::query()
                ->whereNull('deleted_at')->value('map_accepted_at');
            if ($accepted !== null) {
                return response()->json([
                    'error' => 'Fresh run refused: the map is accepted and load-bearing. Rewind phases instead, or rebuild the box.',
                ], 409);
            }
            // CLEAR THE FIELD FIRST (2026-08-05, the 500: a straggler's raster
            // read held a lock, the TRUNCATE queued behind it, the gateway
            // timed the request out and the cancel left a half-purged state).
            // The hatch never waits on the run it is escaping: terminate every
            // other active backend, then truncate into a quiet database. The
            // panel's poll queries just retry on their next tick.
            DB::select(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
                  WHERE datname = current_database()
                    AND pid <> pg_backend_pid() AND state <> 'idle'"
            );

            // KEEP THE AUTHORED CONSTITUTION (setup-loop audit, 2026-08-23).
            // The purge below deletes constitutional_settings, and the planet
            // the re-run builds gets a DB-DEFAULT row from the importer. If
            // Step 1 had already written the founder's values straight onto a
            // planet row (saveConstants does that — and nulls the stash —
            // whenever a planet exists), a Fresh run would silently replace
            // the authored constitution and economy with template defaults.
            // Re-stash them now so activateStep1 re-applies them exactly as a
            // first run would. A stash that already holds values wins.
            $instance = InstanceSettings::current();
            if (! is_array($instance->pending_constitutional_defaults) && $this->resolveRootJurisdiction() !== null) {
                $instance->pending_constitutional_defaults = $this->currentConstitutionalDefaults($instance);
                $instance->save();
                \App\Services\ConstitutionalDefaults::flush();
            }

            // Items + leases FIRST: every held claim vanishes, so straggler
            // workers from a superseded run fail their next heartbeat and
            // kill their children. Unconditional — a virgin box no-ops.
            foreach (['geodata_items', 'geodata_worker_leases', 'geodata_flags',
                      'geodata_runs', 'worldpop_rasters', 'geoboundary_metadata'] as $t) {
                DB::statement("TRUNCATE TABLE {$t} CASCADE");
            }
            // THE DOCKET RAIL FORBIDS TRUNCATE (caught live 2026-08-05:
            // TRUNCATE jurisdictions CASCADE reaches case_filings, whose
            // append-only trigger RAISEs even on an empty table — "nothing
            // argued in open court is sealed retroactively"). The rail is
            // LAW; the channel changes: chunked row DELETEs. Empty civic
            // tables cascade zero rows; a world holding real filings fails
            // loudly, which is correct — and the accepted-map gate above
            // already refuses such worlds. Deepest level first for the
            // self-referencing parent FK; known row-holders first.
            // Chunk sizes derive from host memory (audit row, 2026-08-30):
            // ~6,500 narrow rows / GB and ~2,600 wide (geometry-bearing)
            // rows / GB reproduce the proven 50k/20k on the 8 GB reference
            // box and scale both directions.
            $narrowChunk = (int) max(10000, min(200000, \App\Support\HostCapacity::hostMemoryGb() * 6500));
            $wideChunk   = (int) max(5000, min(80000, \App\Support\HostCapacity::hostMemoryGb() * 2600));
            foreach (['residency_confirmations', 'location_pings',
                      'constitutional_settings'] as $t) {
                do {
                    $n = DB::delete(
                        "DELETE FROM {$t} WHERE ctid IN (SELECT ctid FROM {$t} LIMIT {$narrowChunk})");
                } while ($n > 0);
            }
            for ($level = 6; $level >= 0; $level--) {
                do {
                    $n = DB::delete(
                        "DELETE FROM jurisdictions WHERE id IN
                           (SELECT id FROM jurisdictions WHERE adm_level = ? LIMIT {$wideChunk})",
                        [$level]);
                } while ($n > 0);
            }
            Log::info('geodata pull-start: FRESH purge complete — planet rebuilds from source');
        }

        $options = [
            'countries'  => array_values($data['countries'] ?? []),
            'adm_levels' => array_values($data['adm_levels'] ?? []),
            'source'     => $source,
            'dry_run'    => (bool) ($data['dry_run'] ?? false),
            'auto_scan'  => (bool) ($data['auto_scan'] ?? true),
        ];

        $run = \App\Models\GeodataRun::create([
            'status'            => 'running',
            'phase'             => 'enumerating',
            'data_root'         => $dataRoot,
            'options'           => $options,
            'initiator_user_id' => $request->user()?->id,
        ]);
        \App\Models\GeodataItem::create([
            'run_id'   => $run->id,
            'kind'     => 'manifest',
            'status'   => 'pending',
            'position' => 0,
        ]);

        file_put_contents(
            $controlDir.'/request.json',
            json_encode([
                'submitted_at' => now()->toIso8601String(),
                'mode'         => 'pull',
                'source'       => $source,
                'run_id'       => $run->id,
                'options'      => $options,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Park the pump now so the run advances without waiting a full minute.
        \Illuminate\Support\Facades\Artisan::call('geodata:pump');

        return response()->json(['accepted' => true, 'run_id' => $run->id]);
    }

    /**
     * POST /api/setup/wizard/step2/pull-option {auto_scan: bool} — flip the
     * acceptance-scan checkbox on the ACTIVE run while it is still running
     * (operator ruling 2026-08-29: the scan is optional; the choice can be
     * made any time before finalize completes). No active run: 409.
     */
    public function setGeodataPullOption(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_scan' => ['required', 'boolean'],
        ]);

        $run = \App\Models\GeodataRun::unfinished();
        if ($run === null) {
            return response()->json(['error' => 'No active geodata run.'], 409);
        }

        $options = (array) ($run->options ?? []);
        $options['auto_scan'] = (bool) $data['auto_scan'];
        $run->forceFill(['options' => $options, 'updated_at' => now()])->save();

        return response()->json(['ok' => true, 'auto_scan' => $options['auto_scan']]);
    }

    /**
     * GET /api/setup/wizard/step2/pull-progress — the pull engine dashboard feed:
     * the run row, per-phase item bars (one GROUP BY), the per-worker claim strip
     * (byte-compatible with the Step-3 autoscale strip), and the review census.
     */
    public function geodataPullProgress(): JsonResponse
    {
        $run = \App\Models\GeodataRun::query()
            ->orderByDesc('created_at')->first();
        if ($run === null) {
            return response()->json(['run' => null]);
        }

        $DB = \Illuminate\Support\Facades\DB::class;
        $layers = $DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                kind,
                COUNT(*)                                          AS total,
                COUNT(*) FILTER (WHERE status = 'done')           AS done,
                COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                COUNT(*) FILTER (WHERE status = 'running')        AS running,
                COUNT(*) FILTER (WHERE status = 'review')         AS review,
                COUNT(*) FILTER (WHERE status = 'failed')         AS failed
            ")
            ->groupBy('kind')->get();

        $order  = array_flip(array_values(\App\Models\GeodataRun::PHASE_KIND));
        $layers = $layers->sortBy(fn ($r) => $order[$r->kind] ?? 99)->values();

        $workers = $DB::table('geodata_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->orderBy('started_at')
            ->get(['id', 'claim_type', 'claim_label', 'claim_started_at']);

        // In-flight items with their live per-feature progress (metrics.live,
        // written by the etl_unit child's bar hooks) — the pull panel's
        // per-country mini bars, the legacy stacked-bars detail reborn.
        $inflight = $DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->where('status', 'running')
            ->orderBy('started_at')
            ->get(['id', 'kind', 'iso_code', 'adm_level', 'metrics', 'started_at']);

        // Acceptance-scan live progress (operator ask 2026-08-02: "I don't
        // see the acceptance scan progress"): the scan is Laravel-side and
        // never writes Python-style item bars, but GeodataFlagService
        // already caches per-detector completion — inject it as the scan
        // item's live bar so the panel renders it like every other row.
        foreach ($inflight as $it) {
            if ($it->kind !== 'acceptance_scan') {
                continue;
            }
            // Continuous progress from the category jobs' iso-batch writes:
            // cats = completed detectors, cats_progress = [done, total,
            // flags] per in-flight detector. Bar unit = iso-batches across
            // all six detectors, ticking every few seconds.
            $m = json_decode($it->metrics ?? '{}', true) ?: [];
            $cats     = $m['cats'] ?? [];
            $prog     = $m['cats_progress'] ?? [];
            $perCat   = 0;
            foreach ($prog as $p) {
                $perCat = max($perCat, (int) ($p[1] ?? 0));
            }
            $perCat = max($perCat, 1);
            $totalUnits = count(\App\Models\GeodataFlag::CATEGORIES) * $perCat;
            $current = count($cats) * $perCat;
            $flagsSoFar = 0;
            $labels = [];
            foreach ($prog as $cat => $p) {
                if (! array_key_exists($cat, $cats)) {
                    $current += min((int) ($p[0] ?? 0), $perCat);
                    $labels[] = $cat . ' ' . (int) ($p[0] ?? 0) . '/' . (int) ($p[1] ?? 0);
                }
                $flagsSoFar += max(0, (int) ($p[2] ?? 0));
            }
            $m['live'] = [
                'label'   => $labels === []
                    ? (count($cats) === 0 ? 'scan starting — 6 detectors'
                                          : count($cats) . ' detectors done')
                    : implode(' · ', array_slice($labels, 0, 3))
                      . ' — ' . number_format($flagsSoFar) . ' flags',
                'current' => $current,
                'total'   => $totalUnits,
                'unit'    => 'iso-batches',
            ];
            $it->metrics = json_encode($m);
        }

        $review = $DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->where('status', 'review')
            ->orderBy('kind')->limit(200)
            ->get(['kind', 'iso_code', 'adm_level', 'reason']);

        // Overall jurisdictions progress (operator ask 2026-08-02: "I miss
        // the bars with the overall counts of jurisdictions being loaded").
        // expected = the geoBoundaries metadata census — the same yardstick
        // the acceptance audit uses; loaded = live planet row count.
        // CACHED (8 s): these are planet-scale counts and the panel polls
        // every 2 s — uncached they were measurable load beside the resolve
        // pass's own joins (observed live: several concurrent count backends).
        [$worldLoaded, $worldExpected, $resolve, $levelStats, $fileStats] = cache()->remember(
            'geodata-pull-progress-counts:' . $run->id . ':' . $run->phase,
            8,
            function () use ($DB, $run) {
                $loaded   = $DB::table('jurisdictions')->whereNull('deleted_at')->count();
                $expected = (int) $DB::table('geoboundary_metadata')->sum('adm_unit_count');

                // Resolve-phase incremental visibility ("the Resolve pass is
                // looking opaque"): with parenting deferred to this barrier,
                // the honest live signal is the unparented ADM2+ count
                // DRAINING as each set-based strategy pass commits.
                $resolve = null;
                if ($run->phase === 'resolving') {
                    $resolve = [
                        'total' => $DB::table('jurisdictions')
                            ->whereNull('deleted_at')->where('adm_level', '>', 1)->count(),
                        'unparented' => $DB::table('jurisdictions')
                            ->whereNull('deleted_at')->where('adm_level', '>', 1)
                            ->whereNull('parent_id')->count(),
                    ];
                }

                // Per-level population counts (operator ask 2026-08-02: "I
                // miss the by-level population counts") — the legacy panel's
                // per-ADM census, reborn: rows fill in live as attribution
                // applies each level.
                $levels = $DB::table('jurisdictions')
                    ->whereNull('deleted_at')->where('adm_level', '>', 0)
                    ->selectRaw("adm_level,
                                 COUNT(*) AS rows,
                                 COUNT(*) FILTER (WHERE population > 0) AS with_pop,
                                 COALESCE(SUM(population), 0)::bigint AS pop_sum")
                    ->groupBy('adm_level')->orderBy('adm_level')->get();

                // FILE-SLICE DENOMINATORS (operator, 2026-08-03: "the
                // boundaries bar shouldn't be 232, it should be the number of
                // ISO/ADM level slices"). A boundary ITEM is one country, but
                // a country carries several ADM files — so 232 understates the
                // work and moves in coarse jumps. geoboundary_metadata holds
                // exactly one row per (iso, adm_level), i.e. the true file
                // count, known in advance; progress is the (iso, level) pairs
                // that actually have rows.
                $filesTotal  = (int) $DB::table('geoboundary_metadata')->count();
                $filesLoaded = (int) $DB::table('jurisdictions')
                    ->whereNull('deleted_at')->where('adm_level', '>', 0)
                    ->distinct()->count($DB::raw('(iso_code, adm_level)'));

                // Synthesis can create (iso, level) combos the archive never
                // shipped — PRI's L1 is the standing example — so loaded can
                // legitimately exceed the manifest. Take the larger as the
                // denominator rather than rendering 101%.
                return [$loaded, $expected, $resolve, $levels,
                        ['loaded' => $filesLoaded,
                         'total'  => max($filesTotal, $filesLoaded)]];
            }
        );

        return response()->json([
            'run' => [
                'id'               => $run->id,
                'status'           => $run->status,
                'phase'            => $run->phase,
                'items_total'      => $run->items_total,
                'items_done'       => $run->items_done,
                'items_review'     => $run->items_review,
                'items_failed'     => $run->items_failed,
                'phase_timestamps' => $run->phase_timestamps,
                'last_error'       => $run->last_error,
                'halt_requested'   => $run->haltRequested(),
                'paused'           => $run->isPaused(),
                // NEEDS-OPERATOR review hold: the phase whose automatic
                // half-lane retry could not clear its review residue, plus how
                // many items remain, so the UI can offer Retry / Continue.
                // Null when no phase is waiting on the operator.
                'review_hold'      => $this->geodataReviewHold($run),
                // The optional-scan checkbox state (operator ruling
                // 2026-08-29) and whether this run's scan item settled as
                // skipped — the panel shows the on-demand scan button then.
                'auto_scan'        => (bool) (($run->options['auto_scan'] ?? true)),
                'scan_skipped'     => (bool) $DB::table('geodata_items')
                    ->where('run_id', $run->id)
                    ->where('kind', 'acceptance_scan')
                    ->where('metrics->skipped', true)
                    ->exists(),
            ],
            'layers'   => $layers,
            'workers'  => $workers,
            'inflight' => $inflight,
            'review'   => $review,
            'world'    => ['loaded' => $worldLoaded, 'expected' => $worldExpected ?: null],
            'resolve'  => $resolve,
            'levels'   => $levelStats,
            'files'    => $fileStats,
            // The six scan detectors run LARAVEL-side in Horizon, so they
            // never appear in the worker strips above (those are Python ETL
            // leases). Without this the operator sees a single opaque "Scan"
            // item and concludes it is single-lane — it is not: cat_started
            // shows five detectors dispatching within ~3s of each other.
            'scan'     => $this->geodataScanDetectors($run),
        ]);
    }

    /**
     * Per-detector state for the acceptance scan (Stage S §4).
     *
     * Derived from the scan item's own metrics — no extra queries, no cost.
     * Each detector is: pending (in neither marker nor results) · running
     * (started, no result yet — with elapsed) · done (result >= 0, with its
     * flag count) · error (result -1 or named in cat_errors).
     *
     * @return array{state:string, detectors:list<array>}|null
     */
    private function geodataScanDetectors($run): ?array
    {
        $item = DB::table('geodata_items')
            ->where('run_id', $run->id)->where('kind', 'acceptance_scan')
            ->first(['status', 'metrics']);
        if ($item === null) {
            return null;
        }
        $m       = json_decode($item->metrics ?? '{}', true) ?: [];
        $cats    = $m['cats'] ?? [];
        $started = $m['cat_started'] ?? [];
        $errors  = $m['cat_errors'] ?? [];

        $detectors = [];
        foreach (\App\Models\GeodataFlag::CATEGORIES as $cat) {
            $flags   = $cats[$cat] ?? null;
            $ageS    = isset($started[$cat])
                ? microtime(true) - (float) $started[$cat] : null;
            if (isset($errors[$cat]) || (int) $flags < 0 && $flags !== null) {
                $state = 'error';
            } elseif ($flags !== null) {
                $state = 'done';
            } elseif ($ageS !== null) {
                // STARTED-BUT-NO-RESULT IS NOT THE SAME AS RUNNING
                // (2026-08-03): a detector killed mid-query leaves its
                // cat_started marker behind, so the chip claimed "running"
                // with a forever-climbing clock while nothing was executing.
                // Past the pump's own re-dispatch horizon (30 min, the
                // engine's stale-claim constant) the honest word is STALLED
                // — and that is exactly when the pump will retry it.
                $state = $ageS > 1800 ? 'stalled' : 'running';
            } else {
                $state = 'pending';
            }
            $detectors[] = [
                'key'       => $cat,
                'label'     => ucwords(str_replace('_', ' ', $cat)),
                'state'     => $state,
                'flags'     => $state === 'done' ? (int) $flags : null,
                'elapsed_s' => isset($started[$cat])
                    ? max(0, (int) round(microtime(true) - (float) $started[$cat]))
                    : null,
                'error'     => $errors[$cat] ?? null,
            ];
        }

        return ['state' => (string) $item->status, 'detectors' => $detectors];
    }

    /**
     * POST /api/setup/wizard/step2/pull-control — halt / resume the pull run.
     * DB-flag based (the Python workers stop at their next claim boundary).
     */
    public function geodataPullControl(Request $request): JsonResponse
    {
        $data = $request->validate([
            // review_retry / review_continue added 2026-08-05: when a GROUP's
            // one automatic half-lane retry cannot clear its review residue,
            // the pump holds and hands the decision here (see reviewGateHolds).
            'action' => ['required', Rule::in(['halt', 'resume', 'review_retry', 'review_continue', 'rescan', 'rewind'])],
            'group'  => ['nullable', Rule::in(['ingest', 'derive'])],
            'target' => ['nullable', Rule::in(['scan', 'resolve', 'attribute', 'resolve_attribute',
                                              'boundaries', 'rasters', 'boundaries_rasters'])],
        ]);

        // REWIND (operator order 2026-08-05): re-run any completed phase IN
        // PLACE against the already-loaded planet — no fresh ingestion to
        // field-test a fix. The clock rewinds to the chosen point and moves
        // forward: the target's items reset to pending, its RANGE children
        // are deleted (their coordinators re-enumerate them idempotently),
        // and everything strictly DOWNSTREAM resets with it. Paired phases
        // (boundaries/rasters, resolve/attribution) rewind independently or
        // together — rewinding one sibling never touches the other. All the
        // per-item work is idempotent by the engine's crash-safety contract
        // (ON CONFLICT / DELETE-first / versioned), so a re-run lands the
        // same planet. 'rescan' is the scan-only alias. Acceptance stays
        // available throughout — the scan is advisory, never a gate.
        if (in_array($data['action'], ['rescan', 'rewind'], true)) {
            $target = $data['action'] === 'rescan' ? 'scan' : ($data['target'] ?? null);
            if ($target === null) {
                return response()->json(['error' => 'rewind requires a target.'], 422);
            }
            // ANY STATE (operator, 2026-08-05 — the same escape-hatch law as
            // Fresh: rewind is a recovery control, so it seizes a mid-flight
            // run too). Seizure is safe by the claim machinery: reset items
            // lose their claim_token, workers' next heartbeat returns
            // not-ours, children die within ~20s. Leases cleared so the
            // panel's worker view restarts honest.
            $run = \App\Models\GeodataRun::query()->orderByDesc('created_at')->first();
            if ($run === null) {
                return response()->json(['error' => 'No run to rewind.'], 409);
            }
            DB::table('geodata_worker_leases')->where('run_id', $run->id)->delete();
            // [reset parent kinds, delete child kinds, rewind phase pointer]
            $derivDel = ['resolve_range', 'attribution_pair', 'attribution_range', 'attribution_decompose'];
            $plan = [
                'scan'               => [['acceptance_scan'], [], 'scanning'],
                'attribute'          => [['attribution_pair', 'finalize_global', 'acceptance_scan'],
                                         ['attribution_range', 'attribution_decompose'], 'attribution'],
                'resolve'            => [['resolve_global', 'finalize_global', 'acceptance_scan'],
                                         ['resolve_range'], 'resolving'],
                'resolve_attribute'  => [['resolve_global', 'attribution_pair', 'finalize_global', 'acceptance_scan'],
                                         ['resolve_range', 'attribution_range', 'attribution_decompose'], 'resolving'],
                'boundaries'         => [['boundary_iso', 'resolve_global', 'finalize_global', 'acceptance_scan'],
                                         array_merge(['boundary_range'], $derivDel), 'boundaries'],
                'rasters'            => [['raster_iso', 'resolve_global', 'finalize_global', 'acceptance_scan'],
                                         array_merge(['raster_range'], $derivDel), 'rasters'],
                'boundaries_rasters' => [['boundary_iso', 'raster_iso', 'resolve_global', 'finalize_global', 'acceptance_scan'],
                                         array_merge(['boundary_range', 'raster_range'], $derivDel), 'boundaries'],
            ];
            [$resetKinds, $deleteKinds, $phase] = $plan[$target];
            if ($deleteKinds !== []) {
                DB::table('geodata_items')->where('run_id', $run->id)
                    ->whereIn('kind', $deleteKinds)->delete();
            }
            DB::table('geodata_items')->where('run_id', $run->id)
                ->whereIn('kind', $resetKinds)->update([
                    'status' => 'pending', 'claim_token' => null, 'started_at' => null,
                    'finished_at' => null, 'reason' => null, 'metrics' => null,
                    'updated_at' => now(),
                ]);
            // The clock: drop timestamps for the rewound phase and everything
            // after it; clear the review markers the rewind invalidates.
            $order  = \App\Models\GeodataRun::PHASES;
            $stamps = $run->phase_timestamps ?? [];
            foreach (array_slice($order, (int) array_search($phase, $order, true)) as $p) {
                unset($stamps[$p]);
            }
            foreach (['_review_pass', '_review_hold', '_review_serial', '_accepted'] as $k) {
                unset($stamps[$k]['derive']);
                if (in_array($target, ['boundaries', 'rasters', 'boundaries_rasters'], true)) {
                    unset($stamps[$k]['ingest']);
                }
            }
            $run->forceFill(['phase' => $phase, 'status' => 'running', 'review_pass' => null,
                             'phase_timestamps' => $stamps, 'updated_at' => now()])->save();
            if ($target === 'scan') {
                \App\Jobs\GeodataAcceptanceScanJob::dispatch((string) $run->id);
            }

            return response()->json(['ok' => true, 'rewound_to' => $phase]);
        }

        $run  = \App\Models\GeodataRun::unfinished();
        if ($run === null) {
            return response()->json(['error' => 'No active geodata run.'], 409);
        }

        if ($data['action'] === 'halt' || $data['action'] === 'resume') {
            $run->update(['halt_requested_at' => $data['action'] === 'halt' ? now() : null]);
        } else {
            // RETRY / CONTINUE a stuck review. Target the group the operator
            // acted on, defaulting to whichever group is currently held.
            $group  = $data['group']
                ?: array_key_first($run->phase_timestamps['_review_hold'] ?? []);
            $stamps = $run->phase_timestamps ?? [];

            if ($group !== null) {
                if ($data['action'] === 'review_retry') {
                    // Clear the one-bite + hold + serial-ladder markers so the
                    // gate re-runs the FULL automatic ladder (joint → serial)
                    // for this group on the next tick.
                    unset($stamps['_review_pass'][$group], $stamps['_review_hold'][$group],
                          $stamps['_review_serial'][$group]);
                } else { // review_continue
                    // Accept the residue: the gate stops holding and the run
                    // crosses into the next group. Recorded, not silent.
                    $stamps['_accepted'][$group] = now()->toIso8601String();
                    unset($stamps['_review_hold'][$group]);
                }
                $run->forceFill(['phase_timestamps' => $stamps, 'updated_at' => now()])->save();
            }
        }

        \Illuminate\Support\Facades\Artisan::call('geodata:pump');

        return response()->json(['ok' => true, 'status' => $run->fresh()->status]);
    }

    /**
     * The phase (if any) whose automatic review retry could not clear its
     * residue and is now waiting on the operator — the signal the Step-2 UI
     * uses to show Retry / Continue. Mirrors GeodataPumpCommand::phaseGateKinds
     * so the count reported matches exactly what the gate is holding on.
     */
    private function geodataReviewHold(\App\Models\GeodataRun $run): ?array
    {
        // Keyed by GROUP now (ingest / derive), matching the pump's per-group
        // review gate — the review is a step BETWEEN the two groups, not a
        // per-phase event.
        $held = array_key_first($run->phase_timestamps['_review_hold'] ?? []);
        if ($held === null) {
            return null;
        }
        $kinds = match ($held) {
            'ingest' => ['boundary_iso', 'boundary_range', 'raster_iso', 'raster_range'],
            'derive' => ['resolve_global', 'resolve_range', 'attribution_pair',
                         'attribution_decompose', 'attribution_range'],
            default  => [],
        };
        $bad = DB::table('geodata_items')->where('run_id', $run->id)
            ->whereIn('kind', $kinds)->whereIn('status', ['review', 'failed'])->count();

        $label = $held === 'ingest' ? 'Boundaries + Rasters' : 'Resolve + Attribution';

        return [
            'group'      => $held,
            'label'      => $label,
            'unresolved' => $bad,
            // When the hold began — the panel freezes the work timers here
            // and ticks a separate "waiting on you" clock (2026-08-06: the
            // run-timer kept climbing through a 5-hour hold and read as
            // progress; the operator's words: "that hides reality").
            'since'      => $run->phase_timestamps['_review_hold'][$held] ?? null,
        ];
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
        // Operator-only (route is auth-gated) — same posture as pull-control.
        // This protects WHO may drive the run, not the run's state: the
        // escape-hatch law (a recovery control is never blocked by the state it
        // recovers from) is untouched — an operator's halt/resume always lands.
        abort_unless((bool) $request->user()?->is_operator, 403);

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
    public function completeStep3(Request $request): JsonResponse
    {
        // Operator-only (route is auth-gated): a step advance is the founder's act.
        abort_unless((bool) $request->user()?->is_operator, 403);

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
     * by the autoscale run (sizing phase).
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
     * GET /api/setup/wizard/step3/autoscale-progress — the Step-3 dashboard's
     * poll target during a full-scale autoscale run (2 s cadence, same
     * pattern as Step 2's mapDataProgress).
     *
     * Returns the newest run with counters, live items, rates/ETA, and the
     * review list. `run: null` means no autoscale has ever been started
     * (pre-acceptance, or a legacy box).
     */
    public function autoscaleProgress(): JsonResponse
    {
        $run = \App\Models\AutoscaleRun::query()->orderByDesc('created_at')->first();
        if ($run === null) {
            return response()->json([
                'run' => null,
                'type_b_flagged' => (int) DB::table('legislatures')
                    ->where('type_b_needs_districting', true)->whereNull('deleted_at')->count(),
            ]);
        }

        // Live + review slices (names joined for the dashboard's tables).
        // Under the pull engine the live sweep view is the SCOPE list — the
        // real in-flight work units (Earth's provinces show individually).
        $liveItems = DB::table('autoscale_scopes as s')
            ->join('jurisdictions as j', 'j.id', '=', 's.scope_jurisdiction_id')
            ->join('autoscale_items as ai', 'ai.id', '=', 's.item_id')
            ->where('s.run_id', $run->id)
            ->where('s.status', 'running')
            ->orderBy('s.started_at')
            ->limit(15)
            ->get([
                's.legislature_id', 's.scope_jurisdiction_id as jurisdiction_id',
                'ai.adm_level', 'ai.kind', 's.status', 's.started_at', 's.depth',
                'j.name as jurisdiction_name', 'j.slug as jurisdiction_slug',
            ]);

        $reviewItems = DB::table('autoscale_items as ai')
            ->join('jurisdictions as j', 'j.id', '=', 'ai.jurisdiction_id')
            ->where('ai.run_id', $run->id)
            ->whereIn('ai.status', ['review', 'failed', 'halted'])
            ->orderBy('ai.position')
            ->limit(100)
            ->get([
                'ai.legislature_id', 'ai.jurisdiction_id', 'ai.adm_level',
                'ai.kind', 'ai.status', 'ai.reason', 'ai.seats_expected',
                'ai.seats_seated', 'ai.drift',
                'j.name as jurisdiction_name', 'j.slug as jurisdiction_slug',
            ]);

        // DRIFTED-DONE DRILLDOWN (operator order 2026-08-29, "the same thing
        // must be true in the interface"): completed maps whose NET drift
        // (seats − bonus, per the 08-28 law — the writer now nets) misses
        // the budget render as clickable rows exactly like the review list,
        // so the operator walks straight into each mapper without asking.
        $driftedItems = DB::table('autoscale_items as ai')
            ->join('jurisdictions as j', 'j.id', '=', 'ai.jurisdiction_id')
            ->leftJoin('legislature_district_maps as m', function ($join) {
                $join->on('m.legislature_id', '=', 'ai.legislature_id')
                    ->where('m.status', 'active')->whereNull('m.deleted_at');
            })
            ->where('ai.run_id', $run->id)
            ->where('ai.status', 'done')
            ->whereRaw('COALESCE(ai.drift, 0) <> 0')
            ->orderByRaw('abs(ai.drift) DESC')
            ->limit(100)
            ->get([
                'ai.legislature_id', 'ai.jurisdiction_id', 'ai.adm_level',
                'ai.kind', 'ai.reason', 'ai.seats_expected', 'ai.seats_seated',
                'ai.drift', 'm.id as map_id',
                'j.name as jurisdiction_name', 'j.slug as jurisdiction_slug',
            ]);

        // Σ-seat drift: the count nets lawful bonus lifts (writer + backfill
        // 2026-08-29) — a pure lift map is legal and good, not drift. The
        // attention count covers everything the review table lists
        // (review + failed + halted), so the header never undercounts it.
        $driftRow = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'done' AND COALESCE(drift, 0) <> 0) AS drifted,
                COALESCE(SUM(drift) FILTER (WHERE status = 'done'), 0)              AS net_drift,
                COUNT(*) FILTER (WHERE status IN ('review','failed','halted'))      AS attention
            ")
            ->first();

        // LIVE sizing progress (operator finding, run 2: during Phase A the
        // run-row counters only land at phase end and the page looked
        // frozen). The legislatures count grows row-by-row through sizing —
        // poll it directly; the denominator is the (static) jurisdiction
        // count.
        $sizedLive = null;
        $sizingTotal = null;
        $parentsTotal = null;
        if (in_array($run->status, ['queued', 'sizing'], true)) {
            $sizedLive   = (int) DB::table('legislatures')->whereNull('deleted_at')->count();
            $sizingTotal = (int) Cache::remember('autoscale.sizing_total', 3600, fn () =>
                DB::table('jurisdictions')->whereNull('deleted_at')->count());
            // A1 (operator order 2026-08-29): the denominator for the LIVE
            // parents-pass bar — sized_parents ticks every 200-row chunk and
            // the dashboard renders it as a real bar with rate + ETA. The
            // row-count bar above must never impersonate pass progress again.
            $parentsTotal = (int) Cache::remember('autoscale.parents_total', 300, fn () =>
                DB::table('jurisdictions as p')->whereNull('p.deleted_at')
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))->from('jurisdictions as c')
                          ->whereColumn('c.parent_id', 'p.id')->whereNull('c.deleted_at');
                    })->count());
        }

        // Founding-map mint (operator, 2026-08-29: 75k maps minted in two
        // minutes with zero pixels moving on this page): a live bar for the
        // minting step, denominated by the enumerated item count.
        $mapsMinted = null;
        $mapsTotal  = null;
        if (in_array($run->status, ['queued', 'sizing'], true)) {
            $mapsMinted = (int) DB::table('legislature_district_maps')->count();
            $mapsTotal  = (int) Cache::remember('autoscale.items_total.'.$run->id, 600, fn () =>
                DB::table('autoscale_items')->where('run_id', $run->id)->count());
        }

        // LIVE mapping counters + per-ADM-layer bars — a fresh GROUP BY per
        // poll (the Step-2 pattern: real numbers every 2 s, never the
        // pump's once-a-minute denormalized copies). Index-only on
        // autoscale_items_layers_idx.
        $freshCounts = null;
        $layers = [];
        if (in_array($run->status, ['mapping', 'halted', 'done'], true)) {
            $layerRows = DB::table('autoscale_items')
                ->where('run_id', $run->id)
                ->selectRaw("
                    kind, adm_level,
                    COUNT(*)                                                        AS total,
                    COUNT(*) FILTER (WHERE status = 'done')                         AS done,
                    COUNT(*) FILTER (WHERE status IN ('running','assessing'))       AS running,
                    COUNT(*) FILTER (WHERE status IN ('review','failed'))           AS review
                ")
                ->groupBy('kind', 'adm_level')
                ->orderByRaw('adm_level DESC, kind DESC')
                ->get();

            // ONE ROW PER LEVEL (operator order 2026-08-30): sweeps and leaf
            // councils collapse into a single layer bar, counted by SCOPES
            // (the fluid unit of drawing work) with the jurisdiction counter
            // kept beside it. Labels are the geodata ingestion's canonical
            // names — no ADM jargon user-facing.
            // A scope counts to the MAP it draws (operator catch 2026-08-30:
            // Earth's 81 scopes are all Planet-row work, however deep each
            // scope's own jurisdiction sits), so the grouping key is the
            // ITEM's level, never the scope jurisdiction's.
            $scopeRows = DB::table('autoscale_scopes as s')
                ->join('autoscale_items as ai', 'ai.id', '=', 's.item_id')
                ->where('s.run_id', $run->id)
                ->selectRaw("
                    ai.adm_level,
                    COUNT(*)                                                  AS total,
                    COUNT(*) FILTER (WHERE s.status = 'done')                 AS done,
                    COUNT(*) FILTER (WHERE s.status = 'running')              AS running
                ")
                ->groupBy('ai.adm_level')
                ->get()->keyBy('adm_level');

            $levelLabels = [
                0 => 'Planet', 1 => 'Countries', 2 => 'States / Provinces',
                3 => 'Counties', 4 => 'Municipalities', 5 => 'Townships',
                6 => 'Neighborhoods',
            ];

            $singlesDoneLive = 0;
            $sweepsDoneLive  = 0;
            $byLevel = [];
            foreach ($layerRows as $row) {
                if ($row->kind === 'single') {
                    $singlesDoneLive += (int) $row->done;
                } else {
                    $sweepsDoneLive += (int) $row->done;
                }
                $lvl = (int) $row->adm_level;
                $byLevel[$lvl] = [
                    'total'   => ($byLevel[$lvl]['total'] ?? 0) + (int) $row->total,
                    'done'    => ($byLevel[$lvl]['done'] ?? 0) + (int) $row->done,
                    'running' => ($byLevel[$lvl]['running'] ?? 0) + (int) $row->running,
                    'review'  => ($byLevel[$lvl]['review'] ?? 0) + (int) $row->review,
                ];
            }
            // Top-down (operator order 2026-08-30): the run now works
            // biggest-first, so Planet leads the panel and the leaf layers
            // close it.
            ksort($byLevel);
            foreach ($byLevel as $lvl => $c) {
                $sc = $scopeRows[$lvl] ?? null;
                $layers[] = [
                    'key'           => "level:{$lvl}",
                    'kind'          => 'level',
                    'adm_level'     => $lvl,
                    'label'         => $levelLabels[$lvl] ?? "Level {$lvl}",
                    'total'         => $c['total'],
                    'done'          => $c['done'],
                    'running'       => $c['running'],
                    'review'        => $c['review'],
                    'scopes_total'  => $sc !== null ? (int) $sc->total : 0,
                    'scopes_done'   => $sc !== null ? (int) $sc->done : 0,
                    'scopes_running'=> $sc !== null ? (int) $sc->running : 0,
                    'status'        => $c['done'] >= $c['total']
                        ? 'done'
                        : (($c['running'] > 0 || $c['done'] > 0) ? 'running' : 'pending'),
                ];
            }
            $freshCounts = ['singles_done' => $singlesDoneLive, 'sweeps_done' => $sweepsDoneLive];
        }

        // Precompute bar (global worklist — shown once seeded).
        $precompute = null;
        if ($run->precompute_started_at !== null) {
            $pc = DB::table('jurisdiction_adjacency_parents')
                ->selectRaw("
                    COUNT(*)                                        AS total,
                    COUNT(*) FILTER (WHERE status = 'done')         AS done,
                    COUNT(*) FILTER (WHERE status = 'running')      AS running,
                    COUNT(*) FILTER (WHERE status = 'failed')       AS failed
                ")
                ->first();
            $precompute = [
                'total'   => (int) $pc->total,
                'done'    => (int) $pc->done,
                'running' => (int) $pc->running,
                'failed'  => (int) $pc->failed,
            ];
        }

        // Windowed rates (last 30 min) → honest per-track ETA. The whole-run
        // average lied under bottom-up ordering (cheap leaves first).
        // TRUTH WINDOW (operator order 2026-08-29: "the tile should use the
        // close-to-the-truth numbers"): a 10-minute window converges on the
        // real sweep rate in minutes instead of diluting it across half an
        // hour of pre-sweep phases. The bar-level samplers on the page use
        // the same discipline; the tile now agrees with them.
        $rateRow = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('finished_at', '>', now()->subMinutes(10))
            ->selectRaw("
                COUNT(*) FILTER (WHERE kind = 'sweep'  AND status = 'done') AS sweeps_30m,
                COUNT(*) FILTER (WHERE kind = 'single' AND status = 'done') AS singles_30m
            ")
            ->first();
        $sweepsDoneNow = (int) ($freshCounts['sweeps_done'] ?? $run->sweeps_done);
        // SCOPES, NOT JURISDICTIONS (operator order 2026-08-30, the 222/h
        // vs 96-day ETA absurdity): the headline rate and ETA price the
        // fluid unit of drawing. A map counts done only when its LAST scope
        // lands, so item-rate lags reality by whole maps; scope-rate agrees
        // with the layer bars and with the operator's own eyes.
        $scopeRate = DB::table('autoscale_scopes')
            ->where('run_id', $run->id)
            ->where('status', 'done')
            ->where('finished_at', '>', now()->subMinutes(10))
            ->count();
        $scopesLeft = (int) DB::table('autoscale_scopes')
            ->where('run_id', $run->id)
            ->whereIn('status', ['pending', 'running'])
            ->count();
        $sweepRatePerH = round($scopeRate * 6.0, 1);
        $etaSeconds = $sweepRatePerH > 0
            ? (int) round(($scopesLeft / $sweepRatePerH) * 3600)
            : null;

        // Idle-cause aggregates (operator approval 2026-08-29): when a slot
        // sits idle the strip names WHY instead of a bare "idle". Cheap
        // reads only — the running set is small, and light-pending is an
        // EXISTS probe, never a planet count.
        $heavyRunning = null;
        $lightPending = null;
        if (in_array($run->status, ['mapping', 'halted'], true)) {
            $heavyRunning = (int) DB::table('autoscale_scopes')
                ->where('run_id', $run->id)->where('status', 'running')
                ->where('area_tier', '>=', 4)->count();
            $lightPending = DB::table('autoscale_scopes')
                ->where('run_id', $run->id)->where('status', 'pending')
                ->where('area_tier', '<', 4)->exists();
        }

        // Live workers = a fresh heartbeat OR an open claim (operator order
        // 2026-08-30, lane visibility): a lane deep in one long PostGIS
        // call cannot heartbeat, and it must stay on the strip with its
        // claim label and elapsed seconds for as long as the claim is open.
        $workerRows = DB::table('autoscale_worker_leases')
            ->where('run_id', $run->id)
            ->where(function ($q) {
                $q->where('last_seen_at', '>', now()->subMinutes(2))
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('claim_started_at')
                         ->where('claim_started_at', '>', now()->subHours(4));
                  });
            })
            // Longest-running claim on top (operator order 2026-08-30): the
            // grinders lead the strip, fresh claims join at the bottom.
            ->orderByRaw('claim_started_at ASC NULLS LAST, started_at')
            ->get(['id', 'claim_type', 'claim_label', 'claim_started_at', 'started_at']);
        $workers = $workerRows->count();
        $workersDetail = $workerRows->map(fn ($w) => [
            'id'          => substr((string) $w->id, 0, 8),
            'claim_type'  => $w->claim_type,
            'claim_label' => $w->claim_label,
            'claim_secs'  => $w->claim_started_at !== null
                ? max(0, (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($w->claim_started_at), true))
                : null,
        ])->values();

        return response()->json([
            'run' => [
                'id'                 => (string) $run->id,
                'status'             => $run->status,
                'adm_max'            => (int) $run->adm_max,
                'sized_parents'      => (int) $run->sized_parents,
                'sized_leaves'       => (int) $run->sized_leaves,
                'singles_total'      => (int) $run->singles_total,
                'singles_done'       => (int) ($freshCounts['singles_done'] ?? $run->singles_done),
                'sweeps_total'       => (int) $run->sweeps_total,
                'sweeps_done'        => $sweepsDoneNow,
                'review_count'       => (int) $run->review_count,
                // A lapsed breaker note is history, not state (operator
                // catch 2026-08-30: the red line outlived its pause by an
                // hour). Ship last_error only while its pause is live or
                // the run itself is stopped on it.
                'last_error'         => ($run->isPaused() || in_array($run->status, ['failed', 'halted'], true))
                    ? $run->last_error
                    : null,
                'created_at'            => $run->created_at?->toIso8601String(),
                'sizing_started_at'  => $run->sizing_started_at?->toIso8601String(),
                'precompute_started_at' => $run->precompute_started_at?->toIso8601String(),
                'mapping_started_at' => $run->mapping_started_at?->toIso8601String(),
                'finished_at'        => $run->finished_at?->toIso8601String(),
                'heartbeat_at'       => $run->updated_at?->toIso8601String(),
                'halt_requested'     => $run->halt_requested_at !== null,
                // Only a FUTURE pause is a pause — the stamp lingers after
                // the breaker window lapses and must not render as state.
                'paused_until'       => $run->isPaused() ? $run->paused_until->toIso8601String() : null,
                'sweeps_per_hour'    => $sweepRatePerH,
                'singles_per_hour'   => round(((int) $rateRow->singles_30m) * 6.0, 1),
                'eta_seconds'        => $etaSeconds,
                'drifted_done'       => (int) ($driftRow->drifted ?? 0),
                'net_drift'          => (int) ($driftRow->net_drift ?? 0),
                'attention_count'    => (int) ($driftRow->attention ?? 0),
                'sized_live'         => $sizedLive,
                'sizing_total'       => $sizingTotal,
                'parents_total'      => $parentsTotal,
                'maps_minted'        => $mapsMinted,
                'maps_total'         => $mapsTotal,
                // Pull engine: ONE concurrency limiter — the live worker pool.
                'workers'            => $workers,
                'workers_target'     => \App\Support\HostCapacity::autoscaleWorkers(),
                'heavy_running'      => $heavyRunning,
                'heavy_cap'          => \App\Support\AutoscaleClaims::heavyWorkerCap(),
                'light_pending'      => $lightPending,
            ],
            'layers'         => $layers,
            'precompute'     => $precompute,
            'workers_detail' => $workersDetail,
            'live_items'     => $liveItems,
            'review_items'   => $reviewItems,
            'drifted_items'  => $driftedItems,
            // Type B districting worklist — the flagged-chamber count the
            // dashboard's "Group Type B chambers" control acts on.
            'type_b_flagged' => (int) DB::table('legislatures')
                ->where('type_b_needs_districting', true)->whereNull('deleted_at')->count(),
        ]);
    }

    /**
     * POST /api/setup/wizard/step3/autoscale-halt — operator halt for the
     * full-scale run. DB-backed (pull engine): the pump parks the run within
     * a minute and fans out the in-flight force flags; workers stop at their
     * next claim boundary (per-scope commits survive — the run resumes from
     * autoscale_items/autoscale_scopes).
     */
    public function autoscaleHalt(Request $request, \App\Services\Autoscale\AutoscaleRunControl $control): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        // Single source with the `autoscale:halt` CLI (UI↔CLI parity): the
        // flag + pump-park logic lives in AutoscaleRunControl so the two doors
        // cannot drift. This method keeps only the operator gate + HTTP shape.
        $result = $control->halt();
        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $result['error']], 404);
        }

        return response()->json(['ok' => true, 'halting' => true]);
    }

    /**
     * POST /api/setup/wizard/step3/autoscale-resume — clear the DB halt flag;
     * the pump (called inline for immediacy) rewinds the phase and re-seeds
     * workers. With requeue_review a DONE run is revived to retry its
     * review/failed items. Hand-fixed legislatures are safe: a requeued
     * sweep item whose legislature now has an ACTIVE map with districts is
     * ADOPTED, never re-swept. Shares AutoscaleRunControl with `autoscale:resume`.
     */
    public function autoscaleResume(Request $request, \App\Services\Autoscale\AutoscaleRunControl $control): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $result = $control->resume($request->boolean('requeue_review'));
        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $result['error']], 404);
        }

        return response()->json(['ok' => true, 'run_id' => $result['run_id']]);
    }

    /**
     * POST /api/setup/wizard/step3/autoscale-revert — the Step-3 dashboard's
     * "Rewind mapping" control (UI↔CLI parity with the `autoscale:revert` CLI).
     * Rewinds the run to the mapping start: deletes autoscale-generated maps,
     * resets items, keeps sizing + precompute + the audit chain, and re-mints
     * fresh founding maps/scopes. The operator's confirm step is the
     * deliberate-intent gate the CLI's --force represents; the command's own
     * safety guards (run must be halted/done, no live worker leases) still
     * apply, so no --force is passed — a refusal (e.g. workers still parking)
     * surfaces as a 409 the dashboard shows. The run parks HALTED after the
     * rewind; the existing Resume button carries it forward.
     */
    public function autoscaleRevert(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $exit = \Illuminate\Support\Facades\Artisan::call('autoscale:revert');
        if ($exit !== 0) {
            return response()->json([
                'ok'    => false,
                'error' => trim(\Illuminate\Support\Facades\Artisan::output()) ?: 'Rewind refused — halt the run first and wait for workers to park.',
            ], 409);
        }

        return response()->json(['ok' => true, 'reverted' => true]);
    }

    /**
     * POST /api/setup/wizard/step3/type-b-district — the Step-3 dashboard's
     * "Group Type B chambers" control (UI↔CLI parity with the `type-b:district`
     * CLI). Groups flagged chambers' constituents into shared panels and clears
     * type_b_needs_districting so their at-large Type B races can schedule. Same
     * service (TypeBDistrictMapper), same guards as the CLI; operator-gated. A
     * bounded batch so one click never sweeps the whole planet unattended — the
     * response reports what remains for the next click.
     */
    public function typeBDistrict(Request $request, \App\Services\Legislature\TypeBDistrictMapper $mapper): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $limit = min(500, max(1, (int) $request->integer('limit', 200)));
        $ids = DB::table('legislatures')
            ->where('type_b_needs_districting', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $grouped = 0;
        $seats = 0;
        $undercount = 0;
        $failures = 0;
        foreach ($ids as $id) {
            try {
                $r = $mapper->apply((string) $id);
                if ($r) {
                    $grouped++;
                    $seats += $r['seats'];
                    $undercount += $r['undercount'] ? 1 : 0;
                }
            } catch (\Throwable $e) {
                $failures++;
            }
        }

        $remaining = (int) DB::table('legislatures')
            ->where('type_b_needs_districting', true)
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'ok'         => $failures === 0,
            'grouped'    => $grouped,
            'seats'      => $seats,
            'undercount' => $undercount,
            'failures'   => $failures,
            'remaining'  => $remaining,
        ]);
    }

    /**
     * POST /api/setup/wizard/step4/complete — finish setup.
     *
     * Queues the institution shell set for every jurisdiction that holds a
     * legislature (executive, court, election board + system member, civic
     * spaces), records confirmation, and flips setup_completed_at. From this
     * point /setup redirects home.
     *
     * THE ETL RULE (setup-loop audit, 2026-08-23): this used to call
     * InstitutionStubService::generate(null) inline — one planet-wide pluck of
     * every legislature's jurisdiction_id rebound as `IN (?,?,?…)`. Above
     * 65,535 legislatures PostgreSQL refuses the statement outright ("number
     * of parameters must be between 0 and 65535" — proven live), so an eager
     * full-scale world could never finish setup. The whole-world case now
     * rides the same keyset-chunked, committed-per-chunk, idempotent
     * InstitutionProvisionService the eager chain and /building use, off the
     * request via ProvisionInstitutionsJob (its own docblock forbids running
     * it inline). The per-jurisdiction stub path (ActivationService) is
     * bounded and stays as it is. A world the eager chain already provisioned
     * simply reports zero pending — the job is a no-op there.
     */
    public function completeStep4(Request $request): JsonResponse
    {
        // Operator-only (route is auth-gated): finishing setup closes the
        // founding window for good (constants + game mode lock), so a guest on
        // a public box must never be able to end it.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $provision = app(\App\Services\InstitutionProvisionService::class);
        $pending   = [];
        foreach (\App\Services\InstitutionProvisionService::STEPS as $step) {
            $pending[$step] = $provision->pendingTotal($step);
        }
        \App\Jobs\ProvisionInstitutionsJob::dispatch();

        $stubs = [
            // Kept for the Step 4 page's counters (now "queued", not "created").
            'executives_pending'  => $pending['executives'],
            'judiciaries_pending' => $pending['judiciaries'],
            'pending'             => $pending,
            'queued'              => true,
        ];

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
        // Operator-only (route is auth-gated): a recorded review decision is the
        // founder's judgment on the data; the drill/read endpoints stay public.
        abort_unless((bool) $request->user()?->is_operator, 403);

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
     * /jurisdictions/earth-0-earth, which starts the full-scale AUTOSCALE run
     * (AutoscaleOrchestratorJob, 2026-07-18): every jurisdiction gets a sized
     * legislature and a founding district map, and the orchestrator stamps
     * apportionment_completed_at when sizing finishes. This handler keeps
     * the pending_constitutional_defaults apply logic and the step-completion
     * advance; apportionment is decoupled.
     */
    public function activateStep1(Request $request): JsonResponse
    {
        // Operator-only (route is auth-gated). Advancing the wizard and applying
        // the stashed constitution are the founder's acts; on a PUBLIC box the
        // setup window is reachable by anyone, so a guest must never drive it.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $settings = InstanceSettings::current();

        // Geodata acceptance gate: the wizard cannot advance past Map Data
        // until the operator has reviewed the repair-plane flags and clicked
        // "Accept Map Data & Continue" on the planet-scope viewer (which
        // stamps instance_settings.map_accepted_at and closes the repair
        // window). Checked FIRST so nothing below runs on unaccepted data.
        if ($settings->map_accepted_at === null) {
            return response()->json([
                // map_acceptance_required is the structured signal Step 2
                // keys its guidance rendering on (the message text is copy,
                // not contract).
                'map_acceptance_required' => true,
                'error' => 'Accept the map data first — review and repair the data flags in the Jurisdiction Viewer, then Accept Map Data & Continue.',
            ], 422);
        }

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
    // ─── Setup v2 — game mode, map-data source detection, operator profile ────

    /**
     * POST /api/setup/game-mode — record the WORLD game mode (production | sandbox).
     *
     * Sandbox unlocks the dev toolbox (assume-any-role, manufacture
     * qualifications) as a WORLD property — the principled replacement for
     * ambient dev flags. Re-settable during setup (the operator can flip it
     * before finishing); locked once setup completes is enforced by the wizard
     * gating, not here. flush() clears the per-request GameMode cache so the
     * dev-tool gate reflects the choice on the very next request.
     */
    public function saveGameMode(Request $request): JsonResponse
    {
        // Operator-only (the route is auth-gated; require the operator flag so a
        // self-registered citizen can't flip the world's mode).
        abort_unless((bool) $request->user()?->is_operator, 403);

        $settings = InstanceSettings::current();
        // Founding property: LOCK once setup completes. A live production world
        // must never be flippable to sandbox afterwards — that would re-open the
        // dev toolbox (impersonation, board-seat) on a hardened world.
        if ($settings->isSetupComplete()) {
            return response()->json(['error' => 'Game mode is set at founding and locked once setup is complete.'], 409);
        }

        $data = $request->validate([
            'game_mode' => ['required', Rule::in([\App\Support\GameMode::PRODUCTION, \App\Support\GameMode::SANDBOX])],
        ]);

        $settings->game_mode = $data['game_mode'];
        $settings->save();
        \App\Support\GameMode::flush();

        return response()->json([
            'settings' => $this->serializeSettings($settings->fresh()),
        ]);
    }

    /**
     * GET /api/setup/wizard/step2/sources — report which map datasets are
     * actually staged where the ETL will read them.
     *
     * The app container now bind-mounts the same read-only /archive the etl
     * container sees (docker-compose), so we can stat it directly and tell the
     * operator the truth instead of a hardcoded "reads from D:\…" label that
     * was often wrong. A correctly-staged archive that the ETL simply wasn't
     * pointed at now shows as present/absent honestly.
     */
    public function mapDataSources(): JsonResponse
    {
        $countIso3Dirs = static function (string $path): int {
            if (! is_dir($path)) {
                return 0;
            }
            $n = 0;
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (preg_match('/^[A-Za-z]{3}$/', $entry) && is_dir($path.'/'.$entry)) {
                    $n++;
                }
            }
            return $n;
        };

        // Canonical container layout (matches D:\fair-constitution-map-files):
        //   /archive/geoBoundaries_repo/releaseData/gbOpen/<ISO3>/
        //   /archive/worldpop_100m_latest/<ISO3>/
        $gbDir = '/archive/geoBoundaries_repo/releaseData/gbOpen';
        $wpDir = '/archive/worldpop_100m_latest';
        $pmDir = '/var/www/html/public/maps/protomaps';

        $gbCount = $countIso3Dirs($gbDir);
        $wpCount = $countIso3Dirs($wpDir);
        $pmFiles = is_dir($pmDir) ? array_map('basename', glob($pmDir.'/*.pmtiles') ?: []) : [];

        // Half-applied detection: the operator can set ARCHIVE_PATH in .env but
        // the bind mount only changes when the containers are RECREATED
        // (`docker compose up -d`), NOT on a stop/start. If .env points at a
        // non-default folder yet /archive shows nothing, they almost certainly
        // set the path but haven't recreated the containers — the single most
        // common "the archive won't take" trap.
        $archiveEnv = $this->readEnvValue('ARCHIVE_PATH');
        $isDefaultPath = ($archiveEnv === null || $archiveEnv === '' || $archiveEnv === './data/archive');
        $anyPresent = ($gbCount > 0 || $wpCount > 0 || count($pmFiles) > 0);
        $applyPending = (! $isDefaultPath && $gbCount === 0 && $wpCount === 0);

        return response()->json([
            // The container-visible mount points (constant); the HOST folder is
            // set via ARCHIVE_PATH / PROTOMAPS_DIR in .env and can't be read from
            // inside the container — the UI explains the mapping.
            'archive_mount'   => '/archive',
            'protomaps_mount' => $pmDir,
            'archive_present' => is_dir('/archive'),
            // The folder the operator pointed .env at (host path), and whether it
            // still needs a `docker compose up -d` to take effect.
            'archive_env_path' => $archiveEnv,
            'apply_pending'    => $applyPending,
            'datasets' => [
                'geoboundaries' => [
                    'present'   => $gbCount > 0,
                    'countries' => $gbCount,
                    'path'      => $gbDir,
                    'label'     => 'Jurisdiction boundaries (geoBoundaries)',
                ],
                'worldpop' => [
                    'present'   => $wpCount > 0,
                    'countries' => $wpCount,
                    'path'      => $wpDir,
                    'label'     => 'Population (WorldPop)',
                ],
                'protomaps' => [
                    'present' => count($pmFiles) > 0,
                    'files'   => $pmFiles,
                    'path'    => $pmDir,
                    'label'   => 'Basemap tiles (Protomaps)',
                ],
            ],
        ]);
    }

    /**
     * POST /api/setup/wizard/step2/archive-path — point the ETL at the
     * operator's local map folder by writing ARCHIVE_PATH / PROTOMAPS_DIR into
     * .env. Applying it needs a `docker compose up -d` to remount /archive, so
     * we report restart_required rather than pretending it took effect live.
     */
    public function saveArchivePath(Request $request): JsonResponse
    {
        // Operator-only: this writes .env (route is auth-gated). The value is
        // additionally CR/LF/quote-rejected in writeEnvValues to stop .env
        // injection through a crafted path.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'archive_path'   => ['nullable', 'string', 'max:512'],
            'protomaps_path' => ['nullable', 'string', 'max:512'],
        ]);

        $kv = [];
        if (! empty($data['archive_path'])) {
            $kv['ARCHIVE_PATH'] = $this->normalizePath($data['archive_path']);
        }
        if (! empty($data['protomaps_path'])) {
            $kv['PROTOMAPS_DIR'] = $this->normalizePath($data['protomaps_path']);
        }
        if (empty($kv)) {
            return response()->json(['error' => 'Provide at least one folder path.'], 422);
        }

        try {
            $this->writeEnvValues($kv);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not write .env: '.$e->getMessage()], 500);
        }

        return response()->json([
            'saved'            => $kv,
            'restart_required' => true,
            // The bind mount only changes when the containers are RECREATED, which
            // is what `docker compose up -d` does — a stop/start (or Docker
            // Desktop's stop then start) reuses the old mount and the folder never
            // shows up. Say that explicitly; it's the #1 "the archive won't take" trap.
            'command'          => 'docker compose up -d',
            'message'          => 'Saved. To apply it, re-run the start script from the app folder ("./get-started.sh --reconfigure" or ".\\get-started.ps1 -Reconfigure"), or run "docker compose up -d" directly — both RECREATE the containers so they pick up your folder. A plain stop/start or restart is NOT enough. Then click "Re-check". Tip: next time, the start script asks for your map folder up front so no recreate is needed.',
        ]);
    }

    /**
     * POST /api/setup/operator/roles/establish — turn on operator roles from the
     * operator-setup step, INLINE (so the operator never has to leave the wizard
     * for the full console and find no way back). Founding only: self-asserted
     * channels register, governed channels self-grant. Body {capabilities:[]}
     * (omitted = all). Returns the refreshed per-channel state so the step updates
     * in place.
     */
    public function establishFoundingRoles(
        Request $request,
        \App\Services\Federation\CapabilityService $caps,
        \App\Services\Identity\MeshRoleGrantService $grants,
        \App\Services\Federation\InstanceIdentityService $identity
    ): JsonResponse {
        abort_unless((bool) $request->user()?->is_operator, 403);
        if (! \App\Support\FoundingContext::isFounding()) {
            return response()->json(['error' => 'Roles self-assert only during founding; use the operator console afterwards.'], 409);
        }

        $data = $request->validate([
            'capabilities'   => ['nullable', 'array'],
            'capabilities.*' => [Rule::in(\App\Models\InstanceCapability::CHANNELS)],
        ]);

        $wanted = ! empty($data['capabilities'])
            ? array_values(array_unique($data['capabilities']))
            : \App\Models\InstanceCapability::CHANNELS; // default: all of them

        $identity->ensureIdentity();
        $established = [];
        $errors = [];
        foreach ($wanted as $cap) {
            try {
                if (\App\Models\InstanceCapability::isGoverned($cap)) {
                    $grants->selfGrantFounding($cap);
                } else {
                    $caps->registerSelf($cap);
                }
                $established[] = $cap;
            } catch (\Throwable $e) {
                $errors[$cap] = $e->getMessage();
            }
        }

        return response()->json([
            'established' => $established,
            'errors'      => $errors,
            'channels'    => $this->channelStates(),
        ]);
    }

    /**
     * The 9 capability channels with founding-relevant state: established (the
     * grant is on) and needs_setup (established but the underlying infra — DNS,
     * TLS/lego, a Matrix homeserver, a LiveKit SFU — is NOT configured yet, so
     * the role is granted but doesn't actually work until the operator sets it
     * up). needs_setup keys off MeshGateService's `ready` (the raw prober), NOT
     * `state` (which collapses to 'established' once granted and would mask it).
     *
     * @return list<array{capability:string,label:string,what:string,established:bool,needs_setup:bool}>
     */
    private function channelStates(): array
    {
        $out = [];
        try {
            foreach (app(\App\Services\Federation\MeshGateService::class)->channels() as $c) {
                $established = ($c['state'] ?? null) === 'established';
                $ready = (bool) ($c['ready'] ?? false);
                $out[] = [
                    'capability'  => $c['capability'],
                    'label'       => $c['label'] ?? $c['capability'],
                    'what'        => $c['what'] ?? '',
                    'established'  => $established,
                    'needs_setup' => $established && ! $ready,
                ];
            }
        } catch (\Throwable $e) {
            $out = [];
        }
        return $out;
    }

    /**
     * POST /api/setup/operator/profile — name this node and set the address
     * peers reach it at (operator onboarding, folded into bootstrap). The
     * operator account itself is created with the founder; this fills in the
     * instance identity the mesh needs. FEDERATION_SELF_URL lands in .env
     * (peers read it at handshake); a change needs a container restart to take
     * effect, so restart_required is reported when the self-URL changed.
     */
    public function saveOperatorProfile(Request $request): JsonResponse
    {
        // Operator-only: writes instance_name + FEDERATION_SELF_URL into .env
        // (route is auth-gated). self_url is url-validated and the .env write
        // rejects any CR/LF/quote.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'instance_name' => ['nullable', 'string', 'max:120'],
            'self_url'      => ['nullable', 'url', 'max:255'],
        ]);

        $settings = InstanceSettings::current();
        if (! empty($data['instance_name'])) {
            $settings->instance_name = trim($data['instance_name']);
            $settings->save();
        }

        $restart = false;
        if (! empty($data['self_url'])) {
            $current = (string) config('cga.federation_self_url');
            if ($current !== $data['self_url']) {
                try {
                    $this->writeEnvValues(['FEDERATION_SELF_URL' => $data['self_url']]);
                    $restart = true;
                } catch (\Throwable $e) {
                    return response()->json(['error' => 'Could not write .env: '.$e->getMessage()], 500);
                }
            }
        }

        return response()->json([
            'settings'         => $this->serializeSettings($settings->fresh()),
            'restart_required' => $restart,
        ]);
    }

    /**
     * GET /api/setup/deploy-package?os=windows|unix&kind=solo|join — download a
     * pre-baked start script so the founding operator can hand a colleague a
     * one-file quick deploy that lands where they need to be.
     *
     *   kind=solo — founds a fresh world, .env pre-seeded with this box's ports
     *               + game mode (a colleague founds their own world of the same
     *               shape).
     *   kind=join — mirrors THIS world: pre-baked with this box's self-URL as
     *               the host and a freshly-minted join key, so a fresh run
     *               joins the mesh and the whole game replicates in.
     *
     * The heavy lifting (template render + join-key mint) lives in
     * DeployPackageService so this controller stays the HTTP seam.
     */
    public function deployPackage(Request $request, \App\Services\Setup\DeployPackageService $packages): Response|JsonResponse
    {
        $data = $request->validate([
            'os'   => ['required', Rule::in(['windows', 'unix'])],
            'kind' => ['required', Rule::in(['solo', 'join'])],
        ]);

        try {
            $script = $packages->render($data['os'], $data['kind']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response($script['body'], 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$script['filename'].'"',
        ]);
    }

    /** Read a single KEY's value from the repo-root .env (unquoted, trimmed), or null if absent. */
    private function readEnvValue(string $key): ?string
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            return null;
        }
        foreach (preg_split('/\r\n|\r|\n/', (string) file_get_contents($envPath)) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $m)) {
                return trim($m[1], " \t\"'");
            }
        }
        return null;
    }

    /** Normalize a host path for .env: Windows backslashes → forward slashes (docker-compose accepts them cross-platform). */
    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    /**
     * Upsert KEY=value pairs into the repo-root .env, preserving all other
     * lines. Values with whitespace are double-quoted. Creates .env from
     * .env.example if it is somehow missing. The whole repo is bind-mounted
     * into the container, so .env is writable here.
     */
    private function writeEnvValues(array $kv): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath) && is_file(base_path('.env.example'))) {
            @copy(base_path('.env.example'), $envPath);
        }
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $lines    = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($kv as $key => $value) {
            // .env-injection guard: a value is written on ONE physical line, so a
            // CR/LF would let a caller-supplied path/URL forge additional .env
            // lines (e.g. DB_HOST=attacker) that Laravel reads on the next boot.
            // A double-quote would break out of the wrapper the same way. A real
            // filesystem path or URL never contains any of these — reject hard.
            if (preg_match('/[\r\n"]/', $value)) {
                throw new \RuntimeException('value for '.$key.' contains an illegal character (newline or quote)');
            }
            $render = (preg_match('/\s/', $value) ? '"'.$value.'"' : $value);
            $line   = $key.'='.$render;
            $found  = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                    $lines[$i] = $line;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $lines[] = $line;
            }
        }

        $out = rtrim(implode("\n", $lines), "\n")."\n";
        if (@file_put_contents($envPath, $out) === false) {
            throw new \RuntimeException('write failed (permission?)');
        }
    }

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
            'map_accepted_at'               => optional($settings->map_accepted_at)->toIso8601String(),
            'apportionment_completed_at'    => optional($settings->apportionment_completed_at)->toIso8601String(),
            'setup_districts_confirmed_at'  => optional($settings->setup_districts_confirmed_at)->toIso8601String(),
            'setup_mode'                    => $settings->setup_mode,
            'game_mode'                     => $settings->game_mode, // production | sandbox | null (not yet chosen)
            'is_mirror'                     => $settings->isMirror(),
            'server_id'                     => $settings->server_id, // public mesh id (never the private key — that is $hidden)
            'self_url'                      => config('cga.federation_self_url'),
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
