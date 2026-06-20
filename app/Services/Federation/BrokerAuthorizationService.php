<?php

namespace App\Services\Federation;

use App\Models\BrokerAuthorization;
use App\Models\FederationPeer;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mesh Roles & Channels of Trust (★8) — the broker routing table service. Generalizes the cert-broker's
 * static per-domain `authority_keys` (config/domains.php) into a live, mesh-distributed set of SIGNED
 * facts. An AUTHORITY box attests "broker B may broker under domain D"; the fact is gossiped and each
 * receiver verifies it against the AUTHORITY's OWN pinned key (never the relayer's) — the
 * MeshOperatorService::ingestAnnounce discipline. Both the in-mesh broker (★9) and Box C feed the SAME
 * GrantVerifier: authorityKeysFor(domain) is exactly the `authority_keys` whitelist the verifier consumes.
 *
 * The Cloudflare token never touches this layer — facts carry only public keys + names + signatures.
 */
class BrokerAuthorizationService
{
    public function __construct(private readonly InstanceIdentityService $identity) {}

    /** The canonical fact bytes the authority signs (byte-identical to the broker's Canonical.php). */
    public function canonicalFact(string $domain, string $brokerServerId, string $authorityServerId, int $issuedAt): string
    {
        return AuditService::canonicalJson([
            'v' => 1,
            'type' => 'broker_authorization',
            'domain' => $domain,
            'broker_server_id' => $brokerServerId,
            'authority_server_id' => $authorityServerId,
            'issued_at' => $issuedAt,
        ]);
    }

    /**
     * WE (an authority box) attest that $brokerServerId may broker under $domain — sign + store the fact.
     * Only meaningful on a box holding authority.grant; the grant minted on a broker.* ratify can call this
     * to publish the routing fact.
     */
    public function attest(string $domain, string $brokerServerId): BrokerAuthorization
    {
        $authorityServerId = $this->identity->serverId();
        $issuedAt = now()->getTimestamp();
        $signature = $this->identity->sign($this->canonicalFact($domain, $brokerServerId, $authorityServerId, $issuedAt));

        return BrokerAuthorization::query()->updateOrCreate(
            ['domain' => $domain, 'broker_server_id' => $brokerServerId, 'authority_server_id' => $authorityServerId],
            [
                'authority_pubkey' => $this->identity->publicKey(),
                'signature' => $signature,
                'issued_at' => Carbon::createFromTimestamp($issuedAt),
                'revoked_at' => null,
            ],
        );
    }

    /** Revoke OUR attestation for a broker under a domain (fail-closed at read; authority-driven). */
    public function revoke(string $domain, string $brokerServerId): bool
    {
        return (bool) BrokerAuthorization::query()
            ->where('domain', $domain)
            ->where('broker_server_id', $brokerServerId)
            ->where('authority_server_id', $this->identity->serverId())
            ->update(['revoked_at' => now()]);
    }

    /**
     * Export OUR live attestations for gossip.
     *
     * @return list<array<string,mixed>>
     */
    public function wire(): array
    {
        return BrokerAuthorization::query()
            ->where('authority_server_id', $this->identity->serverId())
            ->whereNull('revoked_at')
            ->get()
            ->map(fn (BrokerAuthorization $a) => [
                'domain' => (string) $a->domain,
                'broker_server_id' => (string) $a->broker_server_id,
                'authority_server_id' => (string) $a->authority_server_id,
                'authority_pubkey' => (string) $a->authority_pubkey,
                'signature' => (string) $a->signature,
                'issued_at' => $a->issued_at?->getTimestamp(),
            ])->all();
    }

    /**
     * Ingest gossiped broker-authorization facts. Each fact is verified against the AUTHORITY's OWN pinned
     * key (resolved by authority_server_id, NOT the relayer) and the fact's claimed authority_pubkey must
     * equal that pinned key — no trust is conferred by relay. Bad signature / unknown authority / pubkey
     * mismatch ⇒ the fact is skipped. Idempotent per (domain, broker, authority).
     *
     * @param  list<array<string,mixed>>  $facts
     * @return int  number of facts accepted
     */
    public function ingest(array $facts, FederationPeer $from): int
    {
        $accepted = 0;

        foreach ($facts as $fact) {
            $domain = (string) ($fact['domain'] ?? '');
            $brokerServerId = (string) ($fact['broker_server_id'] ?? '');
            $authorityServerId = (string) ($fact['authority_server_id'] ?? '');
            $authorityPubkey = (string) ($fact['authority_pubkey'] ?? '');
            $signature = (string) ($fact['signature'] ?? '');
            $issuedAt = (int) ($fact['issued_at'] ?? 0);

            if ($domain === '' || $brokerServerId === '' || $authorityServerId === '' || $authorityPubkey === '') {
                continue;
            }

            // Resolve the AUTHORITY's pinned key — never the relayer's.
            $pinned = $this->serverKey($authorityServerId, $from);
            if ($pinned === null || ! hash_equals($pinned, $authorityPubkey)) {
                continue; // we hold no pinned key for this authority, or the fact claims a different key
            }
            if (! InstanceIdentityService::verify(
                $pinned,
                $this->canonicalFact($domain, $brokerServerId, $authorityServerId, $issuedAt),
                $signature,
            )) {
                continue; // tampered, or not actually signed by the named authority
            }

            BrokerAuthorization::query()->updateOrCreate(
                ['domain' => $domain, 'broker_server_id' => $brokerServerId, 'authority_server_id' => $authorityServerId],
                [
                    'authority_pubkey' => $authorityPubkey,
                    'signature' => $signature,
                    'issued_at' => Carbon::createFromTimestamp($issuedAt ?: time()),
                    'revoked_at' => null,
                ],
            );
            $accepted++;
        }

        return $accepted;
    }

    /**
     * The authority public keys allowed to GRANT under $domain — exactly the `authority_keys` whitelist the
     * cert GrantVerifier consumes. THIS IS A TRUST DECISION, so it is LOCALLY ROOTED: gossip can DISTRIBUTE
     * trust but must never BOOTSTRAP it. A gossiped peer self-attestation ("I am the authority for D",
     * self-signed) would otherwise let any peer self-authorize and forge cert grants (a domain takeover).
     * Roots, both local:
     *   (1) OUR OWN attestations (authority_server_id == us) — we self-attest only on ratify of a broker
     *       role, which already required the domain's token/credential, so it is genuinely rooted; and
     *   (2) authority keys the operator EXPLICITLY pinned in config/domains (cga.broker.domains[D].authority_keys).
     * Gossiped PEER attestations never enter this list — they are discovery-only (see brokersFor).
     *
     * @return list<string>
     */
    public function authorityKeysFor(string $domain): array
    {
        $own = BrokerAuthorization::query()
            ->where('domain', $domain)
            ->whereNull('revoked_at')
            ->where('authority_server_id', $this->identity->serverId()) // OUR attestations only — never gossiped peers
            ->distinct()
            ->pluck('authority_pubkey')
            ->map(fn ($k) => (string) $k)
            ->all();

        $pinned = array_map('strval', (array) (config('cga.broker.domains')[$domain]['authority_keys'] ?? []));

        return array_values(array_unique(array_merge($own, $pinned)));
    }

    /** @return list<string> broker_server_ids authorized to broker under $domain (discovery/routing). */
    public function brokersFor(string $domain): array
    {
        return BrokerAuthorization::query()
            ->where('domain', $domain)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('broker_server_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** Resolve a server's pinned base64 key — ours if it's us, the sender's if it sent, else the peer's. */
    private function serverKey(string $serverId, FederationPeer $from): ?string
    {
        if ($serverId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }
        if ($serverId === (string) $from->server_id && $from->public_key !== null) {
            return (string) $from->public_key;
        }

        $peer = FederationPeer::query()->where('server_id', $serverId)->whereNull('deleted_at')->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }
}
