<?php

// COPY to config/domains.php and fill in. config/domains.php is gitignored — the Cloudflare token never
// gets committed. MULTI-DOMAIN by design: add as many naming roots to 'domains' as the ecosystem needs;
// each is independent (its own token, zone, and authorized granting authorities). Add/replace a domain =
// edit this file; no code change.

return [
    // State: MySQL on a LAMP box (production), or sqlite for a quick local run.
    'store_dsn'  => 'mysql:host=127.0.0.1;dbname=cert_broker;charset=utf8mb4',
    'store_user' => 'cert_broker',
    'store_pass' => 'CHANGE_ME',

    // The ACME backend. 'lego' = real Let's Encrypt via DNS-01 + Cloudflare (install the `lego` binary).
    // 'stub' = self-signed (no network) for wiring tests only.
    'acme' => [
        'provider' => 'lego',
        'email'    => 'ops@worldofstatecraft.org',  // Let's Encrypt account contact
        'lego_bin' => 'lego',                         // path to the lego binary
        'staging'  => true,                           // true while testing (LE staging — no rate limits); false for real certs
    ],

    // How long a signed cert request stays fresh (anti-replay window), seconds.
    'request_ttl' => 120,

    'domains' => [
        'worldofstatecraft.org' => [
            // A Cloudflare API token scoped to EXACTLY Zone:DNS:Edit on THIS zone. Drop it here; it never
            // leaves this box and never appears in any response.
            'cloudflare_token'   => 'PASTE_CLOUDFLARE_DNS_EDIT_TOKEN',
            'cloudflare_zone_id' => 'PASTE_ZONE_ID',
            // The base64 Ed25519 public key(s) of the CGA authority instance(s) allowed to GRANT a cert
            // under this domain. Get it on the authority box: `php artisan federation:identity`
            // (the public_key field). Add more keys to allow more granting authorities.
            'authority_keys'     => [
                'PASTE_AUTHORITY_PUBLIC_KEY_BASE64',
            ],
            'a_record_proxied'   => false, // true = behind Cloudflare's proxy; false = direct A record
        ],

        // 'another-domain.example' => [ 'cloudflare_token' => '…', 'cloudflare_zone_id' => '…',
        //     'authority_keys' => ['…'], 'a_record_proxied' => false ],
    ],
];
