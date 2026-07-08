<?php

namespace Tests\Feature;

use App\Models\GeodataFlag;
use App\Services\Geodata\GeodataFlagService;
use App\Services\Geodata\GeodataRemediationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Geodata repair plane — detector → repair → gate, end to end on live pg.
 *
 * Pins:
 *   (a) the same_space_chain detector finds a 3-member fixture chain and
 *       reports it topmost-first (the exact input order mergeChain takes);
 *   (b) mergeChain is TOPMOST-OWNS — survivor keeps its row, lower members
 *       are soft-deleted with merged_into_id set, the deepest member's
 *       children become the survivor's, and no live row points at a deleted
 *       parent;
 *   (c) reparent captures full prior state and revert() restores it;
 *   (d) synthesizeAnchor mirrors the ETL synthetic-row pattern
 *       (source='synthetic_repair', union geometry, summed population, 1:1
 *       constitutional_settings row) and re-homes the children;
 *   (e) the acceptance gate: activateStep1 refuses while map_accepted_at is
 *       NULL; acceptMaps refuses with open flags unless acknowledged; the
 *       repair window closes on acceptance and reopens via reopen-maps
 *       (while setup is incomplete — afterwards the gate locks for good);
 *   (f) geodata:repairs-export produces a manifest that repairs-apply
 *       --dry-run classifies as already-applied (idempotent replay).
 *
 * Live-pg posture (PostGIS detectors + the flattened baseline's reference
 * rows) — per-test transaction, rolled back; never RefreshDatabase.
 */
class GeodataRepairPlaneTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_geodata_repair';

    // ─── (a) same_space_chain detector ──────────────────────────────────────

    public function test_same_space_chain_detector_finds_fixture_chain_topmost_first(): void
    {
        $this->onLivePg(function () {
            [$chain] = $this->makeChainFixture();

            $counts = app(GeodataFlagService::class)->scan(['same_space_chain'], ['ZZT']);
            $this->assertSame(1, $counts['same_space_chain'], 'exactly one chain flag for the fixture run');

            $flag = GeodataFlag::query()->open()->where('category', 'same_space_chain')->sole();
            $this->assertSame('warning', $flag->severity);
            $this->assertSame('merge_chain', $flag->suggested_action);
            $this->assertSame(
                [$chain['a_slug'], $chain['b_slug'], $chain['c_slug']],
                $flag->payload['chain_slugs'],
                'chain reported topmost first — the exact mergeChain input order'
            );
            $this->assertSame(3, $flag->payload['depth']);
            $this->assertTrue($flag->payload['md5_twin'], 'identical WKT ⇒ md5 twins');
            $this->assertSame($chain['a_id'], $flag->jurisdiction_id, 'flag anchors on the topmost member');
            $this->assertSame($chain['c_id'], $flag->related_jurisdiction_id, 'related = deepest member');
        });
    }

    // ─── (b) mergeChain — topmost owns ──────────────────────────────────────

    public function test_merge_chain_topmost_owns_and_leaves_no_live_orphan(): void
    {
        $this->onLivePg(function () {
            [$chain, $ids] = $this->makeChainFixture();

            $repair = app(GeodataRemediationService::class)->mergeChain(
                null,
                [$chain['a_slug'], $chain['b_slug'], $chain['c_slug']],
                'test merge',
                null,
            );
            $this->assertSame('merge_chain', $repair->action);
            $this->assertSame($chain['a_slug'], $repair->target_slug, 'ledger targets the survivor');

            // Survivor untouched and live.
            $a = DB::table('jurisdictions')->where('id', $chain['a_id'])->first();
            $this->assertNull($a->deleted_at);
            $this->assertNull($a->merged_into_id);

            // Lower members soft-deleted, pointing at the survivor.
            foreach ([$chain['b_id'], $chain['c_id']] as $loserId) {
                $loser = DB::table('jurisdictions')->where('id', $loserId)->first();
                $this->assertNotNull($loser->deleted_at, 'chain member soft-deleted');
                $this->assertSame($chain['a_id'], $loser->merged_into_id, 'merged_into_id = survivor');
            }

            // The deepest member's children are now the survivor's direct children.
            $childRows = DB::table('jurisdictions')
                ->whereIn('id', [$ids['d1'], $ids['d2']])
                ->get();
            foreach ($childRows as $child) {
                $this->assertSame($chain['a_id'], $child->parent_id);
                $this->assertSame('manual', $child->parent_assigned_via);
            }
            $this->assertSame(
                2,
                (int) DB::table('jurisdictions')->where('parent_id', $chain['a_id'])->whereNull('deleted_at')->count(),
                "survivor's direct live children = the deepest member's former children"
            );

            // Invariant: no live ZZT row references a soft-deleted parent.
            $orphans = (int) DB::table('jurisdictions as j')
                ->join('jurisdictions as p', 'p.id', '=', 'j.parent_id')
                ->whereNull('j.deleted_at')
                ->whereNotNull('p.deleted_at')
                ->where('j.iso_code', 'ZZT')
                ->count();
            $this->assertSame(0, $orphans, 'merge leaves no live row under a deleted parent');
        });
    }

    // ─── (c) reparent + revert ──────────────────────────────────────────────

    public function test_reparent_then_revert_restores_prior_parent_and_via(): void
    {
        $this->onLivePg(function () {
            $planet = $this->makeRow('zzt-0-planet', 'Test Planet', 0, null, $this->square(0, 0, 20, 20), null);
            $p1 = $this->makeRow('zzt-1-alphaland', 'Alphaland', 1, $planet, $this->square(0, 0, 10, 10));
            $p2 = $this->makeRow('zzt-1-betaland', 'Betaland', 1, $planet, $this->square(10, 0, 20, 10));
            $x = $this->makeRow('zzt-2-wanderer', 'Wanderer', 2, $p1, $this->square(12, 2, 14, 4));

            $svc = app(GeodataRemediationService::class);
            $repair = $svc->reparent(null, 'zzt-2-wanderer', 'zzt-1-betaland', 'belongs in betaland', null);

            $row = DB::table('jurisdictions')->where('id', $x)->first();
            $this->assertSame($p2, $row->parent_id);
            $this->assertSame('manual', $row->parent_assigned_via);
            $this->assertSame($p1, $repair->params['old_parent_id'], 'prior state captured for revert');
            $this->assertSame('direct', $repair->params['old_parent_assigned_via']);

            $reverted = $svc->revert($repair, null);
            $this->assertNotNull($reverted->reverted_at);

            $row = DB::table('jurisdictions')->where('id', $x)->first();
            $this->assertSame($p1, $row->parent_id, 'revert restores the original parent');
            $this->assertSame('direct', $row->parent_assigned_via, 'revert restores the original via');
        });
    }

    // ─── (d) synthesizeAnchor ───────────────────────────────────────────────

    public function test_synthesize_anchor_creates_row_settings_and_rehomes_children(): void
    {
        $this->onLivePg(function () {
            $planet = $this->makeRow('zzt-0-planet', 'Test Planet', 0, null, $this->square(0, 0, 20, 20), null);
            $country = $this->makeRow('zzt-1-alphaland', 'Alphaland', 1, $planet, $this->square(0, 0, 10, 10), 'ZZT', 5000);
            $c1 = $this->makeRow('zzt-3-east-town', 'East Town', 3, $country, $this->square(1, 1, 3, 3), 'ZZT', 1200);
            $c2 = $this->makeRow('zzt-3-west-town', 'West Town', 3, $country, $this->square(4, 1, 6, 3), 'ZZT', 800);

            $repair = app(GeodataRemediationService::class)->synthesizeAnchor(
                null, 'zzt-1-alphaland', 'Middle Province', ['zzt-3-east-town', 'zzt-3-west-town'], null, null,
            );

            $anchor = DB::table('jurisdictions')->where('id', $repair->params['anchor_id'])->first();
            $this->assertNotNull($anchor, 'anchor row created');
            $this->assertSame('synthetic_repair', $anchor->source);
            $this->assertSame('zzt-2-middle-province', $anchor->slug, '{iso}-{level}-{name} slug pattern');
            $this->assertSame(2, (int) $anchor->adm_level, 'parent level + 1');
            $this->assertSame('ZZT', $anchor->iso_code);
            $this->assertSame($country, $anchor->parent_id);
            $this->assertSame(2000, (int) $anchor->population, 'population = sum of the children');
            $this->assertSame('manual_repair', $anchor->population_assigned_via);
            $this->assertNotNull($anchor->geom, 'geometry = union of the children');
            $this->assertNotNull($anchor->centroid);

            // Children re-homed onto the anchor.
            foreach ([$c1, $c2] as $childId) {
                $child = DB::table('jurisdictions')->where('id', $childId)->first();
                $this->assertSame($anchor->id, $child->parent_id);
                $this->assertSame('manual', $child->parent_assigned_via);
            }

            // The ETL's 1:1 constitutional_settings mirror.
            $this->assertTrue(
                DB::table('constitutional_settings')->where('jurisdiction_id', $anchor->id)->exists(),
                'a new jurisdiction always gets its constitutional_settings row'
            );
        });
    }

    // ─── (e) acceptance gate + repair window ────────────────────────────────

    public function test_acceptance_gate_acknowledgment_window_close_and_reopen(): void
    {
        $this->onLivePg(function () {
            Queue::fake(); // acceptMaps queues apportionment:seed — not under test here

            // accept-maps / reopen-maps are operator-gated (they flip the
            // repair-window gate) — an unauthenticated POST 403s.
            $this->postJson('/api/jurisdictions/accept-maps', [])->assertStatus(403);

            $operator = \App\Models\User::forceCreate([
                'id'                => (string) \Illuminate\Support\Str::uuid(),
                'name'              => 'Gate Test Operator',
                'email'             => 'gate-test-' . uniqid() . '@test.local',
                'password'          => bcrypt('irrelevant'),
                'is_operator'       => true,
                'terms_accepted_at' => now(),
            ]);
            $this->actingAs($operator);

            // Wizard cannot advance while map data is unaccepted.
            $this->postJson('/api/setup/wizard/step1/activate')
                ->assertStatus(422)
                ->assertJsonPath('map_acceptance_required', true)
                ->assertJsonPath('error', fn ($e) => str_contains((string) $e, 'Accept the map data first'));

            // An open critical flag forces the acknowledgment round-trip.
            $flag = GeodataFlag::create([
                'category'    => 'orphaned_rows',
                'severity'    => 'critical',
                'title'       => 'test critical flag',
                'payload'     => ['iso' => 'ZZT'],
                'fingerprint' => sha1('test|ZZT|gate'),
                'status'      => 'open',
                'detected_at' => now(),
            ]);

            $this->postJson('/api/jurisdictions/accept-maps', [])
                ->assertStatus(422)
                ->assertJsonPath('requires_acknowledgment', true)
                ->assertJsonPath('open_flags.critical', 1);

            $this->postJson('/api/jurisdictions/accept-maps', ['acknowledge_open_flags' => true])
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('open_flags_at_acceptance.critical', 1);

            // Acceptance closes the repair window.
            $svc = app(GeodataRemediationService::class);
            try {
                $svc->acceptFlag($flag->refresh(), null, null);
                $this->fail('remediation must throw once the map data is accepted');
            } catch (\RuntimeException $e) {
                $this->assertSame('repair window closed', $e->getMessage());
            }

            // Reopen (setup still incomplete) → the window works again.
            $this->postJson('/api/jurisdictions/reopen-maps')
                ->assertOk()
                ->assertJsonPath('ok', true);
            $this->assertNull(
                DB::table('instance_settings')->whereNull('deleted_at')->value('map_accepted_at'),
                'reopen clears map_accepted_at'
            );

            $accepted = $svc->acceptFlag($flag->refresh(), 'fine after review', null);
            $this->assertSame('accepted', $accepted->status);

            // Once setup completes, the gate locks for good.
            DB::table('instance_settings')->whereNull('deleted_at')->update(['setup_completed_at' => now()]);
            $this->postJson('/api/jurisdictions/reopen-maps')->assertStatus(403);
        });
    }

    // ─── (f) export → apply --dry-run idempotency ───────────────────────────

    public function test_repairs_export_then_dry_run_apply_skips_already_applied_rule(): void
    {
        $this->onLivePg(function () {
            $planet = $this->makeRow('zzt-0-planet', 'Test Planet', 0, null, $this->square(0, 0, 20, 20), null);
            $p1 = $this->makeRow('zzt-1-alphaland', 'Alphaland', 1, $planet, $this->square(0, 0, 10, 10));
            $p2 = $this->makeRow('zzt-1-betaland', 'Betaland', 1, $planet, $this->square(10, 0, 20, 10));
            $this->makeRow('zzt-2-wanderer', 'Wanderer', 2, $p1, $this->square(12, 2, 14, 4));

            app(GeodataRemediationService::class)
                ->reparent(null, 'zzt-2-wanderer', 'zzt-1-betaland', 'manifest test', null);

            $path = storage_path('app/geodata/test-manifest-' . Str::random(8) . '.json');
            try {
                $this->assertSame(0, Artisan::call('geodata:repairs-export', ['--file' => $path]));
                $manifest = json_decode((string) file_get_contents($path), true);
                $this->assertSame(1, $manifest['count']);
                $this->assertSame('reparent', $manifest['repairs'][0]['action']);
                $this->assertSame('zzt-2-wanderer', $manifest['repairs'][0]['target_slug']);

                // The repair is already applied on this box → the dry run
                // classifies the rule as SKIP, nothing as apply/fail.
                $this->assertSame(0, Artisan::call('geodata:repairs-apply', ['file' => $path, '--dry-run' => true]));
                $output = Artisan::output();
                $this->assertStringContainsString('SKIP', $output);
                $this->assertStringContainsString('0 applied, 1 skipped, 0 failed', $output);
            } finally {
                @unlink($path);
            }
        });
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    /**
     * A 3-member same-space chain under a synthetic ZZT country:
     *
     *   planet → country → A → B → C → {D1, D2}
     *
     * A/B/C share identical WKT (md5 twins); D1/D2 are ordinary distinct
     * children of the deepest member.
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function makeChainFixture(): array
    {
        $planet = $this->makeRow('zzt-0-planet', 'Test Planet', 0, null, $this->square(0, 0, 20, 20), null);
        $country = $this->makeRow('zzt-1-zetaland', 'Zetaland', 1, $planet, $this->square(0, 0, 10, 10));

        $same = $this->square(2, 2, 4, 4);
        $aId = $this->makeRow('zzt-2-alpha', 'Alpha', 2, $country, $same);
        $bId = $this->makeRow('zzt-3-beta', 'Beta', 3, $aId, $same);
        $cId = $this->makeRow('zzt-4-gamma', 'Gamma', 4, $bId, $same);

        $d1 = $this->makeRow('zzt-5-delta-one', 'Delta One', 5, $cId, $this->square(2, 2, 3, 3));
        $d2 = $this->makeRow('zzt-5-delta-two', 'Delta Two', 5, $cId, $this->square(3, 3, 4, 4));

        return [
            [
                'a_slug' => 'zzt-2-alpha', 'b_slug' => 'zzt-3-beta', 'c_slug' => 'zzt-4-gamma',
                'a_id' => $aId, 'b_id' => $bId, 'c_id' => $cId,
            ],
            ['planet' => $planet, 'country' => $country, 'd1' => $d1, 'd2' => $d2],
        ];
    }

    /** Insert one live jurisdictions row with PostGIS geometry; returns its id. */
    private function makeRow(
        string $slug,
        string $name,
        int $admLevel,
        ?string $parentId,
        string $wkt,
        ?string $iso = 'ZZT',
        ?int $population = null,
    ): string {
        $id = (string) Str::uuid();
        DB::statement("
            INSERT INTO jurisdictions (
                id, name, slug, iso_code, adm_level, parent_id, population,
                source, parent_assigned_via, geom, centroid, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                'geoboundaries', ?, ST_GeomFromText(?, 4326),
                ST_Centroid(ST_GeomFromText(?, 4326)), NOW(), NOW()
            )
        ", [$id, $name, $slug, $iso, $admLevel, $parentId, $population, $parentId ? 'direct' : null, $wkt, $wkt]);

        return $id;
    }

    /** Axis-aligned MULTIPOLYGON square (lon/lat degrees). */
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
        $this->withoutMiddleware(ValidateCsrfToken::class);

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
