<?php

namespace App\Services\Matrix;

use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\Federation\MultiplexClient;
use App\Services\Federation\ServiceReachService;
use App\Services\Identity\AttestationService;
use Throwable;

/**
 * Phase 5 — foci AV reach, the HOME node (L) side. Resolves where a player's voice should be served and
 * returns a LiveKit token + the SFU url to dial:
 *   • we host the SFU (local) → mint locally (coarse-open: an authenticated player gets commons access);
 *   • a capable PEER hosts it → issue a SHORT-TTL home attestation, package the player's device-signed
 *     envelope, and forward to the peer's /voice/token; the peer mints with ITS secret and returns
 *     {token, sfu_url}. The player then dials the PEER's SFU directly — media bypasses this node.
 *   • nobody reachable → NoReachableHolder propagates (the caller degrades to no voice, never a hard fail).
 *
 * The device signature is created by the player's CLIENT (the device secret is un-escrowed; this node never
 * holds it) and arrives in the request — this service only ISSUES the home attestation (it is the player's
 * home authority) and assembles the envelope. A short attestation TTL bounds the cross-node ban-evasion
 * window: a home `attestation.revoked` now propagates to the verifying peer via the FF&C sync tail (Flag 2),
 * and the short TTL caps the residual exposure to the sync LAG before that revocation lands.
 */
class VoiceReachService
{
    /** Short — bounds the sync-LAG window before a propagated revocation reaches the verifying peer. */
    public const ATTESTATION_TTL_SECONDS = 600;

    public function __construct(
        private readonly ServiceReachService $reach,
        private readonly AttestationService $attestations,
        private readonly LiveKitTokenService $livekit,
        private readonly MatrixPostingGateService $posting,
        private readonly MultiplexClient $mux,
    ) {}

    /**
     * @param  array{device_public_key?:string,action_signature?:string,timestamp?:int}  $device  the client's device-signed proof
     * @return array{token:string,sfu_url:string,room:string,identity:string,via:string}
     *
     * @throws \App\Services\Federation\NoReachableHolder when no peer hosts voice.sfu (degrade, no voice)
     * @throws VoiceReachFailed when the chosen peer refused the request
     */
    public function tokenFor(User $player, string $jurisdictionId, string $room, array $device): array
    {
        $pointer = $this->reach->reachLiveService('voice.sfu', $jurisdictionId);
        $pseudonym = $this->posting->matrixUserId($player); // @u-<handle>:domain — the home-vouched identity

        // We host the SFU → mint locally with our own secret. Coarse-open: the commons is open.
        if ($pointer['local']) {
            $minted = $this->livekit->mintAccessToken($pseudonym, $room);

            return [
                'token' => $minted['token'], 'sfu_url' => $minted['url'], 'room' => $minted['room'],
                'identity' => $pseudonym, 'via' => 'local',
            ];
        }

        // Cross-node: issue a short-TTL attestation bound to the client's device key, then forward.
        $attestation = $this->attestations->issue(
            $player, (string) ($device['device_public_key'] ?? ''), self::ATTESTATION_TTL_SECONDS
        );

        $envelope = [
            'actor' => [
                'attestation' => $this->wire($attestation),
                'timestamp' => (int) ($device['timestamp'] ?? 0),
                'action_signature' => (string) ($device['action_signature'] ?? ''),
                'pseudonym' => $pseudonym,
            ],
            'room' => $room,
        ];

        try {
            $resp = $this->mux->reach((string) $pointer['server_id'], 'POST', '/api/federation/voice/token', $envelope);
        } catch (Throwable $e) {
            throw new VoiceReachFailed('peer_unreachable: '.$e->getMessage(), 504);
        }

        if (! $resp->successful()) {
            throw new VoiceReachFailed('peer_refused', $resp->status());
        }

        $body = (array) $resp->json();

        return [
            'token' => (string) ($body['token'] ?? ''),
            'sfu_url' => (string) ($body['sfu_url'] ?? ''),
            'room' => (string) ($body['room'] ?? $room),
            'identity' => $pseudonym,
            'via' => (string) $pointer['server_id'],
        ];
    }

    /** Serialize the attestation to the wire form the peer's verifier reconstructs (epoch ints). */
    private function wire(StandingAttestation $a): array
    {
        return [
            'id' => (string) $a->id,
            'subject_user_id' => (string) $a->subject_user_id,
            'device_public_key' => (string) $a->device_public_key,
            'issuer_server_id' => (string) $a->issuer_server_id,
            'roles' => array_values((array) $a->roles),
            'issued_at' => $a->issued_at->getTimestamp(),
            'expires_at' => $a->expires_at->getTimestamp(),
            'signature' => (string) $a->signature,
        ];
    }
}
