<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Models\SocialMembership;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Social\PrivateRoomService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * User-owned PRIVATE ROOMS (groups / DMs) — the "Art. I private half". A player creates a private
 * room, invites friends (Invite kind=space → membership on redeem), and they text + call inside. OFF
 * the civic plane: MEMBER-gated (never residency), no testimony, no public record, no governance. A
 * non-member never sees the room. A down homeserver degrades the timeline to empty, never a 500.
 */
class PrivateRoomController extends Controller
{
    public function __construct(
        private readonly PrivateRoomService $rooms,
        private readonly MatrixClientService $client,
        private readonly MatrixPostingGateService $posting,
    ) {}

    /** GET /civic/rooms — the Messages inbox: the player's own + joined direct & group messages. */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $memberSpaceIds = SocialMembership::query()
            ->where('user_id', (string) $user->getKey())
            ->whereNull('deleted_at')
            ->pluck('space_id');

        $spaces = SocialSpace::query()
            ->where('is_private', true)
            ->where('space_type', SocialSpace::TYPE_GROUP)
            ->whereKey($memberSpaceIds)
            ->withCount('memberships')
            ->orderByDesc('created_at')
            ->get();

        // A last-message preview is a REAL Matrix read per room, degraded to null (never a faked
        // preview) when a room has no live channel or the homeserver is down; reads stop after the
        // FIRST failure so a down homeserver costs ONE call, not N. Unread counts, a live-now signal,
        // and a persisted DM-vs-group kind have NO source table — they stay honest-empty (absent from
        // the row), never invented. Member count IS real and stands in for the DM/group distinction.
        $myMxid = $this->posting->matrixUserId($user);
        $matrixDown = false;
        $asRow = function (SocialSpace $s) use ($user, $myMxid, &$matrixDown): array {
            [$preview, $lastAt] = $this->latestPreview($s, $myMxid, $matrixDown);

            return [
                'id'          => (string) $s->id,
                'title'       => $s->title,
                'is_owner'    => (string) $s->owner_user_id === (string) $user->getKey(),
                'memberCount' => (int) $s->memberships_count,
                'openedAt'    => $s->created_at?->toIso8601String(),
                'preview'     => $preview,   // last message, or null (no messages yet / homeserver down)
                'lastAt'      => $lastAt,     // that message's time, for the row's relative "when"
            ];
        };

        $rows = $spaces->map($asRow)->values();

        // The just-created conversation (store() lands back here with ?created=<id>) — resolved ONLY
        // from the member-filtered rows above, so the "Bring people in" share panel can never surface
        // a room the player isn't in.
        $createdId = (string) $request->query('created', '');
        $created = $createdId !== '' ? $rows->firstWhere('id', $createdId) : null;

        return Inertia::render('Civic/PrivateRooms', [
            'surface' => ['title' => 'Messages', 'nav' => 'rooms'],
            'rooms'   => $rows->all(),
            'created' => $created,
        ]);
    }

    /**
     * GET /civic/rooms/new — the "New message" page: name a conversation and start it. There is no
     * @handle people-picker (the mockup's) by design — identities are pseudonymous and there is NO
     * user directory, so people arrive by an invite LINK the owner shares after creating the room.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Civic/PrivateRoomCreate', [
            'surface' => ['title' => 'New message', 'nav' => 'rooms'],
        ]);
    }

    /** POST /civic/rooms — create a direct/group message room (you become its owner). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);

        $space = $this->rooms->create($request->user(), $data['name']);

        // Land back on the Messages inbox with the share step open ("Bring people in") — the invite
        // link is THE way people arrive (no user directory, by design); the room is one click away.
        return redirect('/civic/rooms?created='.$space->id)
            ->with('status', 'Your conversation is ready — share a link to bring people in.');
    }

    /** GET /civic/rooms/{space} — member-gated room view (timeline + call + invite). */
    public function show(Request $request, SocialSpace $space): Response
    {
        $user = $request->user();

        // Member-gate: a non-member sees a "you need an invite" stub — never the room, timeline, or members.
        if (! $this->rooms->isMember($space, $user)) {
            return Inertia::render('Civic/PrivateRoom', [
                'surface' => ['title' => 'Private room', 'nav' => 'rooms'],
                'locked'  => true,
                'room'    => ['id' => (string) $space->id, 'title' => null],
            ]);
        }

        $room = $this->rooms->roomFor($space);

        // Read-degrade: a down homeserver shows an EMPTY timeline, never a broken page (the commons posture).
        $messages = [];
        $reachable = true;
        if ($room !== null) {
            try {
                $page = $this->client->getMessages($room->matrix_room_id, 'b', null, 40);
                $messages = $this->mapMessages((array) ($page['chunk'] ?? []));
            } catch (Throwable $e) {
                $reachable = false;
            }
        }

        $members = SocialMembership::query()
            ->where('space_id', (string) $space->id)
            ->whereNull('deleted_at')
            ->get(['user_id', 'role']);
        $memberUsers = User::query()->whereIn('id', $members->pluck('user_id'))->get()->keyBy('id');

        return Inertia::render('Civic/PrivateRoom', [
            'surface'    => ['title' => $space->title, 'nav' => 'rooms'],
            'locked'     => false,
            'room'       => [
                'id'       => (string) $space->id,
                'title'    => $space->title,
                'is_owner' => (string) $space->owner_user_id === (string) $user->getKey(),
            ],
            'roomId'     => $room?->matrix_room_id,
            'reachable'  => $reachable,
            'messages'   => $messages,
            'members'    => $members->map(function (SocialMembership $m) use ($memberUsers) {
                $u = $memberUsers->get($m->user_id);

                return [
                    // Pseudonym only — never a legal name (Art. I).
                    'handle' => $u !== null ? $this->posting->localpartFor($u) : 'member',
                    'role'   => $m->role,
                ];
            })->values()->all(),
            'myMxid'     => $this->posting->matrixUserId($user),  // the player's own @u-<handle>
            'myUserId'   => (string) $user->getKey(),
        ]);
    }

    /** POST /civic/rooms/{space}/post — pseudonymous text into the private room (member-gated). */
    public function post(Request $request, SocialSpace $space): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $room = $this->rooms->roomFor($space);
        if ($room === null) {
            return back()->with('status', 'This room has no live channel yet.');
        }

        try {
            $this->posting->postToPrivateRoom($request->user(), $room->matrix_room_id, $data['body']);
        } catch (ConstitutionalViolation $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('status', 'Posted.');
    }

    /** POST /civic/rooms/{space}/leave — a member leaves (the owner stays). */
    public function leave(Request $request, SocialSpace $space): RedirectResponse
    {
        $this->rooms->leave($space, $request->user());

        return redirect('/civic/rooms')->with('status', 'You left the room.');
    }

    /**
     * The last message in a room, for the inbox preview — a REAL Matrix read, degraded to null (never
     * a faked preview) when a room has no live channel or the homeserver is down. The caller's
     * $matrixDown short-circuits every read after the first failure, so a down homeserver costs ONE
     * call across the whole inbox rather than one per room.
     *
     * @return array{0: ?string, 1: ?string} [preview text, that message's ISO-8601 time]
     */
    private function latestPreview(SocialSpace $space, string $myMxid, bool &$matrixDown): array
    {
        if ($matrixDown) {
            return [null, null];
        }

        $room = $this->rooms->roomFor($space);
        if ($room === null) {
            return [null, null]; // no live channel yet — honest-empty, not a Matrix call
        }

        try {
            // A small window (not just 1) so a trailing state event never masks the real last message.
            $page = $this->client->getMessages($room->matrix_room_id, 'b', null, 10);
        } catch (Throwable $e) {
            $matrixDown = true; // degrade the rest of the inbox to no-preview — no further reads

            return [null, null];
        }

        $messages = $this->mapMessages((array) ($page['chunk'] ?? [])); // oldest-first
        $last = end($messages) ?: null;
        if ($last === null || ($last['body'] ?? '') === '') {
            return [null, null];
        }

        $body = (string) $last['body'];
        $mine = ($last['sender'] ?? '') === $myMxid;
        $preview = ($mine ? 'You: ' : '').mb_strimwidth($body, 0, 90, '…');

        $at = $last['at'] ?? null;
        $atIso = $at !== null
            ? \Illuminate\Support\Carbon::createFromTimestampMs((int) $at)->toIso8601String()
            : null;

        return [$preview, $atIso];
    }

    /** @param array<int,array<string,mixed>> $chunk */
    private function mapMessages(array $chunk): array
    {
        $out = [];
        foreach ($chunk as $event) {
            if (($event['type'] ?? '') !== 'm.room.message') {
                continue;
            }
            $content = (array) ($event['content'] ?? []);
            $out[] = [
                'event_id' => (string) ($event['event_id'] ?? ''),
                'sender'   => (string) ($event['sender'] ?? ''),   // @u-<handle> — pseudonymous by construction
                'body'     => (string) ($content['body'] ?? ''),
                'at'       => $event['origin_server_ts'] ?? null,
            ];
        }

        return array_reverse($out); // dir='b' returns newest-first; render oldest-first
    }
}
