<?php

namespace Tests\Constitutional;

use App\Models\Legislature;
use App\Models\LegislatureDistrict;
use App\Models\LegislatureDistrictMap;
use App\Services\ElectionLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — PER-KIND RACE BLOCKING (operator ruling, 2026-07-25).
 *
 * A chamber with one unlawful seat-kind must NOT lose its perfectly lawful
 * other kind with it. The operator's framing: *"maps that
 * don't have problems, we proceed. Maps that do have problems, we will not
 * proceed, and we keep them flagged."*
 *
 * WHAT THIS COST BEFORE. `racePlan()` carried a single run-level `$blocked`
 * flag, and `scheduleGeneral()` returned before `createRaces()` whenever it was
 * set, so ONE unlawful kind condemned every lawful sibling. Measured on the
 * planet at the time: 218,320 districts / 1,568,448 type_a seats lost that way.
 *
 * ⚠ HISTORICAL NOTE — the ORIGINAL trigger has since been removed. Back then
 * the blocked kind was type_b, because a bicameral chamber's at-large half was
 * held to the 5–9 district band. The 2026-07-26 ruling settled that the band is
 * a DISTRICT rule and does not bind an at-large Type B race, which is one STV
 * race at whatever size — so a large type_b is now lawful and no longer blocks
 * anything. These fixtures were therefore mirrored: the blockable kind is now
 * an over-ceiling type_a with no district map. The PRINCIPLE under test is
 * unchanged, and that is the point of pinning the principle rather than the
 * example.
 *
 * THE INVARIANTS:
 *   · a lawful kind generates its races even when a sibling kind is blocked
 *   · the blocked kind still generates NOTHING — this is not a licence to
 *     elect an unlawful race
 *   · the blocked posture is still RECORDED (the operator's "keep them flagged")
 *   · when NOTHING is lawful, the whole plan still refuses
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class PerKindRacePlanTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_perkind';

    /**
     * THE HEADLINE: a lawful lower house survives an unlawful upper house.
     * This is the case that covers 218,320 real districts.
     */
    public function test_a_blocked_kind_no_longer_condemns_a_lawful_one(): void
    {
        $this->onLivePg(function () {
            // The blockable kind is now type_a: over the per-race maximum with
            // NO district map, so subdivision is mandatory before it can elect.
            // The type_b half is lawful — it elects PER CHILD (9 children × rep 5
            // = 45) since the 2026-07-29 ruling.
            $legislature = $this->legislature(typeA: 152, typeB: 45, childCount: 9, repFloor: 5);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertTrue($plan['blocked'], 'the type_a half genuinely cannot elect and must be flagged');
            $this->assertFalse($plan['fully_blocked'], 'but the type_b half is lawful, so the plan is not dead');

            $this->assertSame('blocked', $plan['kinds']['type_a']['mode'], 'the lower house cannot proceed');
            $this->assertSame('children', $plan['kinds']['type_b']['mode'], 'the upper house can — one race per child');
            $childRaces = $plan['kinds']['type_b']['children'];
            $this->assertCount(9, $childRaces, 'one race per direct child');
            $this->assertSame(45, array_sum(array_map(fn ($c) => (int) $c->seats, $childRaces)), 'Σ = type_b_seats, no drift');

            $this->assertSame(
                ['type_b'],
                $plan['generable_kinds'],
                'exactly the lawful kinds are generable'
            );
        });
    }

    /** A blocked kind generates nothing — proceeding is not a licence to cheat. */
    public function test_a_blocked_kind_still_generates_no_races(): void
    {
        $this->onLivePg(function () {
            $legislature = $this->legislature(typeA: 152, typeB: 45, childCount: 9, repFloor: 5);

            $service = app(ElectionLifecycleService::class);
            $election = $service->scheduleGeneral($legislature);

            $races = DB::table('election_races')->where('election_id', $election->id)->get();

            $this->assertCount(9, $races, 'only the lawful per-child races are generated (9 children)');
            $this->assertSame(
                ['type_b'],
                $races->pluck('seat_kind')->unique()->values()->all(),
                'and NOT ONE type_a race is generated for the half that cannot elect'
            );
            $this->assertSame(45, (int) $races->sum('seats'), 'Σ = type_b_seats');
            $this->assertSame([5], $races->pluck('seats')->map(fn ($s) => (int) $s)->unique()->values()->all(), 'each child seats rep_floor');
            $this->assertSame(9, $races->whereNull('type_b_panel_id')->count(), 'per-child races carry no panel key');
        });
    }

    /** "…and we keep them flagged." The posture is recorded, not swallowed. */
    public function test_the_blocked_posture_is_still_recorded(): void
    {
        $this->onLivePg(function () {
            $legislature = $this->legislature(typeA: 152, typeB: 45, childCount: 9, repFloor: 5);

            $before = DB::table('audit_log')
                ->where('event', 'election.blocked_pending_subdivision')->count();

            app(ElectionLifecycleService::class)->scheduleGeneral($legislature);

            $this->assertSame(
                $before + 1,
                DB::table('audit_log')->where('event', 'election.blocked_pending_subdivision')->count(),
                'proceeding on the lawful half must NOT silence the flag on the unlawful half'
            );
        });
    }

    /** When nothing is lawful, the plan is still dead. */
    public function test_a_fully_blocked_plan_still_generates_nothing(): void
    {
        $this->onLivePg(function () {
            // Over-ceiling type_a with NO district map, and NO type_b half —
            // a large type_b is lawful since the 2026-07-26 ruling, so it can
            // no longer be used to construct a dead plan.
            $legislature = $this->legislature(typeA: 152, typeB: 0);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertTrue($plan['blocked']);
            $this->assertTrue($plan['fully_blocked'], 'nothing here is lawful');
            $this->assertSame([], $plan['generable_kinds']);

            $election = app(ElectionLifecycleService::class)->scheduleGeneral($legislature);

            $this->assertSame(
                0,
                DB::table('election_races')->where('election_id', $election->id)->count(),
                'a fully blocked chamber must still seat no one'
            );
        });
    }

    /** An ordinary healthy chamber is completely unaffected. */
    public function test_a_healthy_chamber_is_unchanged(): void
    {
        $this->onLivePg(function () {
            $legislature = $this->legislature(typeA: 14, typeB: 5, childCount: 1, repFloor: 5);
            $this->districtMap($legislature, [7, 7]);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertFalse($plan['blocked'], 'nothing is wrong here');
            $this->assertFalse($plan['fully_blocked']);
            $this->assertSame('districts', $plan['kinds']['type_a']['mode']);
            $this->assertSame('children', $plan['kinds']['type_b']['mode']);
            $this->assertCount(1, $plan['kinds']['type_b']['children'], 'one race for the one direct child');
            $this->assertSame(5, (int) $plan['kinds']['type_b']['children'][0]->seats, 'the child seats rep_floor');
        });
    }

    /**
     * R-A GUARD (operator ruling 2026-07-28, V3_SYNTHESIS_PLAN §10) — a chamber
     * flagged `type_b_needs_districting` refuses to schedule its Type B race
     * until lane 1's Type B district mapper clears the flag: *"We will not
     * playtest Type B elections until this is fixed."*
     *
     * The block is PER-KIND — the lower house still elects — and UNCONDITIONAL
     * ON WORLD CLASS: racePlan reads no instance_class and keys only on the
     * persisted flag, so demo, sandbox, scale_demo and standard instances refuse
     * alike. It guards SCHEDULING, never sitting members.
     *
     * THE HEADLINE: a flagged upper house is blocked; the districted lower house
     * elects normally beside it. This is the state of 9,708 real chambers.
     */
    public function test_R_A_a_flagged_type_b_half_is_blocked_while_the_lower_house_elects(): void
    {
        $this->onLivePg(function () {
            // Real flagged shape: type_b (20) exceeds type_a (14), and the lower
            // house carries a lawful active district map.
            $legislature = $this->legislature(typeA: 14, typeB: 20, needsDistricting: true);
            $this->districtMap($legislature, [7, 7]);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertTrue($plan['blocked'], 'the flagged Type B half genuinely cannot elect');
            $this->assertFalse($plan['fully_blocked'], 'but the districted lower house is lawful, so the plan is not dead');

            $this->assertSame('blocked', $plan['kinds']['type_b']['mode'], 'the upper house is blocked pending Type B districting');
            $this->assertSame('Art. V §3', $plan['kinds']['type_b']['citation']);
            $this->assertStringContainsString(
                'type_b_needs_districting',
                $plan['kinds']['type_b']['reason'],
                'the reason must name the flag, so the block is legible on the blocked-posture record'
            );

            $this->assertSame('districts', $plan['kinds']['type_a']['mode'], 'the lower house is untouched');
            $this->assertSame(['type_a'], $plan['generable_kinds'], 'exactly the lawful kinds are generable');
        });
    }

    /** No race is materialised for the blocked Type B half — a block is not a licence to cheat. */
    public function test_R_A_no_type_b_race_is_generated_for_a_flagged_chamber(): void
    {
        $this->onLivePg(function () {
            $legislature = $this->legislature(typeA: 14, typeB: 20, needsDistricting: true);
            $this->districtMap($legislature, [7, 7]);

            $election = app(ElectionLifecycleService::class)->scheduleGeneral($legislature);
            $races = DB::table('election_races')->where('election_id', $election->id)->get();

            $this->assertSame(
                ['type_a', 'type_a'],
                $races->pluck('seat_kind')->all(),
                'only the two district races are generated — NOT ONE type_b race for the flagged half'
            );
        });
    }

    /**
     * THE BOUNDARY (per the 2026-07-29 ruling): an UNFLAGGED Type B chamber
     * elects PER CHILD — one at-large race per direct constituent, each seating
     * its own rep_floor — NOT one pooled at-large race (which let population
     * dominate the equal representation Type B exists to deliver). The guard keys
     * on the FLAG, never on the raw comparison type_b > type_a, so an unflagged
     * chamber the ladder has not condemned elects normally, per child.
     * (SUPERSEDES the pre-2026-07-29 "one lawful at-large race at any size";
     * lane 4's ElectionStageTest twin is co-updated the same way.)
     */
    public function test_R_A_an_unflagged_type_b_elects_per_child(): void
    {
        $this->onLivePg(function () {
            // 20 direct children × rep 5 = 100 Type B seats, unflagged.
            $legislature = $this->legislature(typeA: 14, typeB: 100, needsDistricting: false, childCount: 20, repFloor: 5);
            $this->districtMap($legislature, [7, 7]);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertSame('children', $plan['kinds']['type_b']['mode'], 'unflagged: one at-large race PER CHILD');
            $childRaces = $plan['kinds']['type_b']['children'];
            $this->assertCount(20, $childRaces, 'one race per direct child, however many');
            $this->assertSame(100, array_sum(array_map(fn ($c) => (int) $c->seats, $childRaces)), 'Σ = type_b_seats, no drift');
            $this->assertContains('type_b', $plan['generable_kinds']);
        });
    }

    /**
     * A flagged Type B half AND an unlawful lower house (over-ceiling, no map)
     * is FULLY blocked — the chamber elects nobody and settles honestly, the
     * same honest-absence posture the fully-blocked pin above proves.
     */
    public function test_R_A_a_flagged_chamber_with_no_lawful_lower_house_is_fully_blocked(): void
    {
        $this->onLivePg(function () {
            $legislature = $this->legislature(typeA: 152, typeB: 200, needsDistricting: true);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertTrue($plan['fully_blocked'], 'both halves are blocked, so nothing is lawful');
            $this->assertSame([], $plan['generable_kinds']);
            $this->assertSame('blocked', $plan['kinds']['type_a']['mode']);
            $this->assertSame('blocked', $plan['kinds']['type_b']['mode']);
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function legislature(int $typeA, int $typeB, bool $needsDistricting = false, int $childCount = 0, int $repFloor = 5): Legislature
    {
        $jid = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $jid,
            'name' => 'Per-Kind Pin',
            'slug' => 'per-kind-pin-'.Str::lower(Str::random(8)),
            'adm_level' => 2,
            'population' => 5_000_000,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Direct children for the PER-CHILD Type B path (operator ruling
        // 2026-07-29). Each has population > TypeBSeatLadder::TINY_POP so it
        // contributes a full rep_floor; the caller sets $typeB = childCount ×
        // repFloor so racePlan's drift guard (Σ child seats == type_b_seats) is
        // satisfied. Flagged / type_b=0 cases pass childCount=0 (they block or
        // have no Type B half, so the per-child branch is never reached).
        for ($i = 0; $i < $childCount; $i++) {
            DB::table('jurisdictions')->insert([
                'id' => (string) Str::uuid(),
                'parent_id' => $jid,
                'name' => 'Per-Kind Child '.$i,
                'slug' => 'per-kind-child-'.Str::lower(Str::random(10)),
                'adm_level' => 3,
                'population' => 100_000,
                'source' => 'user_defined',
                'official_languages' => '["en"]',
                'timezone' => 'UTC',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $id = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $id,
            'jurisdiction_id' => $jid,
            'term_number' => 1,
            'status' => 'forming',
            'total_seats' => $typeA + $typeB,
            'type_a_seats' => $typeA,
            'type_b_seats' => $typeB,
            'type_b_rep_floor' => $repFloor,
            'type_b_needs_districting' => $needsDistricting,
            'quorum_required' => max(3, (int) ceil(($typeA + $typeB) / 2)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Legislature::findOrFail($id);
    }

    /** @param list<int> $seatCounts */
    private function districtMap(Legislature $legislature, array $seatCounts): LegislatureDistrictMap
    {
        $map = LegislatureDistrictMap::create([
            'legislature_id' => $legislature->id,
            'name' => 'Pin Map',
            'status' => 'active',
        ]);

        foreach ($seatCounts as $i => $seats) {
            LegislatureDistrict::create([
                'legislature_id' => $legislature->id,
                'map_id' => $map->id,
                'jurisdiction_id' => $legislature->jurisdiction_id,
                'district_number' => $i + 1,
                'seats' => $seats,
                'target_population' => 1_000_000,
                'actual_population' => 1_000_000,
            ]);
        }

        return $map;
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
