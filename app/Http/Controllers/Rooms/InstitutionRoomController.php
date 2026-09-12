<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\Advocate;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\Jurisdiction;
use App\Models\JuryMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MatrixRoom;
use App\Models\PanelJudge;
use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixIdentityProvisioner;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\PublicRoomNames;
use App\Support\JurisdictionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Media and seating for an existing institution; governance actions remain in its record workspace. */
class InstitutionRoomController extends Controller
{
    private const ROSTER_LIMIT = 100;

    public function __construct(
        private readonly SocialTopologyReconcilerService $rooms,
        private readonly MatrixPostingGateService $posting,
        private readonly MatrixClientService $matrix,
        private readonly PublicRoomNames $names,
        private readonly LiveFloorService $floor,
        private readonly BoardRoomAccess $boards,
        private readonly PublicVoiceRoomAccess $voiceAccess,
    ) {}

    public function chamber(Request $request, Legislature $legislature): Response
    {
        $members = LegislatureMember::query()->where('legislature_id', $legislature->id)->current()
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$legislature->speaker_id])
            ->orderBy('id')->limit(self::ROSTER_LIMIT + 1)->get(['id', 'user_id', 'seat_no']);
        $rows = $members->take(self::ROSTER_LIMIT)->map(fn ($member) => [
            'user_id' => (string) $member->user_id,
            'role' => (string) $member->id === (string) $legislature->speaker_id ? 'speaker' : 'legislator',
            'seat' => $member->seat_no,
        ])->all();
        $room = $this->room($request, MatrixRoom::ENTITY_LEGISLATURE, $legislature->id, true, fn () => $this->rooms->reconcileLegislature($legislature));

        return $this->page($request, 'legislature', 'Legislative chamber', $legislature->jurisdiction_id,
            $legislature->id, $room, $rows, $members->count() > self::ROSTER_LIMIT,
            '/legislatures/'.$legislature->id.'/session');
    }

    public function court(Request $request, CourtCase $case): Response
    {
        $judges = PanelJudge::query()->whereHas('panel', fn ($q) => $q->where('case_id', $case->id))
            ->where('status', PanelJudge::STATUS_SEATED)->where('screening_result', PanelJudge::SCREENING_CLEARED)
            ->orderByDesc('is_presiding')->orderBy('id')->limit(self::ROSTER_LIMIT + 1)
            ->get(['user_id', 'is_presiding']);
        $rows = $judges->map(fn ($judge) => ['user_id' => (string) $judge->user_id,
            'role' => $judge->is_presiding ? 'presiding_judge' : 'judge'])->all();
        $parties = CaseParty::query()->where('case_id', $case->id)->where('status', CaseParty::STATUS_ACTIVE)
            ->orderBy('id')->limit(self::ROSTER_LIMIT + 1)->get(['party_user_id', 'party_role', 'represented_by_advocate_id']);
        foreach ($parties as $party) {
            if ($party->party_user_id) $rows[] = ['user_id' => (string) $party->party_user_id, 'role' => match ($party->party_role) {
                'prosecution' => 'prosecutor', 'defendant', 'accused' => 'defense', 'plaintiff' => 'claimant', default => 'respondent',
            }];
        }
        $advocateIds = $parties->pluck('represented_by_advocate_id')->filter()->push($case->advocate_id)->filter()->unique();
        if ($advocateIds->isNotEmpty()) {
            foreach (Advocate::query()->whereIn('id', $advocateIds)->registered()->get(['user_id']) as $advocate) {
                $rows[] = ['user_id' => (string) $advocate->user_id, 'role' => 'advocate'];
            }
        }
        $jurors = JuryMember::query()->whereHas('jury', fn ($q) => $q->where('case_id', $case->id)->where('status', '!=', 'discharged'))
            ->where('screening_status', JuryMember::SCREENING_EMPANELED)->orderBy('seat_no')->orderBy('id')
            ->limit(self::ROSTER_LIMIT + 1)->get(['user_id', 'seat_no']);
        foreach ($jurors as $juror) $rows[] = ['user_id' => (string) $juror->user_id, 'role' => 'juror', 'seat' => $juror->seat_no];
        $truncated = count($rows) > self::ROSTER_LIMIT;
        $room = $this->room($request, MatrixRoom::ENTITY_CASE, $case->id, true, fn () => $this->rooms->reconcileCase($case));

        return $this->page($request, 'court', (string) $case->title, $case->jurisdiction_id,
            $case->id, $room, array_slice($rows, 0, self::ROSTER_LIMIT), $truncated, '/cases/'.$case->id);
    }

    public function board(Request $request, Board $board): Response
    {
        $this->boards->assertMayJoin($request->user(), $board);
        $seats = BoardSeat::query()->where('board_id', $board->id)->seated()->whereNotNull('holder_user_id')
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$board->chair_seat_id])
            ->orderBy('seat_no')->orderBy('id')->limit(self::ROSTER_LIMIT + 1)->get(['id', 'holder_user_id', 'seat_no']);
        $rows = $seats->take(self::ROSTER_LIMIT)->map(fn ($seat) => [
            'user_id' => (string) $seat->holder_user_id,
            'role' => (string) $seat->id === (string) $board->chair_seat_id ? 'chair' : 'board_member', 'seat' => $seat->seat_no,
        ])->all();
        $room = $this->room($request, MatrixRoom::ENTITY_BOARD, $board->id, false, fn () => $this->rooms->reconcileBoard($board));
        $record = $board->boardable_type === Board::BOARDABLE_ORGANIZATIONS
            ? '/organizations/'.$board->boardable_id.'/board-elections' : '/departments/'.$board->boardable_id;

        return $this->page($request, 'board', 'Board meeting', $board->jurisdictionId(), $board->id, $room,
            $rows, $seats->count() > self::ROSTER_LIMIT, $record, '/rooms/board/'.$board->id.'/call-token');
    }

    public function boardToken(Request $request, Board $board, LiveKitTokenService $tokens): JsonResponse
    {
        $this->boards->assertMayJoin($request->user(), $board);
        // The URL selects the board. A supplied room ID can never substitute another room.
        $room = $this->existingRoom(MatrixRoom::ENTITY_BOARD, (string) $board->id);
        abort_unless($this->validRoom($room, false), 403, 'This board call is not available.');
        $identity = app(MatrixIdentityProvisioner::class)->ensureFor($request->user())->matrix_user_id;
        $minted = $tokens->mintAccessToken($identity, $room->matrix_room_id);
        return response()->json(array_merge($minted, ['sfu_url' => $minted['url']]));
    }

    public function chamberMessages(Request $request, Legislature $legislature): RedirectResponse
    {
        return $this->postDiscussion($request, MatrixRoom::ENTITY_LEGISLATURE, $legislature->id, $legislature->jurisdiction_id);
    }

    public function courtMessages(Request $request, CourtCase $case): RedirectResponse
    {
        return $this->postDiscussion($request, MatrixRoom::ENTITY_CASE, $case->id, $case->jurisdiction_id);
    }

    public function boardMessages(Request $request, Board $board): RedirectResponse
    {
        $this->boards->assertMayJoin($request->user(), $board);
        return $this->postDiscussion($request, MatrixRoom::ENTITY_BOARD, $board->id, null, true);
    }

    private function postDiscussion(Request $request, string $type, string $id, ?string $jurisdictionId, bool $private = false): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $room = $this->existingRoom($type, $id);
        abort_unless($this->validRoom($room, ! $private), 403, 'This room discussion is not available.');
        if (! $private) {
            abort_unless($jurisdictionId, 403);
            try { $this->voiceAccess->assertMayJoin($request->user(), $jurisdictionId, $room->matrix_room_id); }
            catch (\App\Services\Matrix\VoiceReachFailed) { abort(403, 'This room discussion is not available.'); }
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        try {
            $identity = app(MatrixIdentityProvisioner::class)->ensureFor($request->user())->matrix_user_id;
            $this->matrix->sendMessage($room->matrix_room_id, ['msgtype' => 'm.text', 'body' => $data['body']], $identity);
        } catch (\Throwable $error) {
            report($error);
            return back()->withErrors(['body' => 'The discussion message could not be sent. Please try again.']);
        }
        return back();
    }

    private function existingRoom(string $type, string $id): ?MatrixRoom
    {
        return MatrixRoom::query()->where('entity_type', $type)->where('entity_id', $id)
            ->whereNull('space_type')->whereNull('tombstoned_at')->whereNotNull('matrix_room_id')->first();
    }

    private function validRoom(?MatrixRoom $room, bool $public): bool
    {
        return $room !== null && ! $room->trashed() && $room->tombstoned_at === null
            && ! empty($room->matrix_room_id) && ! $room->is_encrypted && (bool) $room->is_public === $public
            && $room->room_type === ($public ? MatrixRoom::ROOM_INSTITUTION : MatrixRoom::ROOM_ORG_PRIVATE);
    }

    private function room(Request $request, string $type, string $id, bool $public, callable $provision): ?MatrixRoom
    {
        $room = $this->existingRoom($type, $id);
        $isRefresh = $request->headers->has('X-Inertia-Partial-Data')
            || $request->headers->has('X-Inertia-Partial-Component');
        $isPrefetch = str_contains(strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', '')), 'prefetch');
        // Automatic refreshes, previews, and HEAD requests must remain reads during an outage.
        if ($room === null && $request->isMethod('GET') && ! $isRefresh && ! $isPrefetch) {
            try { $room = $provision(); } catch (\Throwable $error) { report($error); }
        }
        return $this->validRoom($room, $public) && $room->entity_type === $type && (string) $room->entity_id === $id ? $room : null;
    }

    private function page(Request $request, string $variant, string $title, ?string $jurisdictionId, string $entityId,
        ?MatrixRoom $room, array $rows, bool $truncated, string $record, ?string $tokenUrl = null): Response
    {
        $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
        $identities = $this->posting->matrixUserIdsFor($userIds);
        $names = $this->names->forUsers($userIds);
        $roster = [];
        foreach ($rows as $row) {
            $id = $row['user_id'];
            if (! isset($identities[$id]) || isset($roster[$identities[$id]])) continue;
            $roster[$identities[$id]] = ['handle' => $identities[$id], 'display_name' => $names[$id] ?? null,
                'role' => $row['role'], 'seat' => $row['seat'] ?? null];
        }
        $timeline = [];
        $timelineAvailable = false;
        if ($room !== null) {
            try {
                $events = $this->matrix->getMessages($room->matrix_room_id, 'b', null, 30)['chunk'] ?? [];
                foreach (array_reverse(array_slice($events, 0, 30)) as $event) {
                    $content = $event['content'] ?? [];
                    if (($event['type'] ?? '') !== 'm.room.message' || ! in_array($content['msgtype'] ?? '', ['m.text', 'm.notice', 'm.emote'], true)) continue;
                    $timeline[] = ['id' => (string) ($event['event_id'] ?? ''), 'sender' => (string) ($event['sender'] ?? ''),
                        'body' => (string) ($content['body'] ?? ''), 'at' => $event['origin_server_ts'] ?? null];
                }
                $timelineAvailable = true;
            } catch (\Throwable $error) { report($error); }
        }
        $displayNames = $this->names->forHandles(array_column($timeline, 'sender'));
        foreach ($roster as $handle => $person) if ($person['display_name']) $displayNames[$handle] = $person['display_name'];
        $place = $jurisdictionId ? Jurisdiction::query()->find($jurisdictionId, ['id', 'name', 'slug', 'adm_level', 'parent_id']) : null;
        $floor = $this->floor->state($this->floor->key($variant === 'court' ? 'case' : $variant, $entityId));
        $roomHref = '/rooms/'.($variant === 'legislature' ? 'chamber' : $variant).'/'.$entityId;
        return Inertia::render('Rooms/Institution', [
            'title' => $title, 'variant' => $variant, 'private' => $tokenUrl !== null,
            'jurisdiction' => $place?->only(['id', 'name', 'slug']),
            'jurisdictionContext' => $place ? JurisdictionContext::forRoom($place) : null,
            'roster' => array_values($roster), 'rosterTruncated' => $truncated, 'rosterLimit' => self::ROSTER_LIMIT,
            'displayNames' => $displayNames, 'floorHolder' => $floor['floorHolder'],
            'messages' => $timeline, 'timelineAvailable' => $timelineAvailable,
            'voice' => ['roomId' => $room?->matrix_room_id, 'jurisdictionId' => $jurisdictionId,
                'myMxid' => $request->user() ? $this->posting->matrixUserId($request->user()) : null,
                'myUserId' => $request->user()?->getKey(), 'tokenUrl' => $tokenUrl],
            'recordHref' => $record,
            'roomHref' => $roomHref, 'messagesHref' => $roomHref.'/messages',
        ]);
    }
}
