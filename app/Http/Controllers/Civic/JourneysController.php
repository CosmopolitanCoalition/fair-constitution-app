<?php

namespace App\Http\Controllers\Civic;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\JourneyProgress;
use App\Services\JourneyService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * mockups-v3-wiring Phase 3c — the journeys engine (design contract
 * mockups/v3/journeys/journey.html + config/cga/journeys.php).
 *
 * GET  /journeys           — every journey merged with the user's progress
 * GET  /journeys/{id}      — one journey's arc + progress + medal (if earned)
 * POST /journeys/{id}/steps    {step} — mark a step done
 * DELETE /journeys/{id}/steps  {step} — un-mark (only while not completed)
 *
 * Soft-gate rule: journeys nudge, they never block. A medal never changes
 * a vote, a seat, or what you are allowed to do.
 */
class JourneysController extends Controller
{
    public function __construct(private readonly JourneyService $journeys)
    {
    }

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->id;

        $progressByJourney = JourneyProgress::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('journey_id');

        $journeys = collect(config('cga.journeys', []))
            ->map(function (array $journey, string $id) use ($progressByJourney) {
                /** @var JourneyProgress|null $progress */
                $progress = $progressByJourney->get($id);

                return [
                    'id'          => $id,
                    'title'       => $journey['title'],
                    'status'      => $journey['status'],
                    'cls'         => $journey['cls'],
                    'steps_total' => count($journey['steps']),
                    'steps_done'  => count($progress->steps_done ?? []),
                    'completed'   => $progress?->completed_at !== null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Civic/Journeys', [
            'surface'  => SurfaceMeta::for('civic/journeys'),
            'journeys' => $journeys,
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $journey = config("cga.journeys.{$id}");
        abort_if(! is_array($journey), 404);

        $user     = $request->user();
        $progress = $this->journeys->progress($user, $id);

        $achievement = Achievement::query()
            ->where('user_id', (string) $user->id)
            ->where('journey_id', $id)
            ->first();

        return Inertia::render('Civic/Journey', [
            'surface' => SurfaceMeta::for('civic/journey'),
            'journey' => [
                'id'     => $id,
                'title'  => $journey['title'],
                'steps'  => $journey['steps'],
                'status' => $journey['status'],
                'cls'    => $journey['cls'],
            ],
            'progress' => [
                'stepsDone'   => array_values(array_map('intval', $progress->steps_done ?? [])),
                'completedAt' => $progress?->completed_at?->toIso8601String(),
            ],
            'achievement' => $achievement === null ? null : [
                'id'        => (string) $achievement->id,
                'title'     => $achievement->title,
                'earned_at' => $achievement->earned_at?->toIso8601String(),
            ],
        ]);
    }

    /** Mark a 0-based step done (completion earns the medal in-service). */
    public function step(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate(['step' => ['required', 'integer', 'min:0']]);

        $this->journeys->markStep($request->user(), $id, (int) $validated['step']);

        return back();
    }

    /** Un-mark a step — rejected once the journey is complete (frozen). */
    public function unstep(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate(['step' => ['required', 'integer', 'min:0']]);

        $this->journeys->unmarkStep($request->user(), $id, (int) $validated['step']);

        return back();
    }
}
