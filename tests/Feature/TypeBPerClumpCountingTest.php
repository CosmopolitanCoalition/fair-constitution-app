<?php

namespace Tests\Feature;

use App\Models\Candidacy;
use App\Services\Demo\Stages\CountingStage;
use App\Services\Demo\Stages\SeatingStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — per-CLUMP Type B COUNTING + SEATING (W4 ①, operator
 * ruling 2026-07-29). A grouped Type B chamber elects one at-large race PER
 * CLUMP, each from its OWN panel's residents — never one pooled race over the
 * whole parent.
 *
 * COUNTING (CountingStage::electorateFor): a per-clump race (election_races.
 * type_b_panel_id, set by createRaces c500a1f, jurisdiction_id = the PARENT) is
 * scoped to the UNION of the panel's member jurisdictions — a PAIR counts its
 * two children, a SINGLE counts one, NEVER the parent's whole roll (which the
 * old at-large branch would return, N-folding the count).
 *
 * SEATING (CertificationService): N per-clump races each restart RaceResult.
 * seat_no at 1, and a type_b member carries no district_id — so type_b seats are
 * re-sequenced CHAMBER-WIDE, or a vacancy/countback seat lookup is ambiguous.
 *
 * Race SHAPE + RaceFootprint are lane 1's; this is the demo count/seat consuming
 * them. Live-pg + rolled-back tx.
 */
class TypeBPerClumpCountingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_typeb_clump';

    public function test_a_per_clump_race_counts_its_panel_not_the_whole_parent(): void
    {
        $this->onLivePg(function () {
            ['electionId' => $electionId, 'r1' => $r1, 'r2' => $r2] = $this->seedGroupedElection();

            $result = CountingStage::run($electionId, null, 1);

            $this->assertSame(2, $result['races'], 'two per-clump races counted');
            $this->assertSame(4, $result['seats'], '2 + 2 seats');

            $tv1 = (int) DB::table('tabulations')->where('race_id', $r1)->value('total_valid');
            $tv2 = (int) DB::table('tabulations')->where('race_id', $r2)->value('total_valid');

            $this->assertSame(200, $tv1, 'the PAIR panel counts its two children (100 + 100)');
            $this->assertSame(100, $tv2, 'the SINGLE panel counts its one child (100)');
            $this->assertNotSame(10_000, $tv1, 'a per-clump race NEVER draws the whole parent roll (no N-fold)');
        });
    }

    public function test_per_clump_type_b_seats_are_numbered_chamber_wide(): void
    {
        $this->onLivePg(function () {
            ['electionId' => $electionId] = $this->seedGroupedElection();

            CountingStage::run($electionId, null, 1);
            $seating = SeatingStage::run($electionId, null, 1);

            $this->assertTrue($seating['certified'], "certified: {$seating['skipped']}");
            $this->assertSame(4, $seating['seated'], '2 panels × 2 seats seated');

            // The four type_b seats are numbered 1..4 across the two races — NOT
            // two colliding pairs of (1,2). elected_in_race_id resolves which
            // panel each seat represents; no new column needed.
            $seatNos = DB::table('legislature_members')
                ->where('election_id', $electionId)
                ->where('seat_type', 'b')
                ->orderBy('seat_no')
                ->pluck('seat_no')
                ->map(fn ($n) => (int) $n)
                ->all();

            $this->assertSame([1, 2, 3, 4], $seatNos, 'type_b seats are chamber-unique, not per-race collisions');

            // The four seats attribute to their TWO panel races (per-race, not
            // pooled) — elected_in_race_id → race → panel is the read-time link.
            $this->assertSame(2, (int) DB::table('legislature_members')
                ->where('election_id', $electionId)->where('seat_type', 'b')
                ->distinct()->count('elected_in_race_id'),
                'the four seats attribute to their two panel races');
        });
    }

    // ── fixture ─────────────────────────────────────────────────────────────

    /**
     * A grouped Type B chamber: a PAIR panel [c1, c2] and a SINGLE panel [c3],
     * two per-clump races of 2 seats each, plus the bootstrap board the F-ELB-004
     * seating certifies through.
     *
     * @return array{parent: string, electionId: string, r1: string, r2: string}
     */
    private function seedGroupedElection(): array
    {
        $parent = $this->jurisdiction('Clump Parent', 10_000);
        $c1 = $this->jurisdiction('Child One', 1_000);
        $c2 = $this->jurisdiction('Child Two', 1_000);
        $c3 = $this->jurisdiction('Child Three', 1_000);

        $this->cohort($parent, 10_000);
        $this->cohort($c1, 100);
        $this->cohort($c2, 100);
        $this->cohort($c3, 100);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $parent, 'term_number' => 1,
            'status' => 'forming', 'total_seats' => 15, 'type_a_seats' => 11,
            'type_b_seats' => 4, 'quorum_required' => 8,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $groupingId = (string) Str::uuid();
        DB::table('legislature_type_b_groupings')->insert([
            'id' => $groupingId, 'legislature_id' => $legId, 'status' => 'active',
            'rep_floor' => 2, 'group_size' => 2, 'panel_count' => 2, 'seats_total' => 4,
            'type_a_bound' => 11, 'tie_break_key' => 'max_internal_border_len',
            'signature' => 'pin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $p1 = $this->panel($groupingId, $legId, 1, [$c1, $c2]);
        $p2 = $this->panel($groupingId, $legId, 2, [$c3]);

        $boardId = (string) Str::uuid();
        DB::table('election_boards')->insert([
            'id' => $boardId, 'jurisdiction_id' => $parent,
            'is_bootstrap' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The bootstrap board's synthetic system member (user_id NULL) — F-ELB-004
        // certification files through it (BoardProvenance::resolveMemberOnBoard).
        DB::table('election_board_members')->insert([
            'id' => (string) Str::uuid(), 'election_board_id' => $boardId,
            'user_id' => null, 'status' => 'seated',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $electionId = (string) Str::uuid();
        DB::table('elections')->insert([
            'id' => $electionId, 'jurisdiction_id' => $parent, 'legislature_id' => $legId,
            'status' => 'tabulating', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r1 = $this->perClumpRace($electionId, $parent, $p1);
        $r2 = $this->perClumpRace($electionId, $parent, $p2);

        return ['parent' => $parent, 'electionId' => $electionId, 'r1' => $r1, 'r2' => $r2];
    }

    private function jurisdiction(string $name, int $pop): string
    {
        $id = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $id, 'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'adm_level' => 3, 'population' => $pop, 'source' => 'user_defined',
            'official_languages' => '["en"]', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function cohort(string $jid, int $electorate): void
    {
        DB::table('jurisdiction_cohorts')->insert([
            'id' => (string) Str::uuid(), 'jurisdiction_id' => $jid, 'version' => 1,
            'seed' => substr(hash('sha256', $jid), 0, 32), 'population' => $electorate * 2,
            'electorate' => $electorate, 'turnout_pct' => 50, 'archetypes' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<string> $members */
    private function panel(string $groupingId, string $legId, int $number, array $members): string
    {
        $pid = (string) Str::uuid();
        DB::table('legislature_type_b_panels')->insert([
            'id' => $pid, 'grouping_id' => $groupingId, 'legislature_id' => $legId,
            'panel_number' => $number, 'seats' => 2, 'member_count' => count($members),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($members as $jid) {
            DB::table('legislature_type_b_panel_jurisdictions')->insert([
                'id' => (string) Str::uuid(), 'panel_id' => $pid,
                'grouping_id' => $groupingId, 'jurisdiction_id' => $jid,
            ]);
        }

        return $pid;
    }

    private function perClumpRace(string $electionId, string $parentJid, string $panelId): string
    {
        $rid = (string) Str::uuid();
        DB::table('election_races')->insert([
            'id' => $rid, 'election_id' => $electionId, 'district_id' => null,
            'type_b_panel_id' => $panelId, 'jurisdiction_id' => $parentJid,
            'seat_kind' => 'type_b', 'seats' => 2, 'finalist_count' => 3,
            'status' => 'ranked_open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            DB::table('candidacies')->insert([
                'id' => (string) Str::uuid(), 'election_id' => $electionId, 'race_id' => $rid,
                'user_id' => $this->user(), 'status' => Candidacy::STATUS_FINALIST,
                'residency_attested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $rid;
    }

    private function user(): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id, 'name' => 'Cand '.Str::random(6),
            'email' => 'cand-'.Str::uuid().'@test.invalid', 'password' => Str::random(20),
            'terms_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
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
