<?php

namespace App\Jobs;

use App\Models\Election;
use App\Services\ApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WI-B3 — the DAILY approval standings rollup (ESM-04, design §B.2.1).
 * Scheduled in routes/console.php; this job is the ONLY writer of
 * `approval_standings` besides the finalist-cutoff freeze.
 *
 * Earth-scale rule: standings are aggregated once a day per race — NEVER
 * per request, never per approval. Each race rollup appends exactly one
 * chain entry (module 'elections', event 'standings.rolled', counts hash
 * only; identities never leave the `approvals` table).
 *
 * Pass an election id to roll a single election immediately (the cutoff
 * path uses ApprovalService::rollupRace(freeze: true) directly instead).
 */
class ApprovalStandingsRollupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $electionId = null,
    ) {
    }

    public function handle(ApprovalService $approvals): void
    {
        $elections = Election::query()
            ->where('status', Election::STATUS_APPROVAL_OPEN)
            ->when($this->electionId !== null, fn ($q) => $q->whereKey($this->electionId))
            ->orderBy('created_at')
            ->cursor();

        foreach ($elections as $election) {
            foreach ($election->races()->get() as $race) {
                $approvals->rollupRace($race);
            }
        }
    }
}
