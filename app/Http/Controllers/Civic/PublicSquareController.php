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
 * Phase K-1 — the public square (FE). Open civic discourse; posting (F-SOC-001) is engine-routed and
 * OPEN to any player — resident or visitor (Art. I — free movement + equal treatment; corrected
 * 2026-06-27). Residency gates POWERS (and the testimony seal), not square access. The page never 403s
 * a viewer — it reads (public) and lets any signed-in player post. There is NO removal control here:
 * the square is uncensorable.
 */
class PublicSquareController extends Controller
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
                $q->where('space_type', SocialSpace::TYPE_PUBLIC_SQUARE)
                    ->where('is_private', false)
                    ->when($chainIds !== [], fn ($qq) => $qq->whereIn('jurisdiction_id', $chainIds));
            })
            ->with(['posts' => fn ($q) => $q->orderBy('created_at')->limit(20)])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return Inertia::render('Civic/PublicSquare', [
            'surface'       => SurfaceMeta::for('civic/public-square'),
            'threads'       => $threads->map(fn (SocialThread $t) => $this->threadRow($t))->all(),
            'jurisdictions' => array_map(fn ($a) => [
                'id' => $a['id'], 'name' => $a['name'], 'adm_level' => $a['adm_level'],
            ], $associations),
            'isAssociated'  => $chainIds !== [],
        ]);
    }

    /** F-SOC-001 — open a thread / post in the public square (residency-only, uncensorable). */
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
            'space_type'      => 'public_square',
            'title'           => $validated['title'],
            'body'            => $validated['body'],
            'thread_id'       => $validated['thread_id'] ?? null,
        ]);

        return back()->with('status', 'Posted to the public square (F-SOC-001) — open discourse, residency-only, uncensorable (Art. I).');
    }

    private function threadRow(SocialThread $thread): array
    {
        return [
            'id'             => (string) $thread->id,
            'title'          => $thread->title,
            'author_display' => $thread->author_display,
            'posts'          => $thread->posts->map(fn ($p) => [
                'id'             => (string) $p->id,
                'author_display' => $p->author_display,
                'body'           => $p->body,
                'at'             => $p->created_at?->toDayDateTimeString(),
            ])->all(),
        ];
    }
}
