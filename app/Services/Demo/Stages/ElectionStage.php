<?php

namespace App\Services\Demo\Stages;

use App\Models\Candidacy;
use App\Models\Election;
use App\Models\Legislature;
use App\Services\Demo\HashChainRandom;
use App\Services\ElectionLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ELECTIONS stage: call a real election and field real candidates.
 *
 * THE ENGINE IS DRIVEN, NEVER COPIED. The election, its races and the seat
 * arithmetic all come from `ElectionLifecycleService::scheduleGeneral()` — the
 * same method the CLK-01 clock calls on a live instance. That is what makes the
 * demo's governments the same shape as a real one rather than a plausible
 * drawing of one, and it means the per-kind blocking ruling (2026-07-25) applies
 * here for free: a chamber whose type_b half has no lawful race still elects its
 * districts, and the blocked half is recorded rather than silently dropped.
 *
 * WHAT IS BATCHED, AND WHY THAT IS THE LIMIT. Candidacies are written in bulk
 * with ONE summary audit entry, not one filing each. That is lawful under the
 * autoscale precedent because a synthetic candidacy is MECHANICALLY DERIVED:
 * its content is fully determined by the roster and the race, there is no actor
 * exercising judgment, and no role gate is being passed. The precedent stops
 * exactly there — certifying an election and seating a member are
 * governance-constitutive acts that the pinned engine keeps per-act, so this
 * stage deliberately does NOT reach them. That is the operator's D3 boundary,
 * not an implementation convenience.
 */
final class ElectionStage
{
    /** A race needs strictly more candidates than seats to be a contest. */
    public const EXTRA_CANDIDATES = 1;

    private function __construct() {}

    /**
     * @return array{election_id: ?string, races: int, candidacies: int, blocked_kinds: list<string>}
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($legislature === null) {
            // Not every jurisdiction has a chamber. That is an ordinary outcome,
            // not a failure — the item settles done with nothing to report.
            return ['election_id' => null, 'races' => 0, 'candidacies' => 0, 'blocked_kinds' => []];
        }

        // FAST PATH (W7 item 4): ADOPT STEP 4'S ELECTION, NEVER RE-PLAN IT.
        //
        // Step 4 (the provision run) already scheduled every seated chamber's
        // general election and cut its races from the apportionment walk. The
        // sim's only remaining election work is to field candidates. So when an
        // open general election WITH races already exists for this chamber,
        // adopt it directly: skip racePlan (the apportionment walk, which
        // scheduleGeneral runs even on adoption — ~900k times across the planet)
        // and skip scheduleGeneral entirely.
        //
        // This selects EXACTLY the election scheduleGeneral would have adopted
        // (same legislature_id + KIND_GENERAL + SCHEDULED/APPROVAL_OPEN filter,
        // same newest-first order — lines 153-159 there). An election that
        // carries races was not fully blocked and had a board when Step 4 cut
        // them, so the fully_blocked and board guards below are already satisfied
        // by its existence; adopting manufactures NO second election, which is
        // the exact thing those guards protect against.
        $existing = Election::query()
            ->where('legislature_id', $legislature->id)
            ->where('kind', Election::KIND_GENERAL)
            ->whereIn('status', [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN])
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            $existingRaces = DB::table('election_races')->where('election_id', $existing->id)->get();

            if ($existingRaces->isNotEmpty()) {
                $candidacies = self::fieldCandidates(
                    $jurisdictionId,
                    (string) $existing->id,
                    $existingRaces,
                    $version,
                    $beat,
                );

                return [
                    'election_id' => (string) $existing->id,
                    'races' => $existingRaces->count(),
                    'candidacies' => $candidacies,
                    'blocked_kinds' => [],
                ];
            }
        }

        $lifecycle = app(ElectionLifecycleService::class);

        $plan = $lifecycle->racePlan($legislature);
        $blockedKinds = collect($plan['kinds'])
            ->filter(fn ($spec) => $spec['mode'] === 'blocked')
            ->keys()
            ->all();

        if ($plan['fully_blocked']) {
            // Honest refusal: this chamber cannot lawfully elect anyone yet.
            // The item is DONE, not failed — the world is allowed to contain
            // places whose government is still forming, and the console shows it.
            return [
                'election_id' => null,
                'races' => 0,
                'candidacies' => 0,
                'blocked_kinds' => $blockedKinds,
            ];
        }

        // AN UNACTIVATED JURISDICTION DOES NOT GET AN ELECTION.
        //
        // F-ELB-004 requires an active election board to certify, and the board
        // is minted by ACTIVATION (WF-JUR-01), not by sizing a chamber. Without
        // this guard the stage happily scheduled a general election for a place
        // that had never been activated: races were drawn, candidates fielded,
        // ballots counted — and seating then refused, because there was no board
        // to certify through. That is not a harmless no-op. The orphan election
        // stays OPEN, so when the place is later activated it acquires a SECOND
        // general election alongside the first, and a subsequent run certifies
        // both. Niue ended the day with two certified elections per jurisdiction
        // and two overlapping terms, which took a timestamp trace to untangle and
        // briefly looked like a duplicate-firing bug in the term clock. It was
        // not: the clock opened exactly one successor per certification. This
        // stage had simply manufactured a second election to certify.
        //
        // So the refusal moves UPSTREAM, to the cheapest point that can see the
        // problem. Same posture as `fully_blocked` above — done, not failed, with
        // a reason a human can read, because a world containing places that are
        // sized but not yet activated is ordinary.
        $hasBoard = DB::table('election_boards')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasBoard) {
            return [
                'election_id' => null,
                'races' => 0,
                'candidacies' => 0,
                'blocked_kinds' => ['no_election_board'],
            ];
        }

        // THE REAL ENGINE (fallback — a chamber Step 4 did not schedule, e.g. the
        // narrow co-test posture). Creates the election, arms its clocks, and
        // generates exactly the races the constitution allows. The plan computed
        // above for the block check is handed in so racePlan runs ONCE, not twice
        // (the same reuse the Step 4 seat path makes — scheduleGeneral docblock).
        $election = $lifecycle->scheduleGeneral($legislature, plan: $plan);

        $races = DB::table('election_races')->where('election_id', $election->id)->get();

        $candidacies = self::fieldCandidates(
            $jurisdictionId,
            (string) $election->id,
            $races,
            $version,
            $beat,
        );

        return [
            'election_id' => (string) $election->id,
            'races' => $races->count(),
            'candidacies' => $candidacies,
            'blocked_kinds' => $blockedKinds,
        ];
    }

    /**
     * Field seats+1 candidates per race, EACH FROM ITS OWN RACE'S JURISDICTION.
     *
     * Candidates must be RESIDENTS — `RaceFootprint` gates candidacy on an
     * active residency association with the race's jurisdiction, and the
     * identities stage wrote exactly those rows. Drawing from the local roster
     * is therefore not a shortcut; it is the only lawful source.
     *
     * ⚑ PER-CHILD (operator ruling 2026-07-29). An unflagged Type B chamber
     * elects one at-large race PER DIRECT CHILD, each scoped to that child
     * (`election_races.jurisdiction_id` = the child). Those candidates can only
     * come from THAT CHILD's residents, never a pooled parent roster — pooling
     * would let one place's residents contest another's seats, the very thing
     * per-child exists to prevent. So races are grouped by their own
     * jurisdiction and each group draws from that jurisdiction's roster. A
     * district / type_a race carries the chamber's jurisdiction (or none), and
     * falls back to the election's jurisdiction.
     *
     * A user may hold only ONE candidacy per election (a DB unique constraint on
     * election_id+user_id). The identities stage writes confirmations at depth 0
     * — this jurisdiction only, no ancestor sweep — so no user sits in two
     * jurisdictions' rosters, and the constraint holds across groups by
     * construction; within a group the cursor prevents reuse.
     */
    private static function fieldCandidates(
        string $jurisdictionId,
        string $electionId,
        $races,
        int $version,
        ?\Closure $beat = null
    ): int {
        // Group each race under the jurisdiction whose residents may contest it.
        $byScope = [];

        foreach ($races as $race) {
            $scope = ! empty($race->jurisdiction_id) ? (string) $race->jurisdiction_id : $jurisdictionId;
            $byScope[$scope][] = $race;
        }

        $rng = new HashChainRandom(hash('sha256', $electionId).':v'.$version);
        $now = now();
        $rows = [];

        foreach ($byScope as $scope => $scopeRaces) {
            $needed = 0;
            foreach ($scopeRaces as $race) {
                $needed += (int) $race->seats + self::EXTRA_CANDIDATES;
            }

            if ($needed === 0) {
                continue;
            }

            // The roster, in a deterministic order — residency is what makes
            // them eligible, so this is the same set the constitution accepts.
            $roster = DB::table('residency_confirmations')
                ->where('jurisdiction_id', $scope)
                ->where('is_active', true)
                ->orderBy('user_id')
                ->limit($needed)
                ->pluck('user_id')
                ->all();

            if (count($roster) < $needed) {
                throw new \RuntimeException(sprintf(
                    'Roster too small to contest this election: %d residents in jurisdiction %s for %d '
                    .'seats+1 slots. The identities stage must run first and size to Σ(seats + 1) for '
                    .'each race-bearing jurisdiction (per-child races draw from each child).',
                    count($roster),
                    $scope,
                    $needed
                ));
            }

            $cursor = 0;

            foreach ($scopeRaces as $race) {
                $slots = (int) $race->seats + self::EXTRA_CANDIDATES;

                for ($i = 0; $i < $slots; $i++) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'election_id' => $electionId,
                        'race_id' => $race->id,
                        'user_id' => $roster[$cursor++],
                        // VALIDATED so the count can see them: the board's
                        // validation step (F-ELB-002) is a human judgment about a
                        // real person's residency, and there is no human here to
                        // exercise it. The residency it would check is the row
                        // the identities stage already wrote.
                        'status' => Candidacy::STATUS_VALIDATED,
                        'position_tags' => json_encode([]),
                        'residency_attested_at' => $now,
                        'validated_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        // Bounded chunks, each its own committed statement (THE ETL RULE).
        // insertOrIgnore, not insert: a kill mid-loop leaves committed chunks
        // behind, and the reclaimed item re-runs the whole stage. The roster
        // draw and slot assignment are deterministic, so the re-run rebuilds the
        // SAME (election_id, user_id) rows; the unique constraint drops the ones
        // already committed and lands only the missing ones. Redo costs one
        // chunk, never a constraint violation (THE ETL RULE: resumable).
        foreach (array_chunk($rows, 500) as $chunk) {
            $beat && $beat();
            DB::table('candidacies')->insertOrIgnore($chunk);
        }

        // Touch the RNG so the seed is genuinely consumed — candidate ordering
        // is deterministic per election and will carry platform variation once
        // the research layer supplies it.
        $rng->next();

        return count($rows);
    }
}
