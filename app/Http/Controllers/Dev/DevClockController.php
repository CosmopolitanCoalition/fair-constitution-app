<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Dev\DemoMeshTimeCoordinator;
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
    public function advance(Request $request, DevClockService $clock, DemoMeshTimeCoordinator $mesh): JsonResponse
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

            // A FOLLOWER does not originate — the coordinator's advance replays
            // here on sync. Preview is fine; apply is refused with the coordinator
            // named, VERBATIM (DEMO_MESH_TIME_COORDINATION §3). 422, not 404: the
            // base gate opened (this is a demo mesh member), only origination here
            // is refused — the UI shows the plan and this sentence, never nothing.
            if ($meshReason = $mesh->localAdvanceRefusal()) {
                return response()->json(['applied' => false, 'plan' => $plan, 'error' => $meshReason], 422);
            }

            // Originate through the coordinator: runs the same engine advance AND
            // publishes the mesh record declared-demo followers replay.
            $result = $mesh->originateAdvance($days);

            return response()->json(['applied' => true, 'plan' => $plan, 'result' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Designate the demo-mesh time coordinator, or assert/withdraw skew tolerance
     * (§4). The UI twin of `dev:mesh-time --set/--self/--tolerate-skew/--strict`;
     * both sit behind DevTimeControlsEnabled, so this only answers on a demo node.
     */
    public function coordinator(Request $request, DemoMeshTimeCoordinator $mesh): JsonResponse
    {
        $data = $request->validate([
            'coordinator_server_id' => ['sometimes', 'nullable', 'uuid'],
            'self'                  => ['sometimes', 'boolean'],
            'skew_tolerated'        => ['sometimes', 'boolean'],
        ]);

        try {
            if (array_key_exists('skew_tolerated', $data)) {
                $mesh->setSkewTolerance((bool) $data['skew_tolerated']);
            }

            if (! empty($data['self'])) {
                $mesh->setCoordinator(null);
            } elseif (array_key_exists('coordinator_server_id', $data)) {
                $mesh->setCoordinator($data['coordinator_server_id']);
            }

            return response()->json(['mesh' => $mesh->status()]);
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
