<?php

namespace Tests\Constitutional;

use App\Models\ElectionRace;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\ElectionLifecycleService;
use App\Services\Legislature\TypeBDistrictMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE TYPE B MAPPER, END TO END — a flagged chamber is grouped, un-flagged, and
 * its Type B race becomes schedulable IN THE CORRECT SHAPE. This is the whole
 * point of stage two: the R-A guard (ElectionLifecycleService) blocks the Type B
 * half while type_b_needs_districting is set; applying an ACTIVE grouping clears
 * the flag and racePlan emits one at-large race PER CLUMP — a panel each, keyed
 * by type_b_panel_id (operator ruling 2026-07-29, one at-large race per clump,
 * NEVER one pooled race).
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
            $this->assertSame(4, $result['seats'], '2 x 2 = 4 <= bound 5; the odd spare seat is unused, not a bonus');
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
            $this->assertSame(4, (int) $leg->type_b_seats, 'type_b recomputed to Σ panel seats = p x rep_floor');
            $this->assertSame(9, (int) $leg->total_seats, 'type_a 5 + type_b 4');
            $this->assertLessThanOrEqual((int) $leg->type_a_seats, (int) $leg->type_b_seats,
                'grouped Type B no longer exceeds Type A');

            // AFTER: the R-A guard un-blocks — the Type B half is PER-CLUMP.
            $after = app(ElectionLifecycleService::class)->racePlan(Legislature::find($legId));
            $this->assertSame('panels', $after['kinds']['type_b']['mode'] ?? null,
                'the instant the flag clears, the Type B half elects one race per clump');
            $panels = collect($after['kinds']['type_b']['panels']);
            $this->assertCount(2, $panels, 'one race per panel — 2 panels');
            $this->assertSame([2, 2], $panels->map(fn ($p) => (int) $p->seats)->all(),
                'every panel elects rep_floor (2) — no bonus seat');

            // createRaces materialises exactly ONE at-large race per panel, each
            // carrying its type_b_panel_id (the clump key) — never one pooled race.
            $election = app(ElectionLifecycleService::class)->scheduleGeneral(Legislature::find($legId));
            $typeBRaces = DB::table('election_races')
                ->where('election_id', $election->id)->where('seat_kind', 'type_b')->get();
            $this->assertCount(2, $typeBRaces, 'two per-clump at-large races, not one pooled race');
            $this->assertSame(0, $typeBRaces->whereNull('type_b_panel_id')->count(),
                'every per-clump race carries its panel key');
            $this->assertSame(4, (int) $typeBRaces->sum('seats'), 'sum of per-clump seats = the grouped total (p x rep_floor)');
            $this->assertSame(0, $typeBRaces->where('district_id', '!=', null)->count(),
                'per-clump races are at-large (no district_id)');
        });
    }

    /**
     * B6 — the grouping NEVER crosses the parent. A second parent's children
     * (and a mischievous cross-parent adjacency row) must not leak into the
     * grouped chamber; apply() scopes children and adjacency to one parent.
     */
    public function test_grouping_never_crosses_the_parent(): void
    {
        $this->onLivePg(function (): void {
            $tag = 'tbtest-' . Str::lower(Str::random(6));
            $legId = $this->seedFlagged($tag, 8, 5, $childIds);

            // A foreign parent + child, and a cross-parent adjacency row keyed
            // (illegally) under our parent — apply() must ignore both.
            $foreignParent = (string) Str::uuid();
            $foreignChild  = (string) Str::uuid();
            DB::table('jurisdictions')->insert([
                'id' => $foreignParent, 'name' => "{$tag}-fp", 'slug' => "{$tag}-fp",
                'adm_level' => 1, 'population' => 50, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('jurisdictions')->insert([
                'id' => $foreignChild, 'name' => "{$tag}-fc", 'slug' => "{$tag}-fc",
                'parent_id' => $foreignParent, 'adm_level' => 2, 'population' => 25,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $ourParent = DB::table('legislatures')->where('id', $legId)->value('jurisdiction_id');
            DB::table('jurisdiction_adjacency')->insert([
                'parent_id' => $ourParent, 'j1' => $childIds[0], 'j2' => $foreignChild,
                'dim' => 1, 'border_len' => 99.0, 'computed_at' => now(),
            ]);

            (new TypeBDistrictMapper())->apply($legId);

            $memberParents = DB::table('legislature_type_b_panel_jurisdictions as m')
                ->join('legislature_type_b_panels as p', 'p.id', '=', 'm.panel_id')
                ->join('jurisdictions as j', 'j.id', '=', 'm.jurisdiction_id')
                ->where('p.legislature_id', $legId)
                ->distinct()->pluck('j.parent_id');

            $this->assertSame([$ourParent], $memberParents->all(),
                'every panel member is a direct child of the grouped parent — never cross-parent');
        });
    }

    /**
     * B7 — a grouping is VERSIONED. Re-applying an active plan archives the
     * prior active one (≤1 active per legislature, DB-enforced); a draft plan
     * coexists with the active one (the next-term seam — sitting members serve
     * out while the redraw waits).
     */
    public function test_versioning_one_active_and_drafts_coexist(): void
    {
        $this->onLivePg(function (): void {
            $tag = 'tbtest-' . Str::lower(Str::random(6));
            $legId = $this->seedFlagged($tag, 8, 5, $childIds);
            $mapper = new TypeBDistrictMapper();

            $mapper->apply($legId, 'active');
            $mapper->apply($legId, 'active'); // re-apply: prior must archive

            $this->assertSame(1, DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'active')->count(),
                'exactly one active grouping survives a re-apply');
            $this->assertSame(1, DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'archived')->count(),
                'the prior active plan is archived, not deleted (history preserved)');

            $mapper->apply($legId, 'draft'); // next-term plan coexists
            $this->assertSame(1, DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'active')->count(),
                'the draft does not disturb the sitting active plan');
            $this->assertSame(1, DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'draft')->count());
        });
    }

    /**
     * B7 — a DRAFT plan does NOT disturb the sitting chamber. apply(status=draft)
     * persists a draft grouping but must leave type_b_needs_districting set and
     * the legislature's seats untouched — sitting members serve out; the draft
     * seats only when it is later activated. (Regression: the legislatures update
     * ran unconditionally, so a draft cleared the flag and resized this term.)
     */
    public function test_a_draft_grouping_does_not_disturb_the_sitting_chamber(): void
    {
        $this->onLivePg(function (): void {
            $tag = 'tbtest-' . Str::lower(Str::random(6));
            $legId = $this->seedFlagged($tag, 8, 5, $childIds);

            (new TypeBDistrictMapper())->apply($legId, 'draft');

            // The draft grouping persists; no active grouping is created.
            $this->assertSame(1, DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'draft')->count());
            $this->assertSame(0, DB::table('legislature_type_b_groupings')
                ->where('legislature_id', $legId)->where('status', 'active')->count());

            // The sitting chamber is UNTOUCHED — still flagged, still 16 seats.
            $leg = DB::table('legislatures')->where('id', $legId)->first();
            $this->assertTrue((bool) $leg->type_b_needs_districting, 'a draft does NOT clear the flag');
            $this->assertSame(16, (int) $leg->type_b_seats, 'a draft does NOT resize the sitting chamber');
        });
    }

    /**
     * DRIFT + R-A HARDENING (2026-07-29 adversarial pass, MED). racePlan reads the
     * active grouping, but a re-seed (ApportionmentSeedCommand's $existing branch)
     * resizes / re-flags type_b_seats WITHOUT touching the grouping trio, so the
     * two diverge. A STALE grouping — re-flagged OR seats_total ≠ type_b_seats —
     * must BLOCK: emitting its races would seat Σ seats ≠ type_b_seats (DRIFT, always
     * wrong) and silently bypass the R-A guard. Only a CURRENT grouping elects.
     */
    public function test_a_stale_grouping_blocks_instead_of_drifting(): void
    {
        $this->onLivePg(function (): void {
            $tag = 'tbtest-' . Str::lower(Str::random(6));
            $legId = $this->seedFlagged($tag, 8, 5, $childIds);
            (new TypeBDistrictMapper())->apply($legId, 'active'); // flag cleared, type_b_seats=4, grouping seats_total=4

            $svc = app(ElectionLifecycleService::class);

            // Baseline: a CURRENT grouping elects per clump.
            $this->assertSame('panels', $svc->racePlan(Legislature::find($legId))['kinds']['type_b']['mode'] ?? null,
                'a current grouping elects per clump');

            // A re-seed re-flags + re-sizes the Legislature column, leaving the
            // grouping (seats_total 4) stale against type_b_seats 16.
            DB::table('legislatures')->where('id', $legId)
                ->update(['type_b_seats' => 16, 'type_b_needs_districting' => true]);
            $reflagged = $svc->racePlan(Legislature::find($legId));
            $this->assertSame('blocked', $reflagged['kinds']['type_b']['mode'] ?? null,
                're-flagged: the stale grouping must NOT elect (would drift + bypass R-A)');
            $this->assertStringContainsString('stale', $reflagged['kinds']['type_b']['reason']);

            // Even with the flag CLEARED, a seats_total ≠ type_b_seats divergence
            // is drift and must block.
            DB::table('legislatures')->where('id', $legId)
                ->update(['type_b_seats' => 7, 'type_b_needs_districting' => false]);
            $diverged = $svc->racePlan(Legislature::find($legId));
            $this->assertSame('blocked', $diverged['kinds']['type_b']['mode'] ?? null,
                'seats_total (4) != type_b_seats (7) is drift — block until re-grouped');
        });
    }

    /**
     * BY-ELECTION SCOPING (2026-07-29 adversarial pass, HIGH). A Type B PANEL seat's
     * special election must stay within THAT panel. scheduleSpecial must copy
     * type_b_panel_id from the vacated seat's race — drop it and RaceFootprint's
     * COALESCE falls through to the parent jurisdiction, so the WHOLE parent votes
     * (the pooled electorate the per-clump fix removed) and per-panel attribution
     * is lost.
     */
    public function test_a_type_b_panel_by_election_stays_within_its_panel(): void
    {
        $this->onLivePg(function (): void {
            $tag = 'tbtest-' . Str::lower(Str::random(6));
            $legId = $this->seedFlagged($tag, 8, 5, $childIds);
            (new TypeBDistrictMapper())->apply($legId, 'active');

            $svc = app(ElectionLifecycleService::class);
            $election = $svc->scheduleGeneral(Legislature::find($legId));

            $panelRace = ElectionRace::query()
                ->where('election_id', $election->id)
                ->where('seat_kind', 'type_b')
                ->whereNotNull('type_b_panel_id')
                ->first();
            $this->assertNotNull($panelRace, 'a per-clump panel race exists to vacate');

            $user = User::create([
                'name' => 'Panel Rep ' . Str::uuid(),
                'email' => 'panel-rep-' . Str::uuid() . '@test.invalid',
                'password' => Str::random(32),
                'terms_accepted_at' => now(),
            ]);
            $member = LegislatureMember::create([
                'legislature_id'     => $legId,
                'user_id'            => (string) $user->id,
                'seat_type'          => 'b',
                'seat_no'            => 1,
                'status'             => LegislatureMember::STATUS_SEATED,
                'elected_in_race_id' => $panelRace->id,
                'term_ends_on'       => now()->addYears(5)->toDateString(),
            ]);

            $vacancy = Vacancy::create([
                'seat_type'       => 'legislature_members',
                'seat_id'         => (string) $member->id,
                'legislature_id'  => $legId,
                'jurisdiction_id' => DB::table('legislatures')->where('id', $legId)->value('jurisdiction_id'),
                'status'          => Vacancy::STATUS_COUNTBACK_FAILED,
                'detected_at'     => now(),
                'declared_at'     => now(),
            ]);

            $special = $svc->scheduleSpecial($vacancy, null, true); // forced: skip the window check
            $specialRace = ElectionRace::query()->where('election_id', $special->id)->first();

            $this->assertNotNull($specialRace, 'the by-election has a race');
            $this->assertNotNull($specialRace->type_b_panel_id, 'never a bare parent-wide at-large by-election');
            $this->assertSame((string) $panelRace->type_b_panel_id, (string) $specialRace->type_b_panel_id,
                'the by-election carries the vacated seat panel key — it stays within the panel');
            $this->assertNull($specialRace->district_id, 'a Type B by-election is at-large within its clump');
        });
    }

    /**
     * Seed a flagged chamber: parent + $n inhabited children (pop 10) in a line,
     * Type A $typeA, type_b flagged. Returns the legislature id; fills $childIds.
     *
     * @param list<string>|null $childIds
     */
    private function seedFlagged(string $tag, int $n, int $typeA, ?array &$childIds = null): string
    {
        $parentId = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $parentId, 'name' => "{$tag}-parent", 'slug' => "{$tag}-parent",
            'adm_level' => 1, 'population' => $n * 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $childIds = [];
        for ($i = 0; $i < $n; $i++) {
            $cid = (string) Str::uuid();
            $childIds[$i] = $cid;
            DB::table('jurisdictions')->insert([
                'id' => $cid, 'name' => "{$tag}-c{$i}", 'slug' => "{$tag}-c{$i}",
                'parent_id' => $parentId, 'adm_level' => 2, 'population' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        for ($i = 0; $i < $n - 1; $i++) {
            DB::table('jurisdiction_adjacency')->insert([
                'parent_id' => $parentId, 'j1' => $childIds[$i], 'j2' => $childIds[$i + 1],
                'dim' => 1, 'border_len' => 1.0, 'computed_at' => now(),
            ]);
        }

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $parentId, 'term_number' => 1,
            'status' => 'forming', 'total_seats' => 21, 'type_a_seats' => $typeA,
            'type_b_seats' => $n * 2, 'type_b_rep_floor' => 2, 'type_b_needs_districting' => true,
            'quorum_required' => 11, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $legId;
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
