<?php

namespace App\Http\Controllers\Legislature;

use App\Http\Controllers\Controller;
use App\Models\Legislature;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C0/FE-C2 — the /legislature/* resolver prefix (PHASE_C_DESIGN_frontend.md
 * §B shared conventions). The nav's literal hrefs stay stable while the
 * canonical surfaces are legislature-scoped:
 *
 *   GET /legislature[/{sub}] →
 *     1. viewer holds a seated chamber seat        → 302 to that chamber's sub-path
 *     2. multiple seats                            → /legislatures index as the chooser
 *        (the shell legislature-switcher affordance is WI-9 backlog)
 *     3. no seat → the DEEPEST associated jurisdiction's active legislature
 *        (public read — legislature business is public record, Art. II §2)
 *     4. none active anywhere in the chain         → honest empty state
 *        ("jurisdictions activate at critical population · CLK-06")
 */
class ChamberResolverController extends Controller
{
    /** nav sub-path → canonical legislature-scoped segment. */
    private const TARGETS = [
        ''                 => 'chamber',
        'session'          => 'session',
        'bills'            => 'bills',
        'committees'       => 'committees',
        'referendums'      => 'referendums',
        'emergency-powers' => 'emergency-powers',
        'oversight'        => 'oversight',
        'settings'         => 'settings',
        'speaker-tools'    => 'speaker',
    ];

    public function __invoke(Request $request, string $sub = ''): Response|RedirectResponse
    {
        $target = self::TARGETS[$sub] ?? null;

        abort_if($target === null, 404);

        $user = $request->user();

        // 1/2 — the viewer's own chamber(s).
        $seats = DB::table('legislature_members as m')
            ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
            ->where('m.user_id', (string) $user->getKey())
            ->whereIn('m.status', ['elected', 'seated'])
            ->whereNull('m.deleted_at')
            ->whereNull('l.deleted_at')
            ->distinct()
            ->pluck('l.id');

        if ($seats->count() === 1) {
            return redirect("/legislatures/{$seats->first()}/{$target}");
        }

        if ($seats->count() > 1) {
            return redirect('/legislatures')->with(
                'status',
                'You hold seats in more than one chamber — pick one below (its Chamber link opens the legislature surfaces).'
            );
        }

        // 3 — public read: the deepest associated jurisdiction with an
        // ACTIVE legislature.
        $associated = DB::table('residency_confirmations as rc')
            ->join('legislatures as l', 'l.jurisdiction_id', '=', 'rc.jurisdiction_id')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', (string) $user->getKey())
            ->where('rc.is_active', true)
            ->where('l.status', Legislature::STATUS_ACTIVE)
            ->whereNull('l.deleted_at')
            ->orderByDesc('j.adm_level')
            ->value('l.id');

        if ($associated !== null) {
            return redirect("/legislatures/{$associated}/{$target}");
        }

        // 4 — honest empty state on the Chamber page shape.
        return Inertia::render('Legislature/Chamber', [
            'surface'       => SurfaceMeta::for('legislature/legislature-home'),
            'legislature'   => null,
            'members'       => [],
            'vacancies'     => [],
            'firstSessions' => [],
            'mapperHref'    => '/legislatures',
            'can'           => ['takeOath' => false, 'oathMemberId' => null, 'isMember' => false],
            'empty'         => [
                'note' => 'No active legislature in your association chain — jurisdictions activate at critical population · CLK-06.',
            ],
        ]);
    }
}
