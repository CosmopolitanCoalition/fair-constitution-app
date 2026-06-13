<?php

namespace App\Http\Controllers\Executive;

use App\Http\Controllers\Controller;
use App\Models\ExecutiveMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FE-D0 — the /executive[/{sub}] resolver (PHASE_D_DESIGN_frontend.md §B
 * "Entry resolvers", mirroring ChamberResolverController). The nav's
 * literal hrefs stay stable while the canonical surfaces are
 * executive-scoped:
 *
 *   GET /executive[/{sub}] →
 *     1. viewer holds a seated executive_members row → 302 to that
 *        executive's sub-path
 *     2. multiple seats → the same, with a chooser status line
 *     3. no seat → the DEEPEST associated jurisdiction's executive
 *        (public read — executive business is public record, Art. II §2 ·
 *        Art. III)
 *     4. none in the chain → /civic with an honest status line
 *        (Executive/Home requires a non-null office; there is no executive
 *        to show, so we explain rather than render an empty office)
 *
 * `reporting` is special (§B.4): a seated governor (R-18) resolves to
 * THEIR department's reporting page; multiple governorships → one, with a
 * chooser status; non-governors fall through to the departments index.
 */
class ExecutiveResolverController extends Controller
{
    /** nav sub-path → executive-scoped segment ('' = the home surface). */
    private const TARGETS = [
        '' => '',
        'departments' => 'departments',
        'actions' => 'actions',
        'reporting' => 'reporting', // resolved specially below
    ];

    public function __invoke(Request $request, string $sub = ''): RedirectResponse
    {
        abort_unless(array_key_exists($sub, self::TARGETS), 404);

        $user = $request->user();

        if ($sub === 'reporting') {
            return $this->resolveReporting($user);
        }

        $seats = $this->seatedExecutiveIds($user);
        $executiveId = $seats->first() ?? $this->publicReadExecutiveId($user);

        if ($executiveId === null) {
            return redirect('/civic')->with(
                'status',
                'No executive office in your association chain yet — offices form when a legislature delegates one · F-LEG-014.'
            );
        }

        $segment = self::TARGETS[$sub];
        $path = $segment === '' ? "/executives/{$executiveId}" : "/executives/{$executiveId}/{$segment}";

        $redirect = redirect($path);

        if ($seats->count() > 1) {
            $redirect->with('status', 'You hold a seat in more than one executive — showing one; switch from the office page.');
        }

        return $redirect;
    }

    /** §B.4 — /executive/reporting resolves an R-18 governor to their board's department. */
    private function resolveReporting(User $user): RedirectResponse
    {
        $departmentIds = DB::table('board_seats as bs')
            ->join('departments as d', 'd.board_id', '=', 'bs.board_id')
            ->where('bs.holder_user_id', (string) $user->getKey())
            ->where('bs.status', 'seated')
            ->whereNull('bs.deleted_at')
            ->whereNull('d.deleted_at')
            ->distinct()
            ->pluck('d.id');

        if ($departmentIds->count() === 1) {
            return redirect("/departments/{$departmentIds->first()}/reporting");
        }

        if ($departmentIds->count() > 1) {
            return redirect("/departments/{$departmentIds->first()}/reporting")->with(
                'status',
                'You sit on more than one department board — showing one; pick another from its department page.'
            );
        }

        // Non-governor: fall through to the resolved executive's departments index.
        $executiveId = $this->seatedExecutiveIds($user)->first() ?? $this->publicReadExecutiveId($user);

        if ($executiveId === null) {
            return redirect('/civic')->with(
                'status',
                'No executive office in your association chain yet — F-LEG-014.'
            );
        }

        return redirect("/executives/{$executiveId}/departments");
    }

    /** Executive ids the viewer is a SEATED member of (principal or advisor). */
    private function seatedExecutiveIds(User $user): Collection
    {
        return ExecutiveMember::query()
            ->where('user_id', (string) $user->getKey())
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->distinct()
            ->pluck('executive_id');
    }

    /** The deepest associated jurisdiction's executive (public read), or null. */
    private function publicReadExecutiveId(User $user): ?string
    {
        $id = DB::table('residency_confirmations as rc')
            ->join('executives as e', 'e.jurisdiction_id', '=', 'rc.jurisdiction_id')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', (string) $user->getKey())
            ->where('rc.is_active', true)
            ->whereNull('e.deleted_at')
            ->whereNull('j.deleted_at')
            ->orderByDesc('j.adm_level')
            ->value('e.id');

        return $id !== null ? (string) $id : null;
    }
}
