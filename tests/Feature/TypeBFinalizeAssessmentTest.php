<?php

namespace Tests\Feature;

use App\Services\Autoscale\SweepScopeProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * PIN — THE TYPE B ASSESSMENT AT FINALIZE (operator order 2026-09-05, "B also
 * checked just like A"): the chamber's active panel map is judged for the
 * same legalities the mapper surface flags — seat breach over the Type B
 * ceiling, unassigned constituents, uneven clumps, empty panels — plus the
 * seat identities, so an illegal hand map parks the header in review beside
 * the illegal Type A maps. A lawful machine map passes clean.
 *
 * Live PG inside a rolled-back transaction; the fixture is a parent with six
 * children of 100 people, Type A 11 (ceiling min(11, 600 − 11) = 11), rep 2.
 */
class TypeBFinalizeAssessmentTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_type_b_finalize_pin';

    public function test_a_lawful_panel_map_passes_and_illegal_maps_are_named(): void
    {
        $this->onLivePg(function (): void {
            [$leg, $kids] = $this->seedChamber();
            $assess = fn () => $this->assess($leg);

            // Lawful: 5 panels, sizes [2,1,1,1,1], 2 seats each = 10 = type_b_seats.
            $this->grouping($leg, 10, [[$kids[0], $kids[1]], [$kids[2]], [$kids[3]], [$kids[4]], [$kids[5]]], 2);
            $r = $assess();
            $this->assertSame([], $r['reasons'], 'a machine-shaped map has no reason');

            // Seat breach: 6 panels × 2 = 12 over the ceiling 11 (type_b_seats set to match so only the breach fires).
            $this->setTypeB($leg, 12);
            $this->grouping($leg, 12, array_map(fn ($k) => [$k], $kids), 2);
            $r = $assess();
            $this->assertCount(1, $r['reasons']);
            $this->assertStringContainsString('seat breach: 12 seats over the ceiling 11', $r['reasons'][0]);

            // Unassigned: one constituent in no panel.
            $this->setTypeB($leg, 10);
            $this->grouping($leg, 10, [[$kids[0]], [$kids[1]], [$kids[2]], [$kids[3]], [$kids[4]]], 2);
            $r = $assess();
            $this->assertCount(1, $r['reasons']);
            $this->assertStringContainsString('unassigned constituents: 1 of 6', $r['reasons'][0]);

            // Uneven: [4,1,1] with 3 panels × 2 = 6.
            $this->setTypeB($leg, 6);
            $this->grouping($leg, 6, [[$kids[0], $kids[1], $kids[2], $kids[3]], [$kids[4]], [$kids[5]]], 2);
            $r = $assess();
            $this->assertCount(1, $r['reasons']);
            $this->assertStringContainsString('uneven clumps: members 1..4', $r['reasons'][0]);

            // Identity: the grouping records 10 but the chamber holds 8.
            $this->setTypeB($leg, 8);
            $this->grouping($leg, 10, [[$kids[0], $kids[1]], [$kids[2]], [$kids[3]], [$kids[4]], [$kids[5]]], 2);
            $r = $assess();
            $this->assertCount(1, $r['reasons']);
            $this->assertStringContainsString('grouping 10 vs chamber type_b_seats 8', $r['reasons'][0]);

            // Empty panel: a panel with no member elects nobody.
            $this->setTypeB($leg, 12);
            $this->grouping($leg, 12, [[$kids[0], $kids[1]], [$kids[2]], [$kids[3]], [$kids[4]], [$kids[5]], []], 2);
            $r = $assess();
            $this->assertNotEmpty(array_filter($r['reasons'], fn ($s) => str_contains($s, 'empty panels: 1')));
        });
    }

    public function test_a_population_capped_zero_panel_map_is_lawful(): void
    {
        $this->onLivePg(function (): void {
            // Two children of 3, Type A 5: ceiling min(5, 6 − 5) = 1 below one panel of 2.
            [$leg] = $this->seedChamber(children: 2, pop: 3, typeA: 5, typeB: 0);
            $this->grouping($leg, 0, [], 2);
            $r = $this->assess($leg);
            $this->assertSame([], $r['reasons']);
            $this->assertCount(1, $r['notes']);
            $this->assertStringContainsString('zero panels lawful', $r['notes'][0]);
        });
    }

    // ── fixture plumbing ────────────────────────────────────────────────────

    private function assess(string $legId): array
    {
        $leg = DB::table('legislatures')->where('id', $legId)->first();
        $m = new \ReflectionMethod(SweepScopeProcessor::class, 'assessTypeB');
        $m->setAccessible(true);

        return $m->invoke(app(SweepScopeProcessor::class), $leg, $legId);
    }

    private function setTypeB(string $legId, int $seats): void
    {
        DB::table('legislatures')->where('id', $legId)->update(['type_b_seats' => $seats]);
    }

    /** Replace the chamber's active grouping with the given panels. @param list<list<string>> $panels */
    private function grouping(string $legId, int $seatsTotal, array $panels, int $seatsPerPanel): void
    {
        DB::table('legislature_type_b_groupings')->where('legislature_id', $legId)->update(['status' => 'archived']);
        $gid = (string) Str::uuid();
        DB::table('legislature_type_b_groupings')->insert([
            'id' => $gid, 'legislature_id' => $legId, 'status' => 'active', 'rep_floor' => 2,
            'group_size' => max(1, ...array_map('count', $panels ?: [[]])), 'panel_count' => count($panels),
            'seats_total' => $seatsTotal, 'type_a_bound' => 11, 'tie_break_key' => 'test',
            'signature' => Str::random(12), 'effective_start' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($panels as $i => $members) {
            $pid = (string) Str::uuid();
            DB::table('legislature_type_b_panels')->insert([
                'id' => $pid, 'grouping_id' => $gid, 'legislature_id' => $legId, 'panel_number' => $i + 1,
                'seats' => $seatsPerPanel, 'bonus_seats' => 0, 'member_count' => count($members),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($members as $jid) {
                DB::table('legislature_type_b_panel_jurisdictions')->insert([
                    'id' => (string) Str::uuid(), 'panel_id' => $pid, 'grouping_id' => $gid, 'jurisdiction_id' => $jid,
                ]);
            }
        }
    }

    /** @return array{0:string,1:list<string>} [legislature id, child ids] */
    private function seedChamber(int $children = 6, int $pop = 100, int $typeA = 11, int $typeB = 10): array
    {
        $tag = 'tbfin-' . Str::lower(Str::random(6));
        $parentId = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $parentId, 'name' => "{$tag}-parent", 'slug' => "{$tag}-parent",
            'adm_level' => 1, 'population' => $children * $pop, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $kids = [];
        for ($i = 0; $i < $children; $i++) {
            $cid = (string) Str::uuid();
            $kids[] = $cid;
            DB::table('jurisdictions')->insert([
                'id' => $cid, 'name' => "{$tag}-c{$i}", 'slug' => "{$tag}-c{$i}",
                'parent_id' => $parentId, 'adm_level' => 2, 'population' => $pop,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $parentId, 'term_number' => 1,
            'status' => 'forming', 'total_seats' => $typeA + $typeB, 'type_a_seats' => $typeA,
            'type_b_seats' => $typeB, 'type_b_rep_floor' => 2, 'type_b_needs_districting' => false,
            'quorum_required' => max(3, (int) ceil(($typeA + $typeB) / 2)), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$legId, $kids];
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
