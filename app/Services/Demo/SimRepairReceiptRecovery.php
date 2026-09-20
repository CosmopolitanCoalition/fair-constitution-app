<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/** Explicit, scope-targeted correction of D014's demonstrably effect-free receipts. */
class SimRepairReceiptRecovery
{
    /** Retry only the rolled-back D021 fingerprint refusal; no repinning or recount reset. */
    public function retryCompatibleElections(SimRun $run, array $scopes): array
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        if ($scopes === [] || count($scopes) > 100) { throw new \RuntimeException('Name 1–100 exact election compatibility retry scopes.'); }
        return DB::transaction(function () use ($run, $scopes): array {
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! ($run->options['repair_source_run'] ?? null) || $run->status !== 'halted' || ! $run->haltRequested()
                || $run->phase !== 'repairing' || ! ($run->options['repair_apply_authorized'] ?? false)
                || ! ($run->options['repair_election_recovery'] ?? false)
                || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                || DB::table('sim_items')->where('run_id', $run->id)->where('status', 'running')->exists()) {
                throw new \RuntimeException('Compatibility retry requires the drained, halted, authorized repair run.');
            }
            $retried = []; $retained = [];
            foreach (array_unique($scopes) as $scope) {
                $item = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_scope')
                    ->where('unit_key', $scope)->where('status', 'review')->lockForUpdate()->first();
                $source = DB::table('sim_items')->where('run_id', $run->options['repair_source_run'])
                    ->where('kind', 'election_scope')->where('unit_key', $scope)->value('metrics');
                $id = json_decode($source ?? '{}', true)['election_id'] ?? null;
                $election = $id ? \App\Models\Election::find($id) : null;
                if (! $item || ! $election || $election->jurisdiction_id !== $scope || $election->kind !== 'general'
                    || ! in_array($election->status, ['scheduled', 'approval_open'], true)
                    || $election->constitutional_version !== 'cv1.ac7230fe88c24e2fcd8f323f5e160b78'
                    || ! app(\App\Services\ConstitutionalVersionService::class)->permitsElectionCertification(
                        $election->constitutional_version, $election->kind, $election->voting_method)) {
                    $retained[] = $scope; continue;
                }
                $key = ['source_run_id' => $run->options['repair_source_run'], 'repair_version' => (int) ($run->options['repair_version'] ?? 1),
                    'target_id' => $id, 'jurisdiction_id' => $scope];
                $receipts = DB::table('sim_repair_receipts')->where($key)->whereIn('kind', ['election','election_recovery'])
                    ->where('status', 'blocked')->lockForUpdate()->get();
                $matches = $receipts->filter(function ($receipt) use ($election) {
                    $result = json_decode($receipt->result ?? '{}', true);
                    return ($result['exception'] ?? null) === 'ConstitutionalViolation'
                        && str_starts_with($result['reason'] ?? '', "Election [{$election->id}] opened under constitutional_version [{$election->constitutional_version}] but the deployed version has changed");
                });
                if ($matches->count() !== 1 || $receipts->count() !== 1) { $retained[] = $scope; continue; }
                $receipt = $matches->first(); $result = json_decode($receipt->result, true);
                app(AuditService::class)->append('simworld', 'sim.election_compatibility_retry', $key + ['run_id' => $run->id,
                    'item_id' => $item->id, 'pinned_version' => $election->constitutional_version, 'previous' => $result], 'WF-SYS-04', jurisdictionId: $scope);
                DB::table('sim_repair_receipts')->where($key)->where('kind', $receipt->kind)->update(['status' => 'deferred',
                    'result' => json_encode(['reason' => 'D021 STV compatibility: original pinned contest and counts preserved.',
                        '_prior_receipts' => [['status' => $receipt->status, 'result' => $result, 'updated_at' => $receipt->updated_at]]]), 'updated_at' => now()]);
                DB::table('sim_items')->where('id', $item->id)->update(['status' => 'pending', 'claim_token' => null,
                    'reason' => null, 'finished_at' => null, 'updated_at' => now(),
                    'metrics' => json_encode(['_previous_compatibility_review' => json_decode($item->metrics ?? '{}', true)])]);
                $retried[] = $scope;
            }
            return ['retried' => $retried, 'retained' => $retained];
        });
    }

    /** Resume only a partially seated court with enough lawful, distinct court residents. */
    public function retryCourtRosters(SimRun $run, array $scopes): array
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        if ($scopes === [] || count($scopes) > 100) { throw new \RuntimeException('Name 1–100 exact court retry scopes.'); }
        return DB::transaction(function () use ($run, $scopes): array {
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! ($run->options['repair_source_run'] ?? null) || $run->status !== 'halted' || ! $run->haltRequested()
                || $run->phase !== 'repairing' || ! ($run->options['repair_apply_authorized'] ?? false)
                || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                || DB::table('sim_items')->where('run_id', $run->id)->where('status', 'running')->exists()) {
                throw new \RuntimeException('Court retry requires the drained, halted, authorized repair run.');
            }
            $retried = []; $retained = [];
            foreach (array_unique($scopes) as $scope) {
                $item = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_scope')
                    ->where('unit_key', $scope)->where('status', 'review')->lockForUpdate()->first();
                $court = \App\Models\Judiciary::where('jurisdiction_id', $scope)->where('status', 'creating')->first();
                if (! $item || ! $court) { $retained[] = $scope; continue; }
                $key = ['source_run_id' => $run->options['repair_source_run'], 'repair_version' => (int) ($run->options['repair_version'] ?? 1),
                    'kind' => 'judiciary', 'target_id' => $court->id, 'jurisdiction_id' => $scope];
                $receipt = DB::table('sim_repair_receipts')->where($key)->lockForUpdate()->first();
                $result = json_decode($receipt->result ?? '{}', true);
                if (! $receipt || $receipt->status !== 'blocked' || ! preg_match('/^\d+ seat\(s\) deferred$/D', $result['skipped'] ?? '')) {
                    $retained[] = $scope; continue;
                }
                $seats = DB::table('judicial_seats')->where('judiciary_id', $court->id)->whereNull('deleted_at')->get(['status','user_id']);
                $vacant = $seats->where('status', 'vacant')->count();
                if ($vacant === 0 || $seats->contains(fn ($s) => ! in_array($s->status, ['seated','vacant'], true))) { $retained[] = $scope; continue; }
                $available = DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->where('is_active', true)
                    ->whereNotIn('user_id', $seats->pluck('user_id')->filter()->all())
                    ->select('user_id')->distinct()->orderBy('user_id')->limit($vacant)->get();
                if ($available->count() < $vacant) { $retained[] = $scope; continue; }
                $replacement = ['reason' => 'D021: use existing court-jurisdiction nominee eligibility; preserve all seated judges.',
                    '_prior_receipts' => [['status' => $receipt->status, 'result' => $result, 'updated_at' => $receipt->updated_at]]];
                app(AuditService::class)->append('simworld', 'sim.court_roster_retry', $key + ['run_id' => $run->id,
                    'item_id' => $item->id, 'previous' => $result], 'WF-SYS-04', jurisdictionId: $scope);
                DB::table('sim_repair_receipts')->where($key)->update(['status' => 'deferred', 'result' => json_encode($replacement), 'updated_at' => now()]);
                DB::table('sim_items')->where('id', $item->id)->update(['status' => 'pending', 'claim_token' => null,
                    'reason' => null, 'finished_at' => null, 'updated_at' => now(),
                    'metrics' => json_encode(['_previous_court_review' => json_decode($item->metrics ?? '{}', true)])]);
                $retried[] = $scope;
            }
            return ['retried' => $retried, 'retained' => $retained];
        });
    }

    /** Permit a NEW delegation act after the operator's tiny-chamber ruling; never amend old votes. */
    public function retryTinyGovernance(SimRun $run, array $scopes): array
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        if ($scopes === [] || count($scopes) > 100) { throw new \RuntimeException('Name 1–100 exact governance retry scopes.'); }
        return DB::transaction(function () use ($run, $scopes): array {
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! ($run->options['repair_source_run'] ?? null) || $run->status !== 'halted' || ! $run->haltRequested()
                || $run->phase !== 'repairing' || ! ($run->options['repair_apply_authorized'] ?? false)
                || DB::table('sim_worker_leases')->where('run_id', $run->id)->exists()
                || DB::table('sim_items')->where('run_id', $run->id)->where('status', 'running')->exists()) {
                throw new \RuntimeException('Governance retry requires the drained, halted, authorized repair run.');
            }
            $retried = []; $retained = [];
            foreach (array_unique($scopes) as $scope) {
                $item = DB::table('sim_items')->where('run_id', $run->id)->where('kind', 'repair_scope')
                    ->where('unit_key', $scope)->where('status', 'review')->lockForUpdate()->first();
                $leg = \App\Models\Legislature::where('jurisdiction_id', $scope)->first();
                if (! $item || ! $leg || ! DB::table('executives')->where('jurisdiction_id', $scope)
                    ->whereNull('deleted_at')->where('status', 'forming')->exists()) { $retained[] = $scope; continue; }
                $key = ['source_run_id' => $run->options['repair_source_run'], 'repair_version' => (int) ($run->options['repair_version'] ?? 1),
                    'kind' => 'governance', 'target_id' => $leg->id, 'jurisdiction_id' => $scope];
                $receipt = DB::table('sim_repair_receipts')->where($key)->lockForUpdate()->first();
                $result = json_decode($receipt->result ?? '{}', true);
                if (! $receipt || $receipt->status !== 'blocked' || ($result['departments']['skipped'] ?? null) !== 'delegation vote did not adopt') {
                    $retained[] = $scope; continue;
                }
                $vote = \App\Models\ChamberVote::where('body_type', 'legislature')->where('body_id', $leg->id)
                    ->where('vote_type', 'exec_delegate')->orderByDesc('opened_at')->orderByDesc('id')->first();
                if (! $vote || $vote->status !== 'closed' || $vote->outcome !== 'failed' || $vote->threshold_basis !== 'supermajority') {
                    $retained[] = $scope; continue;
                }
                $members = $leg->members()->whereIn('status', ['elected','seated'])->whereNull('vacated_at')->get();
                $tallies = DB::table('chamber_vote_tallies')->where('vote_id', $vote->id)->get();
                $correctable = 0; $valid = $members->count() === (int) $vote->serving_snapshot && $tallies->isNotEmpty();
                foreach ($tallies as $tally) {
                    $serving = $vote->bicameral ? $members->filter(fn ($m) => $m->seatKind() === $tally->lane)->count() : $members->count();
                    $valid = $valid && $serving === (int) $tally->serving;
                    if ($tally->passed) { continue; }
                    $tiny = in_array($serving, [1, 2], true) && (int) $tally->required_yes > $serving
                        && (int) $tally->yes === $serving && $tally->quorate
                        && (int) $tally->no === 0 && (int) $tally->abstain === 0;
                    $valid = $valid && $tiny; $correctable += (int) $tiny;
                }
                if (! $valid || $correctable === 0) { $retained[] = $scope; continue; }
                $replacement = ['reason' => 'D021: operator authorized unanimity for one/two-member chambers; submit a new act.',
                    '_prior_receipts' => [['status' => $receipt->status, 'result' => $result, 'updated_at' => $receipt->updated_at]]];
                app(AuditService::class)->append('simworld', 'sim.tiny_governance_retry', $key + ['run_id' => $run->id,
                    'item_id' => $item->id, 'preserved_failed_vote_id' => $vote->id, 'previous' => $result], 'WF-SYS-04', jurisdictionId: $scope);
                DB::table('sim_repair_receipts')->where($key)->update(['status' => 'deferred', 'result' => json_encode($replacement), 'updated_at' => now()]);
                DB::table('sim_items')->where('id', $item->id)->update(['status' => 'pending', 'claim_token' => null,
                    'reason' => null, 'finished_at' => null, 'updated_at' => now(),
                    'metrics' => json_encode(['_previous_governance_review' => json_decode($item->metrics ?? '{}', true)])]);
                $retried[] = $scope;
            }
            return ['retried' => $retried, 'retained' => $retained];
        });
    }

    /** Retry only rolled-back election failures with a demonstrated panel cap defect. */
    public function retryPopulationCeiling(SimRun $run, array $scopes, bool $tinyElectorates = false, bool $distinctCapacity = false): array
    {
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        if ($scopes === [] || count($scopes) > 100) { throw new \RuntimeException('Name 1–100 exact population-ceiling retry scopes.'); }
        return DB::transaction(function () use ($run, $scopes, $tinyElectorates, $distinctCapacity): array {
            if ($distinctCapacity && DB::getDriverName() === 'pgsql') {
                foreach ([194720126, \App\Console\Commands\SimPumpCommand::ADVISORY_LOCK_KEY] as $key) {
                    if (! DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [$key])->acquired) {
                        throw new \RuntimeException('Repair control is busy; retry when idle.');
                    }
                }
            }
            $run = SimRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $terminal = $distinctCapacity && $run->status === 'done' && $run->phase === 'done'
                && ($run->phase_timings['repairing']['worklist']['complete'] ?? false)
                && ! SimRun::where('id', '!=', $run->id)->whereIn('status', ['queued','running','halted'])->exists()
                && ! DB::table('sim_items')->where('run_id', $run->id)->where('status', 'pending')->exists();
            $halted = $run->status === 'halted' && $run->haltRequested() && $run->phase === 'repairing';
            if (! ($run->options['repair_source_run'] ?? null) || (! $halted && ! $terminal) || ! ($run->options['repair_apply_authorized'] ?? false)
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
                if (! $election || $election->jurisdiction_id !== $scope || ! ($tinyElectorates || $distinctCapacity
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
                $replacement = ['reason' => $distinctCapacity ? 'D023: population-limited distinct representation and constrained-pool ordering; preserve occupied seats.' : ($tinyElectorates ? 'D020: retry integer turnout and optional challenger correction.' : 'D018: retry after correcting the existing real-population ceiling.'),
                    '_prior_receipts' => [['status' => $receipt->status, 'result' => $result, 'updated_at' => $receipt->updated_at]]];
                app(AuditService::class)->append('simworld', $distinctCapacity ? 'sim.distinct_capacity_retry' : ($tinyElectorates ? 'sim.small_electorate_retry' : 'sim.population_ceiling_retry'), $key + ['run_id' => $run->id,
                    'item_id' => $item->id, 'previous' => $result], 'WF-SYS-04', jurisdictionId: $scope);
                DB::table('sim_repair_receipts')->where($key)->update(['status' => 'deferred', 'result' => json_encode($replacement), 'updated_at' => now()]);
                DB::table('sim_items')->where('id', $item->id)->update(['status' => 'pending', 'claim_token' => null,
                    'reason' => null, 'finished_at' => null, 'updated_at' => now(),
                    'metrics' => json_encode(['_previous_population_review' => json_decode($item->metrics ?? '{}', true)])]);
                $retried[] = $scope;
            }
            if ($terminal && $retried !== []) {
                app(AuditService::class)->append('simworld', 'sim.repair_review_continued', ['run_id' => $run->id,
                    'scopes' => $retried, 'previous_finished_at' => $run->finished_at, 'previous_phase_timings' => $run->phase_timings], 'WF-SYS-04');
                $timings = $run->phase_timings; unset($timings['repairing']['finished_at'], $timings['done']);
                $run->forceFill(['status' => 'halted', 'phase' => 'repairing', 'halt_requested_at' => now(),
                    'finished_at' => null, 'phase_timings' => $timings,
                    'items_review' => max(0, $run->items_review - count($retried)), 'open_items' => count($retried)])->save();
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
