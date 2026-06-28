<?php

namespace Tests\Constitutional;

use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\ActorIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\TravelingVoiceTokenService;
use App\Services\Matrix\VoiceTokenRefused;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — cross-node voice token, the foci AV reach (Phase 5). A capable peer mints a LiveKit
 * join token for a TRAVELING player (homed elsewhere) so media dials the peer's SFU directly.
 *
 * THE GATE IS COARSE / OPEN (operator's constitutional correction, 2026-06-27): the public commons is open
 * — a player with NO residency in any jurisdiction still gets a token (free movement + equal treatment,
 * Art. I; residency gates GOVERNANCE POWERS, which the game enforces, not room access). The only floor is a
 * real, HOME-VOUCHED player. INVARIANTS, all fail-closed: a forged/expired/tampered attestation, a stale
 * request, a device signature over a DIFFERENT room (relayer swap) or wrong protocol target, a replay, or an
 * unknown issuer all mint NOTHING; the token sub is the PSEUDONYM (never a legal name); the SFU secret never
 * leaves the box.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class TravelingVoiceTokenTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_voicetoken';
    private const PSEUDONYM = '@u-traveler:home.example';
    private const ROOM = 'call-square-J';

    public function test_open_commons_a_home_vouched_player_with_no_residency_gets_a_pseudonymous_token(): void
    {
        $this->onLivePg(function () {
            [$user, $att, $secret] = $this->homeVouchedPlayer();
            // The player has NO residency_confirmations anywhere — yet (open commons) the token mints.
            $this->assertSame(0, DB::table('residency_confirmations')->where('user_id', (string) $user->id)->count());

            $svc = app(TravelingVoiceTokenService::class);
            $minted = $svc->mintForTravelingActor($this->envelope($att, $secret, self::ROOM, self::PSEUDONYM), self::ROOM);

            $this->assertSame(self::ROOM, $minted['room']);
            $this->assertSame(self::PSEUDONYM, $minted['identity'], 'identity is the home-vouched pseudonym');
            $this->assertNotEmpty($minted['url']);

            // The minted token verifies and carries the PSEUDONYM as sub — never a legal name.
            $claims = app(LiveKitTokenService::class)->verify($minted['token']);
            $this->assertSame(self::PSEUDONYM, $claims['sub'] ?? null);
            $this->assertStringNotContainsString((string) $user->name, (string) json_encode($claims), 'no legal name in the token');
        });
    }

    public function test_fails_closed_on_a_bad_attestation_or_issuer(): void
    {
        $this->onLivePg(function () {
            [, $att, $secret] = $this->homeVouchedPlayer();
            $svc = app(TravelingVoiceTokenService::class);

            // Tampered attestation (mutated roles, signature not re-signed) → invalid.
            $tampered = $this->envelope($att, $secret, self::ROOM, self::PSEUDONYM);
            $tampered['attestation']['roles'] = ['R-99'];
            $this->assertRefused(403, fn () => $svc->mintForTravelingActor($tampered, self::ROOM));

            // Unknown issuer (no pinned key) → refused.
            $stranger = $this->envelope($att, $secret, self::ROOM, self::PSEUDONYM);
            $stranger['attestation']['issuer_server_id'] = (string) \Illuminate\Support\Str::uuid();
            $this->assertRefused(403, fn () => $svc->mintForTravelingActor($stranger, self::ROOM));

            // A validly-signed but EXPIRED attestation → refused.
            $expired = $this->signedAttestation($att->device_public_key, (string) $att->subject_user_id, now()->subHours(2), now()->subHour());
            $this->assertRefused(403, fn () => $svc->mintForTravelingActor($this->envelope($expired, $secret, self::ROOM, self::PSEUDONYM), self::ROOM));

            // A valid, UNEXPIRED, but too-OLD attestation (issued 20m ago) → refused by the recency cap that
            // bounds the cross-node ban-evasion window (revocation doesn't yet propagate to a verifying peer).
            $stale = $this->signedAttestation($att->device_public_key, (string) $att->subject_user_id, now()->subMinutes(20), now()->addHour());
            $this->assertRefused(403, fn () => $svc->mintForTravelingActor($this->envelope($stale, $secret, self::ROOM, self::PSEUDONYM), self::ROOM));
        });
    }

    public function test_fails_closed_on_replay_staleness_and_room_swap(): void
    {
        $this->onLivePg(function () {
            [, $att, $secret] = $this->homeVouchedPlayer();
            $svc = app(TravelingVoiceTokenService::class);

            // Stale timestamp (outside the freshness window) → refused.
            $stale = $this->envelope($att, $secret, self::ROOM, self::PSEUDONYM, now()->getTimestamp() - 600);
            $this->assertRefused(403, fn () => $svc->mintForTravelingActor($stale, self::ROOM));

            // Relayer swap: the device signed for ROOM, but the request asks for a DIFFERENT room → the
            // action signature does not cover the swapped room → refused.
            $env = $this->envelope($att, $secret, self::ROOM, self::PSEUDONYM);
            $this->assertRefused(403, fn () => $svc->mintForTravelingActor($env, 'call-OTHER-room'));

            // Replay: a fresh valid request mints once; the SAME request again is refused (single-use nonce).
            $good = $this->envelope($att, $secret, self::ROOM, self::PSEUDONYM);
            $this->assertSame(self::ROOM, $svc->mintForTravelingActor($good, self::ROOM)['room']);
            $this->assertRefused(403, fn () => $svc->mintForTravelingActor($good, self::ROOM));
        });
    }

    /** @return array{0:User,1:StandingAttestation,2:string} [user, attestation, device-secret-binary] */
    private function homeVouchedPlayer(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $devicePub = sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL);
        $deviceSecret = sodium_crypto_sign_secretkey($kp);

        $user = User::factory()->create(['home_server_id' => null]); // home = us → issue() allows
        $att = app(AttestationService::class)->issue($user, $devicePub, 3600);

        return [$user, $att, $deviceSecret];
    }

    /** A validly-signed (by our instance key) attestation with explicit issued/expires times. */
    private function signedAttestation(string $devicePub, string $subjectUserId, \Carbon\Carbon $issuedAt, \Carbon\Carbon $expiresAt): StandingAttestation
    {
        $att = new StandingAttestation([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'subject_user_id' => $subjectUserId,
            'device_public_key' => $devicePub,
            'issuer_server_id' => app(InstanceIdentityService::class)->serverId(),
            'roles' => [],
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ]);
        $att->signature = app(InstanceIdentityService::class)->sign(app(AttestationService::class)->attestationCanonical($att));

        return $att;
    }

    /** A wire envelope as the traveling player's home node would build it (device signs target+body). */
    private function envelope(StandingAttestation $att, string $deviceSecret, string $room, string $pseudonym, ?int $ts = null): array
    {
        $ts ??= now()->getTimestamp();
        $body = AuditService::canonicalJson(['room' => $room, 'subject_user_id' => (string) $att->subject_user_id, 'pseudonym' => $pseudonym]);
        $signingString = ActorIdentityService::actionSigningString('POST', '/actor/voice-token', $ts, $body);
        $signature = sodium_bin2base64(sodium_crypto_sign_detached($signingString, $deviceSecret), SODIUM_BASE64_VARIANT_ORIGINAL);

        return [
            'attestation' => [
                'id' => (string) $att->id,
                'subject_user_id' => (string) $att->subject_user_id,
                'device_public_key' => (string) $att->device_public_key,
                'issuer_server_id' => (string) $att->issuer_server_id,
                'roles' => array_values((array) $att->roles),
                'issued_at' => $att->issued_at->getTimestamp(),
                'expires_at' => $att->expires_at->getTimestamp(),
                'signature' => (string) $att->signature,
            ],
            'timestamp' => $ts,
            'action_signature' => $signature,
            'pseudonym' => $pseudonym,
        ];
    }

    private function assertRefused(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected VoiceTokenRefused({$status})");
        } catch (VoiceTokenRefused $e) {
            $this->assertSame($status, $e->status());
        }
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(\App\Services\RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(\App\Services\RoleService::class)->flush();
        }
    }
}
