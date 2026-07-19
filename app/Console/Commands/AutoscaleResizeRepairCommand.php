<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use App\Services\ConstitutionalDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * autoscale:resize-repair — the cycle-2 SIZING repairs (operator rulings
 * 2026-07-19), applied set-based to EXISTING legislatures. Sizing lives
 * outside the revert on purpose: autoscale:revert's contract is "keeps
 * sizing", and these are one-time law corrections, idempotent and
 * re-runnable.
 *
 *  1. LEAF RESIZE — the ceiling clamp was unlawful: every childless
 *     legislature re-sizes to max(floor, round(pop^⅓)) (the same law as
 *     parents). Over-ceiling leaves get line-split districts in the next
 *     mapping pass.
 *  2. TYPE B LADDER — Type B (equal representation of direct constituents)
 *     is bound by Type A: each constituent contributes rep_floor seats
 *     (pop ≤ 5 contributes min(pop, rep_floor)), descending 5 → 2 until
 *     the bound holds; still over at 2 → floor-2 value + the
 *     type_b_needs_districting flag (the deferred Type B districting
 *     worklist).
 *
 * total_seats / quorum_required re-derive wherever seats change.
 */
class AutoscaleResizeRepairCommand extends Command
{
    protected $signature = 'autoscale:resize-repair {--dry-run : Report counts without writing}';

    protected $description = 'Cycle-2 sizing repairs: unclamp leaf legislatures + apply the Type B ladder (set-based, idempotent)';

    public function handle(): int
    {
        $floor = ConstitutionalDefaults::floor();

        // Planet-wide joins here would recruit parallel workers whose DSM
        // segments blow through Docker's default 64 MB /dev/shm. Serial is
        // fine for set-based UPDATEs — session-scoped, reset on exit.
        DB::statement('SET max_parallel_workers_per_gather = 0');

        if ($this->option('dry-run')) {
            return $this->dryRun($floor);
        }

        // ── 1. Leaf resize (unclamp) ─────────────────────────────────────
        $resized = DB::update("
            UPDATE legislatures l
               SET type_a_seats    = s.seats,
                   total_seats     = s.seats + l.type_b_seats,
                   quorum_required = GREATEST(3, CEIL((s.seats + l.type_b_seats) / 2.0))::int,
                   updated_at      = now()
              FROM jurisdictions j
             CROSS JOIN LATERAL (
                   SELECT GREATEST(?, ROUND(POWER(GREATEST(COALESCE(j.population, 0), 1)::numeric, 1.0/3.0)))::int AS seats
             ) s
             WHERE j.id = l.jurisdiction_id
               AND j.deleted_at IS NULL
               AND l.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
               AND l.type_a_seats IS DISTINCT FROM s.seats
        ", [$floor]);
        $this->info("Leaf resize: {$resized} legislatures re-sized to the unclamped law.");

        // ── 2. Type B ladder (parents only; leaves stay 0) ───────────────
        // SQL mirror of TypeBSeatLadder::apportion for starting reps ≤ 5
        // (this world's settings); the PHP service handles arbitrary starts
        // on the write paths going forward. Picks the LARGEST rep floor in
        // {start..2} whose sum honors the bound; still over at 2 → flag.
        $laddered = DB::update("
            WITH agg AS (
                SELECT l.id AS leg_id, l.type_a_seats AS a,
                       LEAST(GREATEST(COALESCE(cs.type_b_seats_per_child, 5), 2), 5)::int AS f0,
                       COUNT(c.id) FILTER (WHERE COALESCE(c.population, 0) > 5) AS big,
                       COALESCE(SUM(LEAST(GREATEST(c.population, 0), 5)) FILTER (WHERE COALESCE(c.population, 0) <= 5), 0) AS t5,
                       COALESCE(SUM(LEAST(GREATEST(c.population, 0), 4)) FILTER (WHERE COALESCE(c.population, 0) <= 5), 0) AS t4,
                       COALESCE(SUM(LEAST(GREATEST(c.population, 0), 3)) FILTER (WHERE COALESCE(c.population, 0) <= 5), 0) AS t3,
                       COALESCE(SUM(LEAST(GREATEST(c.population, 0), 2)) FILTER (WHERE COALESCE(c.population, 0) <= 5), 0) AS t2
                  FROM legislatures l
                  JOIN jurisdictions j ON j.id = l.jurisdiction_id AND j.deleted_at IS NULL
                  JOIN jurisdictions c ON c.parent_id = j.id AND c.deleted_at IS NULL
                  LEFT JOIN constitutional_settings cs ON cs.jurisdiction_id = j.id
                 WHERE l.deleted_at IS NULL
                 GROUP BY l.id, l.type_a_seats, cs.type_b_seats_per_child
            ), pick AS (
                SELECT leg_id,
                       CASE WHEN f0 >= 5 AND t5 + big * 5 <= a THEN 5
                            WHEN f0 >= 4 AND t4 + big * 4 <= a THEN 4
                            WHEN f0 >= 3 AND t3 + big * 3 <= a THEN 3
                            ELSE 2 END AS f,
                       CASE WHEN f0 >= 5 AND t5 + big * 5 <= a THEN t5 + big * 5
                            WHEN f0 >= 4 AND t4 + big * 4 <= a THEN t4 + big * 4
                            WHEN f0 >= 3 AND t3 + big * 3 <= a THEN t3 + big * 3
                            ELSE t2 + big * 2 END AS b,
                       (t2 + big * 2 > a) AS needs
                  FROM agg
            )
            UPDATE legislatures l
               SET type_b_seats             = p.b,
                   type_b_rep_floor         = p.f,
                   type_b_needs_districting = p.needs,
                   total_seats              = l.type_a_seats + p.b,
                   quorum_required          = GREATEST(3, CEIL((l.type_a_seats + p.b) / 2.0))::int,
                   updated_at               = now()
              FROM pick p
             WHERE l.id = p.leg_id
               AND (l.type_b_seats IS DISTINCT FROM p.b
                    OR l.type_b_rep_floor IS DISTINCT FROM p.f
                    OR l.type_b_needs_districting IS DISTINCT FROM p.needs)
        ");
        $flagged = (int) DB::table('legislatures')
            ->whereNull('deleted_at')->where('type_b_needs_districting', true)->count();
        $this->info("Type B ladder: {$laddered} chambers re-derived; {$flagged} flagged for the deferred Type B districting.");

        // Hygiene: the two mass UPDATEs above leave a dead row version per
        // touched legislature — without a vacuum, every later FK probe into
        // this table (the revert's founding-map mint, ~500k rows) pays ~1 ms
        // walking the chains (measured live, 2026-07-19).
        if (DB::transactionLevel() === 0) { // VACUUM can't run inside a tx (test harness)
            DB::statement('VACUUM ANALYZE legislatures');
        }

        app(AuditService::class)->append(
            module: 'elections',
            event: 'autoscale.cycle2_sizing_repair',
            payload: [
                'leaves_resized'              => $resized,
                'type_b_chambers_rederived'   => $laddered,
                'type_b_needs_districting'    => $flagged,
                'law'                         => 'leaf unclamp (same law as parents) + Type B ladder (bound by Type A)',
                'generator'                   => 'AutoscaleResizeRepairCommand (cycle 2, 2026-07-19)',
            ],
            ref: 'WF-ELE-02',
        );

        return self::SUCCESS;
    }

    private function dryRun(int $floor): int
    {
        $wouldResize = (int) DB::scalar("
            SELECT COUNT(*) FROM legislatures l
              JOIN jurisdictions j ON j.id = l.jurisdiction_id AND j.deleted_at IS NULL
             CROSS JOIN LATERAL (
                   SELECT GREATEST({$floor}, ROUND(POWER(GREATEST(COALESCE(j.population, 0), 1)::numeric, 1.0/3.0)))::int AS seats
             ) s
             WHERE l.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
               AND l.type_a_seats IS DISTINCT FROM s.seats
        ");
        $this->info("Dry run: {$wouldResize} leaf legislatures would resize; Type B ladder recompute covers every parent.");

        return self::SUCCESS;
    }
}
