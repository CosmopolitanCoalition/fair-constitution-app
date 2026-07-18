<?php

namespace Tests\Constitutional;

use App\Jobs\AutoscaleLegislatureJob;
use App\Jobs\AutoscaleOrchestratorJob;
use App\Models\AutoscaleItem;
use App\Models\AutoscaleRun;
use App\Services\ConstitutionalDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — FULL-SCALE AUTOSCALE (operator ruling 2026-07-18):
 * map-data acceptance kicks off governance for ALL jurisdictions. The
 * orchestrator sizes every legislature (TRUE ALL SCALE — parents per-row by
 * the cube-root law, childless leaves set-based) and district-maps every one
 * (mixed-autoseed sweeps for parents, single at-large districts for leaves).
 *
 * Runs on a fully SYNTHETIC 3-level fixture built in-transaction ("Pin Earth"
 * adm0 → Pinland/Brokenland/2 midgets adm1 → quarters + childless giant
 * strips adm2 → villages adm3), so the pins hold on any dev/CI box. Pins:
 *  1. TRUE ALL SCALE sizing — every jurisdiction gets a legislature: parents
 *     via the cube-root children-sum law, childless leaves set-based with
 *     seats = min(ceiling, max(floor, round(pop^⅓))) and the quorum law;
 *  2. leaves get a complete governance shape: ACTIVE founding map + ONE
 *     at-large district (seats = type_a, member = self) + spatial stats;
 *  3. parents get complete ACTIVE founding maps from the PROVEN mixed sweep —
 *     composite districts AND line-split leaf giants in the same map;
 *  4. Σ-seat drift is INFORMATIONAL (seating law 2026-07-13): a complete map
 *     whose nearest-rounded seats drift from type_a still activates, drift
 *     recorded on the item — never a failure, never total-forced;
 *  5. an engineered-incomplete scope (its leaf giant has no population
 *     raster, so the line split lawfully refuses) lands on the review list
 *     with reasons and its map STAYS DRAFT — failures never sink the run,
 *     which still completes with review_count recorded;
 *  6. the run is durable state: counters + per-item outcomes land in
 *     autoscale_runs/autoscale_items, and the summary audit appends exist.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class AutoscalePinTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_autoscale_pin';

    /** ISO with a synthetic raster (line splits work) vs without (they refuse). */
    private const ISO_OK     = 'ZZA';
    private const ISO_BROKEN = 'ZZB';

    public function test_full_scale_autoscale_builds_every_legislature_and_map(): void
    {
        $this->onLivePg(function (array $ctx) {
            Queue::fake();

            $run = AutoscaleRun::create([
                'status'            => 'queued',
                'adm_max'           => 6,
                'initiator_user_id' => $ctx['operator_id'],
                'template'          => null,
            ]);

            // Drive the tick model inline: each orchestrator tick advances a
            // phase / tops up a wave; queued sweep items run inline (what a
            // supervisor-autoscale worker would do).
            $orchestrator = new AutoscaleOrchestratorJob((string) $run->id);
            for ($tick = 0; $tick < 15; $tick++) {
                $orchestrator->handle();

                $queued = AutoscaleItem::query()
                    ->where('run_id', $run->id)
                    ->where('status', 'queued')
                    ->orderBy('position')
                    ->pluck('id');
                foreach ($queued as $itemId) {
                    (new AutoscaleLegislatureJob((string) $itemId))->handle();
                }

                if (AutoscaleRun::query()->find($run->id)?->status === 'done') {
                    break;
                }
            }

            $run->refresh();
            $this->assertSame('done', $run->status,
                'the run must complete even with a review item — failures never sink the run');

            // ── Pin 1: TRUE ALL SCALE sizing ─────────────────────────────
            foreach ($ctx['parents'] as $name => $jid) {
                $this->assertTrue(
                    DB::table('legislatures')->where('jurisdiction_id', $jid)->whereNull('deleted_at')->exists(),
                    "parent {$name} must have a legislature"
                );
            }
            $leafLegs = DB::table('legislatures as l')
                ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
                ->whereIn('l.jurisdiction_id', array_values($ctx['leaves']))
                ->whereNull('l.deleted_at')
                ->get(['j.name', 'j.population', 'l.type_a_seats', 'l.type_b_seats', 'l.total_seats', 'l.quorum_required']);
            $this->assertCount(count($ctx['leaves']), $leafLegs,
                'EVERY childless jurisdiction gets a legislature (True All Scale — no level skipped)');
            foreach ($leafLegs as $leg) {
                $expected = min(9, max(5, (int) round(pow(max((int) $leg->population, 1), 1 / 3))));
                $this->assertSame($expected, (int) $leg->type_a_seats,
                    "leaf {$leg->name}: seats = min(ceiling, max(floor, round(pop^⅓)))");
                $this->assertSame(0, (int) $leg->type_b_seats);
                $this->assertSame(max(3, (int) ceil($leg->total_seats / 2)), (int) $leg->quorum_required,
                    "leaf {$leg->name}: quorum = max(3, ceil(total/2))");
            }

            // ── Pin 2: leaf singles — active map + one at-large district ──
            foreach ($ctx['leaves'] as $name => $jid) {
                $legId = DB::table('legislatures')->where('jurisdiction_id', $jid)->whereNull('deleted_at')->value('id');
                $map = DB::table('legislature_district_maps')
                    ->where('legislature_id', $legId)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();
                $this->assertNotNull($map, "leaf {$name} must have an ACTIVE founding map");

                $districts = DB::table('legislature_districts')
                    ->where('map_id', $map->id)
                    ->whereNull('deleted_at')
                    ->get();
                $this->assertCount(1, $districts, "leaf {$name}: exactly one at-large district");
                $d = $districts->first();
                $typeA = (int) DB::table('legislatures')->where('id', $legId)->value('type_a_seats');
                $this->assertSame($typeA, (int) $d->seats, "leaf {$name}: district seats = type_a");
                $this->assertSame($jid, (string) $d->jurisdiction_id, 'the at-large district scopes the leaf itself');
                $this->assertTrue((bool) $d->is_contiguous, 'single-member districts are contiguous by definition');
                $this->assertNotNull($d->num_geom_parts, 'spatial stats must be computed set-based');

                $members = DB::table('legislature_district_jurisdictions')
                    ->where('district_id', $d->id)
                    ->pluck('jurisdiction_id');
                $this->assertSame([$jid], $members->all(), "leaf {$name}: membership = self");
            }

            // ── Pin 3: the mixed sweep — composite + line-split, ACTIVE ──
            $pinlandLegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['pinland_id'])->whereNull('deleted_at')->value('id');
            $pinlandMap = DB::table('legislature_district_maps')
                ->where('legislature_id', $pinlandLegId)
                ->where('name', 'Founding Map')
                ->whereNull('deleted_at')
                ->first();
            $this->assertNotNull($pinlandMap);
            $this->assertSame('active', $pinlandMap->status, 'a COMPLETE mixed map activates');

            $composite = DB::table('legislature_districts')
                ->where('map_id', $pinlandMap->id)
                ->where('jurisdiction_id', $ctx['pinland_id'])
                ->whereNull('deleted_at')
                ->count();
            $this->assertSame(4, $composite, 'the 4 quarters composite at the Pinland root scope');

            $drawn = DB::table('district_subdivisions')
                ->where('map_id', $pinlandMap->id)
                ->where('parent_jurisdiction_id', $ctx['pin_giant_id'])
                ->whereNull('deleted_at')
                ->count();
            $this->assertSame(2, $drawn, 'the childless giant is line-split in the SAME founding map (budget 10 → [5,5])');

            // Quarter 1 (the 3rd level): its own sweep is exact and clean.
            $q1LegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['quarter1_id'])->whereNull('deleted_at')->value('id');
            $q1Item = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $q1LegId)->first();
            $this->assertSame('done', $q1Item->status);
            $this->assertSame(0, (int) $q1Item->drift, 'Quarter 1 is exact: 4 villages × 5 seats = 20 = type_a');
            $this->assertSame(20, (int) $q1Item->seats_seated);

            // ── Pin 4: drift is INFORMATIONAL — recorded, never a failure ──
            // Pinland's arithmetic FORCES nonzero drift: giant locks 10, the
            // 4 quarters' pool of 24 nearest-rounds to 7+6+6+6 = 25 (no exact
            // 4-bin drawing exists; single-member bins cannot land), so the
            // map seats 35 vs type_a 34. A reintroduced total-forcer would
            // make this 34/0 — the CONCRETE +1 is the pin.
            $pinlandItem = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $pinlandLegId)->first();
            $this->assertSame('done', $pinlandItem->status,
                'a complete map activates regardless of Σ-seat drift (no total-forcing, ruling 2026-07-13)');
            $seated = (int) DB::table('legislature_districts')->where('map_id', $pinlandMap->id)->whereNull('deleted_at')->sum('seats');
            $this->assertSame(35, $seated, 'nearest-rounded seats: 7+6+6+6 composite + 5+5 drawn');
            $this->assertSame(1, (int) $pinlandItem->drift,
                'the +1 drift is recorded honestly — never repaired by total-forcing');
            $this->assertSame($seated - (int) $pinlandItem->seats_expected, (int) $pinlandItem->drift);

            // ── Pin 5: the engineered-incomplete scope → review, map draft ──
            $brokenLegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['brokenland_id'])->whereNull('deleted_at')->value('id');
            $brokenItem = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $brokenLegId)->first();
            $this->assertSame('review', $brokenItem->status,
                'a scope whose leaf giant cannot line-split (no raster) lands on the review list');
            $this->assertStringContainsString('giant', mb_strtolower((string) $brokenItem->reason));

            $brokenMap = DB::table('legislature_district_maps')
                ->where('legislature_id', $brokenLegId)
                ->where('name', 'Founding Map')
                ->whereNull('deleted_at')
                ->first();
            $this->assertSame('draft', $brokenMap->status, 'an INCOMPLETE map never activates');
            $this->assertGreaterThanOrEqual(1, DB::table('legislature_districts')
                ->where('map_id', $brokenMap->id)->whereNull('deleted_at')->count(),
                'the composite part of the partial sweep is preserved for the operator');

            // ── Pin 6: durable run state + summary audits ─────────────────
            $this->assertSame(count($ctx['leaves']), (int) $run->singles_done);
            $this->assertSame(3, (int) $run->sweeps_done, 'Pin Earth + Pinland + Quarter 1 complete');
            $this->assertSame(1, (int) $run->review_count, 'Brokenland is the one review item');
            $this->assertSame(4, (int) $run->sweeps_total);
            $this->assertNotNull($run->finished_at);

            $this->assertSame(3, (int) DB::table('audit_log')
                ->where('event', 'district_map.generated')
                ->where('payload->generator', 'like', '%AutoscaleLegislatureJob%')
                ->count(), 'EXACTLY one summary audit entry per completed sweep — never a flood, never silent');
            $this->assertSame(3, (int) DB::table('audit_log')->where('event', 'autoscale.singles_generated')->count(),
                'EXACTLY one singles append per adm level touched (1, 2, 3) — never per-row');
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'autoscale.completed')->count());
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'autoscale.sizing_completed')->count());
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'bootstrap_board_constituted')->count(),
                'the founding bootstrap board (R-08 substrate) is constituted exactly once');

            // ── Pin 7: ADOPT, never bulldoze — a requeued sweep item whose
            // legislature already has an ACTIVE map with districts is taken
            // as-is (the operator's accepted work is never archived by a
            // machine redraw).
            AutoscaleItem::query()->whereKey($pinlandItem->id)->update(['status' => 'queued', 'updated_at' => now()]);
            (new AutoscaleLegislatureJob((string) $pinlandItem->id))->handle();
            $pinlandItem->refresh();
            $this->assertSame('done', $pinlandItem->status);
            $this->assertStringContainsString('adopted', (string) $pinlandItem->reason,
                'a re-run over an activated map adopts it instead of re-sweeping');
            $this->assertSame('active', DB::table('legislature_district_maps')->where('id', $pinlandMap->id)->value('status'),
                'the previously activated map is untouched — not archived by a second founding sweep');
        });
    }

    public function test_halt_parks_the_run_and_resume_completes_it(): void
    {
        $this->onLivePg(function (array $ctx) {
            Queue::fake();

            $run = AutoscaleRun::create([
                'status'            => 'queued',
                'adm_max'           => 6,
                'initiator_user_id' => $ctx['operator_id'],
                'template'          => null,
            ]);
            $orchestrator = new AutoscaleOrchestratorJob((string) $run->id);

            // Tick 1: sizing completes, mapping starts, first wave queued.
            $orchestrator->handle();
            $this->assertSame('mapping', $run->refresh()->status);
            $queuedBefore = AutoscaleItem::query()->where('run_id', $run->id)->where('status', 'queued')->count();
            $this->assertGreaterThan(0, $queuedBefore, 'the first wave is out');

            // HALT: the next tick parks the run; already-queued wave jobs
            // drain harmlessly (each hands its item back to pending).
            \Illuminate\Support\Facades\Cache::put(AutoscaleRun::HALT_CACHE_KEY, true, 3600);
            $orchestrator->handle();
            $this->assertSame('halted', $run->refresh()->status, 'halt parks the run at the next tick');

            foreach (AutoscaleItem::query()->where('run_id', $run->id)->where('status', 'queued')->pluck('id') as $itemId) {
                (new AutoscaleLegislatureJob((string) $itemId))->handle();
            }
            $this->assertSame(0, AutoscaleItem::query()->where('run_id', $run->id)->where('status', 'queued')->count(),
                'queued wave jobs requeue their items instead of sweeping through a halt');
            $this->assertSame(0, AutoscaleItem::query()->where('run_id', $run->id)->whereIn('status', ['done'])
                ->where('kind', 'sweep')->count(), 'no sweep ran during the halt');

            // RESUME (the accept-maps re-POST / dashboard-button path):
            // clear the flag, dispatch a fresh chain, run to completion.
            \Illuminate\Support\Facades\Cache::forget(AutoscaleRun::HALT_CACHE_KEY);
            for ($tick = 0; $tick < 15; $tick++) {
                $orchestrator->handle();
                foreach (AutoscaleItem::query()->where('run_id', $run->id)->where('status', 'queued')->pluck('id') as $itemId) {
                    (new AutoscaleLegislatureJob((string) $itemId))->handle();
                }
                if (AutoscaleRun::query()->find($run->id)?->status === 'done') {
                    break;
                }
            }

            $this->assertSame('done', $run->refresh()->status, 'a halted run resumes to completion — nothing is lost');
            $this->assertSame(1, (int) $run->review_count);
        });
    }

    // ── fixture ─────────────────────────────────────────────────────────────

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        // The orchestrator's sizing phase sweeps the WHOLE jurisdictions
        // table (that is its job) — on a fully seeded box that is a
        // multi-minute in-transaction crawl, so this pin runs on dev/CI
        // boxes where the fixture dominates the table.
        $preExisting = (int) DB::table('jurisdictions')->whereNull('deleted_at')->count();
        if ($preExisting > 10000) {
            DB::setDefaultConnection($original);
            $this->markTestSkipped('autoscale pin needs a small jurisdictions table (dev/CI box) — sizing sweeps everything.');
        }

        $conn->beginTransaction();

        try {
            $body($this->buildThreeLevelPinland());
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            ConstitutionalDefaults::flush();
        }
    }

    /**
     * Three-level Pinland (all populations chosen so the law's arithmetic is
     * hand-checkable):
     *
     *   Pin Earth (adm0, stored 75 000 — children-sum 71 000, NOISY on purpose)
     *   ├── Pinland    (adm1, 43 000 stored — children-sum 41 000, ISO ZZA)
     *   │   ├── Pin Quarter 1 (adm2, 8 000) ← PARENT (3rd level below)
     *   │   │   └── 4 villages (adm3, 2 000 each) — exact: 20 seats = 4×5
     *   │   ├── Pin Quarters 2–4 (adm2, 7 000 each, childless)
     *   │   └── Pin Giant Strip (adm2, 12 000, childless, HAS raster)
     *   │       → frac 12000×34/41000 = 9.95 ≥ 9.5 → line-split, budget 10
     *   ├── Brokenland (adm1, 18 000, ISO ZZB — NO raster)
     *   │   ├── Broken Quarters 1–2 (adm2, 3 000 each, childless)
     *   │   └── Broken Giant Strip (adm2, 12 000, childless, NO raster)
     *   │       → frac 17.33 → line-split REFUSES (no population raster)
     *   └── Pin Midget A/B (adm1, 5 000 each, childless)
     *
     * Plus: an operator user (the run's initiating actor — R-08 rides the
     * bootstrap board the ORCHESTRATOR constitutes; nothing pre-seeded) and
     * a synthetic WorldPop tile for ZZA only.
     */
    private function buildThreeLevelPinland(): array
    {
        $now = now();
        $mkJur = function (string $name, ?string $parentId, int $admLevel, int $pop, array $rect, string $iso) use ($now): string {
            $id = (string) Str::uuid();
            DB::insert(
                "INSERT INTO jurisdictions
                    (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                     source, official_languages, timezone, geom, centroid, created_at, updated_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, true, true, 'pin-fixture', '[]', 'UTC',
                     ST_Multi(ST_MakeEnvelope(?, ?, ?, ?, 4326)),
                     ST_Centroid(ST_MakeEnvelope(?, ?, ?, ?, 4326)), ?, ?)",
                [
                    $id, $name, 'zz-'.$admLevel.'-'.Str::slug($name).'-'.substr($id, 0, 8), $iso,
                    $admLevel, $parentId, $pop,
                    ...$rect, ...$rect,
                    $now, $now,
                ]
            );

            return $id;
        };

        // Pin Earth spans both countries. Stored pop NOISY vs children-sum —
        // the Kentucky regression posture rides along at every level.
        $earthId = $mkJur('Pin Earth', null, 0, 75000, [10.0, 49.0, 21.0, 51.0], self::ISO_OK);

        $pinlandId = $mkJur('Pinland', $earthId, 1, 43000, [10.0, 50.0, 10.4, 50.2], self::ISO_OK);
        $quarter1Id = $mkJur('Pin Quarter 1', $pinlandId, 2, 8000, [10.0, 50.1, 10.1, 50.2], self::ISO_OK);
        $villages = [];
        $villages['Pin Village NW'] = $mkJur('Pin Village NW', $quarter1Id, 3, 2000, [10.0, 50.15, 10.05, 50.2], self::ISO_OK);
        $villages['Pin Village NE'] = $mkJur('Pin Village NE', $quarter1Id, 3, 2000, [10.05, 50.15, 10.1, 50.2], self::ISO_OK);
        $villages['Pin Village SW'] = $mkJur('Pin Village SW', $quarter1Id, 3, 2000, [10.0, 50.1, 10.05, 50.15], self::ISO_OK);
        $villages['Pin Village SE'] = $mkJur('Pin Village SE', $quarter1Id, 3, 2000, [10.05, 50.1, 10.1, 50.15], self::ISO_OK);
        $quarters = [];
        foreach (range(2, 4) as $i) {
            $x = 10.0 + ($i - 1) * 0.1;
            $quarters['Pin Quarter '.$i] = $mkJur('Pin Quarter '.$i, $pinlandId, 2, 7000, [$x, 50.1, $x + 0.1, 50.2], self::ISO_OK);
        }
        $pinGiantId = $mkJur('Pin Giant Strip', $pinlandId, 2, 12000, [10.0, 50.0, 10.4, 50.1], self::ISO_OK);

        $brokenlandId = $mkJur('Brokenland', $earthId, 1, 18000, [20.0, 50.0, 20.4, 50.2], self::ISO_BROKEN);
        $brokenQuarters = [];
        $brokenQuarters['Broken Quarter 1'] = $mkJur('Broken Quarter 1', $brokenlandId, 2, 3000, [20.0, 50.1, 20.2, 50.2], self::ISO_BROKEN);
        $brokenQuarters['Broken Quarter 2'] = $mkJur('Broken Quarter 2', $brokenlandId, 2, 3000, [20.2, 50.1, 20.4, 50.2], self::ISO_BROKEN);
        $brokenGiantId = $mkJur('Broken Giant Strip', $brokenlandId, 2, 12000, [20.0, 50.0, 20.4, 50.1], self::ISO_BROKEN);

        $midgets = [];
        $midgets['Pin Midget A'] = $mkJur('Pin Midget A', $earthId, 1, 5000, [15.0, 50.0, 15.2, 50.2], self::ISO_OK);
        $midgets['Pin Midget B'] = $mkJur('Pin Midget B', $earthId, 1, 5000, [15.2, 50.0, 15.4, 50.2], self::ISO_OK);

        // Synthetic WorldPop tile over the ZZA giant strip only: 80×20 cells
        // of 0.005°, 7.5 people each → exactly 12 000. ZZB has NO raster —
        // its line split must refuse, engineering the review case.
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(80, 20, 10.0, 50.1, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, 7.5, NULL),
                     ?)",
            [(string) Str::uuid(), self::ISO_OK, $now]
        );

        // The initiating operator (acceptMaps' request user in production).
        $operatorId = (string) Str::uuid();
        DB::table('users')->insert([
            'id'                => $operatorId,
            'name'              => 'Autoscale Pin Operator',
            'email'             => "autoscale-pin-{$operatorId}@example.test",
            'password'          => password_hash('autoscale-pin-password', PASSWORD_BCRYPT),
            'is_operator'       => true,
            'terms_accepted_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return [
            'earth_id'      => $earthId,
            'pinland_id'    => $pinlandId,
            'brokenland_id' => $brokenlandId,
            'quarter1_id'   => $quarter1Id,
            'pin_giant_id'  => $pinGiantId,
            'operator_id'   => $operatorId,
            'parents'       => [
                'Pin Earth'     => $earthId,
                'Pinland'       => $pinlandId,
                'Brokenland'    => $brokenlandId,
                'Pin Quarter 1' => $quarter1Id,
            ],
            'leaves'        => $quarters + $brokenQuarters + $midgets + $villages + [
                'Pin Giant Strip'    => $pinGiantId,
                'Broken Giant Strip' => $brokenGiantId,
            ],
        ];
    }
}
