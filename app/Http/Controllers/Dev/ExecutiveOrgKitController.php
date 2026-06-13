<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D1 — GET /dev/executive-kit: the fixture-first component harness.
 *
 * Renders every Phase D executive/organizations component in every state
 * from resources/js/fixtures/executive.json (mockup-extracted: the
 * executive-home F-LEG-015 dual-supermajority record, the Bluefin
 * co-determination scale, the Public Works & Utilities board roster, the
 * executive-actions order register incl. the rejected-pre-issuance row)
 * so ConstituentConsentPanel, CoDetScale, BoardStrip, OwnershipPanel,
 * DepartmentCard, OrderScopeCard and Ui/Stepper are pixel/a11y-verified
 * before ANY Phase D backend exists (PHASE_D_DESIGN_frontend.md §E row
 * FE-D1).
 *
 * Gated exactly like /dev/electoral-kit and /dev/legislature-kit: routes
 * registered only in the local environment, DevToolsEnabled (404 when
 * config('cga.impersonation') is off) + auth (routes/web.php WI-4 group).
 */
class ExecutiveOrgKitController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Dev/ExecutiveOrgKit', [
            'surface' => SurfaceMeta::for('dev/executive-kit'),
        ]);
    }
}
