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
}
