<?php

namespace Tests\Feature;

use App\Http\Controllers\Legislature\TypeBMapController;
use App\Models\User;
use App\Services\Legislature\TypeBDistrictMapper;
use App\Services\Legislature\TypeBGroupingPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE TYPE B MAP LIFECYCLE, END TO END through the controller — the functional
 * contract the operator reported broken (2026-09-05): a blank default, an EMPTY
 * "New map", editable/disbandable panels on a draft, a "Clear" that blanks the
 * current map, an Activate that refuses an unsaved id, and a Deactivate that
 * unseats. Runs as an operator against the live founded box inside a rolled-back
 * transaction (no HTTP; the controller methods are called directly with an
 * operator-bearing Request).
 */
class TypeBMapControllerTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_type_b_map_ctrl';

    public function test_full_map_lifecycle(): void
    {
        $this->onLivePg(function (): void {
            [$legId, $childIds] = $this->seedChamber();
            $op     = $this->operator();
            $ctrl   = new TypeBMapController(new TypeBGroupingPreview());
            $mapper = app(TypeBDistrictMapper::class);

            $panelCount = fn (string $g) => (int) DB::table('legislature_type_b_panels')
                ->where('grouping_id', $g)->whereNull('deleted_at')->count();

            // 1. DEFAULT is a BLANK map — no phantom preview (0 panels).
            $preview = (new TypeBGroupingPreview())->forLegislature($legId, null);
            $this->assertSame(0, (int) $preview['meta']['panel_count'], 'no grouping → blank map (no synthetic preview)');
            $this->assertTrue(collect($preview['children'])->every(fn ($c) => $c['panel'] === null), 'every constituent unassigned on a blank map');

            // 2. NEW MAP → an EMPTY draft (0 panels), named.
            $mapId = $ctrl->createMap($this->req($op, ['name' => 'My map']), $legId)->getData(true)['id'];
            $this->assertSame(0, $panelCount($mapId), 'new map is empty');
            $this->assertSame('My map', DB::table('legislature_type_b_groupings')->where('id', $mapId)->value('name'), 'name persists');
            $this->assertSame('draft', DB::table('legislature_type_b_groupings')->where('id', $mapId)->value('status'));

            // 3. AUTOSEED fills the CURRENT draft in place — id + name preserved.
            $as = $ctrl->autoseed($this->req($op, ['map_id' => $mapId]), $legId, $mapper)->getData(true);
            $this->assertSame($mapId, $as['grouping_id'], 'autoseed fills the selected draft, not a new one');
            $this->assertGreaterThan(0, $panelCount($mapId), 'autoseed created panels');
            $this->assertSame('My map', DB::table('legislature_type_b_groupings')->where('id', $mapId)->value('name'), 'name survives autoseed');
            $seeded = $panelCount($mapId);

            // 4. DISBAND a panel → one fewer panel; its members return to unassigned.
            $ctrl->deletePanel($this->req($op, []), $legId, "{$mapId}:1");
            $this->assertSame($seeded - 1, $panelCount($mapId), 'disband removed a panel');

            // 5. CLEAR → 0 panels, but the grouping row is KEPT (a blank map).
            $ctrl->clear($this->req($op, ['map_id' => $mapId]), $legId);
            $this->assertSame(0, $panelCount($mapId), 'clear empties the current map');
            $this->assertNotNull(DB::table('legislature_type_b_groupings')->where('id', $mapId)->whereNull('deleted_at')->first(), 'clear keeps the grouping row');

            // 6. CREATE PANEL from two constituents on the now-blank draft.
            $this->assertSame(200, $ctrl->createPanel($this->req($op, ['jurisdiction_ids' => [$childIds[0], $childIds[1]], 'map_id' => $mapId]), $legId)->getStatusCode());
            $this->assertSame(1, $panelCount($mapId));

            // 7. EDIT — move a third constituent into panel 1.
            $ctrl->updatePanelMembers($this->req($op, ['add' => [$childIds[2]], 'remove' => []]), $legId, "{$mapId}:1");
            $panel1 = DB::table('legislature_type_b_panels')->where('grouping_id', $mapId)->where('panel_number', 1)->whereNull('deleted_at')->value('id');
            $this->assertSame(3, (int) DB::table('legislature_type_b_panel_jurisdictions')->where('panel_id', $panel1)->count(), 'member added');

            // 8. DISBAND panel 1 → 0 panels again.
            $ctrl->deletePanel($this->req($op, []), $legId, "{$mapId}:1");
            $this->assertSame(0, $panelCount($mapId));

            // 9. ACTIVATE a 0-panel map → refused.
            $this->assertSame(422, $ctrl->activateMap($this->req($op, []), $legId, $mapId)->getStatusCode(), 'cannot activate an empty map');

            // 10. ACTIVATE an unsaved id → refused (no silent recompute).
            $this->assertSame(422, $ctrl->activateMap($this->req($op, []), $legId, 'preview')->getStatusCode(), 'unsaved id refused');

            // 11. AUTOSEED then ACTIVATE → chamber seated.
            $ctrl->autoseed($this->req($op, ['map_id' => $mapId]), $legId, $mapper);
            $this->assertSame(200, $ctrl->activateMap($this->req($op, []), $legId, $mapId)->getStatusCode());
            $leg = DB::table('legislatures')->where('id', $legId)->first();
            $this->assertFalse((bool) $leg->type_b_needs_districting, 'flag cleared on activate');
            $this->assertGreaterThan(0, (int) $leg->type_b_seats, 'chamber seated');
            $this->assertSame('active', DB::table('legislature_type_b_groupings')->where('id', $mapId)->value('status'));

            // 12. CLEAR refuses the active map.
            $this->assertSame(422, $ctrl->clear($this->req($op, ['map_id' => $mapId]), $legId)->getStatusCode(), 'cannot clear a seated map');

            // 13. DEACTIVATE → chamber unseated + re-flagged; the grouping archives.
            $ctrl->deactivate($this->req($op, []), $legId);
            $leg2 = DB::table('legislatures')->where('id', $legId)->first();
            $this->assertTrue((bool) $leg2->type_b_needs_districting, 'chamber re-flagged after deactivate');
            $this->assertSame(0, (int) $leg2->type_b_seats, 'seats reset');
            $this->assertSame('archived', DB::table('legislature_type_b_groupings')->where('id', $mapId)->value('status'));

            // 14. RENAME persists (on the archived grouping).
            $ctrl->updateMap($this->req($op, ['name' => 'Renamed']), $legId, $mapId);
            $this->assertSame('Renamed', DB::table('legislature_type_b_groupings')->where('id', $mapId)->value('name'));

            // 15. DELETE a non-active grouping removes it.
            $ctrl->deleteMap($this->req($op, []), $legId, $mapId);
            $this->assertNull(DB::table('legislature_type_b_groupings')->where('id', $mapId)->whereNull('deleted_at')->first());
        });
    }

    private function req(User $op, array $body): Request
    {
        $r = Request::create('/', 'POST', $body);
        $r->setUserResolver(fn () => $op);

        return $r;
    }

    private function operator(): User
    {
        $op = User::create([
            'name'              => 'Op ' . Str::random(6),
            'email'             => 'op-' . Str::uuid() . '@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
        $op->is_operator = true; // in-memory is enough — the controller reads the attribute

        return $op;
    }

    /** parent + 8 inhabited children in a line, Type A 5, flagged. @return array{0:string,1:list<string>} */
    private function seedChamber(): array
    {
        $tag = 'tbmap-' . Str::lower(Str::random(6));
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

        return [$legId, $childIds];
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
