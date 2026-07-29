<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Http\Middleware\DevTimeControlsEnabled;
use App\Models\ChamberVote;
use App\Models\ClockTimer;
use App\Services\Dev\DemoMeshTimeCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The Demo flyout's one state read (V3_SYNTHESIS_PLAN.md §3, D2/D3).
 *
 * Everything the playtest panels need to render, in one GET:
 *   · whether the time/role controls may run, and when not, the refusal
 *     sentence VERBATIM — the server is the truth, the UI never
 *     second-guesses it (D2's explicit contract)
 *   · the soonest armed timers, so "fire one timer" is a pick, not a
 *     uuid hunt (the same 50-soonest list `dev:clock-fire` prints)
 *   · the open chamber votes a bloc cast could ballot, with their live
 *     per-lane tallies read straight off the engine's own snapshots
 *
 * Deliberately registered OUTSIDE DevTimeControlsEnabled (but inside the
 * dev toolbox gate): this route only READS, and it must answer precisely
 * when the controls refuse — otherwise the UI could never show why. The
 * armed/open lists are withheld until the gate opens, so the read
 * exposes nothing the /system/clocks page does not already show.
 */
class PlaytestStateController extends Controller
{
    public function __invoke(DemoMeshTimeCoordinator $mesh): JsonResponse
    {
        $reason = DevTimeControlsEnabled::refusalReason();

        return response()->json([
            'enabled' => $reason === null,
            'reason' => $reason,
            'armed' => $reason === null ? $this->armedTimers() : [],
            'open_votes' => $reason === null ? $this->openVotes() : [],
            // The demo-mesh coordination state (role, coordinator, and — for a
            // follower — the verbatim local-advance refusal). Withheld with the
            // lists when the base gate refuses; the refusal sentence covers that
            // case, and the mesh state means nothing on a node that may not
            // time-travel at all.
            'mesh' => $reason === null ? $mesh->status() : null,
        ]);
    }

    /**
     * What is waiting, soonest first — the same list `dev:clock-fire`
     * prints, shaped for a picker.
     *
     * @return list<array<string,mixed>>
     */
    private function armedTimers(): array
    {
        return DB::table('clock_timers as ct')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 'ct.jurisdiction_id')
            ->where('ct.state', ClockTimer::STATE_ARMED)
            ->whereNull('ct.deleted_at')
            ->orderBy('ct.fires_at')
            ->limit(50)
            ->get(['ct.id', 'ct.clock_id', 'ct.fires_at', 'ct.subject_type', 'j.name as jurisdiction'])
            ->map(static fn ($r): array => [
                'id' => (string) $r->id,
                'clock_id' => $r->clock_id,
                'jurisdiction' => $r->jurisdiction,
                'subject_type' => $r->subject_type ? class_basename($r->subject_type) : null,
                'fires_at' => $r->fires_at,
            ])
            ->all();
    }

    /**
     * Open chamber votes, newest first, with the engine's own per-lane
     * tally snapshots — a pure read of what ChamberVoteService wrote,
     * never a recomputation (the ChamberVotePresenter discipline).
     *
     * @return list<array<string,mixed>>
     */
    private function openVotes(): array
    {
        // chamber_votes carries NO deleted_at — closed/void rows are the only
        // terminal states and corrections are new votes (the CLAUDE.md
        // soft-delete warning: a convention, not a guarantee).
        $votes = DB::table('chamber_votes as cv')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 'cv.jurisdiction_id')
            ->where('cv.status', ChamberVote::STATUS_OPEN)
            ->orderByDesc('cv.opened_at')
            ->limit(50)
            ->get([
                'cv.id', 'cv.vote_type', 'cv.stage', 'cv.bicameral', 'cv.serving_snapshot',
                'cv.opened_at', 'cv.closes_at', 'j.name as jurisdiction',
            ]);

        if ($votes->isEmpty()) {
            return [];
        }

        $lanes = DB::table('chamber_vote_tallies')
            ->whereIn('vote_id', $votes->pluck('id'))
            ->get(['vote_id', 'lane', 'serving', 'quorum_required', 'required_yes', 'yes', 'no', 'abstain', 'present'])
            ->groupBy('vote_id');

        return $votes->map(static fn ($v): array => [
            'id' => (string) $v->id,
            'vote_type' => $v->vote_type,
            'stage' => $v->stage,
            'bicameral' => (bool) $v->bicameral,
            'serving' => (int) $v->serving_snapshot,
            'jurisdiction' => $v->jurisdiction,
            'opened_at' => $v->opened_at,
            'closes_at' => $v->closes_at,
            'lanes' => ($lanes[$v->id] ?? collect())->map(static fn ($t): array => [
                'lane' => $t->lane,
                'serving' => (int) $t->serving,
                'quorum_required' => (int) $t->quorum_required,
                'required_yes' => (int) $t->required_yes,
                'yes' => (int) $t->yes,
                'no' => (int) $t->no,
                'abstain' => (int) $t->abstain,
                'present' => (int) $t->present,
            ])->values()->all(),
        ])->all();
    }
}
