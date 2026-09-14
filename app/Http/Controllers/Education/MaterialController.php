<?php

namespace App\Http\Controllers\Education;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The training-material manager (LE-2, F-EDU-002 — operator ruling rubric
 * lesson-publication-shape = A: structural publish).
 *
 * READING IS OPEN like every Learn surface (the read-everywhere rule): the
 * page renders for anyone, listing tracks and modules with their status,
 * revision, and publication stamps. FILING is R-23's: can.publish reflects
 * whether the viewer holds R-23; a non-holder sees a read-only preview. The
 * engine is the real gate — store() files F-EDU-002 and the engine's
 * authorize() enforces R-23 (waived and recorded on a demo box).
 *
 * The ANSWER-KEY RAIL (§2) is untouched: this surface never selects, shows,
 * or accepts education_questions.correct_keys. It writes module STRUCTURE
 * only; lesson prose is authored in the K-2 source, not here.
 */
class MaterialController extends Controller
{
    /** Modules listed per page (bounded; keyset cursor beyond this). */
    private const PAGE = 50;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
    ) {}

    /** GET /learn/manage — the tracks, the bounded module list, the publish form's options. */
    public function index(Request $request): Response
    {
        return Inertia::render('Learn/MaterialManager', [
            'surface' => SurfaceMeta::for('education/material-manager'),
            'can' => ['publish' => $this->canPublish($request)],
            'tracks' => $this->trackOptions(),
            'surfaces' => $this->surfaceOptions(),
            'modules' => $this->modulePage($request->query('cursor')),
        ]);
    }

    /** GET /learn/manage/{module} — the edit form for one existing module. */
    public function edit(Request $request, string $module): Response|RedirectResponse
    {
        $row = DB::table('education_modules as m')
            ->join('education_tracks as t', 't.id', '=', 'm.track_id')
            ->where('m.key', $module)->whereNull('m.deleted_at')->whereNull('t.deleted_at')
            ->orderByDesc('m.updated_at')
            ->first(['m.key', 'm.title', 'm.surface_id', 'm.minutes', 'm.status', 'm.revision_number', 't.key as track_key']);

        if ($row === null) {
            return redirect('/learn/manage');
        }

        return Inertia::render('Learn/MaterialEdit', [
            'surface' => SurfaceMeta::for('education/material-edit'),
            'can' => ['publish' => $this->canPublish($request)],
            'tracks' => $this->trackOptions(),
            'surfaces' => $this->surfaceOptions(),
            'module' => [
                'module_key' => $row->key,
                'title' => $row->title,
                'track_key' => $row->track_key,
                'surface_id' => $row->surface_id,
                'minutes' => $row->minutes === null ? null : (int) $row->minutes,
                'status' => $row->status,
                'revision_number' => (int) ($row->revision_number ?? 1),
            ],
        ]);
    }

    /** POST /learn/manage — file F-EDU-002 through the engine (R-23 enforced there). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'module_key' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'action' => ['required', 'in:publish,revise'],
            'track_key' => ['required', 'string', 'max:64'],
            'surface_id' => ['required', 'string', 'max:64'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', 'in:draft,live'],
            'ip_register_entry_id' => ['nullable', 'string', 'max:64'],
        ]);

        $this->engine->file('F-EDU-002', $request->user(), $data);

        return redirect('/learn/manage')->with('status', 'Publication filed on the public record.');
    }

    // ----------------------------------------------------------------------

    private function canPublish(Request $request): bool
    {
        return $request->user() !== null
            && in_array('R-23', $this->roles->rolesFor($request->user()), true);
    }

    /** Live and draft tracks — the track selector (tracks are few). */
    private function trackOptions(): array
    {
        return DB::table('education_tracks')
            ->whereNull('deleted_at')
            ->orderBy('ordering')->orderBy('key')
            ->limit(200)
            ->get(['key', 'title', 'status'])
            ->map(fn ($t) => ['key' => $t->key, 'title' => $t->title, 'status' => $t->status])
            ->all();
    }

    /** Registered education surfaces — the surface selector for a module. */
    private function surfaceOptions(): array
    {
        $out = [];

        foreach (config('cga.surfaces', []) as $id => $record) {
            if (($record['module'] ?? null) === 'education') {
                $out[] = ['id' => $id, 'title' => $record['title'] ?? $id];
            }
        }

        return $out;
    }

    /**
     * One bounded page of modules, keyset-ordered by (key, id) so the list is
     * always bounded (limit PAGE + 1) with a stable next cursor. published_by
     * resolves to the editor's public display name.
     *
     * @return array{rows: list<array<string, mixed>>, pages: array{next: string|null}}
     */
    private function modulePage(?string $cursor): array
    {
        $query = DB::table('education_modules as m')
            ->join('education_tracks as t', 't.id', '=', 'm.track_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.published_by')
            ->whereNull('m.deleted_at')->whereNull('t.deleted_at')
            ->orderBy('m.key')->orderBy('m.id');

        if (is_string($cursor) && $cursor !== '') {
            [$ck, $cid] = array_pad(explode('|', $cursor, 2), 2, '');
            $query->where(function ($w) use ($ck, $cid) {
                $w->where('m.key', '>', $ck)
                    ->orWhere(fn ($x) => $x->where('m.key', $ck)->where('m.id', '>', $cid));
            });
        }

        $rows = $query->limit(self::PAGE + 1)->get([
            'm.id', 'm.key', 'm.title', 'm.surface_id', 'm.minutes', 'm.status',
            'm.revision_number', 'm.published_at', 'u.display_name as published_by_name',
            't.key as track_key', 't.title as track_title',
        ]);

        $next = null;

        if ($rows->count() > self::PAGE) {
            $last = $rows[self::PAGE - 1];
            $next = '/learn/manage?cursor='.urlencode($last->key.'|'.$last->id);
            $rows = $rows->take(self::PAGE);
        }

        return [
            'rows' => $rows->map(fn ($m) => [
                'module_key' => $m->key,
                'title' => $m->title,
                'track_key' => $m->track_key,
                'track_title' => $m->track_title,
                'surface_id' => $m->surface_id,
                'minutes' => $m->minutes === null ? null : (int) $m->minutes,
                'status' => $m->status,
                'revision_number' => (int) ($m->revision_number ?? 1),
                'published_by' => $m->published_by_name,
                'published_at' => $m->published_at,
                'edit_href' => '/learn/manage/'.$m->key,
            ])->values()->all(),
            'pages' => ['next' => $next],
        ];
    }
}
