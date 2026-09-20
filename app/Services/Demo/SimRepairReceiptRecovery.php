<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/** Explicit, scope-targeted correction of D014's demonstrably effect-free receipts. */
class SimRepairReceiptRecovery
{
    /** Retry only rolled-back election failures with a demonstrated panel cap defect. */
    public function retryPopulationCeiling(SimRun $run, array $scopes, bool $tinyElectorates = false): array
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        if ($scopes === [] || count($scopes) > 100) { throw new \RuntimeException('Name 1–100 exact population-ceiling retry scopes.'); }
        return DB::transaction(function () use ($run, $scopes, $tinyElectorates): array {
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! ($run->options['repair_source_run'] ?? null) || $run->status !== 'halted' || ! $run->haltRequested()
                || $run->phase !== 'repairing' || ! ($run->options['repair_apply_authorized'] ?? false)
                || ! ($run->options['repair_election_recovery'] ?? false)
                || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                || DB::table('sim_items')->where('run_id', $run->id)->where('status', 'running')->exists()) {
                throw new \RuntimeException('Population-ceiling retry requires the drained, halted, authorized repair run.');
            }
            $retried = []; $retained = [];
            foreach (array_unique($scopes) as $scope) {
                $item = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_scope')
                    ->where('unit_key', $scope)->where('status', 'review')->lockForUpdate()->first();
                if (! $item) { $retained[] = $scope; continue; }
                $source = DB::table('sim_items')->where('run_id', $run->options['repair_source_run'])
                    ->where('kind', 'election_scope')->where('unit_key', $scope)->value('metrics');
                $electionId = (json_decode($source ?? '{}', true)['election_id'] ?? null);
                $election = $electionId ? \App\Models\Election::find($electionId) : null;
                if (! $election || $election->jurisdiction_id !== $scope || ! ($tinyElectorates
                    ? SimElectorate::hasTinyPanel($election)
                    : app(SimPopulationCeiling::class)->adjustments($election) !== [])) {
                    $retained[] = $scope; continue;
                }
                $key = ['source_run_id' => $run->options['repair_source_run'], 'repair_version' => (int) ($run->options['repair_version'] ?? 1),
                    'kind' => 'election_recovery', 'target_id' => $electionId, 'jurisdiction_id' => $scope];
                $receipt = DB::table('sim_repair_receipts')->where($key)->lockForUpdate()->first();
                $result = json_decode($receipt->result ?? '{}', true);
                $reason = $result['reason'] ?? '';
                if (! $receipt || $receipt->status !== 'blocked' || ($result['exception'] ?? null) !== 'RuntimeException'
                    || ! ($reason === 'Recovery count did not elect the full advertised field; no partial certification applied.'
                        || str_starts_with($reason, 'Distinct eligible candidate pool exhausted in '))) {
                    $retained[] = $scope; continue;
                }
                $replacement = ['reason' => $tinyElectorates ? 'D020: retry integer turnout and optional challenger correction.' : 'D018: retry after correcting the existing real-population ceiling.',
                    '_prior_receipts' => [['status' => $receipt->status, 'result' => $result, 'updated_at' => $receipt->updated_at]]];
                app(AuditService::class)->append('simworld', $tinyElectorates ? 'sim.small_electorate_retry' : 'sim.population_ceiling_retry', $key + ['run_id' => $run->id,
                    'item_id' => $item->id, 'previous' => $result], 'WF-SYS-04', jurisdictionId: $scope);
                DB::table('sim_repair_receipts')->where($key)->update(['status' => 'deferred', 'result' => json_encode($replacement), 'updated_at' => now()]);
                DB::table('sim_items')->where('id', $item->id)->update(['status' => 'pending', 'claim_token' => null,
                    'reason' => null, 'finished_at' => null, 'updated_at' => now(),
                    'metrics' => json_encode(['_previous_population_review' => json_decode($item->metrics ?? '{}', true)])]);
                $retried[] = $scope;
            }
            return ['retried' => $retried, 'retained' => $retained];
        });
    }

    public static function isPrerequisiteNoop(string $kind, array $result): bool
    {
        if (! empty($result['resumed_votes'])) { return false; }
        unset($result['resumed_votes']);
        $zero = ['true' => 0, 'minted' => 0];
        if ($kind === 'training') {
            return $result == ['holders' => 0, 'trained' => 0, 'already' => 0, 'unarmed' => 0, 'failed' => 0];
        }
        if ($kind === 'civics') {
            return $result == ['parties' => $zero, 'nonprofits' => $zero, 'businesses' => $zero, 'bills' => $zero,
                'endorsements' => 0, 'cgcs' => 0, 'cgc_governors' => 0,
                'org_boards' => ['boards' => 0, 'workers' => 0, 'owner_seats' => 0, 'worker_seats' => 0], 'skipped' => null];
        }
        $why = $result['skipped'] ?? null;
        if (! in_array($why, ['no legislature','chamber not seated','no seated member with a user to hold the pen'], true)) { return false; }
        return match ($kind) {
            'governance' => $result == [
                'committees' => ['created' => 0, 'target' => null, 'existing' => null, 'skipped' => $why],
                'departments' => ['created' => 0, 'target' => null, 'existing' => null, 'delegated' => false, 'skipped' => $why],
                'governors' => ['departments' => 0, 'nominated' => 0, 'seated' => 0, 'skipped' => $why], 'skipped' => $why],
            'judiciary' => $result == ['filed' => false, 'seats_total' => null, 'seats_seated' => 0, 'status' => null, 'skipped' => $why],
            default => false,
        };
    }

    public function recover(SimRun $run, array $scopes): array
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        if ($scopes === [] || count($scopes) > 100) { throw new \RuntimeException('Name 1–100 exact receipt-recovery scopes.'); }
        return DB::transaction(function () use ($run, $scopes): array {
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! ($run->options['repair_source_run'] ?? null) || $run->status !== 'halted' || ! $run->haltRequested()
                || ($run->options['repair_apply_authorized'] ?? false)
                || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                || DB::table('sim_items')->where('run_id', $run->id)->where('status', 'running')->exists()) {
                throw new \RuntimeException('Receipt correction requires the drained, halted, unapplied inventory.');
            }
            $corrected = []; $retained = [];
            foreach (array_unique($scopes) as $scope) {
                if (! DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_plan_scope')->where('jurisdiction_id', $scope)->exists()) {
                    throw new \RuntimeException('Receipt scope is not in this inventory: '.$scope);
                }
                $key = ['source_run_id' => $run->options['repair_source_run'], 'repair_version' => (int) ($run->options['repair_version'] ?? 1), 'jurisdiction_id' => $scope];
                $rows = DB::table('sim_repair_receipts')->where($key)->whereIn('kind', ['training','governance','judiciary','civics'])
                    ->where('status', 'applied')->orderBy('kind')->orderBy('target_id')->limit(101)->lockForUpdate()->get();
                if ($rows->count() > 100) { throw new \RuntimeException('Unexpected receipt multiplicity; inspect this scope first.'); }
                foreach ($rows as $row) {
                    $result = json_decode($row->result ?? '{}', true);
                    $bound = match ($row->kind) {
                        'training', 'civics' => $row->target_id === $scope,
                        'governance' => DB::table('legislatures')->where('id', $row->target_id)->where('jurisdiction_id', $scope)->exists(),
                        'judiciary' => DB::table('judiciaries')->where('id', $row->target_id)->where('jurisdiction_id', $scope)->exists(),
                    };
                    $target = ['kind' => $row->kind, 'target_id' => $row->target_id];
                    if (! $bound || ! self::isPrerequisiteNoop($row->kind, $result)) { $retained[] = $target; continue; }
                    $replacement = ['reason' => 'D014: prior prerequisite no-op was incorrectly recorded as applied.',
                        '_prior_receipts' => [['status' => $row->status, 'result' => $result, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]]];
                    app(AuditService::class)->append('simworld', 'sim.repair_receipt_corrected', $key + $target + ['previous' => (array) $row, 'status' => 'deferred'], 'WF-SYS-04');
                    DB::table('sim_repair_receipts')->where($key)->where($target)->update(['status' => 'deferred', 'result' => json_encode($replacement), 'updated_at' => now()]);
                    $corrected[] = $target;
                }
            }
            return ['corrected' => $corrected, 'retained' => $retained, 'repair_version' => $run->options['repair_version'] ?? 1];
        });
    }
}
