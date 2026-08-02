<?php

namespace App\Support;

use App\Models\GeodataRun;
use Illuminate\Support\Facades\DB;

/**
 * The geodata pull engine's claim primitive (GEODATA_PULL_ENGINE_PLAN.md §3).
 *
 * A worker calls next() in a loop; each claim is ONE atomic
 * UPDATE … RETURNING with FOR UPDATE SKIP LOCKED, so any number of workers
 * partition the phase's work-list without an orchestrator dispatching
 * anything. Unlike autoscale there is exactly ONE kind claimable at a time
 * (the current phase's kind) — the phase enum on the run IS the barrier
 * between fan-out phases, so the ladder is a single rung.
 *
 * Ordering is LARGEST-FIRST (position ASC == est_cost DESC rank, assigned at
 * enumeration): the opposite of autoscale's simplest-first, because here
 * there is no triage benefit and the heaviest unit (IND L6, 649k polys) must
 * start first or it defines the tail alone.
 *
 * The identical SQL runs Python-side in claims.py — the ladder is plain SQL so
 * both sides share it. This PHP copy is what the pins exercise.
 */
final class GeodataClaims
{
    /**
     * Claim the next pending item of the run's current phase, or null.
     *
     * @return array{id:string, kind:string, iso_code:?string, adm_level:?int, dry_run:bool}|null
     */
    public static function next(GeodataRun $run, string $token): ?array
    {
        if ($run->status !== 'running' || $run->haltRequested() || $run->isPaused()) {
            return null;
        }

        $kind = $run->currentKind();
        if ($kind === null) {
            return null; // terminal phase — nothing to claim
        }

        $row = DB::selectOne('
            UPDATE geodata_items
               SET status = ?, claim_token = ?,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE id = (
                   SELECT id FROM geodata_items
                    WHERE run_id = ? AND status = ? AND kind = ?
                    ORDER BY position
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING id, kind, iso_code, adm_level, dry_run
        ', ['running', $token, $run->id, 'pending', $kind]);

        if ($row === null) {
            return null;
        }

        return [
            'id'        => (string) $row->id,
            'kind'      => (string) $row->kind,
            'iso_code'  => $row->iso_code !== null ? (string) $row->iso_code : null,
            'adm_level' => $row->adm_level !== null ? (int) $row->adm_level : null,
            'dry_run'   => (bool) $row->dry_run,
        ];
    }

    /**
     * Is the given phase's pool fully settled (zero pending + running)? The
     * pump advances the phase pointer only when this is true — done, review,
     * and failed all count as settled (a refused ISO's absence is honest; the
     * acceptance scan flags it).
     */
    public static function phaseDrained(GeodataRun $run, string $phase): bool
    {
        $kind = GeodataRun::PHASE_KIND[$phase] ?? null;
        if ($kind === null) {
            return true;
        }

        // Intra-country parallelism (operator ruling 2026-08-02): a giant
        // country's monster level splits into boundary_range items claimed
        // by free lanes — the boundaries phase is drained only when BOTH the
        // country items and every enumerated range have settled. The raster
        // phase pre-splits monster tifs into raster_range row bands the same
        // way (pre-split ruling), so it drains on both kinds too.
        $kinds = match ($phase) {
            'boundaries'  => [$kind, 'boundary_range'],
            'rasters'     => [$kind, 'raster_range'],
            'attribution' => [$kind, 'attribution_range'],
            default       => [$kind],
        };

        return ! DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->whereIn('kind', $kinds)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /** A human-readable label for the per-worker claim strip. */
    public static function label(array $claim): string
    {
        return match ($claim['kind']) {
            'manifest'         => 'enumerating the archive',
            'boundary_iso'     => 'boundaries · '.($claim['iso_code'] ?? '?'),
            'resolve_global'   => 'resolving global (Earth + orphans + cross-ISO)',
            'raster_iso'       => 'rasters · '.($claim['iso_code'] ?? '?'),
            'attribution_pair' => 'attribution · '.($claim['iso_code'] ?? '?')
                .($claim['adm_level'] !== null ? ' L'.$claim['adm_level'] : ''),
            'finalize_global'  => 'finalizing (planet rollup + validation)',
            'acceptance_scan'  => 'acceptance scan',
            default            => $claim['kind'],
        };
    }
}
