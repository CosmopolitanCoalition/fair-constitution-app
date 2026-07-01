<?php

namespace App\Services\Mirror;

use App\Models\ClusterAdoptionRequest;
use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\FoundationSyncCursor;
use App\Services\AuditService;
use App\Services\Federation\FederationClient;
use App\Services\Federation\FoundationDrainService;
use App\Services\Federation\GeodataSeedTransportService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use App\Services\Federation\SyncProgressService;
use App\Services\MapDataImportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        private readonly GeodataSeedTransportService $seed,
        private readonly MapDataImportService $import,
        private readonly SyncProgressService $progress,
        private readonly FoundationDrainService $foundationDrain,
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

        // One mesh = one game: a mirror that was never deliberately named adopts the HOST's display
        // name on going live, so a citizen sees "United Earth" — the game — not "Unnamed Instance" —
        // the node. A node the operator DID name keeps its name (only the untouched default is
        // replaced); the node identity for mesh management stays server_id, never the display name.
        if ($settings->instance_name === 'Unnamed Instance') {
            $hostName = (string) (FederationPeer::query()->where('server_id', $hostServerId)->value('name') ?? '');
            if ($hostName !== '' && $hostName !== 'Unnamed Instance') {
                $settings->instance_name = $hostName;
            }
        }

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
        array $negotiation = [],
    ): ClusterMembership {
        if ($applicantServerId === '' || $applicantPublicKey === '' || $nonce === '' || $plaintextKey === '') {
            throw new AdoptionRejected('incomplete_adoption_request', 422);
        }
        if ($applicantServerId === $this->identity->serverId()) {
            throw new AdoptionRejected('refuse_self', 422);
        }

        return DB::transaction(function () use ($applicantServerId, $applicantPublicKey, $nonce, $plaintextKey, $applicantUrl, $negotiation): ClusterMembership {
            // Anti-replay: a duplicate (applicant, nonce) raises the unique index.
            $request = ClusterAdoptionRequest::create(array_merge([
                'applicant_server_id' => $applicantServerId,
                'applicant_public_key' => $applicantPublicKey,
                'nonce' => $nonce,
                'admission_method' => ClusterMembership::ADMISSION_JOIN_KEY,
                'status' => ClusterAdoptionRequest::STATUS_PENDING,
            ], $this->negotiationColumns($negotiation, $applicantUrl)));

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
    public function joinHost(string $hostUrl, string $plaintextKey, array $negotiation = [], bool $sync = true): ClusterMembership
    {
        $this->identity->ensureIdentity();
        $nonce = bin2hex(random_bytes(16));

        $response = $this->client->post($hostUrl, '/api/federation/adopt', array_merge([
            'public_key' => $this->identity->publicKey(),
            'key' => $plaintextKey,
            'nonce' => $nonce,
            'url' => config('cga.federation_self_url'),
        ], $this->negotiationBody($negotiation)));

        if (! $response->successful()) {
            throw new RuntimeException("Adoption refused by {$hostUrl} (HTTP {$response->status()}).");
        }

        return $this->finalizeAdmission((array) $response->json(), $hostUrl, ClusterMembership::ADMISSION_JOIN_KEY, $sync);
    }

    /**
     * MIRROR side, keyless (G3): request adoption from a host. Returns the live
     * membership if the host has ALREADY vouched us (200), or null when the
     * request is queued for the host operator (202). Re-poll until admitted.
     */
    public function requestJoin(string $hostUrl, array $negotiation = [], bool $sync = true): ?ClusterMembership
    {
        $this->identity->ensureIdentity();

        $response = $this->client->post($hostUrl, '/api/federation/adopt', array_merge([
            'public_key' => $this->identity->publicKey(),
            'nonce' => bin2hex(random_bytes(16)),
            'url' => config('cga.federation_self_url'),
        ], $this->negotiationBody($negotiation)));

        if ($response->status() === 202) {
            return null; // queued — the host operator must approve
        }
        if (! $response->successful()) {
            throw new RuntimeException("Adoption request refused by {$hostUrl} (HTTP {$response->status()}).");
        }

        return $this->finalizeAdmission((array) $response->json(), $hostUrl, ClusterMembership::ADMISSION_REQUEST, $sync);
    }

    /**
     * MIRROR side: finalize an admitted adoption — pin the host, backfill its
     * corpus in bounded signed pages, and flip into read-only-mirror mode on
     * catch-up. Shared by the keyed (joinHost) and keyless (requestJoin) paths.
     *
     * @param  array<string,mixed>  $body
     */
    private function finalizeAdmission(array $body, string $hostUrl, string $admissionMethod, bool $sync = true): ClusterMembership
    {
        $hostServerId = (string) ($body['host_server_id'] ?? '');
        $hostPublicKey = (string) ($body['host_public_key'] ?? '');
        $scope = $body['scope_jurisdiction_id'] ?? null;

        if ($hostServerId === '' || $hostPublicKey === '') {
            throw new RuntimeException('Adoption response from the host was incomplete.');
        }

        // Pin the host's display name too (an older host omits it — fine, the peer keeps its name).
        $attrs = ['url' => $hostUrl];
        if (is_string($body['host_name'] ?? null) && $body['host_name'] !== '') {
            $attrs['name'] = (string) $body['host_name'];
        }
        $host = $this->pinHost($hostServerId, $hostPublicKey, $attrs);

        $membership = $this->openMirrorMembership($host, $admissionMethod, is_string($scope) ? $scope : null);
        $membership->update(['state' => ClusterMembership::STATE_SYNCING]);

        // Engage the read-only write-guard for the ENTIRE admission window (seed + drain), BEFORE either
        // runs — so this box is authoritative for nothing and fail-CLOSED throughout, even mid-sync or
        // after a crash (a partial seed leaves jurisdictions unowned = NULL = "ours"; the guard makes that
        // refuse writes, not accept them). Safe: the audit drain applies records via raw mirrorRecord, never
        // the write-guarded ConstitutionalEngine, so the early guard never blocks the backfill. markMirrorLive
        // later finalizes (mirror_adopted_at + membership LIVE); setting mirror_of_server_id here is idempotent.
        $settings = InstanceSettings::current();
        if ($settings->mirror_of_server_id !== $hostServerId) {
            $settings->mirror_of_server_id = $hostServerId;
            $settings->save();
        }

        // The browser path admits synchronously (so a bad/exhausted join key fails fast, in-band)
        // but defers the long seed + drain to ClusterJoinJob, which the operator's page watches via
        // SyncProgressService. The membership is left SYNCING for the job to pick up.
        if (! $sync) {
            return $membership->refresh();
        }

        return $this->runSync($membership, $host, $hostServerId);
    }

    /**
     * The resumable, idempotent SYNC TAIL shared by every admission path (the
     * synchronous CLI join, and the deferred ClusterJoinJob the browser dispatches):
     * seed FIRST — pull + apply the host's geodata FOUNDATION (cosmic + jurisdictions
     * + rasters + base constitutional_settings) BEFORE the audit drain, so the
     * replayed institutions have their jurisdiction rows to attach to (identity-safe;
     * keeps THIS box's server_id + keypair) — then drain the host's full public corpus
     * in chunked, resumable, signed pages, and mark the mirror live on catch-up.
     *
     * Phase markers go to SyncProgressService so a separate poll request can render
     * live progress; a marker failure can never stall the drain (the service swallows
     * its own write errors).
     */
    private function runSync(ClusterMembership $membership, FederationPeer $host, string $hostServerId): ClusterMembership
    {
        $this->progress->begin($membership);

        try {
            $this->seedFromHost($host, $membership);

            $this->progress->startDrain($membership);
            $cursor = $this->backfill->drain($host, $membership);

            if ($cursor->status === \App\Models\SyncCursor::STATUS_COMPLETE) {
                $this->progress->completeDrain($membership);
                $this->markMirrorLive($membership, $hostServerId);
                $this->progress->finish($membership);
            } elseif ($cursor->status === \App\Models\SyncCursor::STATUS_ABORTED) {
                $this->progress->fail($membership, 'Audit drain aborted: '.((string) ($cursor->abort_reason ?? 'unknown')));
            }
        } catch (\Throwable $e) {
            $this->progress->fail($membership, $e->getMessage());
            throw $e;
        }

        return $membership->refresh();
    }

    /**
     * Run the resumable sync tail for an already-admitted mirror membership — the
     * entry point ClusterJoinJob calls off the request thread. Flips the membership
     * to SYNCING, resolves its pinned host, and drains. Throws if the membership has
     * no host peer.
     */
    public function syncMembership(ClusterMembership $membership): ClusterMembership
    {
        $host = $membership->peer;
        if ($host === null) {
            throw new RuntimeException('Mirror membership has no host peer to sync from.');
        }

        $membership->update(['state' => ClusterMembership::STATE_SYNCING]);

        return $this->runSync($membership, $host, (string) $host->server_id);
    }

    /**
     * MIRROR side (roles-campaign Phase 0b): pull + apply the host's geodata FOUNDATION before
     * the audit drain. Idempotent — once seeded_at is stamped, a re-join skips straight past.
     *
     * This is the ONE place a mirror writes authoritative_server_id, and it is deliberate: the
     * seed's jurisdiction rows ARE the host's. Rows the donor was authoritative for arrive with a
     * NULL authoritative_server_id (the donor's "mine" sentinel) and are stamped to the host; rows
     * the donor itself mirrored from a third server keep that third server's id. After this the
     * mirror is still authoritative for NOTHING — every imported jurisdiction names another server.
     */
    public function seedFromHost(FederationPeer $host, ClusterMembership $membership): void
    {
        if ($membership->seeded_at !== null) {
            $this->progress->completeDownload($membership);
            $this->progress->completeImport($membership);

            return; // this host's seed is already applied
        }

        // Seed transport (seed redesign). 'paginated' drains the foundation a signed KEYSET page at
        // a time, UPSERTing per table with a resumable cursor — visible, crash-resumable, and
        // non-destructive (never clears the identity / append-only tables). Default 'tarball' keeps
        // the legacy pg_restore path below, byte-identical, as the fallback.
        if (config('cga.federation_seed_transport') === 'paginated') {
            $this->seedFromHostPaginated($host, $membership);

            return;
        }

        $this->progress->startDownload($membership);
        $pulled = $this->seed->pull($host, $membership);    // verified tarball, or null if the host has no seed
        $this->progress->completeDownload($membership);
        if ($pulled === null) {
            $this->progress->completeImport($membership);

            return; // host advertises no seed — a read-only mirror still admits (foundation via ETL/bundle/posture)
        }

        $this->progress->startImport($membership);
        $this->import->importSeedFromFile($pulled['path']); // D1 identity-safe load + cosmic re-point
        $this->progress->completeImport($membership);

        $stamped = DB::table('jurisdictions')
            ->whereNull('authoritative_server_id')
            ->update(['authoritative_server_id' => $host->server_id]);

        // Fail-CLOSED: after the stamp NO jurisdiction may remain unowned — a NULL authoritative_server_id
        // resolves to "ours" (AuthorityResolver), which would make this mirror wrongly authoritative for the
        // whole imported world. Refuse to finish seeding (and to stamp seeded_at) if any slipped through.
        $unowned = (int) DB::table('jurisdictions')->whereNull('authoritative_server_id')->count();
        if ($unowned > 0) {
            throw new RuntimeException("Seed authority stamp left {$unowned} jurisdiction(s) unowned — refusing to finish seeding.");
        }

        $membership->forceFill(['seeded_at' => now()])->save();
        $this->seed->discardIncoming($membership);

        $this->audit->append('mirror', 'mirror.seeded', [
            'host_server_id' => $host->server_id,
            'seed_version' => $membership->seed_version,
            'jurisdictions_attributed' => $stamped,
        ], 'WF-JUR-06');
    }

    /**
     * MIRROR side (seed redesign): seed the geodata FOUNDATION by the PAGINATED transport — drain each
     * foundation table a signed KEYSET page at a time, UPSERTing with a resumable per-table cursor.
     *
     * Identity safety is structural, not procedural: the drain only ever UPSERTs the geodata
     * foundation, so instance_settings (this box's server_id + keypair + cosmic placement) is NEVER
     * touched — the tarball path's detach/clear/re-point dance is unnecessary because nothing is
     * cleared. cosmic_addresses dedupes on slug (we keep our own cosmic uuids). A re-run is idempotent
     * (completed tables short-circuit; ON CONFLICT DO NOTHING).
     *
     * Per-table progress is read live off the committed foundation_sync_cursors by SyncProgressService.
     * The fail-closed seeded_at gate refines from the tarball's all-or-nothing to PER-TABLE-COMPLETE:
     * seeded_at is stamped only when every foundation table's cursor reached COMPLETE.
     */
    private function seedFromHostPaginated(FederationPeer $host, ClusterMembership $membership): void
    {
        $this->progress->startImport($membership);

        $summary = $this->foundationDrain->drain($host);

        // Per-table-complete gate (fail-closed) — every foundation table must have fully drained.
        foreach ($summary as $table => $s) {
            if (($s['status'] ?? null) !== FoundationSyncCursor::STATUS_COMPLETE) {
                $this->progress->fail($membership, "Foundation drain for {$table} did not complete (status ".($s['status'] ?? 'unknown').').');
                throw new RuntimeException("Foundation drain for '{$table}' did not complete (status ".($s['status'] ?? 'unknown').') — re-run the join to resume.');
            }
        }

        // Authority stamp — identical to the tarball path. Rows the donor was authoritative for arrive
        // with a NULL authoritative_server_id (the donor's "mine" sentinel) and are stamped to the host;
        // rows the donor itself mirrored keep their third-server id. Fail-closed: none may remain unowned.
        $stamped = DB::table('jurisdictions')
            ->whereNull('authoritative_server_id')
            ->update(['authoritative_server_id' => $host->server_id]);

        $unowned = (int) DB::table('jurisdictions')->whereNull('authoritative_server_id')->count();
        if ($unowned > 0) {
            $this->progress->fail($membership, "Seed authority stamp left {$unowned} jurisdiction(s) unowned.");
            throw new RuntimeException("Seed authority stamp left {$unowned} jurisdiction(s) unowned — refusing to finish seeding.");
        }

        DB::table('instance_settings')->update(['map_accepted_at' => now()]);

        $this->progress->completeDownload($membership); // the paginated path fuses download+import per page
        $this->progress->completeImport($membership);

        $membership->forceFill(['seeded_at' => now()])->save();

        // The foundation changed wholesale → derived map caches are stale; flush + re-warm (best-effort),
        // mirroring the tarball path's post-import behaviour.
        try {
            Cache::flush();
            \App\Jobs\PrewarmRasterTilesJob::dispatch();
            \App\Jobs\PrewarmGeojsonCachesJob::dispatch();
        } catch (\Throwable $e) {
            Log::warning('post-paginated-seed prewarm dispatch failed', ['error' => $e->getMessage()]);
        }

        $this->audit->append('mirror', 'mirror.seeded', [
            'host_server_id' => $host->server_id,
            'transport' => 'paginated',
            'jurisdictions_attributed' => $stamped,
            'tables' => array_keys($summary),
        ], 'WF-JUR-06');
    }

    /**
     * MIRROR side: resume an in-progress join WITHOUT re-running /adopt. Used when this box is already a
     * pinned mirror (a prior attempt timed out mid-sync) — re-adopting would burn a single-use join key
     * and a different host would collide on the one-active-mirror index. Re-applies the seed (idempotent
     * via seeded_at) and re-drains the corpus from its cursor, marking live on catch-up. Throws if there
     * is no active mirror membership to resume.
     */
    public function resumeJoin(): ClusterMembership
    {
        $membership = $this->activeMirrorMembership();

        if ($membership === null || $membership->peer === null) {
            throw new RuntimeException('No active mirror membership to resume — re-join from the host.');
        }

        return $this->syncMembership($membership);
    }

    /** The current (non-departed/rejected) mirror membership, or null — the one a join/resume operates on. */
    public function activeMirrorMembership(): ?ClusterMembership
    {
        return ClusterMembership::query()
            ->where('role', ClusterMembership::ROLE_MIRROR)
            ->whereNotIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
            ->latest('updated_at')
            ->first();
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
    public function requestAdoption(string $applicantServerId, string $applicantPublicKey, ?string $applicantUrl = null, array $negotiation = []): ClusterAdoptionRequest
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

        $request = ClusterAdoptionRequest::create(array_merge([
            'applicant_server_id' => $applicantServerId,
            'applicant_public_key' => $applicantPublicKey,
            'nonce' => bin2hex(random_bytes(16)),
            'admission_method' => ClusterMembership::ADMISSION_REQUEST,
            'status' => ClusterAdoptionRequest::STATUS_PENDING,
        ], $this->negotiationColumns($negotiation, $applicantUrl)));

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

    /**
     * G3c — the negotiation fields the MIRROR sends in its /adopt body. `co_member`
     * is ADVISORY: the host operator sees the applicant intends the governed flip,
     * but admission still produces a plain read-only mirror. The applicant labels
     * itself with its instance name unless a label is supplied.
     *
     * @param  array<string,mixed>  $negotiation
     * @return array<string,mixed>
     */
    private function negotiationBody(array $negotiation): array
    {
        return [
            'applicant_name' => ($negotiation['applicant_name'] ?? null) ?: InstanceSettings::current()->instance_name,
            'requested_relation' => $negotiation['requested_relation'] ?? null,
            'requested_scope_jurisdiction_id' => $negotiation['requested_scope_jurisdiction_id'] ?? null,
            'note' => $negotiation['note'] ?? null,
        ];
    }

    /**
     * G3c — the negotiation columns the HOST persists on the adoption request. The
     * relation is sanitized to the advisory enum (anything else → null) so a peer
     * cannot poison the CHECK constraint.
     *
     * @param  array<string,mixed>  $negotiation
     * @return array<string,mixed>
     */
    private function negotiationColumns(array $negotiation, ?string $applicantUrl): array
    {
        $relation = $negotiation['requested_relation'] ?? null;

        return [
            'requested_relation' => in_array($relation, ['mirror', 'co_member'], true) ? $relation : null,
            'requested_scope_jurisdiction_id' => ($negotiation['requested_scope_jurisdiction_id'] ?? null) ?: null,
            'applicant_name' => ($negotiation['applicant_name'] ?? null) ?: null,
            'applicant_url' => $applicantUrl ?: null,
            'note' => ($negotiation['note'] ?? null) ?: null,
        ];
    }
}
