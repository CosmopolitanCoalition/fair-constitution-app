<?php

namespace App\Services\Demo\Stages;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Jobs\PublishBallotHashesJob;
use App\Models\ElectionRace;
use App\Services\Demo\CohortBallotExpander;
use App\Services\TabulationRecorder;
use App\Services\VoteCountingService;
use Illuminate\Support\Facades\DB;

/**
 * The COUNTING stage: hold the vote, for real.
 *
 * THE WHOLE DESIGN IN ONE SENTENCE — the ballots are never written down, and
 * the count is exact anyway.
 *
 * A district of four million voters would need four million `ballots` rows to
 * be counted the ordinary way. Across the planet that is billions of rows and
 * terabytes of ciphertext, for an election nobody will ever audit ballot by
 * ballot. But `BallotSet` stores identical rankings ONCE with a multiplicity,
 * and `VoteCountingService` re-groups its input the same way before doing any
 * arithmetic — so a cohort expanded into weighted groups produces a result that
 * is IDENTICAL, not approximate, to expanding every individual voter. Same
 * quota, same transfers, same winners, same `record_hash`. That equivalence is
 * pinned by WeightedBallotIdentityTest and rests on one line of the engine
 * (Gregory truncates the per-ballot weight BEFORE multiplying by multiplicity),
 * pinned separately by GregoryTruncationOrderTest.
 *
 * So this stage feeds the REAL, PROTECTED counting engine — the same
 * `countStv()` a live instance calls — and writes its real tabulation, rounds
 * and results. The demo's elections are not modelled; they are counted.
 *
 * THE ONE SEAM. Production builds its `CountInput` inside
 * `TabulationRecorder::countInput()`, which streams and decrypts physical
 * ballot rows and derives the tie seed from their hashes. A cohort has no such
 * rows, so this stage builds the equivalent input directly. Everything
 * downstream — the count, the rounds, the results, certification, seating — is
 * the untouched production path.
 *
 * THE TIE SEED IS NOT COSMETIC. Ties genuinely reach the lot in realistic
 * synthetic data, so the seed decides who wins a seat. It is therefore derived
 * deterministically and published: anyone holding the cohort can recompute the
 * root, re-run the count, and get the same winners.
 */
final class CountingStage
{
    /** Distinct preference orderings drawn per race. */
    public const RANKING_GROUPS = 64;

    /** Races persisted per audit batch (D3 — one Merkle-rooted entry each). */
    public const BATCH_SIZE = 50;

    private function __construct() {}

    /**
     * Count every uncounted race of one election.
     *
     * @return array{races: int, seats: int, ballots: int, skipped: int}
     */
    public static function run(string $electionId, ?string $runId, int $version): array
    {
        $recorder = app(TabulationRecorder::class);
        $counter = new VoteCountingService;

        $races = ElectionRace::query()
            ->where('election_id', $electionId)
            ->orderBy('id')
            ->get();

        $batch = [];
        $counted = 0;
        $seats = 0;
        $ballots = 0;
        $skipped = 0;

        foreach ($races as $race) {
            // Idempotent: a re-handed item must not count a race twice.
            $already = DB::table('tabulations')
                ->where('race_id', $race->id)
                ->where('status', 'complete')
                ->exists();

            if ($already) {
                $skipped++;

                continue;
            }

            $candidacyIds = $recorder->candidacyIds($race);

            if ($candidacyIds === []) {
                // A race nobody stood in cannot be counted. Honest, and rare —
                // the elections stage fields seats+1 per race.
                $skipped++;

                continue;
            }

            $electorate = self::electorateFor($race);

            if ($electorate < 1) {
                $skipped++;

                continue;
            }

            $seed = self::seed($race, $version);

            $groups = CohortBallotExpander::expand(
                seed: $seed,
                candidacyIds: $candidacyIds,
                electorate: $electorate,
                groups: self::RANKING_GROUPS,
            );

            $tabulation = $recorder->begin($race, 'initial');

            $result = $counter->countStv(new CountInput(
                candidacyIds: $candidacyIds,
                seats: (int) $race->seats,
                ballots: BallotSet::fromGrouped($groups),
                excluded: [],
                tieSeedBase: self::tieSeedBase($groups, (string) $race->id),
            ));

            $batch[] = [$tabulation, $race, $result];
            $counted++;
            $seats += count($result->elected);
            $ballots += $result->totalValid;

            if (count($batch) >= self::BATCH_SIZE) {
                $recorder->completeBatch($batch, self::provenance($runId, $version));
                $batch = [];
            }
        }

        if ($batch !== []) {
            $recorder->completeBatch($batch, self::provenance($runId, $version));
        }

        return ['races' => $counted, 'seats' => $seats, 'ballots' => $ballots, 'skipped' => $skipped];
    }

    /**
     * How many people vote in this race.
     *
     * A districted race takes the district's own measured population; an
     * at-large race takes the whole jurisdiction's. Turnout is already baked
     * into the cohort's `electorate`, so the district figure is scaled by the
     * same rate rather than re-deriving one.
     */
    private static function electorateFor(ElectionRace $race): int
    {
        // PER-CLUMP type_b (operator ruling 2026-07-29): the electorate is the
        // UNION of the panel's constituent jurisdictions' residents — NOT the
        // whole parent. createRaces sets a panel race's jurisdiction_id to the
        // PARENT (c500a1f), so the at-large branch below would count every voter
        // once PER CLUMP (N-fold inflation). A per-CHILD type_b race needs no
        // branch: its jurisdiction_id already IS the child, so the at-large
        // branch is exactly right.
        if ($race->type_b_panel_id !== null) {
            return self::panelElectorate((string) $race->type_b_panel_id);
        }

        $cohort = DB::table('jurisdiction_cohorts')
            ->where('jurisdiction_id', $race->jurisdiction_id)
            ->orderByDesc('version')
            ->first();

        if ($cohort === null) {
            return 0;
        }

        if ($race->district_id === null) {
            return (int) $cohort->electorate;
        }

        $districtPop = (int) DB::table('legislature_districts')
            ->where('id', $race->district_id)
            ->value('actual_population');

        if ($districtPop < 1) {
            return (int) $cohort->electorate;
        }

        return max(1, (int) floor($districtPop * (int) $cohort->turnout_pct / 100));
    }

    /**
     * A per-clump Type B race's electorate — the latest-version cohort
     * electorate summed over the panel's member jurisdictions
     * (legislature_type_b_panel_jurisdictions). Turnout is already baked into
     * each cohort's `electorate` (matching the at-large path), so a PAIR panel's
     * electorate is the sum of its two children's and a SINGLE's is one child's
     * — never the parent's whole roll (which would N-fold the count).
     */
    private static function panelElectorate(string $panelId): int
    {
        $memberIds = DB::table('legislature_type_b_panel_jurisdictions')
            ->where('panel_id', $panelId)
            ->pluck('jurisdiction_id')
            ->all();

        if ($memberIds === []) {
            return 0;
        }

        // Latest cohort version per member jurisdiction, electorate summed.
        return (int) DB::table('jurisdiction_cohorts as jc')
            ->whereIn('jc.jurisdiction_id', $memberIds)
            ->whereRaw('jc.version = (SELECT MAX(v.version) FROM jurisdiction_cohorts v WHERE v.jurisdiction_id = jc.jurisdiction_id)')
            ->sum('jc.electorate');
    }

    /** Deterministic per race and version — the world is a function of its seed. */
    private static function seed(ElectionRace $race, int $version): string
    {
        return hash('sha256', 'race:'.$race->id).':v'.$version;
    }

    /**
     * Mirror production's SHAPE: a root hash over the electorate's contents,
     * bound to the race. Production roots over every physical ballot hash; a
     * cohort roots over its grouped distribution. Either way the seed is a
     * published function of what was actually voted, so a third party can
     * reproduce the tie-break rather than take it on trust.
     *
     * @param  list<array{0: list<string>, 1: int}>  $groups
     */
    private static function tieSeedBase(array $groups, string $raceId): string
    {
        $leaves = [];

        foreach ($groups as [$ranking, $count]) {
            $leaves[] = hash('sha256', implode("\x1f", $ranking).':'.$count);
        }

        sort($leaves, SORT_STRING);

        return hash('sha256', PublishBallotHashesJob::rootHash($leaves).':'.$raceId);
    }

    /** Provenance on every batch entry: these counts were cohort-fed, and say so. */
    private static function provenance(?string $runId, int $version): array
    {
        return [
            'electorate_source' => 'cohort_weighted',
            'generator' => 'CohortBallotExpander v1',
            'sim_run_id' => $runId,
            'world_version' => $version,
        ];
    }
}
