<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalViolation;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\NoReachableHolder;
use App\Services\Federation\ServiceReachService;
use Illuminate\Console\Command;

/**
 * mesh:reach — resolve where a LIVE service (matrix.homeserver / voice.sfu) reaches across the mesh when this
 * light node doesn't host it (Mesh Roles ★24, the mixed environment). Prints the ranked reachable holders +
 * the chosen reach (local / a capable peer / safe-degrade), feeding SOP writing + the rig campaign.
 *
 *   php artisan mesh:reach matrix.homeserver [--scope=<jurisdiction-id>]
 */
class MeshReachCommand extends Command
{
    protected $signature = 'mesh:reach
        {capability : a live-service channel — matrix.homeserver | voice.sfu}
        {--scope= : a jurisdiction id for the geo tiebreak (optional)}';

    protected $description = 'Resolve mesh reach for a live service (matrix.homeserver / voice.sfu) — ranked holders + chosen reach';

    public function handle(ServiceReachService $reach, CapabilityService $caps, InstanceIdentityService $identity): int
    {
        $identity->ensureIdentity();
        $capability = (string) $this->argument('capability');
        $scope = $this->option('scope') !== null && $this->option('scope') !== '' ? (string) $this->option('scope') : null;

        $this->info("Ranked reachable holders of {$capability}:");
        $ranked = $caps->holdersOfRanked($capability, $scope);
        if ($ranked === []) {
            $this->line('  (no peer holders known)');
        }
        foreach ($ranked as $r) {
            $this->line(sprintf('  %s  health=%d  latency=%s  %-10s %s%s',
                substr($r['server_id'], 0, 8).'…',
                $r['health_score'],
                $r['latency_ema_ms'] !== null ? $r['latency_ema_ms'].'ms' : '-',
                $r['attemptable'] ? 'reachable' : 'DOWN',
                $r['transport'] ?? '-',
                $r['distance_km'] !== null ? '  '.$r['distance_km'].'km' : '',
            ));
        }

        try {
            $p = $reach->reachLiveService($capability, $scope);
        } catch (ConstitutionalViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (NoReachableHolder $e) {
            $this->warn('[DEGRADE] '.$e->getMessage());

            return self::SUCCESS; // safe-degrade is the designed outcome, not a failure
        }

        if ($p['local']) {
            $this->info("[LOCAL] this node hosts {$capability} → serve locally ({$p['service_endpoint']}).");
        } else {
            $this->info(sprintf('[REACH] %s via %s (%s) → endpoint %s',
                substr($p['server_id'], 0, 8).'…',
                $p['transport'] ?? '-',
                $p['latency_ema_ms'] !== null ? $p['latency_ema_ms'].'ms' : '?',
                $p['service_endpoint'] ?? '(resolved by the foci slice)',
            ));
        }

        return self::SUCCESS;
    }
}
