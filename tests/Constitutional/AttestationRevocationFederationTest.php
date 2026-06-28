<?php

namespace Tests\Constitutional;

use App\Models\AttestationRevocation;
use App\Models\StandingAttestation;
use App\Models\SyncLogEntry;
use App\Services\AuditService;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\RoleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase 5 (Flag 2): cross-node attestation-revocation propagation. A home authority's
 * `attestation.revoked` rides the Full-Faith-&-Credit sync tail; the verifying peer MATERIALIZES it into its
 * local CRL so verifyAttestation then fails closed for that attestation — closing the cross-node ban-evasion
 * window for the foci voice path and write-forwarding (it retires the old voice recency-cap workaround).
 *
 * The materialization is ISSUER-BOUND and CRYPTOGRAPHICALLY PROVEN, so a trusted peer can never revoke
 * (denial-of-service) a THIRD node's attestations: (a) the revocation's issuer must BE the propagating peer;
 * (b) the issuer's detached signature must verify against the peer's PINNED key. A pre-enrichment / unsigned /
 * mis-issued / forged revocation is silently NOT materialized (fail-closed, idempotent).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AttestationRevocationFederationTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_attestation_revoc_fed';

    public function test_a_peer_revocation_in_the_tail_materializes_into_the_local_crl(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();
            $attestationId = (string) Str::uuid();
            $payload = $this->revocationPayload($peer->server_id, $attestationId, 'device_lost');

            // A single-entry, chain-valid tail carrying the revocation (no public records).
            $tail = $this->tailWithRevocation($peer, $payload);

            $log = app(FederationSyncService::class)->ingestTail($peer, $tail);
            $this->assertSame(SyncLogEntry::RESULT_APPLIED, $log->result, 'a signed, chain-valid revocation tail applies');

            $row = AttestationRevocation::query()->where('attestation_id', $attestationId)->first();
            $this->assertNotNull($row, 'the foreign revocation materialized into the local CRL');
            $this->assertSame((string) $peer->server_id, (string) $row->source_server_id, 'stamped foreign-sourced');
            $this->assertSame((string) $peer->server_id, (string) $row->issuer_server_id, 'the issuer is the propagating peer');
        });
    }

    public function test_a_propagated_revocation_makes_a_foreign_attestation_fail_closed(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $svc = app(AttestationService::class);

            // A foreign attestation X issued + signed by the peer (the home authority of its own user).
            $x = $this->foreignAttestation($peer->server_id);
            $this->assertTrue($svc->verifyAttestation($x, (string) $peer->public_key), 'before revocation: X verifies');

            // The peer revokes X and the revocation propagates to us — we materialize it.
            $payload = $this->revocationPayload($peer->server_id, (string) $x->id, 'abuse');
            $this->assertTrue($svc->ingestForeignRevocation($payload, (string) $peer->server_id, (string) $peer->public_key));

            // Now the verifying peer honors the revocation — X fails closed (the whole point of Flag 2).
            $this->assertFalse($svc->verifyAttestation($x, (string) $peer->public_key), 'after propagation: X is revoked, fails closed');
        });
    }

    public function test_a_planted_revocation_cannot_suppress_a_third_nodes_attestation(): void
    {
        $this->onLivePg(function () {
            $hostile = $this->makeTrustedPeer();   // P — a trusted but hostile peer (we hold its secret)
            $svc = app(AttestationService::class);

            // A THIRD node Q (≠ P) with its own key issues attestation X for one of its users.
            $qKeypair = sodium_crypto_sign_keypair();
            $qSecret = sodium_crypto_sign_secretkey($qKeypair);
            $qPublic = sodium_bin2base64(sodium_crypto_sign_publickey($qKeypair), SODIUM_BASE64_VARIANT_ORIGINAL);
            $qServerId = (string) Str::uuid();
            $x = $this->foreignAttestation($qServerId, $qSecret);
            $this->assertTrue($svc->verifyAttestation($x, $qPublic), 'X is valid before any poisoning');

            // P PLANTS a revocation naming X's id (attestation_id is mesh-public) but TRUTHFULLY claiming
            // ITSELF as the issuer — it signs with its own key, so both bindings pass and the row materializes.
            $poison = $this->revocationPayload((string) $hostile->server_id, (string) $x->id, 'abuse');
            $this->assertTrue($svc->ingestForeignRevocation($poison, (string) $hostile->server_id, (string) $hostile->public_key),
                'P may revoke its OWN attestations, so the row is accepted');

            // But it must NOT suppress X — isRevoked is issuer-scoped, and X was issued by Q, not P.
            $this->assertTrue($svc->verifyAttestation($x, $qPublic),
                'the planted row cannot DoS a third node\'s attestation (anti-poisoning)');

            // And Q's GENUINE revocation still materializes (coexists with the poison) and DOES suppress X.
            $genuine = $this->revocationPayload($qServerId, (string) $x->id, 'abuse', signWith: $qSecret);
            $this->assertTrue($svc->ingestForeignRevocation($genuine, $qServerId, $qPublic),
                'Q\'s genuine revocation lands despite the planted row (uniqueness is per (attestation, issuer))');
            $this->assertFalse($svc->verifyAttestation($x, $qPublic), 'Q\'s genuine revocation suppresses X');
        });
    }

    public function test_a_peer_cannot_revoke_another_nodes_attestation(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $svc = app(AttestationService::class);

            // The revocation claims a DIFFERENT issuer than the propagating peer — issuer-binding refuses it
            // (else a trusted peer could DoS a third node's users by revoking their attestations).
            $otherIssuer = (string) Str::uuid();
            $attestationId = (string) Str::uuid();
            $payload = $this->revocationPayload($otherIssuer, $attestationId, 'griefing', signWith: $this->peerSecret);

            $this->assertFalse($svc->ingestForeignRevocation($payload, (string) $peer->server_id, (string) $peer->public_key));
            $this->assertFalse(AttestationRevocation::query()->where('attestation_id', $attestationId)->exists(),
                'no CRL row — a peer may only carry its OWN revocations');
        });
    }

    public function test_a_forged_or_pre_enrichment_revocation_is_not_materialized(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $svc = app(AttestationService::class);

            // (a) issuer = peer but the signature is garbage → genuine-issuer proof fails.
            $forgedId = (string) Str::uuid();
            $forged = $this->revocationPayload((string) $peer->server_id, $forgedId, 'x');
            $forged['signature'] = sodium_bin2base64(random_bytes(64), SODIUM_BASE64_VARIANT_ORIGINAL);
            $this->assertFalse($svc->ingestForeignRevocation($forged, (string) $peer->server_id, (string) $peer->public_key));
            $this->assertFalse(AttestationRevocation::query()->where('attestation_id', $forgedId)->exists());

            // (b) a pre-enrichment payload (attestation_id + reason only) carries no issuer/signature → skipped.
            $legacyId = (string) Str::uuid();
            $this->assertFalse($svc->ingestForeignRevocation(
                ['attestation_id' => $legacyId, 'reason' => 'old-format'], (string) $peer->server_id, (string) $peer->public_key
            ));
            $this->assertFalse(AttestationRevocation::query()->where('attestation_id', $legacyId)->exists());
        });
    }

    public function test_materialization_is_idempotent(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $svc = app(AttestationService::class);

            $attestationId = (string) Str::uuid();
            $payload = $this->revocationPayload($peer->server_id, $attestationId, 'device_lost');

            $this->assertTrue($svc->ingestForeignRevocation($payload, (string) $peer->server_id, (string) $peer->public_key), 'first materializes');
            $this->assertFalse($svc->ingestForeignRevocation($payload, (string) $peer->server_id, (string) $peer->public_key), 'second is a no-op');
            $this->assertSame(1, AttestationRevocation::query()->where('attestation_id', $attestationId)->count(), 'exactly one CRL row');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A signed `attestation.revoked` audit payload as the issuer would emit it (signed by the peer key). */
    private function revocationPayload(string $issuer, string $attestationId, string $reason, ?string $signWith = null): array
    {
        $revokedAt = now()->getTimestamp();
        $rev = new AttestationRevocation([
            'attestation_id' => $attestationId,
            'issuer_server_id' => $issuer,
            'revoked_at' => CarbonImmutable::createFromTimestamp($revokedAt),
        ]);
        $canonical = app(AttestationService::class)->revocationCanonical($rev);
        $signature = sodium_bin2base64(
            sodium_crypto_sign_detached($canonical, $signWith ?? $this->peerSecret),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );

        return [
            'attestation_id' => $attestationId,
            'issuer_server_id' => $issuer,
            'revoked_at' => $revokedAt,
            'reason' => $reason,
            'signature' => $signature,
        ];
    }

    /** A foreign StandingAttestation issued by $issuer, signed by $signWith (defaults to the peer key). */
    private function foreignAttestation(string $issuer, ?string $signWith = null): StandingAttestation
    {
        $x = new StandingAttestation([
            'id' => (string) Str::uuid(),
            'subject_user_id' => (string) Str::uuid(),
            'device_public_key' => 'dev-'.Str::random(12),
            'issuer_server_id' => $issuer,
            'roles' => ['R-03'],
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);
        $x->signature = sodium_bin2base64(
            sodium_crypto_sign_detached(app(AttestationService::class)->attestationCanonical($x), $signWith ?? $this->peerSecret),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );

        return $x;
    }

    /** A single-entry, chain-valid, peer-signed tail carrying one `attestation.revoked` audit entry. */
    private function tailWithRevocation(\App\Models\FederationPeer $peer, array $payload): array
    {
        $prev = str_repeat('0', 64);
        $hash = AuditService::chainHash($prev, AuditService::canonicalJson($payload));
        $entry = [
            'seq' => 900001,
            'prev_hash' => $prev,
            'hash' => $hash,
            'module' => 'actor_identity',
            'event' => 'attestation.revoked',
            'ref' => 'WF-JUR-06',
            'jurisdiction_id' => null,
            'payload' => $payload,
        ];

        return $this->signTail([
            'server_id' => $peer->server_id,
            'schema_version' => (string) config('cga.schema_version', '1'),
            'from_seq' => $entry['seq'] - 1,
            'to_seq' => $entry['seq'],
            'head_hash' => $hash,
            'entries' => [$entry],
            'records' => [],
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
