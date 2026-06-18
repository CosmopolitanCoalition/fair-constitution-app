<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MeshProbeService;
use App\Services\Federation\TransportService;
use Illuminate\Console\Command;

/**
 * mesh:doctor [target] — the survival-mesh reachability self-test (Phase G, G8b).
 *
 * The whole mesh value prop is "if one survives, all survive" — but the bytes are dialed
 * from INSIDE the app container, and the overlay daemon lives on the HOST, so a registered
 * transport address can be advertised yet be unroutable from where it actually matters.
 * This command surfaces that truth instead of a blind "✓ done": it prints what THIS node
 * advertises, and — given a peer — dials every known transport for it FROM THE CONTAINER
 * (via the shared MeshProbeService, the same probe the federation-console GUI runs) and
 * reports which rungs are live + whether the constitutional_version agrees.
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
        InstanceIdentityService $identity,
        MeshProbeService $probe,
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

        $result = $probe->probe($target);

        if ($result['total'] === 0) {
            $this->warn("No known transport for server_id {$target} — discover + handshake it first, or pass a URL.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Probing '.$target.' over '.$result['total'].' transport(s) FROM INSIDE the container:');

        foreach ($result['rungs'] as $r) {
            if ($r['error'] !== null) {
                $this->line(sprintf('  [%-10s] %-44s UNREACHABLE — %s', $r['transport'], $r['url'], $r['error']));
            } elseif (! $r['reachable']) {
                $this->line(sprintf('  [%-10s] %-44s HTTP %d (%dms)', $r['transport'], $r['url'], $r['http_status'], $r['latency_ms']));
            } else {
                $vNote = $r['version'] === ''
                    ? 'no version advertised'
                    : ($r['version_match'] ? 'version MATCH' : 'version MISMATCH — sync will refuse');
                $this->line(sprintf('  [%-10s] %-44s OK %dms — %s', $r['transport'], $r['url'], $r['latency_ms'], $vNote));
            }
        }

        $this->newLine();
        $this->line($result['reached'].'/'.$result['total'].' transport(s) reached '.$target.'.');

        return $result['reached'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
