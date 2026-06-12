<?php

namespace App\Jobs\Clocks;

use App\Services\ActivationService;
use App\Services\SettingsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * CLK-06 — Critical Population Threshold (population threshold, Art. II §1;
 * fires WF-ELE-02 / WF-JUR-01).
 *
 * Candidate jurisdictions = those with at least one ACTIVE residency
 * confirmation, NO legislature, and no activation row already past
 * boundary_loaded. One aggregate (GROUP BY jurisdiction over the
 * residency_confirmations_jurisdiction_active_idx partial index) — never a
 * per-row loop over the 951k jurisdictions; the candidate set is bounded
 * by where verified residents actually exist.
 *
 * Per candidate, the threshold resolves at EVALUATION time:
 * constitutional_settings.critical_population_threshold (own row →
 * ancestor walk) → config('cga.critical_population_default') (dev default
 * 1 — one verified resident activates; production tiers later, per owner
 * ruling #15). Crossings call ActivationService::onCriticalPopulation
 * (idempotent — re-runs never double-fire: the state guard skips rows
 * already past boundary_loaded).
 */
class EvaluateCriticalPopulationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(ActivationService $activation, SettingsResolver $settings): void
    {
        $candidates = DB::select("
            SELECT rc.jurisdiction_id, count(*) AS verified_residents
            FROM residency_confirmations rc
            WHERE rc.is_active
              AND NOT EXISTS (
                  SELECT 1 FROM legislatures l
                  WHERE l.jurisdiction_id = rc.jurisdiction_id
                    AND l.deleted_at IS NULL
              )
              AND NOT EXISTS (
                  SELECT 1 FROM jurisdiction_activations a
                  WHERE a.jurisdiction_id = rc.jurisdiction_id
                    AND a.deleted_at IS NULL
                    AND a.state <> 'boundary_loaded'
              )
            GROUP BY rc.jurisdiction_id
        ");

        $configDefault = (int) config('cga.critical_population_default', 1);

        foreach ($candidates as $candidate) {
            $threshold = $settings->resolveInt(
                $candidate->jurisdiction_id,
                'critical_population_threshold',
                $configDefault
            );

            if ((int) $candidate->verified_residents >= $threshold) {
                $activation->onCriticalPopulation(
                    $candidate->jurisdiction_id,
                    (int) $candidate->verified_residents,
                    $threshold
                );
            }
        }
    }
}
