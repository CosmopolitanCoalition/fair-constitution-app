<?php

namespace App\Services\Demo\Stages;

use App\Services\Demo\HashChainRandom;
use Illuminate\Support\Facades\DB;

/**
 * The COHORTS stage: decide who lives in a jurisdiction, statistically.
 *
 * One claimed item = one jurisdiction = one `jurisdiction_cohorts` row. Small,
 * bounded, idempotent, and individually committed — the ETL RULE at item grain,
 * which is what makes the run visible in a bar and resumable from any failure.
 *
 * Everything is derived from `hash(jurisdiction_id) + version` plus facts
 * already on the jurisdiction row, so nothing here needs the research layer to
 * exist yet: `population`, `official_languages` (populated for 99.6% of the
 * planet), `adm_level`, and `timezone` already carry enough signal to give a
 * place a plausible demography. The research layer, when it lands, REPLACES the
 * defaults rather than being a prerequisite for them — that ordering is
 * deliberate, so the demo can be walked end-to-end before a penny of LLM spend.
 *
 * The archetypes written here are the preference clusters
 * `CohortBallotExpander` samples from at count time. They are parameters, never
 * expanded people: a jurisdiction of 8 million stores one row.
 */
final class CohortStage
{
    /** Default share of a population that turns out. Overridable per run. */
    public const DEFAULT_TURNOUT_PCT = 62;

    private function __construct() {}

    /**
     * @return array{cohort_id: string, electorate: int, archetypes: int}
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, int $turnoutPct, ?\Closure $beat = null): array
    {
        $j = DB::table('jurisdictions')
            ->select('id', 'name', 'adm_level', 'population', 'official_languages', 'timezone')
            ->where('id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($j === null) {
            throw new \RuntimeException("Jurisdiction {$jurisdictionId} not found (or deleted).");
        }

        $seed = self::seed($jurisdictionId, $version);
        $population = max(0, (int) ($j->population ?? 0));

        // A jurisdiction with no measured population is a REAL case, not an
        // error: 34,763 of them exist on the planet because their borders sit
        // off the population raster. They get a cohort with a zero electorate
        // and are rendered honestly as unpopulated, never silently skipped.
        $electorate = (int) floor($population * $turnoutPct / 100);

        $archetypes = self::archetypes($seed, $j, $population, $beat);

        DB::table('jurisdiction_cohorts')->upsert(
            [[
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'jurisdiction_id' => $jurisdictionId,
                'run_id' => $runId,
                'version' => $version,
                'seed' => $seed,
                'population' => $population,
                'electorate' => $electorate,
                'turnout_pct' => $turnoutPct,
                'archetypes' => json_encode($archetypes),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['jurisdiction_id', 'version'],
            ['run_id', 'seed', 'population', 'electorate', 'turnout_pct', 'archetypes', 'updated_at']
        );

        $cohortId = (string) DB::table('jurisdiction_cohorts')
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('version', $version)
            ->value('id');

        return [
            'cohort_id' => $cohortId,
            'electorate' => $electorate,
            'archetypes' => count($archetypes['clusters']),
        ];
    }

    /** The determinism key. Same jurisdiction + same version = same people. */
    public static function seed(string $jurisdictionId, int $version): string
    {
        return hash('sha256', $jurisdictionId).':v'.$version;
    }

    /**
     * Persona parameters for this place. Deterministic, and shaped by real
     * signals already on the row rather than by a global average:
     * `official_languages` gives the language mix, `adm_level` proxies
     * urbanicity (a level-6 unit inside a level-2 province is a village), and
     * population sets the civic-desire priors (a hamlet has a far higher
     * candidacy rate per head than a metropolis).
     *
     * @return array{languages: list<string>, urbanicity: string, clusters: list<array{weight:int}>, civic: array<string,float|int>}
     */
    private static function archetypes(string $seed, object $j, int $population, ?\Closure $beat = null): array
    {
        $rng = new HashChainRandom($seed.':archetypes');

        $languages = json_decode((string) ($j->official_languages ?? '["en"]'), true);
        $languages = is_array($languages) && $languages !== [] ? array_values($languages) : ['en'];

        $admLevel = (int) ($j->adm_level ?? 4);
        $urbanicity = match (true) {
            $population >= 1_000_000 => 'metropolis',
            $population >= 100_000 => 'urban',
            $population >= 10_000 => 'town',
            $population > 0 => 'rural',
            default => 'unpopulated',
        };

        // Preference clusters: how many distinct political tendencies this place
        // has. Bigger, denser places are more plural — the count is bounded so
        // the ballot expansion stays cheap regardless of population.
        $clusterCount = match ($urbanicity) {
            'metropolis' => 8,
            'urban' => 7,
            'town' => 6,
            'rural' => 5,
            default => 3,
        };

        $clusters = [];
        for ($i = 0; $i < $clusterCount; $i++) {
            $beat && $beat();
            $clusters[] = ['weight' => $rng->between(10, 100)];
        }

        // Civic-desire priors. Candidacy rate falls with population: standing
        // for a village council is common, standing for a national chamber is
        // not, and a flat rate would make big places absurd.
        $candidacyPerThousand = match ($urbanicity) {
            'metropolis' => 0.02,
            'urban' => 0.2,
            'town' => 2.0,
            'rural' => 12.0,
            default => 0.0,
        };

        return [
            'languages' => $languages,
            'urbanicity' => $urbanicity,
            'adm_level' => $admLevel,
            'clusters' => $clusters,
            'civic' => [
                'candidacy_per_thousand' => $candidacyPerThousand,
                'org_affinity' => round($rng->between(5, 35) / 100, 2),
            ],
        ];
    }
}
