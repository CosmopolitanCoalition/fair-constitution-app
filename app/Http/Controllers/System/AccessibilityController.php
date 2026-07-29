<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\SurfaceMeta;
use Inertia\Inertia;
use Inertia\Response;

/**
 * mockups-v3-wiring — /system/accessibility (design contract:
 * mockups/v3/shared/accessibility.html).
 *
 *   GET /system/accessibility   show   (public read)
 *
 * READ-ONLY, zero actions — the accessibility statement the footer links to
 * on every page. Static content; the surface id is `shared/accessibility`
 * (module `shared`) so the authored Learn copy in registry/education.js
 * resolves. Public because the footer link and citations appear before a
 * session exists.
 */
class AccessibilityController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('System/Accessibility', [
            'surface' => SurfaceMeta::for('shared/accessibility'),
        ]);
    }
}
