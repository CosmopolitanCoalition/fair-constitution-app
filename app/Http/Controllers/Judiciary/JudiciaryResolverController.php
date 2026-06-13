<?php

namespace App\Http\Controllers\Judiciary;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FE-E0 — the /judiciary[/{sub}] resolver (PHASE_E_DESIGN_frontend.md §B,
 * mirroring Chamber/Executive resolvers). The nav's literal hrefs stay
 * stable while the canonical surfaces are judiciary-scoped:
 *
 *   GET /judiciary[/{sub}] →
 *     '' / 'docket'  → the viewer's court (seated judge), else the DEEPEST
 *        associated jurisdiction's court (public read — dockets/opinions are
 *        public record, Art. II §2); none in the chain → /civic with a note.
 *     'challenges'   → the global constitutional-challenges index (any
 *        inhabitant may file F-IND-016 — Art. IV §5, not court-scoped).
 *     'jury'         → the viewer's active jury summons (R-22), else a note.
 *
 * /judiciary/advocate is a DIRECT per-viewer route (AdvocateController),
 * registered before this resolver — not resolved here.
 */
class JudiciaryResolverController extends Controller
{
    private const TARGETS = ['', 'docket', 'challenges', 'jury'];

    public function __invoke(Request $request, string $sub = ''): RedirectResponse
    {
        abort_unless(in_array($sub, self::TARGETS, true), 404);

        $user = $request->user();

        if ($sub === 'challenges') {
            return redirect('/constitutional-challenges');
        }

        if ($sub === 'jury') {
            return $this->resolveJury($user);
        }

        $judiciaryId = $this->seatedJudiciaryIds($user)->first() ?? $this->publicReadJudiciaryId($user);

        if ($judiciaryId === null) {
            return redirect('/civic')->with(
                'status',
                'No court in your association chain yet — judiciaries form when a legislature creates one · F-LEG-017.'
            );
        }

        $segment = $sub === 'docket' ? '/docket' : '';
        $redirect = redirect("/judiciaries/{$judiciaryId}{$segment}");

        if ($this->seatedJudiciaryIds($user)->count() > 1) {
            $redirect->with('status', 'You sit on more than one court — showing one; switch from the court page.');
        }

        return $redirect;
    }

    /** The viewer's active jury summons (a jury_members row), or a note. */
    private function resolveJury(User $user): RedirectResponse
    {
        $summonsId = DB::table('jury_members')
            ->where('user_id', (string) $user->getKey())
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['excused', 'dismissed', 'completed'])
            ->orderByDesc('created_at')
            ->value('id');

        if ($summonsId !== null) {
            return redirect("/judiciary/jury/{$summonsId}");
        }

        return redirect('/judiciary/docket')->with(
            'status',
            'You have no active jury summons — jurors are drawn per case from the residency pool (F-JDG-002).'
        );
    }

    /** Judiciary ids the viewer holds a SEATED judicial seat on. */
    private function seatedJudiciaryIds(User $user): Collection
    {
        return DB::table('judicial_seats')
            ->where('user_id', (string) $user->getKey())
            ->where('status', 'seated')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('judiciary_id');
    }

    /** The deepest associated jurisdiction's (non-forming) court, or null. */
    private function publicReadJudiciaryId(User $user): ?string
    {
        $id = DB::table('residency_confirmations as rc')
            ->join('judiciaries as jd', 'jd.jurisdiction_id', '=', 'rc.jurisdiction_id')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', (string) $user->getKey())
            ->where('rc.is_active', true)
            ->whereNull('jd.deleted_at')
            ->whereNull('j.deleted_at')
            ->where('jd.status', '!=', 'forming')
            ->orderByDesc('j.adm_level')
            ->value('jd.id');

        return $id !== null ? (string) $id : null;
    }
}
