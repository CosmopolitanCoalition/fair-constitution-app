<?php

namespace App\Http\Controllers\Education;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Http\Controllers\Controller;
use App\Services\Education\GradingService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Learn pages (Phase K-2, v3 contract: learn/learn-home.html,
 * learn/lesson.html, learn/guides.html).
 *
 * READING IS OPEN TO EVERYONE — guests included (§5.0.2: every training is
 * open to every user; no role gate exists or can be asked for). Taking the
 * check requires an account, because passing files F-EDU-001 on the record.
 *
 * THE ANSWER-KEY RAIL (§2): nothing here ever selects
 * education_questions.correct_keys — the SELECT lists below are explicit,
 * grading is GradingService's job, and the whole path is pinned by
 * EducationAnswerKeySecrecyTest. The v3 mockup's client-side answer index
 * is deliberately NOT copied; a wrong answer gets the explain text, never
 * the right choice.
 */
class LearnController extends Controller
{
    /** GET /learn — tracks, progress, and the role-aware recommendation (the A5 notice's standing form). */
    public function home(Request $request): Response
    {
        $tracks = $this->tracksWithModules($request);

        $recommended = [];

        if ($request->user() !== null) {
            $held = app(ResolvesRoles::class)->rolesFor($request->user());

            foreach (config('cga.education.tracks_by_role', []) as $role => $trackKey) {
                if (in_array($role, $held, true) && ! in_array($trackKey, $recommended, true)) {
                    $recommended[] = $trackKey;
                }
            }
        }

        return Inertia::render('Learn/LearnHome', [
            'surface' => SurfaceMeta::for('learn/home'),
            'tracks' => $tracks,
            'recommended' => $recommended,
        ]);
    }

    /** GET /learn/{track}/{module?} — the lesson + its comprehension check. */
    public function lesson(Request $request, string $track, ?string $module = null): Response|RedirectResponse
    {
        $trackRow = DB::table('education_tracks')
            ->where('key', $track)->where('status', 'live')->whereNull('deleted_at')
            ->first(['id', 'key', 'title']);

        if ($trackRow === null) {
            return redirect('/learn');
        }

        $modules = DB::table('education_modules')
            ->where('track_id', $trackRow->id)->where('status', 'live')->whereNull('deleted_at')
            ->orderBy('ordering')
            ->get(['id', 'key', 'title', 'surface_id', 'minutes']);

        $current = $module === null
            ? $modules->first()
            : $modules->firstWhere('key', $module);

        if ($current === null) {
            return redirect('/learn');
        }

        // The display half only — prompt + choices are i18n keys; the
        // correct choice NEVER rides an Inertia prop (§2, pinned).
        $questions = DB::table('education_questions')
            ->where('module_id', $current->id)->whereNull('deleted_at')
            ->orderBy('ordering')
            ->get(['key', 'prompt', 'choices'])
            ->map(fn ($q) => [
                'key' => $q->key,
                'prompt' => $q->prompt,
                'choices' => json_decode((string) $q->choices, true),
            ])
            ->values();

        $completed = $request->user() === null ? [] : DB::table('education_progress')
            ->join('education_modules', 'education_modules.id', '=', 'education_progress.module_id')
            ->where('education_progress.user_id', (string) $request->user()->id)
            ->where('education_progress.state', 'completed')
            ->pluck('education_modules.key')
            ->all();

        return Inertia::render('Learn/Lesson', [
            'surface' => SurfaceMeta::for('learn/lesson'),
            'track' => ['key' => $trackRow->key, 'title' => $trackRow->title],
            'modules' => $modules->map(fn ($m) => [
                'key' => $m->key, 'title' => $m->title, 'minutes' => $m->minutes,
                'completed' => in_array($m->key, $completed, true),
            ])->values(),
            'module' => [
                'key' => $current->key, 'title' => $current->title,
                'surface_id' => $current->surface_id, 'minutes' => $current->minutes,
                'completed' => in_array($current->key, $completed, true),
            ],
            'questions' => $questions,
            // ?required=1 rides the act-gate's redirect — the banner says why
            // the learner landed here. Informational; it gates nothing.
            'required' => (string) $request->query('required') === '1',
            'quiz' => session('quiz'),
        ]);
    }

    /** POST /learn/{track}/{module}/check — grade server-side; a pass files F-EDU-001. */
    public function check(Request $request, string $track, string $module): RedirectResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['string', 'max:16'],
        ]);

        $result = app(GradingService::class)->grade($track, $module, $validated['answers']);

        if ($result['passed']) {
            // The constitutional act: the completion files through the
            // engine — chain record, progress latch, achievement, the
            // once-only stipend. Wrong answers were never persisted and the
            // filing carries none (the handler refuses answer content).
            app(ConstitutionalEngine::class)->file('F-EDU-001', $request->user(), [
                'track_key' => $track,
                'module_key' => $module,
                'passed' => true,
                'score_pct' => $result['score_pct'],
            ]);
        }

        return back()->with('quiz', [
            'module_key' => $module,
            'passed' => $result['passed'],
            'score_pct' => $result['score_pct'],
            'explain' => $result['explain'],
        ]);
    }

    /** GET /learn/guides — the walkthrough index (journeys are the live half). */
    public function guides(): Response
    {
        $journeys = collect(config('cga.journeys', []))
            ->map(fn ($j, $key) => ['key' => $key, 'title' => $j['title'] ?? $key, 'href' => '/journeys/'.$key])
            ->values();

        return Inertia::render('Learn/Guides', [
            'surface' => SurfaceMeta::for('learn/guides'),
            'journeys' => $journeys,
        ]);
    }

    // ----------------------------------------------------------------------

    private function tracksWithModules(Request $request): array
    {
        $completed = $request->user() === null ? [] : DB::table('education_progress')
            ->where('user_id', (string) $request->user()->id)
            ->where('state', 'completed')
            ->pluck('module_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return DB::table('education_tracks')
            ->where('status', 'live')->whereNull('deleted_at')
            ->orderBy('ordering')
            ->get(['id', 'key', 'title'])
            ->map(fn ($t) => [
                'key' => $t->key,
                'title' => $t->title,
                'modules' => DB::table('education_modules')
                    ->where('track_id', $t->id)->where('status', 'live')->whereNull('deleted_at')
                    ->orderBy('ordering')
                    ->get(['id', 'key', 'title', 'minutes'])
                    ->map(fn ($m) => [
                        'key' => $m->key, 'title' => $m->title, 'minutes' => $m->minutes,
                        'completed' => in_array((string) $m->id, $completed, true),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
