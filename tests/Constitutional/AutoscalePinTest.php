<?php

namespace Tests\Constitutional;

use App\Jobs\AutoscaleSizingJob;
use App\Jobs\AutoscaleWorkerJob;
use App\Models\AutoscaleItem;
use App\Models\AutoscaleRun;
use App\Services\ConstitutionalDefaults;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — FULL-SCALE AUTOSCALE (operator ruling 2026-07-18;
 * pull engine 2026-07-19): map-data acceptance kicks off governance for ALL
 * jurisdictions. The sizing job sizes every legislature (TRUE ALL SCALE —
 * parents per-row by the cube-root law, childless leaves set-based); pull
 * workers claim from the ladder (singles batches → finalize → precompute →
 * sweep scopes) and district-map every one; the pump owns liveness.
 *
 * Runs on a fully SYNTHETIC 3-level fixture built in-transaction ("Pin Earth"
 * adm0 → Pinland/Brokenland/2 midgets adm1 → quarters + childless giant
 * strips adm2 → villages adm3), so the pins hold on any dev/CI box. Pins
 * (cycle-2 leaf law + Type B ladder, operator rulings 2026-07-19):
 *  1. TRUE ALL SCALE sizing — every jurisdiction gets a legislature: the
 *     SAME law everywhere, max(floor, round(pop^⅓)) — the old leaf ceiling
 *     clamp was unlawful truncation; the quorum law holds;
 *  2. IN-BAND leaves get the at-large singles shape (ACTIVE map + ONE
 *     district, seats = type_a, member = self); OVER-CEILING leaves
 *     line-split THEMSELVES (root-leaf: own map, ceil(type_a/ceiling)+
 *     districts summing exactly type_a — the LA-County shape);
 *  3. parents get complete ACTIVE founding maps from the PROVEN mixed sweep —
 *     composite districts AND line-split leaf giants in the same map;
 *  4. Σ-seat drift is INFORMATIONAL (seating law 2026-07-13): a complete map
 *     whose nearest-rounded seats drift from type_a still activates, drift
 *     recorded on the item — never a failure, never total-forced;
 *  5. honest review: a GEOMETRY-LESS giant constituent flags its parent's
 *     map (nothing can draw it), and a geometry-less over-ceiling leaf
 *     flags itself; zero-raster-coverage giants are NOT review — they
 *     line-split via the AREA-PROPORTIONAL fallback with honest
 *     population_source provenance;
 *  6. the run is durable state: counters + per-item outcomes land in
 *     autoscale_runs/autoscale_items, and the summary audit appends exist;
 *  +  the Type B ladder (bound by Type A, tiny-pop cap) and the
 *     simplest-first ordering key (est_districts, cascade_height, adm DESC,
 *     population ASC) are pinned on the same fixture.
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

    /**
     * Drive a run to completion the way production does, inline: sizing job
     * once, then alternate pull workers (claim loops drain the ladder) with
     * pump passes (reclaims, finalize repair, completion).
     */
    private function driveRun(AutoscaleRun $run, int $maxRounds = 10): void
    {
        (new AutoscaleSizingJob((string) $run->id))->handle();
        for ($round = 0; $round < $maxRounds; $round++) {
            (new AutoscaleWorkerJob((string) $run->id))->handle();
            Artisan::call('autoscale:pump');
            if (AutoscaleRun::query()->find($run->id)?->status === 'done') {
                break;
            }
        }
    }

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

            $this->driveRun($run);

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
                // Cycle-2 leaf law: the SAME law as parents — floor clamp
                // ONLY. A reintroduced ceiling clamp fails this concretely
                // (the 12,000-pop strips must size 23, never 9).
                $expected = max(5, (int) round(pow(max((int) $leg->population, 1), 1 / 3)));
                $this->assertSame($expected, (int) $leg->type_a_seats,
                    "leaf {$leg->name}: seats = max(floor, round(pop^⅓)) — no ceiling truncation");
                $this->assertSame(0, (int) $leg->type_b_seats, 'leaves have no constituents — no Type B');
                $this->assertSame(max(3, (int) ceil($leg->total_seats / 2)), (int) $leg->quorum_required,
                    "leaf {$leg->name}: quorum = max(3, ceil(total/2))");
            }

            // ── Pin 2a: IN-BAND leaf singles — active map + one at-large ──
            foreach ($ctx['single_leaves'] as $name => $jid) {
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

            // ── Pin 2b: OVER-CEILING leaf — line-splits ITSELF (root-leaf,
            // the LA-County shape): own founding map, ceil(type_a/ceiling)+
            // drawn districts (parent = itself) whose seats sum EXACTLY to
            // type_a (the Splitline in-band decomposition), map ACTIVE.
            $stripLegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['pin_giant_id'])->whereNull('deleted_at')->value('id');
            $stripItem  = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $stripLegId)->first();
            $this->assertSame('sweep', $stripItem->kind, 'an over-ceiling leaf enumerates as a line-split sweep, never a single');
            $this->assertSame('done', $stripItem->status);
            $stripMap = DB::table('legislature_district_maps')
                ->where('legislature_id', $stripLegId)->where('status', 'active')->whereNull('deleted_at')->first();
            $this->assertNotNull($stripMap, 'the over-ceiling leaf has its OWN active founding map');
            $stripTypeA = (int) DB::table('legislatures')->where('id', $stripLegId)->value('type_a_seats');
            $this->assertSame(23, $stripTypeA, '12,000 people → round(pop^⅓) = 23 seats (the unclamped law)');
            $stripDrawn = DB::table('district_subdivisions')
                ->where('map_id', $stripMap->id)
                ->where('parent_jurisdiction_id', $ctx['pin_giant_id'])
                ->whereNull('deleted_at')->count();
            $this->assertGreaterThanOrEqual((int) ceil($stripTypeA / 9), $stripDrawn,
                'the root-leaf drew at least ceil(type_a/ceiling) districts');
            $stripSeated = (int) DB::table('legislature_districts')
                ->where('map_id', $stripMap->id)->whereNull('deleted_at')->sum('seats');
            $this->assertSame($stripTypeA, $stripSeated,
                'the line-split decomposition seats EXACTLY type_a (in-band leaves summing to S)');

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

            // ── Pin 5: HONEST review + the AREA-PROPORTIONAL rescue ───────
            // (a) Brokenland flags: its GEOMETRY-LESS giant constituent can
            // neither composite nor be drawn — nothing can rescue it.
            $brokenLegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['brokenland_id'])->whereNull('deleted_at')->value('id');
            $brokenItem = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $brokenLegId)->first();
            $this->assertSame('review', $brokenItem->status,
                'a scope with a geometry-less giant constituent lands on the review list');
            $this->assertStringContainsString('geometry-less giant', (string) $brokenItem->reason);

            $brokenMap = DB::table('legislature_district_maps')
                ->where('legislature_id', $brokenLegId)
                ->where('name', 'Founding Map')
                ->whereNull('deleted_at')
                ->first();
            $this->assertSame('draft', $brokenMap->status, 'an INCOMPLETE map never activates');
            $this->assertGreaterThanOrEqual(1, DB::table('legislature_districts')
                ->where('map_id', $brokenMap->id)->whereNull('deleted_at')->count(),
                'the composite part of the partial sweep is preserved for the operator');

            // (b) ZERO-RASTER-COVERAGE is NOT review anymore (cycle-2): the
            // Broken Giant Strip (no raster tile anywhere for its iso)
            // line-splits via the AREA-PROPORTIONAL fallback inside
            // Brokenland's map, with honest provenance on every piece.
            $brokenDrawn = DB::table('district_subdivisions')
                ->where('map_id', $brokenMap->id)
                ->where('parent_jurisdiction_id', $ctx['broken_giant_id'])
                ->whereNull('deleted_at')
                ->get(['population_source']);
            $this->assertGreaterThanOrEqual(2, $brokenDrawn->count(),
                'the raster-less giant is line-split via the area-proportional fallback');
            foreach ($brokenDrawn as $piece) {
                $this->assertSame('area_proportional', (string) $piece->population_source,
                    'area-fallback pieces carry honest provenance');
            }

            // (c) a geometry-less OVER-CEILING leaf flags ITSELF — no
            // geometry means no line split, honestly reviewed.
            $ghostLegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['broken_ghost_id'])->whereNull('deleted_at')->value('id');
            $ghostItem  = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $ghostLegId)->first();
            $this->assertSame('review', $ghostItem->status,
                'a geometry-less over-ceiling leaf cannot be drawn — honest review');
            $this->assertStringContainsString('no line-split districts', (string) $ghostItem->reason);

            // ── Pin 6: durable run state + summary audits ─────────────────
            // Cycle-2 census: 1 in-band single (the hamlet); 24 sweeps = 6
            // parents + 18 over-ceiling leaves; review 2 = Brokenland
            // (geometry-less giant constituent) + the ghost leaf itself.
            $this->assertSame(1, (int) $run->singles_total, 'only the in-band hamlet rides the singles path');
            $this->assertSame(1, (int) $run->singles_done);
            $this->assertSame(24, (int) $run->sweeps_total, '6 parents + 18 over-ceiling leaf line-splits');
            $this->assertSame(22, (int) $run->sweeps_done);
            $this->assertSame(2, (int) $run->review_count, 'Brokenland + the geometry-less ghost leaf');
            $this->assertNotNull($run->finished_at);

            // ── THE TYPE B LADDER PIN (cycle-2): Type B = equal
            // representation of the direct constituents, BOUND by Type A.
            // Pinland: 5 constituents all above tiny-pop → 5 × 5 = 25 ≤ 34.
            // Quarter 1 sits EXACTLY at the bound: 4 villages × 5 = 20 = 20.
            $pinlandRow = DB::table('legislatures')->where('id', $pinlandLegId)->first();
            $this->assertSame(25, (int) $pinlandRow->type_b_seats, 'Pinland Type B: 5 constituents × 5 = 25 ≤ Type A 34');
            $this->assertSame(5, (int) $pinlandRow->type_b_rep_floor);
            $this->assertFalse((bool) $pinlandRow->type_b_needs_districting);
            $this->assertSame((int) $pinlandRow->type_a_seats + 25, (int) $pinlandRow->total_seats,
                'total = A + B, re-derived with the ladder');
            $this->assertSame(max(3, (int) ceil($pinlandRow->total_seats / 2)), (int) $pinlandRow->quorum_required,
                'quorum = majority of ALL serving members, re-derived with the ladder');
            $q1Row = DB::table('legislatures')->where('id', $q1LegId)->first();
            $this->assertSame(20, (int) $q1Row->type_b_seats, 'Quarter 1: 4 × 5 = 20, exactly AT the Type A bound — lawful');
            $this->assertSame(5, (int) $q1Row->type_b_rep_floor);
            $this->assertFalse((bool) $q1Row->type_b_needs_districting);

            // ── THE ORDERING PIN (cycle-2): simplest-first — est_districts
            // ASC, cascade_height ASC, adm DESC, population ASC. The in-band
            // hamlet leads; Pin Earth (est 7, height 3) is dead last.
            $ord = DB::table('autoscale_items')
                ->where('run_id', $run->id)
                ->join('jurisdictions as j', 'j.id', '=', 'autoscale_items.jurisdiction_id')
                ->get(['autoscale_items.position', 'autoscale_items.est_districts',
                       'autoscale_items.cascade_height', 'j.name'])
                ->keyBy('name');
            $this->assertSame(1, (int) $ord['Pin Hamlet']->est_districts);
            $this->assertSame(0, (int) $ord['Pin Hamlet']->cascade_height);
            $this->assertSame(1, (int) $ord['Pin Hamlet']->position, 'the one-district hamlet is the very first work item');
            $this->assertSame(3, (int) $ord['Pin Giant Strip']->est_districts, 'ceil(23/9) = 3');
            $this->assertSame(0, (int) $ord['Pin Giant Strip']->cascade_height);
            $this->assertSame(3, (int) $ord['Pin Quarter 1']->est_districts, 'ceil(20/9) = 3');
            $this->assertSame(1, (int) $ord['Pin Quarter 1']->cascade_height, 'parent of leaves = height 1');
            $this->assertLessThan((int) $ord['Pin Quarter 1']->position, (int) $ord['Pin Giant Strip']->position,
                'same est class: leaves (height 0) work before parents (height 1)');
            $this->assertSame(3, (int) $ord['Pin Earth']->cascade_height);
            $this->assertSame((int) DB::table('autoscale_items')->where('run_id', $run->id)->max('position'),
                (int) $ord['Pin Earth']->position, 'the planet root is the LAST work item — the honest ETA tail');

            // ── THE ONE-FRAME PIN (2026-07-19): Dom Core — a LOCAL giant of
            // Dom Province, sub-threshold in the root frame — gets its own
            // line-split scope. The old root-flat-share walk stranded it.
            $domlandLegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['domland_id'])->whereNull('deleted_at')->value('id');
            $domlandItem  = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $domlandLegId)->first();
            $this->assertSame('done', $domlandItem->status,
                'the one-frame walk completes the dominant-child scope instead of stranding it');
            $domlandMapId = DB::table('legislature_district_maps')
                ->where('legislature_id', $domlandLegId)->where('name', 'Founding Map')->whereNull('deleted_at')->value('id');
            $this->assertGreaterThanOrEqual(2, (int) DB::table('district_subdivisions')
                ->where('map_id', $domlandMapId)
                ->where('parent_jurisdiction_id', $ctx['dom_core_id'])
                ->whereNull('deleted_at')->count(),
                'Dom Core is line-split within its own local-frame budget (cascade, not root flat share)');
            $this->assertStringNotContainsString('unassigned', (string) $domlandItem->reason,
                'no constituent is stranded between frames');

            $this->assertSame(22, (int) DB::table('audit_log')
                ->where('event', 'district_map.generated')
                ->where('payload->generator', 'like', '%SweepScopeProcessor%')
                ->count(), 'EXACTLY one summary audit entry per completed sweep — never a flood, never silent');
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'autoscale.singles_generated')->count(),
                'EXACTLY one singles append per claimed batch (all fixture leaves fit one batch) — never per-row');
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'autoscale.completed')->count());
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'autoscale.sizing_completed')->count());
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'bootstrap_board_constituted')->count(),
                'the founding bootstrap board (R-08 substrate) is constituted exactly once');

            // ── Pin 7: ADOPT, never bulldoze — a requeued sweep item whose
            // legislature already has an ACTIVE map with districts is taken
            // as-is (the operator's accepted work is never archived by a
            // machine redraw). This is the dashboard's "retry" path: item
            // back to pending, stale scope tree dropped, run revived; the
            // pump re-mints the root scope and the first claim adopts.
            AutoscaleItem::query()->whereKey($pinlandItem->id)->update([
                'status' => 'pending', 'claim_token' => null, 'updated_at' => now(),
            ]);
            DB::table('autoscale_scopes')->where('item_id', $pinlandItem->id)->delete();
            $run->forceFill(['status' => 'mapping', 'finished_at' => null])->save();
            Artisan::call('autoscale:pump'); // re-mints the root scope
            (new AutoscaleWorkerJob((string) $run->id))->handle();
            Artisan::call('autoscale:pump');
            $pinlandItem->refresh();
            $this->assertSame('done', $pinlandItem->status);
            $this->assertStringContainsString('adopted', (string) $pinlandItem->reason,
                'a re-run over an activated map adopts it instead of re-sweeping');
            $this->assertSame('active', DB::table('legislature_district_maps')->where('id', $pinlandMap->id)->value('status'),
                'the previously activated map is untouched — not archived by a second founding sweep');
            $this->assertSame('done', AutoscaleRun::query()->find($run->id)->status,
                'the revived run re-completes through the same accounting');

            // ── Pin 7b: the DRIFT-REPAIR flag (operator ruling 2026-07-22,
            // no drift in seat counts) — an item requeued WITH
            // redraw_requested_at skips adoption for exactly ONE attempt and
            // redraws through the audited replace path; the flag is consumed
            // on completion so a later plain requeue adopts again. Direct
            // process() call: the pump/worker plumbing is pin 7's territory.
            AutoscaleItem::query()->whereKey($pinlandItem->id)->update([
                'status' => 'pending', 'claim_token' => null, 'reason' => null,
                'redraw_requested_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('autoscale_scopes')->where('item_id', $pinlandItem->id)->delete();
            $run->forceFill(['status' => 'mapping', 'finished_at' => null])->save();
            $repairScopeId = (string) Str::uuid();
            DB::table('autoscale_scopes')->insert([
                'id' => $repairScopeId, 'run_id' => $run->id, 'item_id' => $pinlandItem->id,
                'legislature_id' => $pinlandItem->legislature_id,
                'scope_jurisdiction_id' => $pinlandItem->jurisdiction_id,
                'depth' => 0, 'status' => 'running', 'claim_token' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(\App\Services\Autoscale\SweepScopeProcessor::class)->process($run->refresh(), [
                'scope_id'              => $repairScopeId,
                'item_id'               => (string) $pinlandItem->id,
                'legislature_id'        => (string) $pinlandItem->legislature_id,
                'scope_jurisdiction_id' => (string) $pinlandItem->jurisdiction_id,
            ]);
            // The item's terminal state (and the flag consumption inside
            // finishItem) lands in the FINALIZE rung, same as the worker
            // ladder runs it — the claim rung flips running → assessing
            // before handing the item to finalize.
            AutoscaleItem::query()->whereKey($pinlandItem->id)
                ->update(['status' => 'assessing', 'updated_at' => now()]);
            app(\App\Services\Autoscale\SweepScopeProcessor::class)->finalize($run->refresh(), (string) $pinlandItem->id);
            $pinlandItem->refresh();
            $this->assertStringNotContainsString('adopted', (string) $pinlandItem->reason,
                'a flagged repair item must REDRAW, never adopt the existing active map');
            $this->assertNull($pinlandItem->redraw_requested_at,
                'the repair flag is consumed by the attempt it triggered');
            $this->assertNotSame('pending', $pinlandItem->status,
                'the flagged repair attempt runs to a terminal state');
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

            // Sizing completes, mapping starts, root scopes minted.
            (new AutoscaleSizingJob((string) $run->id))->handle();
            $this->assertSame('mapping', $run->refresh()->status);
            $this->assertGreaterThan(0, DB::table('autoscale_scopes')
                ->where('run_id', $run->id)->where('status', 'pending')->count(),
                'root scopes are minted at enumeration');

            // HALT (DB column, pull engine): the pump parks the run; a
            // worker seeing a halted run exits at its claim boundary without
            // touching anything.
            $run->forceFill(['halt_requested_at' => now()])->save();
            Artisan::call('autoscale:pump');
            $this->assertSame('halted', $run->refresh()->status, 'the pump parks a halt-requested run');

            (new AutoscaleWorkerJob((string) $run->id))->handle();
            $this->assertSame(0, AutoscaleItem::query()->where('run_id', $run->id)->whereIn('status', ['done'])
                ->where('kind', 'sweep')->count(), 'no sweep ran during the halt');
            $this->assertSame(0, DB::table('autoscale_scopes')
                ->where('run_id', $run->id)->where('status', 'running')->count(),
                'no scope was claimed during the halt');

            // RESUME (the dashboard-button / accept-maps re-POST path):
            // clear the DB flag; the pump rewinds the phase; workers drain.
            $run->forceFill(['halt_requested_at' => null])->save();
            Artisan::call('autoscale:pump');
            $this->assertSame('mapping', $run->refresh()->status, 'resume rewinds to the interrupted phase');
            $this->driveRunFromMapping($run);

            $this->assertSame('done', $run->refresh()->status, 'a halted run resumes to completion — nothing is lost');
            $this->assertSame(2, (int) $run->review_count, 'Brokenland + the geometry-less ghost leaf');
        });
    }

    /** Worker+pump rounds only (sizing already done). */
    private function driveRunFromMapping(AutoscaleRun $run, int $maxRounds = 10): void
    {
        for ($round = 0; $round < $maxRounds; $round++) {
            (new AutoscaleWorkerJob((string) $run->id))->handle();
            Artisan::call('autoscale:pump');
            if (AutoscaleRun::query()->find($run->id)?->status === 'done') {
                break;
            }
        }
    }

    /**
     * PULL-ENGINE PINS (2026-07-19): the crash-recovery and iteration
     * machinery in one fixture pass —
     *  a. the pg-crash breaker pauses claims (pause-only, never a governor);
     *  b. a stale scope claim (dead worker) is reclaimed by the pump and the
     *     run still completes with exactly ONE active map per legislature;
     *  c. table-fed Step-7 adjacency is byte-identical to live compute
     *     (edge list AND order — the Draft-10 Ethiopia lottery clause);
     *  d. autoscale:revert rewinds to the mapping start (generated maps
     *     gone, sizing + boards + precompute kept, adopted maps untouched)
     *     and a re-run reaches the same lawful outcomes.
     */
    public function test_breaker_reclaim_adjacency_parity_and_revert_roundtrip(): void
    {
        $this->onLivePg(function (array $ctx) {
            Queue::fake();

            $run = AutoscaleRun::create([
                'status'            => 'queued',
                'adm_max'           => 6,
                'initiator_user_id' => $ctx['operator_id'],
                'template'          => null,
            ]);
            (new AutoscaleSizingJob((string) $run->id))->handle();
            $this->assertSame('mapping', $run->refresh()->status);

            // ── a. breaker: a changed pg fingerprint pauses claims ────────
            $run->forceFill(['pg_fingerprint' => 'bogus-previous-fingerprint'])->save();
            Artisan::call('autoscale:pump');
            $run->refresh();
            $this->assertNotNull($run->paused_until, 'a fingerprint change pauses claims');
            (new AutoscaleWorkerJob((string) $run->id))->handle();
            $this->assertSame(0, DB::table('autoscale_scopes')
                ->where('run_id', $run->id)->where('status', 'running')->count(),
                'a paused run hands out no claims');
            $this->assertSame(0, AutoscaleItem::query()->where('run_id', $run->id)
                ->where('kind', 'single')->where('status', 'running')->count(),
                'singles batches respect the pause too');
            $run->forceFill(['paused_until' => now()->subMinute()])->save();

            // ── b. stale-claim reclaim: claim a scope, "die", pump revives ─
            // Drain singles + precompute first so the ladder reaches scopes.
            $probeToken = (string) Str::uuid();
            $scopeClaim = null;
            for ($i = 0; $i < 50; $i++) {
                $claim = \App\Support\AutoscaleClaims::next($run, $probeToken);
                if ($claim === null) {
                    break;
                }
                if ($claim['type'] === 'scope') {
                    $scopeClaim = $claim;
                    break;
                }
                match ($claim['type']) {
                    'singles'    => app(\App\Services\Autoscale\SinglesBatchProcessor::class)->process($run, $probeToken),
                    'finalize'   => app(\App\Services\Autoscale\SweepScopeProcessor::class)->finalize($run, $claim['item_id']),
                    'precompute' => app(\App\Services\Autoscale\AdjacencyPrecompute::class)->processParent($claim['parent_id']),
                };
            }
            $this->assertNotNull($scopeClaim, 'the ladder reaches sweep scopes after singles + precompute drain');

            // The claiming worker "dies": nothing processes the scope; its
            // heartbeat goes stale; the pump reclaims it to pending.
            DB::table('autoscale_scopes')->where('id', $scopeClaim['scope_id'])
                ->update(['updated_at' => now()->subMinutes(45)]);
            Artisan::call('autoscale:pump');
            $this->assertSame('pending', DB::table('autoscale_scopes')->where('id', $scopeClaim['scope_id'])->value('status'),
                'the pump reclaims a stale scope claim in minutes — never hours, never a level');

            // Run to completion; the redo must be clean (exactly one active map).
            $this->driveRunFromMapping($run);
            $run->refresh();
            $this->assertSame('done', $run->status);
            $this->assertSame(2, (int) $run->review_count, 'Brokenland + the geometry-less ghost leaf');
            $doubleActive = DB::table('legislature_district_maps')
                ->select('legislature_id')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->groupBy('legislature_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();
            $this->assertSame(0, $doubleActive, 'reclaim + redo never double-mints an active map');

            // ── c. adjacency parity: table-fed Step 7 ≡ live compute ──────
            $childIds = DB::table('jurisdictions')
                ->where('parent_id', $ctx['pinland_id'])->whereNull('deleted_at')->whereNotNull('geom')
                ->pluck('id')->map(fn ($v) => (string) $v)->all();
            $idsStr = '{' . implode(',', $childIds) . '}';
            $this->assertTrue(app(\App\Services\Autoscale\AdjacencyPrecompute::class)->isDone($ctx['pinland_id']),
                'the precompute pass materialized Pinland');
            $fromTable = DB::select("
                SELECT j1, j2, round(border_len::numeric, 6) AS bl FROM jurisdiction_adjacency
                 WHERE parent_id = :p AND dim >= 1 AND j1 = ANY(:ids::uuid[]) AND j2 = ANY(:ids2::uuid[])
                 ORDER BY j1, j2
            ", ['p' => $ctx['pinland_id'], 'ids' => $idsStr, 'ids2' => $idsStr]);
            $live = DB::select("
                WITH g AS (
                    SELECT id,
                           CASE WHEN ST_NPoints(geom) > 1000000 THEN ST_MakeValid(ST_Simplify(geom, 0.01))
                                WHEN ST_NPoints(geom) > 50000  THEN ST_MakeValid(ST_Simplify(geom, 0.001))
                                ELSE geom END AS geom
                    FROM jurisdictions
                    WHERE id = ANY(:ids::uuid[]) AND deleted_at IS NULL AND geom IS NOT NULL
                ),
                pair AS (
                    SELECT a.id AS j1, b.id AS j2, ST_Intersection(a.geom, b.geom) AS ix
                    FROM g a JOIN g b ON a.id < b.id AND a.geom && b.geom AND ST_Intersects(a.geom, b.geom)
                )
                SELECT j1, j2, round(ST_Length(ST_CollectionExtract(ix, 2))::numeric, 6) AS bl
                FROM pair WHERE ST_Dimension(ix) >= 1 ORDER BY j1, j2
            ", ['ids' => $idsStr]);
            $this->assertSame(
                array_map(fn ($e) => [(string) $e->j1, (string) $e->j2, (string) $e->bl], $live),
                array_map(fn ($e) => [(string) $e->j1, (string) $e->j2, (string) $e->bl], $fromTable),
                'the precomputed adjacency is byte-identical to live Step-7 compute — edges, lengths, AND order'
            );

            // ── d. revert round-trip ──────────────────────────────────────
            $mapsBefore = (int) DB::table('legislature_district_maps')
                ->where('description', 'like', 'Auto-generated by full-scale autoscale%')
                ->whereNull('deleted_at')->count();
            $this->assertGreaterThan(0, $mapsBefore);
            $adjacencyRows = (int) DB::table('jurisdiction_adjacency')->count();
            $legislaturesBefore = (int) DB::table('legislatures')->whereNull('deleted_at')->count();

            Artisan::call('autoscale:revert');
            $run->refresh();
            $this->assertSame('halted', $run->status, 'revert parks the run for a deliberate resume');
            $this->assertSame(0, (int) DB::table('legislature_district_maps')
                ->where('description', 'like', 'Auto-generated by full-scale autoscale%')
                ->where('status', '!=', 'draft')
                ->whereNull('deleted_at')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('legislature_districts')
                    ->whereColumn('legislature_districts.map_id', 'legislature_district_maps.id'))
                ->count(), 'generated mapping output is gone');
            $this->assertSame($legislaturesBefore, (int) DB::table('legislatures')->whereNull('deleted_at')->count(),
                'sizing survives the revert — legislatures are never touched');
            $this->assertSame($adjacencyRows, (int) DB::table('jurisdiction_adjacency')->count(),
                'the precompute tables survive the revert (paid once per geometry, not per attempt)');
            $this->assertSame(1, (int) DB::table('audit_log')->where('event', 'autoscale.reverted')->count());

            // Resume and re-run: same lawful outcomes, no duplicate maps.
            $run->forceFill(['halt_requested_at' => null])->save();
            Artisan::call('autoscale:pump');
            $this->driveRunFromMapping($run);
            $run->refresh();
            $this->assertSame('done', $run->status, 'the reverted run re-completes');
            $this->assertSame(2, (int) $run->review_count, 'the same lawful review outcomes');
            $pinlandLegId = DB::table('legislatures')->where('jurisdiction_id', $ctx['pinland_id'])->whereNull('deleted_at')->value('id');
            $pinlandItem = AutoscaleItem::query()->where('run_id', $run->id)->where('legislature_id', $pinlandLegId)->first();
            $this->assertSame(1, (int) $pinlandItem->drift, 'the honest +1 drift reproduces after revert (determinism)');
            $doubleActive = DB::table('legislature_district_maps')
                ->select('legislature_id')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->groupBy('legislature_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();
            $this->assertSame(0, $doubleActive, 'revert + re-run never double-mints an active map');
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
     *   ├── Brokenland (adm1, 18 000, ISO ZZB — NO raster anywhere)
     *   │   ├── Broken Quarters 1–2 (adm2, 3 000 each, childless)
     *   │   ├── Broken Giant Strip (adm2, 12 000, childless, NO raster)
     *   │   │   → cycle-2: line-splits via the AREA-PROPORTIONAL fallback
     *   │   ├── Pin Hamlet (adm2, 400, childless) → the IN-BAND single
     *   │   └── Broken Ghost (adm2, 12 000, GEOMETRY-LESS) → honest review
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

        $brokenlandId = $mkJur('Brokenland', $earthId, 1, 18000, [20.0, 50.0, 20.5, 50.2], self::ISO_BROKEN);
        $brokenQuarters = [];
        $brokenQuarters['Broken Quarter 1'] = $mkJur('Broken Quarter 1', $brokenlandId, 2, 3000, [20.0, 50.1, 20.2, 50.2], self::ISO_BROKEN);
        $brokenQuarters['Broken Quarter 2'] = $mkJur('Broken Quarter 2', $brokenlandId, 2, 3000, [20.2, 50.1, 20.4, 50.2], self::ISO_BROKEN);
        $brokenGiantId = $mkJur('Broken Giant Strip', $brokenlandId, 2, 12000, [20.0, 50.0, 20.4, 50.1], self::ISO_BROKEN);

        // Cycle-2 fixtures: under the UNCLAMPED leaf law every other leaf in
        // this fixture is over-ceiling, so the hamlet (pop 400 → 7 seats) is
        // the one IN-BAND single — the at-large shape's pin. The ghost is
        // the honest-review class: a GEOMETRY-LESS giant (pop 12,000 → its
        // parent's composite can't place it, no line can cut it) — it flags
        // Brokenland AND its own leaf item, and nothing can rescue it.
        $hamletId = $mkJur('Pin Hamlet', $brokenlandId, 2, 400, [20.4, 50.1, 20.5, 50.2], self::ISO_BROKEN);
        $ghostId = (string) Str::uuid();
        DB::insert(
            "INSERT INTO jurisdictions
                (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                 source, official_languages, timezone, geom, centroid, created_at, updated_at)
             VALUES
                (?, 'Broken Ghost', ?, ?, 2, ?, 12000, true, true, 'pin-fixture', '[]', 'UTC',
                 NULL, NULL, ?, ?)",
            [$ghostId, 'zz-2-broken-ghost-'.substr($ghostId, 0, 8), self::ISO_BROKEN, $brokenlandId, $now, $now]
        );

        $midgets = [];
        $midgets['Pin Midget A'] = $mkJur('Pin Midget A', $earthId, 1, 5000, [15.0, 50.0, 15.2, 50.2], self::ISO_OK);
        $midgets['Pin Midget B'] = $mkJur('Pin Midget B', $earthId, 1, 5000, [15.2, 50.0, 15.4, 50.2], self::ISO_OK);

        // Domland — THE ONE-FRAME PIN (the Saint-Pierre/Kozhikode shape):
        // Dom Province (96k, giant → its own scope, cascade budget ~44) has
        // a COVERAGE GAP (children sum 20k), so its local quota is
        // 20k/44 ≈ 455 — Dom Core (19k) is a LOCAL giant (frac ≈ 41.8)
        // while far below the ROOT frame's threshold (19k×46/100k ≈ 8.7 <
        // 9.5). The old root-flat-share walk gave it NO scope: a giant to
        // the composite, invisible to the scope list, territory stranded.
        // Under the one-frame law it is a childless line-split scope with
        // the cascade's budget.
        $domlandId = $mkJur('Domland', $earthId, 1, 100000, [70.0, 50.0, 71.0, 51.0], self::ISO_OK);
        $domProvinceId = $mkJur('Dom Province', $domlandId, 2, 96000, [70.0, 50.0, 70.5, 51.0], self::ISO_OK);
        $domCoreId = $mkJur('Dom Core', $domProvinceId, 3, 19000, [70.0, 50.0, 70.2, 50.1], self::ISO_OK);
        $domLeaves = [];
        $domLeaves['Dom Edge'] = $mkJur('Dom Edge', $domProvinceId, 3, 1000, [70.3, 50.5, 70.35, 50.55], self::ISO_OK);
        $domLeaves['Dom Isle A'] = $mkJur('Dom Isle A', $domlandId, 2, 2000, [70.6, 50.0, 70.8, 50.2], self::ISO_OK);
        $domLeaves['Dom Isle B'] = $mkJur('Dom Isle B', $domlandId, 2, 2000, [70.8, 50.0, 71.0, 50.2], self::ISO_OK);
        $domLeaves['Dom Core'] = $domCoreId;

        // Synthetic WorldPop tiles (ZZA): the Pinland giant strip (80×20
        // cells of 0.005°, 7.5 each → exactly 12 000) AND Dom Core (40×20
        // cells, 23.75 each → exactly 19 000) so both line splits run for
        // real. ZZB has NO raster — its line split must refuse, engineering
        // the review case.
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(80, 20, 10.0, 50.1, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, 7.5, NULL),
                     ?)",
            [(string) Str::uuid(), self::ISO_OK, $now]
        );
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(40, 20, 70.0, 50.1, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, 23.75, NULL),
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
            'earth_id'        => $earthId,
            'pinland_id'      => $pinlandId,
            'brokenland_id'   => $brokenlandId,
            'quarter1_id'     => $quarter1Id,
            'pin_giant_id'    => $pinGiantId,
            'broken_giant_id' => $brokenGiantId,
            'broken_ghost_id' => $ghostId,
            'hamlet_id'       => $hamletId,
            'domland_id'      => $domlandId,
            'dom_core_id'     => $domCoreId,
            'operator_id'     => $operatorId,
            'parents'         => [
                'Pin Earth'     => $earthId,
                'Pinland'       => $pinlandId,
                'Brokenland'    => $brokenlandId,
                'Pin Quarter 1' => $quarter1Id,
                'Domland'       => $domlandId,
                'Dom Province'  => $domProvinceId,
            ],
            // ALL childless jurisdictions (19). Under the unclamped law only
            // the hamlet is in-band; the other 18 line-split themselves.
            'leaves'          => $quarters + $brokenQuarters + $midgets + $villages + $domLeaves + [
                'Pin Giant Strip'    => $pinGiantId,
                'Broken Giant Strip' => $brokenGiantId,
                'Pin Hamlet'         => $hamletId,
                'Broken Ghost'       => $ghostId,
            ],
            'single_leaves'   => [
                'Pin Hamlet' => $hamletId,
            ],
        ];
    }
}
