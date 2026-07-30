<?php

namespace App\Services\Demo\Stages;

use App\Services\Demo\PersonaFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The IDENTITIES stage: mint the people a jurisdiction actually needs.
 *
 * THE SIZING DECISION, which is the whole design. A literal reading of the
 * charter would materialize ~8.35 billion residents — 8.35B `users` plus ~42.3
 * billion `residency_confirmations` (population-weighted mean ancestor depth
 * ~5.07), which is multiple terabytes before a single ballot, and 8.35 billion
 * serialized audit appends at a measured ~28.6/sec, i.e. about nine years.
 *
 * It is also unnecessary. Individual identity is only REQUIRED where the
 * constitution demands it: `legislature_members.user_id` is NOT NULL and a
 * winner must have been a candidate, so the mandatory population is
 * Σ(seats + 1) per race — about 17.2 million planet-wide, three orders of
 * magnitude below eight billion. Everyone else is a cohort statistic, and the
 * counting engine consumes them EXACTLY rather than approximately
 * (WeightedBallotIdentityTest).
 *
 * So this stage mints, per jurisdiction:
 *   · the candidacy pool its own races need, plus
 *   · a small visible sample so the civic plane is not a ghost town
 * and nothing else. A jurisdiction of eight million gets tens of people, not
 * eight million, and the demo says so rather than implying otherwise.
 *
 * RESIDENCY IS WRITTEN DIRECTLY, not filed. `ResidencyService::simulatePings`
 * files one F-IND-005 per day per resident and then DELETES them all at
 * verification — 240 billion serialized chain appends to produce nothing. The
 * three existing demo commands already take this same shortcut; the
 * constitutional gate reads `residency_confirmations`, which is what we write.
 */
final class IdentityStage
{
    /** Residents materialized purely so the civic plane has faces. */
    public const VISIBLE_SAMPLE = 12;

    /**
     * A SANITY ceiling, not a budget. The largest lawful roster on the real
     * planet is Earth's — 1,999 type_a seats across 282 districts plus a
     * 1,141-seat type_b chamber = Σ(seats+1) ≈ 3,423 — so nothing legitimate
     * comes near this.
     *
     * Exceeding it THROWS rather than truncating. Silently minting fewer people
     * than a chamber's races require is precisely the failure that produced an
     * empty world once already: the run reports done, the roster is short, and
     * the election simply cannot be contested. A visible review row is the only
     * acceptable outcome.
     */
    public const MAX_PER_JURISDICTION = 5000;

    private function __construct() {}

    /**
     * @return array{users: int, confirmations: int, reused: int}
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version): array
    {
        $cohort = DB::table('jurisdiction_cohorts')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('version', $version)
            ->first();

        if ($cohort === null) {
            throw new \RuntimeException(
                "No cohort for jurisdiction {$jurisdictionId} at version {$version} — the cohorts phase must run first."
            );
        }

        $archetypes = json_decode((string) $cohort->archetypes, true) ?: [];
        $languages = $archetypes['languages'] ?? ['en'];
        $urbanicity = $archetypes['urbanicity'] ?? 'town';

        $needed = self::rosterSize($jurisdictionId);

        // Idempotent by construction: a re-handed unit tops the roster up to
        // size rather than minting a second one.
        //
        // ⚠ COUNT ONLY THIS ENGINE'S OWN PEOPLE. Counting every active resident
        // looks equivalent and is not: a demo instance is the real standard
        // "broadly materialized", so it can legitimately already contain REAL
        // residents — a founded fixture, an imported world, a partially-played
        // instance. Counting those made the stage conclude the roster was
        // already full and mint NOBODY, silently, reporting done. That is
        // exactly what happened on the San Marino fixture: 11 items done,
        // 0 people, because someone else's residents filled the quota.
        //
        // The sim namespace is the discriminator, and it is reliable because
        // PersonaFactory::email is a pure function of (seed, index).
        $existing = DB::table('residency_confirmations as rc')
            ->join('users as u', 'u.id', '=', 'rc.user_id')
            ->where('rc.jurisdiction_id', $jurisdictionId)
            ->where('rc.is_active', true)
            ->where('u.email', 'like', 'sim-%@demo.invalid')
            ->count();

        if ($existing >= $needed) {
            return ['users' => 0, 'confirmations' => 0, 'reused' => $existing];
        }

        $seed = (string) $cohort->seed;
        $users = [];
        $confirmations = [];
        $now = now();

        for ($i = $existing; $i < $needed; $i++) {
            $persona = PersonaFactory::make($seed, $languages, $urbanicity, $i);
            $userId = (string) Str::uuid();

            $users[] = [
                'id' => $userId,
                'name' => $persona['name'],
                'display_name' => $persona['display_name'],
                'email' => PersonaFactory::email($seed, $i),
                // Unloggable by construction: a synthetic identity is never a
                // door into the instance. The @cga.test seeders use a known
                // password on purpose; a public demo must not.
                'password' => bcrypt(Str::random(40)),
                'status' => 'registered',
                'terms_accepted_at' => $now,
                'languages' => json_encode($persona['languages']),
                'timezone' => 'UTC',
                'locale' => $persona['locale'],
                'comm_prefs' => json_encode(['persona_source' => $persona['persona_source'], 'occupation' => $persona['occupation']]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $confirmations[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'jurisdiction_id' => $jurisdictionId,
                'days_confirmed' => 30,
                'confirmed_at' => $now,
                'voting_right_active' => true,
                'candidacy_right_active' => true,
                'is_active' => true,
                // depth 0 — this jurisdiction only. The full ancestor sweep is
                // what turns 8.35B residents into 42.3B rows; the demo's people
                // are residents of the place they are shown in.
                'depth' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bounded chunks, each its own committed statement (THE ETL RULE), so a
        // large roster is visible while it lands and resumable if it dies.
        foreach (array_chunk($users, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        foreach (array_chunk($confirmations, 500) as $chunk) {
            DB::table('residency_confirmations')->insert($chunk);
        }

        return [
            'users' => count($users),
            'confirmations' => count($confirmations),
            'reused' => $existing,
        ];
    }

    /**
     * How many people this jurisdiction needs: enough to contest every race its
     * own legislature runs, plus a visible sample.
     *
     * A race legally needs MORE candidates than seats, so the pool is
     * Σ(seats + 1). Districts are counted from the ACTIVE map only; a
     * jurisdiction with no legislature still gets its visible sample, because a
     * visitor may well look at it.
     */
    public static function rosterSize(string $jurisdictionId): int
    {
        $legislature = \App\Models\Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($legislature === null) {
            return self::VISIBLE_SAMPLE;
        }

        // ASK THE ENGINE, never re-derive. An earlier version reimplemented the
        // race rules here — "type_b counts only when 1..9" — and that duplicate
        // was wrong within a day: the 2026-07-26 ruling settled that the 5–9
        // band is a DISTRICT rule and does not bind an at-large Type B race,
        // which is one STV race at whatever size. A roster sized from a private
        // copy of the rules silently under-mints the moment the rules move.
        //
        // Driving racePlan() means this follows every future change to what is
        // lawful — including per-kind blocking — with no second edit here.
        $plan = app(\App\Services\ElectionLifecycleService::class)->racePlan($legislature);

        $pool = 0;

        foreach ($plan['kinds'] as $spec) {
            if ($spec['mode'] === 'districts') {
                // Σ(seats + 1) per district race.
                foreach ($spec['districts'] as $district) {
                    $pool += (int) $district->seats + 1;
                }
            } elseif ($spec['mode'] === 'at_large') {
                $pool += (int) $spec['seats'] + 1;
            } elseif ($spec['mode'] === 'panels') {
                // PER-CLUMP TYPE B (operator ruling 2026-07-29): one at-large
                // race per panel, and createRaces scopes EVERY panel race to the
                // chamber's OWN jurisdiction (jurisdiction_id = parent). So the
                // PARENT roster must cover Σ(panel seats + 1) too — mirror the
                // districts case, or ElectionStage::fieldCandidates throws
                // "roster too small" on the parent scope (districts + panels).
                foreach ($spec['panels'] as $panel) {
                    $pool += (int) $panel->seats + 1;
                }
            }
            // 'children' (per-CHILD ungrouped) contributes 0 to the PARENT pool
            // ON PURPOSE: those races are child-scoped (jurisdiction_id = the
            // child) and draw from each child's own roster, which rosterSize
            // sizes when IdentityStage runs on the child. 'blocked' elects
            // nobody, so it needs nobody.
        }

        $roster = max(self::VISIBLE_SAMPLE, $pool);

        if ($roster > self::MAX_PER_JURISDICTION) {
            throw new \RuntimeException(sprintf(
                'Roster of %d exceeds the sanity ceiling of %d for one jurisdiction. Refusing rather '
                .'than minting a short roster: an under-filled roster leaves the election uncontestable '
                .'while the run reports success.',
                $roster,
                self::MAX_PER_JURISDICTION
            ));
        }

        return $roster;
    }
}
