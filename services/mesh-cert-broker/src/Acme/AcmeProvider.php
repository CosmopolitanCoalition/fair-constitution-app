<?php

namespace MeshCertBroker\Acme;

/**
 * The ACME seam (same pattern as the CGA's scan/translation provider seams). The broker's TRUST logic is
 * provider-agnostic; the backend that actually proves domain control + issues the cert is swappable:
 *  - StubAcmeProvider — self-signs the CSR with an ephemeral CA (local logic tests; no network, no CA).
 *  - LegoAcmeProvider — production: DNS-01 via Cloudflare + Let's Encrypt, signing the peer's CSR.
 * The per-domain Cloudflare token is read from $domainCfg here and NOWHERE else; it never leaves the box.
 */
interface AcmeProvider
{
    /**
     * Issue a cert for $fqdn from the peer's CSR (the peer keeps its private key — the CSR carries only
     * the public half). Returns the full-chain PEM. Throws \MeshCertBroker\BrokerError on failure.
     *
     * @param  array<string,mixed>  $domainCfg  the per-domain config (carries cloudflare_token + zone)
     */
    public function issueFromCsr(string $fqdn, string $csrPem, array $domainCfg): string;
}
