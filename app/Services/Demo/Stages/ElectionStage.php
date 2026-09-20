<?php

namespace App\Services\Demo\Stages;

use App\Models\Election;
use App\Models\Legislature;
use App\Services\ElectionLifecycleService;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;

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
     * @return array<string,mixed>  election_id (?string), races, candidacies,
     *         blocked_kinds (list), and on a too-few-residents close: inactive
     *         and too_few_residents (the first scopes, for the review reader).
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null, bool $noFloor = false): array
    {
        $mLeg = hrtime(true);
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();
        SimTimer::record('election.legislature', (int) ((hrtime(true) - $mLeg) / 1000));

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
        $mExist = hrtime(true);
        $existing = Election::query()
            ->where('legislature_id', $legislature->id)
            ->where('kind', Election::KIND_GENERAL)
            ->whereIn('status', [Election::STATUS_SCHEDULED, Election::STATUS_APPROVAL_OPEN])
            ->orderByDesc('created_at')
            ->first();
        SimTimer::record('election.existing_lookup', (int) ((hrtime(true) - $mExist) / 1000));

        if ($existing !== null) {
            $existingRaces = DB::table('election_races')->where('election_id', $existing->id)->get();

            if ($existingRaces->isNotEmpty()) {
                $mField = hrtime(true);
                $fielded = self::fieldCandidates(
                    $jurisdictionId,
                    $runId,
                    (string) $existing->id,
                    $existingRaces,
                    $version,
                    $beat,
                    $noFloor,
                );
                SimTimer::record('election.field', (int) ((hrtime(true) - $mField) / 1000));

                if ($fielded['too_few'] !== []) {
                    return self::tooFewResidents($existingRaces->count(), $fielded['too_few']);
                }

                return [
                    'election_id' => (string) $existing->id,
                    'races' => $existingRaces->count(),
                    'candidacies' => $fielded['candidacies'],
                    'blocked_kinds' => [],
                ];
            }
        }

        $lifecycle = app(ElectionLifecycleService::class);

        $mPlan = hrtime(true);
        $plan = $lifecycle->racePlan($legislature);
        SimTimer::record('election.plan', (int) ((hrtime(true) - $mPlan) / 1000));
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
        $mSched = hrtime(true);
        $election = $lifecycle->scheduleGeneral($legislature, plan: $plan);

        $races = DB::table('election_races')->where('election_id', $election->id)->get();
        SimTimer::record('election.schedule', (int) ((hrtime(true) - $mSched) / 1000));

        $mField = hrtime(true);
        $fielded = self::fieldCandidates(
            $jurisdictionId,
            $runId,
            (string) $election->id,
            $races,
            $version,
            $beat,
            $noFloor,
        );
        SimTimer::record('election.field', (int) ((hrtime(true) - $mField) / 1000));

        if ($fielded['too_few'] !== []) {
            return self::tooFewResidents($races->count(), $fielded['too_few']);
        }

        return [
            'election_id' => (string) $election->id,
            'races' => $races->count(),
            'candidacies' => $fielded['candidacies'],
            'blocked_kinds' => $blockedKinds,
        ];
    }

    /**
     * TOO FEW RESIDENTS (operator ruling 2026-09-19, sim-roster-vs-real-population
     * = C, "ceiling at real population"). The place has fewer real residents
     * than its election needs candidates, and the ceiling forbids minting more
     * people than live there. The election cannot be contested, so nobody is
     * fielded and the item closes DONE with no election id: no count, seating or
     * governance item follows (the same posture as a blocked chamber or a place
     * with no board). Counted on the run and shown on the Step 5 page.
     *
     * @param  array<string,array{residents:int,needed:int,population:int}>  $tooFew  by race scope
     * @return array<string,mixed>
     */
    private static function tooFewResidents(int $races, array $tooFew): array
    {
        return [
            'election_id' => null,
            'races' => $races,
            'candidacies' => 0,
            'blocked_kinds' => [IdentityStage::INACTIVE_TOO_FEW_RESIDENTS],
            'inactive' => IdentityStage::INACTIVE_TOO_FEW_RESIDENTS,
            'too_few_residents' => array_slice($tooFew, 0, 5, true),
        ];
    }

    /**
     * The verdict for a race scope whose roster is still short after the
     * top-up. Pure: the pinned seam of the ceiling law.
     *
     *   'too_few_residents'  the real population is below the need, so the
     *                        ceiling is the cause: lawful, the election closes.
     *   'short'              the place has the people and the roster does not
     *                        (the override is off, or an anomaly): review.
     */
    public static function shortScopeVerdict(int $needed, ?int $population): string
    {
        return $population !== null && $population < $needed
            ? IdentityStage::INACTIVE_TOO_FEW_RESIDENTS
            : 'short';
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
     * Assignment is election-wide and serialized on that election. Narrower
     * scopes draw first; existing assignments are reserved on retry. A collision
     * is refilled from eligible residents, never silently discarded.
     *
     * @return array{candidacies:int, too_few: array<string,array{residents:int,needed:int,population:int}>}
     *         too_few is non-empty when the whole election is short by law
     *         (the ceiling); nobody was fielded and the caller closes it.
     */
    private static function fieldCandidates(
        string $jurisdictionId,
        ?string $runId,
        string $electionId,
        $races,
        int $version,
        ?\Closure $beat = null,
        bool $noFloor = false
    ): array {
        return app(\App\Services\Demo\SimCandidateField::class)->fill(
            $electionId, $runId, $version, $beat, $noFloor
        );
    }
}
