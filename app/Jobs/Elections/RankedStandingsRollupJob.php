<?php

namespace App\Jobs\Elections;

use App\Models\Election;
use App\Services\AuditService;
use App\Services\Elections\RankedProjectionService;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * W4 ⑥ — the DAILY ranked-standings rollup (spec
 * docs/plans/scaling/RANKEDBALLOT_LIVEAGGREGATE_SPEC.md; operator ruling A:
 * daily-batched). Modeled on ApprovalStandingsRollupJob.
 *
 * This is the OUT-OF-BAND, non-request caller that decrypts a ranked_open
 * race's ballots (via RankedProjectionService — the ONLY new decryptForCount
 * caller) and caches a NON-authoritative first-preference projection the ballot
 * page reads. Daily-frozen is a secret-ballot policy invariant, not merely a
 * perf choice: a live per-request standing could influence later voters
 * (Art. II).
 *
 * It NEVER writes a `tabulations` row and NEVER flips `ballots.counted` — so
 * TabulateRaceJob's idempotency gate (a COMPLETE 'initial' tabulation is
 * terminal) is never tripped and the race never flips to TABULATING. Only
 * aggregate counts + candidacy ids leave the decrypt; the cache holds no voter
 * linkage. Pass an election id to roll a single election immediately.
 */
class RankedStandingsRollupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Per-race cache key prefix. The controller reads THIS; it never decrypts. */
    public const CACHE_PREFIX = 'ranked_agg:';

    /** >24h so a single missed daily run never blanks the card. */
    public const TTL_SECONDS = 129600; // 36h

    public function __construct(public readonly ?string $electionId = null) {}

    /**
     * KEYSET-CHUNKED, RESUMABLE, HOST-SIZED (W-0187, THE ETL RULE). The old
     * unbounded ->cursor() over every ranked_open election held one cursor
     * open for the whole pass. This walks the election id keyset in host-sized
     * chunks; a kill costs one chunk, and the projection is idempotent (a
     * recompute + cache put) so a resume repeats no work that matters.
     */
    public function handle(RankedProjectionService $projection, AuditService $audit): void
    {
        $limit   = HostCapacity::sweepChunk();
        $afterId = null;
        $seen    = 0;

        while (true) {
            $cursor = $this->runChunk($projection, $audit, $afterId, $limit, $seen);

            if ($cursor === null) {
                break;
            }

            $afterId = $cursor;
            Log::info('RankedStandingsRollupJob: chunk done', ['seen' => $seen, 'after' => $afterId]);
        }
    }

    /**
     * Roll ONE keyset chunk of ranked_open elections after $afterId. Returns
     * the last election id seen (next cursor), or null when empty.
     */
    public function runChunk(RankedProjectionService $projection, AuditService $audit, ?string $afterId, int $limit, int &$seen = 0): ?string
    {
        $elections = Election::query()
            ->where('status', Election::STATUS_RANKED_OPEN)
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
            $this->rollElection($election, $projection, $audit);
        }

        return $lastId;
    }

    /** Roll every race of one election. The seam a subclass overrides in tests. */
    protected function rollElection(Election $election, RankedProjectionService $projection, AuditService $audit): void
    {
        foreach ($election->races()->get() as $race) {
            $aggregate = $projection->computeForRace($race);

            if ($aggregate === null) {
                continue; // no ballots yet — leave the card null
            }

            Cache::put(self::CACHE_PREFIX.$race->id, $aggregate, self::TTL_SECONDS);

            // Counts-only audit parity (mirrors ApprovalService standings.rolled):
            // candidacy ids + a counts hash only — no voter linkage ever leaves.
            $audit->append(
                module: 'elections',
                event: 'ranked_standings.rolled',
                payload: [
                    'race_id'     => (string) $race->id,
                    'election_id' => (string) $race->election_id,
                    'as_of_date'  => $aggregate['as_of'],
                    'valid'       => $aggregate['valid'],
                    'quota'       => $aggregate['quota'],
                    'counts_hash' => hash('sha256', AuditService::canonicalJson($aggregate['first_prefs'])),
                ],
                ref: 'CLK-18',
                jurisdictionId: $race->jurisdiction_id,
            );
        }
    }
}
