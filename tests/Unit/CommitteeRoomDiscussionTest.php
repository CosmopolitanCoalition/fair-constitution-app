<?php

namespace Tests\Unit;

use App\Http\Controllers\Rooms\LiveRoomController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\CommitteeMeeting;
use App\Models\MatrixIdentity;
use App\Models\MatrixRoom;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixIdentityProvisioner;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Matrix\VoiceReachFailed;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\PublicRoomNames;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Same memory-only fixture posture as InstitutionRoomTest; Matrix is mocked. */
final class CommitteeRoomDiscussionTest extends TestCase
{
    private const CONNECTION = 'committee_room_fixture';
    private string $original;
    private $matrix;
    private $topology;
    private $provisioner;
    private $floor;
    private LiveRoomController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('name'); $t->string('slug'); $t->integer('adm_level');
            $t->string('parent_id')->nullable(); $t->softDeletes();
        });
        $schema->create('legislatures', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('jurisdiction_id'); $t->string('status')->default('active'); $t->softDeletes();
        });
        $schema->create('committees', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('legislature_id'); $t->string('name');
            $t->string('status')->default('seated');
            $t->string('chair_member_id')->nullable(); $t->string('alternate_member_id')->nullable(); $t->softDeletes();
        });
        $schema->create('committee_meetings', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('committee_id'); $t->string('status'); $t->text('agenda');
        });
        $schema->create('committee_seats', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('committee_id'); $t->string('member_id');
            $t->string('seat_kind'); $t->timestamp('vacated_at')->nullable();
        });
        $schema->create('matrix_rooms', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('matrix_room_id')->nullable(); $t->string('entity_type'); $t->string('entity_id');
            $t->string('space_type')->nullable(); $t->string('room_type'); $t->boolean('is_public')->default(true);
            $t->boolean('is_encrypted')->default(false); $t->timestamp('tombstoned_at')->nullable(); $t->softDeletes();
        });
        $schema->create('public_records', function (Blueprint $t) {
            $t->bigInteger('seq')->primary(); $t->string('subject_type'); $t->string('subject_id');
            $t->string('title'); $t->bigInteger('audit_seq');
        });
        DB::table('jurisdictions')->insert(['id' => 'poland', 'name' => 'Poland', 'slug' => 'poland', 'adm_level' => 1]);
        DB::table('legislatures')->insert(['id' => 'legislature', 'jurisdiction_id' => 'poland']);
        DB::table('committees')->insert(['id' => 'committee', 'legislature_id' => 'legislature', 'name' => 'Public works']);
        DB::table('committee_meetings')->insert([
            ['id' => 'meeting', 'committee_id' => 'committee', 'status' => 'open', 'agenda' => '["Current hearing"]'],
            ['id' => 'other-meeting', 'committee_id' => 'committee', 'status' => 'open', 'agenda' => '["Another hearing"]'],
        ]);
        $this->matrix = $this->createMock(MatrixClientService::class);
        $this->topology = $this->createMock(SocialTopologyReconcilerService::class);
        $this->provisioner = $this->createMock(MatrixIdentityProvisioner::class);
        $posting = $this->createMock(MatrixPostingGateService::class);
        $posting->method('matrixUserId')->willReturn('@u-viewer:fixture');
        $floor = $this->createMock(LiveFloorService::class);
        $this->floor = $floor;
        $floor->method('key')->willReturn('fixture-floor');
        $floor->method('state')->willReturn(['floorHolder' => null, 'queue' => [], 'speaking' => null]);
        $names = $this->createMock(PublicRoomNames::class);
        $names->method('forUsers')->willReturn([]);
        $names->method('forHandles')->willReturn(['@u-person:fixture' => 'Public display name']);
        $this->controller = new LiveRoomController($this->topology, $floor, $this->createMock(ChamberVotePresenter::class), $names, $posting);
        app()->instance(MatrixClientService::class, $this->matrix);
        app()->instance(MatrixIdentityProvisioner::class, $this->provisioner);
        app()->instance(PublicVoiceRoomAccess::class, new PublicVoiceRoomAccess($posting));
        app('redirect')->setSession(app('session')->driver('array'));
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION); DB::setDefaultConnection($this->original); parent::tearDown();
    }

    public function test_guest_discussion_read_stays_with_the_meeting_and_exact_meeting_records(): void
    {
        $this->room();
        for ($i = 1; $i <= 125; $i++) {
            DB::table('public_records')->insert(['seq' => $i, 'audit_seq' => $i, 'subject_type' => 'committee_meetings',
                'subject_id' => $i <= 55 ? 'meeting' : 'other-meeting', 'title' => 'Record '.$i]);
        }
        $this->topology->expects($this->never())->method('reconcileCommitteeMeeting');
        $this->provisioner->expects($this->never())->method('ensureFor');
        $this->matrix->expects($this->once())->method('getMessages')->with('!meeting:fixture', 'b', null, 30)->willReturn(['chunk' => [
            ['type' => 'm.room.message', 'event_id' => '$two', 'sender' => '@u-person:fixture', 'content' => ['body' => 'Second']],
            ['type' => 'm.room.member', 'event_id' => '$join', 'sender' => '@u-person:fixture', 'content' => []],
            ['type' => 'm.room.message', 'event_id' => '$one', 'sender' => '@u-person:fixture', 'content' => ['body' => 'First']],
        ]]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $props = $this->page(Request::create('/rooms/committee/meeting?jurisdiction=elsewhere'));
        self::assertSame('Poland', $props['jurisdiction']);
        self::assertSame('poland', $props['voice']['jurisdictionId']);
        self::assertNull($props['voice']['myUserId']);
        self::assertSame(['First', 'Second'], array_column($props['chat'], 'body'));
        self::assertTrue($props['chatAvailable']);
        self::assertSame('Public display name', $props['displayNames']['@u-person:fixture']);
        self::assertSame('/rooms/committee/meeting/messages', $props['urls']['messages']);
        self::assertSame('/committees/committee?meeting=meeting', $props['urls']['chamber']);
        self::assertSame('/meetings/meeting/testimony', $props['urls']['testify']);
        self::assertCount(50, $props['record']);
        self::assertSame(array_map(fn ($i) => 'Record '.$i, range(55, 6)), array_column($props['record'], 'body'));
        $query = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'from "public_records"'));
        self::assertSame(['committee_meetings', 'meeting'], $query['bindings']);
        self::assertStringContainsString('select "title", "audit_seq"', $query['query']);
        self::assertStringContainsString('limit 50', $query['query']);
    }

    public function test_private_encrypted_retired_wrong_kind_and_incomplete_rooms_are_not_read_or_recreated(): void
    {
        $this->room();
        $this->topology->expects($this->never())->method('reconcileCommitteeMeeting');
        $this->matrix->expects($this->never())->method('getMessages');
        foreach ([['is_public' => false], ['is_encrypted' => true], ['tombstoned_at' => '2026-01-01'], ['room_type' => 'user_private'], ['matrix_room_id' => null], ['space_type' => 'halls']] as $change) {
            DB::table('matrix_rooms')->where('id', 'room')->update(array_replace([
                'is_public' => true, 'is_encrypted' => false, 'tombstoned_at' => null,
                'room_type' => MatrixRoom::ROOM_INSTITUTION, 'matrix_room_id' => '!meeting:fixture', 'space_type' => null,
            ], $change));
            $props = $this->page();
            self::assertFalse($props['voice']['enabled']);
            self::assertFalse($props['chatAvailable']);
            self::assertSame([], $props['chat']);
        }
    }

    public function test_polls_prefetches_and_head_requests_do_not_provision(): void
    {
        $this->topology->expects($this->never())->method('reconcileCommitteeMeeting');
        $this->matrix->expects($this->never())->method('getMessages');
        foreach ([['X-Inertia-Partial-Data' => 'chat'], ['X-Inertia-Partial-Component' => 'Legislature/LiveCivicRoom'], ['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch']] as $headers) {
            $request = Request::create('/rooms/committee/meeting'); $request->headers->add($headers);
            self::assertFalse($this->page($request)['voice']['enabled']);
        }
        self::assertFalse($this->page(Request::create('/rooms/committee/meeting', 'HEAD'))['voice']['enabled']);
    }

    public function test_a_provisioner_cannot_substitute_another_meetings_room(): void
    {
        $this->topology->expects($this->once())->method('reconcileCommitteeMeeting')->willReturn((new MatrixRoom)->forceFill([
            'id' => 'foreign', 'matrix_room_id' => '!foreign:fixture', 'entity_type' => MatrixRoom::ENTITY_COMMITTEE_MEETING,
            'entity_id' => 'other-meeting', 'is_public' => true, 'is_encrypted' => false, 'room_type' => MatrixRoom::ROOM_INSTITUTION,
        ]));
        $this->matrix->expects($this->never())->method('getMessages');
        self::assertFalse($this->page()['voice']['enabled']);
    }

    public function test_timeline_failure_keeps_the_civic_hearing_available(): void
    {
        $this->room();
        $this->matrix->expects($this->once())->method('getMessages')->willThrowException(new ConnectionException('offline'));
        $props = $this->page();
        self::assertFalse($props['chatAvailable']);
        self::assertSame([], $props['chat']);
        self::assertSame('Current hearing', $props['agenda'][0]['title']);
        self::assertSame('Public works — hearing', $props['title']);
    }

    public function test_post_derives_room_and_place_from_meeting_not_submitted_ids(): void
    {
        $this->room();
        $this->provisioner->expects($this->once())->method('ensureFor')->willReturn((new MatrixIdentity)->forceFill(['matrix_user_id' => '@u-viewer:fixture']));
        $this->matrix->expects($this->once())->method('sendMessage')->with('!meeting:fixture', ['msgtype' => 'm.text', 'body' => 'Discussion'], '@u-viewer:fixture')->willReturn(['event_id' => '$sent']);
        $response = $this->controller->messages($this->postRequest(), $this->meeting());
        self::assertSame(302, $response->getStatusCode());
    }

    public function test_guests_and_private_room_posters_are_denied_before_identity_or_matrix_writes(): void
    {
        $this->room(['is_public' => false]);
        $this->provisioner->expects($this->never())->method('ensureFor');
        $this->matrix->expects($this->never())->method('sendMessage');
        foreach ([null, new User(['id' => 'viewer'])] as $user) {
            $request = $this->postRequest(); $request->setUserResolver(fn () => $user);
            try { $this->controller->messages($request, $this->meeting()); self::fail('Expected a denied discussion.'); }
            catch (HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
        }
    }

    public function test_wrong_place_voice_denial_is_a_403_instead_of_a_500(): void
    {
        $this->room();
        $access = $this->createMock(PublicVoiceRoomAccess::class);
        $access->expects($this->once())->method('assertMayJoin')->willThrowException(new VoiceReachFailed('room_not_accessible', 403));
        app()->instance(PublicVoiceRoomAccess::class, $access);
        $this->provisioner->expects($this->never())->method('ensureFor');
        $this->matrix->expects($this->never())->method('sendMessage');
        try { $this->controller->messages($this->postRequest(), $this->meeting()); self::fail('Expected scope denial.'); }
        catch (HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
    }

    public function test_send_transport_failure_returns_a_draft_error(): void
    {
        $this->room();
        $this->provisioner->expects($this->once())->method('ensureFor')->willReturn((new MatrixIdentity)->forceFill(['matrix_user_id' => '@u-viewer:fixture']));
        $this->matrix->expects($this->once())->method('sendMessage')->willThrowException(new ConnectionException('offline'));
        $response = $this->controller->messages($this->postRequest(), $this->meeting());
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('could not be confirmed', $response->getSession()->get('errors')->first('body'));
    }

    private function page(?Request $request = null): array
    {
        $request ??= Request::create('/rooms/committee/meeting');
        $response = $this->controller->committee($request, $this->meeting());
        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }
    public function test_first_hand_raise_uses_the_provisioned_identity_instead_of_a_preview_handle(): void
    {
        $this->provisioner->expects($this->once())->method('ensureFor')->with($this->callback(fn ($user) => $user->id === 'viewer'))
            ->willReturn((new MatrixIdentity())->forceFill(['matrix_user_id' => '@u-canonical-person:fixture']));
        $this->floor->expects($this->once())->method('raiseHand')->with('fixture-floor', '@u-canonical-person:fixture', 'To speak')->willReturn([]);
        $this->controller->raiseHand($this->postRequest(), $this->meeting());
    }

    public function test_lowering_own_hand_cannot_remove_another_participant_even_with_a_supplied_handle(): void
    {
        config(['cache.default' => 'array']);
        self::assertSame('array', \Illuminate\Support\Facades\Cache::getDefaultDriver());
        $floor = new LiveFloorService();
        $key = $floor->key('committee_meeting', 'meeting');
        $floor->raiseHand($key, '@u-own:fixture');
        $floor->raiseHand($key, '@u-other:fixture');
        $this->provisioner->expects($this->once())->method('ensureFor')->willReturn((new MatrixIdentity())->forceFill(['matrix_user_id' => '@u-own:fixture']));
        $controller = new LiveRoomController($this->topology, $floor, $this->createMock(ChamberVotePresenter::class),
            $this->createMock(PublicRoomNames::class), $this->createMock(MatrixPostingGateService::class));
        $request = $this->postRequest();
        $request->merge(['action' => 'lower', 'handle' => '@u-other:fixture']);
        $response = $controller->raiseHand($request, $this->meeting());
        self::assertSame(['@u-other:fixture'], array_column($floor->state($key)['queue'], 'handle'));
        self::assertSame('Your hand is lowered.', $response->getSession()->get('status'));
    }

    public function test_unknown_hand_action_is_rejected_before_identity_or_floor_mutation(): void
    {
        $this->provisioner->expects($this->never())->method('ensureFor');
        $this->floor->expects($this->never())->method('raiseHand');
        $this->floor->expects($this->never())->method('lowerHand');
        $request = $this->postRequest();
        $request->merge(['action' => 'recognize']);
        try { $this->controller->raiseHand($request, $this->meeting()); self::fail('Invalid hand action accepted.'); }
        catch (\Illuminate\Validation\ValidationException $error) { self::assertArrayHasKey('action', $error->errors()); }
    }

    public function test_adjourned_hearing_keeps_discussion_but_disables_and_rejects_formal_floor_actions(): void
    {
        $this->room();
        DB::table('committee_meetings')->where('id', 'meeting')->update(['status' => 'adjourned']);
        $this->matrix->method('getMessages')->willReturn(['chunk' => []]);
        $this->provisioner->expects($this->never())->method('ensureFor');
        $this->floor->expects($this->never())->method('raiseHand');
        $this->floor->expects($this->never())->method('recognize');
        $this->floor->expects($this->never())->method('yieldFloor');
        $props = $this->page($this->postRequest());
        self::assertTrue($props['chatAvailable']);
        self::assertTrue($props['voice']['enabled']);
        foreach (['recognize', 'advance', 'raiseHand', 'testify', 'cast'] as $action) self::assertFalse($props['can'][$action]);
        foreach (['raiseHand', 'recognize', 'advance'] as $action) {
            try { $this->controller->{$action}($this->postRequest(), $this->meeting()); self::fail('Closed hearing floor action accepted.'); }
            catch (\Illuminate\Validation\ValidationException $error) { self::assertArrayHasKey('floor', $error->errors()); }
        }
    }

    public function test_dissolved_committee_or_legislature_disables_and_rejects_hearing_floor_actions(): void
    {
        $this->room();
        $this->matrix->method('getMessages')->willReturn(['chunk' => []]);
        $this->provisioner->expects($this->never())->method('ensureFor');
        $this->floor->expects($this->never())->method('raiseHand');
        $this->floor->expects($this->never())->method('recognize');
        $this->floor->expects($this->never())->method('yieldFloor');
        foreach (['committees' => 'seated', 'legislatures' => 'active'] as $table => $previous) {
            DB::table($table)->update(['status' => 'dissolved']);
            $props = $this->page($this->postRequest());
            foreach (['recognize', 'advance', 'raiseHand', 'testify', 'cast'] as $action) self::assertFalse($props['can'][$action]);
            foreach (['raiseHand', 'recognize', 'advance'] as $action) {
                try { $this->controller->{$action}($this->postRequest(), $this->meeting()); self::fail('Dissolved institution floor action accepted.'); }
                catch (\Illuminate\Validation\ValidationException $error) { self::assertArrayHasKey('floor', $error->errors()); }
            }
            DB::table($table)->update(['status' => $previous]);
        }
    }

    private function meeting(): CommitteeMeeting { return CommitteeMeeting::query()->findOrFail('meeting'); }
    private function postRequest(): Request
    {
        $request = Request::create('/rooms/committee/meeting/messages', 'POST', ['body' => 'Discussion', 'room_id' => '!foreign:fixture', 'jurisdiction_id' => 'elsewhere']);
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 'viewer']));
        return $request;
    }
    private function room(array $extra = []): void
    {
        DB::table('matrix_rooms')->insert(array_replace(['id' => 'room', 'matrix_room_id' => '!meeting:fixture',
            'entity_type' => MatrixRoom::ENTITY_COMMITTEE_MEETING, 'entity_id' => 'meeting', 'room_type' => MatrixRoom::ROOM_INSTITUTION], $extra));
    }
}
