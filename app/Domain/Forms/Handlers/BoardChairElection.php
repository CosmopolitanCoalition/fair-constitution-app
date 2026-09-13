<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChamberVoteService;
use App\Services\Organizations\OrgBoardService;
use Illuminate\Support\Str;

/** F-ORG-010: the member's door into the existing WF-ORG-05 joint chair vote. */
class BoardChairElection implements FormHandler
{
    public function __construct(
        private readonly OrgBoardService $boards,
        private readonly ChamberVoteService $votes,
    ) {}

    public function module(): string { return 'organizations'; }
    public function event(): string { return 'board.chair.participation'; }
    // Like restructuring, authority is local to the selected body. Personhood
    // plus the current seat check covers owner, worker and CGC governor alike.
    public function requiredRoles(): array { return ['R-01']; }
    public function systemOnly(): bool { return false; }

    public function handle(?User $actor, array $payload): array
    {
        $organizationId = $payload['organization_id'] ?? null;
        $this->require(is_string($organizationId) && Str::isUuid($organizationId), 'Select an organization.');
        $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
        $this->require($organization !== null && $organization->status === Organization::STATUS_ACTIVE,
            'The organization must be active.');
        $board = Board::query()->whereKey($organization->board_id)->lockForUpdate()->first();
        $this->require($board !== null && $board->status !== Board::STATUS_DISSOLVED
            && $board->boardable_type === Board::BOARDABLE_ORGANIZATIONS
            && (string) $board->boardable_id === (string) $organization->id,
            'Select the organization’s current board.');
        $action = $payload['action'] ?? null;
        $actorSeats = $actor === null ? collect() : $board->seats()->seated()
            ->where('holder_user_id', $actor->getKey())->lockForUpdate()->get()->keyBy('id');
        $seatId = $payload['board_seat_id'] ?? null;
        $seat = $action === 'cast' && $seatId !== null
            ? (is_string($seatId) ? $actorSeats->get($seatId) : null)
            : ($action !== 'cast' || $actorSeats->count() === 1 ? $actorSeats->first() : null);
        $this->require($seat !== null, 'Only a currently seated member of this board may participate.');
        $this->require($board->chair_seat_id === null, 'This board already has an elected chair.');

        $this->require(in_array($action, ['open', 'cast'], true), 'Choose whether to open a ballot or submit a ranking.');
        $vote = ChamberVote::query()->where('body_type', ChamberVote::BODY_BOARD)
            ->where('body_id', $board->id)->where('vote_type', 'board_chair_elect')
            ->orderByDesc('opened_at')->orderByDesc('id')->lockForUpdate()->first();

        if ($action === 'open') {
            // Opening is a cure, never a recall or a way to discard an ongoing
            // ballot. Any seated member may resume an unfilled chair election.
            if ($vote?->status !== ChamberVote::STATUS_OPEN) $vote = $this->boards->openChairElection($board);
            return ['action' => 'open', 'organization_id' => (string) $organization->id,
                'board_id' => (string) $board->id, 'vote_id' => (string) $vote->id];
        }

        $this->require($vote !== null && $vote->status === ChamberVote::STATUS_OPEN
            && (string) $vote->id === ($payload['vote_id'] ?? null),
            'This ballot has been replaced or closed. Refresh the page for the current ballot.');
        $rankings = $payload['rankings'] ?? null;
        $candidates = $board->seats()->seated()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->require(is_array($rankings) && array_is_list($rankings) && count($rankings) > 0
            && count($rankings) <= count($candidates), 'Rank one or more seated members.');
        $seen = [];
        foreach ($rankings as $id) {
            $this->require(is_string($id) && in_array($id, $candidates, true) && ! isset($seen[$id]),
                'Rank each selected member once, using the current board roster.');
            $seen[$id] = true;
        }
        $explanation = $payload['explanation'] ?? null;
        $this->require($explanation === null || (is_string($explanation) && mb_strlen($explanation) <= 2000),
            'Keep the explanation to 2,000 characters.');
        $cast = $this->votes->castBoardSeat($vote, $seat, null, $rankings, $explanation, 'F-ORG-010');
        $vote->refresh();

        return ['action' => 'cast', 'organization_id' => (string) $organization->id,
            'board_id' => (string) $board->id, 'vote_id' => (string) $vote->id,
            'board_seat_id' => (string) $seat->id, 'public_rankings' => $cast->rankings,
            'public_record_id' => (string) $cast->public_record_id,
            'vote_status' => $vote->status, 'vote_outcome' => $vote->outcome];
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new ConstitutionalViolation($message, 'Art. III §6');
    }
}
