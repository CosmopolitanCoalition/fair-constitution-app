<?php

namespace App\Jobs\Elections;

use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\Tabulation;
use App\Jobs\PublishBallotHashesJob;
use App\Services\ElectionLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WI-B5 — per-election tabulation fan-out (counting design §D).
 *
 * Trigger chain (no official discretion, Art. II §2 hardened): the
 * ranked_close timer fires → AdvanceElectionPhaseJob flips ranked_open →
 * voting_closed and dispatches THIS job onto the `long-running` Horizon
 * queue. The job is system machinery, not a form: it flips voting_closed →
 * tabulating through ElectionLifecycleService (the single ESM-03
 * authority, audited) and fans out one TabulateRaceJob per race (Earth
 * general = 274 jobs; Horizon parallelizes).
 *
 * Per race it FIRST dispatches PublishBallotHashesJob (§B.5.4 — the
 * sorted commitment list seals the count's INPUTS into the chain before
 * the count runs; both jobs share the FIFO long-running queue) and then
 * the race count.
 *
 * Idempotent: a re-dispatch skips races that already hold a COMPLETE
 * tabulation of this kind (`tabulations.status` is the authority;
 * TabulateRaceJob re-checks under way). An election not in the expected
 * phase is left untouched.
 *
 * kind = 'audit_rerun' is the F-ELB-006 path (CertificationService::
 * beginAuditRerun): scoped to $raceId when the order names one race,
 * publication is NOT re-dispatched (the list is already sealed), and the
 * race jobs carry $auditId so the completing job can resolve the audit's
 * outcome (reaffirmed | corrected).
 */
class TabulateElectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public readonly string $electionId,
        public readonly string $kind = Tabulation::KIND_INITIAL,
        public readonly ?string $raceId = null,
        public readonly ?string $auditId = null,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(ElectionLifecycleService $lifecycle): void
    {
        $election = Election::query()->find($this->electionId);

        if ($election === null) {
            return;
        }

        if ($this->kind === Tabulation::KIND_INITIAL) {
            if ($election->status === Election::STATUS_VOTING_CLOSED) {
                $lifecycle->markTabulating($election);
            }

            if ($election->status !== Election::STATUS_TABULATING) {
                return; // wrong phase / timer re-fire — idempotent no-op
            }
        } elseif ($election->status !== Election::STATUS_AUDIT_RERUN) {
            return;
        }

        $races = $election->races()
            ->when($this->raceId !== null, fn ($q) => $q->whereKey($this->raceId))
            ->get();

        foreach ($races as $race) {
            if ($this->kind === Tabulation::KIND_INITIAL && $this->alreadyCounted($race)) {
                continue;
            }

            if ($this->kind === Tabulation::KIND_INITIAL) {
                // Inputs sealed before the count (idempotent per race).
                PublishBallotHashesJob::dispatch($race->id);
            }

            TabulateRaceJob::dispatch($race->id, $this->kind, $this->auditId);
        }
    }

    private function alreadyCounted(ElectionRace $race): bool
    {
        return Tabulation::query()
            ->where('race_id', $race->id)
            ->where('kind', Tabulation::KIND_INITIAL)
            ->where('status', Tabulation::STATUS_COMPLETE)
            ->exists();
    }
}
