<?php

namespace App\Console\Commands;

use App\Models\AutoscaleRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * CLI entry to the full-scale autoscale — the same run "Accept Map Data &
 * Continue" starts. Pull engine (2026-07-19): this command only shapes RUN
 * STATE (create / halt-flag / resume-flag); the scheduler's every-minute
 * `autoscale:pump` is the liveness root, and the inline pump call here just
 * skips the first minute of waiting.
 *
 *   php artisan districting:autoscale               # start (or resume) at full depth
 *   php artisan districting:autoscale --adm-max=2   # staged test: countries + provinces
 *   php artisan districting:autoscale --resume      # also requeue review/failed items
 *   php artisan districting:autoscale --halt        # request a halt (workers park at claim boundaries)
 */
class AutoscaleDistrictingCommand extends Command
{
    protected $signature = 'districting:autoscale
                            {--adm-max= : Deepest adm level to size/map (default: config cga.autoscale_adm_max, 6)}
                            {--resume : Resume the newest unfinished run; also requeues review/failed items}
                            {--halt : Request a halt instead of starting anything}';

    protected $description = 'Run the full-scale autoscale: size + district-map every jurisdiction (True All Scale)';

    public function handle(): int
    {
        if ((bool) $this->option('halt')) {
            $run = AutoscaleRun::unfinished();
            if ($run === null) {
                $this->error('No active autoscale run to halt.');
                return self::FAILURE;
            }
            $run->forceFill(['halt_requested_at' => now()])->save();
            Artisan::call('autoscale:pump'); // park it now, not in a minute
            $this->info('Halt requested — the run is parked; workers stop at their next claim boundary.');
            return self::SUCCESS;
        }

        // --resume also revives a DONE run to retry its review/failed items
        // (hand-fixed legislatures are adopted, never re-swept). A fresh full
        // run over a completed world is never started implicitly.
        $existing = AutoscaleRun::unfinished()
            ?? ((bool) $this->option('resume')
                ? AutoscaleRun::query()->where('status', 'done')->orderByDesc('created_at')->first()
                : null);

        if ($existing !== null) {
            if ($this->option('adm-max') !== null) {
                $this->warn("--adm-max is ignored: resuming existing run {$existing->id} at its own depth (adm ≤ {$existing->adm_max}).");
            }

            if ((bool) $this->option('resume')) {
                $requeuedIds = DB::table('autoscale_items')
                    ->where('run_id', $existing->id)
                    ->whereIn('status', ['review', 'failed', 'halted'])
                    ->pluck('id');
                if ($requeuedIds->isNotEmpty()) {
                    // Stale attempt scope trees go; the pump re-mints roots.
                    DB::table('autoscale_scopes')->whereIn('item_id', $requeuedIds)->delete();
                    DB::table('autoscale_items')
                        ->whereIn('id', $requeuedIds)
                        ->update([
                            'status' => 'pending', 'reason' => null,
                            'claim_token' => null, 'updated_at' => now(),
                        ]);
                }
                $this->info('Requeued '.count($requeuedIds).' review/failed/halted items.');
                if ($existing->status === 'done') {
                    $existing->forceFill(['status' => 'mapping', 'finished_at' => null])->save();
                }
            }

            $existing->forceFill(['halt_requested_at' => null])->save();
            Artisan::call('autoscale:pump');
            $this->info("Resuming autoscale run {$existing->id} (status: {$existing->status}). The pump owns it from here.");
            return self::SUCCESS;
        }

        $admMax = $this->option('adm-max') !== null
            ? (int) $this->option('adm-max')
            : (int) config('cga.autoscale_adm_max', 6);

        // CLI runs carry the founding operator as the F-ELB-008 filing actor
        // (leaf-giant line splits refuse the null system actor — R-08).
        $initiator = DB::table('users')
            ->where('is_operator', true)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->value('id');
        if ($initiator === null) {
            $this->error('No operator user exists — leaf-giant line splits need a filing actor. Create the founder first.');
            return self::FAILURE;
        }

        $run = AutoscaleRun::create([
            'status'            => 'queued',
            'adm_max'           => $admMax,
            'initiator_user_id' => $initiator,
            'template'          => null,
        ]);

        Artisan::call('autoscale:pump');

        $this->info("Autoscale run {$run->id} created (adm ≤ {$admMax}). The pump owns it — watch the Step-3 dashboard.");
        return self::SUCCESS;
    }
}
