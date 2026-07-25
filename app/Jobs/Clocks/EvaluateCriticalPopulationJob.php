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
 * Candidate jurisdictions = LIVE jurisdictions with at least one ACTIVE
 * residency confirmation, NO seated or elected membership, and no activation
 * row already past boundary_loaded.
 *
 * ── Why the predicate changed (lane 3, 2026-07-25) ────────────────────────
 * This job previously required `NOT EXISTS (legislatures ...)`. That was
 * correct when "has a legislature" meant "is already governed". After the
 * autoscale run seeded one legislature for EVERY live jurisdiction it means
 * nothing of the sort — a `forming` chamber with zero members is scaffolding.
 *
 * The effect was not a merely empty candidate set; it was an INVERTED one.
 * The query never joined `jurisdictions`, so the only rows that could still
 * pass were the SOFT-DELETED jurisdictions (measured: 1,206 on the seeded
 * planet). `residency_confirmations` has an FK ON DELETE CASCADE, but
 * jurisdictions are soft-deleted so the cascade never fires and confirmations
 * outlive deletion — and ActivationService::onCriticalPopulation performs no
 * liveness check of its own. The first thing this sweep would ever have done
 * on a populated instance is boot a government inside a deleted jurisdiction.
 *
 * Hence three deliberate clauses, each of which a later "cleanup" would
 * otherwise undo:
 *
 *  1. The `jurisdictions` JOIN is a LIVENESS RAIL, not an incidental lookup.
 *     It must survive every variant of this query.
 *  2. The membership anti-join is LOAD-BEARING, not belt-and-braces.
 *     Certification can seat a chamber without ever calling ActivationService
 *     (activate() has exactly two callers: JurisdictionActivateCommand and
 *     ElectionsDemoCommand), so a seated-but-unactivated jurisdiction is
 *     reachable and would otherwise get `critical_population` written on top
 *     of a working government.
 *  3. It tests "no membership record AT ALL", not "is anyone seated".
 *     Vacancy in legislature_members is a `status` change with vacated_at,
 *     not a soft delete. This shape deliberately matches the codebase's own
 *     definition of memberless, ActivationService::isMemberlessForming().
 *     Narrowing it to `status = 'seated'` would re-open a jurisdiction whose
 *     entire chamber had vacated.
 *
 * Reads are keyset-chunked over the residency table (THE ETL RULE): the
 * candidate set is bounded by where verified residents actually exist, never
 * by the ~955k jurisdiction table, and no run materialises the whole set.
 *
 * Per candidate the threshold resolves at EVALUATION time via
 * ActivationService::thresholdFor() — the single seam that both this sweep
 * and `jurisdiction:activate` share. Crossings call onCriticalPopulation
 * (idempotent — the state guard skips rows already past boundary_loaded).
 */
class EvaluateCriticalPopulationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Keyset page size over the residency table. */
    private const CHUNK = 5000;

    public function handle(ActivationService $activation, SettingsResolver $settings): void
    {
        $afterId = '00000000-0000-0000-0000-000000000000';

        while (true) {
            $candidates = DB::select('
                SELECT rc.jurisdiction_id,
                       count(*)          AS verified_residents,
                       max(j.population) AS population
                FROM residency_confirmations rc
                JOIN jurisdictions j
                  ON j.id = rc.jurisdiction_id
                 AND j.deleted_at IS NULL          -- LIVENESS RAIL (see docblock)
                WHERE rc.is_active
                  AND rc.jurisdiction_id > ?
                  AND NOT EXISTS (
                      SELECT 1 FROM jurisdiction_activations a
                      WHERE a.jurisdiction_id = rc.jurisdiction_id
                        AND a.deleted_at IS NULL
                        AND a.state <> \'boundary_loaded\'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM legislatures l
                      JOIN legislature_members lm
                        ON lm.legislature_id = l.id
                       AND lm.deleted_at IS NULL
                      WHERE l.jurisdiction_id = rc.jurisdiction_id
                        AND l.deleted_at IS NULL
                  )
                GROUP BY rc.jurisdiction_id
                ORDER BY rc.jurisdiction_id
                LIMIT ?
            ', [$afterId, self::CHUNK]);

            if ($candidates === []) {
                return;
            }

            foreach ($candidates as $candidate) {
                $afterId = $candidate->jurisdiction_id;

                $threshold = $activation->thresholdFor(
                    $candidate->jurisdiction_id,
                    $candidate->population !== null ? (int) $candidate->population : null,
                    $settings
                );

                if ((int) $candidate->verified_residents >= $threshold) {
                    $activation->onCriticalPopulation(
                        $candidate->jurisdiction_id,
                        (int) $candidate->verified_residents,
                        $threshold
                    );
                }
            }

            if (count($candidates) < self::CHUNK) {
                return;
            }
        }
    }
}
