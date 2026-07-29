<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteeSeat;
use App\Models\MatrixIdentity;
use App\Models\PublicRecord;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Rooms\LiveFloorService;
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
    ) {
    }

    public function committee(Request $request, CommitteeMeeting $meeting): Response
    {
        /** @var Committee $committee */
        $committee = $meeting->committee()->with('legislature.jurisdiction:id,name')->firstOrFail();
        $legislature = $committee->legislature;
        $jurisdiction = $legislature?->jurisdiction;

        // Lazily provision the room's Matrix home (idempotent — safe on every view).
        // A Matrix hiccup must DEGRADE, never block: the durable civic record —
        // agenda, the vote, the sealed record — is Plane A (Postgres) and never
        // depends on the homeserver being up.
        try {
            $this->rooms->reconcileCommitteeMeeting($meeting);
        } catch (\Throwable $e) {
            report($e);
        }

        $seats = CommitteeSeat::query()
            ->where('committee_id', $committee->id)
            ->live()
            ->with('member.user:id,name,display_name')
            ->get();

        $viewer = $request->user();
        $viewerMemberId = $this->viewerCommitteeMemberId($committee, $seats, $viewer);
        $isChair = $viewerMemberId !== null
            && (string) $committee->chair_member_id === $viewerMemberId;
        $isAlternate = $viewerMemberId !== null
            && (string) $committee->alternate_member_id === $viewerMemberId;
        $isMember = $viewerMemberId !== null;

        $floorKey = $this->floor->key('committee_meeting', (string) $meeting->id);
        $floorState = $this->floor->state($floorKey);

        return Inertia::render('Legislature/LiveCivicRoom', [
            'surface'   => \App\Support\SurfaceMeta::for('legislature/committee-detail'),
            'variant'   => 'committee',
            'entity'    => ['type' => 'committee_meeting', 'id' => (string) $meeting->id],
            'title'     => $committee->name.' — hearing',
            'jurisdiction' => $jurisdiction?->name ?? '',
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
            'presence'  => $this->presence($seats, $committee, $floorState),
            'queue'     => $this->queueRows($floorState),
            'floorHolder' => $floorState['floorHolder'],
            'chat'      => [], // the live Matrix timeline reads in step 4 (empty on a fresh room); useLiveRoom refreshes it
            'voice'     => ['enabled' => true, 'participants' => [], 'residencyGated' => true],
            'translation' => ['from' => 'en', 'to' => 'en', 'isPrivate' => false, 'rail' => 'server-local'],
            'record'    => $this->recordRows($committee),
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
                'recognize' => $isChair || $isAlternate,
                'advance'   => $isChair || $isAlternate,
                'raiseHand' => $viewer !== null,
                'testify'   => $viewer !== null,
                'cast'      => $isMember,
                'isGallery' => $viewer === null || ! $isMember,
            ],
            'urls' => [
                'raiseHand' => "/rooms/committee/{$meeting->id}/raise-hand",
                'recognize' => "/rooms/committee/{$meeting->id}/recognize",
                'advance'   => "/rooms/committee/{$meeting->id}/advance",
                'testify'   => "/committees/{$committee->id}/reports",
                'chamber'   => "/committees/{$committee->id}",
            ],
        ]);
    }

    // =========================================================================
    // The live floor — recognition write-path (ephemeral; LiveFloorService)
    // =========================================================================

    /** A resident raises a hand to speak (any authenticated player — Art. I). */
    public function raiseHand(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        abort_if($request->user() === null, 403);

        $key = $this->floor->key('committee_meeting', (string) $meeting->id);
        $this->floor->raiseHand($key, $this->pseudonym((string) $request->user()->id), 'To speak');

        return back()->with('status', 'Your hand is raised — the chair recognizes speakers in turn.');
    }

    /** The chair recognizes the next hand (or a named handle) → the floor. */
    public function recognize(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        $this->authorizeChair($request, $meeting);

        $key = $this->floor->key('committee_meeting', (string) $meeting->id);
        $handle = $request->input('handle'); // null → the next hand in the queue (FIFO)
        $state = $this->floor->recognize($key, is_string($handle) ? $handle : null);

        return back()->with('status', $state['floorHolder'] !== null
            ? "{$state['floorHolder']} now holds the floor."
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
        return $seats->map(function (CommitteeSeat $seat) use ($committee, $floorState) {
            $handle = $this->pseudonym($seat->member?->user_id);

            return [
                'handle'   => $handle,
                'name'     => null, // pseudonymous by construction (MANIFEST) — never a legal name
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
    private function recordRows(Committee $committee): array
    {
        return PublicRecord::query()
            ->where('subject_type', 'committee_meetings')
            ->whereIn('subject_id', CommitteeMeeting::query()->where('committee_id', $committee->id)->pluck('id'))
            ->orderByDesc('audit_seq')
            ->limit(50)
            ->get()
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

        $identity = MatrixIdentity::query()->where('user_id', $userId)->first();
        if ($identity !== null && $identity->matrix_user_id !== null) {
            return (string) $identity->matrix_user_id;
        }
        if ($identity !== null && $identity->matrix_localpart !== null) {
            return '@'.$identity->matrix_localpart;
        }

        // No Matrix identity yet — a STABLE anonymous token derived from the id,
        // never the legal name (MANIFEST: handles are pseudonymous by construction).
        return '@u-'.substr(hash('sha256', $userId), 0, 8);
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
