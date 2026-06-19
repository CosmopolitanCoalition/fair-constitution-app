<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\SocialSpace;
use App\Models\SocialThread;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase K-1 — the halls of governance (FE). Deliberation tied to live governance objects;
 * opening a thread (F-SOC-001) and filing testimony (F-SOC-002 → the append-only public_records
 * seal) are both engine-routed and residency-only (Art. I). Halls are append-only; threads that
 * have been sealed as testimony show their record back-pointer.
 */
class HallsController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $associations = $this->roles->associationsFor($user);
        $chainIds = array_column($associations, 'id');

        $threads = SocialThread::query()
            ->whereHas('subforum.space', function ($q) use ($chainIds) {
                $q->where('space_type', SocialSpace::TYPE_HALLS)
                    ->where('is_private', false)
                    ->when($chainIds !== [], fn ($qq) => $qq->whereIn('jurisdiction_id', $chainIds));
            })
            ->with(['posts' => fn ($q) => $q->orderBy('created_at')->limit(20), 'subforum.space'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return Inertia::render('Civic/Halls', [
            'surface'       => SurfaceMeta::for('civic/halls'),
            'threads'       => $threads->map(fn (SocialThread $t) => [
                'id'              => (string) $t->id,
                'title'           => $t->title,
                'author_display'  => $t->author_display,
                'jurisdiction_id' => (string) ($t->subforum?->space?->jurisdiction_id),
                'sealed'          => $t->published_record_id !== null,
                'posts'          => $t->posts->map(fn ($p) => [
                    'id'             => (string) $p->id,
                    'author_display' => $p->author_display,
                    'body'           => $p->body,
                    'mine'           => $user !== null && (string) $p->author_user_id === (string) $user->getKey(),
                    'at'             => $p->created_at?->toDayDateTimeString(),
                ])->all(),
            ])->all(),
            'jurisdictions' => array_map(fn ($a) => [
                'id' => $a['id'], 'name' => $a['name'], 'adm_level' => $a['adm_level'],
            ], $associations),
            'isAssociated'  => $chainIds !== [],
        ]);
    }

    /** F-SOC-001 — open a deliberation thread in the halls. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jurisdiction_id' => ['required', 'uuid'],
            'title'           => ['required', 'string', 'max:300'],
            'body'            => ['required', 'string', 'max:20000'],
            'thread_id'       => ['nullable', 'uuid'],
        ]);

        $this->engine->file('F-SOC-001', $request->user(), [
            'jurisdiction_id' => $validated['jurisdiction_id'],
            'space_type'      => 'halls',
            'title'           => $validated['title'],
            'body'            => $validated['body'],
            'thread_id'       => $validated['thread_id'] ?? null,
        ]);

        return back()->with('status', 'Posted to the halls (F-SOC-001). File it as testimony to seal it into the append-only record (Art. II §2).');
    }

    /** F-SOC-002 — seal your own hall post into the append-only public register. */
    public function fileTestimony(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jurisdiction_id' => ['required', 'uuid'],
            'thread_id'       => ['required', 'uuid'],
            'post_id'         => ['required', 'uuid'],
        ]);

        $result = $this->engine->file('F-SOC-002', $request->user(), [
            'jurisdiction_id' => $validated['jurisdiction_id'],
            'thread_id'       => $validated['thread_id'],
            'post_id'         => $validated['post_id'],
        ]);

        $recordId = $result->recorded['record_id'] ?? null;

        return back()->with(
            'status',
            'Testimony filed (F-SOC-002) — sealed into the append-only record (Art. II §2).'
            .($recordId !== null ? " Record {$recordId}." : '')
        );
    }
}
