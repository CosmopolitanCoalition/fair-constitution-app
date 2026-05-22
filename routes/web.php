<?php

use App\Http\Controllers\CosmicAddressController;
use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LegislatureController;
use App\Http\Controllers\MapsController;
use App\Http\Controllers\RasterTileController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
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
Route::get('/api/export/jurisdictions',                    [JurisdictionController::class, 'exportMaps'])->name('jurisdictions.export');
Route::get('/api/export/jurisdictions/list',               [JurisdictionController::class, 'exportMapsList'])->name('jurisdictions.export.list');
Route::get('/api/export/jurisdictions/download/{filename}',[JurisdictionController::class, 'exportMapsDownload'])
    ->where('filename', '[A-Za-z0-9._-]+\.tar\.gz')
    ->name('jurisdictions.export.download');
Route::delete('/api/export/jurisdictions/{exportId}',      [JurisdictionController::class, 'exportMapsDelete'])
    ->where('exportId', '[A-Za-z0-9._-]+')
    ->name('jurisdictions.export.delete');
Route::post('/api/import/jurisdictions',                   [JurisdictionController::class, 'importMaps'])->name('jurisdictions.import');

// Legislature browser
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
Route::post('/api/legislatures/{legislature_id}/recolor', [LegislatureController::class, 'recolor'])->name('legislatures.recolor');
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
