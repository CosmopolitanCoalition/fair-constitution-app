<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\TransportEndpoints;
use App\Services\Federation\TransportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * mesh:doctor [target] — the survival-mesh reachability self-test (Phase G, G8b).
 *
 * The whole mesh value prop is "if one survives, all survive" — but the bytes are dialed
 * from INSIDE the app container, and the overlay daemon lives on the HOST, so a registered
 * transport address can be advertised yet be unroutable from where it actually matters.
 * This command surfaces that truth instead of a blind "✓ done": it prints what THIS node
 * advertises, and — given a peer — dials every known transport for it FROM THE CONTAINER
 * and reports which rungs are actually live (and whether the constitutional_version agrees,
 * since a mismatch makes Meter C refuse sync).
 *
 *   php artisan mesh:doctor                                   # what do we advertise?
 *   php artisan mesh:doctor http://[200:abcd::1]:8081         # probe a URL directly
 *   php artisan mesh:doctor 7f3e...-uuid                      # probe a known peer's whole ladder
 */
class MeshDoctorCommand extends Command
{
    protected $signature = 'mesh:doctor {target? : a peer server_id, or a base URL, to probe over every known transport}';

    protected $description = 'Diagnose mesh reachability from inside the app container (the survival-mesh self-test)';

    public function handle(
        TransportService $transports,
        TransportEndpoints $endpoints,
        FederationClient $client,
        InstanceIdentityService $identity,
    ): int {
        $settings = InstanceSettings::current();
        $self = $transports->selfEndpoints();

        $this->line('This node:');
        $this->line('  server_id              : '.$identity->serverId());
        $this->line('  federation_enabled     : '.($settings->federation_enabled ? 'yes' : 'NO — run federation:init'));
        $this->line('  constitutional_version : '.$settings->constitutionalVersion());
        $this->line('  advertised transports  : '.($self === []
            ? 'NONE — run transport:register'
            : implode(', ', array_map(fn ($e) => $e['transport'].'='.$e['url'], $self))));

        $target = trim((string) $this->argument('target'));

        if ($target === '') {
            $this->newLine();
            $this->info('No target given. To verify TWO-WAY reachability, run on EACH box:');
            $this->line('  php artisan mesh:doctor <the-other-box-server_id-or-url>');

            return self::SUCCESS;
        }

        // Resolve the (transport, url) rungs to probe.
        if (Str::isUuid($target)) {
            $rungs = array_map(
                fn ($r) => ['transport' => $r['transport'], 'url' => $r['url']],
                $endpoints->forPeer($target),
            );
            if ($rungs === []) {
                $this->warn("No known transport for server_id {$target} — discover + handshake it first, or pass a URL.");

                return self::FAILURE;
            }
        } else {
            $rungs = [['transport' => '(direct)', 'url' => rtrim($target, '/')]];
        }

        $this->newLine();
        $this->line('Probing '.$target.' over '.count($rungs).' transport(s) FROM INSIDE the container:');

        $timeout = max(1, (int) config('cga.federation_probe_timeout_seconds', 5));
        $reached = 0;

        foreach ($rungs as $rung) {
            $startedMs = (int) (microtime(true) * 1000);

            try {
                $resp = $client->get($rung['url'], '/api/federation/identity', [], $timeout);
            } catch (\Throwable $e) {
                $this->line(sprintf('  [%-10s] %-44s UNREACHABLE — %s', $rung['transport'], $rung['url'], $e->getMessage()));

                continue;
            }

            $ms = (int) (microtime(true) * 1000) - $startedMs;

            if (! $resp->successful()) {
                $this->line(sprintf('  [%-10s] %-44s HTTP %d (%dms)', $rung['transport'], $rung['url'], $resp->status(), $ms));

                continue;
            }

            $reached++;
            $peerCv = (string) (((array) $resp->json())['constitutional_version'] ?? '');
            $vNote = $peerCv === ''
                ? 'no version advertised'
                : ($peerCv === $settings->constitutionalVersion() ? 'version MATCH' : 'version MISMATCH — sync will refuse');
            $this->line(sprintf('  [%-10s] %-44s OK %dms — %s', $rung['transport'], $rung['url'], $ms, $vNote));
        }

        $this->newLine();
        $this->line($reached.'/'.count($rungs).' transport(s) reached '.$target.'.');

        return $reached > 0 ? self::SUCCESS : self::FAILURE;
    }
}
