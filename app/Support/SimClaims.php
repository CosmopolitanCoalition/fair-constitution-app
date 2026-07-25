<?php

namespace App\Support;

use App\Models\SimItem;
use App\Models\SimRun;
use Illuminate\Support\Facades\DB;

/**
 * The simulated-world claim ladder — the ONLY place a worker takes work.
 *
 * Shape is `AutoscaleClaims`' verbatim, because that is the shape that survived
 * four failed planet-scale runs:
 *
 *   UPDATE … SET status='running', claim_token=?
 *    WHERE id IN (SELECT id … WHERE status='pending' ORDER BY position
 *                 LIMIT 1 FOR UPDATE SKIP LOCKED)
 *      AND status='pending' RETURNING …
 *
 * SKIP LOCKED is what makes N workers contention-free without a coordinator,
 * and the redundant `AND status = 'pending'` outside the subquery is what makes
 * the claim safe if two transactions ever reach the same row.
 *
 * TWO DELIBERATE DIVERGENCES FROM AUTOSCALE, each with a named precedent:
 *
 *  1. ORDERING IS LARGEST-FIRST (`position` is filled from `est_cost DESC`) —
 *     the inversion `GEODATA_PULL_ENGINE_PLAN.md` already adopted. Autoscale
 *     goes simplest-first for triage value; here there is none, and the biggest
 *     populations must start first or they define the tail alone.
 *
 *  2. THE RESEARCH LANE IS SUB-CAPPED inside the one limiter, mirroring
 *     `AutoscaleClaims::heavyWorkerCap()`. `profile_research` items are
 *     network/LLM-bound and rate-limited upstream; without a cap they would
 *     occupy every worker slot while the CPU-bound stages starve.
 *
 * PHASE ADVANCE IS NOT HERE. It lives in the pump, exactly once per tick. A
 * worker that could advance a phase could advance it twice.
 */
final class SimClaims
{
    /** Kinds that are network-bound rather than CPU/PG-bound. */
    public const NETWORK_KINDS = ['profile_research'];

    private function __construct() {}

    /**
     * Share of the worker pool the network lane may occupy. Same 20% formula as
     * the autoscale heavy lane, floored at 1 so the lane can never deadlock.
     */
    public static function networkWorkerCap(): int
    {
        return max(1, (int) ceil(0.2 * HostCapacity::autoscaleWorkers()));
    }

    /**
     * Claim one unit for this run's CURRENT phase, or null if there is nothing
     * claimable. Honors halt and the breaker before touching a row.
     *
     * @return object|null {id, kind, jurisdiction_id, legislature_id, race_id, adm_level}
     */
    public static function next(SimRun $run, string $token): ?object
    {
        if (! $run->isClaimable()) {
            return null;
        }

        $kinds = $run->currentKinds();

        if ($kinds === []) {
            return null;
        }

        // Split the phase's kinds by lane so the network sub-cap only gates the
        // kinds it applies to. Most phases have exactly one kind and one lane.
        $network = array_values(array_intersect($kinds, self::NETWORK_KINDS));
        $ordinary = array_values(array_diff($kinds, self::NETWORK_KINDS));

        if ($ordinary !== []) {
            $row = self::claim($run, $token, $ordinary);

            if ($row !== null) {
                return $row;
            }
        }

        if ($network !== [] && self::networkLaneHasRoom($run)) {
            return self::claim($run, $token, $network);
        }

        return null;
    }

    /** Live claimants currently holding a network-lane item. */
    private static function networkLaneHasRoom(SimRun $run): bool
    {
        $running = (int) DB::table('sim_items')
            ->where('run_id', $run->id)
            ->where('status', SimItem::STATUS_RUNNING)
            ->whereIn('kind', self::NETWORK_KINDS)
            ->whereNotNull('claim_token')
            ->distinct()
            ->count('claim_token');

        return $running < self::networkWorkerCap();
    }

    /**
     * @param  list<string>  $kinds
     */
    private static function claim(SimRun $run, string $token, array $kinds): ?object
    {
        $placeholders = implode(',', array_fill(0, count($kinds), '?'));

        $bindings = array_merge(
            [SimItem::STATUS_RUNNING, $token, $run->id],
            $kinds,
            [SimItem::STATUS_PENDING, SimItem::STATUS_PENDING]
        );

        return DB::selectOne(
            "UPDATE sim_items s
                SET status = ?, claim_token = ?,
                    started_at = COALESCE(s.started_at, now()), updated_at = now()
              WHERE s.id = (
                    SELECT s2.id FROM sim_items s2
                     WHERE s2.run_id = ?
                       AND s2.kind IN ({$placeholders})
                       AND s2.status = ?
                     ORDER BY s2.position, s2.id
                     LIMIT 1
                     FOR UPDATE SKIP LOCKED
              )
                AND s.status = ?
          RETURNING s.id, s.kind, s.jurisdiction_id, s.legislature_id, s.race_id, s.adm_level",
            $bindings
        );
    }

    /** Is there any claimable work left anywhere in this run? */
    public static function workAvailable(SimRun $run): bool
    {
        return DB::table('sim_items')
            ->where('run_id', $run->id)
            ->where('status', SimItem::STATUS_PENDING)
            ->exists();
    }

    /**
     * Release a claim back to pending (worker died mid-item, or a graceful
     * stand-down). Scoped by claim_token so a worker can only release its own.
     */
    public static function release(string $itemId, string $token, ?string $reason = null): void
    {
        DB::table('sim_items')
            ->where('id', $itemId)
            ->where('claim_token', $token)
            ->where('status', SimItem::STATUS_RUNNING)
            ->update([
                'status' => SimItem::STATUS_PENDING,
                'claim_token' => null,
                'reason' => $reason,
                'updated_at' => now(),
            ]);
    }
}
