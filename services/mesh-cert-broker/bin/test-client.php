<?php

// Simulates a PROMOTED PEER end-to-end: it mints a throwaway authority + peer keypair, generates a real
// CSR, signs a promotion grant (as the authority) + a cert request (as the peer) with the SAME canonical
// the broker verifies, writes a matching test config (stub ACME, sqlite, the authority key embedded), and
// writes the signed request JSON. Then POST the request at the broker:
//
//   php bin/test-client.php config/domains.test.php var/req.json worldofstatecraft.org paris 203.0.113.7
//   CB_CONFIG=$PWD/config/domains.test.php php -S 127.0.0.1:8099 -t public &
//   curl -s -X POST --data-binary @var/req.json http://127.0.0.1:8099/
//
// Stands in for the eventual CGA-side peer client (php artisan mesh:request-cert) until that's wired.

require __DIR__.'/../bootstrap.php';

use MeshCertBroker\Canonical;

[$_, $configOut, $requestOut, $domain, $subdomain] = array_pad($argv, 5, null);
$target = $argv[5] ?? null;
if (! $configOut || ! $requestOut || ! $domain || ! $subdomain) {
    fwrite(STDERR, "usage: test-client.php <configOut> <requestOut> <domain> <subdomain> [target]\n");
    exit(2);
}

$b64 = fn (string $bin) => sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_ORIGINAL);
$sign = fn (string $msg, string $sk) => $b64(sodium_crypto_sign_detached($msg, $sk));

// Throwaway authority + peer Ed25519 identities (these mimic two CGA instances' signing keys).
$authKp = sodium_crypto_sign_keypair();
$authPub = $b64(sodium_crypto_sign_publickey($authKp));
$authSec = sodium_crypto_sign_secretkey($authKp);
$peerKp = sodium_crypto_sign_keypair();
$peerPub = $b64(sodium_crypto_sign_publickey($peerKp));
$peerSec = sodium_crypto_sign_secretkey($peerKp);

$fqdn = strtolower($subdomain).'.'.strtolower($domain);

// A real CSR for the granted name (the peer's TLS key — its private half never leaves here).
$tlsKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csrRes = openssl_csr_new(['commonName' => $fqdn], $tlsKey, ['digest_alg' => 'sha256']);
openssl_csr_export($csrRes, $csrPem);

$now = time();
$grant = [
    'v' => 1,
    'type' => 'cert_grant',
    'domain' => strtolower($domain),
    'subdomain' => strtolower($subdomain),
    'peer_pubkey' => $peerPub,
    'peer_server_id' => 'test-peer-'.bin2hex(random_bytes(4)),
    'authority_pubkey' => $authPub,
    'authority_server_id' => 'test-authority-'.bin2hex(random_bytes(4)),
    'issued_at' => $now,
    'expires_at' => $now + 300,
];
$grantSig = $sign(Canonical::json($grant), $authSec);

$core = [
    'grant' => $grant,
    'grant_signature' => $grantSig,
    'csr' => $csrPem,
    'nonce' => bin2hex(random_bytes(16)),
    'requested_at' => $now,
];
if ($target) {
    $core['target'] = $target;
}
$requestSig = $sign(Canonical::json($core), $peerSec);
$body = $core + ['request_signature' => $requestSig];

// A matching test config: stub ACME (no network), sqlite state, and the authority key authorized.
@mkdir(__DIR__.'/../var', 0700, true);
$config = "<?php\nreturn ".var_export([
    'store_dsn' => 'sqlite:'.realpath(__DIR__.'/..').'/var/test.sqlite',
    'acme' => ['provider' => 'stub'],
    'request_ttl' => 120,
    'domains' => [
        strtolower($domain) => [
            'cloudflare_token' => 'unused-in-stub',
            'cloudflare_zone_id' => 'unused-in-stub',
            'authority_keys' => [$authPub],
            'a_record_proxied' => false,
        ],
    ],
], true).";\n";
file_put_contents($configOut, $config);
file_put_contents($requestOut, json_encode($body));

fwrite(STDERR, "wrote config -> {$configOut}\nwrote request -> {$requestOut}\nfqdn = {$fqdn}\n");
