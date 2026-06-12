<?php

namespace App\Jobs;

use App\Models\Ballot;
use App\Models\ElectionRace;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * WF-CIV-04 step 3 — voter self-audit publication (design §B.5.4/§B.5.5).
 *
 * At `voting_closed`, per race:
 *
 *  1. CLUSTER the ballots heap on the ballot_hash unique index — the
 *     mitigation (NOT a proof) for the physical insertion-order channel:
 *     before this, a DB superuser could correlate ballots↔envelopes by
 *     ctid adjacency; after it, heap order is hash order. Single-table
 *     CLUSTER is transaction-safe and takes a brief ACCESS EXCLUSIVE lock
 *     (fine post-close: the window is shut, nothing writes ballots).
 *  2. Append the SORTED hash list + its root hash to the audit chain
 *     (module 'elections', event 'ballot_hashes.published') — this entry
 *     is what makes receipts verifiable: a voter checks their receipt's
 *     ballot_hash for inclusion; the tamper-evident chain pins the list.
 *
 * Publishing hashes here does NOT breach the commit-time audit discipline
 * (§B.5.6 — never hash at commit): the list is published once, sorted,
 * after the window closes, so no chain adjacency links any hash to any
 * envelope entry.
 *
 * Root hash = sha256 of the ascending-sorted hashes concatenated — a
 * compact integrity anchor, not a Merkle tree (at Phase B scale the full
 * list IS the inclusion proof; the read endpoint serves it verbatim).
 *
 * Idempotent per race (one publication entry, ever) — re-dispatch and
 * retry-after-CLUSTER both converge. Queued on `long-running` alongside
 * TabulateElectionJob (Earth-scale races publish six-figure lists).
 */
class PublishBallotHashesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT = 'ballot_hashes.published';

    public int $tries = 1;

    public function __construct(
        public readonly string $raceId,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(AuditService $audit): void
    {
        $race = ElectionRace::query()->findOrFail($this->raceId);

        // Idempotency: exactly one publication per race, ever.
        $published = DB::table('audit_log')
            ->where('module', 'elections')
            ->where('event', self::EVENT)
            ->where('payload->race_id', $race->id)
            ->exists();

        if ($published) {
            return;
        }

        // 1. Physical re-order mitigation (whole table — hash order carries
        //    no race grouping information either).
        DB::statement('CLUSTER ballots USING ballots_ballot_hash_unique');

        // 2. Sorted list + root → chain.
        $hashes = Ballot::query()
            ->where('race_id', $race->id)
            ->orderBy('ballot_hash')
            ->pluck('ballot_hash')
            ->all();

        $audit->append(
            module: 'elections',
            event: self::EVENT,
            payload: [
                'election_id'   => $race->election_id,
                'race_id'       => $race->id,
                'ballot_count'  => count($hashes),
                'root_hash'     => self::rootHash($hashes),
                'ballot_hashes' => $hashes,
            ],
            ref: 'WF-CIV-04',
            actorId: null,
            jurisdictionId: $race->jurisdiction_id,
        );
    }

    /**
     * Canonical root over a hash list: sha256 of the ascending-sorted
     * concatenation. Pure — pinned by BallotSecrecyTest.
     *
     * @param  list<string>  $hashes
     */
    public static function rootHash(array $hashes): string
    {
        sort($hashes, SORT_STRING);

        return hash('sha256', implode('', $hashes));
    }
}
