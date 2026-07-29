<?php

namespace App\Console\Commands;

use App\Models\AutoscaleRun;
use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * maps:reopen — the CLI half of the "reopen map data" affordance (UI↔CLI
 * parity). CANONICAL SOURCE: JurisdictionController::reopenMaps — keep in sync.
 * Clears instance_settings.map_accepted_at so the geodata repair window
 * reopens. LEGAL ONLY while setup is incomplete: once setup_completed_at is
 * stamped the accepted dataset is the constitutional substrate and the gate
 * locks for good — that guard travels with the pair. Reopening PAUSES a live
 * autoscale run (repairs and sizing must not race). Idempotent.
 */
class MapsReopenCommand extends Command
{
    protected $signature = 'maps:reopen';

    protected $description = 'Reopen the geodata repair window (clear map acceptance) — refused once setup is complete';

    public function handle(): int
    {
        $instance = InstanceSettings::current();
        if (! $instance) {
            $this->error('Instance settings row is missing — bootstrap not complete.');

            return self::FAILURE;
        }

        if ($instance->isSetupComplete()) {
            $this->error('Setup is complete — the accepted map data is locked and cannot be reopened.');

            return self::FAILURE;
        }

        if ($instance->map_accepted_at === null) {
            $this->info('Repair window already open — nothing to do.');

            return self::SUCCESS;
        }

        $instance->forceFill(['map_accepted_at' => null])->save();

        // Reopening PAUSES a live run: repairs merge/soft-delete jurisdictions,
        // and sizing/sweeps must not build on rows the operator is retiring.
        $halted = false;
        $unfinished = AutoscaleRun::unfinished();
        if ($unfinished !== null) {
            $unfinished->forceFill(['halt_requested_at' => now()])->save();
            Artisan::call('autoscale:pump'); // park it now
            $halted = true;
        }

        $this->info('Map acceptance reopened — the geodata repair window is open again.'
            .($halted ? ' (live autoscale run signalled to halt)' : ''));

        return self::SUCCESS;
    }
}
