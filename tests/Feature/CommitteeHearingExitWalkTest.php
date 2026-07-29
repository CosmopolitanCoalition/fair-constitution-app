<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteeSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\PublicRecord;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * KEYSTONE ACCEPTANCE GATE (Slice 6, W4 ②) — an AUTOMATED committee hearing
 * end-to-end in ONE Live Civic Room, driven through the real room routes:
 * a guest watches (Art. II §2); a resident raises a hand and testifies; a
 * non-chair cannot run the floor, but the chair recognizes the speaker and then
 * yields. This proves the governance form (agenda, the sealed record) FUSED with
 * the live social floor (presence, the hands-raised queue, recognition) on one
 * page — the wave's acceptance gate.
 *
 * TWO LEGS ARE NOT ROOM-DRIVABLE (surface map, by design) and are asserted at
 * the model level with a note: the referral vote (LiveRoomController::
 * openCommitteeVote returns null — a committee reports/refers, it is not a
 * ChamberVoteProposal) and adjourn (no route transitions a meeting off its
 * status). Both are Wave-4-flagged room wiring, not test defects.
 *
 * Live-pg + rolled-back tx (default test conn is sqlite:memory, no schema);
 * MatrixClientService mocked hermetic — the room lazily provisions its Matrix
 * home on view (degrade-not-block), never a real homeserver.
 */
class CommitteeHearingExitWalkTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_exit_walk';

    /** A matching session+request token clears CSRF so the POST reaches auth (not a 419). */
    private const CSRF = 'exit-walk-csrf';

    private function postAs(User $user, string $url, array $data = [])
    {
        return $this->actingAs($user)
            ->withSession(['_token' => self::CSRF])
            ->post($url, array_merge(['_token' => self::CSRF], $data));
    }

    public function test_a_committee_hearing_runs_end_to_end_in_one_room(): void
    {
        $this->onLivePg(function () {
            $this->mockClient();

            $jurId = $this->anUnclaimedJurisdiction();

            $legislature = Legislature::create([
                'id'              => (string) Str::uuid(),
                'jurisdiction_id' => $jurId,
                'term_number'     => 1,
                'status'          => Legislature::STATUS_ACTIVE,
                'total_seats'     => 5,
                'type_a_seats'    => 5,
                'type_b_seats'    => 0,
                'quorum_required' => 3,
            ]);

            $chairUser    = $this->aUser('Chair');
            $residentUser = $this->aUser('Resident');

            $chairMember = LegislatureMember::create([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $legislature->id,
                'user_id'        => (string) $chairUser->id,
                'seat_type'      => 'a',
                'seat_no'        => 1,
                'status'         => LegislatureMember::STATUS_SEATED,
            ]);

            $committee = Committee::create([
                'id'              => (string) Str::uuid(),
                'legislature_id'  => (string) $legislature->id,
                'name'            => 'Committee on Public Works',
                'seats'           => 1,
                'status'          => Committee::STATUS_SEATED,
                'chair_member_id' => (string) $chairMember->id,
            ]);

            CommitteeSeat::create([
                'id'           => (string) Str::uuid(),
                'committee_id' => (string) $committee->id,
                'member_id'    => (string) $chairMember->id,
                'seat_kind'    => 'type_a',
                'status'       => 'seated',
            ]);

            $meeting = CommitteeMeeting::create([
                'id'                  => (string) Str::uuid(),
                'committee_id'        => (string) $committee->id,
                'called_by_member_id' => (string) $chairMember->id,
                'scheduled_for'       => now(),
                'agenda'              => ['Hearing on the drainage ordinance'],
                'status'              => CommitteeMeeting::STATUS_OPEN,
            ]);

            $room      = "/rooms/committee/{$meeting->id}";
            $resHandle = '@u-'.substr(hash('sha256', (string) $residentUser->id), 0, 8);

            // 1) A GUEST watches the hearing (Art. II §2): gallery, the agenda item
            //    is live, and there is no vote yet (a committee reports/refers).
            $this->get($room)
                ->assertOk()
                ->assertInertia(fn (Assert $p) => $p
                    ->component('Legislature/LiveCivicRoom')
                    ->where('variant', 'committee')
                    ->where('can.isGallery', true)
                    ->where('can.recognize', false)
                    ->where('vote', null)
                    ->where('agenda.0.title', 'Hearing on the drainage ordinance')
                    ->where('agenda.0.status', 'in_progress'));

            // 2) A resident raises a hand → the hands-raised queue.
            $this->postAs($residentUser, "{$room}/raise-hand")->assertRedirect();
            $this->get($room)->assertInertia(fn (Assert $p) => $p
                ->has('queue', 1)
                ->where('queue.0.handle', $resHandle));

            // 3) A non-chair cannot run the floor; the chair recognizes → the floor.
            $this->postAs($residentUser, "{$room}/recognize")->assertForbidden();
            $this->postAs($chairUser, "{$room}/recognize")->assertRedirect();
            $this->get($room)->assertInertia(fn (Assert $p) => $p
                ->where('floorHolder', $resHandle));

            // 4) The recognized resident testifies → sealed verbatim into the
            //    immutable public record (WF-LEG-08), which surfaces in the room.
            $this->postAs($residentUser, "/meetings/{$meeting->id}/testimony", ['text' => 'The culvert floods every spring.'])
                ->assertRedirect();

            $this->assertSame(1, PublicRecord::query()
                ->where('kind', 'testimony')
                ->where('subject_type', 'committee_meetings')
                ->where('subject_id', (string) $meeting->id)
                ->count(), 'testimony seals exactly one public record');

            $this->get($room)->assertInertia(fn (Assert $p) => $p->has('record', 1));

            // 5) The chair yields the floor (advance) — open for the next speaker.
            $this->postAs($chairUser, "{$room}/advance")->assertRedirect();
            $this->get($room)->assertInertia(fn (Assert $p) => $p->where('floorHolder', null));

            // GAP LEG (adjourn has no room route): force it and assert the room
            // renders the concluded state — the surface-map-documented Wave-4 gap.
            $meeting->update(['status' => CommitteeMeeting::STATUS_ADJOURNED]);
            $this->get($room)->assertInertia(fn (Assert $p) => $p
                ->where('status.label', 'Adjourned'));
        });
    }

    private function aUser(string $label): User
    {
        $name = "{$label} ".Str::random(6);

        return User::create([
            'name'              => $name,
            'display_name'      => $name,
            'email'             => strtolower($label).'-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function anUnclaimedJurisdiction(): string
    {
        $id = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            // An unclaimed jurisdiction — one already holding Matrix rooms would
            // collide on matrix_rooms_entity_unique when the room ensures its
            // Space (InstitutionRoomProvisioningTest's note on the Niue collision).
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('matrix_rooms')
                ->whereColumn('matrix_rooms.entity_id', 'jurisdictions.id')
                ->whereNull('matrix_rooms.deleted_at'))
            ->value('id');

        if ($id === null) {
            $this->markTestSkipped('Live DB has no unclaimed jurisdiction.');
        }

        return (string) $id;
    }

    private function mockClient(): void
    {
        $n = 0;
        $this->mock(MatrixClientService::class, function ($m) use (&$n) {
            $m->shouldReceive('roomVersions')->andReturn(['default' => '10', 'available' => ['10', '11', '12']]);
            $m->shouldReceive('createRoom')->andReturnUsing(function ($body) use (&$n) {
                $n++;

                return ['room_id' => '!room'.$n.':localhost'];
            });
            $m->shouldReceive('sendStateEvent')->andReturn(['event_id' => '$e']);
        });
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
