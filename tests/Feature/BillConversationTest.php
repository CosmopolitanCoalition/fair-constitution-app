<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillVersion;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\SocialPost;
use App\Models\SocialSpace;
use App\Models\SocialSubforum;
use App\Models\SocialThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * A bill — the conversation (mockups/v3/shared/bill.html): /bills/{bill}/conversation is the
 * conversation face of a bill (progress + the real text + the amendment path + comments) composed
 * over the formal record. Comments RIDE the bill's auto-bound hall subforum via F-SOC-001; there is
 * no per-clause redline (the Art. V §3 violation the engine rejects) and no fabricated summary.
 *
 * The MessagesInboxTest posture: DB-backed on the guarded live-pg connection, everything in a
 * rolled-back transaction; SKIPS when pg is unreachable.
 */
class BillConversationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_bill_convo';

    public function test_the_conversation_page_renders_with_stages_text_and_honest_empty_comments(): void
    {
        $this->onLivePg(function () {
            ['bill' => $bill] = $this->aBillWithSubforum('Clean Air Act '.Str::random(4), 'No smoking within 50m of a school.');

            // Public read (guest): the page renders; commenting needs sign-in, but the space exists.
            $this->get("/bills/{$bill->id}/conversation")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Legislature/BillConversation')
                    ->where('bill.id', (string) $bill->id)
                    ->where('bill.title', $bill->title)
                    ->where('bill.text', 'No smoking within 50m of a school.') // the REAL text — no fabricated summary
                    ->where('bill.versionCount', 1)
                    ->where('bill.formalHref', "/bills/{$bill->id}")
                    ->has('stages.path', 7)
                    ->where('stages.path.0.label', 'Introduced')
                    ->where('stages.path.0.state', 'current')
                    ->where('stages.terminal', null)
                    ->where('comments', [])              // honest-empty: no comments yet
                    ->where('commentState', 'needs_auth')); // subforum exists, guest not signed in
        });
    }

    public function test_a_comment_rides_the_bills_bound_hall_subforum(): void
    {
        $this->onLivePg(function () {
            ['bill' => $bill, 'subforum' => $subforum] = $this->aBillWithSubforum('Transit Act '.Str::random(4), 'Free buses on election day.');
            $commenter = $this->aUser('Commenter');

            $this->withSession(['_token' => 'pin'])
                ->actingAs($commenter)
                ->post("/bills/{$bill->id}/comments", ['body' => 'Strongly support this.', '_token' => 'pin'])
                ->assertRedirect();

            // The comment landed as an F-SOC-001 post in a thread of the BILL's bound subforum.
            $threadIds = SocialThread::query()->where('subforum_id', $subforum->id)->pluck('id');
            $post = SocialPost::query()->whereIn('thread_id', $threadIds)->where('body', 'Strongly support this.')->first();
            $this->assertNotNull($post, 'the comment is a post in the bill subforum');

            // And it now shows on the conversation page.
            $this->actingAs($commenter)
                ->get("/bills/{$bill->id}/conversation")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('comments', 1)
                    ->where('comments.0.body', 'Strongly support this.')
                    ->where('commentState', 'open'));
        });
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'bill-convo-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    /** A jurisdiction with NO legislature (the live DB carries demo chambers). */
    private function aFreshJurisdiction(): Jurisdiction
    {
        $used = Legislature::query()->pluck('jurisdiction_id')->all();
        $j = Jurisdiction::query()->whereNotIn('id', $used)->where('adm_level', 2)->orderBy('id')->first();
        $this->assertNotNull($j, 'live DB has an adm2 jurisdiction without a legislature');

        return $j;
    }

    /**
     * A bill on a fresh legislature, with version 1 text and the bill's bound hall subforum in the
     * jurisdiction's canonical halls space — the shape SubforumReconciler produces for a live bill.
     *
     * @return array{bill: Bill, subforum: SocialSubforum}
     */
    private function aBillWithSubforum(string $title, string $text): array
    {
        $sponsor = $this->aUser('Bill Sponsor');
        $jur = $this->aFreshJurisdiction();

        $legislature = Legislature::create([
            'jurisdiction_id' => (string) $jur->id,
            'status'          => Legislature::STATUS_ACTIVE,
        ]);
        $member = LegislatureMember::create([
            'legislature_id' => (string) $legislature->id,
            'user_id'        => (string) $sponsor->id,
            'status'         => LegislatureMember::STATUS_SEATED,
        ]);

        $bill = Bill::create([
            'legislature_id'     => (string) $legislature->id,
            'jurisdiction_id'    => (string) $jur->id,
            'sponsor_member_id'  => (string) $member->id,
            'title'              => $title,
            'act_type'           => Bill::TYPE_ORDINARY,
            'scale'              => [(string) $jur->id],
            'status'             => Bill::STATUS_INTRODUCED,
            'current_version_no' => 1,
            'introduced_at'      => now(),
        ]);
        BillVersion::create([
            'bill_id'     => (string) $bill->id,
            'version_no'  => 1,
            'law_text'    => $text,
            'change_kind' => BillVersion::KIND_INTRODUCTION,
        ]);

        // The jurisdiction's canonical halls space + the bill's bound subforum (SubforumReconciler shape).
        $halls = SocialSpace::create([
            'jurisdiction_id' => (string) $jur->id,
            'space_type'      => SocialSpace::TYPE_HALLS,
            'title'           => 'Halls of Governance',
            'status'          => SocialSpace::STATUS_OPEN,
            'is_private'      => false,
        ]);
        $subforum = SocialSubforum::create([
            'space_id'              => (string) $halls->id,
            'governing_object_type' => SocialSubforum::OBJECT_BILL,
            'governing_object_id'   => (string) $bill->id,
            'title'                 => 'Bill — '.$title,
            'status'                => SocialSubforum::STATUS_OPEN,
        ]);

        return ['bill' => $bill, 'subforum' => $subforum];
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
