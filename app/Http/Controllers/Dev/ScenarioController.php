<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Http\Middleware\DevTimeControlsEnabled;
use App\Jobs\RunScenarioPresetJob;
use App\Services\AuditService;
use App\Services\Dev\ScenarioPresetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * D5 — the scenario presets' two doors (ruling 10: BUILD; desk green-lit
 * full async 2026-07-28).
 *
 *   GET  /dev/scenario/state     what CAN run here, what IS running, each
 *                                run's live tail — the flyout's 2s poll
 *   POST /dev/scenario/{preset}  queue one preset (one at a time)
 *
 * The state read sits OUTSIDE DevTimeControlsEnabled (same posture as
 * /dev/playtest/state): it must answer when the gate refuses, carrying
 * the refusal sentence VERBATIM, and it withholds the lists until the
 * gate opens. The POST sits INSIDE the strong gate — a preset writes a
 * world.
 *
 * The queue marker files BEFORE dispatch; the seeder's own engine
 * filings then carry the real chain, exactly as a terminal run would.
 */
class ScenarioController extends Controller
{
    public function __construct(
        private readonly ScenarioPresetService $scenarios,
        private readonly AuditService $audit,
    ) {}

    public function state(): JsonResponse
    {
        $reason = DevTimeControlsEnabled::refusalReason();

        if ($reason !== null) {
            return response()->json([
                'enabled' => false,
                'reason' => $reason,
                'presets' => [],
                'unbacked' => [],
                'running' => null,
            ]);
        }

        $presets = [];

        foreach (ScenarioPresetService::presets() as $id => $def) {
            [$ok, $whyNot] = $this->scenarios->probe($id);

            $presets[] = [
                'id' => $id,
                'label' => $def['label'],
                'command' => $def['command'],
                'lights' => $def['lights'],
                'detail' => $def['detail'],
                'available' => $ok,
                'blocked_reason' => $whyNot,
                'run' => $this->scenarios->runState($id),
            ];
        }

        return response()->json([
            'enabled' => true,
            'reason' => null,
            'presets' => $presets,
            'unbacked' => ScenarioPresetService::unbacked(),
            'running' => $this->scenarios->running(),
        ]);
    }

    public function queue(Request $request, string $preset): JsonResponse
    {
        $def = ScenarioPresetService::presets()[$preset] ?? null;

        if ($def === null) {
            return response()->json(['error' => 'Unknown preset.'], 404);
        }

        [$ok, $whyNot] = $this->scenarios->probe($preset);

        if (! $ok) {
            // The advisory probe said no — report its teaching sentence.
            return response()->json(['error' => $whyNot], 409);
        }

        // One run at a time: the seeders share worlds. Cache::add is the
        // atomic claim; the job releases it in finally.
        if (! Cache::add(ScenarioPresetService::LOCK_KEY, $preset, ScenarioPresetService::LOCK_TTL_SECONDS)) {
            return response()->json([
                'error' => 'A scenario is already running ('.$this->scenarios->running().') — one at a time, the seeders share the world.',
            ], 409);
        }

        $userId = $request->user()?->getKey();

        // The marker goes down BEFORE the dispatch: if the queue dies, the
        // chain still says a developer asked for this scenario.
        $this->audit->append(
            module: 'dev',
            event: 'scenario.queued',
            payload: [
                'preset' => $preset,
                'command' => $def['command'],
                'args' => $def['args'],
                'note' => 'PLAYTEST CONTROL — a demo seeder was queued from the Demo flyout. '
                    .'The seeder files its own records through the engine, as a terminal run would.',
            ],
            ref: 'DEV-SCENARIO',
            actorId: $userId !== null ? (string) $userId : null,
        );

        $this->scenarios->putRunState($preset, [
            'preset' => $preset,
            'command' => $def['command'],
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'tail' => '',
            'queued_by' => $userId !== null ? (string) $userId : null,
        ]);

        RunScenarioPresetJob::dispatch($preset, $def['command'], $def['args'], $userId !== null ? (string) $userId : null);

        return response()->json(['queued' => true, 'preset' => $preset, 'command' => $def['command']]);
    }
}
