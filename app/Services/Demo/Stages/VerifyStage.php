<?php

namespace App\Services\Demo\Stages;

use App\Models\SimRun;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;

/**
 * THE VERIFY stage (G1) — the acceptance scan for ONE jurisdiction's own world.
 *
 * PER-JURISDICTION IS THE CHUNK (THE ETL RULE). The old `verifying` phase ran
 * nothing (PHASE_KINDS['verifying'] was []), so a "done" run asserted a
 * readiness it never checked. This stage reads ONLY this jurisdiction's OWN
 * artifact rows — its legislatures, executives, judiciaries, organizations and
 * their boards — filtered by the jurisdiction's own foreign keys. It never
 * walks descendants, never rolls a subtree up, never runs a planet-wide
 * statement: a cross-level fact would be a subtree scan one item at a time,
 * re-creating the very defect the bounded engine exists to avoid.
 *
 * The checks are GATED BY THE RUN'S SELECTED ASPECTS (SimRun::activeAspects) so
 * the scan only asserts what the run actually built: an elections-only run is
 * not faulted for having no executive it never modelled.
 *
 * The stage RETURNS its verdict in the result array — `_verdict` = 'done' when
 * every required artifact for the run's aspects is present, or 'review' with a
 * `gaps` list and a `_reason` string when one is missing. It files no
 * constitutional act (the audit batch commits nothing), so it is a pure read
 * that settles the item and its metrics.
 */
final class VerifyStage
{
    private function __construct() {}

    /**
     * @return array<string,mixed>  metrics; `_verdict` (done|review) and, on
     *                              review, `_reason` steer the worker's settle.
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $mScan = hrtime(true);
        $beat && $beat();

        $aspects = self::activeAspects($runId);

        $gaps = [];
        $metrics = ['jurisdiction_id' => $jurisdictionId, 'aspects' => $aspects];

        // LAWFUL INACTIVE PLACES (operator ruling 2026-09-19,
        // sim-roster-vs-real-population = C). A place with zero population, or
        // with fewer real residents than its election needs, has no government
        // to verify: the run closed it DONE with no election, by law. It settles
        // done here with the reason recorded. Without this, every such place
        // files "legislature has zero seats" or "seated 0/5" and the step cannot
        // close without documented exclusions for places that are not defects.
        $inactive = self::lawfulInactive($jurisdictionId, $runId, $version);
        if ($inactive !== null) {
            SimTimer::record('verify.scan', (int) ((hrtime(true) - $mScan) / 1000));
            $metrics['inactive'] = $inactive;
            $metrics['_verdict'] = 'done';

            return $metrics;
        }

        // ── Representatives: every legislature of this jurisdiction seated ──
        // (elections aspect — seating is what the elections lane produces).
        if (in_array('elections', $aspects, true)) {
            $legislatures = DB::table('legislatures')
                ->where('jurisdiction_id', $jurisdictionId)
                ->whereNull('deleted_at')
                ->get(['id', 'total_seats', 'status']);

            $metrics['legislatures'] = $legislatures->count();
            $seatsTotal = 0;
            $seatedTotal = 0;

            if ($legislatures->isEmpty()) {
                $gaps[] = 'no legislature for a chamber-bearing scope';
            }

            foreach ($legislatures as $leg) {
                $seats = (int) $leg->total_seats;

                if ($seats <= 0) {
                    $gaps[] = 'legislature has zero seats (chamber not sized)';

                    continue;
                }

                $seated = (int) DB::table('legislature_members')
                    ->where('legislature_id', $leg->id)
                    ->whereIn('status', ['elected', 'seated'])
                    ->count();

                $seatsTotal += $seats;
                $seatedTotal += $seated;

                $majority = intdiv($seats, 2) + 1;
                if ($seated < $majority) {
                    $gaps[] = "legislature seated {$seated}/{$seats}, below the majority of {$majority}";
                }
            }

            $metrics['seats'] = $seatsTotal;
            $metrics['seated'] = $seatedTotal;
        }

        $beat && $beat();

        // ── Executive + judiciary present and operating (governance aspect) ──
        if (in_array('governance', $aspects, true)) {
            $executives = DB::table('executives')
                ->where('jurisdiction_id', $jurisdictionId)
                ->whereNull('deleted_at')
                ->get(['status']);

            $metrics['executives'] = $executives->count();
            foreach ($executives as $exec) {
                if (! in_array($exec->status, ['delegated', 'elected'], true)) {
                    $gaps[] = "executive status '{$exec->status}', not delegated or elected";
                }
            }

            $judiciaries = DB::table('judiciaries')
                ->where('jurisdiction_id', $jurisdictionId)
                ->whereNull('deleted_at')
                ->get(['status', 'judge_count', 'min_judges']);

            $metrics['judiciaries'] = $judiciaries->count();
            foreach ($judiciaries as $jud) {
                if (! in_array($jud->status, ['appointed', 'elected'], true)) {
                    $gaps[] = "judiciary status '{$jud->status}', not appointed or elected";

                    continue;
                }

                $judges = (int) $jud->judge_count;
                $min = (int) $jud->min_judges;
                if ($judges < $min) {
                    $gaps[] = "judiciary has {$judges} judges, below the minimum of {$min}";
                }
            }
        }

        $beat && $beat();

        // ── Ownership structures + board chairs (civic_life aspect) ──
        // Reads this jurisdiction's own organizations and, by the org's own
        // board_id FK, those boards. No cross-jurisdiction join.
        if (in_array('civic_life', $aspects, true)) {
            $orgs = DB::table('organizations')
                ->where('jurisdiction_id', $jurisdictionId)
                ->whereNull('deleted_at')
                ->get(['id', 'ownership_type', 'board_id']);

            $metrics['organizations'] = $orgs->count();

            $boardIds = $orgs->pluck('board_id')->filter()->values()->all();
            $boards = $boardIds !== []
                ? DB::table('boards')->whereIn('id', $boardIds)->get(['id', 'chair_seat_id', 'status', 'owner_seats'])->keyBy('id')
                : collect();

            $missingOwnership = 0;
            $missingChair = 0;

            foreach ($orgs as $org) {
                if ($org->ownership_type === null || $org->ownership_type === '') {
                    $missingOwnership++;
                }

                if ($org->board_id !== null) {
                    $board = $boards->get($org->board_id);
                    // A board that seats an owner side but has no chair is an
                    // unfilled chair; a dissolved board is not a live gap.
                    if ($board !== null
                        && $board->status !== 'dissolved'
                        && (int) $board->owner_seats > 0
                        && $board->chair_seat_id === null) {
                        $missingChair++;
                    }
                }
            }

            $metrics['orgs_missing_ownership'] = $missingOwnership;
            $metrics['boards_missing_chair'] = $missingChair;

            if ($missingOwnership > 0) {
                $gaps[] = "{$missingOwnership} organization(s) with no ownership structure";
            }
            if ($missingChair > 0) {
                $gaps[] = "{$missingChair} board(s) with an unfilled chair";
            }
        }

        SimTimer::record('verify.scan', (int) ((hrtime(true) - $mScan) / 1000));

        if ($gaps === []) {
            $metrics['_verdict'] = 'done';

            return $metrics;
        }

        $metrics['gaps'] = $gaps;
        $metrics['_verdict'] = 'review';
        $metrics['_reason'] = implode('; ', array_slice($gaps, 0, 6));

        return $metrics;
    }

    /**
     * The lawful-inactive reason for a place, or null when the place must be
     * verified. Two bounded reads, both on a unique key: the place's own cohort
     * row, and this run's own election item for it.
     */
    private static function lawfulInactive(string $jurisdictionId, ?string $runId, int $version): ?string
    {
        if (IdentityStage::populationOf($jurisdictionId, $version) === 0) {
            // Zero is zero only where there is no sized chamber to verify. A
            // zero-row place that still carries seats is an anomaly and takes
            // the full scan below.
            $sized = DB::table('legislatures')
                ->where('jurisdiction_id', $jurisdictionId)
                ->whereNull('deleted_at')
                ->where('total_seats', '>', 0)
                ->exists();

            if (! $sized) {
                return IdentityStage::INACTIVE_ZERO_POPULATION;
            }
        }

        if ($runId === null) {
            return null;
        }

        $metrics = DB::table('sim_items')
            ->where('run_id', $runId)
            ->where('kind', 'election_scope')
            ->where('unit_key', $jurisdictionId)
            ->where('status', 'done')
            ->value('metrics');

        $decoded = is_string($metrics) ? json_decode($metrics, true) : null;

        return is_array($decoded) && ($decoded['inactive'] ?? null) === IdentityStage::INACTIVE_TOO_FEW_RESIDENTS
            ? IdentityStage::INACTIVE_TOO_FEW_RESIDENTS
            : null;
    }

    /**
     * The run's active aspects (closed over prerequisites), or every aspect
     * when the run or its scope is unknown — a missing run must not silently
     * narrow the scan.
     *
     * @return list<string>
     */
    private static function activeAspects(?string $runId): array
    {
        if ($runId === null) {
            return SimRun::ALL_ASPECTS;
        }

        $run = SimRun::find($runId);

        return $run !== null ? $run->activeAspects() : SimRun::ALL_ASPECTS;
    }
}
