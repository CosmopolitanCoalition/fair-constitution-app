<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C1 — GET /dev/legislature-kit: the fixture-first component harness.
 *
 * Renders every Phase C legislature component in every state from
 * resources/js/fixtures/legislature.json (mockup-extracted chamber +
 * threshold grammar; a synthetic San Marino-shaped 41-seat bicameral
 * chamber) so SeatMap, VoteTally, AgendaStrip, VoteCastList, LawDiff,
 * SignatureMeter, EmergencyBanner and the generalized RankList are
 * pixel/a11y-verified before ANY Phase C backend exists
 * (PHASE_C_DESIGN_frontend.md §E row FE-C1).
 *
 * Gated exactly like /dev/electoral-kit: routes registered only in the
 * local environment, DevToolsEnabled (404 when config('cga.impersonation')
 * is off) + auth (see routes/web.php WI-4 group).
 */
class LegislatureKitController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Dev/LegislatureKit', [
            'surface' => SurfaceMeta::for('dev/legislature-kit'),
        ]);
    }
}
