<?php

namespace App\Services\Demo;

use App\Console\Commands\SimPumpCommand;
use App\Models\{Legislature, SimRun};
use App\Services\AuditService;
use App\Services\Demo\Stages\GovernanceStage;
use App\Services\InstitutionScaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** D022: retry the exhausted committee vocabulary, preserving all other work. */
class SimCommitteeRecovery
{
    public function retry(SimRun $run, array $scopes): array
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        if ($scopes === [] || count($scopes) > 100 || collect($scopes)->contains(fn ($id) => ! Str::isUuid($id))) {
            throw new \RuntimeException('Name 1–100 exact committee retry scopes.');
        }
        return DB::transaction(function () use ($run, $scopes): array {
            // Coordinate with both repair creation and phase advancement. Never
            // wait behind a busy pump or reopen a run another run has replaced.
            if (DB::getDriverName() === 'pgsql') {
                foreach ([194720126, SimPumpCommand::ADVISORY_LOCK_KEY] as $key) {
                    if (! DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [$key])->acquired) {
                        throw new \RuntimeException('Repair control is busy; retry after it is idle.');
                    }
                }
            }
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $terminal = $run->status === 'done' && $run->phase === 'done';
            $halted = $run->status === 'halted' && $run->phase === 'repairing' && $run->haltRequested();
            if (! ($run->options['repair_source_run'] ?? null) || ! ($run->options['repair_apply_authorized'] ?? false)
                || ! ($run->phase_timings['repairing']['worklist']['complete'] ?? false) || (! $terminal && ! $halted)
                || SimRun::where('id', '!=', $run->id)->whereIn('status', ['queued','running','halted'])->exists()
                || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                || DB::table('sim_items')->where('run_id', $run->id)->where('status', 'running')->exists()
                || ($terminal && DB::table('sim_items')->where('run_id', $run->id)->where('status', 'pending')->exists())) {
                throw new \RuntimeException('Committee retry requires the drained, authorized, halted or completed repair run.');
            }
            $retried = []; $retained = [];
            foreach (array_unique($scopes) as $scope) {
                $item = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_scope')
                    ->where('unit_key', $scope)->where('status', 'review')->lockForUpdate()->first();
                $leg = Legislature::where('jurisdiction_id', $scope)->first();
                if (! $item || ! $leg || ! DB::table('sim_items')->where('run_id', $run->options['repair_source_run'])
                    ->where('kind', 'verify_scope')->where('unit_key', $scope)->exists()) { $retained[] = $scope; continue; }
                $key = ['source_run_id' => $run->options['repair_source_run'], 'repair_version' => (int) ($run->options['repair_version'] ?? 1),
                    'kind' => 'governance', 'target_id' => $leg->id, 'jurisdiction_id' => $scope];
                $receipt = DB::table('sim_repair_receipts')->where($key)->lockForUpdate()->first();
                $result = json_decode($receipt->result ?? '{}', true);
                $target = InstitutionScaleService::committeeTarget((int) $leg->total_seats);
                $names = DB::table('committees')->where('legislature_id', $leg->id)->whereNull('deleted_at')->pluck('name')->all();
                if (! $receipt || $receipt->status !== 'blocked' || ($result['committees']['created'] ?? null) !== 0
                    || ($result['committees']['existing'] ?? null) !== count($names)
                    || ($result['committees']['target'] ?? null) !== $target
                    || ! array_key_exists('skipped', $result['committees'] ?? []) || $result['committees']['skipped'] !== null
                    || $target <= count($names) || array_diff(GovernanceStage::COMMITTEE_NAMES, $names) !== []) {
                    $retained[] = $scope; continue;
                }
                app(AuditService::class)->append('simworld', 'sim.committee_vocabulary_retry', $key + [
                    'run_id' => $run->id, 'item_id' => $item->id, 'previous' => $result,
                ], 'WF-SYS-04', jurisdictionId: $scope);
                DB::table('sim_repair_receipts')->where($key)->update(['status' => 'deferred', 'updated_at' => now(),
                    'result' => json_encode(['reason' => 'D022: committee names now cover the existing size target.',
                        '_prior_receipts' => [['status' => $receipt->status, 'result' => $result, 'updated_at' => $receipt->updated_at]]])]);
                DB::table('sim_items')->where('id', $item->id)->update(['status' => 'pending', 'claim_token' => null,
                    'reason' => null, 'finished_at' => null, 'updated_at' => now(),
                    'metrics' => json_encode(['_previous_committee_review' => json_decode($item->metrics ?? '{}', true)])]);
                $retried[] = $scope;
            }
            if ($terminal && $retried !== []) {
                app(AuditService::class)->append('simworld', 'sim.repair_review_continued', [
                    'run_id' => $run->id, 'scopes' => $retried, 'previous_finished_at' => $run->finished_at,
                    'previous_phase_timings' => $run->phase_timings,
                ], 'WF-SYS-04');
                $timings = $run->phase_timings;
                unset($timings['repairing']['finished_at'], $timings['done']);
                $run->forceFill(['status' => 'halted', 'phase' => 'repairing', 'halt_requested_at' => now(),
                    'finished_at' => null, 'phase_timings' => $timings])->save();
            }
            if ($retried !== []) {
                $run->forceFill(['items_review' => max(0, $run->items_review - count($retried)),
                    'open_items' => $run->open_items + count($retried)])->save();
            }
            return ['retried' => $retried, 'retained' => $retained];
        });
    }
}
