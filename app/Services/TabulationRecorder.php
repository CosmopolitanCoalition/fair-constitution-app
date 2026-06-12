<?php

namespace App\Services;

use App\Domain\Ballots\BallotBox;
use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Counting\CountResult;
use App\Domain\Counting\Micro;
use App\Jobs\PublishBallotHashesJob;
use App\Models\Ballot;
use App\Models\Candidacy;
use App\Models\ElectionRace;
use App\Models\Tabulation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WI-B5 — the single DB face of the counting engine (counting design §D
 * "TabulateRaceJob" steps 1–4, factored out so TabulateRaceJob and the
 * countback path (VacancyService) persist count records identically).
 *
 *   begin()      — open a `tabulations` row (kind initial | audit_rerun |
 *                  countback). Stale 'running' rows of the same kind are
 *                  marked superseded first (design §D.5: count history is
 *                  append-only; rounds are never edited).
 *   countInput() — stream + decrypt the race's ballots through
 *                  BallotBox::decryptForCount (the legitimate caller per
 *                  its trust-boundary note) into a grouped BallotSet, and
 *                  derive tieSeedBase = sha256(root-hash-of-sorted-ballot-
 *                  hashes ∥ ':' ∥ race_id) — public inputs, so the §A.5-T
 *                  seeded lot is reproducible by any auditor.
 *   complete()   — ONE transaction: tabulation_rounds (chunks of 500),
 *                  race_results (winners + vote_share_norm), tabulation →
 *                  complete with the sealed record_hash, race quota/total
 *                  snapshot, ballots.counted, and the 'race.tabulated'
 *                  chain entry — mutation and chain entry atomic, matching
 *                  the Phase A engine pattern. NEVER ballot content in the
 *                  payload: the round record is the public count, the
 *                  ballots stay sealed.
 *
 * vote_share_norm (q-ledger #2, the Phase C committee tie-break input):
 * the winner's NORMALIZED-QUOTA SHARE — tally held at the start of their
 * election round, divided by the race quota, in microvotes:
 *
 *     vote_share_norm = tally_at_election_µv / (quota × SCALE)
 *
 * Dividing by the race's own quota normalizes one-person-one-vote
 * deviations across districts (a quota is "one seat's worth of votes" in
 * that district), so shares are comparable chamber-wide. Truncated to 4
 * decimals (engine truncation discipline). Computed once here from the
 * count record, copied to the member row at certification (design §A B-8).
 */
class TabulationRecorder
{
    /** Candidacy statuses that form the counting universe of a race. */
    public const COUNTABLE_CANDIDACY_STATUSES = [
        Candidacy::STATUS_VALIDATED,
        Candidacy::STATUS_IN_POOL,
        Candidacy::STATUS_FINALIST,
        Candidacy::STATUS_NON_FINALIST,
        // Post-certification statuses — audit re-runs and countbacks count
        // the same universe the original count saw.
        Candidacy::STATUS_ELECTED,
        Candidacy::STATUS_DEFEATED,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly BallotBox $ballots,
    ) {
    }

    /**
     * Open a tabulation run. `$excludedCandidacyId` is the countback
     * strike (DB CHECK: NOT NULL iff kind = 'countback'; the full
     * cumulative strike list rides the audit payload).
     */
    public function begin(ElectionRace $race, string $kind, ?string $excludedCandidacyId = null): Tabulation
    {
        return DB::transaction(function () use ($race, $kind, $excludedCandidacyId): Tabulation {
            // Append-only history: a re-run supersedes a crashed run, it
            // never edits one.
            Tabulation::query()
                ->where('race_id', $race->id)
                ->where('kind', $kind)
                ->where('status', Tabulation::STATUS_RUNNING)
                ->update(['status' => Tabulation::STATUS_SUPERSEDED, 'updated_at' => now()]);

            return Tabulation::create([
                'race_id'               => $race->id,
                'kind'                  => $kind,
                'excluded_candidacy_id' => $excludedCandidacyId,
                'engine_version'        => VoteCountingService::ENGINE_VERSION,
                'seats'                 => (int) $race->seats,
                'status'                => Tabulation::STATUS_RUNNING,
                'started_at'            => now(),
            ]);
        });
    }

    /**
     * Build the pure counting input for a race. Plaintext rankings exist
     * only inside this call's generator chain and are erased by BallotSet
     * grouping (which also strips ordering).
     */
    public function countInput(ElectionRace $race): CountInput
    {
        $hashes = Ballot::query()
            ->where('race_id', $race->id)
            ->where('kind', Ballot::KIND_RANKED)
            ->orderBy('ballot_hash')
            ->pluck('ballot_hash')
            ->all();

        $tieSeedBase = hash('sha256', PublishBallotHashesJob::rootHash($hashes) . ':' . $race->id);

        return new CountInput(
            candidacyIds: $this->candidacyIds($race),
            seats: (int) $race->seats,
            ballots: BallotSet::fromRankings($this->ballots->decryptForCount($race)),
            excluded: [],
            tieSeedBase: $tieSeedBase,
        );
    }

    /** @return list<string> the race's countable candidacy ids */
    public function candidacyIds(ElectionRace $race): array
    {
        return Candidacy::query()
            ->where('race_id', $race->id)
            ->whereIn('status', self::COUNTABLE_CANDIDACY_STATUSES)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Persist a finished count (see class docblock). `$updateRace` is true
     * only for the initial count — re-runs and countbacks never overwrite
     * the race's published quota/total snapshot.
     */
    public function complete(
        Tabulation $tabulation,
        ElectionRace $race,
        CountResult $result,
        bool $updateRace = true,
        array $auditExtra = [],
    ): Tabulation {
        return DB::transaction(function () use ($tabulation, $race, $result, $updateRace, $auditExtra): Tabulation {
            $now = now();

            // Round record — full tallies every round (storage semantics;
            // display collapse is the Vue layer's business).
            $rows = [];
            foreach ($result->rounds as $round) {
                $rows[] = [
                    'id'            => (string) Str::uuid(),
                    'tabulation_id' => $tabulation->id,
                    'round_no'      => $round->roundNo,
                    'action'        => $round->action,
                    'candidacy_id'  => $round->candidacyId,
                    'transfer'      => $round->transfer !== null
                        ? json_encode($round->transfer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null,
                    'tallies'       => json_encode($round->tallies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at'    => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('tabulation_rounds')->insert($chunk);
            }

            // Winners → race_results (vote_share_norm computed once here).
            $norms = self::voteShareNorms($result);

            $resultRows = [];
            foreach ($result->elected as $e) {
                $resultRows[] = [
                    'id'              => (string) Str::uuid(),
                    'tabulation_id'   => $tabulation->id,
                    'candidacy_id'    => $e['candidacy_id'],
                    'round_elected'   => $e['round'],
                    'seat_no'         => $e['seat_no'],
                    'vote_share_norm' => $norms[$e['candidacy_id']] ?? null,
                    'is_runner_up'    => false,
                    'runner_up_rank'  => null,
                    'created_at'      => $now,
                ];
            }

            if ($resultRows !== []) {
                DB::table('race_results')->insert($resultRows);
            }

            $tabulation->forceFill([
                'status'       => Tabulation::STATUS_COMPLETE,
                'quota'        => $result->quota,
                'total_valid'  => $result->totalValid,
                'record_hash'  => $result->recordHash(),
                'completed_at' => $now,
            ])->save();

            if ($updateRace) {
                $race->forceFill([
                    'quota'               => $result->quota,
                    'total_valid_ballots' => $result->totalValid,
                ])->save();

                Ballot::query()
                    ->where('race_id', $race->id)
                    ->where('kind', Ballot::KIND_RANKED)
                    ->update(['counted' => true]);
            }

            // Sealed into the chain in the SAME transaction (design §D.4).
            $this->audit->append(
                module: 'elections',
                event: 'race.tabulated',
                payload: array_merge([
                    'race_id'        => (string) $race->id,
                    'election_id'    => (string) $race->election_id,
                    'tabulation_id'  => (string) $tabulation->id,
                    'kind'           => $tabulation->kind,
                    'engine_version' => $result->engineVersion,
                    'seats'          => $result->seats,
                    'quota'          => $result->quota,
                    'total_valid'    => $result->totalValid,
                    'rounds'         => count($result->rounds),
                    'seats_unfilled' => $result->seatsUnfilled,
                    'elected'        => $result->elected,
                    'record_hash'    => $result->recordHash(),
                ], $auditExtra),
                ref: 'ESM-03',
                jurisdictionId: $race->jurisdiction_id,
            );

            return $tabulation->refresh();
        });
    }

    /**
     * Normalized-quota shares per winner (see class docblock) — PURE,
     * pinned DB-free by tests/Feature/TabulationCertificationPipelineTest.
     *
     * @return array<string, string> candidacy_id → '1.2345' (4-dp, truncated)
     */
    public static function voteShareNorms(CountResult $result): array
    {
        $byRound = [];
        foreach ($result->rounds as $round) {
            $byRound[$round->roundNo] = $round;
        }

        $denominator = max(1, $result->quota) * Micro::SCALE;
        $norms = [];

        foreach ($result->elected as $e) {
            $round = $byRound[$e['round']] ?? null;

            // Round-START tallies: in a surplus-elect round the winner
            // still holds their FULL pre-transfer total (the fixture
            // convention) — that total is "the votes they were elected
            // with"; shortcut-fill winners hold their sub-quota tally.
            $tallyMicro = (int) ($round?->tallies['candidates'][$e['candidacy_id']] ?? 0);

            $scaled = min(Micro::mulDiv($tallyMicro, 10_000, $denominator), 9999_9999);

            $norms[$e['candidacy_id']] = sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
        }

        return $norms;
    }
}
