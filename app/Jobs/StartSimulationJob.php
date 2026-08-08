<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * The tail of the "Activate & Scale Now + simulate at scale" chain (operator,
 * 2026-08-08, dev-only): after the eager build's institution provisioning
 * lands, start the simulated-world run — people at leaf grain, real
 * elections, real counts, seated chambers, governance acts. Guarded here as
 * well as at acceptance: a production instance (game_mode !== sandbox) or an
 * unset flag makes this a logged no-op, never a forged world.
 */
class StartSimulationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $instance = \App\Models\InstanceSettings::query()->whereNull('deleted_at')->first();
        if ($instance === null
            || $instance->game_mode !== 'sandbox'
            || ! (bool) $instance->simulate_at_scale) {
            Log::info('StartSimulationJob: skipped — not a sandbox instance with simulate_at_scale set.');

            return;
        }

        $exit = Artisan::call('sim:start');
        Log::info(sprintf(
            'StartSimulationJob: sim:start exited %d — %s',
            $exit, trim(substr(Artisan::output(), -300)),
        ));
    }
}
