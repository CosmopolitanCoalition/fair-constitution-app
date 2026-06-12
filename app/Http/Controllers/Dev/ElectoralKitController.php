<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B1 — GET /dev/electoral-kit: the fixture-first component harness.
 *
 * Renders every Electoral component in every state from
 * resources/js/fixtures/electoral.json (mockup-extracted STV_DATA + the
 * Manhattan candidates array) so the 8 components are pixel/a11y-verified
 * before ANY electoral backend exists (PHASE_B_DESIGN_frontend.md §E
 * row FE-B1).
 *
 * Gated exactly like the other /dev tooling: routes registered only in the
 * local environment, DevToolsEnabled (404 when config('cga.impersonation')
 * is off) + auth (see routes/web.php WI-4 group).
 */
class ElectoralKitController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Dev/ElectoralKit', [
            'surface' => SurfaceMeta::for('dev/electoral-kit'),
        ]);
    }
}
