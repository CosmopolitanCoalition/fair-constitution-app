<?php

namespace App\Jobs\Elections;

use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionRace;
use App\Models\Tabulation;
use App\Services\AuditService;
use App\Services\ElectionLifecycleService;
use App\Services\TabulationRecorder;
use App\Services\VoteCountingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * WI-B5 — one race, one count (counting design §D "TabulateRaceJob"):
 *
 *   1. idempotency gate: a COMPLETE 'initial' tabulation for the race ends
 *      the job (tabulations.status is the authority); a stale 'running'
 *      row is superseded by TabulationRecorder::begin (append-only count
 *      history — design §D.5).
 *   2. stream + decrypt ballots → grouped BallotSet; tieSeedBase derived
 *      from the published hash list (TabulationRecorder::countInput).
 *   3. VoteCountingService::countStv — or countRcv for seat_kind 'single'
 *      (the Phase D individual-executive consumer; wired now so the
 *      pipeline never needs reopening).
 *   4. TabulationRecorder::complete — rounds + results + record_hash +
 *      'race.tabulated' chain entry, one transaction.
 *   5. kind='initial': watermark — when the LAST race completes, one
 *      'election.tabulated' chain entry marks the election
 *      ready-to-certify (F-ELB-004 re-verifies every hash regardless).
 *      kind='audit_rerun': resolve the F-ELB-006 audit instead —
 *      hash unchanged → outcome 'reaffirmed', election returns to
 *      certified; changed → 'corrected', prior tabulation superseded,
 *      election stays audit_rerun awaiting the superseding F-ELB-004.
 *
 * Failure → the tabulation row stays 'running' for Horizon retry /
 * superseding re-dispatch; rounds are never edited.
 */
class TabulateRaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public readonly string $raceId,
        public readonly string $kind = Tabulation::KIND_INITIAL,
        public readonly ?string $auditId = null,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(
        TabulationRecorder $recorder,
        VoteCountingService $counter,
        ElectionLifecycleService $lifecycle,
        AuditService $audit,
    ): void {
        $race = ElectionRace::query()->find($this->raceId);

        if ($race === null) {
            return;
        }

        // Idempotency authority (re-dispatch, timer re-fire, double fan-out).
        if ($this->kind === Tabulation::KIND_INITIAL) {
            $complete = Tabulation::query()
                ->where('race_id', $race->id)
                ->where('kind', Tabulation::KIND_INITIAL)
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->exists();

            if ($complete) {
                return;
            }

            if ($race->status === Election::STATUS_VOTING_CLOSED) {
                $race->forceFill(['status' => Election::STATUS_TABULATING])->save();
            }
        }

        $tabulation = $recorder->begin($race, $this->kind);
        $input      = $recorder->countInput($race);

        $result = $race->seat_kind === ElectionRace::SEAT_KIND_SINGLE
            ? $counter->countRcv($input)
            : $counter->countStv($input);

        $recorder->complete(
            $tabulation,
            $race,
            $result,
            updateRace: $this->kind === Tabulation::KIND_INITIAL,
        );

        if ($this->kind === Tabulation::KIND_AUDIT_RERUN) {
            $this->resolveAudit($race, $lifecycle, $audit);

            return;
        }

        $this->markElectionTabulated($race, $audit);
    }

    /**
     * Watermark: one 'election.tabulated' entry once EVERY race holds a
     * complete initial tabulation (idempotent via the chain itself, same
     * pattern as PublishBallotHashesJob).
     */
    private function markElectionTabulated(ElectionRace $race, AuditService $audit): void
    {
        $election = $race->election()->first();

        if ($election === null) {
            return;
        }

        $raceIds = $election->races()->pluck('id')->map(fn ($id) => (string) $id)->all();

        $counted = Tabulation::query()
            ->whereIn('race_id', $raceIds)
            ->where('kind', Tabulation::KIND_INITIAL)
            ->where('status', Tabulation::STATUS_COMPLETE)
            ->distinct()
            ->count('race_id');

        if ($counted < count($raceIds)) {
            return;
        }

        $already = DB::table('audit_log')
            ->where('module', 'elections')
            ->where('event', 'election.tabulated')
            ->where('payload->election_id', (string) $election->id)
            ->exists();

        if ($already) {
            return;
        }

        $audit->append(
            module: 'elections',
            event: 'election.tabulated',
            payload: [
                'election_id' => (string) $election->id,
                'races'       => count($raceIds),
            ],
            ref: 'ESM-03',
            jurisdictionId: $election->jurisdiction_id,
        );
    }

    /**
     * F-ELB-006 resolution: when every race in the audit's scope holds a
     * post-order re-run, compare each re-run hash against the record the
     * certification sealed (the latest complete tabulation before the
     * order).
     */
    private function resolveAudit(ElectionRace $race, ElectionLifecycleService $lifecycle, AuditService $audit): void
    {
        $order = $this->auditId !== null ? ElectionAudit::query()->find($this->auditId) : null;

        if ($order === null || $order->resolved_at !== null) {
            return;
        }

        $election = Election::query()->find($order->election_id);

        if ($election === null) {
            return;
        }

        $scope = $election->races()
            ->when($order->race_id !== null, fn ($q) => $q->whereKey($order->race_id))
            ->get();

        $comparisons   = [];
        $lastRerun     = null;
        $correctedRows = [];

        foreach ($scope as $scopedRace) {
            $rerun = Tabulation::query()
                ->where('race_id', $scopedRace->id)
                ->where('kind', Tabulation::KIND_AUDIT_RERUN)
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->where('completed_at', '>=', $order->ordered_at)
                ->orderByDesc('completed_at')
                ->first();

            if ($rerun === null) {
                return; // another race's re-run is still pending — resolve later
            }

            $prior = Tabulation::query()
                ->where('race_id', $scopedRace->id)
                ->where('status', Tabulation::STATUS_COMPLETE)
                ->where('completed_at', '<', $order->ordered_at)
                ->orderByDesc('completed_at')
                ->first();

            $matches = $prior !== null && $prior->record_hash === $rerun->record_hash;

            $comparisons[] = [
                'race_id'    => (string) $scopedRace->id,
                'prior_hash' => $prior?->record_hash,
                'rerun_hash' => $rerun->record_hash,
                'matches'    => $matches,
            ];

            if (! $matches && $prior !== null) {
                $correctedRows[] = $prior;
            }

            $lastRerun = $rerun;
        }

        $corrected = collect($comparisons)->contains(fn (array $c) => ! $c['matches']);

        DB::transaction(function () use ($order, $election, $comparisons, $corrected, $correctedRows, $lastRerun, $lifecycle, $audit) {
            foreach ($correctedRows as $prior) {
                $prior->forceFill(['status' => Tabulation::STATUS_SUPERSEDED])->save();
            }

            $order->forceFill([
                'tabulation_id' => $lastRerun?->id,
                'outcome'       => $corrected ? ElectionAudit::OUTCOME_CORRECTED : ElectionAudit::OUTCOME_REAFFIRMED,
                'resolved_at'   => now(),
            ])->save();

            $audit->append(
                module: 'elections',
                event: 'election.audit_resolved',
                payload: [
                    'audit_id'    => (string) $order->id,
                    'election_id' => (string) $election->id,
                    'outcome'     => $order->outcome,
                    'races'       => $comparisons,
                ],
                ref: 'F-ELB-006',
                jurisdictionId: $election->jurisdiction_id,
            );

            if (! $corrected) {
                // Result reaffirmed — the standing certification holds;
                // ESM-03 returns audit_rerun → certified (audited move).
                $lifecycle->markCertified($election, [
                    'audit_id' => (string) $order->id,
                    'outcome'  => ElectionAudit::OUTCOME_REAFFIRMED,
                ]);
            }
            // corrected → election stays audit_rerun; the superseding
            // F-ELB-004 certification (board) completes the loop.
        });
    }
}
