<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Dev\DevClockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Playtest time controls over HTTP (P1, P2).
 *
 * Same two actions as the console commands and the same service behind them,
 * so a journey can be walked from the browser without dropping to a terminal.
 * Gated by DevTimeControlsEnabled on the route: local, sandbox, `cga.dev_time`,
 * and refused outright on any federated or peered node.
 *
 * ADVANCE IS A DRY RUN UNLESS ASKED OTHERWISE. `apply` must be sent
 * explicitly, because a ten-year advance on a founded world fires a great deal
 * at once and the operator must be able to see the list before it happens.
 */
class DevClockController extends Controller
{
    public function advance(Request $request, DevClockService $clock): JsonResponse
    {
        $data = $request->validate([
            'days'  => ['required', 'integer', 'min:1', 'max:73000'],
            'apply' => ['sometimes', 'boolean'],
        ]);

        $days = (int) $data['days'];

        try {
            // Always compute the dry run — it is what the caller sees when
            // they have not asked to apply, and the record of what they were
            // shown when they have.
            $plan = $clock->dryRun($days);

            if (empty($data['apply'])) {
                return response()->json(['applied' => false, 'plan' => $plan]);
            }

            $result = $clock->advance($days);

            return response()->json(['applied' => true, 'plan' => $plan, 'result' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function fire(string $timer, DevClockService $clock): JsonResponse
    {
        try {
            return response()->json($clock->fireOne($timer));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
