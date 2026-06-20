<?php

namespace MeshCertBroker;

/**
 * The broker's MULTI-DOMAIN configuration. The ecosystem can carry many domains; each is independently
 * configured with its own Cloudflare token + zone + the set of CGA authority public keys allowed to grant
 * a cert under it. Adding / changing a domain is a config edit — no code change. The Cloudflare TOKEN
 * lives ONLY here (the operator drops it), never travels to a peer, never appears in a response.
 *
 * Shape (config/domains.php returns):
 *   [
 *     'store_dsn'  => 'mysql:host=localhost;dbname=cert_broker',  // or 'sqlite:/path/broker.sqlite'
 *     'store_user' => 'broker', 'store_pass' => '...',
 *     'acme'       => ['provider' => 'lego'|'stub', 'email' => 'ops@…', 'lego_bin' => '/usr/bin/lego', 'staging' => false],
 *     'request_ttl'=> 120,   // seconds a signed request stays fresh (anti-replay window)
 *     'domains'    => [
 *        'worldofstatecraft.org' => [
 *            'cloudflare_token'   => '…',         // DNS:Edit token for THIS zone only
 *            'cloudflare_zone_id' => '…',
 *            'authority_keys'     => ['<base64 Ed25519 pubkey of an authorized CGA authority>', …],
 *            'a_record_proxied'   => false,
 *        ],
 *        // add more domains here — same shape — to grow/replace the ecosystem's naming roots
 *     ],
 *   ]
 */
final class Config
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (empty($data['domains'])) {
            throw new \RuntimeException('Broker config needs a non-empty "domains" map.');
        }

        return new self($data);
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Broker config not found at {$path} — copy config/domains.example.php to config/domains.php and fill it.");
        }
        $data = require $path;
        if (! is_array($data) || empty($data['domains'])) {
            throw new \RuntimeException('Broker config malformed — expected an array with a non-empty "domains" map.');
        }

        return new self($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function requestTtl(): int
    {
        return (int) ($this->data['request_ttl'] ?? 120);
    }

    /** @return array<string,mixed>|null the per-domain config, or null if this domain is not in the ecosystem */
    public function domain(string $domain): ?array
    {
        $d = $this->data['domains'][$domain] ?? null;

        return is_array($d) ? $d : null;
    }

    /** @return list<string> every domain the broker serves (the ecosystem's naming roots). */
    public function domains(): array
    {
        return array_keys($this->data['domains']);
    }

    /** @return array<string,mixed> */
    public function acme(): array
    {
        return (array) ($this->data['acme'] ?? ['provider' => 'stub']);
    }

    public function storeDsn(): string
    {
        return (string) ($this->data['store_dsn'] ?? 'sqlite:'.dirname(__DIR__).'/var/broker.sqlite');
    }

    public function storeUser(): ?string
    {
        return $this->data['store_user'] ?? null;
    }

    public function storePass(): ?string
    {
        return $this->data['store_pass'] ?? null;
    }
}
