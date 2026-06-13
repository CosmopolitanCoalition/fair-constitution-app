<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-E1 — GET /dev/judiciary-kit: the fixture-first component harness.
 *
 * Renders every Phase E judiciary component in every state from
 * resources/js/fixtures/judiciary.json (mockup-extracted: the
 * State v. Whitfield 10-stage case, the conflict-screened panel incl. the
 * recused-judge re-draw + the full-court major-constitutional variant, the
 * Novák / Curfew §3 Art. IV §5 tracker in window-open / overridden / applied
 * + empty, the NY State 8/9-leg + 40/62-county judiciary conversion, and the
 * juror voir-dire questionnaire clean + flagged) so PanelTable,
 * CaseLifecycle, Art4Section5Tracker, JurorScreening, and the reuse wiring
 * of ConstituentConsentPanel for judiciary conversion are pixel/a11y-verified
 * before ANY Phase E page exists (PHASE_E_DESIGN_frontend.md §D row FE-E1).
 *
 * Gated exactly like /dev/executive-kit: routes registered only in the
 * local environment, DevToolsEnabled (404 when config('cga.impersonation')
 * is off) + auth (routes/web.php WI-4 group).
 */
class JudiciaryKitController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Dev/JudiciaryKit', [
            'surface' => SurfaceMeta::for('dev/judiciary-kit'),
        ]);
    }
}
