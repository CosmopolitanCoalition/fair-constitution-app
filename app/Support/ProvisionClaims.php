<?php

namespace App\Support;

use App\Models\ProvisionRun;
use Illuminate\Support\Facades\DB;

/**
 * The Step 4 engine's claim ladder over provision_ledger (Wave 6). Workers
 * call next() in a loop; each rung is ONE atomic UPDATE … RETURNING with
 * FOR UPDATE SKIP LOCKED, so any number of lanes partition the work-list
 * without an orchestrator dispatching anything.
 *
 *   1. shell batches  — SHELL_BATCH stage-0 rows per claim; the six set-based
 *                       shell statements run over the claim (minutes for the
 *                       planet, every lane eats them first);
 *   2. units          — one stage-1 row per claim: the election and its races,
 *                       the committees and the departments as system acts.
 *
 * THE TWO-ENDED DRAIN (the lane law): the pile is ordered by est_cost. A
 * 'topdown' lane claims largest first (monsters start immediately, their
 * failures surface early); a 'bottomup' lane claims smallest first (the
 * light mass churns, small-class bugs surface on their own). Debug to the
 * middle. A lane never idles while either rung holds work.
 */
final class ProvisionClaims
{
    // 500, un-bulked (operator order 2026-09-06: avoid bulking single
    // transactions so all lanes can be leveraged). A smaller set-based insert
    // holds index and buffer locks for a shorter time, so all lanes run the
    // shell phase concurrently without the buffer-cache contention a 2,000-row
    // batch caused. The lane/part timings surface whether the trade of more
    // claims for lighter transactions pays; tune from there.
    public const SHELL_BATCH = 500;

    public const LANE_TOPDOWN  = 'topdown';
    public const LANE_BOTTOMUP = 'bottomup';

    /**
     * @return array{type:'shell_batch',count:int}|array{type:'unit',legislature_id:string,jurisdiction_id:string,est_cost:int}|null
     */
    public static function next(ProvisionRun $run, string $token, string $lane): ?array
    {
        if ($claim = self::claimShellBatch($token, $lane)) {
            return $claim;
        }

        return self::claimUnit($token, $lane);
    }

    /** Anything claimable right now? (The pump's lane-seeding gate.) */
    public static function claimableWork(): bool
    {
        return DB::table('provision_ledger')
            ->where('status', 'pending')
            ->whereIn('stage', [0, 1])
            ->exists();
    }

    /** Any row still open (pending or running)? */
    public static function openWork(): bool
    {
        return DB::table('provision_ledger')
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    private static function order(string $lane): string
    {
        // THE LAYER BLOCK (fix 3, 2026-09-06): adm_level is the block, top-down
        // like the districting block order (planet, then countries, down to
        // neighborhoods). Within a layer the pile drains TWO-ENDED by est_cost:
        // a topdown lane takes the biggest chamber of the highest layer, a
        // bottomup lane the smallest chamber of the lowest layer, meeting in the
        // middle. Rows seeded before adm_level existed sort at the near end and
        // still drain by cost.
        // THE STAMPED WALK (operator insight 2026-09-06): the whole order is
        // folded into ONE integer (walk_position) stamped once at seed
        // completion, so the claim is an INDEX POP of one column — never a
        // re-sort of the pending pile. Topdown pops the low end (layer
        // top-down, biggest chamber first); bottomup pops the high end, the
        // same pl_walk_idx scanned backward. This is the Step 3 model.
        return $lane === self::LANE_BOTTOMUP ? 'walk_position DESC' : 'walk_position ASC';
    }

    private static function claimShellBatch(string $token, string $lane): ?array
    {
        $order = self::order($lane);
        $rows = DB::select("
            UPDATE provision_ledger
               SET status = 'running', claim_token = ?::uuid,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE legislature_id IN (
                   SELECT legislature_id FROM provision_ledger
                    WHERE status = 'pending' AND stage = 0
                    ORDER BY {$order}
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING legislature_id
        ", [$token, self::SHELL_BATCH]);

        if ($rows === []) {
            return null;
        }

        return ['type' => 'shell_batch', 'count' => count($rows)];
    }

    private static function claimUnit(string $token, string $lane): ?array
    {
        $order = self::order($lane);
        $row = DB::selectOne("
            UPDATE provision_ledger
               SET status = 'running', claim_token = ?::uuid,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE legislature_id = (
                   SELECT legislature_id FROM provision_ledger
                    WHERE status = 'pending' AND stage = 1
                    ORDER BY {$order}
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING legislature_id, jurisdiction_id, est_cost
        ", [$token]);

        if ($row === null) {
            return null;
        }

        return [
            'type'            => 'unit',
            'legislature_id'  => (string) $row->legislature_id,
            'jurisdiction_id' => (string) $row->jurisdiction_id,
            'est_cost'        => (int) $row->est_cost,
        ];
    }
}
