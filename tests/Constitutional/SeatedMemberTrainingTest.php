<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\TrainingRequired;
use App\Models\User;
use App\Services\Education\SeatedMemberTrainingService;
use App\Services\Education\TrainingGateService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the seated-member pre-train (operator ruling Option
 * A, Wave 4 §①), the arming half of A5:
 *
 *   education:seed publishes content (arms the gate) and then trains every
 *   already-seated holder, so a played/demo world is not a wall of
 *   redirects. Three properties are load-bearing and pinned here:
 *
 *   1. The enumeration MIRRORS RoleService — a holder is pre-trained iff the
 *      gate would otherwise redirect them.
 *   2. It is a SAFE NO-OP until content is live (a redirect needs a
 *      destination) — this is why the fixture corpus stays green when the
 *      demo seeders call it on an unseeded box.
 *   3. It is IDEMPOTENT — re-arming trains only the newly-seated and mints
 *      no second achievement/stipend.
 *
 * If an edit breaks these, fix the edit, not the test.
 */
class SeatedMemberTrainingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_seat_train';

    public function test_enumeration_returns_the_seated_legislator_mirroring_role_derivation(): void
    {
        $this->onLivePg(function () {
            [, , $member] = $this->seatedMember();

            $pairs = app(SeatedMemberTrainingService::class)->seatedHolderTracks();

            $mine = array_values(array_filter(
                $pairs,
                fn ($p) => $p['user_id'] === (string) $member->id,
            ));

            $this->assertCount(1, $mine, 'A seated legislator maps to exactly one track.');
            $this->assertSame('legislature', $mine[0]['track']);

            // The mirror anchor: the enumeration agrees with role derivation.
            $this->assertContains('R-09', app(RoleService::class)->rolesFor($member));
        });
    }

    public function test_arming_is_a_safe_noop_until_content_is_published(): void
    {
        $this->onLivePg(function () {
            [, , $member] = $this->seatedMember();

            // No education content on the box → nothing to train against.
            $tally = app(SeatedMemberTrainingService::class)->armSeatedMembers();

            $this->assertSame(0, $tally['filed'], 'No live content → no filings.');
            $this->assertGreaterThanOrEqual(1, $tally['unarmed'], 'The seated holder is counted, unarmed.');
            $this->assertSame(0, DB::table('audit_log')
                ->where('ref', 'F-EDU-001')->where('rejected', false)
                ->where('actor_user_id', (string) $member->id)->count());
        });
    }

    public function test_arming_pretrains_seated_members_and_is_idempotent(): void
    {
        $this->onLivePg(function () {
            $engine = app(ConstitutionalEngine::class);
            $gate = app(TrainingGateService::class);
            $service = app(SeatedMemberTrainingService::class);

            [$jid, $legId, $member] = $this->seatedMember();
            $this->publishLegislatureTraining();

            // Armed + untrained: the member's first role-act redirects.
            $voteId = $this->openFloorVote($jid, $legId);
            try {
                $engine->file('F-LEG-004', $member, ['vote_id' => $voteId, 'value' => 'yes']);
                $this->fail('An armed, untrained member must redirect before the pre-train.');
            } catch (TrainingRequired $e) {
                $this->assertSame('legislature', $e->track);
            }

            // The pre-train pass trains the seated member.
            $first = $service->armSeatedMembers();
            $this->assertGreaterThanOrEqual(1, $first['filed'], 'The seated member is newly trained.');
            $this->assertTrue($gate->hasCompleted($member, 'legislature'));
            $this->assertSame(1, DB::table('achievements')
                ->where('user_id', (string) $member->id)->where('award_key', 'ACH-EDU-001')->count());

            // The gate is now open — the SAME act proceeds.
            $result = $engine->file('F-LEG-004', $member, ['vote_id' => $voteId, 'value' => 'yes']);
            $this->assertSame('F-LEG-004', $result->formId);

            // Idempotent: a second pass files nothing and mints nothing new.
            $second = $service->armSeatedMembers();
            $this->assertSame(0, $second['filed'], 'A second pass re-trains no one.');
            $this->assertGreaterThanOrEqual(1, $second['already']);
            $this->assertSame(1, DB::table('achievements')
                ->where('user_id', (string) $member->id)->where('award_key', 'ACH-EDU-001')->count());
        });
    }

    /**
     * PER-JURISDICTION arming (W7 item 7 — the sim's TrainingStage door). The
     * sim trains one jurisdiction at a time so the pass is bounded and
     * resumable; a scoped pass must train ITS jurisdiction's holders and leave
     * every other jurisdiction untouched.
     */
    public function test_per_jurisdiction_arming_trains_only_that_jurisdictions_holders(): void
    {
        $this->onLivePg(function () {
            $service = app(SeatedMemberTrainingService::class);
            $gate = app(TrainingGateService::class);

            [$jid, , $member] = $this->seatedMember();
            [, , $other] = $this->seatedMember(); // a second jurisdiction's seated member
            $this->publishLegislatureTraining();

            // The scoped enumeration sees only this jurisdiction's holder.
            $ids = array_column($service->seatedHolderTracksForJurisdiction($jid), 'user_id');
            $this->assertContains((string) $member->id, $ids);
            $this->assertNotContains((string) $other->id, $ids, 'a scoped pass sees only its own jurisdiction');

            // Arm just this jurisdiction: its member trains; the other stays untrained.
            $tally = $service->armForJurisdiction($jid);
            $this->assertGreaterThanOrEqual(1, $tally['filed']);
            $this->assertTrue($gate->hasCompleted($member, 'legislature'));
            $this->assertFalse($gate->hasCompleted($other, 'legislature'), 'the other jurisdiction was untouched');

            // Idempotent: a second scoped pass files nothing new.
            $again = $service->armForJurisdiction($jid);
            $this->assertSame(0, $again['filed']);
            $this->assertGreaterThanOrEqual(1, $again['already']);
        });
    }

    // ── fixtures (the shape of TrainingGateEndToEndTest) ────────────────────

    /** @return array{0: string, 1: string, 2: User} [jurisdictionId, legislatureId, member] */
    private function seatedMember(): array
    {
        $jid = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jid, 'name' => 'Seat Train', 'slug' => 'seat-train-'.Str::lower(Str::random(10)),
            'adm_level' => 3, 'population' => 1000, 'source' => 'user_defined',
            'official_languages' => '["en"]', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $jid, 'term_number' => 1, 'status' => 'active',
            'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $member = User::create([
            'name' => 'Seat Train Member',
            'email' => 'seat-train-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        DB::table('legislature_members')->insert([
            'id' => (string) Str::uuid(), 'legislature_id' => $legId,
            'user_id' => (string) $member->id, 'seat_type' => 'a', 'seat_no' => 1,
            'status' => 'seated', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(RoleService::class)->flush();

        return [$jid, $legId, $member];
    }

    private function openFloorVote(string $jid, string $legId): string
    {
        $voteId = (string) Str::uuid();
        DB::table('chamber_votes')->insert([
            'id' => $voteId, 'body_type' => 'legislature', 'body_id' => $legId,
            'legislature_id' => $legId, 'jurisdiction_id' => $jid,
            'vote_type' => 'motion', 'vote_method' => 'yes_no',
            'threshold_basis' => 'majority', 'stage' => 'floor', 'bicameral' => false,
            'serving_snapshot' => 5, 'speaker_tiebreak' => false,
            'opened_at' => now(), 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('chamber_vote_tallies')->insert([
            'id' => (string) Str::uuid(), 'vote_id' => $voteId, 'lane' => 'all',
            'serving' => 5, 'quorum_required' => 3, 'required_yes' => 3,
            'present' => 0, 'yes' => 0, 'no' => 0, 'abstain' => 0,
            'quorate' => false, 'passed' => false,
        ]);

        return $voteId;
    }

    private function publishLegislatureTraining(): string
    {
        $trackId = (string) Str::uuid();
        DB::table('education_tracks')->insert([
            'id' => $trackId, 'key' => 'legislature', 'title' => 'Serving in a legislature',
            'status' => 'live', 'ordering' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The catalog's first legislature module key — the backfill resolves
        // the same key from config('cga.education.content').
        $moduleKey = config('cga.education.content.legislature.modules.0.key', 'chamber-basics');
        DB::table('education_modules')->insert([
            'id' => (string) Str::uuid(), 'track_id' => $trackId, 'key' => $moduleKey,
            'title' => 'The chamber', 'status' => 'live', 'ordering' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $moduleKey;
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
