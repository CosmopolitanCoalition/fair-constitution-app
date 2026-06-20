<?php

namespace App\Services\Federation;

use MeshCertBroker\Acme\LegoAcmeProvider;
use MeshCertBroker\Acme\StubAcmeProvider;
use MeshCertBroker\Broker;
use MeshCertBroker\Config;
use MeshCertBroker\Store;
use RuntimeException;

/**
 * Mesh Roles & Channels of Trust (★9) — the in-mesh broker adapter. "External LAMP Box C" and
 * "in-mesh box adopting the broker role" run the IDENTICAL issuance core (Broker / GrantVerifier /
 * Canonical, framework-free); they differ only in where authority_keys come from. Box C reads the static
 * config/domains.php; the in-mesh box sources authority_keys from the gossiped broker_authorizations
 * (BrokerAuthorizationService) — the static whitelist becomes the live, mesh-distributed routing table.
 * Both feed the SAME Broker::issue().
 *
 * The Cloudflare token stays pinned to THIS box (config/.env) — it never federates, never appears in a
 * grant or response. The ACME backend is explicit + fail-closed (stub = offline self-signed for wiring;
 * lego = real Let's Encrypt, the rig leg). Adding/replacing a domain is config — no code change.
 */
class InMeshBrokerService
{
    public function __construct(
        private readonly BrokerAuthorizationService $authz,
        private readonly BrokerCredentialService $credentials,
    ) {}

    /** Issue a cert from a signed request — the same trust chain whether LAMP or in-mesh. */
    public function issue(array $body): array
    {
        $config = $this->config();

        return (new Broker($config, $this->store($config), $this->acme($config)))->issue($body);
    }

    /**
     * Build the broker Config from THIS box's local secrets (CF token + zones, never federated) joined
     * with the mesh-distributed authority_keys for each served domain.
     */
    public function config(): Config
    {
        $domains = [];
        foreach ($this->servedDomains() as $domain => $cfg) {
            // The operator-dropped credential store wins; static config is the fallback. The token is read
            // here ONLY to hand to the broker's own Cloudflare calls — it never leaves this method's result.
            $domains[$domain] = [
                'cloudflare_token' => (string) ($this->credentials->tokenFor($domain) ?? ($cfg['cloudflare_token'] ?? config('services.cloudflare.dns_token', ''))),
                'cloudflare_zone_id' => (string) ($this->credentials->zoneFor($domain) ?? ($cfg['cloudflare_zone_id'] ?? '')),
                // THE JOIN: authority_keys are the live, gossiped, per-author-verified routing table.
                'authority_keys' => $this->authz->authorityKeysFor($domain),
                'a_record_proxied' => (bool) ($cfg['a_record_proxied'] ?? false),
            ];
        }

        if ($domains === []) {
            throw new RuntimeException('This box brokers no domains — configure cga.broker.domains and adopt broker.tls.');
        }

        return Config::fromArray([
            'store_dsn' => $this->storeDsn(),
            'acme' => [
                'provider' => (string) config('cga.broker.acme.provider', 'stub'),
                'email' => (string) config('cga.broker.acme.email', ''),
                'lego_bin' => (string) config('cga.broker.acme.lego_bin', 'lego'),
                'staging' => (bool) config('cga.broker.acme.staging', true),
            ],
            'request_ttl' => (int) config('cga.broker.request_ttl', 120),
            'domains' => $domains,
        ]);
    }

    /** @return array<string,array<string,mixed>> domain => per-domain local config (operator credential store ∪ static config). */
    private function servedDomains(): array
    {
        $static = (array) config('cga.broker.domains', []);
        // Every domain the operator dropped a credential for is served, even if absent from static config.
        foreach ($this->credentials->domains() as $domain) {
            $static[$domain] = $static[$domain] ?? [];
        }

        return $static;
    }

    private function storeDsn(): string
    {
        // The broker's private nonce/issuance ledger — its OWN store, not CGA governance data. Defaults to
        // the box's PostgreSQL (always available); the operator can point it elsewhere.
        $configured = (string) config('cga.broker.store_dsn', '');
        if ($configured !== '') {
            return $configured;
        }

        $pg = (array) config('database.connections.pgsql');

        return sprintf('pgsql:host=%s;port=%s;dbname=%s', $pg['host'] ?? 'postgres', $pg['port'] ?? '5432', $pg['database'] ?? 'fair_constitution');
    }

    private function store(Config $config): Store
    {
        $pg = (array) config('database.connections.pgsql');
        $user = str_starts_with($config->storeDsn(), 'pgsql') ? ($pg['username'] ?? null) : $config->storeUser();
        $pass = str_starts_with($config->storeDsn(), 'pgsql') ? ($pg['password'] ?? null) : $config->storePass();

        return new Store($config->storeDsn(), $user, $pass);
    }

    private function acme(Config $config): StubAcmeProvider|LegoAcmeProvider
    {
        // Fail-closed: a typo'd provider must NOT silently serve self-signed certs in production.
        return match ($config->acme()['provider'] ?? null) {
            'lego' => new LegoAcmeProvider($config->acme()),
            'stub' => new StubAcmeProvider(),
            default => throw new RuntimeException("Set cga.broker.acme.provider to 'lego' (production) or 'stub' (offline tests)."),
        };
    }
}
