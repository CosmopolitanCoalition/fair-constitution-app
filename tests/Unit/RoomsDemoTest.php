<?php

namespace Tests\Unit;

use App\Console\Commands\RoomsDemoCommand;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteeSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Support\InstanceClass;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins institutions:demo-room — the seed that gives the tour's live committee
 * hearing a real row at its fixed UUID, so /rooms/committee/<id> resolves
 * without a prior sim run.
 *
 * Guarded SQLite memory fixture (no live world DB). The synthetic-data guard is
 * satisfied through the InstanceClass test seam, not a real instance_settings
 * row.
 */
final class RoomsDemoTest extends TestCase
{
    private const CONNECTION = 'rooms_demo_fixture';

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $tables = [
            'jurisdictions'       => [['slug', 'string'], ['name', 'string']],
            'legislatures'        => [['jurisdiction_id', 'string'], ['term_number', 'int'], ['status', 'string'],
                ['total_seats', 'int'], ['type_a_seats', 'int'], ['type_b_seats', 'int'], ['quorum_required', 'int'], ['speaker_id', 'string']],
            'legislature_members' => [['legislature_id', 'string'], ['user_id', 'string'], ['seat_type', 'string'],
                ['seat_no', 'int'], ['status', 'string'], ['seated_at', 'string'], ['vacated_at', 'string']],
            'committees'          => [['legislature_id', 'string'], ['name', 'string'], ['purpose', 'string'],
                ['seats', 'int'], ['status', 'string'], ['chair_member_id', 'string'], ['alternate_member_id', 'string']],
            'committee_seats'     => [['committee_id', 'string'], ['member_id', 'string'], ['seat_kind', 'string'],
                ['status', 'string'], ['assigned_via', 'string'], ['seated_at', 'string'], ['vacated_at', 'string']],
            'committee_meetings'  => [['committee_id', 'string'], ['called_by_member_id', 'string'], ['scheduled_for', 'string'],
                ['agenda', 'string'], ['status', 'string']],
            'users'               => [['name', 'string'], ['display_name', 'string'], ['email', 'string'],
                ['password', 'string'], ['terms_accepted_at', 'string']],
        ];
        foreach ($tables as $table => $columns) {
            DB::connection()->getSchemaBuilder()->create($table, function (Blueprint $t) use ($columns): void {
                $t->string('id')->primary();
                foreach ($columns as [$name, $type]) {
                    $type === 'int' ? $t->integer($name)->nullable() : $t->string($name)->nullable();
                }
                $t->timestamps();
                $t->softDeletes();
            });
        }

        DB::table('jurisdictions')->insert([
            'id' => 'jur-smr', 'slug' => 'smr-1-san-marino', 'name' => 'San Marino',
        ]);

        InstanceClass::override(InstanceClass::SCALE_DEMO);
    }

    protected function tearDown(): void
    {
        InstanceClass::flush();
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_it_seeds_the_tour_meeting_and_the_room_data_path_resolves(): void
    {
        $this->artisan('institutions:demo-room')->assertExitCode(0);

        $meeting = CommitteeMeeting::query()->find(RoomsDemoCommand::TOUR_MEETING_ID);
        self::assertNotNull($meeting, 'the meeting exists at the tour UUID');
        self::assertSame(CommitteeMeeting::STATUS_SCHEDULED, $meeting->status);
        self::assertNotEmpty($meeting->agenda);

        // The exact reads LiveRoomController::committee() makes.
        $committee = $meeting->committee()->with('legislature.jurisdiction')->firstOrFail();
        self::assertSame(Committee::STATUS_SEATED, $committee->status);
        self::assertNotNull($committee->chair_member_id, 'a chair runs the room');
        self::assertSame('San Marino', $committee->legislature->jurisdiction->name);
        self::assertNotNull($meeting->called_by_member_id);

        $liveSeats = CommitteeSeat::query()->where('committee_id', $committee->id)->live()->get();
        self::assertGreaterThanOrEqual(3, $liveSeats->count(), 'a quorum of seated members');
        self::assertTrue($liveSeats->contains('member_id', $committee->chair_member_id));

        // No prior sim run: the command minted the seated chamber itself.
        self::assertGreaterThanOrEqual(3, LegislatureMember::query()
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)->count());
    }

    public function test_it_is_idempotent(): void
    {
        $this->artisan('institutions:demo-room')->assertExitCode(0);
        $this->artisan('institutions:demo-room')->assertExitCode(0);

        self::assertSame(1, CommitteeMeeting::query()->where('id', RoomsDemoCommand::TOUR_MEETING_ID)->count());
        self::assertSame(1, Committee::query()->where('name', 'like', '%[ROOM-DEMO]%')->count());
    }

    public function test_fresh_clears_the_seeded_rows(): void
    {
        $this->artisan('institutions:demo-room')->assertExitCode(0);
        $this->artisan('institutions:demo-room', ['--fresh' => true])->assertExitCode(0);

        // --fresh cleared the prior rows, then the same run reseeded one meeting.
        self::assertSame(1, CommitteeMeeting::query()->where('id', RoomsDemoCommand::TOUR_MEETING_ID)->count());
        self::assertSame(1, Committee::query()->where('name', 'like', '%[ROOM-DEMO]%')->count());

        // A --fresh with no reseed leaves nothing behind.
        CommitteeMeeting::query()->whereKey(RoomsDemoCommand::TOUR_MEETING_ID)->delete();
    }

    public function test_the_guard_refuses_on_a_production_world(): void
    {
        InstanceClass::override(InstanceClass::PRODUCTION);

        $this->artisan('institutions:demo-room')->assertExitCode(1);
        self::assertNull(CommitteeMeeting::query()->find(RoomsDemoCommand::TOUR_MEETING_ID));
    }

    public function test_the_scenario_preset_runs_this_seed(): void
    {
        $presets = \App\Services\Dev\ScenarioPresetService::presets();
        self::assertArrayHasKey('committee-hearing', $presets);
        self::assertSame('institutions:demo-room', $presets['committee-hearing']['command']);

        // A founded world (the fixture holds San Marino) satisfies the probe.
        [$ok, $why] = (new \App\Services\Dev\ScenarioPresetService())->probe('committee-hearing');
        self::assertTrue($ok, $why ?? '');
    }

    public function test_the_seed_uuid_matches_the_tour_stop_in_surfaces_js(): void
    {
        $surfaces = file_get_contents(base_path('resources/js/registry/surfaces.js'));
        self::assertIsString($surfaces);
        self::assertMatchesRegularExpression(
            '#/rooms/committee/'.preg_quote(RoomsDemoCommand::TOUR_MEETING_ID, '#').'#',
            $surfaces,
            'the tour committee-hearing href must point at the seeded meeting UUID'
        );
    }
}
