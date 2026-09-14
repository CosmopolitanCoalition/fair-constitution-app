<?php

namespace App\Jobs\Clocks;

use App\Models\ResidencyClaim;
use App\Services\AuditService;
use App\Services\ResidencyService;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CLK-05 — Residency Verification Threshold (accumulating threshold,
 * Art. I; Art. V §1; fires WF-CIV-02/03).
 *
 * For every claim under ping monitoring: recompute qualifyingDays (the
 * ST_Contains distinct-day count — never trusted from the denormalized
 * column) against the per-jurisdiction threshold resolved at EVALUATION
 * time (ResidencyService::thresholdDays). Crossings transition the claim
 * ping_monitoring → threshold_met and chain one audit entry (module
 * 'clocks', ref CLK-05).
 *
 * Deliberately NOT auto-verify: threshold_met surfaces the F-IND-006
 * confirmation panel ("this is my residence" | "correct the boundary");
 * verification stays a deliberate confirm step (ResidencyController →
 * ResidencyService::verify).
 *
 * ResidencyService::recordPing already flips threshold_met inline on each
 * ping (the cheap, event-driven path). This sweep is the CATCH-UP path:
 * thresholds LOWERED by amendment after the last ping, plus any claim
 * whose inline evaluation was missed. Idempotent — a claim already past
 * ping_monitoring is never touched, re-runs cannot double-transition
 * (state recheck under row lock).
 *
 * KEYSET-CHUNKED, RESUMABLE, HOST-SIZED (W-0187, THE ETL RULE). The old
 * unbounded ->cursor() over every ping_monitoring claim held one server-side
 * cursor open for the whole pass. This walks the id keyset in host-sized
 * chunks: each chunk is bounded, its work committed per claim, and a kill
 * costs one chunk. A crossed claim leaves ping_monitoring, so a resume never
 * repeats it and never skips one; progress is logged per chunk.
 */
class EvaluateResidencyThresholdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(ResidencyService $residency, AuditService $audit): void
    {
        $limit   = HostCapacity::sweepChunk();
        $afterId = null;
        $seen    = 0;

        while (true) {
            $cursor = $this->runChunk($residency, $audit, $afterId, $limit, $seen);

            if ($cursor === null) {
                break;
            }

            $afterId = $cursor;
            Log::info('EvaluateResidencyThresholdsJob: chunk done', ['seen' => $seen, 'after' => $afterId]);
        }
    }

    /**
     * Process ONE keyset chunk of ping_monitoring claims after $afterId.
     * Returns the last claim id seen (the next cursor), or null when the
     * chunk was empty (the pass is complete). $seen accumulates by reference
     * so the caller can log real progress.
     */
    public function runChunk(ResidencyService $residency, AuditService $audit, ?string $afterId, int $limit, int &$seen = 0): ?string
    {
        $claims = ResidencyClaim::query()
            ->where('status', ResidencyClaim::STATUS_PING_MONITORING)
            ->when($afterId !== null, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($claims->isEmpty()) {
            return null;
        }

        $lastId = null;
        foreach ($claims as $claim) {
            $lastId = (string) $claim->id;
            $seen++;

            $days      = $residency->qualifyingDays($claim);
            $threshold = $residency->thresholdDays($claim);

            if ($days < $threshold) {
                // Keep the denormalized meter honest even below threshold.
                if ($days !== (int) $claim->qualifying_days) {
                    $claim->forceFill(['qualifying_days' => $days])->save();
                }

                continue;
            }

            DB::transaction(function () use ($claim, $days, $threshold, $audit) {
                $fresh = ResidencyClaim::query()->whereKey($claim->id)->lockForUpdate()->first();

                // Idempotency: only the monitoring → threshold_met edge.
                if ($fresh === null || $fresh->status !== ResidencyClaim::STATUS_PING_MONITORING) {
                    return;
                }

                $fresh->forceFill([
                    'status'           => ResidencyClaim::STATUS_THRESHOLD_MET,
                    'qualifying_days'  => $days,
                    'threshold_met_at' => now(),
                ])->save();

                // System event (clock crossing), not a form filing — chained
                // via AuditService directly. Counts only, never coordinates.
                $audit->append(
                    module: 'clocks',
                    event: 'residency_threshold_met',
                    payload: [
                        'claim_id'        => $fresh->id,
                        'qualifying_days' => $days,
                        'threshold_days'  => $threshold,
                        'fires_workflow'  => 'WF-CIV-02',
                    ],
                    ref: 'CLK-05',
                    actorId: null,
                    jurisdictionId: $fresh->jurisdiction_id,
                );
            });
        }

        return $lastId;
    }
}
