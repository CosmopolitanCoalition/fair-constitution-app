<?php

// Security self-test for the broker's trust chain — no network, stub ACME, in-memory sqlite. Exercises
// the happy path + every refusal the GrantVerifier must enforce. Run: php bin/selftest.php

require __DIR__.'/../bootstrap.php';

use MeshCertBroker\Acme\StubAcmeProvider;
use MeshCertBroker\Broker;
use MeshCertBroker\BrokerError;
use MeshCertBroker\Canonical;
use MeshCertBroker\Config;
use MeshCertBroker\Store;

$pass = 0;
$fail = 0;
$check = function (string $name, callable $fn) use (&$pass, &$fail) {
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "  ✗ {$name} — ".$e->getMessage()."\n";
        $fail++;
    }
};

$b64 = fn (string $bin) => sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_ORIGINAL);
$sign = fn (string $msg, string $sk) => $b64(sodium_crypto_sign_detached($msg, $sk));

// One authority + one peer, and the broker that trusts this authority for "wos.test".
$authKp = sodium_crypto_sign_keypair();
$authPub = $b64(sodium_crypto_sign_publickey($authKp));
$authSec = sodium_crypto_sign_secretkey($authKp);
$peerKp = sodium_crypto_sign_keypair();
$peerPub = $b64(sodium_crypto_sign_publickey($peerKp));
$peerSec = sodium_crypto_sign_secretkey($peerKp);

$config = Config::fromArray([
    'store_dsn' => 'sqlite::memory:',
    'acme' => ['provider' => 'stub'],
    'request_ttl' => 120,
    'domains' => ['wos.test' => [
        'authority_keys' => [$authPub], 'cloudflare_token' => 'x', 'cloudflare_zone_id' => 'x',
    ]],
]);
$broker = new Broker($config, new Store('sqlite::memory:'), new StubAcmeProvider());

// A factory for a fully-valid signed request for <sub>.wos.test.
$makeRequest = function (string $sub, ?string $csrName = null, ?array $grantOverride = null, ?string $authSecOverride = null)
    use ($authPub, $authSec, $peerPub, $peerSec, $b64, $sign) {
    $fqdn = $sub.'.wos.test';
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => $csrName ?? $fqdn], $key, ['digest_alg' => 'sha256']);
    openssl_csr_export($csr, $csrPem);

    $now = time();
    $grant = $grantOverride ?? [
        'v' => 1, 'type' => 'cert_grant', 'domain' => 'wos.test', 'subdomain' => $sub,
        'peer_pubkey' => $peerPub, 'peer_server_id' => 'p1',
        'authority_pubkey' => $authPub, 'authority_server_id' => 'a1',
        'issued_at' => $now, 'expires_at' => $now + 300,
    ];
    $grantSig = $sign(Canonical::json($grant), $authSecOverride ?? $authSec);
    $core = ['grant' => $grant, 'grant_signature' => $grantSig, 'csr' => $csrPem,
        'nonce' => bin2hex(random_bytes(16)), 'requested_at' => $now];
    $core['request_signature'] = $sign(Canonical::json($core), $peerSec);

    return $core;
};

$assertIssued = function (array $req, string $fqdn) use ($broker) {
    $r = $broker->issue($req);
    if ($r['fqdn'] !== $fqdn) {
        throw new \RuntimeException("fqdn {$r['fqdn']} != {$fqdn}");
    }
    $p = openssl_x509_parse($r['certificate']);
    if (($p['subject']['CN'] ?? '') !== $fqdn) {
        throw new \RuntimeException('cert CN != fqdn');
    }
};
$assertRefused = function (array $req) use ($broker) {
    try {
        $broker->issue($req);
    } catch (BrokerError) {
        return;
    }
    throw new \RuntimeException('expected a refusal, got an issuance');
};

echo "mesh-cert-broker self-test:\n";

$check('a valid promoted request is issued a cert for the granted name', fn () => $assertIssued($makeRequest('paris'), 'paris.wos.test'));

$check('a tampered grant (subdomain changed after signing) is refused', function () use ($makeRequest, $assertRefused) {
    $req = $makeRequest('paris');
    $req['grant']['subdomain'] = 'london'; // breaks the authority signature
    $assertRefused($req);
});

$check('a grant signed by an UNauthorized authority is refused', function () use ($makeRequest, $assertRefused, $authPub) {
    $rogue = sodium_crypto_sign_keypair();
    $req = $makeRequest('berlin', null,
        grantOverride: ['v' => 1, 'type' => 'cert_grant', 'domain' => 'wos.test', 'subdomain' => 'berlin',
            'peer_pubkey' => null, 'authority_pubkey' => sodium_bin2base64(sodium_crypto_sign_publickey($rogue), SODIUM_BASE64_VARIANT_ORIGINAL),
            'issued_at' => time(), 'expires_at' => time() + 300],
        authSecOverride: sodium_crypto_sign_secretkey($rogue));
    $assertRefused($req);
});

$check('a CSR for a DIFFERENT name than granted is refused', function () use ($makeRequest, $assertRefused) {
    $assertRefused($makeRequest('rome', csrName: 'evil.wos.test'));
});

$check('an unknown domain is refused', function () use ($assertRefused, $authPub, $authSec, $peerPub, $peerSec, $sign) {
    $now = time();
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'x.other.test'], $key); openssl_csr_export($csr, $csrPem);
    $grant = ['v' => 1, 'type' => 'cert_grant', 'domain' => 'other.test', 'subdomain' => 'x',
        'peer_pubkey' => $peerPub, 'authority_pubkey' => $authPub, 'issued_at' => $now, 'expires_at' => $now + 300];
    $core = ['grant' => $grant, 'grant_signature' => $sign(Canonical::json($grant), $authSec), 'csr' => $csrPem,
        'nonce' => bin2hex(random_bytes(16)), 'requested_at' => $now];
    $core['request_signature'] = $sign(Canonical::json($core), $peerSec);
    $assertRefused($core);
});

$check('a replayed nonce is refused the second time', function () use ($makeRequest, $broker) {
    $req = $makeRequest('vienna');
    $broker->issue($req);                 // first: ok
    try {
        $broker->issue($req);             // replay: same nonce
        throw new \RuntimeException('replay was accepted');
    } catch (BrokerError) { /* expected */ }
});

// A CSR carrying a SMUGGLED extra SAN must be refused (the domain-takeover vector the review found).
$sanCsr = function (string $cn, array $sans) {
    $cnf = "[req]\ndistinguished_name=dn\nreq_extensions=v3_req\n[dn]\n[v3_req]\nsubjectAltName=".implode(',', $sans)."\n";
    $tmp = tempnam(sys_get_temp_dir(), 'cnf');
    file_put_contents($tmp, $cnf);
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => $cn], $key, ['config' => $tmp, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256']);
    openssl_csr_export($csr, $pem);
    @unlink($tmp);

    return $pem;
};
$signFor = function (string $sub, string $csrPem) use ($authPub, $authSec, $peerPub, $peerSec, $sign) {
    $now = time();
    $grant = ['v' => 1, 'type' => 'cert_grant', 'domain' => 'wos.test', 'subdomain' => $sub,
        'peer_pubkey' => $peerPub, 'peer_server_id' => 'p1', 'authority_pubkey' => $authPub,
        'authority_server_id' => 'a1', 'issued_at' => $now, 'expires_at' => $now + 300];
    $core = ['grant' => $grant, 'grant_signature' => $sign(Canonical::json($grant), $authSec), 'csr' => $csrPem,
        'nonce' => bin2hex(random_bytes(16)), 'requested_at' => $now];
    $core['request_signature'] = $sign(Canonical::json($core), $peerSec);

    return $core;
};

$check('a CSR with a smuggled EXTRA DNS SAN is refused', function () use ($sanCsr, $signFor, $assertRefused) {
    $csr = $sanCsr('madrid.wos.test', ['DNS:madrid.wos.test', 'DNS:evil.example']);
    $assertRefused($signFor('madrid', $csr));
});

$check('a CSR with a non-DNS (IP) SAN is refused', function () use ($sanCsr, $signFor, $assertRefused) {
    $csr = $sanCsr('lisbon.wos.test', ['DNS:lisbon.wos.test', 'IP:203.0.113.9']);
    $assertRefused($signFor('lisbon', $csr));
});

$check('a non-IP target is refused', function () use ($makeRequest, $assertRefused, $authSec, $peerSec, $sign) {
    $req = $makeRequest('oslo');
    $req['target'] = 'not-an-ip; rm -rf';
    // re-sign the peer signature over the mutated body so it fails at the target check, not the sig check
    $core = $req; unset($core['request_signature']);
    $req['request_signature'] = $sign(Canonical::json($core), $peerSec);
    $assertRefused($req);
});

$check('a grant that is not a cert_grant is refused', function () use ($makeRequest, $assertRefused) {
    $req = $makeRequest('athens');
    $req['grant']['type'] = 'federation_handshake'; // breaks the sig too — refused either way
    $assertRefused($req);
});

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
