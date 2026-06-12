<?php

use App\Http\Controllers\Civic\HomeController;
use App\Http\Controllers\Civic\IdentityVerificationController;
use App\Http\Controllers\Civic\MyRecordController;
use App\Http\Controllers\Civic\PingController;
use App\Http\Controllers\Civic\ResidencyController;
use App\Http\Controllers\CosmicAddressController;
use App\Http\Controllers\Dev\ElectoralKitController;
use App\Http\Controllers\Dev\LegislatureKitController;
use App\Http\Controllers\Dev\ImpersonationController;
use App\Http\Controllers\Dev\ResidencyGrantController;
use App\Http\Controllers\Elections\ApprovalController;
use App\Http\Controllers\Elections\BallotController;
use App\Http\Controllers\Elections\BoardConsoleController;
use App\Http\Controllers\Elections\CandidacyController;
use App\Http\Controllers\Elections\ElectionController;
use App\Http\Controllers\Elections\ResultsController;
use App\Http\Controllers\Elections\VacancyController;
use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LegislatureController;
use App\Http\Controllers\MapsController;
use App\Http\Controllers\RasterTileController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\System\AuditChainController;
use App\Http\Middleware\DevToolsEnabled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// WI-8: /civic is the authenticated landing; Home stays the guest welcome.
Route::get('/', function (Request $request) {
    return $request->user() !== null
        ? redirect('/civic')
        : Inertia::render('Home');
});

// Setup wizard — WordPress-style install flow.
Route::get('/setup', [SetupController::class, 'index'])->name('setup.index');
Route::get('/setup/step/{n}', [SetupController::class, 'step'])
    ->where('n', '[0-4]')
    ->name('setup.step');

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
Route::post('/api/setup/wizard/step1/detect', [SetupController::class, 'detectStep1'])->name('api.setup.step1.detect');
Route::post('/api/setup/wizard/step1/activate', [SetupController::class, 'activateStep1'])->name('api.setup.step1.activate');
Route::post('/api/setup/wizard/step2/start', [SetupController::class, 'startMapData'])->name('api.setup.step2.start');
Route::get('/api/setup/wizard/step2/progress', [SetupController::class, 'mapDataProgress'])->name('api.setup.step2.progress');
Route::post('/api/setup/wizard/step2/control', [SetupController::class, 'controlMapData'])->name('api.setup.step2.control');
Route::get('/api/setup/wizard/step3/summary', [SetupController::class, 'step3Summary'])->name('api.setup.step3.summary');
Route::post('/api/setup/wizard/step3/complete', [SetupController::class, 'completeStep3'])->name('api.setup.step3.complete');
Route::post('/api/setup/wizard/step4/complete', [SetupController::class, 'completeStep4'])->name('api.setup.step4.complete');

// Step 2 manual data review — surfaces post-ETL discrepancies BEFORE the user
// clicks Continue (which fires apportionment). Reviewing after districting
// would be too late: apportionment + districts already use the populations.
// No autofix anywhere — operator records decisions, future remediation acts.
Route::get('/api/setup/wizard/step2/review/population_gaps',           [SetupController::class, 'reviewPopulationGaps'])
    ->name('api.setup.step2.review.population_gaps');
Route::get('/api/setup/wizard/step2/review/aggregation_discrepancies', [SetupController::class, 'reviewAggregationDiscrepancies'])
    ->name('api.setup.step2.review.aggregation_discrepancies');
Route::get('/api/setup/wizard/step2/review/orphans',                   [SetupController::class, 'reviewOrphans'])
    ->name('api.setup.step2.review.orphans');
Route::get('/api/setup/wizard/step2/review/sovereign_territories',     [SetupController::class, 'reviewSovereignTerritories'])
    ->name('api.setup.step2.review.sovereign_territories');

// Phase JK assignment-audit drill endpoints
Route::get('/api/setup/wizard/step2/review/parent_assignment_audit',     [SetupController::class, 'reviewParentAssignmentAudit'])
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
Route::get('/api/export/jurisdictions/list',               [JurisdictionController::class, 'exportMapsList'])->name('jurisdictions.export.list');
Route::get('/api/export/jurisdictions/tables',             [JurisdictionController::class, 'exportMapsTables'])->name('jurisdictions.export.tables');
Route::get('/api/export/jurisdictions/download/{filename}',[JurisdictionController::class, 'exportMapsDownload'])
    ->where('filename', '[A-Za-z0-9._-]+\.tar\.gz')
    ->name('jurisdictions.export.download');
Route::post('/api/export/jurisdictions/{exportId}/halt',   [JurisdictionController::class, 'exportMapsHalt'])
    ->where('exportId', '[A-Za-z0-9._-]+')
    ->name('jurisdictions.export.halt');
Route::delete('/api/export/jurisdictions/{exportId}',      [JurisdictionController::class, 'exportMapsDelete'])
    ->where('exportId', '[A-Za-z0-9._-]+')
    ->name('jurisdictions.export.delete');
Route::post('/api/import/jurisdictions',                   [JurisdictionController::class, 'importMaps'])->name('jurisdictions.import');

// Legislature browser
// WI-9: /legislatures is the multi-legislature index (the sidebar's entry
// point — no UUID memorization). Registered before the {legislature_id}
// catch-all; same public posture as show().
Route::get('/legislatures', [LegislatureController::class, 'index'])->name('legislatures.index');
Route::get('/legislatures/{legislature_id}', [LegislatureController::class, 'show'])->name('legislatures.show');

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

// Auto-seed stepper: post-order DFS walk of giant scopes (constitutional
// giant_threshold-aware). Returns { steps: [{ scope_id, scope_name }, ...],
// current_index } so the District Mapper can step through every drillable
// jurisdiction in the legislature's giant tree.
Route::get('/api/legislatures/{legislature_id}/wizard-steps', [LegislatureController::class, 'wizardSteps'])->name('legislatures.wizard-steps');

// District map management
Route::get('/api/legislatures/{legislature_id}/maps', [LegislatureController::class, 'listMaps'])->name('legislatures.maps.list');
Route::post('/api/legislatures/{legislature_id}/maps', [LegislatureController::class, 'createMap'])->name('legislatures.maps.create');
Route::patch('/api/legislatures/{legislature_id}/maps/{map_id}', [LegislatureController::class, 'updateMap'])->name('legislatures.maps.update');
Route::delete('/api/legislatures/{legislature_id}/maps/{map_id}', [LegislatureController::class, 'deleteMap'])->name('legislatures.maps.delete');
Route::post('/api/legislatures/{legislature_id}/maps/{map_id}/activate', [LegislatureController::class, 'activateMap'])->name('legislatures.maps.activate');
Route::post('/api/legislatures/{legislature_id}/maps/{map_id}/copy',     [LegislatureController::class, 'copyMap'])->name('legislatures.maps.copy');

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
    Route::get('/elections/open-ballot',   [ElectionController::class, 'entry'])->defaults('target', 'open-ballot')->name('elections.entry.open-ballot');
    Route::get('/elections/candidacy',     [ElectionController::class, 'entry'])->defaults('target', 'candidacy')->name('elections.entry.candidacy');
    Route::get('/elections/ranked-ballot', [ElectionController::class, 'entry'])->defaults('target', 'ranked-ballot')->name('elections.entry.ranked-ballot');
    Route::get('/elections/results',       [ElectionController::class, 'entry'])->defaults('target', 'results')->name('elections.entry.results');
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
    Route::post('/elections/{election}/races/{race}/referendum-ballots', [BallotController::class, 'storeReferendum'])
        ->whereUuid(['election', 'race'])->name('elections.referendum-ballots.store'); // F-IND-008

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
});

// WI-8 — System of record: read-only audit-chain viewer (auth — the chain
// is the shared public record of the instance) + operator-triggered verify.
Route::middleware('auth')->prefix('system')->name('system.')->group(function () {
    Route::get('/audit-chain', [AuditChainController::class, 'show'])->name('audit-chain');
    Route::post('/audit-chain/verify', [AuditChainController::class, 'verify'])->name('audit-chain.verify');
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
    });
}

// Session auth — register / login / logout (WI-3).
require __DIR__.'/auth.php';
