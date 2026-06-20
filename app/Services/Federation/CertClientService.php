<?php

namespace App\Services\Federation;

use App\Services\AuditService;
use RuntimeException;

/**
 * Mesh Roles & Channels of Trust (★10) — the peer cert-client mechanics. Generates a TLS keypair + CSR
 * LOCALLY (the private key NEVER leaves the box), assembles the broker's signed request body
 * (canonical-JSON byte-identical to the broker's Canonical::json via the same Ed25519 identity, a fresh
 * nonce per request), and installs the returned full-chain PEM. The reference is the broker's
 * bin/test-client.php; MeshRequestCertCommand drives it end to end.
 */
class CertClientService
{
    public function __construct(private readonly InstanceIdentityService $identity) {}

    /**
     * A fresh RSA keypair + a CSR for EXACTLY $fqdn (CN only, no SANs — the broker refuses smuggled names).
     *
     * @return array{private_key: string, csr: string}
     */
    public function generateKeyAndCsr(string $fqdn): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('TLS keypair generation failed.');
        }
        $csr = openssl_csr_new(['commonName' => $fqdn], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new RuntimeException('CSR generation failed for '.$fqdn.'.');
        }

        $csrPem = '';
        openssl_csr_export($csr, $csrPem);
        $privPem = '';
        openssl_pkey_export($key, $privPem);

        return ['private_key' => $privPem, 'csr' => $csrPem];
    }

    /**
     * Assemble the signed cert-request body. The peer (us) signs the canonical body EXCEPT its own
     * signature — exactly what GrantVerifier re-derives and checks against grant.peer_pubkey.
     *
     * @param  array<string,mixed>  $grant       the authority's cert_grant
     * @param  string               $grantSig    the authority's detached signature over the grant
     * @return array<string,mixed>
     */
    public function buildRequest(array $grant, string $grantSig, string $csrPem, ?string $target = null): array
    {
        $body = [
            'grant' => $grant,
            'grant_signature' => $grantSig,
            'csr' => $csrPem,
            'nonce' => bin2hex(random_bytes(16)),
            'requested_at' => now()->getTimestamp(),
        ];
        if ($target !== null && $target !== '') {
            $body['target'] = $target;
        }

        // The peer signs everything EXCEPT request_signature (the broker unsets it before verifying).
        $body['request_signature'] = $this->identity->sign(AuditService::canonicalJson($body));

        return $body;
    }

    /**
     * Install the issued full-chain PEM + the local private key under the configured TLS dir.
     *
     * @return array{key_path: string, cert_path: string}
     */
    public function install(string $fqdn, string $privateKeyPem, string $fullChainPem): array
    {
        $dir = (string) config('cga.broker.tls_path', storage_path('app/mesh-tls'));
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create TLS directory {$dir}.");
        }

        $keyPath = $dir.'/'.$fqdn.'.key';
        $certPath = $dir.'/'.$fqdn.'.crt';
        file_put_contents($keyPath, $privateKeyPem);
        @chmod($keyPath, 0600);
        file_put_contents($certPath, $fullChainPem);

        return ['key_path' => $keyPath, 'cert_path' => $certPath];
    }
}
