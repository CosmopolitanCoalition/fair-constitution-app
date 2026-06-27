<?php

namespace App\Services\Federation;

use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Services\AuditService;
use App\Services\MapDataExportService;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * Roles-onboarding campaign, Phase 0b — the geodata SEED bytes transport.
 *
 * The geodata FOUNDATION (cosmic_addresses, jurisdictions, worldpop_rasters,
 * geoboundary_metadata, base constitutional_settings) is bulk-loaded by the ETL and
 * never rides the Full-Faith-&-Credit audit tail — yet a joining mirror needs it
 * BEFORE it replays the audit corpus (the replayed institutions reference jurisdiction
 * rows that must already exist). This service is the missing "raster BYTES transport"
 * the GeodataManifestService docblock reserved:
 *
 *   DONOR  publishSeed() — export the foundation subset to a tarball + publish a signed manifest.
 *          readRange()   — range-serve that tarball's bytes to a pinned peer (the seed-page route).
 *   JOINER pull()        — pull the manifest (origin-signed), then range-pull the bytes resumably,
 *                          verifying the assembled file against the manifest's SIGNED sha256 before use.
 *
 * Integrity model: the byte pages are unsigned (an opaque range of a tarball); trust comes
 * from (a) the request being `federation.signed` (a pinned peer) AND (b) the assembled file
 * matching the origin-signed sha256 in the manifest. A relayer that flips any byte fails the
 * digest → fail-closed. No private data ever rides this channel — the seed EXCLUDES
 * instance_settings (identity) and every institutional table (those arrive by audit-replay).
 */
class GeodataSeedTransportService
{
    /** The one dataset the seed channel serves today (the Earth foundation). */
    public const DATASET = 'seed:earth';

    /**
     * The foundation tables a seed carries — full geodata, NO identity, NO institutions.
     * Cosmic address syncs (it is part of the shared game). instance_settings is NEVER
     * included (donor identity); institutional tables are NEVER included (they replay).
     *
     * @var list<string>
     */
    public const FOUNDATION_TABLES = [
        'cosmic_addresses',
        'jurisdictions',
        'worldpop_rasters',
        'geoboundary_metadata',
        'constitutional_settings',
    ];

    public function __construct(
        private readonly GeodataManifestService $manifests,
        private readonly MapDataExportService $exporter,
        private readonly FederationClient $client,
        private readonly AuditService $audit,
    ) {}

    // ── DONOR ───────────────────────────────────────────────────────────────────

    /**
     * Build the seed tarball (foundation subset) and publish a signed manifest for it.
     * Re-publishing the same version rebuilds the file + refreshes the manifest row.
     *
     * @return array{dataset:string,version:string,sha256:string,size_bytes:int,path:string}
     */
    public function publishSeed(string $version, ?string $license = null): array
    {
        $license ??= 'geoBoundaries CC-BY-4.0 + WorldPop CC-BY-4.0';
        $path = $this->seedPath($version);
        @mkdir(dirname($path), 0775, true);

        // Foundation subset only. MapDataExportService::resolveTables intersects against its
        // curated allowlist, so a caller cannot dump arbitrary tables; instance_settings is
        // excluded simply by not listing it. export() writes <tmpDir>/<stagingId>.tar.gz.
        $built = $this->exporter->export(
            tmpDir: dirname($path),
            stagingId: $this->stagingId($version),
            tables: self::FOUNDATION_TABLES,
        );
        if ($built !== $path && is_file($built)) {
            @rename($built, $path);
        }
        if (! is_file($path)) {
            throw new RuntimeException("Seed export did not produce a tarball at {$path}.");
        }

        $sha = hash_file('sha256', $path) ?: '';
        $size = (int) filesize($path);

        $this->manifests->publish(self::DATASET, $version, $sha, $license, $size);

        $this->audit->append('federation', 'geodata.seed_published', [
            'dataset' => self::DATASET, 'version' => $version, 'sha256' => $sha, 'size_bytes' => $size,
        ], 'WF-JUR-06');

        return ['dataset' => self::DATASET, 'version' => $version, 'sha256' => $sha, 'size_bytes' => $size, 'path' => $path];
    }

    /**
     * DONOR: read a byte range [$offset, $offset+$len) of the latest published seed tarball
     * for $dataset. Returns null when no seed is published / the file is missing. Clamps the
     * length to the file end; an offset at/past EOF yields an empty byte string.
     *
     * @return array{bytes:string,total_bytes:int,version:string}|null
     */
    public function readRange(string $dataset, int $offset, int $len): ?array
    {
        $wire = $this->manifests->serveWire($dataset);
        if ($wire === null) {
            return null;
        }
        $version = (string) $wire['version'];
        $path = $this->seedPath($version);
        if (! is_file($path)) {
            return null;
        }

        $total = (int) filesize($path);
        $offset = max(0, $offset);
        $len = max(0, min($len, max(0, $total - $offset)));

        $bytes = '';
        if ($len > 0) {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return null;
            }
            fseek($fh, $offset);
            $bytes = (string) fread($fh, $len);
            fclose($fh);
        }

        return ['bytes' => $bytes, 'total_bytes' => $total, 'version' => $version];
    }

    // ── JOINER ──────────────────────────────────────────────────────────────────

    /**
     * JOINER: fetch the origin-signed manifest, then range-pull the tarball bytes resumably
     * (resuming from membership.seed_cursor_bytes), and verify the assembled file against the
     * manifest's SIGNED sha256 before returning its path. Fail-closed: a digest mismatch
     * deletes the partial file, resets the cursor, and throws.
     *
     * Returns NULL when the host advertises no seed (no published / authenticatable manifest) — that
     * is NOT an error: a legacy host, or one whose foundation the joiner already holds, still admits
     * a read-only mirror. Only integrity failures AFTER a manifest is found (digest mismatch,
     * truncated transfer) throw — the seed is opportunistic, never a join blocker.
     *
     * @return array{path:string,manifest:array{dataset:string,version:string,sha256:string,size_bytes:int}}|null
     */
    public function pull(FederationPeer $host, ClusterMembership $membership, string $dataset = self::DATASET): ?array
    {
        try {
            $manifest = $this->manifests->pullFrom($host, $dataset);
        } catch (ConnectionException) {
            return null; // the manifest channel is unreachable → treat as "no seed available" (opportunistic)
        }
        if ($manifest === null) {
            return null; // host advertises no seed — the caller skips seeding (not a failure)
        }

        $expectedSha = (string) $manifest->sha256;
        $total = (int) $manifest->size_bytes;
        $pageBytes = max(1, (int) config('cga.federation_seed_page_bytes', 8 * 1024 * 1024));

        $tmp = $this->incomingPath($membership);
        @mkdir(dirname($tmp), 0775, true);

        // Resume from the on-disk partial (capped by the cursor); a partial longer than the
        // declared total is stale → restart. The membership cursor is the source of truth.
        $haveBytes = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($haveBytes > $total) {
            @unlink($tmp);
            $haveBytes = 0;
        }
        $membership->forceFill([
            'seed_dataset' => $dataset,
            'seed_version' => (string) $manifest->version,
            'seed_sha256' => $expectedSha,
            'seed_total_bytes' => $total,
            'seed_cursor_bytes' => $haveBytes,
        ])->save();

        $fh = fopen($tmp, $haveBytes > 0 ? 'cb' : 'wb');
        if ($fh === false) {
            throw new RuntimeException("Could not open the seed staging file {$tmp}.");
        }
        fseek($fh, $haveBytes);

        try {
            while ($haveBytes < $total) {
                $response = $this->client->get((string) $host->url, '/api/federation/geodata/seed/page', [
                    'dataset' => $dataset, 'offset' => $haveBytes, 'len' => $pageBytes,
                ]);
                if (! $response->successful()) {
                    throw new RuntimeException("Seed page fetch failed (HTTP {$response->status()}).");
                }
                $chunk = (string) $response->body();
                if ($chunk === '') {
                    throw new RuntimeException('Seed page returned no bytes before the manifest size was reached.');
                }
                fwrite($fh, $chunk);
                $haveBytes += strlen($chunk);
                $membership->forceFill(['seed_cursor_bytes' => $haveBytes])->save();
            }
        } finally {
            fclose($fh);
        }

        $actualSha = hash_file('sha256', $tmp) ?: '';
        if (! hash_equals($expectedSha, $actualSha)) {
            @unlink($tmp);
            $membership->forceFill(['seed_cursor_bytes' => 0])->save();
            throw new RuntimeException('Seed digest mismatch — the assembled foundation failed its signed checksum.');
        }

        return ['path' => $tmp, 'manifest' => [
            'dataset' => $dataset, 'version' => (string) $manifest->version,
            'sha256' => $expectedSha, 'size_bytes' => $total,
        ]];
    }

    /** Discard the staging file for a membership (call after a successful import applies it). */
    public function discardIncoming(ClusterMembership $membership): void
    {
        $tmp = $this->incomingPath($membership);
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    // ── paths ───────────────────────────────────────────────────────────────────

    private function stagingId(string $version): string
    {
        $safe = static fn (string $s): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $s);

        return 'seed-'.$safe(self::DATASET).'-'.$safe($version);
    }

    private function seedPath(string $version): string
    {
        return storage_path('app/exports/'.$this->stagingId($version).'.tar.gz');
    }

    private function incomingPath(ClusterMembership $membership): string
    {
        return storage_path('app/seeds-incoming/'.$membership->id.'.tar.gz');
    }
}
