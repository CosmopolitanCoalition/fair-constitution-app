<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Boot every HALF-ACTIVATED jurisdiction — one that holds a legislature (seat
 * math) but no active election board, so its district plans cannot be
 * accepted (F-ELB-008 needs R-08, and the bootstrap board is that substrate).
 *
 * Why a bulk job: places seeded before 2026-08-08's full-boot fix — and any
 * subtree queued under it — are half-activated in the hundreds. Healing them
 * one row at a time is nine pages of clicking; this is the same idempotent
 * per-node boot, chunked and resumable (a kill costs the in-flight node, and
 * a re-dispatch skips everything already booted).
 */
class FinishActivationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 0;

    public function handle(): void
    {
        // Cheap id/slug roster first (THE ETL RULE: bound the INPUT).
        $roster = DB::table('jurisdictions as j')
            ->join('legislatures as l', function ($join) {
                $join->on('l.jurisdiction_id', '=', 'j.id')->whereNull('l.deleted_at');
            })
            ->whereNull('j.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('election_boards as eb')
                ->whereColumn('eb.jurisdiction_id', 'j.id')
                ->where('eb.status', 'active')->whereNull('eb.deleted_at'))
            ->orderBy('j.adm_level')->orderBy('j.id')
            ->pluck('j.slug')
            ->all();

        $booted = 0;
        $failed = 0;
        foreach ($roster as $i => $slug) {
            // One bad node must never end the pass — 419 places behind it
            // still deserve their boards (the all-or-nothing-is-a-bug law).
            try {
                $exit = Artisan::call('jurisdiction:activate', ['slug' => $slug, '--force' => true]);
                $exit === 0 ? $booted++ : $failed++;
                if ($exit !== 0) {
                    Log::warning(sprintf('FinishActivationsJob: %s exited %d', $slug, $exit));
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning(sprintf('FinishActivationsJob: %s threw — %s', $slug, $e->getMessage()));
            }
            if (($i + 1) % 50 === 0) {
                Log::info(sprintf('FinishActivationsJob: %d/%d — %d booted, %d failed',
                    $i + 1, count($roster), $booted, $failed));
            }
        }

        Log::info(sprintf('FinishActivationsJob COMPLETE: %d booted, %d failed of %d half-activated.',
            $booted, $failed, count($roster)));
    }
}
