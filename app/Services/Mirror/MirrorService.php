<?php

namespace App\Services\Mirror;

use App\Models\ClusterAdoptionRequest;
use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Services\AuditService;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mirror lifecycle (Phase G, Track A — the permissionless read-only commons).
 *
 * The shared local-state path behind BOTH the CLI (`federation:cold-sync` /
 * adoption command) and the browser "Join a cluster" wizard. G1 lands the
 * structural primitives — pin the host, open the `mirror` membership, and flip
 * THIS instance into read-only-mirror mode. G2 layers the network adoption
 * (`POST /api/federation/adopt`, join key, chunked backfill) and the
 * ConstitutionalEngine write-guard on top of these primitives.
 *
 * Cardinal invariant: a mirror is authoritative for NOTHING. Nothing here ever
 * writes `authoritative_server_id` — `mirror_of_server_id` points AT the host;
 * it never claims authority.
 */
class MirrorService
{
    public function __construct(
        private readonly PeerService $peers,
        private readonly AuditService $audit,
        private readonly MirrorJoinKeyService $keys,
        private readonly MirrorBackfillService $backfill,
        private readonly FederationClient $client,
        private readonly InstanceIdentityService $identity,
    ) {}

    /** Is THIS instance a read-only mirror of some host? */
    public function isMirror(): bool
    {
        return InstanceSettings::current()->isMirror();
    }

    /**
     * Pin the host we intend to mirror as a trusted `host` edge (TOFU), reusing
     * the shared peer-upsert so the mirror path and the sovereign handshake share
     * one pin block. From our side the peer's relation is `host`.
     *
     * @param  array<string,mixed>  $attrs  name/url/schema_version
     */
    public function pinHost(string $serverId, string $publicKey, array $attrs = []): FederationPeer
    {
        return $this->peers->upsertTrustedPeer(
            $serverId, $publicKey, $attrs, FederationPeer::RELATION_HOST, 'mirror_host'
        );
    }

    /**
     * Open (idempotently) our `mirror` membership against a host peer. Creating a
     * second active `mirror` membership — against any other host — is rejected by
     * the one-active-mirror index: an instance mirrors at most one host.
     */
    public function openMirrorMembership(
        FederationPeer $host,
        string $admissionMethod,
        ?string $scopeJurisdictionId = null,
    ): ClusterMembership {
        return ClusterMembership::query()->firstOrCreate(
            ['peer_id' => $host->id, 'role' => ClusterMembership::ROLE_MIRROR],
            [
                'state' => ClusterMembership::STATE_REQUESTED,
                'admission_method' => $admissionMethod,
                'scope_jurisdiction_id' => $scopeJurisdictionId,
            ],
        );
    }

    /**
     * Commit the adoption: mark the membership live and flip THIS instance into
     * read-only-mirror mode. After this the engine write-guard (G2) refuses every
     * constitutional write. Idempotent — re-running on an already-live mirror of
     * the same host is a no-op beyond refreshing the membership state.
     */
    public function markMirrorLive(ClusterMembership $membership, string $hostServerId): void
    {
        $membership->update(['state' => ClusterMembership::STATE_LIVE]);

        $settings = InstanceSettings::current();
        $settings->mirror_of_server_id = $hostServerId;
        $settings->mirror_adopted_at ??= now();
        $settings->save();

        $this->audit->append('mirror', 'mirror.adopted',
            ['host_server_id' => $hostServerId, 'membership_id' => $membership->id], 'WF-JUR-06');
    }

    /**
     * HOST side of adoption. Atomically: ledger the nonce (anti-replay), verify +
     * consume the join key, pin the applicant as OUR mirror (relation=mirror), and
     * open a `host` membership. A replayed (applicant, nonce) collides on the
     * unique index (the caller maps it to 409); an invalid/exhausted key throws
     * AdoptionRejected. The applicant is authoritative for nothing — we stay the
     * authoritative instance and it copies us.
     */
    public function admitMirror(
        string $applicantServerId,
        string $applicantPublicKey,
        string $nonce,
        string $plaintextKey,
        ?string $applicantUrl = null,
    ): ClusterMembership {
        if ($applicantServerId === '' || $applicantPublicKey === '' || $nonce === '' || $plaintextKey === '') {
            throw new AdoptionRejected('incomplete_adoption_request', 422);
        }
        if ($applicantServerId === $this->identity->serverId()) {
            throw new AdoptionRejected('refuse_self', 422);
        }

        return DB::transaction(function () use ($applicantServerId, $applicantPublicKey, $nonce, $plaintextKey, $applicantUrl): ClusterMembership {
            // Anti-replay: a duplicate (applicant, nonce) raises the unique index.
            $request = ClusterAdoptionRequest::create([
                'applicant_server_id' => $applicantServerId,
                'applicant_public_key' => $applicantPublicKey,
                'nonce' => $nonce,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
                'status' => ClusterAdoptionRequest::STATUS_PENDING,
            ]);

            $key = $this->keys->verify($plaintextKey);
            if ($key === null || ! $this->keys->consume($key)) {
                $request->update(['status' => ClusterAdoptionRequest::STATUS_REJECTED]);
                throw new AdoptionRejected('invalid_or_exhausted_key', 403);
            }

            $peer = $this->peers->upsertTrustedPeer(
                $applicantServerId, $applicantPublicKey, ['url' => $applicantUrl],
                FederationPeer::RELATION_MIRROR, 'mirror_admitted'
            );

            $membership = $this->openHostMembership($peer, ClusterMembership::ADMISSION_JOIN_KEY, $key->scope_jurisdiction_id);

            $request->update([
                'status' => ClusterAdoptionRequest::STATUS_ADMITTED,
                'join_key_handle' => $key->handle,
                'cluster_membership_id' => $membership->id,
            ]);

            $this->audit->append('mirror', 'mirror.admitted', [
                'mirror_server_id' => $applicantServerId,
                'join_key_handle' => $key->handle,
                'membership_id' => $membership->id,
            ], 'WF-JUR-06');

            return $membership;
        });
    }

    /**
     * Open (idempotently) OUR `host` membership for an admitted mirror peer — we
     * host it, it copies us. Many allowed (no one-active-mirror constraint on
     * `host`); goes straight to live, since hosting is passive.
     */
    public function openHostMembership(FederationPeer $mirror, string $admissionMethod, ?string $scopeJurisdictionId = null): ClusterMembership
    {
        return ClusterMembership::query()->firstOrCreate(
            ['peer_id' => $mirror->id, 'role' => ClusterMembership::ROLE_HOST],
            [
                'state' => ClusterMembership::STATE_LIVE,
                'admission_method' => $admissionMethod,
                'scope_jurisdiction_id' => $scopeJurisdictionId,
            ],
        );
    }

    /**
     * MIRROR side of adoption (the one-step join). POST the host's /adopt with our
     * identity + the join key, pin the host, backfill its full corpus in bounded
     * signed pages, then flip into read-only-mirror mode. Throws on refusal.
     */
    public function joinHost(string $hostUrl, string $plaintextKey): ClusterMembership
    {
        $this->identity->ensureIdentity();
        $nonce = bin2hex(random_bytes(16));

        $response = $this->client->post($hostUrl, '/api/federation/adopt', [
            'public_key' => $this->identity->publicKey(),
            'key' => $plaintextKey,
            'nonce' => $nonce,
            'url' => config('cga.federation_self_url'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Adoption refused by {$hostUrl} (HTTP {$response->status()}).");
        }

        $body = (array) $response->json();
        $hostServerId = (string) ($body['host_server_id'] ?? '');
        $hostPublicKey = (string) ($body['host_public_key'] ?? '');
        $scope = $body['scope_jurisdiction_id'] ?? null;

        if ($hostServerId === '' || $hostPublicKey === '') {
            throw new RuntimeException('Adoption response from the host was incomplete.');
        }

        $host = $this->pinHost($hostServerId, $hostPublicKey, ['url' => $hostUrl]);

        $membership = $this->openMirrorMembership($host, ClusterMembership::ADMISSION_JOIN_KEY, is_string($scope) ? $scope : null);
        $membership->update(['state' => ClusterMembership::STATE_SYNCING]);

        // Pull the host's full public corpus (chunked, resumable, signed pages).
        $cursor = $this->backfill->drain($host, $membership);

        if ($cursor->status === \App\Models\SyncCursor::STATUS_COMPLETE) {
            $this->markMirrorLive($membership, $hostServerId);
        }

        return $membership->refresh();
    }

    /**
     * Leave the cluster: stop being a read-only mirror. Clears mirror mode (the
     * engine write-guard switches off) and departs the active mirror membership.
     */
    public function leave(): void
    {
        $settings = InstanceSettings::current();
        $hostServerId = $settings->mirror_of_server_id;
        $settings->mirror_of_server_id = null;
        $settings->mirror_adopted_at = null;
        $settings->save();

        ClusterMembership::query()
            ->where('role', ClusterMembership::ROLE_MIRROR)
            ->whereNotIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
            ->update(['state' => ClusterMembership::STATE_DEPARTED]);

        $this->audit->append('mirror', 'mirror.left', ['host_server_id' => $hostServerId], 'WF-JUR-06');
    }
}
