<?php

namespace App\Http\Controllers\Education;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Http\Controllers\Controller;
use App\Services\Education\GradingService;
use App\Support\MediaMeta;
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

        // LE-3: the lesson's library film. The assignment is authored in the
        // K-2 corpus and emitted (with the demo fallback resolved) to
        // resources/js/registry/education.videos.json. The client reads the
        // same registry for the film's source label (surface vs default note);
        // here the server hands the player a ready MediaMeta record + base URL.
        $videoId = $this->lessonVideoId($current->surface_id);
        $video = $videoId === null ? null : MediaMeta::for($videoId);

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
            // The lesson film for the multi-track player, or null when the
            // surface resolves to no film. baseUrl null => the player's poster.
            'video' => $video,
            'videoBaseUrl' => $video === null ? null : MediaMeta::baseUrl(),
            // ?required=1 rides the act-gate's redirect — the banner says why
            // the learner landed here. Informational; it gates nothing.
            'required' => (string) $request->query('required') === '1',
            'quiz' => session('quiz'),
        ]);
    }

    /**
     * The catalog id of the lesson's library film for a surface, or null.
     *
     * Reads the generated {surfaceId: videoId} map
     * (resources/js/registry/education.videos.json, LE-3). The map is keyed by
     * the corpus surface ids; older seeded curricula carry the pre-rename
     * surface ids, so the same legacy aliases as
     * resources/js/composables/lessonContent.js are applied before the lookup.
     * The id is confirmed against the catalog so a drifted map never lets
     * MediaMeta::for() throw onto the lesson page.
     */
    private function lessonVideoId(?string $surfaceId): ?string
    {
        if ($surfaceId === null) {
            return null;
        }

        // Mirror of composables/lessonContent.js LEGACY_SURFACES.
        $legacy = [
            'legislature/floor' => 'legislature/session-console',
            'elections/board' => 'elections/board-console',
            'executive/office' => 'executive/executive-home',
            'judiciary/court' => 'judiciary/judiciary-home',
            'judiciary/cases' => 'judiciary/advocate-console',
        ];

        $map = $this->videoAssignments();
        $id = $map[$surfaceId] ?? ($map[$legacy[$surfaceId] ?? ''] ?? null);

        if ($id === null || ! in_array($id, MediaMeta::ids(), true)) {
            return null;
        }

        return $id;
    }

    /**
     * The generated surface->video map, or an empty map when it is absent.
     *
     * @return array<string, string>
     */
    private function videoAssignments(): array
    {
        $path = resource_path('js/registry/education.videos.json');

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
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

    /** The old guide directory is an alias of the complete journey directory. */
    public function guides(): RedirectResponse
    {
        return redirect()->route('journeys.index');
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
