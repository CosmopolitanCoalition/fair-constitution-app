<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\AuditService;
use App\Support\HostCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Separate run and manifest: never rewind or overwrite the source simulation. */
class SimRepairControl
{
    public function start(string $sourceId, array $scopes = [], int $version = 1): SimRun
    {
        $this->guard();
        if ($version < 1 || count($scopes) > 100) { throw new \RuntimeException('Use a positive repair version and at most 100 exact pilot scopes.'); }
        foreach ($scopes as $id) { if (! Str::isUuid($id)) { throw new \RuntimeException('Pilot scopes must be jurisdiction UUIDs.'); } }
        return DB::transaction(function () use ($sourceId, $scopes, $version): SimRun {
            if (DB::getDriverName() === 'pgsql') { DB::statement('SELECT pg_advisory_xact_lock(194720126)'); }
            $source = SimRun::findOrFail($sourceId);
            if ($source->status !== 'done' || ($source->options['repair_source_run'] ?? null)) {
                throw new \RuntimeException('Select the completed original Step 5 run.');
            }
            if (app(SimRunControl::class)->activeRun()) { throw new \RuntimeException('An unfinished run already holds the engine; resume it instead.'); }
            $sourceItems = DB::table('sim_items')->where('run_id', $sourceId)->where('kind', 'verify_scope');
            $expected = (clone $sourceItems)->count();
            if ($expected === 0) { throw new \RuntimeException('The source has no verification worklist.'); }
            if ($scopes && (clone $sourceItems)->whereIn('jurisdiction_id', array_unique($scopes))->count() !== count(array_unique($scopes))) {
                throw new \RuntimeException('Every pilot scope must belong to the original verification worklist.');
            }
            $run = SimRun::create(['status' => 'queued', 'phase' => 'repair_planning', 'started_at' => now(),
                'options' => array_replace($source->options ?? [], ['repair_source_run' => $sourceId,
                    'repair_version' => $version, 'repair_scope_ids' => array_values(array_unique($scopes)),
                    'repair_source_verify_total' => $expected,
                    'repair_inspector_revision' => SimRepairInspector::REVISION,
                    'repair_plan_complete' => false, 'repair_apply_authorized' => false]), 'phase_timings' => []]);
            app(AuditService::class)->append('simworld', 'sim.repair_planned', ['run_id' => $run->id, 'source' => $sourceId, 'version' => $version, 'scopes' => $scopes], 'WF-SYS-04');
            return $run;
        });
    }

    public function apply(SimRun $run): void
    {
        $this->guard();
        DB::transaction(function () use ($run): void {
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->phase !== 'repair_planning' || ! ($run->options['repair_plan_complete'] ?? false)) {
                throw new \RuntimeException('Wait for the repair inventory to finish before applying it.');
            }
            if (($run->options['repair_inspector_revision'] ?? 1) < SimRepairInspector::REVISION) {
                throw new \RuntimeException('Refresh this older inventory with sim:repair --refresh-plan='.$run->id.' before applying it.');
            }
            if ($run->options['repair_apply_authorized'] ?? false) { return; }
            $options = $run->options; $options['repair_apply_authorized'] = true;
            $run->forceFill(['options' => $options, 'status' => 'running', 'halt_requested_at' => null])->save();
            app(AuditService::class)->append('simworld', 'sim.repair_apply_authorized', ['run_id' => $run->id], 'WF-SYS-04');
        });
    }

    /** Record the operator's recovery choice without starting or replaying work. */
    public function enableElectionRecovery(SimRun $run, array $scopes = []): array
    {
        $this->guard();
        DB::transaction(function () use ($run): void {
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! ($run->options['repair_source_run'] ?? null) || $run->status !== 'halted' || ! $run->haltRequested()
                || ! in_array($run->phase, ['repair_planning', 'repairing'], true)
                || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                || DB::table('sim_items')->where('run_id', $run->id)->where('status', 'running')->exists()) {
                throw new \RuntimeException('Enable election recovery on the drained, halted repair run.');
            }
            if ($run->options['repair_election_recovery'] ?? false) { return; }
            $options = $run->options; $options['repair_election_recovery'] = true;
            $timings = $run->phase_timings;
            if ($run->phase === 'repair_planning') {
                $options['repair_inspector_revision'] = 0; $options['repair_plan_complete'] = false;
                unset($timings['repair_planning']['reclassification'], $timings['repair_planning']['summary']);
            }
            $run->forceFill(['options' => $options, 'phase_timings' => $timings])->save();
            app(AuditService::class)->append('simworld', 'sim.election_recovery_authorized', [
                'run_id' => $run->id, 'source_run_id' => $options['repair_source_run'],
                'policy' => 'Supplement never-filled certified seats; supersede deficient unfinished synthetic counts; preserve existing terms and history',
            ], 'WF-SYS-04');
        });
        $run->refresh(); $cursor = null; $retried = 0;
        if ($run->phase === 'repairing') {
            // D015 already applied the full run. Revisit only election-blocked
            // reviews, retaining their prior outcome and every successful item.
            do {
                $rows = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_scope')->where('status', 'review')
                    ->when($scopes !== [], fn ($q) => $q->whereIn('jurisdiction_id', $scopes))
                    ->when($cursor, fn ($q) => $q->where('id', '>', $cursor))->orderBy('id')->limit(HostCapacity::sweepChunk())->get();
                foreach ($rows as $row) {
                    $retried += DB::transaction(function () use ($run, $row): int {
                        $fresh = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                        if ($fresh->status !== 'halted' || ! $fresh->haltRequested()) { throw new \RuntimeException('Keep the repair halted during review recovery.'); }
                        $plan = app(SimRepairInspector::class)->inspect($fresh, $row->jurisdiction_id);
                        if ($plan['blockers'] !== [] || ! collect($plan['actions'])->contains('kind', 'election_recovery')) { return 0; }
                        $old = json_decode($row->metrics ?? '{}', true);
                        app(AuditService::class)->append('simworld', 'sim.election_review_requeued', ['run_id' => $run->id, 'item_id' => $row->id,
                            'previous_reason' => $row->reason], 'WF-SYS-04', jurisdictionId: $row->jurisdiction_id);
                        return DB::table('sim_items')->where('id', $row->id)->where('status', 'review')->update([
                            'status' => 'pending', 'claim_token' => null, 'reason' => null, 'finished_at' => null, 'updated_at' => now(),
                            'metrics' => json_encode(['_previous_election_review' => $old, 'recovery_enabled' => true]),
                        ]);
                    });
                }
                $cursor = $rows->last()?->id;
            } while ($rows->count() === HostCapacity::sweepChunk());
        }
        return ['election_recovery' => true, 'reviews_requeued' => $retried, 'phase' => $run->phase,
            'next' => $run->phase === 'repairing' ? 'Resume this same halted run.' : 'Refresh the inventory, then Apply this same run.'];
    }

    public function enumerate(SimRun $run): int
    {
        $this->guard();
        $phase = $run->phase;
        if (! ($run->options['repair_source_run'] ?? null) || ! in_array($phase, ['repair_planning', 'repairing'], true)) {
            throw new \RuntimeException('Not a repair enumeration.');
        }
        $source = $phase === 'repair_planning' ? $run->options['repair_source_run'] : $run->id;
        $sourceKind = $phase === 'repair_planning' ? 'verify_scope' : 'repair_plan_scope';
        $targetKind = $phase === 'repair_planning' ? 'repair_plan_scope' : 'repair_scope';
        $size = HostCapacity::sweepChunk(); $inserted = 0;
        while (true) {
            $complete = DB::transaction(function () use ($run, $source, $sourceKind, $targetKind, $phase, $size, &$inserted): bool {
                // Serialize each bounded cursor advance, including duplicate enumerators.
                $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($run->phase !== $phase || $run->haltRequested() || ! in_array($run->status, ['queued', 'running'], true)) { return true; }
                $state = $run->phase_timings[$phase]['worklist'] ?? [];
                if ($state['complete'] ?? false) {
                    if ($run->status === 'queued') { $run->forceFill(['status' => 'running'])->save(); }
                    return true;
                }
                $rows = DB::table('sim_items')->where('run_id', $source)->where('kind', $sourceKind)
                ->when(isset($state['cursor']), fn ($q) => $q->where('unit_key', '>', $state['cursor']))
                ->when($run->options['repair_scope_ids'] ?? [], fn ($q, $ids) => $q->whereIn('jurisdiction_id', $ids))
                ->orderBy('unit_key')->limit($size)->get(['id','unit_key','jurisdiction_id','adm_level','status']);
                $now = now(); $items = [];
                foreach ($rows as $row) {
                    $items[] = ['id' => (string) Str::uuid(), 'run_id' => $run->id, 'kind' => $targetKind,
                        'status' => 'pending', 'jurisdiction_id' => $row->jurisdiction_id, 'adm_level' => $row->adm_level,
                        'unit_key' => $row->unit_key, 'position' => 99 - (int) ($row->adm_level ?? 0), 'est_cost' => 0,
                        'metrics' => json_encode(['source_item_id' => $row->id, 'source_status' => $row->status]),
                        'created_at' => $now, 'updated_at' => $now];
                }
                if ($items !== []) { $inserted += DB::table('sim_items')->insertOrIgnore($items); }
                $state['cursor'] = $rows->last()?->unit_key ?? ($state['cursor'] ?? null);
                $state['scanned'] = ($state['scanned'] ?? 0) + count($rows);
                $state['complete'] = $rows->isEmpty();
                $timings = $run->phase_timings ?? []; $timings[$phase]['worklist'] = $state;
                $run->forceFill(['phase_timings' => $timings, 'status' => $rows->isEmpty() ? 'running' : $run->status])->save();
                return $rows->isEmpty();
            });
            if ($complete) { return $inserted; }
        }
    }

    private function guard(): void
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
    }

    public function report(SimRun $run, ?string $after = null): array
    {
        $kind = $run->phase === 'repair_planning' ? 'repair_plan_scope' : 'repair_scope';
        $rows = DB::table('sim_items')->where('run_id', $run->id)->where('kind', $kind)
            ->when($after, fn ($q) => $q->where('unit_key', '>', $after))->orderBy('unit_key')->limit(51)
            ->get(['unit_key','jurisdiction_id','status','reason','metrics']);
        $page = $rows->take(50)->map(function ($row) {
            $row->metrics = json_decode($row->metrics ?? '{}', true);
            return $row;
        });
        return ['run_id' => $run->id, 'status' => $run->status, 'phase' => $run->phase,
            'source_run_id' => $run->options['repair_source_run'] ?? null,
            'pilot' => ! empty($run->options['repair_scope_ids']),
            'plan_complete' => (bool) ($run->options['repair_plan_complete'] ?? false) && ($run->options['repair_inspector_revision'] ?? 1) >= SimRepairInspector::REVISION,
            'classification_stale' => ($run->options['repair_inspector_revision'] ?? 1) < SimRepairInspector::REVISION,
            'authorized' => (bool) ($run->options['repair_apply_authorized'] ?? false),
            'election_recovery' => (bool) ($run->options['repair_election_recovery'] ?? false),
            'summary' => ($run->options['repair_inspector_revision'] ?? 1) < SimRepairInspector::REVISION ? null : ($run->phase_timings['repair_planning']['summary'] ?? null),
            'items' => $page->values()->all(), 'next' => $rows->count() > 50 ? $page->last()->unit_key : null];
    }

    /** One resumable, chunked rollup after inspection, never a world aggregate on a poll. */
    public function summarize(SimRun $run, bool $allowHalted = false): bool
    {
        while (true) {
            $run->refresh();
            if ($run->haltRequested() && ! $allowHalted) { return false; }
            $state = $run->phase_timings['repair_planning']['summary'] ?? ['scopes' => 0, 'categories' => [], 'actions' => []];
            if ($state['complete'] ?? false) { return true; }
            $rows = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_plan_scope')
                ->when($state['cursor'] ?? null, fn ($q, $id) => $q->where('unit_key', '>', $id))
                ->orderBy('unit_key')->limit(HostCapacity::sweepChunk())->get(['unit_key','status','metrics']);
            foreach ($rows as $row) {
                $metrics = json_decode($row->metrics ?? '{}', true); $category = $metrics['category'] ?? 'inspection_error';
                $state['scopes']++; $state['categories'][$category] = ($state['categories'][$category] ?? 0) + 1;
                foreach ($metrics['plan']['actions'] ?? [] as $action) { $state['actions'][$action['kind']] = ($state['actions'][$action['kind']] ?? 0) + 1; }
            }
            $state['cursor'] = $rows->last()?->unit_key ?? ($state['cursor'] ?? null); $state['complete'] = $rows->isEmpty();
            $timings = $run->phase_timings; $timings['repair_planning']['summary'] = $state;
            $run->forceFill(['phase_timings' => $timings])->save();
            if ($state['complete']) { return true; }
        }
    }

    /** Refresh only affected election plans; retain work IDs, receipts and all source outcomes. */
    public function refreshInventory(SimRun $run): array
    {
        $this->guard();
        // Share the pump's ownership so enumeration/advancement cannot overlap.
        $pdo = DB::connection()->getPdo();
        $lock = $pdo->prepare('SELECT pg_try_advisory_lock(?)');
        $lock->execute([\App\Console\Commands\SimPumpCommand::ADVISORY_LOCK_KEY]);
        if (! $lock->fetchColumn()) { throw new \RuntimeException('Simulation pump or inventory refresh is busy; retry after it finishes.'); }
        try {
            DB::transaction(function () use ($run): void {
                $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if (! ($run->options['repair_source_run'] ?? null) || $run->phase !== 'repair_planning' || $run->status !== 'halted'
                    || ! $run->haltRequested() || ($run->options['repair_apply_authorized'] ?? false)
                    || ! ($run->phase_timings['repair_planning']['worklist']['complete'] ?? false)
                    || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                    || DB::table('sim_items')->where('run_id', $run->id)->whereIn('status', ['pending','running'])->exists()) {
                    throw new \RuntimeException('Wait for the completed, drained, halted inspection before refreshing its classification.');
                }
                if (($run->options['repair_inspector_revision'] ?? 1) >= SimRepairInspector::REVISION) { return; }
                $timings = $run->phase_timings; $options = $run->options;
                if (($timings['repair_planning']['reclassification']['revision'] ?? 0) !== SimRepairInspector::REVISION) {
                    $timings['repair_planning']['reclassification'] = ['revision' => SimRepairInspector::REVISION, 'cursor' => null, 'scanned' => 0, 'refreshed' => 0, 'complete' => false];
                    unset($timings['repair_planning']['summary']);
                }
                $options['repair_plan_complete'] = false;
                $run->forceFill(['options' => $options, 'phase_timings' => $timings])->save();
            });
            $run->refresh();
            if (($run->options['repair_inspector_revision'] ?? 1) >= SimRepairInspector::REVISION) { return ['already_current' => true]; }
            while (true) {
                $complete = DB::transaction(function () use ($run): bool {
                    $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                    $timings = $run->phase_timings; $state = $timings['repair_planning']['reclassification'];
                    if ($state['complete']) { return true; }
                    $rows = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_plan_scope')
                        ->when($state['cursor'], fn ($q, $cursor) => $q->where('unit_key', '>', $cursor))
                        ->orderBy('unit_key')->limit(HostCapacity::sweepChunk())->get(['id','unit_key','jurisdiction_id','status','metrics']);
                    foreach ($rows as $row) {
                        $metrics = json_decode($row->metrics ?? '{}', true);
                        if ($row->status === 'done' && (! empty($metrics['plan']['blockers'])
                            || collect($metrics['plan']['actions'] ?? [])->contains(fn ($a) => in_array($a['kind'], ['election', 'election_recovery'], true)))) {
                            $updated = app(SimRepairService::class)->run($run, $row, planning: true);
                            unset($updated['_verdict']);
                            $updated['_previous_inventory'] = $metrics;
                            DB::table('sim_items')->where('id', $row->id)->update(['metrics' => json_encode($updated), 'updated_at' => now()]);
                            $state['refreshed']++;
                        }
                        $state['scanned']++;
                    }
                    $state['cursor'] = $rows->last()?->unit_key ?? $state['cursor']; $state['complete'] = $rows->isEmpty();
                    $timings['repair_planning']['reclassification'] = $state;
                    $run->forceFill(['phase_timings' => $timings])->save();
                    return $state['complete'];
                });
                if ($complete) { break; }
            }
            $this->summarize($run, allowHalted: true);
            return DB::transaction(function () use ($run): array {
                $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                $options = $run->options; $options['repair_inspector_revision'] = SimRepairInspector::REVISION; $options['repair_plan_complete'] = true;
                $run->forceFill(['options' => $options])->save();
                $state = $run->phase_timings['repair_planning']['reclassification'];
                app(AuditService::class)->append('simworld', 'sim.repair_inventory_refreshed', ['run_id' => $run->id, 'revision' => SimRepairInspector::REVISION] + $state, 'WF-SYS-04');
                return $state;
            });
        } finally {
            $lock = $pdo->prepare('SELECT pg_advisory_unlock(?)');
            $lock->execute([\App\Console\Commands\SimPumpCommand::ADVISORY_LOCK_KEY]);
        }
    }
}
