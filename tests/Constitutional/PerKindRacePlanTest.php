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
 * A chamber whose type_b half has no lawful race must NOT lose its perfectly
 * lawful type_a district races with it. The operator's framing: *"maps that
 * don't have problems, we proceed. Maps that do have problems, we will not
 * proceed, and we keep them flagged."*
 *
 * WHAT THIS COST BEFORE. `racePlan()` carried a single run-level `$blocked`
 * flag, and `scheduleGeneral()` returned before `createRaces()` whenever it was
 * set. Because `VoteCountingService` hard-throws outside 1–9 seats and a
 * bicameral chamber's type_b half elects at-large as ONE race, every chamber
 * with `type_b_seats > 9` condemned its own lower house too. Measured on the
 * planet: **218,320 districts / 1,568,448 type_a seats** lost that way, on top
 * of the 2,069,820 type_b seats that genuinely have no lawful race — 23.8% of
 * all seats, with Earth's own 1,999-seat lower house among the casualties.
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
    public function test_a_blocked_type_b_no_longer_condemns_a_lawful_type_a(): void
    {
        $this->onLivePg(function () {
            // type_b = 1,141 — Earth's actual value, far above the per-race max.
            $legislature = $this->legislature(typeA: 14, typeB: 1141);
            $this->districtMap($legislature, [7, 7]);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertTrue($plan['blocked'], 'the type_b half is genuinely unlawful and must be flagged');
            $this->assertFalse($plan['fully_blocked'], 'but the type_a half is lawful, so the plan is not dead');

            $this->assertSame('districts', $plan['kinds']['type_a']['mode'], 'the lower house proceeds');
            $this->assertSame('blocked', $plan['kinds']['type_b']['mode'], 'the upper house does not');

            $this->assertSame(
                ['type_a'],
                $plan['generable_kinds'],
                'exactly the lawful kinds are generable'
            );
        });
    }

    /** A blocked kind generates nothing — proceeding is not a licence to cheat. */
    public function test_a_blocked_kind_still_generates_no_races(): void
    {
        $this->onLivePg(function () {
            $legislature = $this->legislature(typeA: 14, typeB: 1141);
            $this->districtMap($legislature, [7, 7]);

            $service = app(ElectionLifecycleService::class);
            $election = $service->scheduleGeneral($legislature);

            $races = DB::table('election_races')->where('election_id', $election->id)->get();

            $this->assertCount(2, $races, 'the two lawful districts each get a race');
            $this->assertSame(
                ['type_a', 'type_a'],
                $races->pluck('seat_kind')->all(),
                'and NOT ONE type_b race is generated for the unlawful half'
            );
            $this->assertSame(
                [7, 7],
                $races->pluck('seats')->map(fn ($s) => (int) $s)->all()
            );
        });
    }

    /** "…and we keep them flagged." The posture is recorded, not swallowed. */
    public function test_the_blocked_posture_is_still_recorded(): void
    {
        $this->onLivePg(function () {
            $legislature = $this->legislature(typeA: 14, typeB: 1141);
            $this->districtMap($legislature, [7, 7]);

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
            // Over-ceiling type_a with NO district map, and an unlawful type_b.
            $legislature = $this->legislature(typeA: 152, typeB: 400);

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
            $legislature = $this->legislature(typeA: 14, typeB: 5);
            $this->districtMap($legislature, [7, 7]);

            $plan = app(ElectionLifecycleService::class)->racePlan($legislature);

            $this->assertFalse($plan['blocked'], 'nothing is wrong here');
            $this->assertFalse($plan['fully_blocked']);
            $this->assertSame('districts', $plan['kinds']['type_a']['mode']);
            $this->assertSame('at_large', $plan['kinds']['type_b']['mode']);
            $this->assertSame(5, $plan['kinds']['type_b']['seats']);
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function legislature(int $typeA, int $typeB): Legislature
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

        $id = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $id,
            'jurisdiction_id' => $jid,
            'term_number' => 1,
            'status' => 'forming',
            'total_seats' => $typeA + $typeB,
            'type_a_seats' => $typeA,
            'type_b_seats' => $typeB,
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
