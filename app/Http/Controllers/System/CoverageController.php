<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * mockups-v3-wiring — /coverage + /coverage-ops (design contracts:
 * mockups/v3/shared/coverage.html and coverage-ops.html).
 *
 *   GET /coverage       index   the registry-vs-app coverage dashboard
 *   GET /coverage-ops   ops     the dense build-team drift matrix
 *
 * Both are "for the build team" QA instruments. They ship the app's ground
 * truth — the static GET routes the app actually serves and every registered
 * surface (config/cga/surfaces.php via SurfaceMeta) — as props; the Vue pages
 * import the JS registry (surfaces.js) and cross-check the two in the browser
 * (registry/coverage.js). The point is to FAIL loudly when they drift: a
 * registry href or a tour stop pointing at a route the app no longer serves,
 * or a surface `nav` that no menu id answers. Public read — build-team info
 * only (surface names + route paths), no user data, reachable at review time.
 */
class CoverageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('System/Coverage', [
            'surface'  => SurfaceMeta::for('shared/coverage'),
            'routes'   => $this->getRoutePatterns(),
            'surfaces' => $this->configSurfaces(),
        ]);
    }

    public function ops(): Response
    {
        return Inertia::render('System/CoverageOps', [
            'surface'  => SurfaceMeta::for('shared/coverage-ops'),
            'routes'   => $this->getRoutePatterns(),
            'surfaces' => $this->configSurfaces(),
        ]);
    }

    /**
     * Every GET route the app serves, as a leading-slash URI PATTERN (params
     * kept: 'legislature/{sub?}' → '/legislature/{sub?}'). The JS side matches
     * registry hrefs against these patterns, so a nav href served by a resolver
     * route (e.g. /legislature/bills via /legislature/{sub?}) resolves instead
     * of reading as a dead link. Read from the already-registered route
     * collection (no action reflection), so a half-built controller elsewhere
     * in the tree cannot break it.
     *
     * @return list<string>
     */
    private function getRoutePatterns(): array
    {
        $patterns = [];
        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if ($route->isFallback) {
                continue; // a catch-all would falsely "serve" every path
            }
            $patterns['/'.ltrim($route->uri(), '/')] = true;
        }
        $out = array_keys($patterns);
        sort($out);

        return $out;
    }

    /**
     * Every registered surface, flattened to the fields the drift check needs.
     * `nav` is the join key to the JS registry (a surface's active-nav id).
     *
     * @return list<array{id: string, nav: string|null, module: string|null, title: string}>
     */
    private function configSurfaces(): array
    {
        $surfaces = [];
        foreach (SurfaceMeta::ids() as $id) {
            $record = config("cga.surfaces.{$id}", []);
            $surfaces[] = [
                'id'     => $id,
                'nav'    => $record['nav'] ?? null,
                'module' => $record['module'] ?? null,
                'title'  => $record['title'] ?? $id,
            ];
        }

        return $surfaces;
    }
}
