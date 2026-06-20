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

    $acme = ($config->acme()['provider'] ?? 'stub') === 'lego'
        ? new \MeshCertBroker\Acme\LegoAcmeProvider($config->acme())
        : new \MeshCertBroker\Acme\StubAcmeProvider();

    return new \MeshCertBroker\Broker($config, $store, $acme);
}
