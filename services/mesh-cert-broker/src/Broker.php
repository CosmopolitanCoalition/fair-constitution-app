<?php

namespace MeshCertBroker;

use MeshCertBroker\Acme\AcmeProvider;

/**
 * The orchestrator: verify the trust chain → issue the cert from the CSR → (best-effort) point the name
 * at the peer → record the issuance. One public method; everything security-relevant is in GrantVerifier.
 */
final class Broker
{
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly AcmeProvider $acme,
    ) {}

    /**
     * @param array<string,mixed> $body the signed cert request
     * @return array{fqdn:string, certificate:string, issued_at:int, dns:string}
     */
    public function issue(array $body): array
    {
        $v = (new GrantVerifier($this->config, $this->store))->verify($body);

        // The cert is the deliverable — issue it first (DNS-01 uses a TXT record, not the A record).
        $cert = $this->acme->issueFromCsr($v['fqdn'], (string) $body['csr'], $v['domainCfg']);

        // THE NET (defense in depth): inspect the ACTUAL issued cert in-process and refuse to deliver it
        // unless it covers EXACTLY the granted name. This catches any name the CSR pre-filter could miss,
        // including a SAN lego copied straight from the CSR — a mis-issued cert is never handed back.
        self::assertCertCoversOnly($cert, $v['fqdn']);

        // Best-effort: point <fqdn> at the peer's address. A failure here does NOT void the cert — the
        // peer can set DNS itself; we report it so the operator sees an incomplete provision.
        $dns = 'skipped';
        if ($v['target'] !== null && $v['target'] !== '') {
            try {
                Cloudflare::upsertAddressRecord($v['domainCfg'], $v['fqdn'], $v['target']);
                $dns = 'created';
            } catch (BrokerError) {
                $dns = 'failed';
            }
        }

        $notAfter = null;
        $parsed = @openssl_x509_parse($cert);
        if (is_array($parsed) && isset($parsed['validTo_time_t'])) {
            $notAfter = (int) $parsed['validTo_time_t'];
        }

        $this->store->recordIssuance([
            'id' => self::uuid(),
            'fqdn' => $v['fqdn'],
            'domain' => $v['domain'],
            'peer_pubkey' => (string) $v['grant']['peer_pubkey'],
            'peer_server_id' => (string) ($v['grant']['peer_server_id'] ?? ''),
            'authority_server_id' => (string) ($v['grant']['authority_server_id'] ?? ''),
            'target' => $v['target'],
            'issued_at' => time(),
            'not_after' => $notAfter,
        ]);

        return ['fqdn' => $v['fqdn'], 'certificate' => $cert, 'issued_at' => time(), 'dns' => $dns];
    }

    /** Refuse to deliver a cert that names anything other than $fqdn (CN + every SAN, all types). */
    private static function assertCertCoversOnly(string $certPem, string $fqdn): void
    {
        $parsed = @openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            throw new BrokerError('Issued certificate could not be verified.', 500);
        }

        $names = [];
        $cn = $parsed['subject']['CN'] ?? null;
        if (! empty($cn)) {
            $names[] = strtolower((string) $cn);
        }
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $names[] = preg_match('/^DNS:(.+)$/i', $entry, $m)
                ? strtolower(trim($m[1]))
                : 'non-dns:'.strtolower(explode(':', $entry, 2)[0]);
        }

        if (array_values(array_unique($names)) !== [$fqdn]) {
            throw new BrokerError('Issued certificate covers an unexpected name — refusing to deliver.', 500);
        }
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
