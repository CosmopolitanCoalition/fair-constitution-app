<?php

namespace MeshCertBroker;

/**
 * THE TRUST CHAIN — the security core of the broker. A cert is issued ONLY if ALL hold:
 *   1. the request is signed by the PEER (the same Ed25519 key the mesh pins);
 *   2. the request carries a promotion GRANT signed by an AUTHORITY whose key is in THIS domain's
 *      authorized set (so a peer cannot self-authorize, and the authority for one domain cannot grant
 *      under another);
 *   3. the grant is unexpired and the request is fresh (anti-replay window) with an unseen nonce;
 *   4. the requested name is well-formed `<subdomain>.<domain>` for a domain the broker actually serves;
 *   5. the CSR asks for EXACTLY that name and no other (no smuggled SANs).
 * Any failure → a client-safe BrokerError. The Cloudflare token is never read here and never leaks.
 */
final class GrantVerifier
{
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
    ) {}

    /** @return array{fqdn:string, domain:string, domainCfg:array, target:?string, grant:array} */
    public function verify(array $body): array
    {
        $now = time();

        // ── shape ──────────────────────────────────────────────────────────
        $grant = $body['grant'] ?? null;
        $grantSig = $body['grant_signature'] ?? null;
        $reqSig = $body['request_signature'] ?? null;
        $csr = $body['csr'] ?? null;
        $nonce = $body['nonce'] ?? null;
        $requestedAt = $body['requested_at'] ?? null;

        if (! is_array($grant) || ! is_string($grantSig) || ! is_string($reqSig)
            || ! is_string($csr) || ! is_string($nonce) || ! is_int($requestedAt)) {
            throw new BrokerError('Malformed request.', 400);
        }
        foreach (['domain', 'subdomain', 'peer_pubkey', 'authority_pubkey'] as $k) {
            if (! isset($grant[$k]) || ! is_string($grant[$k]) || $grant[$k] === '') {
                throw new BrokerError("Grant missing {$k}.", 400);
            }
        }
        // Pin the grant kind + version — a grant the authority signed for some OTHER protocol must not
        // double as a cert grant (cross-protocol confusion).
        if (($grant['type'] ?? null) !== 'cert_grant' || ($grant['v'] ?? null) !== 1) {
            throw new BrokerError('Not a v1 cert_grant.', 400);
        }
        // Timestamps are integers (same rigor as requested_at) so a malformed grant can't slip through.
        if (! isset($grant['issued_at'], $grant['expires_at']) || ! is_int($grant['issued_at']) || ! is_int($grant['expires_at'])) {
            throw new BrokerError('Grant timestamps must be integers.', 400);
        }

        // ── 4. domain is in the ecosystem ──────────────────────────────────
        $domainCfg = $this->config->domain((string) $grant['domain']);
        if ($domainCfg === null) {
            throw new BrokerError('Unknown domain — not served by this broker.', 404);
        }

        // ── 2. the authority key is authorized FOR THIS DOMAIN ─────────────
        $authorized = array_map('strval', (array) ($domainCfg['authority_keys'] ?? []));
        if (! Canonical::isOneOf((string) $grant['authority_pubkey'], $authorized)) {
            throw new BrokerError('Grant authority is not authorized for this domain.', 403);
        }

        // ── 1+2. signatures (grant by authority, request by peer) ──────────
        if (! Canonical::verify((string) $grant['authority_pubkey'], Canonical::json($grant), $grantSig)) {
            throw new BrokerError('Grant signature invalid.', 403);
        }
        $core = $body;
        unset($core['request_signature']); // the peer signs everything EXCEPT its own signature
        if (! Canonical::verify((string) $grant['peer_pubkey'], Canonical::json($core), $reqSig)) {
            throw new BrokerError('Request signature invalid (peer key mismatch).', 403);
        }

        // ── 3. freshness + grant validity + replay ─────────────────────────
        $expiresAt = (int) ($grant['expires_at'] ?? 0);
        $issuedAt = (int) ($grant['issued_at'] ?? 0);
        if ($expiresAt <= $now) {
            throw new BrokerError('Grant has expired.', 403);
        }
        if ($issuedAt > $now + 60) {
            throw new BrokerError('Grant issued in the future.', 403);
        }
        if (abs($now - $requestedAt) > $this->config->requestTtl()) {
            throw new BrokerError('Request is stale (clock skew or replay).', 408);
        }
        $this->store->pruneNonces($now - max(300, $this->config->requestTtl() * 2));
        if (! $this->store->consumeNonce($nonce, (string) $grant['peer_pubkey'], $now)) {
            throw new BrokerError('Nonce already used (replay).', 409);
        }

        // ── 4. name well-formedness ────────────────────────────────────────
        $subdomain = strtolower((string) $grant['subdomain']);
        // /D so $ cannot match before a trailing newline (a "paris\n" label must not pass).
        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/D', $subdomain)) {
            throw new BrokerError('Invalid subdomain label.', 400);
        }
        $fqdn = $subdomain.'.'.strtolower((string) $grant['domain']);

        // ── 5. the CSR asks for EXACTLY this name, nothing more ────────────
        $csrNames = CsrInspector::names($csr);
        if ($csrNames === [] || array_diff($csrNames, [$fqdn]) !== []) {
            throw new BrokerError('CSR must request exactly the granted name ('.$fqdn.') and no other.', 400);
        }

        // target (optional) — the A/AAAA content must be a BARE IP, never arbitrary text.
        $target = null;
        if (isset($body['target']) && $body['target'] !== '') {
            if (! is_string($body['target']) || filter_var($body['target'], FILTER_VALIDATE_IP) === false) {
                throw new BrokerError('target must be a bare IPv4/IPv6 address.', 400);
            }
            $target = $body['target'];
        }

        return ['fqdn' => $fqdn, 'domain' => (string) $grant['domain'], 'domainCfg' => $domainCfg, 'target' => $target, 'grant' => $grant];
    }
}
