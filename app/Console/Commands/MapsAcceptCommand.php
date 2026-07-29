<?php

namespace App\Console\Commands;

use App\Models\AutoscaleRun;
use App\Models\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * maps:accept — the CLI half of the planet-scope "Accept Map Data & Continue"
 * button (UI↔CLI parity). CANONICAL SOURCE: JurisdictionController::acceptMaps
 * — this command mirrors its state changes and guards; keep the two in sync.
 * Stamps instance_settings.map_accepted_at (which CLOSES the repair window),
 * advances setup_step_completed to ≥2, and starts (or resumes) the full-scale
 * autoscale run via the pump.
 *
 * The repair-plane acknowledgment gate travels with the pair: open geodata
 * flags block acceptance unless --acknowledge is passed (the confirm dialog's
 * role in the UI). Operator-trusted by construction (box shell); the
 * controller enforces is_operator on its side. Idempotent: re-running after
 * acceptance resumes a halted run and otherwise no-ops.
 */
class MapsAcceptCommand extends Command
{
    protected $signature = 'maps:accept {--acknowledge : proceed despite open geodata flags (the confirm-dialog acknowledgment)}';

    protected $description = 'Accept map data — close the repair window and start the full-scale autoscale run';

    public function handle(): int
    {
        // Locked check-then-stamp: repairs take the same instance_settings row
        // lock as their first statement, so acceptance serializes against an
        // in-flight repair instead of racing it (mirrors the controller).
        $outcome = DB::transaction(function () {
            $instance = InstanceSettings::query()->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $instance) {
                return ['status' => 'missing'];
            }

            if ($instance->map_accepted_at) {
                // Re-accept after acceptance RESUMES an unfinished run (the
                // reboot/halt recovery path); a live run is left alone.
                $unfinished = AutoscaleRun::unfinished();
                if ($unfinished !== null) {
                    if ($unfinished->status !== 'halted' && ! $unfinished->haltRequested()) {
                        return ['status' => 'already_live', 'run_id' => (string) $unfinished->id, 'run_status' => $unfinished->status];
                    }
                    $unfinished->forceFill(['halt_requested_at' => null])->save();

                    return ['status' => 'resumed', 'run_id' => (string) $unfinished->id];
                }

                return ['status' => 'already_accepted'];
            }

            // Repair-plane acknowledgment gate: accepting CLOSES the repair
            // window, so open flags must be acknowledged first.
            $openRow = DB::table('geodata_flags')
                ->whereNull('deleted_at')
                ->where('status', 'open')
                ->selectRaw("
                    COUNT(*) FILTER (WHERE severity = 'critical') AS critical,
                    COUNT(*) FILTER (WHERE severity = 'warning')  AS warning,
                    COUNT(*) FILTER (WHERE severity = 'info')     AS info
                ")
                ->first();
            $openFlags = [
                'critical' => (int) ($openRow->critical ?? 0),
                'warning'  => (int) ($openRow->warning ?? 0),
                'info'     => (int) ($openRow->info ?? 0),
            ];
            if (array_sum($openFlags) > 0 && ! $this->option('acknowledge')) {
                return ['status' => 'requires_ack', 'open_flags' => $openFlags];
            }

            $instance->forceFill([
                'map_accepted_at'      => now(),
                'setup_step_completed' => max((int) $instance->setup_step_completed, 2),
            ])->save();

            return ['status' => 'accepted', 'open_flags' => $openFlags];
        });

        switch ($outcome['status']) {
            case 'missing':
                $this->error('Instance settings row is missing — bootstrap not complete.');

                return self::FAILURE;
            case 'already_live':
                $this->info("Already accepted — autoscale run {$outcome['run_id']} is {$outcome['run_status']} (nothing to do).");

                return self::SUCCESS;
            case 'resumed':
                Artisan::call('autoscale:pump'); // pump kick AFTER the locked tx commits
                $this->info("Already accepted — resumed halted autoscale run {$outcome['run_id']}.");

                return self::SUCCESS;
            case 'already_accepted':
                $this->info('Already accepted — no unfinished autoscale run.');

                return self::SUCCESS;
            case 'requires_ack':
                $f = $outcome['open_flags'];
                $this->error(sprintf(
                    'Open geodata flags block acceptance: %d critical, %d warning, %d info. Re-run with --acknowledge to proceed.',
                    $f['critical'], $f['warning'], $f['info']
                ));

                return self::FAILURE;
        }

        // status = accepted → start (or resume) the run and pump.
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            $run = AutoscaleRun::create([
                'status'            => 'queued',
                'adm_max'           => (int) config('cga.autoscale_adm_max', 6),
                'initiator_user_id' => null, // the CLI has no request user
                'template'          => null,
            ]);
        } else {
            $run->forceFill(['halt_requested_at' => null])->save();
        }
        Artisan::call('autoscale:pump');

        $f = $outcome['open_flags'];
        $this->info(sprintf(
            'Map data accepted (open flags at acceptance: %d critical, %d warning, %d info). Autoscale run %s started.',
            $f['critical'], $f['warning'], $f['info'], $run->id
        ));

        return self::SUCCESS;
    }
}
