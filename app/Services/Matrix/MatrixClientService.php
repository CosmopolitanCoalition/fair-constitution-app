<?php

namespace App\Services\Matrix;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase K-3 — the appservice's thin wrapper over the Matrix Client-Server API. Authenticates as the
 * CGA appservice with the as_token, and impersonates namespaced users (@u-<handle>) via ?user_id=
 * (the appservice protocol — independent of MAS, which only handles human login). This is the OUTBOUND
 * half of the bridge: every room the CGA creates or governs goes through here. Room versions are
 * always queried LIVE (roomVersions()) — never hardcoded — so the v12 power-clamp (K3-E) can refuse a
 * homeserver that cannot offer it.
 */
class MatrixClientService
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('matrix.synapse_url'), '/'))
            ->withToken((string) config('matrix.appservice.as_token'))
            ->acceptJson()
            ->timeout(15);
    }

    /** ?user_id= for appservice impersonation of a namespaced user (null = act as the appservice sender). */
    private function asUser(?string $mxid): array
    {
        return $mxid !== null ? ['user_id' => $mxid] : [];
    }

    public function whoami(?string $asUser = null): array
    {
        return $this->http()->withQueryParameters($this->asUser($asUser))
            ->get('/_matrix/client/v3/account/whoami')->throw()->json();
    }

    public function capabilities(): array
    {
        return $this->http()->get('/_matrix/client/v3/capabilities')->throw()->json();
    }

    /** Fetch a single event (its REAL sender + content + origin_server_ts) — the testimony bridge's
     *  ground truth: the snapshot + the own-post check are made against this, not a caller's claim. */
    public function getEvent(string $roomId, string $eventId): array
    {
        $path = '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/event/'.rawurlencode($eventId);

        return $this->http()->get($path)->throw()->json();
    }

    /**
     * Fetch a page of a room's messages (the /messages endpoint) — the READ seam the embedded K3-L client
     * renders. Read-only; the appservice acts as the room's governor (no ?user_id needed for a public room
     * it created). Returns {chunk, start, end}; `from` pages backwards (dir='b') for the timeline.
     */
    public function getMessages(string $roomId, string $dir = 'b', ?string $from = null, int $limit = 50): array
    {
        $query = array_filter(
            ['dir' => $dir, 'from' => $from, 'limit' => max(1, min(100, $limit))],
            fn ($v) => $v !== null
        );
        $path = '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/messages';

        return $this->http()->withQueryParameters($query)->get($path)->throw()->json();
    }

    /** The homeserver's supported room versions + default, queried LIVE (never hardcode — K3-E gate). */
    public function roomVersions(): array
    {
        $caps = $this->capabilities()['capabilities']['m.room_versions'] ?? [];

        // Room versions are STRINGS ('11', '12') — but json_decode coerces the available map's
        // numeric keys to PHP ints, so strval() them or a strict in_array('12', …, true) misses.
        return [
            'default'   => isset($caps['default']) ? (string) $caps['default'] : null,
            'available' => array_map('strval', array_keys($caps['available'] ?? [])),
        ];
    }

    public function createRoom(array $body, ?string $asUser = null): array
    {
        return $this->http()->withQueryParameters($this->asUser($asUser))
            ->post('/_matrix/client/v3/createRoom', $body)->throw()->json();
    }

    public function sendStateEvent(string $roomId, string $type, string $stateKey, array $content, ?string $asUser = null): array
    {
        $path = '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/state/'.rawurlencode($type).'/'.rawurlencode($stateKey);

        return $this->http()->withQueryParameters($this->asUser($asUser))->put($path, $content)->throw()->json();
    }

    public function sendMessage(string $roomId, array $content, ?string $asUser = null, string $type = 'm.room.message'): array
    {
        $txn = (string) Str::uuid();
        $path = '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/send/'.rawurlencode($type).'/'.$txn;

        return $this->http()->withQueryParameters($this->asUser($asUser))->put($path, $content)->throw()->json();
    }

    /** Best-effort UI removal (Art. I §5.7 — never "erased"); the durable artifact is the carve-out log. */
    public function redact(string $roomId, string $eventId, string $reason, ?string $asUser = null): array
    {
        $txn = (string) Str::uuid();
        $path = '/_matrix/client/v3/rooms/'.rawurlencode($roomId).'/redact/'.rawurlencode($eventId).'/'.$txn;

        return $this->http()->withQueryParameters($this->asUser($asUser))->put($path, ['reason' => $reason])->throw()->json();
    }

    /**
     * M-5 (K3-I.4 / K3-N P1) — the CSAM byte-DESTROY seam (quarantine KEEPS bytes; redaction RETAINS them;
     * purge DELETEs them). Two halves: (1) the appservice-authorised redaction (strips event content,
     * always attempted), and (2) the actual media file + thumbnail destruction via the Synapse admin API
     * (DELETE /_synapse/admin/v1/media/<server>/<media_id>), which needs an OPERATOR-supplied admin token.
     *
     * Returns the HONEST physical-removal status — never claims the bytes are gone while they remain:
     *   'deferred' — no admin token (dev), or a text event with no media to destroy
     *   'done'     — the admin media-DELETE succeeded (bytes + thumbnails destroyed)
     *   'failed'   — the admin DELETE errored
     */
    public function purgeEvent(string $roomId, string $eventId, ?string $asUser = null): string
    {
        // (1) the appservice-authorised redaction — best-effort (the byte-DELETE below is the real removal).
        try {
            $this->redact($roomId, $eventId, '[m5_legal] purged', $asUser);
        } catch (\Throwable $e) {
            // continue — a down homeserver does not change the byte-destruction status below.
        }

        // (2) the media byte-DELETE needs the operator admin token (rig). Without it, the bytes survive —
        //     say so (DEFERRED), never 'done'.
        $adminToken = (string) config('matrix.admin_token', '');
        if ($adminToken === '') {
            return 'deferred';
        }

        return $this->destroyEventMedia($roomId, $eventId, $adminToken);
    }

    /** Resolve the event's mxc media and DELETE it via the Synapse admin API (rig-verified at LEG 5). */
    private function destroyEventMedia(string $roomId, string $eventId, string $adminToken): string
    {
        try {
            $event = $this->getEvent($roomId, $eventId);
            $content = (array) ($event['content'] ?? []);
            $mxc = (string) ($content['url'] ?? ($content['info']['thumbnail_url'] ?? ''));

            if (! str_starts_with($mxc, 'mxc://')) {
                return 'deferred'; // a text event — the redaction was the removal; no bytes to destroy
            }

            [$server, $mediaId] = explode('/', substr($mxc, strlen('mxc://')), 2) + [1 => ''];
            if ($server === '' || $mediaId === '') {
                return 'failed';
            }

            Http::baseUrl(rtrim((string) config('matrix.synapse_url'), '/'))
                ->withToken($adminToken)->acceptJson()->timeout(15)
                ->delete('/_synapse/admin/v1/media/'.rawurlencode($server).'/'.rawurlencode($mediaId))
                ->throw();

            return 'done';
        } catch (\Throwable $e) {
            return 'failed';
        }
    }
}
