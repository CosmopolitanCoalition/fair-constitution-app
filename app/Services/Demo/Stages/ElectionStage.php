<?php

namespace App\Services\Demo\Stages;

use App\Models\Candidacy;
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
    public static function run(string $jurisdictionId, ?string $runId, int $version): array
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

        // THE REAL ENGINE. Creates the election, arms its clocks, and generates
        // exactly the races the constitution allows.
        $election = $lifecycle->scheduleGeneral($legislature);

        $races = DB::table('election_races')->where('election_id', $election->id)->get();

        $candidacies = self::fieldCandidates(
            $jurisdictionId,
            (string) $election->id,
            $races,
            $version
        );

        return [
            'election_id' => (string) $election->id,
            'races' => $races->count(),
            'candidacies' => $candidacies,
            'blocked_kinds' => $blockedKinds,
        ];
    }

    /**
     * Field seats+1 candidates per race from the jurisdiction's own roster.
     *
     * Candidates must be RESIDENTS — `RaceFootprint` gates candidacy on an
     * active residency association, and the identities stage wrote exactly
     * those rows. Drawing from the local roster is therefore not a shortcut; it
     * is the only lawful source.
     *
     * A user may hold only ONE candidacy per election (a DB unique constraint
     * on election_id+user_id), so the roster is consumed across races rather
     * than reused — which is also why the identities stage sizes it as
     * Σ(seats + 1) in the first place.
     */
    private static function fieldCandidates(
        string $jurisdictionId,
        string $electionId,
        $races,
        int $version
    ): int {
        $needed = $races->sum(fn ($r) => (int) $r->seats + self::EXTRA_CANDIDATES);

        if ($needed === 0) {
            return 0;
        }

        // The roster, in a deterministic order — residency is what makes them
        // eligible, so this is the same set the constitution would accept.
        $roster = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->orderBy('user_id')
            ->limit($needed)
            ->pluck('user_id')
            ->all();

        if (count($roster) < $needed) {
            throw new \RuntimeException(sprintf(
                'Roster too small to contest this election: %d residents for %d seats+1 slots. '
                .'The identities stage must run first and size to Σ(seats + 1).',
                count($roster),
                $needed
            ));
        }

        $rng = new HashChainRandom(hash('sha256', $electionId).':v'.$version);
        $now = now();
        $rows = [];
        $cursor = 0;

        foreach ($races as $race) {
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
                    // exercise it. The residency it would check is the row the
                    // identities stage already wrote.
                    'status' => Candidacy::STATUS_VALIDATED,
                    'position_tags' => json_encode([]),
                    'residency_attested_at' => $now,
                    'validated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Bounded chunks, each its own committed statement (THE ETL RULE).
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('candidacies')->insert($chunk);
        }

        // Touch the RNG so the seed is genuinely consumed — candidate ordering
        // is deterministic per election and will carry platform variation once
        // the research layer supplies it.
        $rng->next();

        return count($rows);
    }
}
