<?php

namespace Tests\Constitutional;

use App\Services\DistrictingService;
use App\Support\AutoscaleEnumeration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * POOL LANDING PINS (operator rulings 2026-09-02, the drift redraw pass).
 *
 * Every pool below sits beside locked giants that rounded DOWN, so the
 * pool owes more seats than its own fractional sum shows in the scope
 * frame. The pins hold the four classes the live ledger exposed:
 *
 *   A. THE LANDING RUNS BEFORE THE LEGALITY TEST. A two-atom pool whose
 *      only exact landing has one bin below the floor files that bin as a
 *      floor exception (Erbil 8 + 2), never one 9-seat district and a lost
 *      seat. A landed 1 takes the sub-2 lift at write time (Cagayan Valley
 *      9 + 1 -> 2, bonus 1, net 10). An in-band exact landing outranks the
 *      exception when its worst district is in the same or a better band
 *      (Milunga 5 + 5, no exception).
 *   B. THE POOL CAPACITY PROMOTION. A pool owing more than its atoms can
 *      hold under the ceiling gives the spare seat to the atom with the
 *      highest round-down; that atom is a giant (ceiling + 1) and the walk
 *      materializes it as its own scope (Vinh Long: Mang Thit 9.40 -> 10).
 *   D. ZERO POPULATION. A composite with head 0 over a root and children
 *      that hold nobody files no district.
 *
 * Live-pg posture (PostGIS adjacency + real Step 12 inserts): per-test
 * transaction, rolled back; never RefreshDatabase.
 */
class PoolLandingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_pool_landing';

    /**
     * (a) Erbil in miniature: two giants lock 10 + 10 of a 30 head and the
     * pool owes 10 over Koysinjaq 7.72 + Al-Zibar 1.51 (scope frame; 8.36 +
     * 1.64 in the pool frame). The exact landing is 8 + 2 with the 2 a
     * floor exception. Before the ruling the pool filed one 9-seat district.
     */
    public function test_two_atom_pool_lands_eight_plus_two_with_a_floor_exception(): void
    {
        $this->onLivePg(function () {
            [$leg, $scopeId, $ids] = $this->makeGiantPoolFixture('zzpa', [104_000, 103_700, 77_200, 15_100], 30);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 30, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, (int) $result['districts_created'], 'the pool splits into its two atoms');

            $rows = $this->districtsAt($leg->id, $scopeId);
            $this->assertSame([8, 2], $rows->pluck('seats')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(0, (int) $rows->sum('bonus_seats'), 'a landed 2 needs no lift');
            $this->assertSame(10, (int) $rows->sum(fn ($r) => $r->seats - $r->bonus_seats), 'the pool seats exactly what it owes');
            $this->assertFalse((bool) $rows[0]->floor_override, 'the 8 holds the band');
            $this->assertTrue((bool) $rows[1]->floor_override, 'the 2 is a recorded floor exception');
            $this->assertSame(1, $this->memberCount($rows[1]->id), 'the exception bin is one indivisible atom');
        });
    }

    /**
     * (b) Milunga in miniature: the pool owes 10 over Massau 5.08 + Macolo
     * 4.07 (5.55 + 4.45 in the pool frame). Nearest lands 6 + 4 with an
     * exception; the in-band 5 + 5 lands the same budget in the same
     * worst-district band and wins. No exception, no bonus.
     */
    public function test_two_atom_pool_lands_five_plus_five_in_band(): void
    {
        $this->onLivePg(function () {
            [$leg, $scopeId] = $this->makeGiantPoolFixture('zzpb', [104_200, 104_300, 50_800, 40_700], 30);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 30, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, (int) $result['districts_created']);

            $rows = $this->districtsAt($leg->id, $scopeId);
            $this->assertSame([5, 5], $rows->pluck('seats')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(0, (int) $rows->sum('bonus_seats'));
            foreach ($rows as $r) {
                $this->assertFalse((bool) $r->floor_override, 'both districts hold the floor: no exception');
            }
        });
    }

    /**
     * (c) Cagayan Valley in miniature: the pool owes 10 over Quirino 8.68 +
     * Batanes 0.80 (9.16 + 0.84 in the pool frame). Exact: 9 + 1; the 1 is a
     * forced sub-2 landing and lifts to exactly 2 by one bonus seat. The
     * lawful landing (seats - bonus) still sums 10.
     */
    public function test_two_atom_pool_lands_nine_plus_one_lifted_to_two(): void
    {
        $this->onLivePg(function () {
            [$leg, $scopeId] = $this->makeGiantPoolFixture('zzpc', [102_600, 102_600, 86_800, 8_000], 30);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $scopeId, false, 30, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(2, (int) $result['districts_created']);

            $rows = $this->districtsAt($leg->id, $scopeId);
            $this->assertSame([9, 2], $rows->pluck('seats')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(0, (int) $rows[0]->bonus_seats);
            $this->assertSame(1, (int) $rows[1]->bonus_seats, 'one bonus seat lifts the landed 1 to 2');
            $this->assertTrue((bool) $rows[1]->floor_override);
            $this->assertSame(10, (int) $rows->sum(fn ($r) => $r->seats - $r->bonus_seats), 'net of bonus the pool seats what it owes');
            $this->assertSame(11, (int) $rows->sum('seats'), 'the chamber grows by exactly the bonus');
        });
    }

    /**
     * (d) Vinh Long in miniature: a 28-seat scope over three atoms of 9.39 /
     * 9.34 / 9.26 (capacity 3 x 9 = 27). The spare seat goes to the highest
     * round-down atom, which becomes a 10-seat giant in the cascade and a
     * scope of its own in the walk. The residual pool of two lands 9 + 9.
     */
    public function test_pool_over_capacity_promotes_the_highest_round_down_atom_to_a_giant(): void
    {
        $this->onLivePg(function () {
            $pops    = [94_000, 93_500, 92_700];
            $rootId  = $this->makeJurisdiction('zzpd-0-root', 'Cap Root', 0, null, $this->square(0, 0, 3, 3), array_sum($pops));
            $scopeId = $this->makeJurisdiction('zzpd-1-scope', 'Cap Scope', 1, $rootId, $this->square(0, 0, 3, 1), array_sum($pops));
            $atoms = [];
            foreach ($pops as $i => $p) {
                $atoms[] = $this->makeJurisdiction("zzpd-2-atom-{$i}", "Atom {$i}", 2, $scopeId, $this->square($i, 0, $i + 1, 1), $p);
            }
            $leg = $this->makeLegislature($rootId, 28);

            $svc = app(DistrictingService::class);
            $giants = $svc->giantChildrenForScope($scopeId, $leg->id);
            $this->assertSame([$atoms[0] => 10], $giants,
                'the 9.39 atom (round-down 0.39, the highest) takes the spare seat and locks at ceiling + 1');
            $this->assertSame(10, $svc->computeSeatBudget($atoms[0], $leg->id), 'the cascade answers the promoted atom from its lock');

            // The walk materializes the promoted atom as its own scope with
            // the promoted budget (the cascade/materialization input).
            $computed = AutoscaleEnumeration::computeApportionment($leg->id, $rootId, 28, $svc);
            $this->assertNull($computed['gate_reason']);
            $byScope = [];
            foreach ($computed['steps'] as [$jid, $depth, $parentJid, $budget]) {
                $byScope[$jid] = [$depth, $parentJid, $budget];
            }
            $this->assertArrayHasKey($atoms[0], $byScope, 'the promoted atom is a scope in the walk');
            $this->assertSame([2, $scopeId, 10], $byScope[$atoms[0]]);
            $this->assertSame(28, $byScope[$scopeId][2]);
            $this->assertArrayNotHasKey($atoms[1], $byScope);
            $this->assertArrayNotHasKey($atoms[2], $byScope);

            // The composite at the scope excludes the promoted giant and
            // lands the residual pool of two exactly: 18 = 9 + 9.
            $result = $svc->runAutoCompositeForScope($leg->id, $leg, $scopeId, false, 28, null);
            $this->assertNull($result['error']);
            $rows = $this->districtsAt($leg->id, $scopeId);
            $this->assertSame([9, 9], $rows->pluck('seats')->map(fn ($v) => (int) $v)->all());
            foreach ($rows as $r) {
                $this->assertNotContains($atoms[0], $this->memberIds($r->id), 'the promoted giant holds no composite district');
            }
        });
    }

    /**
     * (e) Hatohobei in miniature: head 0, own population 0, every child 0.
     * The composite files nothing and answers with the singles path's
     * reason. Before the ruling it minted one 1-seat district.
     */
    public function test_zero_population_composite_files_no_district(): void
    {
        $this->onLivePg(function () {
            $rootId = $this->makeJurisdiction('zzpe-0-root', 'Empty Root', 0, null, $this->square(0, 0, 2, 1), 0);
            $this->makeJurisdiction('zzpe-1-a', 'Empty A', 1, $rootId, $this->square(0, 0, 1, 1), 0);
            $this->makeJurisdiction('zzpe-1-b', 'Empty B', 1, $rootId, $this->square(1, 0, 2, 1), 0);
            $leg = $this->makeLegislature($rootId, 0);

            $result = app(DistrictingService::class)->runAutoCompositeForScope(
                $leg->id, $leg, $rootId, false, 0, null
            );
            $this->assertNull($result['error']);
            $this->assertSame(0, (int) $result['districts_created']);
            $this->assertSame(DistrictingService::ZERO_POPULATION_REASON, $result['reason'] ?? null);
            $this->assertSame(0, DB::table('legislature_districts')
                ->where('legislature_id', $leg->id)->whereNull('deleted_at')->count(),
                'zero population seats nobody: zero districts');
        });
    }

    // ─── fixtures ────────────────────────────────────────────────────────────

    /**
     * Root -> scope -> a row of unit squares: the first two children are
     * giants (their fracs pass 9.5 of a 30 head and round DOWN), the rest
     * form the pool. Returns [$legRow, $scopeId, $childIds].
     */
    private function makeGiantPoolFixture(string $prefix, array $pops, int $seats): array
    {
        $n       = count($pops);
        $total   = array_sum($pops);
        $rootId  = $this->makeJurisdiction("{$prefix}-0-root", 'Pool Root', 0, null, $this->square(0, 0, $n, 3), $total);
        $scopeId = $this->makeJurisdiction("{$prefix}-1-scope", 'Pool Scope', 1, $rootId, $this->square(0, 0, $n, 1), $total);
        $ids = [];
        foreach ($pops as $i => $p) {
            $ids[] = $this->makeJurisdiction(
                "{$prefix}-2-child-{$i}",
                "Child {$i}",
                2,
                $scopeId,
                $this->square($i, 0, $i + 1, 1),
                $p
            );
        }

        return [$this->makeLegislature($rootId, $seats), $scopeId, $ids];
    }

    private function districtsAt(string $legislatureId, string $scopeId)
    {
        return DB::table('legislature_districts')
            ->where('legislature_id', $legislatureId)
            ->where('jurisdiction_id', $scopeId)
            ->whereNull('deleted_at')
            ->orderByDesc('seats')
            ->orderBy('district_number')
            ->get(['id', 'seats', 'bonus_seats', 'floor_override', 'fractional_seats', 'actual_population']);
    }

    private function memberCount(string $districtId): int
    {
        return (int) DB::table('legislature_district_jurisdictions')->where('district_id', $districtId)->count();
    }

    private function memberIds(string $districtId): array
    {
        return DB::table('legislature_district_jurisdictions')->where('district_id', $districtId)
            ->pluck('jurisdiction_id')->map(fn ($v) => (string) $v)->all();
    }

    private function makeLegislature(string $jurisdictionId, int $seats): object
    {
        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id'              => $legId,
            'jurisdiction_id' => $jurisdictionId,
            'term_number'     => 1,
            'status'          => 'active',
            'total_seats'     => $seats,
            'type_a_seats'    => $seats,
            'type_b_seats'    => 0,
            'quorum_required' => intdiv($seats, 2) + 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('legislatures')->where('id', $legId)->first();
    }

    /** Insert one live jurisdictions row with PostGIS geometry; returns its id. */
    private function makeJurisdiction(
        string $slug,
        string $name,
        int $admLevel,
        ?string $parentId,
        string $wkt,
        int $population
    ): string {
        $id = (string) Str::uuid();
        DB::statement("
            INSERT INTO jurisdictions (
                id, name, slug, iso_code, adm_level, parent_id, population,
                source, parent_assigned_via, geom, centroid, created_at, updated_at
            ) VALUES (
                ?, ?, ?, 'ZZP', ?, ?, ?,
                'geoboundaries', ?, ST_GeomFromText(?, 4326),
                ST_Centroid(ST_GeomFromText(?, 4326)), NOW(), NOW()
            )
        ", [$id, $name, $slug, $admLevel, $parentId, $population, $parentId ? 'direct' : null, $wkt, $wkt]);

        return $id;
    }

    /** Axis-aligned MULTIPOLYGON rectangle (lon/lat degrees). */
    private function square(float $x0, float $y0, float $x1, float $y1): string
    {
        return sprintf(
            'MULTIPOLYGON(((%1$s %2$s, %3$s %2$s, %3$s %4$s, %1$s %4$s, %1$s %2$s)))',
            $x0, $y0, $x1, $y1
        );
    }

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
