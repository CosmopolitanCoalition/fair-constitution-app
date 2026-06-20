<?php

namespace App\Services\Matrix\Scan;

/**
 * Phase K-3 (K3-I.4) — the DEFAULT, fully-OFFLINE media-scan provider. Matches a media hash against a
 * sideloaded set of known-illegal hashes with NO network call and NO classifier (the privacy rail: no
 * media ever leaves the box for the default scan). The set ships EMPTY — the OPERATOR supplies the
 * actual access-controlled list under their own legal credentials (IWF / NCMEC / PhotoDNA integration
 * is operator-config / rig-gated). On a LAN or air-gapped deployment this still works: scan + purge are
 * offline; only list UPDATES and the NCMEC report defer to connectivity.
 */
class LocalHashListScanProvider implements MediaScanProvider
{
    /** @var array<string,true> the known-illegal hash set (lowercased), as a membership map. */
    private array $hashes;

    /** @param list<string>|null $hashes overrides config('matrix.scan.local_hashes') when given. */
    public function __construct(?array $hashes = null)
    {
        $list = $hashes ?? (array) config('matrix.scan.local_hashes', []);
        $this->hashes = array_fill_keys(array_map(
            fn ($h) => strtolower(trim((string) $h)),
            $list
        ), true);
    }

    public function matchesKnownIllegal(string $mediaHash): bool
    {
        $key = strtolower(trim($mediaHash));

        return $key !== '' && isset($this->hashes[$key]);
    }

    public function source(): string
    {
        return 'local-hash-list';
    }
}
