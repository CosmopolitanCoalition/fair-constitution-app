<?php

namespace MeshCertBroker\Acme;

use MeshCertBroker\BrokerError;

/**
 * DEV/TEST provider — signs the peer's CSR with an EPHEMERAL in-memory CA, returning a real (but
 * untrusted) X.509 cert for the granted name. No network, no Cloudflare, no Let's Encrypt: it proves the
 * broker's trust chain + protocol + the round-trip end-to-end WITHOUT touching the real domain. Production
 * swaps in LegoAcmeProvider. NEVER use this provider to serve real clients — the CA is throwaway.
 */
final class StubAcmeProvider implements AcmeProvider
{
    public function issueFromCsr(string $fqdn, string $csrPem, array $domainCfg): string
    {
        $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($caKey === false) {
            throw new BrokerError('Stub CA keygen failed.', 500);
        }
        $caCsr = openssl_csr_new(['commonName' => 'mesh-cert-broker stub CA'], $caKey, ['digest_alg' => 'sha256']);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 1, ['digest_alg' => 'sha256']); // self-signed CA
        if ($caCert === false) {
            throw new BrokerError('Stub CA self-sign failed.', 500);
        }

        $cert = openssl_csr_sign($csrPem, $caCert, $caKey, 90, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new BrokerError('Stub issuance failed for '.$fqdn.'.', 500);
        }

        $out = '';
        openssl_x509_export($cert, $out);
        $caOut = '';
        openssl_x509_export($caCert, $caOut);

        return $out."\n".$caOut; // leaf + the throwaway CA, so the chain shape matches production
    }
}
