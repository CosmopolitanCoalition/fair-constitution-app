<?php

namespace App\Services\Federation;

use App\Services\AuditService;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Mesh Roles & Channels of Trust — the operator's local broker-credential store. The Cloudflare DNS-edit
 * token a broker box needs lives HERE, on the box, and only here: a gitignored file under storage/app
 * (which the FF&C sync NEVER touches — the tail carries audit_log + records, not storage files),
 * encrypted at rest with the app key. The operator drops the token through the broker console (or the
 * CLI); it is WRITE-ONLY from the UI's perspective — status() returns whether a domain is configured but
 * NEVER the token value, and the token never rides a prop, a response, a log line, or the mesh.
 *
 * The tokenFor()/zoneFor() readers are for the broker's own Cloudflare calls (InMeshBrokerService /
 * Cloudflare) ONLY — never hand their return value to anything that renders or federates.
 */
class BrokerCredentialService
{
    private function path(): string
    {
        return storage_path('app/broker/credentials.json');
    }

    /** @return array<string,array<string,mixed>> domain => {zone_id, token_encrypted, updated_at} */
    private function read(): array
    {
        $p = $this->path();
        if (! is_file($p)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($p), true);

        return is_array($data) && isset($data['domains']) && is_array($data['domains']) ? $data['domains'] : [];
    }

    /** @param array<string,array<string,mixed>> $domains */
    private function write(array $domains): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create the broker credential directory.');
        }
        file_put_contents($this->path(), json_encode(['domains' => $domains], JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($this->path(), 0600);
    }

    /**
     * Store (or replace) the Cloudflare credential for a domain. The token is encrypted at rest; it is
     * never returned to a UI-facing caller, never federated, never logged (the audit records the domain +
     * zone only).
     */
    public function setCredential(string $domain, string $zoneId, string $plaintextToken): void
    {
        $domain = strtolower(trim($domain));
        $domains = $this->read();
        $domains[$domain] = [
            'zone_id' => trim($zoneId),
            'token_encrypted' => Crypt::encryptString($plaintextToken),
            'updated_at' => now()->getTimestamp(),
        ];
        $this->write($domains);

        app(AuditService::class)->append('federation', 'broker.credential.set', [
            'domain' => $domain,
            'zone_id' => trim($zoneId), // NEVER the token
        ], 'MESH-ROLES');
    }

    public function forget(string $domain): void
    {
        $domains = $this->read();
        unset($domains[strtolower(trim($domain))]);
        $this->write($domains);
    }

    /** @return list<string> domains with a stored credential. */
    public function domains(): array
    {
        return array_map('strval', array_keys($this->read()));
    }

    public function has(string $domain): bool
    {
        return array_key_exists(strtolower(trim($domain)), $this->read());
    }

    public function zoneFor(string $domain): ?string
    {
        $z = $this->read()[strtolower(trim($domain))]['zone_id'] ?? null;

        return $z !== null ? (string) $z : null;
    }

    /** The DECRYPTED token — for the broker's own Cloudflare calls ONLY. Never return this to a prop/log/peer. */
    public function tokenFor(string $domain): ?string
    {
        $enc = $this->read()[strtolower(trim($domain))]['token_encrypted'] ?? null;
        if ($enc === null) {
            return null;
        }
        try {
            return Crypt::decryptString((string) $enc);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * UI-safe status — per domain, the zone + whether it's configured, NEVER the token.
     *
     * @return list<array{domain:string,zone_id:?string,configured:bool,updated_at:?int}>
     */
    public function status(): array
    {
        $out = [];
        foreach ($this->read() as $domain => $c) {
            $out[] = [
                'domain' => (string) $domain,
                'zone_id' => isset($c['zone_id']) ? (string) $c['zone_id'] : null,
                'configured' => true,
                'updated_at' => isset($c['updated_at']) ? (int) $c['updated_at'] : null,
            ];
        }

        return $out;
    }
}
