<?php

namespace App\Console\Commands;

use App\Jobs\AutoscaleOrchestratorJob;
use App\Models\AutoscaleRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CLI entry to the full-scale autoscale (operator ruling 2026-07-18) — the
 * same run "Accept Map Data & Continue" starts. Useful for staged tests
 * (--adm-max=2), resuming after a reboot, or starting when Horizon was down
 * at acceptance time.
 *
 *   php artisan districting:autoscale               # start (or resume) at full depth
 *   php artisan districting:autoscale --adm-max=2   # staged test: countries + provinces
 *   php artisan districting:autoscale --resume      # also requeue review/failed/halted items
 *   php artisan districting:autoscale --halt        # signal every loop to stop at the next boundary
 */
class AutoscaleDistrictingCommand extends Command
{
    protected $signature = 'districting:autoscale
                            {--adm-max= : Deepest adm level to size/map (default: config cga.autoscale_adm_max, 6)}
                            {--resume : Resume the newest unfinished run; also requeues review/failed/halted items}
                            {--halt : Set the halt flag instead of starting anything}';

    protected $description = 'Run the full-scale autoscale: size + district-map every jurisdiction (True All Scale)';

    public function handle(): int
    {
        if ((bool) $this->option('halt')) {
            Cache::put(AutoscaleRun::HALT_CACHE_KEY, true, 86400);
            $this->info('Halt flag set — the orchestrator parks the run at its next tick; in-flight sweeps finish or halt at their next scope boundary.');
            return self::SUCCESS;
        }

        // --resume also revives a DONE run to retry its review/failed/halted
        // items (hand-fixed legislatures are adopted, never re-swept). A
        // fresh full run over a completed world is never started implicitly.
        $existing = AutoscaleRun::unfinished()
            ?? ((bool) $this->option('resume')
                ? AutoscaleRun::query()->where('status', 'done')->orderByDesc('created_at')->first()
                : null);

        if ($existing !== null) {
            if ($this->option('adm-max') !== null) {
                $this->warn("--adm-max is ignored: resuming existing run {$existing->id} at its own depth (adm ≤ {$existing->adm_max}).");
            }

            if ((bool) $this->option('resume')) {
                $requeued = DB::table('autoscale_items')
                    ->where('run_id', $existing->id)
                    ->whereIn('status', ['review', 'failed', 'halted'])
                    ->update(['status' => 'pending', 'reason' => null, 'updated_at' => now()]);
                $this->info("Requeued {$requeued} review/failed/halted items.");
                if ($existing->status === 'done') {
                    $existing->forceFill(['status' => 'mapping', 'finished_at' => null])->save();
                }
            }

            Cache::forget(AutoscaleRun::HALT_CACHE_KEY);
            AutoscaleOrchestratorJob::dispatch((string) $existing->id);
            $this->info("Resuming autoscale run {$existing->id} (status: {$existing->status}).");
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

        Cache::forget(AutoscaleRun::HALT_CACHE_KEY);
        AutoscaleOrchestratorJob::dispatch((string) $run->id);

        $this->info("Autoscale run {$run->id} dispatched (adm ≤ {$admMax}). Watch the Step-3 dashboard or autoscale_runs/autoscale_items.");
        return self::SUCCESS;
    }
}
