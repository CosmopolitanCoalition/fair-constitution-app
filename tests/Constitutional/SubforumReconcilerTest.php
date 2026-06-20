<?php

namespace Tests\Constitutional;

use App\Jobs\EvaluateSocialStructureJob;
use App\Models\SocialSpace;
use App\Models\SocialSubforum;
use App\Models\User;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Social\SubforumReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-1 (the auto-bind reconciler). One live subforum per live
 * governance object, idempotently: re-running over the same set is a no-op (the partial-unique
 * key), a closed object's subforum is ARCHIVED (never deleted — history stays browsable), a
 * re-opened object's subforum re-opens, and a duplicate is never created. The sweep provisions a
 * jurisdiction's public_square + halls idempotently.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SubforumReconcilerTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k1_reconciler';

    public function test_reconcile_is_idempotent_archives_closed_and_reopens(): void
    {
        $this->onLivePg(function () {
            $halls = SocialSpace::query()->create([
                'jurisdiction_id' => (string) Str::uuid(),
                'space_type'      => SocialSpace::TYPE_HALLS,
                'title'           => 'Halls',
                'status'          => SocialSpace::STATUS_OPEN,
                'is_private'      => false,
            ]);

            $billId = (string) Str::uuid();
            $petId = (string) Str::uuid();
            $live = [
                ['type' => 'bill', 'id' => $billId, 'title' => 'Bill — A'],
                ['type' => 'petition', 'id' => $petId, 'title' => 'Petition — B'],
            ];
            $reconciler = app(SubforumReconciler::class);

            $first = $reconciler->reconcile($halls, $live);
            $this->assertSame(2, $first['created']);

            // Re-run: idempotent — creates nothing, still exactly two live object subforums.
            $second = $reconciler->reconcile($halls, $live);
            $this->assertSame(0, $second['created'], 're-run is a no-op');
            $this->assertSame(2, $this->liveObjectSubforums($halls));

            // Drop the bill from the live set → its subforum is archived (not deleted).
            $reconciler->reconcile($halls, [['type' => 'petition', 'id' => $petId, 'title' => 'Petition — B']]);
            $this->assertSame(
                SocialSubforum::STATUS_ARCHIVED,
                SocialSubforum::query()->where('governing_object_type', 'bill')->where('governing_object_id', $billId)->value('status')
            );
            $this->assertSame(1, $this->liveObjectSubforums($halls));

            // Re-add the bill → its subforum re-opens; never a duplicate.
            $back = $reconciler->reconcile($halls, $live);
            $this->assertSame(1, $back['reopened']);
            $this->assertSame(2, $this->liveObjectSubforums($halls));
            $this->assertSame(
                1,
                SocialSubforum::query()->where('governing_object_type', 'bill')->where('governing_object_id', $billId)->count(),
                'the object-bound subforum is never duplicated'
            );
        });
    }

    public function test_the_sweep_provisions_square_and_halls_idempotently(): void
    {
        $this->onLivePg(function () {
            $jurisdictionId = (string) Str::uuid();

            // The K-3 Matrix topology mirror is best-effort + bridged-not-merged; this pin is about the
            // Plane-A space provisioning, so stub the topology reconciler (no live homeserver side-effects).
            $this->mock(SocialTopologyReconcilerService::class, function ($m) {
                $m->shouldReceive('reconcileJurisdiction')->andReturnNull();
            });
            $reconciler = app(SubforumReconciler::class);
            $topology = app(SocialTopologyReconcilerService::class);

            (new EvaluateSocialStructureJob($jurisdictionId))->handle($reconciler, $topology);

            $this->assertSame(1, SocialSpace::query()->where('jurisdiction_id', $jurisdictionId)->where('space_type', 'public_square')->count());
            $this->assertSame(1, SocialSpace::query()->where('jurisdiction_id', $jurisdictionId)->where('space_type', 'halls')->count());

            // Idempotent — re-running provisions nothing new.
            (new EvaluateSocialStructureJob($jurisdictionId))->handle($reconciler, $topology);
            $this->assertSame(1, SocialSpace::query()->where('jurisdiction_id', $jurisdictionId)->where('space_type', 'halls')->count());
        });
    }

    public function test_the_join_path_seams_bind_live_objects_and_candidacy_stays_pseudonymous(): void
    {
        $this->onLivePg(function () {
            $jur = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
            if ($jur === null) {
                $this->markTestSkipped('Live DB has no jurisdiction.');
            }
            $jur = (string) $jur;
            $reconciler = app(SubforumReconciler::class);

            // Committee meeting — bound via the committee→legislature→jurisdiction JOIN (no direct FK).
            $legId = (string) Str::uuid();
            DB::table('legislatures')->insert([
                'id' => $legId, 'jurisdiction_id' => $jur, 'term_number' => 1, 'status' => 'active',
                'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $member = $this->user('member');
            $memberId = (string) Str::uuid();
            DB::table('legislature_members')->insert([
                'id' => $memberId, 'legislature_id' => $legId, 'user_id' => $member->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $cmteId = (string) Str::uuid();
            DB::table('committees')->insert([
                'id' => $cmteId, 'legislature_id' => $legId, 'name' => 'Budget Committee', 'seats' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $meetingId = (string) Str::uuid();
            DB::table('committee_meetings')->insert([
                'id' => $meetingId, 'committee_id' => $cmteId, 'called_by_member_id' => $memberId,
                'scheduled_for' => now(), 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
            ]);

            // Candidacy — bound via election→jurisdiction JOIN; the title is the candidate's PSEUDONYM.
            $electionId = (string) Str::uuid();
            DB::table('elections')->insert([
                'id' => $electionId, 'jurisdiction_id' => $jur, 'status' => 'scheduled',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $candidate = $this->user('Jane LEGALNAME Doe');   // a legal name that must NEVER appear in a title
            $candidacyId = (string) Str::uuid();
            DB::table('candidacies')->insert([
                'id' => $candidacyId, 'election_id' => $electionId, 'user_id' => $candidate->id,
                'residency_attested_at' => now(), 'status' => 'registered',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $live = collect($reconciler->gatherLiveObjects($jur));

            $meeting = $live->firstWhere('id', $meetingId);
            $this->assertNotNull($meeting, 'a scheduled committee meeting binds via the legislature join');
            $this->assertSame(SocialSubforum::OBJECT_COMMITTEE_MEETING, $meeting['type']);
            $this->assertStringContainsString('Budget Committee', $meeting['title']);

            $cand = $live->firstWhere('id', $candidacyId);
            $this->assertNotNull($cand, 'a standing candidacy binds via the election join');
            $this->assertSame(SocialSubforum::OBJECT_CANDIDACY, $cand['type']);
            $this->assertStringNotContainsString('LEGALNAME', $cand['title'], 'Art. I — a candidacy title is NEVER the legal name');
            $this->assertStringStartsWith('Candidacy — Resident-', $cand['title'], 'pseudonymous fallback');

            // Terminal status drops an object out of the live set (→ the reconciler ARCHIVES its subforum).
            DB::table('committee_meetings')->where('id', $meetingId)->update(['status' => 'adjourned']);
            DB::table('candidacies')->where('id', $candidacyId)->update(['status' => 'withdrawn']);

            $after = collect($reconciler->gatherLiveObjects($jur));
            $this->assertNull($after->firstWhere('id', $meetingId), 'an adjourned meeting is no longer live');
            $this->assertNull($after->firstWhere('id', $candidacyId), 'a withdrawn candidacy is no longer live');
        });
    }

    private function user(string $name): User
    {
        return User::create([
            'name'              => $name,
            'email'             => 'recon-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function liveObjectSubforums(SocialSpace $space): int
    {
        return SocialSubforum::query()
            ->where('space_id', (string) $space->id)
            ->whereNotNull('governing_object_type')
            ->where('status', SocialSubforum::STATUS_OPEN)
            ->count();
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
