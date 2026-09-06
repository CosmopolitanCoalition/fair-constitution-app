<?php

namespace Tests\Constitutional;

use App\Services\InstitutionProvisionService;
use App\Services\InstitutionScaleService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the provisioning SQL mirror agrees with the PHP formula.
 *
 * ONE FORMULA, TWO CALL SITES. `InstitutionScaleService` is the REFERENCE
 * IMPLEMENTATION (lane 3 owns it, and the scaling path calls the statics
 * directly). `InstitutionProvisionService` is set-based — chunked
 * `INSERT … SELECT` over 25,000 rows at a time, per THE ETL RULE — so it cannot
 * call a PHP static per row and must express the same decision in SQL. That is a
 * MIRROR, not a fork, and this test is what makes the distinction real: the two
 * are compared across every band and every boundary value.
 *
 * The file already stated this contract for the zero rule ("Mirrors
 * InstitutionScaleService::tierFor(), which is the reference implementation and
 * is pinned against this SQL"). This extends the same guarantee to the tier and
 * the bench, which provisioning now consults.
 *
 * THE INVARIANTS:
 *
 *  1. THE TIER MIRROR IS EXACT. For every (population, constituents) pair —
 *     including each band boundary and the depth-promotion threshold — the SQL
 *     returns exactly what `tierFor()` returns. Not "close": equal.
 *  2. THE BENCH MIRROR IS EXACT. The SQL bench equals `judgeCount(tier)` —
 *     5/5/7/9, the values §9-Q2 ruled to KEEP.
 *  3. THE ART. IV §1 FLOOR IS UNBREAKABLE. No input produces a bench below 5,
 *     including the `none` tier that the zero rule already excludes. A floor
 *     that is merely unreachable is not a floor.
 *  4. THE `free` BINDING SHORT-CIRCUITS BOTH. Under free binding population
 *     imposes nothing and every place is `standard` — a founding property of the
 *     world, never a per-jurisdiction dial.
 *
 * ⚑ IF LANE 3 CHANGES A CURVE OR A CLAMP, THIS TEST GOES RED — and that is its
 * job. The mirror and the reference must move in the same commit.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class InstitutionProvisionMirrorParityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_provision_mirror';

    /** Band boundaries and their neighbours — where an off-by-one would hide. */
    private const POPULATIONS = [
        0, 1, 2, 999, 1_000, 1_001, 249_999, 250_000, 250_001,
        9_999_999, 10_000_000, 10_000_001,
    ];

    /** Child counts around the depth-promotion threshold of 25. */
    private const CHILD_COUNTS = [0, 1, 24, 25, 26];

    public function test_the_sql_tier_mirror_matches_the_php_reference_exactly(): void
    {
        $this->onLivePg(function () {
            $rows = $this->evaluate($this->sql('tierSql'));

            foreach ($rows as $row) {
                $expected = InstitutionScaleService::tierFor(
                    (int) $row->population,
                    (int) $row->kids,
                    InstitutionScaleService::BINDING_REAL,
                );

                $this->assertSame(
                    $expected,
                    $row->result,
                    "tier mismatch at population={$row->population}, constituents={$row->kids}: "
                    ."PHP says {$expected}, the provisioning SQL says {$row->result}"
                );
            }

            // Non-vacuity: the matrix must actually exercise more than one tier.
            $tiers = array_unique(array_map(fn ($r) => $r->result, $rows));
            $this->assertGreaterThanOrEqual(4, count($tiers), 'the matrix must span the bands, not sit in one');
        });
    }

    public function test_the_sql_bench_mirror_matches_the_bench_law_exactly(): void
    {
        $this->onLivePg(function () {
            // THE BENCH LAW (2026-09-05): seats stand in through the population
            // column of the matrix, constituents through kids, floor fixed at 5.
            $rows = $this->evaluate(\App\Support\BenchLaw::sql('COALESCE(j.population, 0)', '5', 'k.kids'));

            foreach ($rows as $row) {
                $expected = \App\Support\BenchLaw::bench((int) $row->population, 5, (int) $row->kids);

                $this->assertSame(
                    $expected,
                    (int) $row->result,
                    "bench mismatch at seats={$row->population}, constituents={$row->kids}"
                );
                $this->assertGreaterThanOrEqual(5, (int) $row->result, 'the floor holds for every input');
            }

            $benches = array_unique(array_map(fn ($r) => (int) $r->result, $rows));
            $this->assertGreaterThan(1, count($benches), 'the matrix must reach past the floor');
        });
    }

    public function test_the_free_binding_makes_every_place_standard(): void
    {
        $this->onLivePg(function () {
            $service = app(InstitutionProvisionService::class);
            $this->forceBinding($service, InstitutionScaleService::BINDING_FREE);

            $rows = $this->evaluate($this->sqlFor($service, 'tierSql'));

            foreach ($rows as $row) {
                $this->assertSame(
                    InstitutionScaleService::TIER_STANDARD,
                    $row->result,
                    'under `free` binding population imposes nothing — every place is standard'
                );
                $this->assertSame(
                    InstitutionScaleService::tierFor(
                        (int) $row->population,
                        (int) $row->kids,
                        InstitutionScaleService::BINDING_FREE,
                    ),
                    $row->result,
                );
            }
        });
    }

    /**
     * Evaluate a provisioning SQL expression over the whole matrix WITHOUT
     * touching a real jurisdiction: the (population, kids) pairs are supplied as
     * a VALUES list aliased to the same `j` and `k` names the expression expects.
     *
     * @return list<object{population:int, kids:int, result:mixed}>
     */
    private function evaluate(string $expression): array
    {
        $tuples = [];

        foreach (self::POPULATIONS as $pop) {
            foreach (self::CHILD_COUNTS as $kids) {
                $tuples[] = "({$pop}, {$kids})";
            }
        }

        $values = implode(', ', $tuples);

        $rows = DB::select("
            SELECT j.population, k.kids, ({$expression}) AS result
              FROM (VALUES {$values}) AS j(population, kidcount)
              CROSS JOIN LATERAL (SELECT j.kidcount AS kids) k
        ");

        $this->assertCount(
            count(self::POPULATIONS) * count(self::CHILD_COUNTS),
            $rows,
            'the matrix must evaluate in full'
        );

        return $rows;
    }

    /** The expression under test, from the container's service. */
    private function sql(string $method): string
    {
        return $this->sqlFor(app(InstitutionProvisionService::class), $method);
    }

    /**
     * Read a private SQL-emitting method. Reflection rather than widening the
     * service's API: these are internals, and a pin should not force a public
     * surface into existence just to be able to look at them.
     */
    private function sqlFor(InstitutionProvisionService $service, string $method): string
    {
        $ref = new \ReflectionMethod($service, $method);
        $ref->setAccessible(true);

        return (string) $ref->invoke($service);
    }

    private function forceBinding(InstitutionProvisionService $service, string $binding): void
    {
        $prop = new \ReflectionProperty($service, 'binding');
        $prop->setAccessible(true);
        $prop->setValue($service, $binding);
    }

    /** The AchievementsPageTest posture: live pg, set as default, always rolled back. */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
