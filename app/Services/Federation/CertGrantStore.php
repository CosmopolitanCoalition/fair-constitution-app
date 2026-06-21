<?php

namespace App\Services\Federation;

/**
 * Local store for cert_grants DELIVERED to this box by an authority (Mesh Roles — auto-delivery). When an
 * authority pushes a grant to /api/federation/cert-grant, the controller verifies it against the
 * authority's pinned key and persists it HERE, keyed by the granted fqdn, so `mesh:request-cert` can pick
 * it up automatically — no operator copy-paste. A cert_grant carries only public keys + names + a
 * signature (no secret), so it is stored as plain JSON under storage/app/broker (gitignored; the FF&C sync
 * never touches storage files). It is re-verified end-to-end by the broker's GrantVerifier at issuance, so
 * a tampered stored grant can never produce a cert — this store is a convenience cache, not a trust root.
 */
class CertGrantStore
{
    private function path(): string
    {
        // Config-overridable so tests use an isolated store and never touch real delivered grants.
        return (string) config('cga.broker.received_grants_path', storage_path('app/broker/received-grants.json'));
    }

    /** @return array<string,array<string,mixed>> fqdn => {grant, grant_signature, from_server_id, received_at} */
    private function read(): array
    {
        $p = $this->path();
        if (! is_file($p)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($p), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string,array<string,mixed>> $all */
    private function write(array $all): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create the broker grant directory.');
        }
        file_put_contents($this->path(), json_encode($all, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($this->path(), 0600);
    }

    private function fqdn(array $grant): string
    {
        return strtolower((string) ($grant['subdomain'] ?? '')).'.'.strtolower((string) ($grant['domain'] ?? ''));
    }

    /** Persist a verified, delivered cert_grant keyed by its fqdn (latest delivery wins). */
    public function put(array $grant, string $grantSignature, string $fromServerId): string
    {
        $fqdn = $this->fqdn($grant);
        $all = $this->read();
        $all[$fqdn] = [
            'grant' => $grant,
            'grant_signature' => $grantSignature,
            'from_server_id' => $fromServerId,
            'received_at' => now()->getTimestamp(),
        ];
        $this->write($all);

        return $fqdn;
    }

    /** @return array{grant:array<string,mixed>,grant_signature:string}|null a delivered grant for $fqdn, if any. */
    public function get(string $fqdn): ?array
    {
        $row = $this->read()[strtolower($fqdn)] ?? null;
        if (! is_array($row) || ! isset($row['grant'], $row['grant_signature'])) {
            return null;
        }

        return ['grant' => (array) $row['grant'], 'grant_signature' => (string) $row['grant_signature']];
    }

    public function forget(string $fqdn): void
    {
        $all = $this->read();
        unset($all[strtolower($fqdn)]);
        $this->write($all);
    }

    /** @return list<string> the fqdns we hold a delivered grant for (UI/CLI visibility; no secrets). */
    public function fqdns(): array
    {
        return array_map('strval', array_keys($this->read()));
    }
}
