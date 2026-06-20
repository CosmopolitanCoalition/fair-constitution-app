<?php

namespace App\Console\Commands;

use App\Services\Federation\CapabilityService;
use App\Services\Federation\CertClientService;
use App\Services\Federation\CertGrantService;
use App\Services\Federation\InMeshBrokerService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MultiplexClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * mesh:request-cert — the peer cert-client (Mesh Roles & Channels of Trust ★10). Generates a TLS keypair
 * + CSR LOCALLY (the private key never leaves), assembles the broker's signed request, sends it to a mesh
 * broker (discovered by capability, or this box's own broker via --local), and installs the returned cert.
 *
 * The cert_grant comes from --grant-file (an authority delivered it) or is minted locally when THIS box is
 * an authorized authority for the domain (the self-cert path). --local issues offline via this box's own
 * in-mesh broker (stub ACME = self-signed, for wiring); a live LE cert is the rig leg.
 */
class MeshRequestCertCommand extends Command
{
    protected $signature = 'mesh:request-cert {domain} {subdomain}
        {--target= : optional bare IP to point the name at}
        {--grant-file= : JSON file with {grant, grant_signature} from an authority}
        {--broker= : broker server_id to POST to (default: discover a broker.tls holder)}
        {--local : issue via THIS box\'s own in-mesh broker (offline-capable)}';

    protected $description = 'Request + install a TLS cert for <subdomain>.<domain> from a mesh broker';

    public function handle(
        CertClientService $client,
        CertGrantService $grants,
        InMeshBrokerService $localBroker,
        CapabilityService $caps,
        MultiplexClient $mux,
        InstanceIdentityService $identity,
    ): int {
        $domain = strtolower((string) $this->argument('domain'));
        $subdomain = strtolower((string) $this->argument('subdomain'));
        $fqdn = $subdomain.'.'.$domain;

        $kc = $client->generateKeyAndCsr($fqdn);

        // Obtain the cert_grant: a delivered grant file, else mint locally if we are an authority.
        if ($file = $this->option('grant-file')) {
            $loaded = json_decode((string) @file_get_contents($file), true);
            if (! is_array($loaded) || ! isset($loaded['grant'], $loaded['grant_signature'])) {
                $this->error("Grant file {$file} is missing or malformed (expected {grant, grant_signature}).");

                return self::FAILURE;
            }
            $grant = (array) $loaded['grant'];
            $grantSig = (string) $loaded['grant_signature'];
        } else {
            try {
                $minted = $grants->mint($domain, $subdomain, $identity->serverId(), $identity->publicKey());
            } catch (Throwable $e) {
                $this->error('No grant available: '.$e->getMessage());
                $this->line('Provide --grant-file=<path> from an authority, or attest this box as an authority for the domain.');

                return self::FAILURE;
            }
            $grant = $minted['grant'];
            $grantSig = $minted['grant_signature'];
        }

        $body = $client->buildRequest($grant, $grantSig, $kc['csr'], $this->option('target') ?: null);

        // Issue — locally (offline) or over the mesh to a discovered broker.
        try {
            if ($this->option('local')) {
                $result = $localBroker->issue($body);
            } else {
                $brokerId = (string) ($this->option('broker') ?: ($caps->holdersOf('broker.tls')[0] ?? ''));
                if ($brokerId === '') {
                    $this->error('No broker.tls holder is discoverable — pass --broker=<server_id> or --local.');

                    return self::FAILURE;
                }
                $resp = $mux->reach($brokerId, 'POST', '/api/federation/cert-request', $body);
                if (! $resp->successful()) {
                    $this->error("Broker refused (HTTP {$resp->status()}): ".(string) $resp->body());

                    return self::FAILURE;
                }
                $result = (array) $resp->json();
            }
        } catch (Throwable $e) {
            $this->error('Cert issuance failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $paths = $client->install($fqdn, $kc['private_key'], (string) ($result['certificate'] ?? ''));
        $this->info("Cert installed for {$fqdn} (dns: ".(string) ($result['dns'] ?? 'n/a').').');
        $this->line('  key:  '.$paths['key_path']);
        $this->line('  cert: '.$paths['cert_path']);

        return self::SUCCESS;
    }
}
