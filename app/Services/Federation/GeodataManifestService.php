<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Models\GeodataDatasetManifest;
use App\Services\AuditService;

/**
 * Phase G (G3c — decision N3) — the GEODATA_ORIGIN signed-dataset MANIFEST channel.
 *
 * Geospatial datasets are large + license-bound, so they ride a SEPARATE, optional,
 * signed channel — never the Full-Faith-&-Credit audit tail a mirror pulls. This
 * service: self-PUBLISHES a manifest for a dataset we host (signed by THIS instance),
 * SERVES it as a wire object, PULLS one from an upstream, and INGESTS it — verifying
 * the ORIGIN's signature against the origin's pinned key (never the relayer's, like
 * ingestAnnounce / DirectoryService). Records the manifest LEDGER only; the raster
 * BYTES transport lands with Phase H. No private data ever rides this channel.
 */
class GeodataManifestService
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly FederationClient $client,
    ) {}

    /** The byte-stable canonical the ORIGIN instance signs / a verifier reconstructs. */
    public function canonical(string $dataset, string $version, string $sha256, string $license, int $sizeBytes, string $originServerId): string
    {
        return AuditService::canonicalJson([
            'dataset'          => $dataset,
            'version'          => $version,
            'sha256'           => $sha256,
            'license'          => $license,
            'size_bytes'       => $sizeBytes,
            'origin_server_id' => $originServerId,
        ]);
    }

    /**
     * Self-publish a manifest for a dataset we host, signed by THIS instance.
     * Idempotent on (dataset, version, self) — re-publishing refreshes the row.
     */
    public function publish(string $dataset, string $version, string $sha256, string $license, int $sizeBytes): GeodataDatasetManifest
    {
        $origin = $this->identity->serverId();
        $signature = $this->identity->sign($this->canonical($dataset, $version, $sha256, $license, $sizeBytes, $origin));

        $manifest = GeodataDatasetManifest::query()->firstOrNew([
            'dataset'          => $dataset,
            'version'          => $version,
            'origin_server_id' => $origin,
        ]);
        $manifest->fill([
            'sha256'     => $sha256,
            'license'    => $license,
            'size_bytes' => $sizeBytes,
            'signature'  => $signature,
            'fetched_at' => null, // self-published, not fetched
        ]);
        $manifest->save();

        return $manifest;
    }

    /**
     * The wire form of the latest self-published manifest for a dataset (or null).
     *
     * @return array<string,mixed>|null
     */
    public function serveWire(string $dataset): ?array
    {
        $manifest = GeodataDatasetManifest::query()
            ->where('dataset', $dataset)
            ->where('origin_server_id', $this->identity->serverId())
            ->whereNull('deleted_at')
            ->orderByDesc('version')
            ->orderByDesc('created_at')
            ->first();

        if ($manifest === null) {
            return null;
        }

        return [
            'dataset'          => (string) $manifest->dataset,
            'version'          => (string) $manifest->version,
            'sha256'           => (string) $manifest->sha256,
            'license'          => (string) $manifest->license,
            'size_bytes'       => (int) $manifest->size_bytes,
            'origin_server_id' => (string) $manifest->origin_server_id,
            'signature'        => (string) $manifest->signature,
        ];
    }

    /**
     * MIRROR side: pull a dataset manifest from an upstream (signed GET), verify
     * the origin signature, and record it. Returns the recorded manifest or null.
     */
    public function pullFrom(FederationPeer $origin, string $dataset): ?GeodataDatasetManifest
    {
        $response = $this->client->get((string) $origin->url, '/api/federation/geodata/manifest', ['dataset' => $dataset]);

        if (! $response->successful()) {
            return null;
        }

        return $this->ingest((array) $response->json(), $origin);
    }

    /**
     * Ingest a fetched manifest, verifying the ORIGIN's signature against the
     * origin's pinned key (the relayer cannot forge it). Records it with
     * fetched_at; drops (returns null) anything it cannot authenticate.
     *
     * @param  array<string,mixed>  $wire
     */
    public function ingest(array $wire, FederationPeer $from): ?GeodataDatasetManifest
    {
        $dataset = (string) ($wire['dataset'] ?? '');
        $version = (string) ($wire['version'] ?? '');
        $sha256 = (string) ($wire['sha256'] ?? '');
        $license = (string) ($wire['license'] ?? '');
        $sizeBytes = (int) ($wire['size_bytes'] ?? 0);
        $origin = (string) ($wire['origin_server_id'] ?? '');
        $signature = (string) ($wire['signature'] ?? '');

        if ($dataset === '' || $version === '' || $sha256 === '' || $origin === '') {
            return null;
        }

        $originKey = $this->serverKey($origin, $from);
        if ($originKey === null) {
            return null; // we hold no key to authenticate the named origin
        }
        if (! InstanceIdentityService::verify(
            $originKey,
            $this->canonical($dataset, $version, $sha256, $license, $sizeBytes, $origin),
            $signature,
        )) {
            return null; // tampered, or not actually signed by the named origin
        }

        $manifest = GeodataDatasetManifest::query()->firstOrNew([
            'dataset'          => $dataset,
            'version'          => $version,
            'origin_server_id' => $origin,
        ]);
        $manifest->fill([
            'sha256'     => $sha256,
            'license'    => $license,
            'size_bytes' => $sizeBytes,
            'signature'  => $signature,
            'fetched_at' => now(),
        ]);
        $manifest->save();

        return $manifest;
    }

    /** Resolve the public key for the server that signed a manifest (self / relayer / pinned peer). */
    private function serverKey(string $serverId, FederationPeer $from): ?string
    {
        if ($serverId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }
        if ($serverId === (string) $from->server_id && $from->public_key !== null) {
            return (string) $from->public_key;
        }

        $peer = FederationPeer::query()->where('server_id', $serverId)->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }
}
