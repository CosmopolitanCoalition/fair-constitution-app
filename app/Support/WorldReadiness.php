<?php

namespace App\Support;

use App\Models\SimRun;
use App\Services\Demo\SimSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE OWNER of the world-readiness report (G1).
 *
 * Both surfaces read readiness through here: the Step 5 page (so the operator
 * sees the verify rollup and the unresolved list) and the completion guard (so
 * the ladder cannot advance past a world that was never verified). Single-owner
 * rails — one reader, no drift between what the page shows and what the guard
 * enforces.
 *
 * BOUNDED BY CONSTRUCTION (THE ETL RULE). Readiness is answered by an
 * index-only aggregate over the run's own `verify_scope` items
 * (`sim_items_claim_idx`), never by `SimSnapshot::world()`'s planet scans. The
 * verify PHASE did the per-jurisdiction work in bounded, resumable chunks; this
 * only sums the outcomes it recorded, plus a capped list of the scopes that
 * filed review with their gaps. `sim_items.status` IS the progress store — no
 * new table, no new column.
 */
class WorldReadiness
{
    /** How many unresolved scopes the report lists (the rest are counted, not named). */
    public const UNRESOLVED_CAP = 50;

    /**
     * The readiness of a run (the active-or-latest by default).
     *
     * @return array{
     *   run_id: ?string, run_status: ?string, run_done: bool,
     *   verify_total: int, verify_done: int, verify_review: int,
     *   pending: bool, complete: bool, unresolved: list<array<string,mixed>>
     * }
     */
    public function report(?SimRun $run = null): array
    {
        $run ??= app(SimSnapshot::class)->activeOrLatestRun();

        if ($run === null) {
            return [
                'run_id' => null,
                'run_status' => null,
                'run_done' => false,
                'verify_total' => 0,
                'verify_done' => 0,
                'verify_review' => 0,
                'pending' => false,
                'complete' => false,
                'unresolved' => [],
            ];
        }

        // Portable aggregate (SUM(CASE …) works on both PostgreSQL and the
        // sqlite test fixture; FILTER does not). Index-only on the claim index.
        $row = DB::table('sim_items')
            ->where('run_id', $run->id)
            ->where('kind', ($run->options['repair_source_run'] ?? null) ? 'repair_scope' : 'verify_scope')
            ->selectRaw(
                'COUNT(*) AS total,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS done,
                 SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS review',
                ['done', 'review', 'failed']
            )
            ->first();

        $total = (int) ($row->total ?? 0);
        $done = (int) ($row->done ?? 0);
        $review = (int) ($row->review ?? 0);

        $runDone = $run->status === 'done';

        // A done run with no verify items has NOT been verified — the phase was
        // never run (a legacy run, or one closed before this phase existed).
        // That is "verification pending", never a clean pass.
        $expected = ($run->options['repair_source_run'] ?? null)
            ? (int) ($run->options['repair_source_verify_total'] ?? 0)
            : $total;
        $pilot = ! empty($run->options['repair_scope_ids']);
        $pending = $runDone && ($total === 0 || $total !== $expected || $pilot);
        $complete = $runDone && ! $pending && $total > 0 && $done === $total;

        return [
            'run_id' => (string) $run->id,
            'run_status' => $run->status,
            'run_done' => $runDone,
            'verify_total' => $total,
            'verify_done' => $done,
            'verify_review' => $review,
            'verify_expected' => $expected,
            'repair_source_run' => $run->options['repair_source_run'] ?? null,
            'repair_pilot' => $pilot,
            'pending' => $pending,
            'complete' => $complete,
            'unresolved' => $review > 0 ? $this->unresolved($run) : [],
        ];
    }

    /**
     * The bounded list of scopes that filed review, with their gaps — capped,
     * ordered by claim position so the biggest places lead. Reads only this
     * run's verify_scope review rows joined to their own jurisdiction row.
     *
     * @return list<array<string,mixed>>
     */
    private function unresolved(SimRun $run): array
    {
        return DB::table('sim_items as s')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 's.jurisdiction_id')
            ->where('s.run_id', $run->id)
            ->where('s.kind', ($run->options['repair_source_run'] ?? null) ? 'repair_scope' : 'verify_scope')
            ->whereIn('s.status', ['review', 'failed'])
            ->orderBy('s.position')
            ->limit(self::UNRESOLVED_CAP)
            ->get(['s.jurisdiction_id', 's.reason', 'j.name as name', 'j.slug as slug', 'j.adm_level as adm_level'])
            ->map(fn ($i) => [
                'jurisdiction_id' => $i->jurisdiction_id !== null ? (string) $i->jurisdiction_id : null,
                'name' => $i->name ?? '—',
                'slug' => $i->slug,
                'adm_level' => $i->adm_level !== null ? (int) $i->adm_level : null,
                'gaps' => $i->reason,
            ])
            ->all();
    }
}
