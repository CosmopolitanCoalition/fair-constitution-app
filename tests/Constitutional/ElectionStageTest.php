<?php

namespace Tests\Constitutional;

use App\Models\Candidacy;
use App\Models\Legislature;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\ElectionStage;
use App\Services\Demo\Stages\IdentityStage;
use App\Services\ElectionLifecycleService;
use App\Services\Legislature\TypeBDistrictMapper;
use App\Services\TabulationRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the ELECTIONS stage.
 *
 * THE ENGINE IS DRIVEN, NEVER COPIED. The election, its races and the seat
 * arithmetic come from `ElectionLifecycleService::scheduleGeneral()` — the same
 * method CLK-01 calls on a live instance. A demo whose elections were shaped by
 * a parallel implementation would be a drawing of a government; one that calls
 * the real lifecycle is the same machine with synthetic input.
 *
 * THE INVARIANTS:
 *   · races come from the ACTIVE district map, via the real race plan
 *   · exactly Σ(seats + 1) candidates — a race must be a contest, and one more
 *     than the seats is the minimum that makes it one
 *   · candidates are RESIDENTS — `RaceFootprint` gates candidacy on an active
 *     residency association, so the local roster is the only lawful source
 *   · one candidacy per person per election (a DB unique constraint), which is
 *     WHY the identity roster is sized Σ(seats + 1) in the first place
 *   · candidacies are in a COUNTABLE status, or the count would see an empty field
 *   · a fully-blocked chamber elects NOBODY and settles honestly — the world is
 *     allowed to contain places whose government is still forming
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ElectionStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_electionstage';

    /** The headline: real races off the real map, contested by exactly enough people. */
    public function test_it_calls_a_real_election_with_exactly_enough_candidates(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 5, districtSeats: [7, 7]);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNotNull($result['election_id']);

            // 2 district races + 1 lawful at-large type_b race.
            $this->assertSame(3, $result['races']);

            // Σ(seats + 1): (7+1) + (7+1) + (5+1) = 22.
            $this->assertSame(22, $result['candidacies']);

            $rows = DB::table('election_races')->where('election_id', $result['election_id'])->get();
            $this->assertSame(
                22,
                $rows->sum(fn ($r) => (int) $r->seats + 1),
                'the candidate count must equal the races the engine actually generated'
            );
        });
    }

    /** Every candidate is a resident — the only lawful source. */
    public function test_candidates_are_residents_of_the_jurisdiction(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 0, districtSeats: [7, 7]);
            $result = ElectionStage::run($jid, null, 1);

            $candidateIds = DB::table('candidacies')
                ->where('election_id', $result['election_id'])
                ->pluck('user_id')
                ->all();

            $residentIds = DB::table('residency_confirmations')
                ->where('jurisdiction_id', $jid)
                ->where('is_active', true)
                ->pluck('user_id')
                ->all();

            $this->assertNotEmpty($candidateIds);
            $this->assertEmpty(
                array_diff($candidateIds, $residentIds),
                'a candidate who is not a resident would be refused by RaceFootprint'
            );
        });
    }

    /** One candidacy per person per election — a DB constraint, and the reason for the roster size. */
    public function test_no_person_contests_an_election_twice(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 5, districtSeats: [7, 7]);
            $result = ElectionStage::run($jid, null, 1);

            $ids = DB::table('candidacies')
                ->where('election_id', $result['election_id'])
                ->pluck('user_id')
                ->all();

            $this->assertSame(
                count($ids),
                count(array_unique($ids)),
                'the roster must be CONSUMED across races, never reused'
            );
        });
    }

    /** The count must be able to see them, or the election is a formality. */
    public function test_candidacies_are_countable(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 0, districtSeats: [7, 7]);
            $result = ElectionStage::run($jid, null, 1);

            $statuses = DB::table('candidacies')
                ->where('election_id', $result['election_id'])
                ->pluck('status')
                ->unique()
                ->all();

            foreach ($statuses as $status) {
                $this->assertContains(
                    $status,
                    TabulationRecorder::COUNTABLE_CANDIDACY_STATUSES,
                    'a candidacy the counting engine cannot see is not a candidacy'
                );
            }

            $this->assertSame([Candidacy::STATUS_VALIDATED], $statuses);
        });
    }

    /**
     * A chamber with no lawful race elects NOBODY — and that is a DONE outcome,
     * not a failure. The world may contain places whose government is forming.
     *
     * The fixture is an over-ceiling type_a with NO district map and NO type_b
     * half. Note what is NOT blocking here: a large type_b is lawful since the
     * 2026-07-26 ruling (one at-large STV race at any size), so it can no longer
     * be used to construct a dead plan.
     */
    public function test_a_fully_blocked_chamber_elects_nobody_and_does_not_fail(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 152, typeB: 0, districtSeats: []);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNull($result['election_id'], 'nothing lawful can be scheduled here');
            $this->assertSame(0, $result['races']);
            $this->assertSame(0, $result['candidacies']);
            $this->assertNotEmpty($result['blocked_kinds'], 'and the reason must be reported, not swallowed');
        });
    }

    /**
     * THE 2026-07-26 TYPE B RULING, pinned from the engine's side.
     *
     * The 5–9 band is a DISTRICT rule. It does NOT bind an at-large Type B
     * race, which is ONE STV race at whatever size — the schema has always said
     * so (`election_races_seats_check` bounds type_a to 1..9 and type_b only to
     * >= 1). So a 1,141-seat upper chamber is lawful and elects alongside the
     * district races rather than blocking anything.
     *
     * CLOSED 2026-07-26 (`5fbdb9d`): the counting core no longer carries the
     * 5–9 band at all. It is pure and cannot tell a district race from an
     * at-large one, so the band is enforced where districts ARE known —
     * `racePlan()`, `election_races_seats_check`, and `DistrictingService`. A
     * `type_a` race above nine remains impossible. San Marino's 27-seat
     * at-large chamber is counted, certified and seated.
     */
    public function test_a_large_type_b_is_one_lawful_at_large_race(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 1141, districtSeats: [7, 7]);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNotNull($result['election_id']);
            $this->assertSame(
                3,
                $result['races'],
                'two district races PLUS one at-large type_b race — not blocked'
            );
            $this->assertSame(
                [],
                $result['blocked_kinds'],
                'a large type_b is lawful; nothing here should be blocked'
            );

            $atLarge = DB::table('election_races')
                ->where('election_id', $result['election_id'])
                ->where('seat_kind', 'type_b')
                ->first();

            $this->assertNotNull($atLarge, 'the upper chamber gets its race');
            $this->assertSame(1141, (int) $atLarge->seats, 'at its full size, undivided');
            $this->assertNull($atLarge->district_id, 'at-large: the jurisdiction IS the district');
        });
    }

    /**
     * R-A DEFERRAL, pinned from the sim engine's side (ruling 2026-07-28, plan §10).
     *
     * R-A: NO Type B election playtesting on chambers flagged
     * `type_b_needs_districting` until lane 1's Type B district mapper ships;
     * lane 3 builds the scheduling guard IN `racePlan()`. This stage is not the
     * guard and must never become one — it must DEFER to whatever racePlan
     * authorises, PER KIND, so that when lane 3 makes racePlan refuse the Type B
     * half of a flagged chamber, this stage schedules the lawful half and records
     * the refused half, with no edit here at all.
     *
     * The mechanism is pinned with the block that CAN be triggered today: an
     * over-ceiling `type_a` (152) with NO active map is a blocked district half,
     * while a lawful at-large `type_b` (5) elects. The stage must schedule ONLY
     * the type_b race and record the district half in `blocked_kinds` — exactly
     * the shape R-A needs, with the blocked side merely swapped. If a future edit
     * hand-rolled races here instead of reading racePlan, this partial deferral
     * would break and the guard would be silently bypassed.
     */
    public function test_a_partially_blocked_chamber_elects_only_its_lawful_half(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 152, typeB: 5, districtSeats: []); // no district map

            $result = ElectionStage::run($jid, null, 1);

            // The lawful half elected; the whole chamber was NOT dropped.
            $this->assertNotNull($result['election_id'], 'the lawful type_b half must still elect');
            $this->assertNotEmpty(
                $result['blocked_kinds'],
                'the blocked district half must be RECORDED, never silently dropped'
            );

            // Nothing was scheduled for the blocked half — every race is the type_b one.
            $rows = DB::table('election_races')->where('election_id', $result['election_id'])->get();
            $this->assertSame(1, $rows->count(), 'exactly the one lawful race the engine authorised');
            $this->assertTrue(
                $rows->every(fn ($r) => $r->seat_kind === 'type_b'),
                'the stage schedules only what racePlan authorised — never a race for the blocked kind'
            );
        });
    }

    /**
     * THE UN-FLAG PICKUP — the other direction of the R-A deferral (lane 4,
     * Wave 3, 2026-07-29; the companion to the blocked-direction pin above).
     *
     * `test_a_partially_blocked_chamber…` proves the sim stage DEFERS when
     * racePlan blocks the Type B half. This proves it defers the OTHER way: the
     * instant lane 1's Type B mapper clears `type_b_needs_districting`, the guard
     * un-blocks and ElectionStage SCHEDULES the newly-lawful at-large Type B race
     * — with NO edit here. The stage reads racePlan and inherits the un-flag for
     * free, exactly as it inherits the block. `TypeBDistrictMapperApplyTest` pins
     * the mapper→racePlan flip at the plan level; THIS pins the demo populate
     * pipeline's side of it.
     *
     * Pinned against the TRUE un-flag path — the real `TypeBDistrictMapper::apply()`,
     * never a synthetic flag flip. A synthetic flip would only clear the column;
     * the real apply also groups the constituents into panels and recomputes
     * `type_b_seats` to `panel_count × rep_floor`, which is the seat count the
     * scheduled race must carry. Same Niue shape lane 1 cleared first: type_a 5
     * (≤ ceiling → at-large, no map needed), 8 inhabited children grouped to
     * 2 panels × 2 = 4 Type B seats ≤ the Type A total.
     */
    public function test_the_sim_schedules_the_type_b_race_the_instant_the_mapper_unflags_it(): void
    {
        $this->onLivePg(function () {
            $tag = 'unflag-'.Str::lower(Str::random(6));

            // The parent carries the sim preconditions (a 2M cohort roster + a
            // bootstrap board); its 8 inhabited children + line adjacency are what
            // the mapper groups. Type A 5 is at-large (no map). Type B is flagged:
            // 8 × 2-per-constituent = 16 > 5, so the ladder set needs_districting.
            $parentId = $this->jurisdiction(2_000_000);

            $childIds = [];
            for ($i = 0; $i < 8; $i++) {
                $cid = (string) Str::uuid();
                $childIds[$i] = $cid;
                DB::table('jurisdictions')->insert([
                    'id' => $cid, 'name' => "{$tag}-c{$i}", 'slug' => "{$tag}-c{$i}",
                    'parent_id' => $parentId, 'adm_level' => 4, 'population' => 10,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Line adjacency c0-c1-…-c7 so the grouping is compact + deterministic.
            for ($i = 0; $i < 7; $i++) {
                DB::table('jurisdiction_adjacency')->insert([
                    'parent_id' => $parentId, 'j1' => $childIds[$i], 'j2' => $childIds[$i + 1],
                    'dim' => 1, 'border_len' => 1.0, 'computed_at' => now(),
                ]);
            }

            $legId = (string) Str::uuid();
            DB::table('legislatures')->insert([
                'id' => $legId, 'jurisdiction_id' => $parentId, 'term_number' => 1,
                'status' => 'forming', 'total_seats' => 21, 'type_a_seats' => 5,
                'type_b_seats' => 16, 'type_b_rep_floor' => 2, 'type_b_needs_districting' => true,
                'quorum_required' => 11, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->electionBoard($parentId);
            CohortStage::run($parentId, null, 1, 62);
            IdentityStage::run($parentId, null, 1);

            // PRECONDITION: while flagged, the R-A guard blocks the Type B half.
            $before = app(ElectionLifecycleService::class)->racePlan(Legislature::find($legId));
            $this->assertSame('blocked', $before['kinds']['type_b']['mode'] ?? null,
                'precondition: the flagged Type B half is blocked by the R-A guard');

            // THE TRUE UN-FLAG PATH: lane 1's real mapper groups + clears the flag.
            $applied = (new TypeBDistrictMapper())->apply($legId);
            $this->assertNotNull($applied, 'the mapper groups this chamber');
            $groupedSeats = (int) DB::table('legislatures')->where('id', $legId)->value('type_b_seats');
            $this->assertSame($applied['seats'], $groupedSeats, 'type_b recomputed to panel_count × rep_floor');
            $this->assertFalse((bool) DB::table('legislatures')->where('id', $legId)->value('type_b_needs_districting'),
                'the flag is cleared — the guard must now un-block');

            // AFTER: the SIM STAGE schedules the newly-unflagged Type B race, no edit.
            $result = ElectionStage::run($parentId, null, 1);

            $this->assertNotNull($result['election_id'], 'the un-flagged chamber now elects');
            $this->assertNotContains('type_b', $result['blocked_kinds'] ?? [],
                'the Type B half is no longer blocked once the flag clears');

            $typeBRaces = DB::table('election_races')
                ->where('election_id', $result['election_id'])
                ->where('seat_kind', 'type_b')
                ->get();

            // The mapper unlocked the Type B half in the CORRECT shape: one
            // at-large race PER CLUMP (operator ruling 2026-07-29), NOT one pooled
            // race. The sim stage still just defers to racePlan — no edit here.
            $this->assertSame($applied['panel_count'], $typeBRaces->count(),
                'the sim scheduled one at-large race per clump the mapper unlocked — the stage deferred, no edit here');
            $this->assertSame($groupedSeats, (int) $typeBRaces->sum('seats'),
                'Σ per-clump seats = the grouped total (panel_count × rep_floor)');
            $this->assertSame(0, $typeBRaces->whereNull('type_b_panel_id')->count(),
                'each per-clump race carries its panel key');
            $this->assertSame(0, $typeBRaces->where('district_id', '!=', null)->count(),
                'at-large: each clump votes at-large for its own seats');
        });
    }

    /** Most jurisdictions have no chamber. That is ordinary, not an error. */
    public function test_a_jurisdiction_without_a_legislature_is_a_no_op(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(50_000);
            CohortStage::run($jid, null, 1, 62);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNull($result['election_id']);
            $this->assertSame(0, $result['races']);
            $this->assertSame(0, $result['candidacies']);
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** A jurisdiction with a cohort, a roster and a chamber — the stage's precondition. */
    /**
     * A chamber with no active election board cannot be seated, so the stage
     * must not call an election for it — see the guard in ElectionStage. A place
     * that is sized but not yet ACTIVATED is exactly that case.
     */
    public function test_an_unactivated_jurisdiction_gets_no_election(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(2_000_000);
            $this->legislature($jid, 9, 0, [9]);
            CohortStage::run($jid, null, 1, 62);
            IdentityStage::run($jid, null, 1);

            // Deliberately NO election board — the activation pipeline mints it.
            $result = ElectionStage::run($jid, null, 1);

            $this->assertNull($result['election_id'], 'no board means no lawful election to call');
            $this->assertSame(0, $result['races']);
            $this->assertSame(0, $result['candidacies']);
            $this->assertContains('no_election_board', $result['blocked_kinds']);

            $this->assertSame(
                0,
                DB::table('elections')->where('jurisdiction_id', $jid)->count(),
                'an orphan election left open here acquires a SECOND one at activation, and both later certify'
            );
        });
    }

    private function world(int $typeA, int $typeB, array $districtSeats): string
    {
        $jid = $this->jurisdiction(2_000_000);
        $this->legislature($jid, $typeA, $typeB, $districtSeats);
        $this->electionBoard($jid);
        CohortStage::run($jid, null, 1, 62);
        IdentityStage::run($jid, null, 1);

        return $jid;
    }

    /**
     * The bootstrap board. ActivationService constitutes one when a jurisdiction
     * activates for real; every other fixture here presumes an activated place.
     */
    private function electionBoard(string $jid): void
    {
        DB::table('election_boards')->insert([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jid,
            'is_bootstrap' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function jurisdiction(int $population): string
    {
        $id = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $id,
            'name' => 'Election Pin',
            'slug' => 'election-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 3,
            'population' => $population,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function legislature(string $jid, int $typeA, int $typeB, array $districtSeats): void
    {
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

        if ($districtSeats === []) {
            return;
        }

        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id' => $mapId,
            'legislature_id' => $id,
            'name' => 'Pin Map',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($districtSeats as $i => $seats) {
            DB::table('legislature_districts')->insert([
                'id' => (string) Str::uuid(),
                'legislature_id' => $id,
                'map_id' => $mapId,
                'jurisdiction_id' => $jid,
                'district_number' => $i + 1,
                'seats' => $seats,
                'target_population' => 1_000_000,
                'actual_population' => 1_000_000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
