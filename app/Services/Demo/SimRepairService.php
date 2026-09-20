<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\Demo\Stages\{CountingStage, SeatingStage, TrainingStage, GovernanceStage, JudiciaryStage, CivicsStage};
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;

class SimRepairService
{
    public function __construct(private SimRepairInspector $inspector) {}

    public function run(SimRun $run, object $item, ?\Closure $beat = null, bool $planning = false): array
    {
        if (! ($run->options['repair_source_run'] ?? null)) { throw new \RuntimeException('Repair source is missing.'); }
        if ($reason = app(SimRunControl::class)->refusalReason()) { throw new \RuntimeException($reason); }
        $jurisdiction = (string) $item->jurisdiction_id;
        $before = $this->inspector->inspect($run, $jurisdiction, $beat);
        if ($planning) {
            foreach ($before['actions'] as $action) {
                if ($before['blockers'] !== [] && $action['kind'] !== 'chair') { continue; }
                $this->plan($run, $jurisdiction, $action['kind'], $action['target']);
            }
            return ['_verdict' => 'done', 'category' => $this->category($before), 'plan' => $before, 'source_run_id' => $run->options['repair_source_run']];
        }
        if (! ($run->options['repair_apply_authorized'] ?? false)) { throw new \RuntimeException('Repair execution has not been authorized.'); }
        $version = (int) ($run->options['version'] ?? 1);
        $source = (string) $run->options['repair_source_run'];
        $effects = [];
        $halted = function () use ($run, $beat): bool { $beat && $beat(); return ! $run->refresh()->isClaimable(); };
        // Chairs with valid seated boards do not wait on unrelated elections.
        foreach ($before['actions'] as $action) {
            if ($action['kind'] !== 'chair') { continue; }
            if ($halted()) { throw new SimRepairPaused('Repair halted between recorded actions.'); }
            $effects[] = $this->action($run, $jurisdiction, 'chair', $action['target'],
                fn () => app(SimChairService::class)->complete($action['target']));
        }
        if ($before['blockers'] === [] && ! ($before['verification']['inactive'] ?? null)) {
            $election = collect($before['actions'])->firstWhere('kind', 'election');
            $electionApplied = true;
            if ($election) {
                if ($halted()) { throw new SimRepairPaused('Repair halted before election recovery.'); }
                $effect = $this->action($run, $jurisdiction, 'election', $election['target'], function () use ($election, $source, $version, $beat, $run): array {
                    $fields = app(SimCandidateField::class)->fill($election['target'], $source, $version, $beat, (bool) ($run->options['no_floor'] ?? false));
                    if ($fields['too_few'] !== []) { throw new \RuntimeException('Election cannot be repaired above its real-population ceiling.'); }
                    $count = CountingStage::run($election['target'], $source, $version, $beat);
                    $seat = SeatingStage::run($election['target'], $source, $version, $beat);
                    return ['fielding' => $fields, 'counting' => $count, 'seating' => $seat];
                }, fn () => $this->inspector->inspect($run, $jurisdiction, $beat)['complete']['election'] ?? false);
                $effects[] = $effect; $electionApplied = $effect['status'] === 'applied';
            }
            $current = $this->inspector->inspect($run, $jurisdiction, $beat);
            $institutionActions = array_values(array_filter($current['actions'], fn ($a) => in_array($a['kind'], ['governance','judiciary','civics'], true)));
            if ($electionApplied && $current['blockers'] === [] && ($current['institution_ready'] ?? false) && $institutionActions !== []) {
                if ($halted()) { throw new SimRepairPaused('Repair halted before training.'); }
                $training = $this->action($run, $jurisdiction, 'training', $jurisdiction,
                    fn () => TrainingStage::run($jurisdiction, $source, $version, $beat),
                    fn ($result) => ($result['holders'] ?? 0) > 0 && ($result['failed'] ?? 0) === 0 && ($result['unarmed'] ?? 0) === 0
                        && (int) ($result['trained'] ?? 0) + (int) ($result['already'] ?? 0) === (int) $result['holders']);
                $effects[] = $training;
                if (($training['result']['failed'] ?? 0) === 0 && ($training['result']['unarmed'] ?? 0) === 0 && $training['status'] === 'applied') {
                    foreach ($institutionActions as $action) {
                        if ($halted()) { throw new SimRepairPaused('Repair halted between institution actions.'); }
                        $effect = $this->action($run, $jurisdiction, $action['kind'], $action['target'], function () use ($action, $jurisdiction, $source, $version, $beat): array {
                            $resumed = $this->resumePendingActs($jurisdiction, $action['kind'], $beat);
                            $result = match ($action['kind']) {
                                'governance' => GovernanceStage::run($jurisdiction, $source, $version, $beat),
                                'judiciary' => JudiciaryStage::run($jurisdiction, $source, $version, $beat),
                                'civics' => CivicsStage::run($jurisdiction, $source, $version, $beat),
                            };
                            return $result + ['resumed_votes' => $resumed];
                        }, fn () => $this->inspector->inspect($run, $jurisdiction, $beat)['complete'][$action['kind']] ?? false);
                        $effects[] = $effect;
                        if ($effect['status'] !== 'applied') { break; }
                    }
                }
            }
            // Include newly created boards, and boards missed by old pointer-only generators.
            foreach ($this->inspector->boards($jurisdiction, $beat) as $board) {
                if ($board->chair_seat_id !== null) { continue; }
                if ($halted()) { throw new SimRepairPaused('Repair halted between board actions.'); }
                $effects[] = $this->action($run, $jurisdiction, 'chair', $board->id,
                    fn () => app(SimChairService::class)->complete($board->id));
            }
        }
        $after = $this->inspector->inspect($run, $jurisdiction, $beat);
        $gaps = array_values(array_unique([...($after['verification']['gaps'] ?? []), ...$after['acceptance_gaps'], ...$after['blockers']]));
        foreach ($after['actions'] as $remaining) { $gaps[] = 'Unresolved '.$remaining['kind'].': '.$remaining['target']; }
        foreach ($effects as $effect) {
            if ($effect['status'] !== 'applied') { $gaps[] = $effect['kind'].': '.($effect['result']['reason'] ?? 'prerequisite or postcondition incomplete'); }
        }
        return ['_verdict' => $gaps === [] ? 'done' : 'review', '_reason' => $gaps === [] ? null : implode('; ', array_slice($gaps, 0, 8)),
            'before' => $before, 'after' => $after, 'effects' => $effects, 'gaps' => $gaps,
            'stipend_replayed' => false, 'source_run_id' => $source];
    }

    private function category(array $plan): string
    {
        if ($plan['verification']['inactive'] ?? null) { return 'lawfully_inactive'; }
        if ($plan['blockers'] !== []) { return 'blocked_recovery'; }
        $kinds = array_column($plan['actions'], 'kind');
        foreach (['election','governance','judiciary','civics','chair'] as $kind) {
            if (in_array($kind, $kinds, true)) { return $kind; }
        }
        return $plan['acceptance_gaps'] === [] && empty($plan['verification']['gaps']) ? 'already_clear' : 'acceptance_review';
    }

    private function resumePendingActs(string $jurisdiction, string $kind, ?\Closure $beat): array
    {
        $leg = \App\Models\Legislature::where('jurisdiction_id', $jurisdiction)->first();
        if (! $leg) { return []; }
        $members = $leg->members()->whereIn('status', ['elected','seated'])->whereNull('vacated_at')->get();
        $allowed = match ($kind) {
            'governance' => ['committee_creation','exec_delegation','department_creation'],
            'judiciary' => ['judiciary_creation'],
            'civics' => ['cgc_creation'],
            default => [],
        };
        $ids = [];
        \App\Models\ChamberVote::where('body_type', 'legislature')->where('body_id', $leg->id)
            ->where(function ($q) use ($kind) {
                $q->where('status', 'open');
                if ($kind === 'judiciary') {
                    $q->orWhere(function ($q) {
                        $q->where('votable_type', 'judiciary')->where('outcome', 'adopted')->whereExists(function ($q) {
                            $q->selectRaw('1')->from('appointments')->whereColumn('consent_vote_id', 'chamber_votes.id')->where('status', 'nominated')->whereNull('deleted_at');
                        });
                    });
                }
            })
            ->chunkById(\App\Support\HostCapacity::sweepChunk(), function ($votes) use ($members, $allowed, $kind, $beat, &$ids) {
                foreach ($votes as $vote) {
                    $target = match ($vote->votable_type) {
                        'chamber_vote_proposal' => \App\Models\ChamberVoteProposal::find($vote->votable_id),
                        'appointment_consent' => \App\Models\Appointment::find($vote->votable_id),
                        'judiciary' => \App\Models\Judiciary::find($vote->votable_id),
                        default => null,
                    };
                    $proposal = $target instanceof \App\Models\ChamberVoteProposal && in_array($target->proposal_kind, $allowed, true);
                    $appointment = $target instanceof \App\Models\Appointment && $target->status === 'nominated'
                        && (($kind === 'governance' && $target->nominated_via_form === 'F-EXE-001') || ($kind === 'judiciary' && $target->nominated_via_form === 'F-LEG-021'));
                    $slate = $kind === 'judiciary' && $target instanceof \App\Models\Judiciary && $vote->vote_type === 'bog_consent';
                    if (! $proposal && ! $appointment && ! $slate) { continue; }
                    $beat && $beat();
                    if ($vote->status === 'open') {
                        if ((int) $vote->serving_snapshot !== $members->count()) { throw new \RuntimeException('Existing vote electorate changed; inspect vote '.$vote->id); }
                        app(\App\Services\ChamberVoteService::class)->castManyYes($vote, $members);
                    }
                    $vote->refresh(); $ids[] = (string) $vote->id;
                    if ($vote->outcome !== 'adopted') { throw new \RuntimeException('Existing institutional vote did not adopt: '.$vote->id); }
                    if ($slate) { app(\App\Services\Judiciary\JudicialSeatService::class)->seatSlateOnAdoption($target); }
                }
            });
        return $ids;
    }

    private function key(SimRun $run, string $kind, string $target): array
    {
        return ['source_run_id' => $run->options['repair_source_run'], 'repair_version' => (int) ($run->options['repair_version'] ?? 1), 'kind' => $kind, 'target_id' => $target];
    }

    private function plan(SimRun $run, string $jurisdiction, string $kind, string $target): void
    {
        DB::table('sim_repair_receipts')->insertOrIgnore($this->key($run, $kind, $target) +
            ['jurisdiction_id' => $jurisdiction, 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Receipt and domain effects share one commit; no volatile audit buffer. */
    private function action(SimRun $run, string $jurisdiction, string $kind, string $target, \Closure $perform, ?\Closure $complete = null): array
    {
        $this->plan($run, $jurisdiction, $kind, $target);
        $key = $this->key($run, $kind, $target);
        $began = hrtime(true);
        try {
            return DB::transaction(function () use ($key, $kind, $perform, $complete): array {
                $query = DB::table('sim_repair_receipts')->where($key);
                $receipt = (clone $query)->lockForUpdate()->first();
                if (! in_array($receipt->status, ['planned','deferred'], true)) {
                    $saved = json_decode($receipt->result ?? '{}', true);
                    if ($receipt->status === 'applied' && $complete && ! $complete($saved)) {
                        return ['kind' => $kind, 'status' => 'blocked', 'result' => ['reason' => 'Existing receipt does not satisfy the current prerequisite/postcondition; inspect it.', 'saved' => $saved], 'reused' => true];
                    }
                    return ['kind' => $kind, 'status' => $receipt->status, 'result' => $saved, 'reused' => true];
                }
                $result = $perform();
                $status = ($result['status'] ?? null) === 'blocked' ? 'blocked'
                    : (SimRepairReceiptRecovery::isPrerequisiteNoop($kind, $result) ? 'deferred' : 'applied');
                if ($status === 'applied' && $complete && ! $complete($result)) {
                    $status = 'blocked'; $result['reason'] = 'Repair action did not meet its required postcondition.';
                }
                $previous = json_decode($receipt->result ?? '{}', true);
                if ($receipt->status === 'deferred') {
                    $result['_prior_receipts'] = [...($previous['_prior_receipts'] ?? []), ['status' => $receipt->status, 'result' => array_diff_key($previous, ['_prior_receipts' => true]), 'updated_at' => $receipt->updated_at]];
                }
                app(\App\Services\AuditService::class)->append('simworld', 'sim.repair_action', $key + ['status' => $status, 'result' => $result], 'WF-SYS-04');
                $query->update(['status' => $status, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                return ['kind' => $kind, 'status' => $status, 'result' => $result];
            });
        } catch (\Throwable $error) {
            if ($error instanceof SimRepairPaused) { throw $error; }
            $saved = DB::table('sim_repair_receipts')->where($key)->first();
            if ($saved && ! in_array($saved->status, ['planned','deferred'], true)) {
                return ['kind' => $kind, 'status' => $saved->status, 'result' => json_decode($saved->result ?? '{}', true), 'reused' => true];
            }
            // Failed transaction left no domain effects. Durable refusal avoids
            // creating a new act on every duplicate delivery.
            $result = ['reason' => $error->getMessage(), 'exception' => class_basename($error)];
            if ($saved?->status === 'deferred') { $result['_prior_receipts'] = [['status' => $saved->status, 'result' => json_decode($saved->result ?? '{}', true), 'updated_at' => $saved->updated_at]]; }
            DB::table('sim_repair_receipts')->where($key)->whereIn('status', ['planned','deferred'])
                ->update(['status' => 'blocked', 'result' => json_encode($result), 'updated_at' => now()]);
            return ['kind' => $kind, 'status' => 'blocked', 'result' => $result];
        } finally { SimTimer::record('repair.'.$kind, (int) ((hrtime(true) - $began) / 1000)); }
    }
}
