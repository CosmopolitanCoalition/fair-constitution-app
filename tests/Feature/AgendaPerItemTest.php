<?php

namespace Tests\Feature;

use App\Models\AgendaItem;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteeSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\Legislature\AgendaService;
use App\Services\Matrix\MatrixClientService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the per-item agenda (Wave 5 ⑤). Two layers:
 *   · the AgendaService core: sync materializes ordered rows; start/advance walk
 *     them pending → in_progress → done; a LOCKED head is never disposed.
 *   · the committee integration: F-CHR-002 materializes rows, F-CHR-005 takes up
 *     item 1, the room renders the durable status, and advance progresses it —
 *     retiring the positional stub the room flagged (LiveRoomController:168).
 *
 * Live-pg + rolled-back tx (default test conn is sqlite:memory, no schema).
 */
class AgendaPerItemTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_agenda';
    private const CSRF = 'agenda-csrf';

    /** sync materializes ordered rows; start + advance walk them to done. */
    public function test_agenda_service_syncs_and_walks_items(): void
    {
        $this->onLivePg(function () {
            $agenda = app(AgendaService::class);
            $host   = AgendaItem::HOST_COMMITTEE_MEETING;
            $id     = (string) Str::uuid();

            $items = $agenda->sync($host, $id, ['Open remarks', 'The drainage ordinance', 'Adjournment motion']);
            $this->assertCount(3, $items);
            $this->assertSame([1, 2, 3], $items->pluck('position')->all());
            $this->assertTrue($items->every(fn ($i) => $i->status === AgendaItem::STATUS_PENDING));

            // start: item 1 taken up.
            $agenda->start($host, $id);
            $rows = $agenda->forHost($host, $id);
            $this->assertSame(AgendaItem::STATUS_IN_PROGRESS, $rows[0]->status);
            $this->assertSame(AgendaItem::STATUS_PENDING, $rows[1]->status);

            // advance: item 1 disposed, item 2 taken up.
            $next = $agenda->advance($host, $id, 'heard');
            $this->assertSame('The drainage ordinance', $next->title);
            $rows = $agenda->forHost($host, $id);
            $this->assertSame(AgendaItem::STATUS_DONE, $rows[0]->status);
            $this->assertSame('heard', $rows[0]->disposition);
            $this->assertNotNull($rows[0]->disposed_at);
            $this->assertSame(AgendaItem::STATUS_IN_PROGRESS, $rows[1]->status);

            // advance through the tail, then exhaustion returns null.
            $this->assertSame('Adjournment motion', $agenda->advance($host, $id)->title);
            $this->assertNull($agenda->advance($host, $id));
            $this->assertSame(3, $agenda->forHost($host, $id)->where('status', AgendaItem::STATUS_DONE)->count());

            // re-sync replaces the set — positions reused after the soft-delete.
            $reset = $agenda->sync($host, $id, ['A fresh single item']);
            $this->assertCount(1, $reset);
            $this->assertSame(1, $reset->first()->position);
            $this->assertSame(AgendaItem::STATUS_PENDING, $reset->first()->status);
        });
    }

    /** A LOCKED head item (emergency / constitutional) is never disposed by the chair. */
    public function test_advance_never_disposes_a_locked_head(): void
    {
        $this->onLivePg(function () {
            $agenda = app(AgendaService::class);
            $host   = AgendaItem::HOST_COMMITTEE_MEETING;
            $id     = (string) Str::uuid();

            $agenda->sync($host, $id, [
                ['kind' => 'emergency_power', 'title' => 'Emergency review', 'locked' => true],
                ['kind' => 'general', 'title' => 'Ordinary business'],
            ]);

            $agenda->start($host, $id);
            $rows = $agenda->forHost($host, $id);
            $this->assertSame(AgendaItem::STATUS_PENDING, $rows->firstWhere('locked', true)->status,
                'the locked head is never taken up by the chair');
            $this->assertSame(AgendaItem::STATUS_IN_PROGRESS, $rows->firstWhere('locked', false)->status);

            $agenda->advance($host, $id);
            $this->assertSame(AgendaItem::STATUS_PENDING,
                $agenda->forHost($host, $id)->firstWhere('locked', true)->status,
                'and advance still never disposes it');
        });
    }

    /** The full committee wiring: F-CHR-002 rows → F-CHR-005 open → room → advance. */
    public function test_committee_agenda_is_route_driven_end_to_end(): void
    {
        $this->onLivePg(function () {
            $this->mockClient();
            [$chairUser, $meeting] = $this->seatChairAndMeeting();

            // F-CHR-002 — the chair sets a three-item agenda → durable rows.
            $this->postAs($chairUser, "/meetings/{$meeting->id}/agenda", [
                'agenda' => ['Open remarks', 'The drainage ordinance', 'Adjournment motion'],
            ])->assertRedirect();

            $this->assertSame(3, AgendaItem::query()
                ->where('agendable_type', AgendaItem::HOST_COMMITTEE_MEETING)
                ->where('agendable_id', (string) $meeting->id)
                ->count(), 'F-CHR-002 materializes one row per item');

            // F-CHR-005 — opening takes up item 1.
            $this->postAs($chairUser, "/meetings/{$meeting->id}/open")->assertRedirect();
            $this->app['auth']->forgetGuards();

            // The room renders the DURABLE per-item status, not a positional guess.
            $this->get("/rooms/committee/{$meeting->id}")->assertInertia(fn (Assert $p) => $p
                ->where('agenda.0.title', 'Open remarks')
                ->where('agenda.0.status', 'in_progress')
                ->where('agenda.1.status', 'pending'));

            // advance — item 1 disposed, item 2 taken up (via the route).
            $this->postAs($chairUser, "/rooms/committee/{$meeting->id}/advance")->assertRedirect();
            $this->app['auth']->forgetGuards();

            $this->get("/rooms/committee/{$meeting->id}")->assertInertia(fn (Assert $p) => $p
                ->where('agenda.0.status', 'done')
                ->where('agenda.1.title', 'The drainage ordinance')
                ->where('agenda.1.status', 'in_progress'));
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: User, 1: CommitteeMeeting} */
    private function seatChairAndMeeting(): array
    {
        $jurId = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('matrix_rooms')
                ->whereColumn('matrix_rooms.entity_id', 'jurisdictions.id')
                ->whereNull('matrix_rooms.deleted_at'))
            ->value('id');

        if ($jurId === null) {
            $this->markTestSkipped('Live DB has no unclaimed jurisdiction.');
        }

        $legislature = Legislature::create([
            'id'              => (string) Str::uuid(),
            'jurisdiction_id' => (string) $jurId,
            'term_number'     => 1,
            'status'          => Legislature::STATUS_ACTIVE,
            'total_seats'     => 5,
            'type_a_seats'    => 5,
            'type_b_seats'    => 0,
            'quorum_required' => 3,
        ]);

        $chairUser = User::create([
            'name'              => 'Chair '.Str::random(6),
            'display_name'      => 'Chair',
            'email'             => 'chair-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

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
            'agenda'              => [],
            'status'              => CommitteeMeeting::STATUS_SCHEDULED,
        ]);

        return [$chairUser, $meeting];
    }

    private function postAs(User $user, string $url, array $data = [])
    {
        return $this->actingAs($user)
            ->withSession(['_token' => self::CSRF])
            ->post($url, array_merge(['_token' => self::CSRF], $data));
    }

    private function mockClient(): void
    {
        $n = 0;
        $this->mock(MatrixClientService::class, function ($m) use (&$n) {
            $m->shouldReceive('roomVersions')->andReturn(['default' => '10', 'available' => ['10', '11', '12']]);
            $m->shouldReceive('createRoom')->andReturnUsing(function () use (&$n) {
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

        // The per-item agenda table ships with this wave's migration slot; until
        // it is run this pin skips (it never force-fails a pre-migration tree).
        if (! Schema::connection(self::LIVE_CONNECTION)->hasTable('agenda_items')) {
            DB::setDefaultConnection($original);
            $this->markTestSkipped('agenda_items not migrated yet (2026_07_30_000000) — grant the slot, then run.');
        }

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
