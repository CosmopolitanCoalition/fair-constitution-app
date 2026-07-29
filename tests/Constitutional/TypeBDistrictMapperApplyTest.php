<?php

namespace Tests\Constitutional;

use App\Models\Legislature;
use App\Services\ElectionLifecycleService;
use App\Services\Legislature\TypeBDistrictMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE TYPE B MAPPER, END TO END — a flagged chamber is grouped, un-flagged, and
 * its Type B race becomes schedulable. This is the whole point of stage two: the
 * R-A guard (ElectionLifecycleService) blocks the at-large Type B race while
 * type_b_needs_districting is set; applying a grouping clears the flag and the
 * guard stops firing — line 676 emits the single at-large race with no other
 * downstream change.
 *
 * Runs against the live founded box inside a rolled-back transaction (the audit
 * genesis head stays intact). No rail weakened.
 */
class TypeBDistrictMapperApplyTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_type_b_mapper_pin';

    public function test_a_flagged_chamber_is_grouped_unflagged_and_its_type_b_race_schedules(): void
    {
        $this->onLivePg(function (): void {
            $tag = 'tbtest-' . Str::lower(Str::random(6));

            // A synthetic flagged chamber: parent + 8 inhabited children in a
            // line, Type A 5. At 2-per-constituent Type B wants 16 > 5 → flagged.
            $parentId = (string) Str::uuid();
            DB::table('jurisdictions')->insert([
                'id' => $parentId, 'name' => "{$tag}-parent", 'slug' => "{$tag}-parent",
                'adm_level' => 1, 'population' => 80, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $childIds = [];
            for ($i = 0; $i < 8; $i++) {
                $cid = (string) Str::uuid();
                $childIds[$i] = $cid;
                DB::table('jurisdictions')->insert([
                    'id' => $cid, 'name' => "{$tag}-c{$i}", 'slug' => "{$tag}-c{$i}",
                    'parent_id' => $parentId, 'adm_level' => 2, 'population' => 10,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Line adjacency c0-c1-...-c7 so grouping is deterministic + compact.
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

            // BEFORE: the R-A guard blocks the Type B race.
            $before = app(ElectionLifecycleService::class)->racePlan(Legislature::find($legId));
            $this->assertSame('blocked', $before['kinds']['type_b']['mode'] ?? null,
                'while flagged, the Type B race is blocked');

            // APPLY the grouping.
            $result = (new TypeBDistrictMapper())->apply($legId);

            $this->assertNotNull($result);
            $this->assertSame(2, $result['panel_count'], 'floor(bound 5 / rep_floor 2) = 2 panels');
            $this->assertSame(4, $result['seats'], '2 panels x 2 = 4 <= Type A 5');
            $this->assertFalse($result['undercount']);

            // Persistence: the trio landed.
            $grouping = DB::table('legislature_type_b_groupings')->where('legislature_id', $legId)->where('status', 'active')->first();
            $this->assertNotNull($grouping);
            $this->assertSame(2, (int) $grouping->panel_count);
            $this->assertSame(4, (int) $grouping->seats_total);
            $this->assertSame(TypeBDistrictMapper::TIE_BREAK_KEY, $grouping->tie_break_key);
            $this->assertNotEmpty($grouping->signature);

            $this->assertSame(2, DB::table('legislature_type_b_panels')->where('grouping_id', $grouping->id)->count());
            $this->assertSame(8, DB::table('legislature_type_b_panel_jurisdictions')->where('grouping_id', $grouping->id)->count(),
                'every inhabited constituent sits on exactly one panel');

            // The chamber is recomputed and UN-FLAGGED.
            $leg = DB::table('legislatures')->where('id', $legId)->first();
            $this->assertFalse((bool) $leg->type_b_needs_districting, 'the flag is cleared');
            $this->assertSame(4, (int) $leg->type_b_seats, 'type_b recomputed to panel_count x rep_floor');
            $this->assertSame(9, (int) $leg->total_seats, 'type_a 5 + type_b 4');
            $this->assertLessThanOrEqual((int) $leg->type_a_seats, (int) $leg->type_b_seats,
                'grouped Type B no longer exceeds Type A');

            // AFTER: the R-A guard un-blocks — ONE at-large Type B race schedules.
            $after = app(ElectionLifecycleService::class)->racePlan(Legislature::find($legId));
            $this->assertSame('at_large', $after['kinds']['type_b']['mode'] ?? null,
                'the instant the flag clears, the Type B race is at-large and schedulable');
            $this->assertSame(4, $after['kinds']['type_b']['seats'] ?? null, 'it elects the grouped seat count');
        });
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
