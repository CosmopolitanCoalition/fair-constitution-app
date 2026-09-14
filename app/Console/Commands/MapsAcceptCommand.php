<?php

namespace App\Console\Commands;

use App\Services\Setup\MapAcceptanceOptions;
use App\Services\Setup\MapAcceptanceResult;
use App\Services\Setup\MapAcceptanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * maps:accept — the CLI half of the planet-scope "Accept Map Data & Continue"
 * button (UI↔CLI parity). Both doors, and a backup restore, route through
 * MapAcceptanceService, so the state left behind is identical: the repair
 * window closes, the instance is stamped, the activation mode is recorded, and
 * (under eager) the full-scale autoscale run starts.
 *
 * This command once drifted from the endpoint — it had no verifier gate, wrote
 * no mode, and created a run with the wrong status. The shared service removed
 * that drift; the command now only resolves options and renders the result.
 *
 * The repair-plane acknowledgment gate travels with the pair: open geodata
 * flags block acceptance unless --acknowledge is passed (the confirm dialog's
 * role in the UI). Operator-trusted by construction (box shell); the
 * controller enforces is_operator on its side. Idempotent: re-running after
 * acceptance resumes a halted run and otherwise no-ops.
 */
class MapsAcceptCommand extends Command
{
    protected $signature = 'maps:accept
                            {--mode=eager : Activation mode — eager | population | manual (eager starts the planet build)}
                            {--simulate : Under eager on a sandbox world, populate with simulated residents after the build}
                            {--acknowledge : Proceed despite open geodata flags (the confirm-dialog acknowledgment)}';

    protected $description = 'Accept map data — close the repair window, record the mode, and start the full-scale autoscale run under eager';

    public function handle(MapAcceptanceService $service): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['eager', 'population', 'manual'], true)) {
            $this->error("Unknown --mode '{$mode}'. Use eager, population or manual.");

            return self::FAILURE;
        }

        $opts = new MapAcceptanceOptions(
            mode: $mode,
            simulateAtScale: (bool) $this->option('simulate'),
            acknowledgeOpenFlags: (bool) $this->option('acknowledge'),
            startAutoscale: false,
            // Eager gates on the world-build verifier, exactly as the endpoint.
            gateOnVerifier: $mode === 'eager',
            initiatorUserId: null, // the CLI has no request user
        );

        $result = $service->accept($opts);
        $p = $result->payload;

        switch ($result->outcome) {
            case MapAcceptanceResult::MISSING_INSTANCE:
                $this->error('Instance settings row is missing — bootstrap not complete.');

                return self::FAILURE;

            case MapAcceptanceResult::WORLD_BUILD_INCOMPLETE:
                $legs = $p['progress']['legislatures'] ?? [];
                $app = $p['progress']['apportionment'] ?? [];
                $this->error('World build incomplete — acceptance refused. '
                    .'Unsized parents: '.($legs['unsized_parents'] ?? '?').', '
                    .'unsized leaves: '.($legs['unsized_leaves'] ?? '?').', '
                    .'apportionment open: '.($app['open'] ?? '?').', failed: '.($app['failed'] ?? '?').'.');

                return self::FAILURE;

            case MapAcceptanceResult::REQUIRES_ACKNOWLEDGMENT:
                $f = $p['open_flags'];
                $this->error(sprintf(
                    'Open geodata flags block acceptance: %d critical, %d warning, %d info. Re-run with --acknowledge to proceed.',
                    $f['critical'], $f['warning'], $f['info']
                ));

                return self::FAILURE;

            case MapAcceptanceResult::ALREADY_LIVE:
                $this->info("Already accepted — autoscale run {$result->run?->id} is ".($p['run_status'] ?? '?').' (nothing to do).');

                return self::SUCCESS;

            case MapAcceptanceResult::RESUMED:
                Artisan::call('autoscale:pump'); // pump kick AFTER the locked tx commits
                $this->info("Already accepted — resumed halted autoscale run {$result->run?->id}.");

                return self::SUCCESS;

            case MapAcceptanceResult::ALREADY_ACCEPTED:
                $this->info('Already accepted — no unfinished autoscale run.');

                return self::SUCCESS;
        }

        // ACCEPTED — a fresh stamp.
        $f = $p['open_flags'] ?? ['critical' => 0, 'warning' => 0, 'info' => 0];

        if ($p['deferred'] ?? false) {
            $this->info(sprintf(
                'Map data accepted — mode %s: planet-wide autoscale deferred '.
                '(open flags at acceptance: %d critical, %d warning, %d info).',
                $p['mode'] ?? $mode, $f['critical'], $f['warning'], $f['info']
            ));

            return self::SUCCESS;
        }

        if ($result->kickPump) {
            Artisan::call('autoscale:pump');
        }

        $this->info(sprintf(
            'Map data accepted (open flags at acceptance: %d critical, %d warning, %d info). Autoscale run %s started.',
            $f['critical'], $f['warning'], $f['info'], $result->run?->id
        ));

        return self::SUCCESS;
    }
}
