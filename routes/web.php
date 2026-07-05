<?php

use App\Http\Controllers\Civic\HomeController;
use App\Http\Controllers\Civic\IdentityVerificationController;
use App\Http\Controllers\Civic\MyRecordController;
use App\Http\Controllers\Civic\PingController;
use App\Http\Controllers\Civic\ResidencyController;
use App\Http\Controllers\CosmicAddressController;
use App\Http\Controllers\Dev\ElectoralKitController;
use App\Http\Controllers\Dev\ExecutiveOrgKitController;
use App\Http\Controllers\Dev\ImpersonationController;
use App\Http\Controllers\Dev\JudiciaryKitController;
use App\Http\Controllers\Dev\LegislatureKitController;
use App\Http\Controllers\Dev\ResidencyGrantController;
use App\Http\Controllers\Elections\ApprovalController;
use App\Http\Controllers\Elections\BallotController;
use App\Http\Controllers\Elections\BoardConsoleController;
use App\Http\Controllers\Elections\CandidacyController;
use App\Http\Controllers\Elections\ElectionController;
use App\Http\Controllers\Elections\ResultsController;
use App\Http\Controllers\Elections\VacancyController;
use App\Http\Controllers\Invites\InviteController;
use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\Oidc\OidcAuthorizationController;
use App\Http\Controllers\Legislature\BillController;
use App\Http\Controllers\Legislature\ChamberController;
use App\Http\Controllers\Legislature\ChamberResolverController;
use App\Http\Controllers\Legislature\SessionController;
use App\Http\Controllers\Legislature\SettingsController;
use App\Http\Controllers\LegislatureController;
use App\Http\Controllers\MapsController;
use App\Http\Controllers\RasterTileController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\System\AuditChainController;
use App\Http\Middleware\DevToolsEnabled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Game-as-OIDC-provider (K3-C.2) — the AUTHORIZE endpoint is the one OIDC route that needs the citizen's
// session: behind `auth`, an unauthenticated player is sent to the game login (intended preserved) and
// bounces back here once logged in — the player's ONE login. Discovery/JWKS/token/userinfo are stateless
// and live in routes/oidc.php (mounted outside the web group).
Route::get('/oauth/authorize', [OidcAuthorizationController::class, 'authorize'])->middleware('auth');

// WI-8: /civic is the authenticated landing; Home stays the guest welcome.
Route::get('/', function (Request $request) {
    return $request->user() !== null
        ? redirect('/civic')
        : Inertia::render('Home');
});

// Phase K-3 — Matrix .well-known delegation (public, no auth). Served dynamically so
// MATRIX_DOMAIN/APP_URL resolve per instance (nginx cannot env-substitute a JSON body).
Route::get('/.well-known/matrix/server', [\App\Http\Controllers\Matrix\WellKnownController::class, 'server']);
Route::get('/.well-known/matrix/client', [\App\Http\Controllers\Matrix\WellKnownController::class, 'client']);

// Roles-campaign — zero-foreknowledge discovery descriptor (public, no auth). How a cold node (or the
// canonical front door) self-identifies as a CGA federation entry point. Public facts only, never a secret.
Route::get('/.well-known/cga-federation', [\App\Http\Controllers\Federation\WellKnownFederationController::class, 'descriptor']);

// Setup wizard — WordPress-style install flow.
Route::get('/setup', [SetupController::class, 'index'])->name('setup.index');
Route::get('/setup/step/{n}', [SetupController::class, 'step'])
    ->where('n', '[0-4]')
    ->name('setup.step');

// Roles-campaign Phase 1 — the SOLO/JOIN fork (first question after the operator account) + the
// JOIN (mirror-onboarding) screen. SOLO falls through to the build steps above; JOIN connects to a host.
Route::get('/setup/mode', [SetupController::class, 'mode'])->name('setup.mode');
// START-path operator bootstrap (operator account + node address + founding roles) — after the fork, before cosmic.
Route::get('/setup/operator', [SetupController::class, 'operatorSetupPage'])->name('setup.operator');
Route::get('/setup/join', [SetupController::class, 'join'])->name('setup.join');
Route::post('/api/setup/mode', [SetupController::class, 'setMode'])->name('api.setup.mode');
Route::post('/api/setup/join', [SetupController::class, 'joinFromSetup'])->name('api.setup.join');
// Zero-foreknowledge auto-discovery — the JOIN screen asks the node to FIND a federation (front door +
// opt-in LAN sweep) instead of the operator having to know a host address up front. AUTH-gated: this
// drives server-side outbound probing (incl. an opt-in LAN sweep), so it must never be an unauthenticated
// trigger — the operator already has a session (createFounder logs them in). Throttled against retry loops.
Route::post('/api/setup/discover', [SetupController::class, 'discover'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('api.setup.discover');

// Phase M — self-bootstrap (schema migrations + founder account).
// /setup/bootstrap is reachable even when the schema is empty — the page
// itself is what walks the operator through getting it ready.
Route::get('/setup/bootstrap', [SetupController::class, 'bootstrapPage'])
    ->name('setup.bootstrap');
Route::get('/api/setup/bootstrap/status', [SetupController::class, 'bootstrapStatusEndpoint'])
    ->name('api.setup.bootstrap.status');
Route::post('/api/setup/bootstrap/migrate', [SetupController::class, 'runMigrations'])
    ->name('api.setup.bootstrap.migrate');
Route::post('/api/setup/bootstrap/create-founder', [SetupController::class, 'createFounder'])
    ->name('api.setup.bootstrap.create-founder');

Route::get('/api/setup/state', [SetupController::class, 'state'])->name('api.setup.state');
Route::post('/api/setup/cosmic-address', [SetupController::class, 'saveCosmicAddress'])->name('api.setup.cosmic-address');
Route::post('/api/setup/constants', [SetupController::class, 'saveConstants'])->name('api.setup.constants');
// Setup v2 — world game mode (production | sandbox) chosen at the defaults step.
// AUTH-gated: flipping to sandbox unlocks the dev toolbox, so it must never be a
// guest trigger (the founder is logged in from createFounder onward); the handler
// additionally requires is_operator + refuses once setup is complete.
Route::post('/api/setup/game-mode', [SetupController::class, 'saveGameMode'])
    ->middleware('auth')->name('api.setup.game-mode');
// Setup v2 — operator profile (instance name + peer-reachable self-URL) writes .env; auth + is_operator gated.
Route::post('/api/setup/operator/profile', [SetupController::class, 'saveOperatorProfile'])
    ->middleware('auth')->name('api.setup.operator.profile');
Route::post('/api/setup/wizard/step1/detect', [SetupController::class, 'detectStep1'])->name('api.setup.step1.detect');
Route::post('/api/setup/wizard/step1/activate', [SetupController::class, 'activateStep1'])->name('api.setup.step1.activate');
// Setup v2 — detect which map datasets are staged, and point the ETL at a local folder.
Route::get('/api/setup/wizard/step2/sources', [SetupController::class, 'mapDataSources'])->name('api.setup.step2.sources');
// AUTH-gated: writes .env (ARCHIVE_PATH / PROTOMAPS_DIR); handler requires is_operator.
Route::post('/api/setup/wizard/step2/archive-path', [SetupController::class, 'saveArchivePath'])
    ->middleware('auth')->name('api.setup.step2.archive-path');
Route::post('/api/setup/wizard/step2/start', [SetupController::class, 'startMapData'])->name('api.setup.step2.start');
Route::get('/api/setup/wizard/step2/progress', [SetupController::class, 'mapDataProgress'])->name('api.setup.step2.progress');
Route::post('/api/setup/wizard/step2/control', [SetupController::class, 'controlMapData'])->name('api.setup.step2.control');
// Setup v2 — download the pre-baked deployment script package (per OS × solo/join).
Route::get('/api/setup/deploy-package', [SetupController::class, 'deployPackage'])
    ->middleware('auth')->name('api.setup.deploy-package');
Route::get('/api/setup/wizard/step3/summary', [SetupController::class, 'step3Summary'])->name('api.setup.step3.summary');
Route::post('/api/setup/wizard/step3/complete', [SetupController::class, 'completeStep3'])->name('api.setup.step3.complete');
Route::post('/api/setup/wizard/step4/complete', [SetupController::class, 'completeStep4'])->name('api.setup.step4.complete');

// Step 2 manual data review — surfaces post-ETL discrepancies BEFORE the user
// clicks Continue (which fires apportionment). Reviewing after districting
// would be too late: apportionment + districts already use the populations.
// No autofix anywhere — operator records decisions, future remediation acts.
Route::get('/api/setup/wizard/step2/review/population_gaps', [SetupController::class, 'reviewPopulationGaps'])
    ->name('api.setup.step2.review.population_gaps');
Route::get('/api/setup/wizard/step2/review/aggregation_discrepancies', [SetupController::class, 'reviewAggregationDiscrepancies'])
    ->name('api.setup.step2.review.aggregation_discrepancies');
Route::get('/api/setup/wizard/step2/review/orphans', [SetupController::class, 'reviewOrphans'])
    ->name('api.setup.step2.review.orphans');
Route::get('/api/setup/wizard/step2/review/sovereign_territories', [SetupController::class, 'reviewSovereignTerritories'])
    ->name('api.setup.step2.review.sovereign_territories');

// Phase JK assignment-audit drill endpoints
Route::get('/api/setup/wizard/step2/review/parent_assignment_audit', [SetupController::class, 'reviewParentAssignmentAudit'])
    ->name('api.setup.step2.review.parent_assignment_audit');
Route::get('/api/setup/wizard/step2/review/population_assignment_audit', [SetupController::class, 'reviewPopulationAssignmentAudit'])
    ->name('api.setup.step2.review.population_assignment_audit');

// Per-row review detail + decision capture (manual resolution, no autofix)
Route::get('/api/setup/wizard/step2/review/{category}/{jurisdiction}/detail',
    [SetupController::class, 'reviewDetail'])
    ->where(['category' => '[a-z_]+', 'jurisdiction' => '[a-f0-9\-]+'])
    ->name('api.setup.step2.review.detail');
Route::post('/api/setup/wizard/step2/review/{category}/{jurisdiction}/decision',
    [SetupController::class, 'reviewDecision'])
    ->where(['category' => '[a-z_]+', 'jurisdiction' => '[a-f0-9\-]+'])
    ->name('api.setup.step2.review.decision');

Route::get('/api/cosmic-addresses/default-path', [CosmicAddressController::class, 'defaultPath'])->name('api.cosmic-addresses.default-path');
Route::get('/api/cosmic-addresses/{id}/children', [CosmicAddressController::class, 'children'])->name('api.cosmic-addresses.children');

// Jurisdiction viewer
//
// The public-facing show route binds by SLUG (the `{jurisdiction:slug}`
// syntax) rather than UUID — slugs are human-readable, link-shareable,
// and the operator asked them surfaced in the URL bar so the sidebar can
// drop its dedicated "slug" stat card. UUID-bound API endpoints below
// stay unchanged because they're internal JS-driven calls where slugs
// would only add lookup cost.
Route::get('/jurisdictions', [JurisdictionController::class, 'index'])->name('jurisdictions.index');
Route::get('/jurisdictions/{jurisdiction:slug}', [JurisdictionController::class, 'show'])->name('jurisdictions.show');

// ── Invites — the share-to-signup growth loop ───────────────────────────────────────────
// PUBLIC landing: a friend opens the link with no account. If signed in they redeem +
// continue; if a guest they preview it and sign up (the destination is carried across signup).
// The token is `handle.secret` (alnum + one dot); throttled against enumeration.
Route::get('/i/{token}', [InviteController::class, 'land'])
    ->where('token', '[A-Za-z0-9.]+')
    ->middleware('throttle:30,1')
    ->name('invite.land');
// AUTH: mint a shareable link for a destination the inviter can already reach.
Route::middleware('auth')->group(function () {
    Route::post('/invites', [InviteController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('invites.store');
});

// PUBLIC: a guest on a public page (a proceeding, the commons) taps "Sign up to take part" — capture
// where they were so signup/login continues there. SAME-ORIGIN app paths only (no open redirect).
Route::get('/continue', function (Request $request) {
    $to = (string) $request->query('to', '');
    $sameOrigin = $to !== '' && str_starts_with($to, '/') && ! str_starts_with($to, '//')
        && ! str_contains($to, '\\') && ! str_contains($to, '..')
        && parse_url($to, PHP_URL_HOST) === null && parse_url($to, PHP_URL_SCHEME) === null;
    if ($sameOrigin) {
        $request->session()->put('url.intended', $to);
    }

    return redirect($request->query('mode') === 'login' ? '/login' : '/register');
})->name('continue');

// GeoJSON API endpoints
Route::get('/api/jurisdictions/{jurisdiction}/children.geojson', [JurisdictionController::class, 'childrenGeoJson'])->name('jurisdictions.children.geojson');
Route::get('/api/jurisdictions/{jurisdiction}/self.geojson', [JurisdictionController::class, 'selfGeoJson'])->name('jurisdictions.self.geojson');
Route::get('/api/jurisdictions/{jurisdiction}/siblings.geojson', [JurisdictionController::class, 'siblingsGeoJson'])->name('jurisdictions.siblings.geojson');
Route::get('/api/jurisdictions/{jurisdiction}/ancestors', [JurisdictionController::class, 'ancestors'])->name('jurisdictions.ancestors');

// WorldPop raster as Leaflet TileLayer (Web Mercator XYZ scheme). Replaces
// the per-jurisdiction ImageOverlay served from JurisdictionController::rasterPng
// — see RasterTileController docblock for why.
Route::get('/api/rasters/{z}/{x}/{y}.png', [RasterTileController::class, 'tile'])
    ->where(['z' => '\d+', 'x' => '\d+', 'y' => '\d+'])
    ->name('rasters.tile');

// Latest Protomaps PMTiles bundle in the bind-mounted basemap directory.
// Frontend hits this on map init to pick up newly-dropped dated bundles
// without code changes.
Route::get('/api/maps/latest-pmtiles', [MapsController::class, 'latestPmtiles'])
    ->name('maps.latest-pmtiles');

// P.6: operator-facing acceptance gate. Posted from the planet-scope
// jurisdiction viewer's "Accept Map Data & Continue" button.
Route::post('/api/jurisdictions/accept-maps', [JurisdictionController::class, 'acceptMaps'])->name('jurisdictions.accept-maps');

// P.9: export/import endpoints for the portable-archive paradigm. Export
// streams a tar.gz of jurisdictions + worldpop + meta tables; import
// accepts the same shape and runs pg_restore against a freshly truncated
// schema.
//
// Sync vs async exports:
//   - `GET  /api/export/jurisdictions`               — synchronous (default)
//     or queue an async job with `?async=1` (returns export_id + status_url).
//     `?skip_rasters=1` drops the ~7 GB worldpop_rasters table.
//   - `GET  /api/export/jurisdictions/list`          — directory listing
//     of in-progress + completed exports for the wizard's status panel.
//   - `GET  /api/export/jurisdictions/download/{f}`  — fetch a built tarball.
//   - `DEL  /api/export/jurisdictions/{exportId}`    — delete a past export.
//   - `POST /api/export/jurisdictions/{exportId}/halt` — request halt of a
//     running export. Sets a cache flag the job polls; pg_dump receives
//     SIGTERM, the partial dump is unlinked, status flips to "halted".
// GET for the legacy sync-download path (browser navigates → file streams back).
// POST for the new dispatch path so the form-encoded `tables[]` array travels
// in the body rather than as bracketed query params.
Route::match(['get', 'post'], '/api/export/jurisdictions', [JurisdictionController::class, 'exportMaps'])
    ->name('jurisdictions.export');
Route::get('/api/export/jurisdictions/list', [JurisdictionController::class, 'exportMapsList'])->name('jurisdictions.export.list');
Route::get('/api/export/jurisdictions/tables', [JurisdictionController::class, 'exportMapsTables'])->name('jurisdictions.export.tables');
Route::get('/api/export/jurisdictions/download/{filename}', [JurisdictionController::class, 'exportMapsDownload'])
    ->where('filename', '[A-Za-z0-9._-]+\.tar\.gz')
    ->name('jurisdictions.export.download');
Route::post('/api/export/jurisdictions/{exportId}/halt', [JurisdictionController::class, 'exportMapsHalt'])
    ->where('exportId', '[A-Za-z0-9._-]+')
    ->name('jurisdictions.export.halt');
Route::delete('/api/export/jurisdictions/{exportId}', [JurisdictionController::class, 'exportMapsDelete'])
    ->where('exportId', '[A-Za-z0-9._-]+')
    ->name('jurisdictions.export.delete');
Route::post('/api/import/jurisdictions', [JurisdictionController::class, 'importMaps'])->name('jurisdictions.import');

// Legislature browser
// WI-9: /legislatures is the multi-legislature index (the sidebar's entry
// point — no UUID memorization). Registered before the {legislature_id}
// catch-all; same public posture as show().
Route::get('/legislatures', [LegislatureController::class, 'index'])->name('legislatures.index');
Route::get('/legislatures/{legislature_id}', [LegislatureController::class, 'show'])->name('legislatures.show');
// Phase 3e monolith retirement: the district mapper extracted from show()
// onto its own surface. Same public posture + dual-accept (UUID|slug) as
// show(); pre-split mapper deep links (?scope/?map/?setup/?compare) on the
// show route 302 here with the query preserved.
Route::get('/legislatures/{legislature_id}/districts', [LegislatureController::class, 'districts'])->name('legislatures.districts');

// Legislature district editing API
Route::post('/api/legislatures/{legislature_id}/districts', [LegislatureController::class, 'createDistrict'])->name('legislatures.districts.create');
Route::patch('/api/legislatures/{legislature_id}/districts/{district_id}/members', [LegislatureController::class, 'updateDistrictMembers'])->name('legislatures.districts.members');
Route::delete('/api/legislatures/{legislature_id}/districts/{district_id}', [LegislatureController::class, 'deleteDistrict'])->name('legislatures.districts.delete');

// Legislature GeoJSON + auto-composite
Route::get('/api/legislatures/{legislature_id}/revealed.geojson', [LegislatureController::class, 'revealedGeoJson'])->name('legislatures.revealed.geojson');
Route::post('/api/legislatures/{legislature_id}/auto-composite', [LegislatureController::class, 'autoComposite'])->name('legislatures.auto-composite');
Route::post('/api/legislatures/{legislature_id}/mass-reseed', [LegislatureController::class, 'massReseed'])->name('legislatures.mass-reseed');
Route::post('/api/legislatures/{legislature_id}/mass-disband', [LegislatureController::class, 'massDisband'])->name('legislatures.mass-disband');
Route::get('/api/legislatures/{legislature_id}/mass-status', [LegislatureController::class, 'massStatus'])->name('legislatures.mass-status');
Route::post('/api/legislatures/{legislature_id}/mass-halt', [LegislatureController::class, 'massHalt'])->name('legislatures.mass-halt');
Route::get('/api/legislatures/{legislature_id}/districts-at', [LegislatureController::class, 'districtsAt'])->name('legislatures.districts-at');

// Phase H — manual district drawing for a childless leaf giant. probe is the
// read-only live readout behind the draw tool; draw files F-ELB-008 (audited).
// The MUTATING endpoints (draw / split-commit / autoseed commit) require a
// session: F-ELB-008's role gate + board provenance key on the ACTOR, and a
// null actor is the system path (engine bypasses the role gate) — a guest
// POST must never ride it. Probes/preview/balance stay public (read-only).
Route::post('/api/legislatures/{legislature_id}/population-probe', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'probe'])->name('legislatures.population-probe');
Route::post('/api/legislatures/{legislature_id}/subdivisions/draw', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'draw'])->name('legislatures.subdivisions.draw')->middleware('auth');
// Remainder probe (read-only, public like the other probes): the giant minus
// its live drawn districts. The UI's Fill-remainder stages the result like a
// hand draw and commits through the NORMAL draw endpoint — no new filing path.
Route::post('/api/legislatures/{legislature_id}/subdivisions/remainder', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'remainder'])->name('legislatures.subdivisions.remainder');
// Split-line bisection: draw a line, see the population each side, commit both districts.
Route::post('/api/legislatures/{legislature_id}/split-probe', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'splitProbe'])->name('legislatures.split-probe');
Route::post('/api/legislatures/{legislature_id}/split-commit', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'splitCommit'])->name('legislatures.split-commit')->middleware('auth');
// AUTOSEED for a childless leaf giant (Phase 5 design §3, template-aware):
// preview computes the full deterministic plan (read-only) under the chosen
// template (shortest / strips / community_cells), commit recomputes it
// server-side and files one F-ELB-008 per leaf district, and split-balance
// slides a hand-placed line to the nearest in-band seat balance without
// changing its angle.
Route::post('/api/legislatures/{legislature_id}/autoseed-lines/preview', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'autoseedPreview'])->name('legislatures.autoseed-lines.preview');
Route::post('/api/legislatures/{legislature_id}/autoseed-lines/commit', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'autoseedCommit'])->name('legislatures.autoseed-lines.commit')->middleware('auth');
Route::post('/api/legislatures/{legislature_id}/split-balance', [\App\Http\Controllers\Legislature\SubdivisionDrawController::class, 'splitBalance'])->name('legislatures.split-balance');

// Auto-seed stepper: post-order DFS walk of giant scopes (constitutional
// giant_threshold-aware). Returns { steps: [{ scope_id, scope_name }, ...],
// current_index } so the District Mapper can step through every drillable
// jurisdiction in the legislature's giant tree.
Route::get('/api/legislatures/{legislature_id}/wizard-steps', [LegislatureController::class, 'wizardSteps'])->name('legislatures.wizard-steps');

// The district mapper is a long-lived tab (drawing sessions run hours). Its
// heartbeat keeps the session warm so the CSRF token baked into the page at
// render never dies mid-draw (an expired session turns every probe/commit
// POST into an HTML 419 the fetch helpers could only report generically),
// and returns the CURRENT token so a tab revived after laptop sleep can
// re-arm its headers without a reload.
Route::get('/api/session/heartbeat', function () {
    return response()->json([
        'csrf' => csrf_token(),
        'auth' => \Illuminate\Support\Facades\Auth::check(),
    ]);
})->name('session.heartbeat');

// District map management
Route::get('/api/legislatures/{legislature_id}/maps', [LegislatureController::class, 'listMaps'])->name('legislatures.maps.list');
Route::post('/api/legislatures/{legislature_id}/maps', [LegislatureController::class, 'createMap'])->name('legislatures.maps.create');
Route::patch('/api/legislatures/{legislature_id}/maps/{map_id}', [LegislatureController::class, 'updateMap'])->name('legislatures.maps.update');
Route::delete('/api/legislatures/{legislature_id}/maps/{map_id}', [LegislatureController::class, 'deleteMap'])->name('legislatures.maps.delete');
Route::post('/api/legislatures/{legislature_id}/maps/{map_id}/activate', [LegislatureController::class, 'activateMap'])->name('legislatures.maps.activate');
Route::post('/api/legislatures/{legislature_id}/maps/{map_id}/copy', [LegislatureController::class, 'copyMap'])->name('legislatures.maps.copy');

/*
|--------------------------------------------------------------------------
| Phase B — election surfaces (FE-B2…B8 route table)
|--------------------------------------------------------------------------
| The COMPLETE per-design route table (PHASE_B_DESIGN_frontend.md §B),
| registered up front for both Phase B page batches:
|   FE-B2/B3/B4 → Elections\{ElectionController,CandidacyController,
|                 ApprovalController} (this batch)
|   FE-B5…B8   → Elections\{BallotController,ResultsController,
|                 BoardConsoleController,VacancyController} (parallel batch
|                 — routes point at them before the classes exist; Laravel
|                 only resolves controllers at dispatch, so the routes are
|                 inert until those WIs land).
|
| Election records are public to authenticated residents (R-01+); writes
| are guarded by the constitutional engine (role gates + phase windows),
| never by route middleware alone. /receipt-check is deliberately OUTSIDE
| 'auth': the lookup is anonymized by design (design §D — anyone may check
| any hash against ballots.ballot_hash).
*/
Route::middleware('auth')->group(function () {
    // Jurisdiction-scoped resolver: finds the viewer's election (or renders
    // the CLK-01 empty state). The nav entry points below resolve the same
    // way, then forward to the election-scoped page.
    Route::get('/elections', [ElectionController::class, 'index'])->name('elections.index');
    Route::get('/elections/open-ballot', [ElectionController::class, 'entry'])->defaults('target', 'open-ballot')->name('elections.entry.open-ballot');
    Route::get('/elections/candidacy', [ElectionController::class, 'entry'])->defaults('target', 'candidacy')->name('elections.entry.candidacy');
    Route::get('/elections/ranked-ballot', [ElectionController::class, 'entry'])->defaults('target', 'ranked-ballot')->name('elections.entry.ranked-ballot');
    Route::get('/elections/results', [ElectionController::class, 'entry'])->defaults('target', 'results')->name('elections.entry.results');
    // nav.js points the R-08 section at /elections/board + /elections/countback;
    // the canonical surfaces live at /board (vacancy links render per-id).
    Route::redirect('/elections/board', '/board');
    Route::redirect('/elections/countback', '/board');

    // FE-B2 — ElectionDetail
    Route::get('/elections/{election}', [ElectionController::class, 'show'])
        ->whereUuid('election')->name('elections.show');

    // FE-B3 — CandidacyRegistration (F-IND-011) + CandidateProfile
    Route::get('/elections/{election}/candidacy', [CandidacyController::class, 'create'])
        ->whereUuid('election')->name('elections.candidacy.create');
    Route::post('/elections/{election}/candidacy', [CandidacyController::class, 'store'])
        ->whereUuid('election')->name('elections.candidacy.store');
    Route::get('/candidates/{candidacy}', [CandidacyController::class, 'show'])
        ->whereUuid('candidacy')->name('candidates.show');
    Route::patch('/candidates/{candidacy}', [CandidacyController::class, 'update'])
        ->whereUuid('candidacy')->name('candidates.update');                     // F-CAN-001
    Route::post('/candidates/{candidacy}/withdraw', [CandidacyController::class, 'withdraw'])
        ->whereUuid('candidacy')->name('candidates.withdraw');                   // F-CAN-003
    Route::post('/candidates/{candidacy}/endorsement-requests', [CandidacyController::class, 'requestEndorsement'])
        ->whereUuid('candidacy')->name('candidates.endorsement-requests.store'); // F-CAN-002

    // FE-B4 — OpenBallot + approve/revoke (engine actions, no F-ID — design §C)
    Route::get('/elections/{election}/open-ballot', [ApprovalController::class, 'show'])
        ->whereUuid('election')->name('elections.open-ballot');
    Route::post('/elections/{election}/approvals', [ApprovalController::class, 'store'])
        ->whereUuid('election')->name('elections.approvals.store');
    Route::delete('/elections/{election}/approvals/{candidacy}', [ApprovalController::class, 'destroy'])
        ->whereUuid(['election', 'candidacy'])->name('elections.approvals.destroy');

    // FE-B5 — RankedBallot (parallel batch: BallotController)
    Route::get('/elections/{election}/ranked-ballot', [BallotController::class, 'show'])
        ->whereUuid('election')->name('elections.ranked-ballot');
    Route::post('/elections/{election}/races/{race}/ballots', [BallotController::class, 'store'])
        ->whereUuid(['election', 'race'])->name('elections.ballots.store');           // F-IND-007
    // F-IND-008 — QUESTION-scoped, not race-scoped (Phase C C-8: the
    // referendum envelope/ballot pair carries referendum_question_id;
    // race_id is NULL on the referendum kind). Body: {question_id, choice}.
    Route::post('/elections/{election}/referendum-ballots', [BallotController::class, 'storeReferendum'])
        ->whereUuid('election')->name('elections.referendum-ballots.store'); // F-IND-008

    // FE-B6 — Results (parallel batch: ResultsController)
    Route::get('/elections/{election}/results', [ResultsController::class, 'show'])
        ->whereUuid('election')->name('elections.results');
    Route::get('/elections/{election}/results.csv', [ResultsController::class, 'csv'])
        ->whereUuid('election')->name('elections.results.csv');

    // FE-B7 — BoardConsole (parallel batch: BoardConsoleController)
    Route::get('/board', [BoardConsoleController::class, 'show'])->name('board.show');
    Route::post('/board/scheduling-orders', [BoardConsoleController::class, 'schedule'])
        ->name('board.scheduling-orders.store');                                  // F-ELB-001
    Route::post('/board/validations/{candidacy}', [BoardConsoleController::class, 'decideValidation'])
        ->whereUuid('candidacy')->name('board.validations.decide');               // F-ELB-002
    Route::post('/board/petition-audits/{petition}', [BoardConsoleController::class, 'auditPetition'])
        ->whereUuid('petition')->name('board.petition-audits.run');               // F-ELB-005 (FE-C10 — panel live)
    Route::post('/elections/{election}/certify', [BoardConsoleController::class, 'certify'])
        ->whereUuid('election')->name('elections.certify');                       // F-ELB-004
    Route::post('/elections/{election}/recount', [BoardConsoleController::class, 'recount'])
        ->whereUuid('election')->name('elections.recount');                       // F-ELB-006

    // FE-B8 — VacancyCountback (parallel batch: VacancyController)
    Route::get('/vacancies/{vacancy}', [VacancyController::class, 'show'])
        ->whereUuid('vacancy')->name('vacancies.show');
    Route::post('/vacancies/{vacancy}/certify', [VacancyController::class, 'certify'])
        ->whereUuid('vacancy')->name('vacancies.certify');                        // F-ELB-004
    Route::post('/vacancies/{vacancy}/special-election', [VacancyController::class, 'scheduleSpecial'])
        ->whereUuid('vacancy')->name('vacancies.special-election');               // F-ELB-001
});

// Receipt self-audit — public, unauthenticated-OK (anonymized by design).
Route::post('/receipt-check', [BallotController::class, 'receiptCheck'])->name('receipt-check');

/*
|--------------------------------------------------------------------------
| Phase C — legislature / civic / system surfaces (FE-C2…C11 route table)
|--------------------------------------------------------------------------
| The COMPLETE per-design route table (PHASE_C_DESIGN_frontend.md §B),
| registered up front for all three Phase C page batches — the same
| pattern Phase B used:
|   FE-C2…C5  → Legislature\{ChamberResolverController,ChamberController,
|               SessionController,BillController,SettingsController}
|               (this batch)
|   FE-C6…C8  → Legislature\{CommitteeController,SpeakerController,
|               OversightController} (parallel batch — routes point at
|               them before the classes exist; Laravel resolves
|               controllers at dispatch, so the routes are inert until
|               those WIs land)
|   FE-C9…C11 → Legislature\{ReferendumController,EmergencyPowerController},
|               Civic\{PetitionController,RelocationController},
|               System\{PublicRecordsController,TermSyncController}
|               (batch 3 — same posture)
|
| Reads are public-to-authenticated (legislature business is public
| record — Art. II §2); every WRITE is one ConstitutionalEngine filing —
| role gates + state guards live in the engine, never in route
| middleware alone. ConstitutionalViolation → 422 with the citation
| verbatim (errors.constitution).
*/
Route::middleware('auth')->group(function () {
    // ── FE-F — Federation console (Phase F, WF-JUR-06): peers, FF&C sync
    // history, head checkpoints, authority claims. Public-read (Art. II §2).
    Route::get('/federation', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'show'])
        ->name('federation.show');
    // Operator Operations console (Phase 1, read-only): the infra & identity inventory.
    // Public shell; the inventory is operator-gated inside the controller (like the
    // host block on /federation) — a citizen sees only a sign-in prompt.
    Route::get('/operator/operations', [\App\Http\Controllers\Operator\OperatorConsoleController::class, 'operations'])
        ->name('operator.operations');
    // G3b — "Join a cluster": adopt this instance as a read-only mirror, or leave.
    Route::post('/federation/cluster/join', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'join'])
        ->name('federation.cluster.join');
    Route::post('/federation/cluster/leave', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'leave'])
        ->name('federation.cluster.leave');
    // G3b — live seed/drain progress poll for the "Join a cluster" panel (public-read
    // mesh state, Art. II §2; byte + record counts only, no secrets).
    Route::get('/federation/cluster/sync-progress', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'syncProgress'])
        ->name('federation.cluster.sync-progress');

    // The /legislature/* resolver prefix: nav hrefs stay literal while the
    // canonical surfaces are legislature-scoped (§B shared conventions).
    Route::get('/legislature/{sub?}', ChamberResolverController::class)
        ->where('sub', '|session|bills|committees|referendums|emergency-powers|oversight|settings|speaker-tools')
        ->name('legislature.resolve');

    // ── FE-C2 — Chamber (legislature/legislature-home) ──────────────────────
    Route::get('/legislatures/{legislature}/chamber', [ChamberController::class, 'show'])
        ->whereUuid('legislature')->name('chamber.show')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::post('/members/{member}/oath', [ChamberController::class, 'oath'])
        ->whereUuid('member')->name('members.oath');                          // F-LEG-001

    // ── FE-C3 — SessionConsole (legislature/session-console) ────────────────
    Route::get('/legislatures/{legislature}/session', [SessionController::class, 'show'])
        ->whereUuid('legislature')->name('session.show');
    Route::post('/legislatures/{legislature}/sessions', [SessionController::class, 'store'])
        ->whereUuid('legislature')->name('sessions.store');                   // F-SPK-001
    Route::post('/legislatures/{legislature}/speaker-ballot', [SessionController::class, 'launchSpeakerBallot'])
        ->whereUuid('legislature')->name('sessions.speaker-ballot');          // F-LEG-008 (open)
    Route::post('/sessions/{session}/attendance', [SessionController::class, 'attendance'])
        ->whereUuid('session')->name('sessions.attendance');                  // F-LEG-002
    Route::post('/sessions/{session}/quorum', [SessionController::class, 'quorum'])
        ->whereUuid('session')->name('sessions.quorum');                      // F-SPK-003
    Route::post('/sessions/{session}/agenda', [SessionController::class, 'agenda'])
        ->whereUuid('session')->name('sessions.agenda');                      // F-SPK-002
    Route::post('/sessions/{session}/motions', [SessionController::class, 'motion'])
        ->whereUuid('session')->name('sessions.motions');                     // F-LEG-007
    Route::post('/sessions/{session}/statements', [SessionController::class, 'statement'])
        ->whereUuid('session')->name('sessions.statements');                  // F-LEG-006
    Route::post('/sessions/{session}/compel', [SessionController::class, 'compel'])
        ->whereUuid('session')->name('sessions.compel');                      // F-SPK-008
    Route::post('/sessions/{session}/adjourn', [SessionController::class, 'adjourn'])
        ->whereUuid('session')->name('sessions.adjourn');                     // F-SPK-009

    // ONE cast endpoint for every chamber decision — the vote row resolves
    // the canonical form (F-LEG-004 floor / F-LEG-005 committee /
    // F-LEG-008 speaker RCV / F-LEG-011 chair RCV).
    Route::post('/votes/{vote}/cast', [SessionController::class, 'cast'])
        ->whereUuid('vote')->name('votes.cast');
    Route::post('/votes/{vote}/tiebreak', [SessionController::class, 'tiebreak'])
        ->whereUuid('vote')->name('votes.tiebreak');                          // F-SPK-004

    // ── FE-C4 — Bills + BillDetail (legislature/bills, bill-detail) ─────────
    Route::get('/legislatures/{legislature}/bills', [BillController::class, 'index'])
        ->whereUuid('legislature')->name('bills.index')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::post('/legislatures/{legislature}/bills', [BillController::class, 'store'])
        ->whereUuid('legislature')->name('bills.store');                      // F-LEG-003
    Route::post('/legislatures/{legislature}/bills/validate', [BillController::class, 'validateSetting'])
        ->whereUuid('legislature')->name('bills.validate');                   // pure pre-flight
    Route::get('/bills/{bill}', [BillController::class, 'show'])
        ->whereUuid('bill')->name('bills.show')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::post('/bills/{bill}/refer', [BillController::class, 'refer'])
        ->whereUuid('bill')->name('bills.refer');                             // F-LEG-007 / F-CHR-003

    // ── FE-C5 — Settings register (legislature/settings) ────────────────────
    Route::get('/legislatures/{legislature}/settings', [SettingsController::class, 'show'])
        ->whereUuid('legislature')->name('settings.show');

    // ── FE-C6 — Committees + CommitteeDetail (parallel batch) ───────────────
    Route::get('/legislatures/{legislature}/committees', [\App\Http\Controllers\Legislature\CommitteeController::class, 'index'])
        ->whereUuid('legislature')->name('committees.index');
    Route::post('/legislatures/{legislature}/committees', [\App\Http\Controllers\Legislature\CommitteeController::class, 'store'])
        ->whereUuid('legislature')->name('committees.store');                 // F-LEG-009
    Route::post('/legislatures/{legislature}/committee-preferences', [\App\Http\Controllers\Legislature\CommitteeController::class, 'storePreferences'])
        ->whereUuid('legislature')->name('committees.preferences');           // F-LEG-010
    Route::post('/legislatures/{legislature}/committees/assign', [\App\Http\Controllers\Legislature\CommitteeController::class, 'assign'])
        ->whereUuid('legislature')->name('committees.assign');                // F-SPK-005
    Route::get('/committees/{committee}', [\App\Http\Controllers\Legislature\CommitteeController::class, 'show'])
        ->whereUuid('committee')->name('committees.show');
    Route::post('/committees/{committee}/meetings', [\App\Http\Controllers\Legislature\CommitteeController::class, 'storeMeeting'])
        ->whereUuid('committee')->name('committees.meetings');                // F-CHR-001
    Route::post('/meetings/{meeting}/agenda', [\App\Http\Controllers\Legislature\CommitteeController::class, 'meetingAgenda'])
        ->whereUuid('meeting')->name('meetings.agenda');                      // F-CHR-002
    Route::post('/meetings/{meeting}/testimony', [\App\Http\Controllers\Legislature\CommitteeController::class, 'testimony'])
        ->whereUuid('meeting')->name('meetings.testimony');                   // → public_records
    Route::post('/committees/{committee}/reports', [\App\Http\Controllers\Legislature\CommitteeController::class, 'storeReport'])
        ->whereUuid('committee')->name('committees.reports');                 // F-CHR-004
    Route::post('/bills/{bill}/refer-to-floor', [\App\Http\Controllers\Legislature\CommitteeController::class, 'referToFloor'])
        ->whereUuid('bill')->name('bills.refer-to-floor');                    // F-CHR-003
    Route::post('/committees/{committee}/chair-ballot', [\App\Http\Controllers\Legislature\CommitteeController::class, 'openChairBallot'])
        ->whereUuid('committee')->name('committees.chair-ballot');            // F-LEG-011 (re-ballot)

    // ── FE-C7 — SpeakerTools (parallel batch) ───────────────────────────────
    Route::get('/legislatures/{legislature}/speaker', [\App\Http\Controllers\Legislature\SpeakerController::class, 'show'])
        ->whereUuid('legislature')->name('speaker.show');
    Route::post('/legislatures/{legislature}/priorities', [\App\Http\Controllers\Legislature\SpeakerController::class, 'storePriority'])
        ->whereUuid('legislature')->name('speaker.priorities');               // F-SPK-006

    // ── FE-C8 — Oversight (parallel batch) ──────────────────────────────────
    Route::get('/legislatures/{legislature}/oversight', [\App\Http\Controllers\Legislature\OversightController::class, 'show'])
        ->whereUuid('legislature')->name('oversight.show');
    Route::post('/legislatures/{legislature}/investigations', [\App\Http\Controllers\Legislature\OversightController::class, 'intake'])
        ->whereUuid('legislature')->name('oversight.intake');                 // I-ADM intake (audited non-form action)
    Route::post('/investigations/{investigation}/refer', [\App\Http\Controllers\Legislature\OversightController::class, 'referInvestigation'])
        ->whereUuid('investigation')->name('investigations.refer');           // R-29
    Route::post('/legislatures/{legislature}/removal-proceedings', [\App\Http\Controllers\Legislature\OversightController::class, 'openProceeding'])
        ->whereUuid('legislature')->name('oversight.removal-proceedings');    // F-SPK-007 + F-LEG-022
    Route::post('/legislatures/{legislature}/vacancies', [\App\Http\Controllers\Legislature\OversightController::class, 'declareVacancy'])
        ->whereUuid('legislature')->name('oversight.vacancies');              // F-LEG-036
    Route::post('/legislatures/{legislature}/admin-office', [\App\Http\Controllers\Legislature\OversightController::class, 'createOffice'])
        ->whereUuid('legislature')->name('oversight.admin-office');           // F-LEG-013

    // ── FE-C9 — Referendums + EmergencyPowers (batch 3) ─────────────────────
    Route::get('/legislatures/{legislature}/referendums', [\App\Http\Controllers\Legislature\ReferendumController::class, 'index'])
        ->whereUuid('legislature')->name('referendums.index');
    Route::post('/legislatures/{legislature}/referendums', [\App\Http\Controllers\Legislature\ReferendumController::class, 'store'])
        ->whereUuid('legislature')->name('referendums.store');                // F-LEG-023
    Route::post('/laws/{law}/referendum-modification', [\App\Http\Controllers\Legislature\ReferendumController::class, 'modify'])
        ->whereUuid('law')->name('laws.referendum-modification');             // F-LEG-034 (CLK-19 gate)
    Route::get('/legislatures/{legislature}/emergency-powers', [\App\Http\Controllers\Legislature\EmergencyPowerController::class, 'index'])
        ->whereUuid('legislature')->name('emergency-powers.index');
    Route::post('/legislatures/{legislature}/emergency-powers', [\App\Http\Controllers\Legislature\EmergencyPowerController::class, 'store'])
        ->whereUuid('legislature')->name('emergency-powers.store');           // F-LEG-024
    Route::post('/emergency-powers/{power}/renewals', [\App\Http\Controllers\Legislature\EmergencyPowerController::class, 'renew'])
        ->whereUuid('power')->name('emergency-powers.renew');                 // F-LEG-025

    // ── FE-C10 — Petitions + PetitionDetail + Relocation (batch 3) ──────────
    Route::get('/civic/petitions', [\App\Http\Controllers\Civic\PetitionController::class, 'index'])
        ->name('civic.petitions.index');
    Route::post('/civic/petitions', [\App\Http\Controllers\Civic\PetitionController::class, 'store'])
        ->name('civic.petitions.store');                                      // F-IND-009
    Route::get('/civic/petitions/{petition}', [\App\Http\Controllers\Civic\PetitionController::class, 'show'])
        ->whereUuid('petition')->name('civic.petitions.show');
    Route::post('/petitions/{petition}/signatures', [\App\Http\Controllers\Civic\PetitionController::class, 'sign'])
        ->whereUuid('petition')->name('petitions.signatures.store');          // F-IND-010
    Route::delete('/petitions/{petition}/signatures', [\App\Http\Controllers\Civic\PetitionController::class, 'revoke'])
        ->whereUuid('petition')->name('petitions.signatures.revoke');         // F-IND-010 (revocable)
    Route::get('/civic/relocation', [\App\Http\Controllers\Civic\RelocationController::class, 'show'])
        ->name('civic.relocation');
    Route::post('/civic/relocation/travelling', [\App\Http\Controllers\Civic\RelocationController::class, 'travelling'])
        ->name('civic.relocation.travelling');                                // audited engine action, no F-ID

    // ── FE-C11 — PublicRecords + TermSync (batch 3) ─────────────────────────
    Route::get('/system/public-records', [\App\Http\Controllers\System\PublicRecordsController::class, 'index'])
        ->name('system.public-records')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::post('/system/public-records/statements', [\App\Http\Controllers\System\PublicRecordsController::class, 'statement'])
        ->name('system.public-records.statements');                           // F-LEG-006
    Route::get('/system/term-sync', [\App\Http\Controllers\System\TermSyncController::class, 'show'])
        ->name('system.term-sync');

    // ════════════════════════════════════════════════════════════════════════
    // PHASE D — Executive & Organizations (FE-D2..D9). Public read across the
    // board (orders, departments, boards, registry, IP register are public
    // record — Art. II §2 · Art. III); *actions* gate by derived role + engine
    // 422. POSTs run through ConstitutionalEngine::file().
    // ════════════════════════════════════════════════════════════════════════

    // ── FE-D0 — /executive[/{sub}] resolver (nav hrefs → exec-scoped surfaces) ─
    Route::get('/executive/{sub?}', \App\Http\Controllers\Executive\ExecutiveResolverController::class)
        ->where('sub', 'departments|actions|reporting')->name('executive.resolve');

    // ── FE-D2 — Executive/Home ──────────────────────────────────────────────
    Route::get('/executives/{executive}', [\App\Http\Controllers\Executive\ExecutiveController::class, 'show'])
        ->whereUuid('executive')->name('executives.show')->withoutMiddleware('auth'); // public read — Art. II §2

    // ── FE-D3 — Departments + DepartmentDetail (BoG-consent exit surface) ────
    Route::get('/executives/{executive}/departments', [\App\Http\Controllers\Executive\DepartmentController::class, 'index'])
        ->whereUuid('executive')->name('executive.departments')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::get('/departments/{department}', [\App\Http\Controllers\Executive\DepartmentController::class, 'show'])
        ->whereUuid('department')->name('executive.department-detail');
    Route::post('/departments/{department}/nominations', [\App\Http\Controllers\Executive\DepartmentController::class, 'nominate'])
        ->whereUuid('department')->name('executive.departments.nominate');         // F-EXE-001
    Route::post('/departments/{department}/removal-requests', [\App\Http\Controllers\Executive\DepartmentController::class, 'requestRemoval'])
        ->whereUuid('department')->name('executive.departments.removal');          // F-EXE-003

    // ── FE-D5 — DepartmentReporting ─────────────────────────────────────────
    Route::get('/departments/{department}/reporting', [\App\Http\Controllers\Executive\DepartmentReportingController::class, 'show'])
        ->whereUuid('department')->name('departments.reporting');
    Route::post('/departments/{department}/rules', [\App\Http\Controllers\Executive\DepartmentReportingController::class, 'fileRule'])
        ->whereUuid('department')->name('departments.rules');                      // F-BOG-001
    Route::post('/departments/{department}/reports', [\App\Http\Controllers\Executive\DepartmentReportingController::class, 'fileReport'])
        ->whereUuid('department')->name('departments.reports');                    // F-BOG-002

    // ── FE-D4 — Actions (order-rejection exit surface) ──────────────────────
    Route::get('/executives/{executive}/actions', [\App\Http\Controllers\Executive\ExecutiveActionController::class, 'index'])
        ->whereUuid('executive')->name('executive.actions')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::post('/executives/{executive}/orders', [\App\Http\Controllers\Executive\ExecutiveActionController::class, 'storeOrder'])
        ->whereUuid('executive')->name('executive.orders.store');                  // F-EXE-005
    Route::post('/executives/{executive}/policy-proposals', [\App\Http\Controllers\Executive\ExecutiveActionController::class, 'storeProposal'])
        ->whereUuid('executive')->name('executive.proposals.store');               // F-EXE-002
    Route::post('/executives/{executive}/investigations', [\App\Http\Controllers\Executive\ExecutiveActionController::class, 'storeInvestigation'])
        ->whereUuid('executive')->name('executive.investigations.store');          // F-EXE-004
    Route::post('/appropriations/{appropriation}/applications', [\App\Http\Controllers\Executive\ExecutiveActionController::class, 'storeApplication'])
        ->whereUuid('appropriation')->name('executive.appropriations.applications.store');

    // ── FE-D6/D7/D9 — Organizations: STATIC paths BEFORE the {organization} wildcard ─
    Route::get('/organizations', [\App\Http\Controllers\Organizations\OrganizationController::class, 'index'])
        ->name('organizations.index');
    Route::post('/organizations', [\App\Http\Controllers\Organizations\OrganizationController::class, 'store'])
        ->name('organizations.store');                                             // F-IND-012
    Route::get('/organizations/co-determination', [\App\Http\Controllers\Organizations\CoDeterminationController::class, 'show'])
        ->name('organizations.co-determination');                                  // FE-D7 (CLK-13 exit surface)
    Route::get('/organizations/transfers-conversions', [\App\Http\Controllers\Organizations\TransferController::class, 'index'])
        ->name('organizations.transfers-conversions');                             // FE-D9

    Route::get('/organizations/{organization}', [\App\Http\Controllers\Organizations\OrganizationController::class, 'show'])
        ->whereUuid('organization')->name('organizations.show');                   // 302s is_cgc → /cgc
    Route::patch('/organizations/{organization}', [\App\Http\Controllers\Organizations\OrganizationController::class, 'update'])
        ->whereUuid('organization')->name('organizations.update');                 // F-ORG-001
    Route::get('/organizations/{organization}/cgc', [\App\Http\Controllers\Organizations\CgcController::class, 'show'])
        ->whereUuid('organization')->name('organizations.cgc.show');               // FE-D9
    Route::post('/organizations/{organization}/ip-register', [\App\Http\Controllers\Organizations\CgcController::class, 'registerIp'])
        ->whereUuid('organization')->name('organizations.ip-register');            // CGC public-domain dedication (additive only)
    Route::post('/organizations/{organization}/memberships', [\App\Http\Controllers\Organizations\OrganizationController::class, 'storeMembership'])
        ->whereUuid('organization')->name('organizations.memberships.store');      // F-IND-013
    Route::post('/organizations/{organization}/workers', [\App\Http\Controllers\Organizations\OrganizationController::class, 'storeWorker'])
        ->whereUuid('organization')->name('organizations.workers.store');          // F-IND-014 (the headcount feed)
    Route::post('/organizations/{organization}/documents', [\App\Http\Controllers\Organizations\OrganizationController::class, 'storeDocument'])
        ->whereUuid('organization')->name('organizations.documents.store');        // F-ORG-001
    Route::post('/organizations/{organization}/endorsements/{endorsementRequest}/grant', [\App\Http\Controllers\Organizations\OrganizationController::class, 'grantEndorsement'])
        ->whereUuid('organization')->whereUuid('endorsementRequest')->name('organizations.endorsements.grant'); // F-ORG-002
    Route::post('/contracts/{contract}/cosign', [\App\Http\Controllers\Organizations\OrganizationController::class, 'cosignContract'])
        ->whereUuid('contract')->name('contracts.cosign');                         // F-ORG-001 (countersign)

    // ── FE-D8 — BoardElections (owner/worker tracks + joint chair) ──────────
    Route::get('/organizations/{organization}/board-elections', [\App\Http\Controllers\Organizations\BoardElectionController::class, 'show'])
        ->whereUuid('organization')->name('organizations.board-elections');
    Route::post('/organizations/{organization}/board-elections', [\App\Http\Controllers\Organizations\BoardElectionController::class, 'store'])
        ->whereUuid('organization')->name('organizations.board-elections.store');  // F-ORG-003 / F-ORG-004

    // ── FE-D9 — Transfers / conversions / dissolution ───────────────────────
    Route::post('/organizations/{organization}/transfers', [\App\Http\Controllers\Organizations\TransferController::class, 'transfer'])
        ->whereUuid('organization')->name('organizations.transfers');             // F-ORG-005
    Route::post('/transfers/{transfer}/consent', [\App\Http\Controllers\Organizations\TransferController::class, 'consent'])
        ->whereUuid('transfer')->name('transfers.consent');                       // F-ORG-005 (counterparty)
    Route::post('/organizations/{organization}/conversion-requests', [\App\Http\Controllers\Organizations\TransferController::class, 'conversionRequest'])
        ->whereUuid('organization')->name('organizations.conversion-requests');   // F-ORG-006
    Route::post('/organizations/{organization}/dissolution', [\App\Http\Controllers\Organizations\TransferController::class, 'dissolution'])
        ->whereUuid('organization')->name('organizations.dissolution');           // F-ORG-007

    // ════════════════════════════════════════════════════════════════════════
    // PHASE E — Judiciary & Law (FE-E2..E6). Dockets/opinions/challenges are
    // public record (Art. II §2); actions gate by role + engine 422.
    // ════════════════════════════════════════════════════════════════════════

    // ── FE-E0 — per-viewer surfaces (specific routes FIRST) + the resolver ──
    Route::get('/judiciary/advocate', [\App\Http\Controllers\Judiciary\AdvocateController::class, 'show'])
        ->name('judiciary.advocate');
    Route::post('/advocate/registration', [\App\Http\Controllers\Judiciary\AdvocateController::class, 'register'])
        ->name('judiciary.advocate.register');                                    // F-IND-015
    Route::get('/judiciary/jury/{summons}', [\App\Http\Controllers\Judiciary\JurorController::class, 'show'])
        ->whereUuid('summons')->name('judiciary.juror.show');
    Route::post('/judiciary/jury/{summons}/screening', [\App\Http\Controllers\Judiciary\JurorController::class, 'screening'])
        ->whereUuid('summons')->name('judiciary.juror.screening');                // voir-dire (no F-* form)
    Route::get('/judiciary/{sub?}', \App\Http\Controllers\Judiciary\JudiciaryResolverController::class)
        ->where('sub', 'docket|challenges|jury')->name('judiciary.resolve');

    // ── FE-E2 — Judiciary/Home ──────────────────────────────────────────────
    Route::get('/judiciaries/{judiciary}', [\App\Http\Controllers\Judiciary\JudiciaryController::class, 'show'])
        ->whereUuid('judiciary')->name('judiciaries.show')->withoutMiddleware('auth'); // public read — Art. II §2

    // ── FE-E3 — Docket + CaseDetail ─────────────────────────────────────────
    Route::get('/judiciaries/{judiciary}/docket', [\App\Http\Controllers\Judiciary\DocketController::class, 'index'])
        ->whereUuid('judiciary')->name('judiciary.docket')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::post('/judiciaries/{judiciary}/cases', [\App\Http\Controllers\Judiciary\DocketController::class, 'store'])
        ->whereUuid('judiciary')->name('judiciary.cases.store');                  // F-IND-017 / F-ADV-001
    Route::get('/cases/{case}', [\App\Http\Controllers\Judiciary\CaseController::class, 'show'])
        ->whereUuid('case')->name('judiciary.cases.show')->withoutMiddleware('auth'); // public read — Art. II §2
    Route::post('/cases/{case}/acceptance', [\App\Http\Controllers\Judiciary\CaseController::class, 'acceptance'])
        ->whereUuid('case')->name('judiciary.cases.acceptance');                  // F-JDG-001
    Route::post('/cases/{case}/jury-orders', [\App\Http\Controllers\Judiciary\CaseController::class, 'juryOrder'])
        ->whereUuid('case')->name('judiciary.cases.jury-orders');                 // F-JDG-002
    Route::post('/cases/{case}/opinions', [\App\Http\Controllers\Judiciary\CaseController::class, 'opinion'])
        ->whereUuid('case')->name('judiciary.cases.opinions');                    // F-JDG-003
    Route::post('/cases/{case}/sentencing', [\App\Http\Controllers\Judiciary\CaseController::class, 'sentencing'])
        ->whereUuid('case')->name('judiciary.cases.sentencing');                  // F-JDG-009
    Route::post('/cases/{case}/warrants', [\App\Http\Controllers\Judiciary\CaseController::class, 'warrant'])
        ->whereUuid('case')->name('judiciary.cases.warrants');                    // F-JDG-010
    Route::post('/cases/{case}/filings', [\App\Http\Controllers\Judiciary\CaseController::class, 'filing'])
        ->whereUuid('case')->name('judiciary.cases.filings.store');               // F-ADV-002/003/004

    // ── FE-E5 — Constitutional challenges (Art. IV §5 — the exit surface) ────
    Route::get('/constitutional-challenges', [\App\Http\Controllers\Judiciary\ChallengeController::class, 'index'])
        ->name('judiciary.challenges.index');
    Route::get('/constitutional-challenges/{challenge}', [\App\Http\Controllers\Judiciary\ChallengeController::class, 'show'])
        ->whereUuid('challenge')->name('judiciary.challenges.show');
    Route::post('/constitutional-challenges', [\App\Http\Controllers\Judiciary\ChallengeController::class, 'file'])
        ->name('judiciary.challenges.file');                                      // F-IND-016
});

// WI-5/WI-8 — Civic module: dashboard, record, identity, residency claim
// lifecycle + location pings.
Route::middleware('auth')->prefix('civic')->name('civic.')->group(function () {
    Route::get('', [HomeController::class, 'show'])->name('home');
    Route::get('/record', [MyRecordController::class, 'show'])->name('record');
    Route::post('/record/profile', [MyRecordController::class, 'updateProfile'])->name('record.profile');
    Route::get('/identity', [IdentityVerificationController::class, 'show'])->name('identity');
    Route::post('/identity/request', [IdentityVerificationController::class, 'requestAttestation'])->name('identity.request');
    Route::get('/jurisdictions/search', [ResidencyController::class, 'searchJurisdictions'])->name('jurisdictions.search');
    Route::get('/residency', [ResidencyController::class, 'show'])->name('residency');
    // Point-first declare preview: smallest containing jurisdiction + chain.
    Route::post('/residency/locate', [ResidencyController::class, 'locate'])->name('residency.locate');
    Route::post('/residency/declare', [ResidencyController::class, 'declare'])->name('residency.declare');
    Route::post('/residency/confirm', [ResidencyController::class, 'confirm'])->name('residency.confirm');
    Route::post('/residency/redeclare', [ResidencyController::class, 'redeclare'])->name('residency.redeclare');
    Route::post('/pings', [PingController::class, 'store'])->name('pings.store');

    // G-ID (Phase G) — the person manages their own device signing keys and mints
    // the short-lived attestation a client attaches to a forwarded write. Acts on
    // the authenticated user only.
    Route::post('/actor/devices', [\App\Http\Controllers\Identity\ActorIdentityController::class, 'enrollDevice'])->name('actor.devices.enroll');
    Route::post('/actor/attestations', [\App\Http\Controllers\Identity\ActorIdentityController::class, 'issueAttestation'])->name('actor.attestations.issue');

    // Phase K-1 — the public square + halls (the civic record plane). Posting (F-SOC-001) is OPEN to
    // any player (Art. I — corrected 2026-06-27); filing testimony (F-SOC-002) stays residency-gated
    // (a governance seal). There is NO removal route — the square is uncensorable; carve-out removals
    // are the judicial F-SOC-003 path elsewhere.
    Route::get('/square', [\App\Http\Controllers\Civic\PublicSquareController::class, 'index'])->name('square');
    Route::post('/square', [\App\Http\Controllers\Civic\PublicSquareController::class, 'store'])->name('square.store');
    Route::get('/halls', [\App\Http\Controllers\Civic\HallsController::class, 'index'])->name('halls');
    Route::post('/halls', [\App\Http\Controllers\Civic\HallsController::class, 'store'])->name('halls.store');
    Route::post('/halls/testimony', [\App\Http\Controllers\Civic\HallsController::class, 'fileTestimony'])->name('halls.testimony');

    // Phase K-3 (K3-L) — the embedded LIVE commons over the Matrix mesh (Plane B). Reads are
    // appservice-backed + degrade to empty when the homeserver is down; posting is OPEN to any player
    // (room-scoped to the jurisdiction's square/halls), testimony stays residency-gated — same rule as
    // the Plane-A views (corrected 2026-06-27).
    Route::get('/commons/square', [\App\Http\Controllers\Civic\MatrixCommonsController::class, 'square'])->name('commons.square');
    Route::get('/commons/halls', [\App\Http\Controllers\Civic\MatrixCommonsController::class, 'halls'])->name('commons.halls');
    Route::post('/commons/post', [\App\Http\Controllers\Civic\MatrixCommonsController::class, 'post'])->name('commons.post');
    Route::post('/commons/testimony', [\App\Http\Controllers\Civic\MatrixCommonsController::class, 'fileTestimony'])->name('commons.testimony');

    // Phase K-3 (K3-K) — server-side in-conversation translation. The TranslationGate PRIVACY RAIL is
    // enforced here regardless of client: a private room is never cloud-translated (fail-closed).
    Route::post('/matrix/translate', \App\Http\Controllers\Matrix\MatrixTranslationController::class)->name('matrix.translate');

    // Phase K-3 (K3-J) — request a LiveKit (Element Call) join token for the OPEN public commons. Any
    // player may join (Art. I — corrected 2026-06-27); the gate fails closed unless the room is the
    // jurisdiction's square/halls. The token is room-scoped, short-lived, pseudonymous, appservice-signed.
    Route::post('/matrix/call-token', \App\Http\Controllers\Matrix\CallTokenController::class)->name('matrix.call-token');

    // Private rooms (groups / DMs) — the "Art. I private half". A player owns a room + invites friends;
    // MEMBER-gated (never residency), off the civic plane (no testimony, no public record). The call token
    // is the private counterpart to /civic/matrix/call-token — gated on membership, minted locally.
    Route::get('/rooms', [\App\Http\Controllers\Civic\PrivateRoomController::class, 'index'])->name('rooms.index');
    Route::post('/rooms', [\App\Http\Controllers\Civic\PrivateRoomController::class, 'store'])->name('rooms.store');
    Route::get('/rooms/{space}', [\App\Http\Controllers\Civic\PrivateRoomController::class, 'show'])
        ->whereUuid('space')->name('rooms.show');
    Route::post('/rooms/{space}/post', [\App\Http\Controllers\Civic\PrivateRoomController::class, 'post'])
        ->whereUuid('space')->name('rooms.post');
    Route::post('/rooms/{space}/leave', [\App\Http\Controllers\Civic\PrivateRoomController::class, 'leave'])
        ->whereUuid('space')->name('rooms.leave');
    Route::post('/matrix/private-call-token', \App\Http\Controllers\Matrix\PrivateCallTokenController::class)
        ->name('matrix.private-call-token');

    // Phase 5 — foci AV reach. The MIXED-ENVIRONMENT voice endpoint: resolve where voice is served (local
    // SFU, else a capable peer reached via an attested envelope), and return {token, sfu_url} to dial. The
    // commons is OPEN (coarse gate); the client supplies its device-signed proof. Degrades to no-voice when
    // nothing reachable hosts an SFU.
    Route::post('/matrix/voice-reach', \App\Http\Controllers\Matrix\VoiceReachController::class)->name('matrix.voice-reach');
});

// mockups-v3-wiring Phase 3c — the journeys engine: guided learn-by-doing
// arcs with durable per-user completion + profile medals. Auth-gated (progress
// is personal); writes validate against config/cga/journeys.php in
// JourneyService (planned journeys reject; completed journeys freeze).
Route::middleware('auth')->group(function () {
    Route::get('/journeys', [\App\Http\Controllers\Civic\JourneysController::class, 'index'])
        ->name('journeys.index');
    Route::get('/journeys/{id}', [\App\Http\Controllers\Civic\JourneysController::class, 'show'])
        ->where('id', '[a-z0-9\-]+')->name('journeys.show');
    Route::post('/journeys/{id}/steps', [\App\Http\Controllers\Civic\JourneysController::class, 'step'])
        ->where('id', '[a-z0-9\-]+')->name('journeys.step');
    Route::delete('/journeys/{id}/steps', [\App\Http\Controllers\Civic\JourneysController::class, 'unstep'])
        ->where('id', '[a-z0-9\-]+')->name('journeys.unstep');
});

// WI-8 — System of record: read-only audit-chain viewer (auth — the chain
// is the shared public record of the instance) + operator-triggered verify.
Route::middleware('auth')->prefix('system')->name('system.')->group(function () {
    Route::get('/audit-chain', [AuditChainController::class, 'show'])->name('audit-chain');
    Route::post('/audit-chain/verify', [AuditChainController::class, 'verify'])->name('audit-chain.verify');
    // mockups-v3-wiring Phase 2 — read-only registry/ledger pages over
    // EXISTING services (design contracts: mockups/v3/shared/clocks.html,
    // mockups/v3/system/amendments.html). Zero actions by design.
    Route::get('/clocks', [\App\Http\Controllers\System\ClocksController::class, 'show'])->name('clocks');
    Route::get('/amendments', [\App\Http\Controllers\System\AmendmentsController::class, 'show'])->name('amendments');
});

// mockups-v3-wiring Phase 1 — /support/report intake. Anyone may SEE the form
// (public read); FILING is attributed, so the POST stays auth-gated. The intake
// routes a request — it removes nothing (carve-out removals are the judicial
// F-SOC-003 path).
Route::middleware('auth')->group(function () {
    Route::get('/support/report', [\App\Http\Controllers\Support\SupportReportController::class, 'create'])
        ->name('support.report')->withoutMiddleware('auth'); // public read
    Route::post('/support/report', [\App\Http\Controllers\Support\SupportReportController::class, 'store'])
        ->name('support.report.store');
});

// WI-4 — dev tooling: impersonation + ping simulator. Registered ONLY in
// the local environment; DevToolsEnabled additionally 404s at runtime when
// config('cga.impersonation') is off (instant toggle, and testable —
// boot-time registration can't be flipped inside a test). It runs BEFORE
// 'auth' so disabled tooling is indistinguishable from a missing route.
if (app()->environment('local') && config('cga.impersonation', true)) {
    Route::middleware([DevToolsEnabled::class, 'auth'])->prefix('dev')->name('dev.')->group(function () {
        Route::get('/users', [ImpersonationController::class, 'index'])->name('users');
        Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
        Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->name('impersonate');
        Route::post('/pings/simulate', [PingController::class, 'simulate'])->name('pings.simulate');
        // Dev residency bypass: declare → simulated pings → verify, all
        // through the real engine, in one request (dev-only relocation).
        Route::post('/residency/grant', [ResidencyGrantController::class, 'grant'])->name('residency.grant');
        // FE-B1 — fixture-first harness: every Electoral component in every state.
        Route::get('/electoral-kit', [ElectoralKitController::class, 'show'])->name('electoral-kit');
        // FE-C1 — fixture-first harness: every Phase C legislature component.
        Route::get('/legislature-kit', [LegislatureKitController::class, 'show'])->name('legislature-kit');
        // FE-D1 — fixture-first harness: every Phase D executive/orgs component.
        Route::get('/executive-kit', [ExecutiveOrgKitController::class, 'show'])->name('executive-kit');
        // FE-E1 — fixture-first harness: every Phase E judiciary component.
        Route::get('/judiciary-kit', [JudiciaryKitController::class, 'show'])->name('judiciary-kit');
        // Phase 5 — one-click R-08 for districting walkthroughs: seat/unseat
        // the CURRENT user on the active election board of a legislature's
        // jurisdiction (the posture the now-auth-gated draw commits require).
        Route::post('/board/seat', [\App\Http\Controllers\Dev\BoardSeatController::class, 'seat'])->name('board.seat');
        Route::post('/board/unseat', [\App\Http\Controllers\Dev\BoardSeatController::class, 'unseat'])->name('board.unseat');
    });

    // Dev login-as: a passwordless web session for any user — the
    // operator-driven persona switch for live walkthroughs (the chamber
    // needs many distinct members). Reachable WITHOUT a prior session (no
    // 'auth' gate) so it can establish the first one; still local-only +
    // DevToolsEnabled-gated like the rest of the dev tooling.
    Route::middleware(DevToolsEnabled::class)->prefix('dev')->name('dev.')->group(function () {
        Route::post('/login-as', \App\Http\Controllers\Dev\LoginAsController::class)->name('login-as');
    });
}

// ── Phase G (G3c) — the OPERATOR plane: session login on the auth:operator guard
// (separate from the citizen `web` guard) + the host adoption console actions
// (mint/approve invite keys in the browser, no hand-passed handle.secret).
Route::get('/operator/login', [\App\Http\Controllers\Auth\OperatorSessionController::class, 'create'])->name('operator.login');
Route::post('/operator/login', [\App\Http\Controllers\Auth\OperatorSessionController::class, 'store']);
Route::middleware('auth:operator')->group(function () {
    Route::post('/operator/logout', [\App\Http\Controllers\Auth\OperatorSessionController::class, 'destroy'])->name('operator.logout');

    // Operator Operations console (Phase 2) — instant-tier knob edits (operator-gated).
    // Overrides overlay onto config and apply on the next request — no restart.
    Route::post('/operator/operations/tuning', [\App\Http\Controllers\Operator\OperatorConsoleController::class, 'setTuning'])
        ->name('operator.operations.tuning');
    Route::post('/operator/operations/tuning/reset', [\App\Http\Controllers\Operator\OperatorConsoleController::class, 'resetTuning'])
        ->name('operator.operations.tuning.reset');

    // Operator Operations console (Phase 3) — restart-tier host-apply (LiveKit ICE
    // networking). The POST stages a desired-state control file; the host supervisor
    // applies it + recreates the container; the GET polls the apply lifecycle.
    Route::post('/operator/operations/apply', [\App\Http\Controllers\Operator\OperatorConsoleController::class, 'applyInfra'])
        ->name('operator.operations.apply');
    Route::get('/operator/operations/apply-status', [\App\Http\Controllers\Operator\OperatorConsoleController::class, 'applyStatus'])
        ->name('operator.operations.apply-status');

    Route::post('/federation/host/keys', [\App\Http\Controllers\Federation\FederationHostController::class, 'mintKey'])
        ->name('federation.host.keys.mint');
    Route::post('/federation/host/keys/revoke', [\App\Http\Controllers\Federation\FederationHostController::class, 'revokeKey'])
        ->name('federation.host.keys.revoke');
    Route::post('/federation/host/requests/{id}/approve', [\App\Http\Controllers\Federation\FederationHostController::class, 'approveRequest'])
        ->name('federation.host.requests.approve');
    Route::post('/federation/host/requests/{id}/reject', [\App\Http\Controllers\Federation\FederationHostController::class, 'rejectRequest'])
        ->name('federation.host.requests.reject');
    Route::post('/federation/host/rw/{id}/deny', [\App\Http\Controllers\Federation\FederationHostController::class, 'denyReadWrite'])
        ->name('federation.host.rw.deny');

    // G3c — MIRROR side: petition the host for read-write authority (the GUI
    // front door to the governed Art. V §7 flip). Operator-grade; grants nothing.
    Route::post('/federation/cluster/request-read-write', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'requestReadWrite'])
        ->name('federation.cluster.rw-request');

    // G8b — the operator's two-way mesh setup + verification actions (the GUI front doors
    // to federation:peer:discover / :handshake + mesh:doctor). Operator-grade.
    Route::post('/federation/mesh/discover', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'discoverPeer'])
        ->name('federation.mesh.discover');
    Route::post('/federation/mesh/handshake', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'handshakePeer'])
        ->name('federation.mesh.handshake');
    Route::post('/federation/mesh/probe', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'probePeer'])
        ->name('federation.mesh.probe');

    // Mesh Roles ★15 — the capability lifecycle controls (the GUI front doors to mesh:role +
    // transport:register). Operator-grade; a governed channel still routes through the dual-meter consent.
    Route::post('/federation/roles/establish', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'establishChannel'])
        ->name('federation.roles.establish');
    Route::post('/federation/roles/request', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'requestChannel'])
        ->name('federation.roles.request');
    Route::post('/federation/roles/approve', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'approveChannel'])
        ->name('federation.roles.approve');
    Route::post('/federation/roles/revoke', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'revokeChannel'])
        ->name('federation.roles.revoke');
    Route::post('/federation/transports/register', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'registerTransport'])
        ->name('federation.transports.register');
    Route::post('/federation/transports/disable', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'disableTransport'])
        ->name('federation.transports.disable');

    // Mesh Roles — broker credential input (the operator drops the Cloudflare token for a domain into the
    // local, encrypted, never-federated store). Write-only: the token is never read back to the UI.
    Route::post('/federation/broker/credentials', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'setBrokerCredential'])
        ->name('federation.broker.credentials.set');
    Route::post('/federation/broker/credentials/forget', [\App\Http\Controllers\Federation\FederationConsoleController::class, 'forgetBrokerCredential'])
        ->name('federation.broker.credentials.forget');

    // G-OP / G3c — Flow B: link THIS operator account to an existing mesh identity
    // by device-possession proof (the proof string targets POST /operator/link).
    Route::post('/operator/link', [\App\Http\Controllers\Federation\OperatorLinkController::class, 'link'])
        ->name('operator.link');
});

// ── mockups-v3-wiring Phase 4 — the operator/* console suite (PHASE_4_DESIGN_peerage.md
// §3.1) + the traveling-write receipt (§4). READS gate like /operator/operations: the
// page shell is reachable by any authenticated user, the operator data block is built
// only for an auth:operator session (a citizen sees a sign-in prompt). The role-lifecycle
// ACTIONS ride auth:operator, wrapping the mesh:role CLI verbs one-for-one. The G3c
// read-write petition ladder is NOT presented here (design flag 1 — /federation keeps it).
Route::middleware('auth')->group(function () {
    Route::get('/operator', [\App\Http\Controllers\Operator\MeshConsoleController::class, 'home'])
        ->name('operator.home');
    Route::get('/operator/console', [\App\Http\Controllers\Operator\MeshConsoleController::class, 'console'])
        ->name('operator.console');
    Route::get('/operator/roles', [\App\Http\Controllers\Operator\MeshConsoleController::class, 'roles'])
        ->name('operator.roles');
    Route::get('/operator/mesh', [\App\Http\Controllers\Operator\MeshConsoleController::class, 'mesh'])
        ->name('operator.mesh');
    Route::get('/operator/identity', [\App\Http\Controllers\Operator\MeshConsoleController::class, 'identity'])
        ->name('operator.identity');
    Route::get('/operator/versioning', [\App\Http\Controllers\Operator\MeshConsoleController::class, 'versioning'])
        ->name('operator.versioning');

    // The traveling-write receipt: poll the ForwardedWrite outcome for a write YOU
    // filed (own-writes only, determined through the sealed audit row; else 404).
    Route::get('/api/federation/write-status/{origin}/{key}', \App\Http\Controllers\Federation\WriteStatusController::class)
        ->whereUuid('origin')->where('key', '[A-Za-z0-9_\-]{1,128}')
        ->name('federation.write-status');
});

// Phase 4 — the qualify → request → approve → join lifecycle over the wall the
// /operator/operations POSTs use (auth:operator; thin wrappers over the services).
Route::middleware('auth:operator')->group(function () {
    Route::post('/operator/roles/qualify', [\App\Http\Controllers\Operator\MeshRolesController::class, 'qualify'])
        ->name('operator.roles.qualify');
    Route::post('/operator/roles/request', [\App\Http\Controllers\Operator\MeshRolesController::class, 'request'])
        ->name('operator.roles.request');
    Route::post('/operator/roles/approve', [\App\Http\Controllers\Operator\MeshRolesController::class, 'approve'])
        ->name('operator.roles.approve');
    Route::post('/operator/roles/revoke', [\App\Http\Controllers\Operator\MeshRolesController::class, 'revoke'])
        ->name('operator.roles.revoke');
});

// Session auth — register / login / logout (WI-3).
require __DIR__.'/auth.php';
