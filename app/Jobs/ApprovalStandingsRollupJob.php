<?php

namespace App\Jobs;

use App\Models\Election;
use App\Services\ApprovalService;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
 *
 * KEYSET-CHUNKED, RESUMABLE, HOST-SIZED (W-0187, THE ETL RULE). The old
 * unbounded ->cursor() over every approval_open election held one cursor
 * open for the whole pass. This walks the election id keyset in host-sized
 * chunks; a kill costs one chunk, and because a rollup is idempotent (it
 * recomputes) a resume repeats no work that matters and skips none.
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
        $limit   = HostCapacity::sweepChunk();
        $afterId = null;
        $seen    = 0;

        while (true) {
            $cursor = $this->runChunk($approvals, $afterId, $limit, $seen);

            if ($cursor === null) {
                break;
            }

            $afterId = $cursor;
            Log::info('ApprovalStandingsRollupJob: chunk done', ['seen' => $seen, 'after' => $afterId]);
        }
    }

    /**
     * Roll ONE keyset chunk of approval_open elections after $afterId.
     * Returns the last election id seen (next cursor), or null when empty.
     */
    public function runChunk(ApprovalService $approvals, ?string $afterId, int $limit, int &$seen = 0): ?string
    {
        $elections = Election::query()
            ->where('status', Election::STATUS_APPROVAL_OPEN)
            ->when($this->electionId !== null, fn ($q) => $q->whereKey($this->electionId))
            ->when($afterId !== null, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($elections->isEmpty()) {
            return null;
        }

        $lastId = null;
        foreach ($elections as $election) {
            $lastId = (string) $election->id;
            $seen++;
            $this->rollElection($election, $approvals);
        }

        return $lastId;
    }

    /** Roll every race of one election. The seam a subclass overrides in tests. */
    protected function rollElection(Election $election, ApprovalService $approvals): void
    {
        foreach ($election->races()->get() as $race) {
            $approvals->rollupRace($race);
        }
    }
}
