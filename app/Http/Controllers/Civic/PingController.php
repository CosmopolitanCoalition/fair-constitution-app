<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Services\ResidencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * WI-5 — location pings.
 *
 *   POST /civic/pings          — manual ping (browser geolocation or
 *                                lat/lng fields) → F-IND-005 via the engine
 *   POST /dev/pings/simulate   — dev-only backdated one-per-day pings at
 *                                the declared boundary's point-on-surface
 *                                (gated by DevToolsEnabled, exactly like
 *                                impersonation — WI-4)
 *
 * PRIVACY: coordinates go into location_pings only; audit entries carry
 * count-bumps, and verification purges the raw pings.
 */
class PingController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ResidencyService $residency,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'latitude'        => ['required', 'numeric', 'between:-90,90'],
            'longitude'       => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->engine->file('F-IND-005', $request->user(), [
            'latitude'        => (float) $validated['latitude'],
            'longitude'       => (float) $validated['longitude'],
            'accuracy_meters' => $validated['accuracy_meters'] ?? null,
            'source'          => 'web',
        ]);

        $days = $result->recorded['qualifying_days'] ?? null;

        return back()->with(
            'status',
            $days === null ? 'Ping recorded.' : "Ping recorded — {$days} qualifying day(s)."
        );
    }

    /** Dev simulator: POST /dev/pings/simulate {days} (default 30). */
    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
        ]);

        $summary = $this->residency->simulatePings(
            $request->user(),
            (int) ($validated['days'] ?? 30)
        );

        return response()->json($summary);
    }
}
