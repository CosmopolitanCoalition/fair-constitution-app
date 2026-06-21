<?php

namespace App\Console\Commands;

use App\Models\FederationPeer;
use App\Services\Federation\CertGrantService;
use App\Services\Federation\MultiplexClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * mesh:cert-grant — the AUTHORITY side of cert-grant delivery (Mesh Roles — auto-delivery). An authority.
 * grant-holding box mints a cert_grant for a peer's name and PUSHES it to that peer over the mesh
 * (/api/federation/cert-grant), where the peer verifies it against our pinned key and stores it. The peer
 * then runs `mesh:request-cert <domain> <subdomain> --broker=<us>` with NO --grant-file — it picks up the
 * delivered grant automatically. This is what removes the operator copy-paste: the GRANT (data) rides the
 * mesh; only the "now run it" coordination is relayed.
 *
 * The grant carries only public keys + names + a signature — never a private key or the Cloudflare token.
 */
class MeshCertGrantCommand extends Command
{
    protected $signature = 'mesh:cert-grant {domain} {subdomain} {peer : grantee peer server_id or URL}';

    protected $description = 'Mint + deliver a cert_grant for a peer\'s name over the mesh (no copy-paste)';

    public function handle(CertGrantService $grants, MultiplexClient $mux): int
    {
        $domain = strtolower((string) $this->argument('domain'));
        $subdomain = strtolower((string) $this->argument('subdomain'));
        $needle = (string) $this->argument('peer');

        $peer = FederationPeer::query()->matchingNeedle($needle)->whereNull('deleted_at')->first();
        if ($peer === null || $peer->public_key === null) {
            $this->error("No trust-established peer matches [{$needle}] (discover + handshake it first).");

            return self::FAILURE;
        }

        try {
            $minted = $grants->mint($domain, $subdomain, (string) $peer->server_id, (string) $peer->public_key);
        } catch (Throwable $e) {
            $this->error('Cannot mint the grant: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $resp = $mux->reach((string) $peer->server_id, 'POST', '/api/federation/cert-grant', [
                'grant' => $minted['grant'],
                'grant_signature' => $minted['grant_signature'],
            ]);
        } catch (Throwable $e) {
            $this->error('Delivery failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $resp->successful()) {
            $this->error("Grantee refused the grant (HTTP {$resp->status()}): ".(string) $resp->body());

            return self::FAILURE;
        }

        $fqdn = $subdomain.'.'.$domain;
        $this->info("[DELIVERED] cert_grant for {$fqdn} → ".substr((string) $peer->server_id, 0, 8).' (stored on the peer).');
        $this->line("That box can now run:  php artisan mesh:request-cert {$domain} {$subdomain} --broker=".\App\Models\InstanceSettings::current()->server_id);

        return self::SUCCESS;
    }
}
