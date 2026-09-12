<?php

namespace App\Services\Rooms;

use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\CourtCase;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Panel;
use App\Models\PanelJudge;
use App\Models\User;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\MatrixIdentityProvisioner;

/** Live choreography uses actual current seats; demo roles never grant authority. */
class RoomFloorService
{
    public function __construct(
        private readonly LiveFloorService $floor,
        private readonly MatrixPostingGateService $identities,
        private readonly PublicRoomNames $names,
        private readonly BoardRoomAccess $boards,
    ) {}

    public function view(string $kind, string $entityId, ?User $viewer): array
    {
        [$open, $presider] = $this->access($kind, $entityId, $viewer);
        $state = $open ? $this->floor->state($this->key($kind, $entityId)) : [
            'queue' => [], 'floorHolder' => null, 'activeWitness' => null,
        ];
        $handle = $viewer ? $this->identities->matrixUserId($viewer) : null;
        $handles = array_filter(array_merge(array_column($state['queue'], 'handle'), [$state['floorHolder'], $state['activeWitness'] ?? null]));
        $names = $this->names->forHandles($handles);

        return [
            'queue' => array_map(fn ($row) => ['handle' => $row['handle'], 'display_name' => $names[$row['handle']] ?? null], $state['queue']),
            'floorHolder' => $state['floorHolder'], 'activeWitness' => $state['activeWitness'] ?? null,
            'canRequest' => $open && $viewer !== null, 'canPreside' => $presider,
            'myHandRaised' => $handle !== null && in_array($handle, array_column($state['queue'], 'handle'), true),
            'displayNames' => $names,
        ];
    }

    public function act(string $kind, string $entityId, User $actor, string $action, ?string $handle = null): void
    {
        // Reload the exact institution on each action, rather than trust an earlier preview.
        [$open, $presider] = $this->access($kind, $entityId, $actor);
        abort_unless($open, 403, 'This institution is closed for live floor actions.');
        $key = $this->key($kind, $entityId);
        if ($action === 'raise' || $action === 'lower') {
            // First-time participants need the same collision-safe identity and public
            // name lookup as chat/calls; this writes only their local identity row.
            $own = app(MatrixIdentityProvisioner::class)->ensureFor($actor)->matrix_user_id;
            $action === 'raise' ? $this->floor->raiseHand($key, $own) : $this->floor->lowerHand($key, $own);
            return;
        }
        abort_unless($presider, 403, 'Only the current presiding officer can manage this room’s floor.');
        match ($action) {
            'recognize' => $this->floor->recognize($key, $handle),
            'yield' => $this->floor->yieldFloor($key),
            'witness' => $kind === 'court' && $handle !== null
                ? $this->floor->recognizeWitness($key, $handle)
                : abort(422, 'Choose a waiting participant in this court to place on the witness stand.'),
            default => abort(422, 'Unknown floor action.'),
        };
    }

    private function key(string $kind, string $entityId): string
    {
        return $this->floor->key($kind === 'court' ? 'case' : $kind, $entityId);
    }

    /** @return array{bool,bool} Open for requests, viewer is the actual current presider. */
    private function access(string $kind, string $id, ?User $viewer): array
    {
        if ($kind === 'legislature') {
            $legislature = Legislature::query()->findOrFail($id, ['id', 'speaker_id', 'status']);
            $open = $legislature->status !== Legislature::STATUS_DISSOLVED;
            $presider = $open && $viewer !== null && $legislature->speaker_id !== null
                && LegislatureMember::query()->where('legislature_id', $id)->whereKey($legislature->speaker_id)
                    ->current()->where('user_id', $viewer->getKey())->exists();
            return [$open, $presider];
        }
        if ($kind === 'court') {
            $case = CourtCase::query()->findOrFail($id, ['id', 'status']);
            $open = ! in_array($case->status, CourtCase::TERMINAL_STATUSES, true);
            $presider = $open && $viewer !== null && PanelJudge::query()
                ->whereHas('panel', fn ($query) => $query->where('case_id', $id)->where('status', Panel::STATUS_SEATED))
                ->where('user_id', $viewer->getKey())->where('is_presiding', true)
                ->where('status', PanelJudge::STATUS_SEATED)->where('screening_result', PanelJudge::SCREENING_CLEARED)->exists();
            return [$open, $presider];
        }
        if ($kind === 'board') {
            $board = Board::query()->findOrFail($id, ['id', 'chair_seat_id', 'status']);
            $this->boards->assertMayJoin($viewer, $board);
            $presider = $board->chair_seat_id !== null && BoardSeat::query()->where('board_id', $id)
                ->whereKey($board->chair_seat_id)->seated()->where('holder_user_id', $viewer->getKey())->exists();
            return [true, $presider];
        }
        abort(404);
    }
}
