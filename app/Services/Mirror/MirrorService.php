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

        return $this->finalizeAdmission((array) $response->json(), $hostUrl, ClusterMembership::ADMISSION_JOIN_KEY);
    }

    /**
     * MIRROR side, keyless (G3): request adoption from a host. Returns the live
     * membership if the host has ALREADY vouched us (200), or null when the
     * request is queued for the host operator (202). Re-poll until admitted.
     */
    public function requestJoin(string $hostUrl): ?ClusterMembership
    {
        $this->identity->ensureIdentity();

        $response = $this->client->post($hostUrl, '/api/federation/adopt', [
            'public_key' => $this->identity->publicKey(),
            'nonce' => bin2hex(random_bytes(16)),
            'url' => config('cga.federation_self_url'),
        ]);

        if ($response->status() === 202) {
            return null; // queued — the host operator must approve
        }
        if (! $response->successful()) {
            throw new RuntimeException("Adoption request refused by {$hostUrl} (HTTP {$response->status()}).");
        }

        return $this->finalizeAdmission((array) $response->json(), $hostUrl, ClusterMembership::ADMISSION_REQUEST);
    }

    /**
     * MIRROR side: finalize an admitted adoption — pin the host, backfill its
     * corpus in bounded signed pages, and flip into read-only-mirror mode on
     * catch-up. Shared by the keyed (joinHost) and keyless (requestJoin) paths.
     *
     * @param  array<string,mixed>  $body
     */
    private function finalizeAdmission(array $body, string $hostUrl, string $admissionMethod): ClusterMembership
    {
        $hostServerId = (string) ($body['host_server_id'] ?? '');
        $hostPublicKey = (string) ($body['host_public_key'] ?? '');
        $scope = $body['scope_jurisdiction_id'] ?? null;

        if ($hostServerId === '' || $hostPublicKey === '') {
            throw new RuntimeException('Adoption response from the host was incomplete.');
        }

        $host = $this->pinHost($hostServerId, $hostPublicKey, ['url' => $hostUrl]);

        $membership = $this->openMirrorMembership($host, $admissionMethod, is_string($scope) ? $scope : null);
        $membership->update(['state' => ClusterMembership::STATE_SYNCING]);

        // Pull the host's full public corpus (chunked, resumable, signed pages).
        $cursor = $this->backfill->drain($host, $membership);

        if ($cursor->status === \App\Models\SyncCursor::STATUS_COMPLETE) {
            $this->markMirrorLive($membership, $hostServerId);
        }

        return $membership->refresh();
    }

    /**
     * MIRROR side (G3c): petition our host for read-write authority over a
     * jurisdiction subtree. Composes + sends a signed S2S request to the host's
     * read-write intake (federation.signed:pinned — the host already pinned our
     * key when it admitted us). The host RECORDS the petition; its government
     * decides (Art. V §7). This grants NOTHING locally — a mirror stays
     * authoritative for nothing until a governed authority-flip commits. Returns
     * the host's intake acknowledgement; throws if this instance is not a mirror
     * or the host refuses.
     *
     * @return array{request_id:?string,state:?string}
     */
    public function petitionReadWrite(string $rootJurisdictionId, ?string $note = null): array
    {
        if ($rootJurisdictionId === '') {
            throw new RuntimeException('A jurisdiction is required for a read-write petition.');
        }
        if (! $this->isMirror()) {
            throw new RuntimeException('This instance is not a mirror — join a cluster before requesting read-write authority.');
        }

        $membership = ClusterMembership::query()
            ->where('role', ClusterMembership::ROLE_MIRROR)
            ->whereNotIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
            ->latest('updated_at')
            ->first();

        $hostUrl = $membership?->peer?->url;

        if ($hostUrl === null || $hostUrl === '') {
            throw new RuntimeException('The cluster host URL is unknown — cannot reach the host.');
        }

        $this->identity->ensureIdentity();

        $response = $this->client->post($hostUrl, '/api/federation/request-read-write', [
            'root_jurisdiction_id' => $rootJurisdictionId,
            'note' => $note,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("The host refused the read-write petition (HTTP {$response->status()}).");
        }

        $body = (array) $response->json();

        return [
            'request_id' => isset($body['request_id']) ? (string) $body['request_id'] : null,
            'state'      => isset($body['state']) ? (string) $body['state'] : null,
        ];
    }

    /**
     * HOST side, keyless (G3): find-or-create a PENDING adoption request for an
     * applicant (idempotent — repeated polls return the same row). The operator
     * later approves or rejects it from the review queue.
     */
    public function requestAdoption(string $applicantServerId, string $applicantPublicKey, ?string $applicantUrl = null): ClusterAdoptionRequest
    {
        if ($applicantServerId === '' || $applicantPublicKey === '') {
            throw new AdoptionRejected('incomplete_adoption_request', 422);
        }
        if ($applicantServerId === $this->identity->serverId()) {
            throw new AdoptionRejected('refuse_self', 422);
        }

        $existing = ClusterAdoptionRequest::query()
            ->where('applicant_server_id', $applicantServerId)
            ->whereIn('status', [ClusterAdoptionRequest::STATUS_PENDING, ClusterAdoptionRequest::STATUS_ADMITTED])
            ->orderByDesc('created_at')->first();

        if ($existing !== null) {
            return $existing;
        }

        $request = ClusterAdoptionRequest::create([
            'applicant_server_id' => $applicantServerId,
            'applicant_public_key' => $applicantPublicKey,
            'nonce' => bin2hex(random_bytes(16)),
            'admission_method' => ClusterMembership::ADMISSION_REQUEST,
            'status' => ClusterAdoptionRequest::STATUS_PENDING,
        ]);

        $this->audit->append('mirror', 'mirror.adoption_requested',
            ['mirror_server_id' => $applicantServerId, 'request_id' => $request->id], 'WF-JUR-06');

        return $request;
    }

    /**
     * HOST side: operator approves a pending request — pin the applicant as our
     * mirror (relation=mirror) and host it. Idempotent on an already-admitted
     * request; refuses an already-rejected one.
     */
    public function approveRequest(string $requestId): ClusterMembership
    {
        $request = ClusterAdoptionRequest::query()->findOrFail($requestId);

        if ($request->status === ClusterAdoptionRequest::STATUS_ADMITTED && $request->cluster_membership_id !== null) {
            return ClusterMembership::query()->findOrFail($request->cluster_membership_id);
        }
        if ($request->status === ClusterAdoptionRequest::STATUS_REJECTED) {
            throw new AdoptionRejected('request_already_rejected', 409);
        }

        return DB::transaction(function () use ($request): ClusterMembership {
            $peer = $this->peers->upsertTrustedPeer(
                $request->applicant_server_id, $request->applicant_public_key, [],
                FederationPeer::RELATION_MIRROR, 'mirror_vouched'
            );
            $membership = $this->openHostMembership($peer, ClusterMembership::ADMISSION_REQUEST, null);

            $request->update([
                'status' => ClusterAdoptionRequest::STATUS_ADMITTED,
                'cluster_membership_id' => $membership->id,
            ]);

            $this->audit->append('mirror', 'mirror.request_approved', [
                'mirror_server_id' => $request->applicant_server_id,
                'request_id' => $request->id,
                'membership_id' => $membership->id,
            ], 'WF-JUR-06');

            return $membership;
        });
    }

    /** HOST side: operator rejects a pending request. Idempotent. */
    public function rejectRequest(string $requestId): void
    {
        $request = ClusterAdoptionRequest::query()->findOrFail($requestId);

        if ($request->status === ClusterAdoptionRequest::STATUS_REJECTED) {
            return;
        }

        $request->update(['status' => ClusterAdoptionRequest::STATUS_REJECTED]);

        $this->audit->append('mirror', 'mirror.request_rejected',
            ['mirror_server_id' => $request->applicant_server_id, 'request_id' => $request->id], 'WF-JUR-06');
    }

    /**
     * HOST side: the operator's pending-request review queue.
     *
     * @return \Illuminate\Support\Collection<int,ClusterAdoptionRequest>
     */
    public function pendingRequests(): \Illuminate\Support\Collection
    {
        return ClusterAdoptionRequest::query()
            ->where('status', ClusterAdoptionRequest::STATUS_PENDING)
            ->orderBy('created_at')->get();
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
