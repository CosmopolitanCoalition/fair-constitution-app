<?php

namespace App\Http\Controllers\Civic;

use App\Http\Controllers\Controller;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\MatrixRoom;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Matrix\TestimonyBridgeService;
use App\Services\RoleService;
use App\Services\Rooms\PublicRoomNames;
use App\Support\SurfaceMeta;
use App\Support\JurisdictionContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Phase K-3 (K3-L) — the embedded client for the LIVE commons over the Matrix mesh (Plane B), the
 * counterpart to the K-1 Plane-A record views. It READS a jurisdiction's #square / #halls timeline via
 * the appservice (getMessages), POSTS to the OPEN commons + pseudonymous (MatrixPostingGateService —
 * any player, resident or visitor, Art. I), and FILES a live message as testimony (TestimonyBridgeService
 * → the Plane-A seal, which IS residency-gated as governance participation). Messages are
 * pseudonymous BY CONSTRUCTION (the sender is the @u-<handle> mxid, never a legal name). A down /
 * unreachable homeserver DEGRADES to an empty timeline (`reachable=false`) — never a 500.
 */
class MatrixCommonsController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly MatrixClientService $client,
        private readonly MatrixPostingGateService $posting,
        private readonly TestimonyBridgeService $testimony,
        private readonly PublicRoomNames $names,
        private readonly SocialTopologyReconcilerService $topology,
    ) {}

    public function square(Request $request): Response
    {
        return $this->render($request, MatrixRoom::SPACE_PUBLIC_SQUARE, 'civic/commons-square');
    }

    public function halls(Request $request): Response
    {
        return $this->render($request, MatrixRoom::SPACE_HALLS, 'civic/commons-halls');
    }

    private function render(Request $request, string $spaceType, string $surfaceId): Response
    {
        $user = $request->user();
        $place = JurisdictionContext::requested($request);
        $associations = $user !== null ? $this->roles->associationsFor($user) : [];
        if ($place === null && isset($associations[0]['id'])) {
            $place = Jurisdiction::query()->find($associations[0]['id'], ['id', 'name', 'slug', 'parent_id', 'adm_level']);
        }
        $jurisdictionId = $place !== null ? (string) $place->id : '';
        $room = $place !== null ? $this->publicRoom($jurisdictionId, $spaceType) : null;
        $roomState = $place === null ? 'choose_place' : ($room === null ? 'not_ready' : 'ready');
        $reachable = true;
        $isRefresh = $request->headers->has('X-Inertia-Partial-Data')
            || $request->headers->has('X-Inertia-Partial-Component');
        $isPrefetch = str_contains(strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', '')), 'prefetch');
        if ($place !== null && $room === null && ! $isRefresh && ! $isPrefetch && $request->isMethod('GET')
            && ! $this->hasRoomMapping($jurisdictionId, $spaceType)) {
            // Match the existing topology policy: an active legislature is a
            // seated government. Never inspect or provision another place.
            $isSeated = $spaceType === MatrixRoom::SPACE_HALLS && Legislature::query()
                ->where('jurisdiction_id', $jurisdictionId)->where('status', Legislature::STATUS_ACTIVE)->exists();
            if ($spaceType === MatrixRoom::SPACE_PUBLIC_SQUARE || $isSeated) {
                try {
                    $this->topology->reconcileJurisdiction($jurisdictionId, $isSeated);
                    $room = $this->publicRoom($jurisdictionId, $spaceType);
                    $roomState = $room !== null ? 'ready' : 'not_ready';
                } catch (Throwable $e) {
                    $reachable = false;
                    $roomState = 'unavailable';
                }
            } else {
                $roomState = 'waiting_for_government';
            }
        }

        // Read-degrade: a down/unreachable homeserver shows an EMPTY timeline, never a broken page.
        $messages = [];
        if ($room !== null) {
            try {
                $page = $this->client->getMessages($room->matrix_room_id, 'b', null, 40);
                $messages = $this->mapMessages((array) ($page['chunk'] ?? []));
            } catch (Throwable $e) {
                $reachable = false;
            }
        }

        $myMxid = $user !== null ? $this->posting->matrixUserId($user) : null;
        $displayNames = $this->names->forHandles(array_column($messages, 'sender'));
        if ($myMxid !== null) {
            $ownName = $this->names->forUsers([(string) $user->id])[(string) $user->id] ?? null;
            if ($ownName !== null) {
                $displayNames[$myMxid] = $ownName;
            }
        }

        return Inertia::render('Civic/MatrixCommons', [
            'surface' => SurfaceMeta::for($surfaceId),
            'spaceType' => $spaceType,
            'isHalls' => $spaceType === MatrixRoom::SPACE_HALLS,
            'jurisdictionId' => $jurisdictionId,
            'selectedPlace' => $place !== null ? JurisdictionContext::chip($place) : null,
            'jurisdictionContext' => $place !== null ? JurisdictionContext::forRoom($place) : null,
            'roomState' => $roomState,
            'roomId' => $room?->matrix_room_id,
            'reachable' => $reachable,
            'messages' => $messages,
            'jurisdictions' => array_map(
                fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'adm_level' => $a['adm_level']],
                $associations
            ),
            'isAssociated' => in_array($jurisdictionId, array_column($associations, 'id'), true),
            'myMxid' => $myMxid,
            'displayNames' => $displayNames,
        ]);
    }

    /** A guest read must never turn an unrelated or private mapping into a public timeline. */
    private function publicRoom(string $jurisdictionId, string $spaceType): ?MatrixRoom
    {
        return MatrixRoom::query()->where('entity_type', MatrixRoom::ENTITY_JURISDICTION)
            ->where('entity_id', $jurisdictionId)->where('space_type', $spaceType)
            ->where('room_type', MatrixRoom::ROOM_COMMONS)->where('is_public', true)->where('is_encrypted', false)
            ->whereNull('tombstoned_at')->whereNotNull('matrix_room_id')
            ->first(['id', 'matrix_room_id']);
    }

    private function hasRoomMapping(string $jurisdictionId, string $spaceType): bool
    {
        // A private, encrypted or retired mapping is not a missing room. Do
        // not alter or recreate it as a side effect of a public visit.
        return MatrixRoom::query()->where('entity_type', MatrixRoom::ENTITY_JURISDICTION)
            ->where('entity_id', $jurisdictionId)->where('space_type', $spaceType)->exists();
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
                'sender' => (string) ($event['sender'] ?? ''),       // @u-<handle> — pseudonymous by construction
                'body' => (string) ($content['body'] ?? ''),
                'seat' => $content['cga.acting_seat'] ?? null,      // derived-live officeholder badge
                'at' => $event['origin_server_ts'] ?? null,
            ];
        }

        return array_reverse($out); // getMessages dir='b' returns newest-first; render oldest-first
    }

    /** Open, pseudonymous post into the live commons (Art. I — the public commons is open to any player). */
    public function post(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jurisdiction_id' => ['required', 'uuid'],
            'room_id' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $this->posting->post($request->user(), $validated['jurisdiction_id'], $validated['room_id'], $validated['body']);
        } catch (ConnectionException|RequestException $e) {
            return back()->withErrors(['body' => 'Posting could not be confirmed. Your draft has been kept; try again.']);
        }

        return back()->with('status', 'Posted to the live commons.');
    }

    /** File a live #halls message as testimony — the Plane B → Plane A seal (F-SOC-002). */
    public function fileTestimony(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->testimony->fileTestimony($request->user(), $validated['room_id'], $validated['event_id']);
        } catch (ConnectionException|RequestException $e) {
            return back()->withErrors(['room' => 'The live message could not be read. Try filing it again when the room is available.']);
        }

        return back()->with('status', 'Filed as testimony — sealed into the append-only record (Art. II §2).');
    }
}
