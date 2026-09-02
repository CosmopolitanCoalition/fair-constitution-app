<?php

namespace App\Http\Controllers;

use App\Services\Autoscale\AutoscaleRunControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lane kill controls for the Step-3 dashboard (operator order 2026-09-02).
 *
 * Deadlines are WARNINGS: the strip colors a lane at cga.lane_warn_seconds.
 * Kills are manual (POST .../lanes/{lease}/kill) or opt-in automatic (POST
 * .../auto-kill sets autoscale_runs.auto_kill_minutes; the pump kills scope
 * claims older than the limit every minute). A killed scope PARKS in review.
 *
 * Both doors are operator-gated here (is_operator), the same posture as the
 * halt / resume controls. The kill itself lives in AutoscaleRunControl so the
 * controller and the pump share one implementation.
 */
class AutoscaleLaneController extends Controller
{
    /** POST /api/setup/wizard/step3/lanes/{lease}/kill */
    public function kill(Request $request, string $lease, AutoscaleRunControl $control): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        if (! Str::isUuid($lease)
            || ! DB::table('autoscale_worker_leases')->where('id', $lease)->exists()) {
            return response()->json(['ok' => false, 'error' => 'No such lane.'], 404);
        }

        // The stamp is the request of record; the pump's sweep kills any
        // stamped lease the immediate kill below could not finish.
        DB::table('autoscale_worker_leases')
            ->where('id', $lease)
            ->update(['kill_requested_at' => now()]);

        $control->killLease($lease, 'killed by operator');

        return response()->json(['ok' => true]);
    }

    /** POST /api/setup/wizard/step3/auto-kill  body {minutes: int|null} */
    public function autoKill(Request $request, AutoscaleRunControl $control): JsonResponse
    {
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);
        $minutes = isset($data['minutes']) ? (int) $data['minutes'] : null;

        $result = $control->setAutoKillMinutes($minutes);
        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $result['error']], 404);
        }

        return response()->json(['ok' => true, 'auto_kill_minutes' => $result['auto_kill_minutes']]);
    }
}
