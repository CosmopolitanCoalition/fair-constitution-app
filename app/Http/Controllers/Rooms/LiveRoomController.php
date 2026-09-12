<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteeSeat;
use App\Models\Legislature;
use App\Models\PublicRecord;
use App\Models\MatrixRoom;
use App\Models\User;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\VoiceReachFailed;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\PublicRoomNames;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Slice 6 — THE LIVE CIVIC ROOM. One page (LiveCivicRoom.vue) renders every
 * meeting type from the MANIFEST §1 config; this controller builds that config
 * from the real domain and lazily provisions the room's Matrix home. The room
 * FUSES the governance form (agenda, the vote tile, the record) with the live
 * social floor (presence, the hands-raised queue, the call, the timeline).
 *
 * Poll-first (store contract c6399aa, desk-ruled): the page mounts useLiveRoom,
 * which re-requests the volatile props on a cadence — server snapshots are the
 * truth. Handles are ALWAYS pseudonymous (@u-<localpart>, never a legal name —
 * MANIFEST rule); the floor is ephemeral (LiveFloorService, cache).
 *
 * This method is the exit-test path: a committee hearing in ONE room.
 */
class LiveRoomController extends Controller
{
    public function __construct(
        private readonly SocialTopologyReconcilerService $rooms,
        private readonly LiveFloorService $floor,
        private readonly ChamberVotePresenter $votes,
        private readonly PublicRoomNames $names,
        private readonly MatrixPostingGateService $posting,
    ) {
    }

    public function committee(Request $request, CommitteeMeeting $meeting): Response
    {
        /** @var Committee $committee */
        $committee = $meeting->committee()->with('legislature.jurisdiction:id,name,slug,parent_id,adm_level')->firstOrFail();
        $legislature = $committee->legislature;
        $jurisdiction = $legislature?->jurisdiction;

        // Lazily provision only the selected meeting on an initial page view.
        // A Matrix hiccup must DEGRADE, never block: the durable civic record —
        // agenda, the vote, the sealed record — is Plane A (Postgres) and never
        // depends on the homeserver being up.
        $matrixRoom = $this->existingMeetingRoom($meeting);
        try {
            if ($matrixRoom === null && $request->isMethod('GET')
                && ! $request->headers->has('X-Inertia-Partial-Data')
                && ! $request->headers->has('X-Inertia-Partial-Component')
                && ! str_contains(strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', '')), 'prefetch')) {
                $matrixRoom = $this->rooms->reconcileCommitteeMeeting($meeting);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        if (! $this->validMeetingRoom($matrixRoom, $meeting)) $matrixRoom = null;
        $chat = [];
        $chatAvailable = false;
        if ($matrixRoom) {
            try {
                $events = app(\App\Services\Matrix\MatrixClientService::class)->getMessages($matrixRoom->matrix_room_id, 'b', null, 30)['chunk'] ?? [];
                foreach (array_reverse(array_slice($events, 0, 30)) as $event) {
                    if (($event['type'] ?? '') !== 'm.room.message') continue;
                    $chat[] = ['event_id' => $event['event_id'], 'sender' => $event['sender'], 'body' => $event['content']['body'] ?? ''];
                }
                $chatAvailable = true;
            } catch (\Throwable $error) { report($error); }
        }

        $seats = CommitteeSeat::query()
            ->where('committee_id', $committee->id)
            ->live()
            ->with('member:id,user_id')
            ->get();

        $viewer = $request->user();
        $viewerMemberId = $this->viewerCommitteeMemberId($committee, $seats, $viewer);
        $isChair = $viewerMemberId !== null
            && (string) $committee->chair_member_id === $viewerMemberId;
        $isAlternate = $viewerMemberId !== null
            && (string) $committee->alternate_member_id === $viewerMemberId;
        $isMember = $viewerMemberId !== null;
        $floorOpen = in_array($meeting->status, [CommitteeMeeting::STATUS_SCHEDULED, CommitteeMeeting::STATUS_OPEN], true)
            && $committee->status !== Committee::STATUS_DISSOLVED
            && $legislature !== null && $legislature->status !== Legislature::STATUS_DISSOLVED;

        $floorKey = $this->floor->key('committee_meeting', (string) $meeting->id);
        $floorState = $this->floor->state($floorKey);
        $presence = $this->presence($seats, $committee, $floorState);
        $displayNames = $this->names->forHandles(array_filter(array_merge(
            array_column($floorState['queue'], 'handle'), [$floorState['floorHolder']], array_column($chat, 'sender')
        )));
        foreach ($presence as $person) {
            if ($person['display_name']) {
                $displayNames[$person['handle']] = $person['display_name'];
            }
        }

        return Inertia::render('Legislature/LiveCivicRoom', [
            'surface'   => \App\Support\SurfaceMeta::for('legislature/committee-detail'),
            'variant'   => 'committee',
            'entity'    => ['type' => 'committee_meeting', 'id' => (string) $meeting->id],
            'title'     => $committee->name.' — hearing',
            'jurisdiction' => $jurisdiction?->name ?? '',
            'jurisdictionContext' => $jurisdiction ? \App\Support\JurisdictionContext::forRoom($jurisdiction) : null,
            'status'    => $this->statusOf($meeting->status),
            'chairRole' => 'chair',
            'chair'     => $this->chairCard($committee, $seats),
            'clocks'    => [
                'agendaItem' => 0,
                'speaking'   => $floorState['speaking']['seconds'] ?? 120,
            ],
            'constitutionalOrder' => false, // a committee is not the chamber — no locked slots
            'agenda'    => $this->agendaItems($meeting),
            'floor'     => [
                'kind'     => 'hearing',
                'title'    => $this->currentAgendaTitle($meeting) ?? 'Committee hearing',
                'body'     => 'The committee hears the matter, takes testimony, and may report to the floor.',
                'form'     => null,
                'citation' => 'Art. II §4',
                'deepLink' => "/committees/{$committee->id}",
            ],
            'vote'      => $this->openCommitteeVote($committee),
            'presence'  => $presence,
            'displayNames' => $displayNames,
            'queue'     => $this->queueRows($floorState),
            'floorHolder' => $floorState['floorHolder'],
            'chat'      => $chat,
            'chatAvailable' => $chatAvailable,
            'voice'     => [
                'enabled' => $matrixRoom !== null,
                'roomId' => $matrixRoom?->matrix_room_id,
                'jurisdictionId' => $jurisdiction?->id,
                'myMxid' => $viewer !== null ? $this->posting->matrixUserId($viewer) : null,
                'myUserId' => $viewer?->id,
            ],
            'translation' => ['from' => 'en', 'to' => 'en', 'isPrivate' => false, 'rail' => 'server-local'],
            'record'    => $this->recordRows($meeting),
            'residencyGated' => true,
            'galleryNote' => 'Anyone may watch a committee hearing (Art. II §2). Residents may raise a hand and testify; the seated members vote.',
            'forms'     => ['F-CHR-001', 'F-CHR-002', 'F-SOC-002'],
            'chairControls' => [
                'Recognize the next speaker',
                'Start the speaking clock',
                'Advance the agenda',
                'Call the committee vote',
            ],
            'can' => [
                'recognize' => $floorOpen && ($isChair || $isAlternate),
                'advance'   => $floorOpen && ($isChair || $isAlternate),
                'raiseHand' => $floorOpen && $viewer !== null,
                'testify'   => $floorOpen && $viewer !== null,
                'cast'      => $floorOpen && $isMember,
                'isGallery' => $viewer === null || ! $isMember,
            ],
            'urls' => [
                'messages' => "/rooms/committee/{$meeting->id}/messages",
                'rooms' => '/rooms?'.http_build_query(['jurisdiction' => $jurisdiction?->slug, 'section' => 'committees']),
                'raiseHand' => "/rooms/committee/{$meeting->id}/raise-hand",
                'recognize' => "/rooms/committee/{$meeting->id}/recognize",
                'advance'   => "/rooms/committee/{$meeting->id}/advance",
                'testify'   => "/meetings/{$meeting->id}/testimony",
                'chamber'   => "/committees/{$committee->id}?".http_build_query(['meeting' => (string) $meeting->id]),
                'commons'   => '/civic/commons/halls?jurisdiction='.$jurisdiction?->id,
            ],
        ]);
    }

    // =========================================================================
    // The live floor — recognition write-path (ephemeral; LiveFloorService)
    // =========================================================================

    /** Informal discussion, distinct from testimony and official records. */
    public function messages(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $room = $this->existingMeetingRoom($meeting);
        abort_unless($this->validMeetingRoom($room, $meeting), 403, 'This hearing discussion is not available.');
        $jid = $meeting->committee()->firstOrFail()->legislature()->value('jurisdiction_id');
        abort_unless($jid, 403, 'This hearing discussion is not available.');
        try {
            app(\App\Services\Matrix\PublicVoiceRoomAccess::class)->assertMayJoin($request->user(), (string) $jid, $room->matrix_room_id);
        } catch (VoiceReachFailed $error) {
            abort(403, 'This hearing discussion is not available.');
        }
        try {
            $identity = app(\App\Services\Matrix\MatrixIdentityProvisioner::class)->ensureFor($request->user());
            app(\App\Services\Matrix\MatrixClientService::class)->sendMessage($room->matrix_room_id,
                ['msgtype' => 'm.text', 'body' => $data['body']], $identity->matrix_user_id);
        } catch (\Throwable $error) {
            report($error);
            return back()->withErrors(['body' => 'The message could not be confirmed. Check the conversation before retrying.']);
        }
        return back()->with('status', 'Message sent to the hearing discussion.');
    }

    private function existingMeetingRoom(CommitteeMeeting $meeting): ?MatrixRoom
    {
        // Retired or malformed mappings must not cause a public GET to create
        // a replacement or change the original room's privacy.
        return MatrixRoom::query()->where('entity_type', MatrixRoom::ENTITY_COMMITTEE_MEETING)
            ->where('entity_id', $meeting->id)->first();
    }

    private function validMeetingRoom(?MatrixRoom $room, CommitteeMeeting $meeting): bool
    {
        return $room !== null && ! $room->trashed() && $room->tombstoned_at === null
            && $room->is_public && ! $room->is_encrypted && ! empty($room->matrix_room_id)
            && $room->room_type === MatrixRoom::ROOM_INSTITUTION && $room->space_type === null
            && $room->entity_type === MatrixRoom::ENTITY_COMMITTEE_MEETING
            && (string) $room->entity_id === (string) $meeting->id;
    }

    /** A player raises or lowers only their own hand; omitted action preserves older clients. */
    public function raiseHand(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        abort_if($request->user() === null, 403);
        $data = $request->validate(['action' => ['sometimes', 'required', 'string', 'in:raise,lower']]);
        $action = $data['action'] ?? 'raise';
        $this->assertFloorOpen($meeting);

        $key = $this->floor->key('committee_meeting', (string) $meeting->id);
        $identity = app(\App\Services\Matrix\MatrixIdentityProvisioner::class)->ensureFor($request->user());
        if ($action === 'lower') {
            $this->floor->lowerHand($key, $identity->matrix_user_id);
        } else {
            $this->floor->raiseHand($key, $identity->matrix_user_id, 'To speak');
        }

        return back()->with('status', $action === 'lower' ? 'Your hand is lowered.' : 'Your hand is raised — the chair recognizes speakers in turn.');
    }

    /** The chair recognizes the next hand (or a named handle) → the floor. */
    public function recognize(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        $this->authorizeChair($request, $meeting);

        $key = $this->floor->key('committee_meeting', (string) $meeting->id);
        $handle = $request->input('handle'); // null → the next hand in the queue (FIFO)
        $state = $this->floor->recognize($key, is_string($handle) ? $handle : null);

        $label = $state['floorHolder'] !== null
            ? ($this->names->forHandles([$state['floorHolder']])[$state['floorHolder']] ?? 'The next speaker')
            : null;

        return back()->with('status', $state['floorHolder'] !== null
            ? "{$label} now holds the floor."
            : 'No hands are raised to recognize.');
    }

    /** The chair yields the floor / moves on — the ephemeral floor resets. */
    public function advance(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        $this->authorizeChair($request, $meeting);

        // The agenda is a plain string list with no per-item status column, so
        // "current" is positional. Real per-item progression needs a structured
        // agenda (a schema question — FLAGGED, not written). For now advancing
        // yields the floor so the next speaker can be recognized.
        $this->floor->yieldFloor($this->floor->key('committee_meeting', (string) $meeting->id));

        return back()->with('status', 'The floor is open — recognize the next speaker.');
    }

    /** Only the committee chair (or its alternate) runs the floor. */
    private function authorizeChair(Request $request, CommitteeMeeting $meeting): void
    {
        $user = $request->user();
        abort_if($user === null, 403);
        $this->assertFloorOpen($meeting);

        $committee = $meeting->committee()->firstOrFail();
        $seats = CommitteeSeat::query()
            ->where('committee_id', $committee->id)
            ->live()
            ->with('member:id,user_id')
            ->get();

        $memberId = $this->viewerCommitteeMemberId($committee, $seats, $user);
        $isChair = $memberId !== null && (string) $committee->chair_member_id === $memberId;
        $isAlternate = $memberId !== null && (string) $committee->alternate_member_id === $memberId;

        abort_unless($isChair || $isAlternate, 403, 'Only the committee chair (or alternate) runs the floor.');
    }

    private function assertFloorOpen(CommitteeMeeting $meeting): void
    {
        $committee = $meeting->committee()->with('legislature:id,status')->first();
        if (! in_array($meeting->status, [CommitteeMeeting::STATUS_SCHEDULED, CommitteeMeeting::STATUS_OPEN], true)
            || $committee === null || $committee->status === Committee::STATUS_DISSOLVED
            || $committee->legislature === null || $committee->legislature->status === Legislature::STATUS_DISSOLVED) {
            throw \Illuminate\Validation\ValidationException::withMessages(['floor' => 'This hearing or institution is closed. Its record and informal discussion remain available.']);
        }
    }

    // =========================================================================
    // Config builders
    // =========================================================================

    private function statusOf(string $status): array
    {
        return match ($status) {
            CommitteeMeeting::STATUS_OPEN => ['state' => 'open', 'label' => 'In session'],
            CommitteeMeeting::STATUS_ADJOURNED => ['state' => 'adjourned', 'label' => 'Adjourned'],
            default => ['state' => 'scheduled', 'label' => 'Scheduled'],
        };
    }

    /** The meeting's plain-string agenda → the structured AgendaStrip item shape. */
    private function agendaItems(CommitteeMeeting $meeting): array
    {
        $agenda = array_values((array) $meeting->agenda);

        return array_map(fn ($title, $i) => [
            'position' => $i + 1,
            'locked'   => false, // a committee has no constitutional lock (Art. II §2 binds the chamber)
            'kind'     => 'other',
            'title'    => is_string($title) ? $title : (string) ($title['title'] ?? 'Item'),
            'subject'  => null,
            'status'   => $i === 0 && $meeting->status === CommitteeMeeting::STATUS_OPEN ? 'in_progress' : 'pending',
        ], $agenda, array_keys($agenda));
    }

    private function currentAgendaTitle(CommitteeMeeting $meeting): ?string
    {
        $agenda = array_values((array) $meeting->agenda);
        $first = $agenda[0] ?? null;

        return is_string($first) ? $first : ($first['title'] ?? null);
    }

    /** The chair's card — pseudonymous, seat-tagged; never a legal name. */
    private function chairCard(Committee $committee, $seats): array
    {
        $seat = $seats->firstWhere('member_id', $committee->chair_member_id);

        return [
            'handle' => $this->pseudonym($seat?->member?->user_id),
            'name'   => null,
            'seat'   => $seat?->seat_kind,
            'persona' => $this->pseudonym($seat?->member?->user_id),
        ];
    }

    /** Presence = the committee's seated members, pseudonymous + seat-tagged. */
    private function presence($seats, Committee $committee, array $floorState): array
    {
        $names = $this->names->forUsers($seats->map(fn ($seat) => $seat->member?->user_id)->filter()->all());

        return $seats->map(function (CommitteeSeat $seat) use ($committee, $floorState, $names) {
            $handle = $this->pseudonym($seat->member?->user_id);

            return [
                'handle'   => $handle,
                'display_name' => $names[(string) $seat->member?->user_id] ?? null,
                'seat'     => $seat->seat_kind,
                'role'     => (string) $committee->chair_member_id === (string) $seat->member_id ? 'chair' : 'member',
                'online'   => false, // liveness is the call's business (LiveKit); the poll refreshes it
                'speaking' => $floorState['floorHolder'] === $handle,
            ];
        })->values()->all();
    }

    private function queueRows(array $floorState): array
    {
        return array_map(fn ($q) => [
            'handle' => $q['handle'],
            'reason' => $q['reason'] ?? null,
        ], $floorState['queue']);
    }

    /**
     * A committee decides by REPORTING/REFERRING (F-CHR-003/004), not by a
     * chamber-scoped ChamberVoteProposal (those are legislature institution
     * acts). So the hearing room is deliberation-only until the referral vote
     * is wired to the VoteTally atom (step 4) — null renders the "no vote yet"
     * state; the atom is proven where the floor session already uses it.
     */
    private function openCommitteeVote(Committee $committee): ?array
    {
        return null;
    }

    /** The sealed record — testimony + reports published from this committee. */
    private function recordRows(CommitteeMeeting $meeting): array
    {
        return PublicRecord::query()
            ->where('subject_type', 'committee_meetings')
            ->where('subject_id', $meeting->id)
            ->orderByDesc('audit_seq')
            ->limit(50)
            ->get(['title', 'audit_seq'])
            ->map(fn (PublicRecord $r) => [
                'handle'    => '@u-record',
                'body'      => $r->title ?? 'Sealed to the record',
                'sealState' => 'recorded',
                'recordHref' => '/system/audit-chain?seq='.(int) $r->audit_seq,
            ])
            ->all();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** A user's pseudonymous @u-<localpart>; never a legal name. Falls back to an anonymous seat token. */
    private function pseudonym(?string $userId): string
    {
        if ($userId === null) {
            return '@u-anon';
        }

        // Use the same identity as text and voice, including before first Matrix login.
        // A second truncated hash here would split one participant into two seats.
        return $this->posting->matrixUserId((new User())->forceFill(['id' => $userId]));
    }

    private function viewerCommitteeMemberId(Committee $committee, $seats, $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $seat = $seats->first(fn (CommitteeSeat $s) => (string) $s->member?->user_id === (string) $user->id);

        return $seat !== null ? (string) $seat->member_id : null;
    }
}
