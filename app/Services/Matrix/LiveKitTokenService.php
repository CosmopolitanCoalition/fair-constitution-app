<?php

namespace App\Services\Matrix;

use App\Models\User;

/**
 * Phase K-3 (K3-J) — the LiveKit (Element Call SFU) access-token minter. Voice/video in a jurisdiction's
 * room is participation, so it is gated EXACTLY like posting: RESIDENCY is the ONLY gate (Art. I — never
 * karma, account age, or any reputation), reusing MatrixPostingGateService::assertMayPost. The token's
 * identity is the resident's PSEUDONYM (@u-<handle>), never the legal name. The token is ROOM-SCOPED
 * (a VideoGrant for one room), SHORT-LIVED (a bounded exp), grants NO admin/recording rights, and is
 * signed by the APPSERVICE alone (HS256 over the LiveKit api_secret — hand-rolled, no extra dependency).
 *
 * The SFU itself (the livekit-server container) + the real media path are dev-stack / scaling concerns;
 * this service is the constitutional surface: who may get a token, as whom, for how long, for what room.
 */
class LiveKitTokenService
{
    /** Hard ceiling on a call token's lifetime — a token is a short-lived join grant, not a session. */
    public const MAX_TTL_SECONDS = 21600; // 6h

    public const DEFAULT_TTL_SECONDS = 3600; // 1h

    public function __construct(private readonly MatrixPostingGateService $posting) {}

    /**
     * Mint a room-scoped LiveKit join token for a RESIDENT of $jurisdictionId.
     *
     * @return array{token:string,url:string,identity:string,room:string}
     *
     * @throws \App\Domain\Engine\ConstitutionalViolation when the caller is not a resident (Art. I)
     */
    public function mintFor(User $caller, string $jurisdictionId, string $roomName, ?int $ttlSeconds = null): array
    {
        // RESIDENCY is the ONLY gate — the SAME assertion as posting. A non-resident throws Art. I.
        $this->posting->assertMayPost($caller, $jurisdictionId);

        $identity = $this->posting->matrixUserId($caller);  // pseudonym — never the legal name
        $ttl = min(max(60, $ttlSeconds ?? self::DEFAULT_TTL_SECONDS), self::MAX_TTL_SECONDS);

        return [
            'token'    => $this->mintJwt($identity, $roomName, $ttl),
            'url'      => (string) config('matrix.livekit.url'),
            'identity' => $identity,
            'room'     => $roomName,
        ];
    }

    /**
     * Mint a room-scoped LiveKit join token for an ALREADY-AUTHORIZED identity — the GATE is the CALLER's
     * responsibility. Used by the attestation-gated cross-node path (TravelingVoiceTokenService): a capable
     * peer mints for a TRAVELING player it has already verified via a home-signed attestation, signing with
     * THIS box's own api_secret (which never leaves the box). Returns the EXTERNALLY-reachable SFU url so the
     * remote browser can dial it directly. Identity must be the PSEUDONYM (never a legal name).
     *
     * @return array{token:string,url:string,identity:string,room:string}
     */
    public function mintAccessToken(string $identity, string $roomName, ?int $ttlSeconds = null): array
    {
        $ttl = min(max(60, $ttlSeconds ?? self::DEFAULT_TTL_SECONDS), self::MAX_TTL_SECONDS);

        return [
            'token'    => $this->mintJwt($identity, $roomName, $ttl),
            'url'      => (string) config('matrix.livekit.public_url', config('matrix.livekit.url')),
            'identity' => $identity,
            'room'     => $roomName,
        ];
    }

    /** Build + sign the LiveKit JWT (HS256 over api_secret). Room-scoped VideoGrant, bounded lifetime. */
    private function mintJwt(string $identity, string $roomName, int $ttl): string
    {
        $apiKey = (string) config('matrix.livekit.api_key');
        $secret = (string) config('matrix.livekit.api_secret');
        $now = now()->timestamp;

        $claims = [
            'iss'   => $apiKey,           // LiveKit identifies the signer by api_key
            'sub'   => $identity,         // the participant identity = the pseudonym
            'name'  => $identity,
            'nbf'   => $now,
            'exp'   => $now + $ttl,       // bounded — a join grant, not a session
            'video' => [                  // the VideoGrant: ONE room, join only, no admin/record rights
                'room'         => $roomName,
                'roomJoin'     => true,
                'canPublish'   => true,
                'canSubscribe' => true,
            ],
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $signingInput = $this->b64url($this->json($header)).'.'.$this->b64url($this->json($claims));
        $signature = hash_hmac('sha256', $signingInput, $secret, true);

        return $signingInput.'.'.$this->b64url($signature);
    }

    /** Verify + decode a token against the configured api_secret (fails closed). For callers/tests. */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $expected = $this->b64url(hash_hmac('sha256', $h.'.'.$p, (string) config('matrix.livekit.api_secret'), true));
        if (! hash_equals($expected, $s)) {
            return null; // signature mismatch — not minted by this appservice
        }

        $claims = json_decode($this->b64urlDecode($p), true);

        return is_array($claims) ? $claims : null;
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
