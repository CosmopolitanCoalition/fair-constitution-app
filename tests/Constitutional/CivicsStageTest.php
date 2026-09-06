<?php

namespace Tests\Constitutional;

use App\Models\Organization;
use App\Services\Demo\Stages\CivicsStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the CIVICS stage (census-flavored orgs, operator ruling
 * 2026-08-08, rubric sim-org-bill-rates = B).
 *
 * This is a smoke of the stage's leaf path: a populated leaf mints sampled
 * businesses and nonprofits, and the heartbeat closure fires while it does.
 * The heartbeat is not decoration — a large leaf's org loop is a long item, and
 * without the beat the lease would expire and the item be double-dispatched
 * (W7 item 1). The beat was wired into `mintOrgs` but for a time never received
 * the closure, so the loop referenced an undefined variable and threw at
 * runtime; this pins that it is threaded end to end.
 */
class CivicsStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_civicsstage';

    /** A populated leaf mints sampled orgs, and the heartbeat fires as it does. */
    public function test_a_populated_leaf_mints_orgs_and_fires_the_heartbeat(): void
    {
        $this->onLivePg(function () {
            $jid = $this->leaf(500_000);

            $beats = 0;
            $result = CivicsStage::run($jid, null, 1, function () use (&$beats) {
                $beats++;
            });

            // The leaf path mints sampled businesses and nonprofits (people live
            // at leaves; true counts ride the metrics, sampled rows land).
            $this->assertGreaterThan(0, $result['businesses']['minted'] ?? 0, 'a populated leaf mints businesses');
            $this->assertGreaterThan(
                0,
                DB::table('organizations')
                    ->where('jurisdiction_id', $jid)
                    ->where('type', Organization::TYPE_BUSINESS)
                    ->count(),
                'the sampled business rows landed'
            );

            $this->assertGreaterThan(0, $beats, 'the heartbeat closure fired during the org loop');
        });
    }

    /** Re-running mints no duplicates — the sampled count is a ceiling, not an addend. */
    public function test_re_running_does_not_double_mint(): void
    {
        $this->onLivePg(function () {
            $jid = $this->leaf(500_000);

            CivicsStage::run($jid, null, 1);
            $after1 = DB::table('organizations')->where('jurisdiction_id', $jid)->count();

            CivicsStage::run($jid, null, 1);
            $after2 = DB::table('organizations')->where('jurisdiction_id', $jid)->count();

            $this->assertSame($after1, $after2, 'the second pass tops up to the same ceiling, never doubles');
        });
    }

    /**
     * ENDORSEMENTS (W7 item 9): a MIX of endorsers — organizations of any type
     * AND individual residents — back candidates in the open election.
     * Endorsement is polymorphic (partisanship is mooted under polymorphic STV),
     * so the graph is never a party-only slate. Idempotent.
     */
    public function test_a_mix_of_endorsers_back_candidates_in_the_open_election(): void
    {
        $this->onLivePg(function () {
            $jid = $this->leaf(500_000);

            // A seated chamber (so parties mint) and an open election with two
            // candidates (so there is something to endorse).
            $legId = (string) Str::uuid();
            DB::table('legislatures')->insert([
                'id' => $legId, 'jurisdiction_id' => $jid, 'term_number' => 1, 'status' => 'active',
                'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $memberUser = (string) Str::uuid();
            DB::table('users')->insert([
                'id' => $memberUser, 'name' => 'Seated', 'email' => 'sim-'.Str::lower(Str::random(10)).'@demo.invalid',
                'password' => bcrypt(Str::random(20)), 'status' => 'registered', 'terms_accepted_at' => now(),
                'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('legislature_members')->insert([
                'id' => (string) Str::uuid(), 'legislature_id' => $legId, 'user_id' => $memberUser,
                'seat_type' => 'a', 'seat_no' => 1, 'status' => 'elected', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $electionId = (string) Str::uuid();
            DB::table('elections')->insert([
                'id' => $electionId, 'jurisdiction_id' => $jid, 'legislature_id' => $legId,
                'kind' => 'general', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['cand-a', 'cand-b'] as $i => $tag) {
                $u = (string) Str::uuid();
                DB::table('users')->insert([
                    'id' => $u, 'name' => "Candidate {$i}", 'email' => 'sim-'.Str::lower(Str::random(10))."-{$i}@demo.invalid",
                    'password' => bcrypt(Str::random(20)), 'status' => 'registered', 'terms_accepted_at' => now(),
                    'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('candidacies')->insert([
                    'id' => (string) Str::uuid(), 'election_id' => $electionId, 'race_id' => null,
                    'user_id' => $u, 'status' => 'validated', 'position_tags' => json_encode([]),
                    'residency_attested_at' => now(), 'validated_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Individual residents (so the endorser set is a real mix, not just
            // orgs) — active residency is what makes them eligible to endorse.
            foreach (['res-a', 'res-b'] as $i => $tag) {
                $u = (string) Str::uuid();
                DB::table('users')->insert([
                    'id' => $u, 'name' => "Resident {$i}", 'email' => 'sim-'.Str::lower(Str::random(10))."-r{$i}@demo.invalid",
                    'password' => bcrypt(Str::random(20)), 'status' => 'registered', 'terms_accepted_at' => now(),
                    'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('residency_confirmations')->insert([
                    'id' => (string) Str::uuid(), 'user_id' => $u, 'jurisdiction_id' => $jid,
                    'days_confirmed' => 30, 'confirmed_at' => now(),
                    'voting_right_active' => true, 'candidacy_right_active' => true,
                    'is_active' => true, 'depth' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            CivicsStage::run($jid, null, 1);

            $endorsements = DB::table('endorsements')->where('election_id', $electionId)->count();
            $this->assertGreaterThan(0, $endorsements, 'candidates collect endorsements');

            // Polymorphic: BOTH organizations and individuals endorse — no party
            // slate, no partisan layer between voters and their selections.
            $types = DB::table('endorsements')->where('election_id', $electionId)
                ->distinct()->pluck('endorser_type')->all();
            $this->assertContains('organizations', $types, 'organizations endorse');
            $this->assertContains('users', $types, 'individuals endorse too');

            // Idempotent: a second pass adds none.
            CivicsStage::run($jid, null, 1);
            $this->assertSame($endorsements, DB::table('endorsements')->where('election_id', $electionId)->count(),
                'a second civics pass does not double the endorsements');
        });
    }

    /**
     * CGCs (W7 — the CGC register): the chamber charters Common Good
     * Corporations, driven through the real CgcService, so the register shows
     * publicly-owned corporations with public-domain IP and a governor board.
     */
    public function test_the_chamber_charters_common_good_corporations(): void
    {
        $this->onLivePg(function () {
            $jid = $this->leaf(500_000);

            $legId = (string) Str::uuid();
            DB::table('legislatures')->insert([
                'id' => $legId, 'jurisdiction_id' => $jid, 'term_number' => 1, 'status' => 'active',
                'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $member = (string) Str::uuid();
            DB::table('users')->insert([
                'id' => $member, 'name' => 'Seated', 'email' => 'sim-'.Str::lower(Str::random(10)).'@demo.invalid',
                'password' => bcrypt(Str::random(20)), 'status' => 'registered', 'terms_accepted_at' => now(),
                'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('legislature_members')->insert([
                'id' => (string) Str::uuid(), 'legislature_id' => $legId, 'user_id' => $member,
                'seat_type' => 'a', 'seat_no' => 1, 'status' => 'elected', 'created_at' => now(), 'updated_at' => now(),
            ]);
            // Residents to hold the appointed governor and org-board seats.
            for ($i = 0; $i < 6; $i++) {
                $u = (string) Str::uuid();
                DB::table('users')->insert([
                    'id' => $u, 'name' => "Resident {$i}", 'email' => 'sim-'.Str::lower(Str::random(10))."-b{$i}@demo.invalid",
                    'password' => bcrypt(Str::random(20)), 'status' => 'registered', 'terms_accepted_at' => now(),
                    'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('residency_confirmations')->insert([
                    'id' => (string) Str::uuid(), 'user_id' => $u, 'jurisdiction_id' => $jid,
                    'days_confirmed' => 30, 'confirmed_at' => now(), 'voting_right_active' => true,
                    'candidacy_right_active' => true, 'is_active' => true, 'depth' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            CivicsStage::run($jid, null, 1);

            $cgcs = DB::table('organizations')
                ->where('jurisdiction_id', $jid)->where('type', 'common_good_corp')->whereNull('deleted_at')->get();
            $this->assertGreaterThan(0, $cgcs->count(), 'the chamber charters CGCs');

            // The governor (public) side is seated — the board is no longer empty.
            $govSeated = DB::table('board_seats as s')
                ->join('boards as b', 'b.id', '=', 's.board_id')
                ->join('organizations as o', 'o.id', '=', 'b.boardable_id')
                ->where('o.jurisdiction_id', $jid)->where('o.type', 'common_good_corp')
                ->where('s.seat_class', 'governor')->where('s.status', 'seated')->count();
            $this->assertGreaterThan(0, $govSeated, 'CGC governor seats are seated');

            // Business boards: provisioned, employed, and seated.
            $bizBoards = DB::table('boards as b')
                ->join('organizations as o', 'o.id', '=', 'b.boardable_id')
                ->where('o.jurisdiction_id', $jid)->where('o.type', 'business')
                ->where('b.boardable_type', 'organizations')->count();
            $this->assertGreaterThan(0, $bizBoards, 'a sample of businesses get boards');
            $this->assertGreaterThan(0, DB::table('org_workers')
                ->where('employer_type', 'organizations')->where('status', 'active')->count(),
                'real org_workers employment rows exist');
            $this->assertGreaterThan(0, DB::table('board_seats as s')
                ->join('boards as b', 'b.id', '=', 's.board_id')
                ->join('organizations as o', 'o.id', '=', 'b.boardable_id')
                ->where('o.jurisdiction_id', $jid)->where('o.type', 'business')
                ->where('s.seat_class', 'owner_elected')->where('s.status', 'seated')->count(),
                'business owner reps are seated');

            foreach ($cgcs as $cgc) {
                $this->assertTrue((bool) $cgc->is_cgc);
                $this->assertTrue((bool) $cgc->ip_is_public_domain, 'CGC IP is public domain (Art. III §5)');
                $this->assertSame('public', $cgc->ownership_type);
                // Genesis IP dedication landed in the register.
                $this->assertGreaterThan(0, DB::table('cgc_ip_register')->where('organization_id', $cgc->id)->count(),
                    'the genesis IP dedication is on the register');
                // A governor board with the jurisdiction's 100% stake.
                $this->assertNotNull($cgc->board_id, 'a governor board was provisioned');
                $this->assertSame(1, DB::table('org_ownership_stakes')
                    ->where('organization_id', $cgc->id)->where('holder_type', 'jurisdictions')->count(),
                    'the jurisdiction holds the single public stake');
            }

            // Idempotent: a second civics pass charters no more.
            CivicsStage::run($jid, null, 1);
            $this->assertSame($cgcs->count(), DB::table('organizations')
                ->where('jurisdiction_id', $jid)->where('type', 'common_good_corp')->whereNull('deleted_at')->count());
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** A childless jurisdiction with a population — the leaf org path's precondition. */
    private function leaf(int $population): string
    {
        $id = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $id,
            'name' => 'Civics Pin',
            'slug' => 'civics-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 4,
            'population' => $population,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
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
