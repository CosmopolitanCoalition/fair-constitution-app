<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/** Explicit, scope-targeted correction of D014's demonstrably effect-free receipts. */
class SimRepairReceiptRecovery
{
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
