<?php

namespace Tests\Constitutional;

use App\Services\WorldStatsService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — a suppressed snapshot never contributes a number to the
 * world rollup (ATLAS_DESIGN.md §4 and §9; CI-1).
 *
 * The reach snapshot withholds any count below the k-anonymity floor of 5. The
 * planet total is a SUM of those rows, which makes it the obvious back door: add
 * the suppressed places in and the aggregate republishes exactly what the floor
 * refused, place by place, to anyone willing to difference two nights.
 *
 * THE INVARIANTS:
 *
 *  1. `verifiedTotal` sums PUBLISHED rows only. A suppressed row adds nothing.
 *  2. A suppressed place still counts toward `placesGauged`. It IS gauged — we
 *     simply may not say by how much. Dropping it entirely would understate how
 *     much of the world is measured and hand back a different leak.
 *  3. `measuredPlaces` counts the `measured` state only, so it never implies a
 *     number exists for a place that withheld one.
 *  4. A world that has never been measured yields NULL, never 0. "Nobody is
 *     verified" and "we have not counted" are different claims, and only one of
 *     them is ever true of a new instance.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class WorldRollupSuppressionTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_world_rollup';

    public function test_a_suppressed_snapshot_adds_no_number_but_is_still_gauged(): void
    {
        $this->onLivePg(function () {
            [$a, $b] = $this->twoPlaces();

            // A future night, so it is unambiguously "the latest" the rollup reads.
            $night = '2099-06-01';

            // One published place: 40 verified residents.
            $this->snapshotRow($a, $night, verified: 40, suppressed: false, state: 'measured');

            // One SUPPRESSED place. verified/ratio NULL is the shape the DB
            // CHECK constraint requires, and the shape the write side produces.
            $this->snapshotRow($b, $night, verified: null, suppressed: true, state: 'activating');

            $reach = app(WorldStatsService::class)->compute()['reach'];

            $this->assertSame($night, $reach['asOfDate'], 'the rollup must read the latest night');

            // (1) The total carries the published place only.
            $this->assertSame(40, $reach['verifiedTotal'], 'a suppressed row must add nothing to the planet total');

            // (2) But the suppressed place is still counted as gauged.
            $this->assertSame(2, $reach['placesGauged'], 'a suppressed place is gauged — we simply may not say by how much');

            // (3) …and is not counted as measured.
            $this->assertSame(1, $reach['measuredPlaces'], 'only the `measured` state may be counted as measured');
        });
    }

    /**
     * The stronger case: a night in which EVERY place is suppressed publishes no
     * total at all. A zero here would be the loudest possible lie — it would
     * assert that nobody in the world is verified, on a night when the truth is
     * that every single place was too small to say.
     */
    public function test_a_night_where_everything_is_suppressed_publishes_no_total(): void
    {
        $this->onLivePg(function () {
            [$a, $b] = $this->twoPlaces();

            $night = '2099-06-02';
            $this->snapshotRow($a, $night, verified: null, suppressed: true, state: 'activating');
            $this->snapshotRow($b, $night, verified: null, suppressed: true, state: 'activating');

            $reach = app(WorldStatsService::class)->compute()['reach'];

            $this->assertNull($reach['verifiedTotal'], 'an all-suppressed night must publish NO total — never 0');
            $this->assertSame(2, $reach['placesGauged']);
            $this->assertSame(0, $reach['measuredPlaces']);
        });
    }

    /**
     * The economy and people domains are RULED `planned` (§8 Q4, option (a)), so
     * the rollup must leave them ABSENT rather than writing a zeroed shape the
     * Atlas would render as real figures.
     */
    public function test_planned_domains_are_absent_not_zeroed(): void
    {
        $this->onLivePg(function () {
            $domains = app(WorldStatsService::class)->compute();

            $this->assertArrayNotHasKey('economy', $domains, 'a planned domain must be absent, not zeroed');
            $this->assertArrayNotHasKey('people', $domains, 'a planned domain must be absent, not zeroed');
        });
    }

    /** @return array{0:string,1:string} two jurisdiction ids we are authoritative for */
    private function twoPlaces(): array
    {
        $places = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereNull('authoritative_server_id')
            ->orderBy('adm_level')
            ->limit(2)
            ->pluck('id')
            ->all();

        $this->assertCount(2, $places, 'the live box needs at least two jurisdictions to gauge');

        return [(string) $places[0], (string) $places[1]];
    }

    private function snapshotRow(string $jurisdictionId, string $night, ?int $verified, bool $suppressed, string $state): void
    {
        DB::table('legitimacy_snapshots')->insert([
            'jurisdiction_id' => $jurisdictionId,
            'as_of_date' => $night,
            'verified_residents' => $verified,
            'population_estimate' => 1000,
            'ratio_micro' => $verified === null ? null : (int) round($verified / 1000 * 1_000_000),
            'state' => $state,
            'suppressed' => $suppressed,
            'population_provenance' => 'test',
            'population_year' => 2024,
            'source_server_id' => null,
        ]);
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
