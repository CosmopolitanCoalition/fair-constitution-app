<?php

// Tiny PSR-4 autoloader (no composer — drop-and-run on a plain LAMP box) + the wiring factory.

spl_autoload_register(function (string $class): void {
    $prefix = 'MeshCertBroker\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__.'/src/'.$rel.'.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Build the broker from config. Config path: $CB_CONFIG env, else config/domains.php. The ACME backend is
 * chosen by acme.provider ('lego' = production Let's Encrypt + Cloudflare; 'stub' = self-signed, tests).
 *
 * @return \MeshCertBroker\Broker
 */
function broker_factory(): \MeshCertBroker\Broker
{
    $configPath = getenv('CB_CONFIG') ?: (__DIR__.'/config/domains.php');
    $config = \MeshCertBroker\Config::load($configPath);
    $store = new \MeshCertBroker\Store($config->storeDsn(), $config->storeUser(), $config->storePass());

    // Explicit + fail-closed: a typo'd or missing provider must NOT silently fall back to the self-signed
    // stub (which would serve untrusted certs in production). The operator opts into 'stub' deliberately.
    $provider = $config->acme()['provider'] ?? null;
    $acme = match ($provider) {
        'lego' => new \MeshCertBroker\Acme\LegoAcmeProvider($config->acme()),
        'stub' => new \MeshCertBroker\Acme\StubAcmeProvider(),
        default => throw new \RuntimeException(
            "Unknown acme.provider — set it explicitly to 'lego' (production) or 'stub' (offline tests)."
        ),
    };

    return new \MeshCertBroker\Broker($config, $store, $acme);
}
