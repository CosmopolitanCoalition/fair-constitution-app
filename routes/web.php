<?php

use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LegislatureController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Home');
});

// Jurisdiction viewer
Route::get('/jurisdictions', [JurisdictionController::class, 'index'])->name('jurisdictions.index');
Route::get('/jurisdictions/{jurisdiction}', [JurisdictionController::class, 'show'])->name('jurisdictions.show');

// GeoJSON API endpoints
Route::get('/api/jurisdictions/{jurisdiction}/children.geojson', [JurisdictionController::class, 'childrenGeoJson'])->name('jurisdictions.children.geojson');
Route::get('/api/jurisdictions/{jurisdiction}/self.geojson', [JurisdictionController::class, 'selfGeoJson'])->name('jurisdictions.self.geojson');
Route::get('/api/jurisdictions/{jurisdiction}/siblings.geojson', [JurisdictionController::class, 'siblingsGeoJson'])->name('jurisdictions.siblings.geojson');
Route::get('/api/jurisdictions/{jurisdiction}/districts.geojson', [JurisdictionController::class, 'districtsGeoJson'])->name('jurisdictions.districts.geojson');

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
Route::post('/api/legislatures/{legislature_id}/recolor', [LegislatureController::class, 'recolor'])->name('legislatures.recolor');
Route::get('/api/legislatures/{legislature_id}/districts-at', [LegislatureController::class, 'districtsAt'])->name('legislatures.districts-at');

// District map management
Route::get('/api/legislatures/{legislature_id}/maps', [LegislatureController::class, 'listMaps'])->name('legislatures.maps.list');
Route::post('/api/legislatures/{legislature_id}/maps', [LegislatureController::class, 'createMap'])->name('legislatures.maps.create');
Route::patch('/api/legislatures/{legislature_id}/maps/{map_id}', [LegislatureController::class, 'updateMap'])->name('legislatures.maps.update');
Route::delete('/api/legislatures/{legislature_id}/maps/{map_id}', [LegislatureController::class, 'deleteMap'])->name('legislatures.maps.delete');
Route::post('/api/legislatures/{legislature_id}/maps/{map_id}/activate', [LegislatureController::class, 'activateMap'])->name('legislatures.maps.activate');
Route::post('/api/legislatures/{legislature_id}/maps/{map_id}/copy',     [LegislatureController::class, 'copyMap'])->name('legislatures.maps.copy');
