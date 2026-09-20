<?php

namespace App\Services\Demo;

use App\Models\Board;
use App\Models\ChamberVote;
use App\Services\ChamberVoteService;
use App\Services\Organizations\OrgBoardService;
use Illuminate\Support\Facades\DB;

/** Synthetic Step 5 input to the real full-board ranked chair election. */
class SimChairService
{
    public function complete(string $boardId): array
    {
        return DB::transaction(function () use ($boardId): array {
            $board = Board::query()->whereKey($boardId)->lockForUpdate()->first();
            if ($board === null || $board->status === Board::STATUS_DISSOLVED) {
                return ['status' => 'blocked', 'reason' => 'board missing or dissolved'];
            }
            $seats = $board->seats()->seated()->orderBy('id')->get();
            if ($board->chair_seat_id !== null) {
                return $seats->contains('id', $board->chair_seat_id)
                    ? ['status' => 'done', 'chair_seat_id' => $board->chair_seat_id, 'existing' => true]
                    : ['status' => 'blocked', 'reason' => 'chair is not a current seated member'];
            }
            if ($seats->count() < 2 || $seats->contains(fn ($seat) => $seat->holder_user_id === null)) {
                return ['status' => 'blocked', 'reason' => 'chair election needs at least two seated holders'];
            }
            $vote = ChamberVote::query()->where('body_type', ChamberVote::BODY_BOARD)
                ->where('body_id', $board->id)->where('vote_type', 'board_chair_elect')
                ->orderByDesc('opened_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($vote !== null && $vote->status !== ChamberVote::STATUS_OPEN) {
                return ['status' => 'blocked', 'reason' => 'previous chair vote closed without a current chair; inspect its outcome', 'vote_id' => $vote->id];
            }
            $vote ??= app(OrgBoardService::class)->openChairElection($board);
            if ((int) $vote->serving_snapshot !== $seats->count()) {
                return ['status' => 'blocked', 'reason' => 'chair ballot electorate changed', 'vote_id' => $vote->id];
            }
            $rankings = $seats->pluck('id')->map(fn ($id) => (string) $id)->all();
            app(ChamberVoteService::class)->castRemainingBoardRankings($vote, $rankings,
                'Simulated Step 5 full-board chair ballot.', 'F-ORG-010');
            $board->refresh(); $vote->refresh();
            return ['status' => $board->chair_seat_id !== null && $vote->outcome === ChamberVote::OUTCOME_ADOPTED ? 'done' : 'blocked',
                'vote_id' => (string) $vote->id, 'chair_seat_id' => $board->chair_seat_id,
                'outcome' => $vote->outcome, 'reason' => $board->chair_seat_id === null ? 'chair ballot did not adopt' : null];
        });
    }
}
