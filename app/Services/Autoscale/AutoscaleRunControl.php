<?php

namespace App\Services\Autoscale;

use App\Models\AutoscaleRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Shared halt/resume control for the full-scale autoscale run — the ONE code
 * path behind BOTH the Step-3 dashboard buttons (SetupController::autoscaleHalt
 * / autoscaleResume) and the `autoscale:halt` / `autoscale:resume` CLIs. UI↔CLI
 * parity means the window checks and item bookkeeping travel with the pair;
 * extracting them here (the SimRunControl precedent) is what keeps the two
 * doors from drifting.
 *
 * The operator gate lives with the CALLER, by design: the controller enforces
 * `is_operator` on the request; the CLI is operator-trusted by construction
 * (it runs in the box shell). This service performs the run state change only —
 * identical whichever door called it.
 */
class AutoscaleRunControl
{
    /**
     * Halt the active run: stamp halt_requested_at and pump once so the run
     * parks now rather than at the next scheduled tick.
     *
     * @return array{ok:bool, error?:string}
     */
    public function halt(): array
    {
        $run = AutoscaleRun::unfinished();
        if ($run === null) {
            return ['ok' => false, 'error' => 'No active autoscale run.'];
        }
        $run->forceFill(['halt_requested_at' => now()])->save();
        Artisan::call('autoscale:pump'); // park it now, not in a minute

        return ['ok' => true, 'run_id' => (string) $run->id];
    }

    /**
     * Resume a halted run. With $requeueReview it also revives a DONE run's
     * review/failed/halted items (dropping their stale scope trees) so the
     * pump re-mints fresh root scopes — the dashboard's "Retry all review
     * items" path. Clears halt_requested_at and pumps.
     *
     * @return array{ok:bool, run_id?:string, error?:string}
     */
    public function resume(bool $requeueReview = false): array
    {
        $run = AutoscaleRun::unfinished()
            ?? ($requeueReview
                ? AutoscaleRun::query()->where('status', 'done')->orderByDesc('created_at')->first()
                : null);
        if ($run === null) {
            return ['ok' => false, 'error' => 'No autoscale run to resume.'];
        }

        if ($requeueReview) {
            // STATUS-CLEAR, NEVER ROW-DELETE (single-home law, 2026-08-31):
            // scope rows are FACTS; a retry resets their work state in
            // bounded chunks. A gate-refused header returns to review, not
            // pending — a refusal only moves when the data changes.
            $requeued = DB::table('apportionment_ledger')
                ->whereIn('map_status', ['review', 'failed'])
                ->whereNull('gate_reason')
                ->pluck('legislature_id');
            foreach ($requeued->chunk(5000) as $chunk) {
                DB::table('apportionment_ledger_scopes')
                    ->whereIn('legislature_id', $chunk)
                    ->update([
                        'status' => 'pending', 'claim_token' => null, 'reason' => null,
                        'started_at' => null, 'finished_at' => null,
                        'retry_count' => 0, 'updated_at' => now(),
                    ]);
                DB::table('apportionment_ledger')
                    ->whereIn('legislature_id', $chunk)
                    ->update([
                        'map_status'  => 'pending', 'reason' => null,
                        'claim_token' => null, 'updated_at' => now(),
                    ]);
            }
        }

        if ($run->status === 'done') {
            $run->forceFill(['status' => 'mapping', 'finished_at' => null])->save();
        }

        $run->forceFill(['halt_requested_at' => null])->save();
        Artisan::call('autoscale:pump');

        return ['ok' => true, 'run_id' => (string) $run->id];
    }
}
