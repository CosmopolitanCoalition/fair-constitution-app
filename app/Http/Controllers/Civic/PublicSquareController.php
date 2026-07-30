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
    /**
     * COMMUNITY STANDARDS (Wave 4 §③, lane 15 educational slice). The square
     * is uncensorable for VIEWPOINT — there is literally no code path to
     * remove a post for its content or opinion. `CarveoutEmitterService`, the
     * ONLY remover, knows nothing but the narrow classes below; each is
     * judicially authorised and logged (matrix_carveout_log + a public
     * moderation-flip record), so a removal is itself on the public record.
     * Grounded in the real F-SOC-003 / M-4 / M-5 mechanisms, not the mockup's
     * looser "four carve-outs" wording. This is the community-standards card;
     * lane 6 owns the post rows, presence, handles and a11y on this page.
     */
    private const COMMUNITY_STANDARDS = [
        'headline' => 'This square cannot be censored for viewpoint.',
        'lede' => 'No control on this page removes a post, and no code path removes one for its content or opinion. The only removals are four narrow, content-neutral carve-outs — each judicially authorised and logged on the public record.',
        'carve_outs' => [
            [
                'key' => 'imminent_harm',
                'label' => 'Imminent harm',
                'what' => 'A true threat of concrete, imminent violence against a person.',
                'why' => 'Speech that is itself an act of harm is not a viewpoint — the narrowest floor every free-speech order keeps.',
                'basis' => 'Legal floor · true_threat (M-5)',
            ],
            [
                'key' => 'private_data',
                'label' => 'Someone else’s private data',
                'what' => 'Publishing another person’s private, identifying data against their will (doxxing).',
                'why' => 'One person’s speech cannot erase another’s Art. I privacy — this protects a right, it does not police an opinion.',
                'basis' => 'F-SOC-003 · rights_protection',
            ],
            [
                'key' => 'off_topic_flooding',
                'label' => 'Off-topic flooding',
                'what' => 'Volume-based spam suppression — by behaviour and rate, never by what is said.',
                'why' => 'Keeping the room usable is not a viewpoint judgment; identical treatment whatever the message (Art. I equal treatment).',
                'basis' => 'Anti-spam · M-4 (behavioural, content-neutral)',
            ],
            [
                'key' => 'legal_floor',
                'label' => 'The legal floor',
                'what' => 'CSAM matched by hash, or a specific court order naming the material.',
                'why' => 'The unavoidable legal minimum — a hash match or a named judicial order, never a discretionary content call.',
                'basis' => 'Legal floor · csam_hashmatch / court_order_specific (M-5)',
            ],
        ],
        'no_viewpoint_path' => 'There is no “remove for content” or “community-guidelines” action anywhere in the code — the carve-out map knows only the classes above. A removal that isn’t one of them cannot be invoked.',
        'logged' => 'Every carve-out is exercised by judicial authority and written to the public record (a moderation-flip entry) — the removal is as visible as the post was.',
    ];

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
            // Lane 15 §③ educational slice — its own props key (lane 6 routes
            // around it and owns the post rows / presence / a11y).
            'standards'     => self::COMMUNITY_STANDARDS,
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
