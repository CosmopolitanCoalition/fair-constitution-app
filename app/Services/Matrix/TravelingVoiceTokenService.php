<?php

namespace App\Services\Matrix;

use App\Models\FederationPeer;
use App\Models\StandingAttestation;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\ActorIdentityService;
use App\Services\Identity\AttestationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 5 — foci AV reach. The CAPABLE peer's side of cross-node voice: mint a LiveKit join token for a
 * TRAVELING player (homed on another node) so the player's media dials THIS box's SFU directly, bypassing
 * their home node. This box holds no residency facts about the player and alone holds its SFU api_secret.
 *
 * THE GATE IS COARSE / OPEN (operator's constitutional correction, 2026-06-27): access to a public commons
 * room is NOT residency-gated — free movement + equal treatment under the law (Art. I) make the public
 * square open to visitors; residency gates the GOVERNANCE POWERS (votes, seats), which the GAME enforces,
 * not the room-access layer. So the only floor here is "a real, HOME-VOUCHED player" — an abuse floor, not
 * a residency check. No jurisdiction id ever enters this path (minimal PII on a pseudonym).
 *
 * Authenticity is the same two-layer model as write-forwarding (mirrors AttestedForwardedActor) MINUS the
 * local-subject resolution (a foreign player has no local row): (1) the home authority's attestation
 * verifies against the ISSUER's pinned key (real, vouched player); (2) the player's DEVICE signed THIS exact
 * request over a DISTINCT target+body (no cross-protocol replay of a /actor/write signature); plus this READ
 * path adds its OWN replay defense — a freshness window + a single-use nonce — since it deliberately does
 * not use the write-path idempotency table. The token sub is the home-supplied PSEUDONYM (never re-derived
 * here, never a legal name); the SFU api_secret never leaves this box.
 */
class TravelingVoiceTokenService
{
    /** A token-mint request must be within ±this of now (the read-path replay bound; bounds blast radius). */
    public const FRESHNESS_WINDOW_SECONDS = 120;

    /**
     * The attestation must have been issued within this window. Cross-node attestation REVOCATION does not
     * yet propagate to a verifying peer (a shared limitation of the attestation model — the write-forwarding
     * path has it too; the proper fix is materializing foreign 'attestation.revoked' events into the local
     * CRL during sync-tail replay). Until then, requiring a FRESHLY-issued attestation bounds the cross-node
     * ban-evasion window for voice: a join request carries a per-request attestation anyway, so a banned
     * player's pre-ban attestation stops working here within minutes rather than lingering to its full TTL.
     */
    public const MAX_ATTESTATION_AGE_SECONDS = 900;

    public function __construct(
        private readonly AttestationService $attestations,
        private readonly InstanceIdentityService $identity,
        private readonly LiveKitTokenService $livekit,
    ) {}

    /**
     * @param  array<string,mixed>  $actor  the envelope's actor block: {attestation, timestamp, action_signature, pseudonym}
     * @return array{token:string,url:string,identity:string,room:string}
     *
     * @throws VoiceTokenRefused on ANY failed gate (mints nothing)
     */
    public function mintForTravelingActor(array $actor, string $room): array
    {
        $room = trim($room);
        if ($room === '') {
            throw new VoiceTokenRefused('missing_room', 422);
        }
        if (! is_array($actor['attestation'] ?? null)) {
            throw new VoiceTokenRefused('malformed_actor_envelope', 422);
        }
        // The pseudonym is the home-vouched Matrix identity; it becomes the token sub verbatim (never a legal
        // name, never re-derived on this box — we hold no profile for a foreign player).
        $pseudonym = (string) ($actor['pseudonym'] ?? '');
        if ($pseudonym === '' || ! str_starts_with($pseudonym, '@')) {
            throw new VoiceTokenRefused('missing_or_invalid_pseudonym', 422);
        }

        $attestation = $this->reconstruct($actor['attestation']);

        // (1) The attestation is valid against the ISSUER's pinned key (never the relayer's) — the player is
        // a real, home-vouched person. Fails closed on expiry / revocation / any field mutation.
        $issuerKey = $this->issuerPublicKey((string) $attestation->issuer_server_id);
        if ($issuerKey === null) {
            throw new VoiceTokenRefused('unknown_attestation_issuer', 403);
        }
        if (! $this->attestations->verifyAttestation($attestation, $issuerKey)) {
            throw new VoiceTokenRefused('attestation_invalid_expired_or_revoked', 403);
        }

        // (1b) Recency cap — bounds the cross-node ban-evasion window (revocation does not yet propagate to
        // a verifying peer). A voice join carries a fresh per-request attestation; a stale one is rejected.
        if (now()->getTimestamp() - (int) $attestation->issued_at->getTimestamp() > self::MAX_ATTESTATION_AGE_SECONDS) {
            throw new VoiceTokenRefused('attestation_too_old_reissue', 403);
        }

        // (2) Freshness — a READ path with no idempotency table must bound replay itself.
        $ts = (int) ($actor['timestamp'] ?? 0);
        if (abs(now()->getTimestamp() - $ts) > self::FRESHNESS_WINDOW_SECONDS) {
            throw new VoiceTokenRefused('stale_request', 403);
        }

        // (3) The DEVICE signed THIS exact request. A DISTINCT target (/actor/voice-token) + body kills
        // cross-protocol replay of a /actor/write signature; room + pseudonym are inside the signed body, so
        // a relayer cannot swap the room or the identity after the device signed.
        $signingString = ActorIdentityService::actionSigningString(
            'POST', '/actor/voice-token', $ts,
            $this->actionBody($room, (string) $attestation->subject_user_id, $pseudonym),
        );
        if (! InstanceIdentityService::verify(
            (string) $attestation->device_public_key, $signingString, (string) ($actor['action_signature'] ?? ''),
        )) {
            throw new VoiceTokenRefused('action_signature_invalid', 403);
        }

        // (4) Single-use within the freshness window — a captured valid request cannot be re-minted. The
        // nonce TTL must outlive the FULL freshness span: a request may arrive as early as ts-window and be
        // replayed as late as ts+window (2×window), and under clock skew an honest request can look
        // future-dated, so the nonce is sized to 2×window (+margin) so it never expires before the request
        // goes stale.
        $nonceKey = 'voice-token:nonce:'.hash('sha256', (string) ($actor['action_signature'] ?? ''));
        if (! Cache::add($nonceKey, 1, 2 * self::FRESHNESS_WINDOW_SECONDS + 5)) {
            throw new VoiceTokenRefused('replayed_request', 403);
        }

        // Mint with OUR SFU secret (it never leaves this box); the sub is the home-vouched pseudonym.
        return $this->livekit->mintAccessToken($pseudonym, $room);
    }

    /** The exact bytes the device signed — room + subject + pseudonym, canonicalized (issuer/verifier agree). */
    private function actionBody(string $room, string $subjectUserId, string $pseudonym): string
    {
        return AuditService::canonicalJson([
            'room' => $room,
            'subject_user_id' => $subjectUserId,
            'pseudonym' => $pseudonym,
        ]);
    }

    private function issuerPublicKey(string $issuerServerId): ?string
    {
        if ($issuerServerId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }
        $peer = FederationPeer::query()->where('server_id', $issuerServerId)->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }

    /**
     * Rebuild the unsaved StandingAttestation from the wire fields so its canonical (and signature check)
     * reproduces exactly — mirrors AttestedForwardedActor::reconstruct (epoch ints as the issuer hashed).
     *
     * @param  array<string,mixed>  $att
     */
    private function reconstruct(array $att): StandingAttestation
    {
        $attestation = new StandingAttestation([
            'id' => (string) ($att['id'] ?? ''),
            'subject_user_id' => (string) ($att['subject_user_id'] ?? ''),
            'device_public_key' => (string) ($att['device_public_key'] ?? ''),
            'issuer_server_id' => (string) ($att['issuer_server_id'] ?? ''),
            'roles' => array_values((array) ($att['roles'] ?? [])),
            'issued_at' => CarbonImmutable::createFromTimestamp((int) ($att['issued_at'] ?? 0)),
            'expires_at' => CarbonImmutable::createFromTimestamp((int) ($att['expires_at'] ?? 0)),
        ]);
        $attestation->signature = (string) ($att['signature'] ?? '');

        return $attestation;
    }
}
