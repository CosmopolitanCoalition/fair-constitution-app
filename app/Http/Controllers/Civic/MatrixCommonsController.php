<?php

namespace App\Http\Controllers\Civic;

use App\Http\Controllers\Controller;
use App\Models\MatrixRoom;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\TestimonyBridgeService;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Phase K-3 (K3-L) — the embedded client for the LIVE commons over the Matrix mesh (Plane B), the
 * counterpart to the K-1 Plane-A record views. It READS a jurisdiction's #square / #halls timeline via
 * the appservice (getMessages), POSTS residency-gated + pseudonymous (MatrixPostingGateService), and
 * FILES a live message as testimony (TestimonyBridgeService → the Plane-A seal). Messages are
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
        $associations = $this->roles->associationsFor($user);
        $jurisdictionId = (string) ($request->query('jurisdiction') ?? ($associations[0]['id'] ?? ''));

        $room = $jurisdictionId !== ''
            ? MatrixRoom::query()
                ->where('entity_type', MatrixRoom::ENTITY_JURISDICTION)
                ->where('entity_id', $jurisdictionId)
                ->where('space_type', $spaceType)
                ->whereNull('tombstoned_at')
                ->first()
            : null;

        // Read-degrade: a down/unreachable homeserver shows an EMPTY timeline, never a broken page.
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

        return Inertia::render('Civic/MatrixCommons', [
            'surface'        => SurfaceMeta::for($surfaceId),
            'spaceType'      => $spaceType,
            'isHalls'        => $spaceType === MatrixRoom::SPACE_HALLS,
            'jurisdictionId' => $jurisdictionId,
            'roomId'         => $room?->matrix_room_id,
            'reachable'      => $reachable,
            'messages'       => $messages,
            'jurisdictions'  => array_map(
                fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'adm_level' => $a['adm_level']],
                $associations
            ),
            'isAssociated'   => $associations !== [],
            'myMxid'         => $user !== null ? $this->posting->matrixUserId($user) : null,
        ]);
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
                'sender'   => (string) ($event['sender'] ?? ''),       // @u-<handle> — pseudonymous by construction
                'body'     => (string) ($content['body'] ?? ''),
                'seat'     => $content['cga.acting_seat'] ?? null,      // derived-live officeholder badge
                'at'       => $event['origin_server_ts'] ?? null,
            ];
        }

        return array_reverse($out); // getMessages dir='b' returns newest-first; render oldest-first
    }

    /** Residency-gated, pseudonymous post into the live room (Art. I — residency is the only gate). */
    public function post(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jurisdiction_id' => ['required', 'uuid'],
            'room_id'         => ['required', 'string', 'max:255'],
            'body'            => ['required', 'string', 'max:20000'],
        ]);

        $this->posting->post($request->user(), $validated['jurisdiction_id'], $validated['room_id'], $validated['body']);

        return back()->with('status', 'Posted to the live commons.');
    }

    /** File a live #halls message as testimony — the Plane B → Plane A seal (F-SOC-002). */
    public function fileTestimony(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_id'  => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:255'],
        ]);

        $this->testimony->fileTestimony($request->user(), $validated['room_id'], $validated['event_id']);

        return back()->with('status', 'Filed as testimony — sealed into the append-only record (Art. II §2).');
    }
}
