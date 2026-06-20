<?php

namespace App\Services\Federation;

use App\Domain\Engine\ConstitutionalViolation;
use App\Services\AuditService;

/**
 * Mesh Roles & Channels of Trust (★11) — authority grant-on-promotion. When a box is approved for a
 * broker/cert channel, the authority.grant-holding box signs a `cert_grant` with its instance key (the
 * same key in the broker's authority_keys / broker_authorizations). This is the cryptographic RECEIPT of
 * approval the broker's GrantVerifier consumes: a peer cannot self-authorize, and the authority for one
 * domain cannot grant under another. The grant carries ONLY public keys + names — never the Cloudflare
 * token, never a private key.
 *
 * Canonical-JSON is byte-identical to the broker's Canonical::json (== AuditService::canonicalJson), so a
 * grant minted here verifies identically on Box C (LAMP) or an in-mesh broker.
 */
class CertGrantService
{
    /** Matches the broker's revocation-by-expiry clock (the 90-day LE renewal window). */
    private const GRANT_TTL_DAYS = 90;

    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly BrokerAuthorizationService $authz,
    ) {}

    /**
     * Mint + sign a cert_grant authorizing $peer to obtain a cert for <subdomain>.<domain>. Refuses unless
     * THIS box's key is an authorized authority for the domain (it must be in broker_authorizations) — the
     * mint cannot outrun the routing table the broker verifies against.
     *
     * @return array{grant: array<string,mixed>, grant_signature: string}
     */
    public function mint(string $domain, string $subdomain, string $peerServerId, string $peerPubKey, int $ttlDays = self::GRANT_TTL_DAYS): array
    {
        $domain = strtolower($domain);
        $authorityPub = $this->identity->publicKey();

        if (! in_array($authorityPub, $this->authz->authorityKeysFor($domain), true)) {
            throw new ConstitutionalViolation(
                "This box is not an authorized authority for [{$domain}] — attest it in broker_authorizations "
                .'before minting a cert grant (the mint cannot outrun the routing table).',
                'Mesh Roles & Channels of Trust · §4.3',
            );
        }

        $grant = [
            'v' => 1,
            'type' => 'cert_grant',
            'domain' => $domain,
            'subdomain' => strtolower($subdomain),
            'peer_pubkey' => $peerPubKey,
            'peer_server_id' => $peerServerId,
            'authority_pubkey' => $authorityPub,
            'authority_server_id' => $this->identity->serverId(),
            'issued_at' => now()->getTimestamp(),
            'expires_at' => now()->addDays($ttlDays)->getTimestamp(),
        ];

        return [
            'grant' => $grant,
            'grant_signature' => $this->identity->sign(AuditService::canonicalJson($grant)),
        ];
    }
}
