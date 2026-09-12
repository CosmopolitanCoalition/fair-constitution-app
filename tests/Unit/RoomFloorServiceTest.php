<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\MatrixIdentity;
use App\Services\Matrix\MatrixIdentityProvisioner;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\PublicRoomNames;
use App\Services\Rooms\RoomFloorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Small SQLite and array-cache fixtures; no world, Matrix, or media writes. */
final class RoomFloorServiceTest extends TestCase
{
    private string $original;
    private RoomFloorService $rooms;
    private LiveFloorService $floor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['cache.default' => 'array', 'database.connections.room_floor_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('room_floor_fixture');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        self::assertSame('sqlite', DB::connection()->getDriverName());
        $schema = DB::connection()->getSchemaBuilder();
        foreach ([
            'legislatures' => ['speaker_id'],
            'legislature_members' => ['legislature_id', 'user_id'],
            'cases' => [],
            'panels' => ['case_id'],
            'panel_judges' => ['panel_id', 'user_id', 'screening_result'],
            'boards' => ['chair_seat_id'],
            'board_seats' => ['board_id', 'holder_user_id'],
        ] as $table => $columns) {
            $schema->create($table, function (Blueprint $t) use ($table, $columns) {
                $t->string('id')->primary();
                $t->string('status');
                foreach ($columns as $column) $t->string($column)->nullable();
                if ($table === 'panel_judges') $t->boolean('is_presiding')->default(false);
                $t->softDeletes();
            });
        }
        DB::table('legislatures')->insert([
            ['id' => 'chamber', 'status' => 'active', 'speaker_id' => 'speaker-seat'],
            ['id' => 'elsewhere', 'status' => 'active', 'speaker_id' => null],
        ]);
        DB::table('legislature_members')->insert([
            'id' => 'speaker-seat', 'status' => 'seated', 'legislature_id' => 'chamber', 'user_id' => 'speaker',
        ]);
        DB::table('cases')->insert(['id' => 'case', 'status' => 'paneled']);
        DB::table('panels')->insert(['id' => 'panel', 'status' => 'seated', 'case_id' => 'case']);
        DB::table('panel_judges')->insert([
            'id' => 'judge-seat', 'status' => 'seated', 'panel_id' => 'panel', 'user_id' => 'judge',
            'screening_result' => 'cleared', 'is_presiding' => true,
        ]);
        DB::table('boards')->insert(['id' => 'board', 'status' => 'active', 'chair_seat_id' => 'chair-seat']);
        DB::table('board_seats')->insert([
            ['id' => 'chair-seat', 'status' => 'seated', 'board_id' => 'board', 'holder_user_id' => 'chair'],
            ['id' => 'member-seat', 'status' => 'seated', 'board_id' => 'board', 'holder_user_id' => 'member'],
            ['id' => 'wrong-seat', 'status' => 'seated', 'board_id' => 'elsewhere', 'holder_user_id' => 'outsider'],
        ]);
        $identities = $this->createMock(MatrixPostingGateService::class);
        $identities->method('matrixUserId')->willReturnCallback(fn (User $user) => '@'.$user->getKey().':fixture');
        $provisioner = $this->createMock(MatrixIdentityProvisioner::class);
        $provisioner->method('ensureFor')->willReturnCallback(fn (User $user) => (new MatrixIdentity())->forceFill([
            'matrix_user_id' => '@'.$user->getKey().':fixture',
        ]));
        app()->instance(MatrixIdentityProvisioner::class, $provisioner);
        $names = $this->createMock(PublicRoomNames::class);
        $names->method('forHandles')->willReturnCallback(fn (array $handles) => array_fill_keys($handles, 'Chosen public name'));
        $this->floor = new LiveFloorService();
        $this->rooms = new RoomFloorService($this->floor, $identities, $names, new BoardRoomAccess());
    }

    protected function tearDown(): void
    {
        DB::purge('room_floor_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function user(string $id): User
    {
        return (new User())->forceFill(['id' => $id]);
    }

    private function forbidden(callable $action): void
    {
        try { $action(); self::fail('The operation should be forbidden.'); }
        catch (HttpException $error) { self::assertSame(403, $error->getStatusCode()); }
    }

    public function test_observers_can_request_and_lower_only_their_own_hand_without_authority(): void
    {
        self::assertFalse($this->rooms->view('legislature', 'chamber', null)['canRequest']);
        $observer = $this->user('observer');
        $this->rooms->act('legislature', 'chamber', $observer, 'raise', '@speaker:fixture');
        $state = $this->rooms->view('legislature', 'chamber', $observer);
        self::assertTrue($state['myHandRaised']);
        self::assertFalse($state['canPreside']);
        self::assertSame([['handle' => '@observer:fixture', 'display_name' => 'Chosen public name']], $state['queue']);
        $this->forbidden(fn () => $this->rooms->act('legislature', 'chamber', $observer, 'recognize'));
        $this->rooms->act('legislature', 'chamber', $this->user('speaker'), 'raise');
        $this->rooms->act('legislature', 'chamber', $observer, 'lower', '@speaker:fixture');
        self::assertSame(['@speaker:fixture'], array_column($this->rooms->view('legislature', 'chamber', $observer)['queue'], 'handle'));
    }

    public function test_speaker_authority_is_exact_and_rechecked_after_membership_changes(): void
    {
        $speaker = $this->user('speaker');
        self::assertTrue($this->rooms->view('legislature', 'chamber', $speaker)['canPreside']);
        self::assertFalse($this->rooms->view('legislature', 'elsewhere', $speaker)['canPreside']);
        DB::table('legislature_members')->where('id', 'speaker-seat')->update(['status' => 'term_ended']);
        self::assertFalse($this->rooms->view('legislature', 'chamber', $speaker)['canPreside']);
        $this->forbidden(fn () => $this->rooms->act('legislature', 'chamber', $speaker, 'yield'));
    }

    public function test_named_recognition_cannot_pull_a_person_from_another_rooms_queue(): void
    {
        $this->rooms->act('legislature', 'elsewhere', $this->user('visitor'), 'raise');
        try {
            $this->rooms->act('legislature', 'chamber', $this->user('speaker'), 'recognize', '@visitor:fixture');
            self::fail('A handle must be waiting in this exact room.');
        } catch (ValidationException $error) { self::assertArrayHasKey('floor', $error->errors()); }
        self::assertNull($this->rooms->view('legislature', 'chamber', null)['floorHolder']);
        self::assertCount(1, $this->rooms->view('legislature', 'elsewhere', null)['queue']);
    }

    public function test_court_witness_is_an_ephemeral_position_and_yield_clears_it(): void
    {
        $this->rooms->act('court', 'case', $this->user('witness'), 'raise');
        $judge = $this->user('judge');
        $this->rooms->act('court', 'case', $judge, 'witness', '@witness:fixture');
        $state = $this->rooms->view('court', 'case', null);
        self::assertSame('@witness:fixture', $state['activeWitness']);
        self::assertSame('@witness:fixture', $state['floorHolder']);
        self::assertSame([], $state['queue']);
        self::assertSame('paneled', DB::table('cases')->where('id', 'case')->value('status'));
        $this->rooms->act('court', 'case', $this->user('counsel'), 'raise');
        $this->rooms->act('court', 'case', $judge, 'recognize');
        $question = $this->rooms->view('court', 'case', null);
        self::assertSame('@counsel:fixture', $question['floorHolder']);
        self::assertSame('@witness:fixture', $question['activeWitness']);
        $this->rooms->act('court', 'case', $judge, 'yield');
        self::assertNull($this->rooms->view('court', 'case', null)['activeWitness']);
        DB::table('panel_judges')->where('id', 'judge-seat')->update(['screening_result' => 'recused']);
        $this->forbidden(fn () => $this->rooms->act('court', 'case', $judge, 'recognize'));
        DB::table('panel_judges')->where('id', 'judge-seat')->update(['screening_result' => 'cleared']);
        DB::table('panels')->where('id', 'panel')->update(['status' => 'dissolved']);
        self::assertFalse($this->rooms->view('court', 'case', $judge)['canPreside']);
    }

    public function test_private_board_queue_requires_current_exact_membership_and_chair(): void
    {
        foreach ([null, $this->user('outsider')] as $viewer) {
            $this->forbidden(fn () => $this->rooms->view('board', 'board', $viewer));
        }
        $this->rooms->act('board', 'board', $this->user('member'), 'raise');
        $this->forbidden(fn () => $this->rooms->act('board', 'board', $this->user('member'), 'recognize'));
        $this->rooms->act('board', 'board', $this->user('chair'), 'recognize');
        self::assertSame('@member:fixture', $this->rooms->view('board', 'board', $this->user('member'))['floorHolder']);
        DB::table('board_seats')->where('id', 'chair-seat')->update(['status' => 'vacated']);
        $this->forbidden(fn () => $this->rooms->act('board', 'board', $this->user('chair'), 'yield'));
    }

    public function test_closed_institutions_do_not_accept_or_display_stale_floor_actions(): void
    {
        $visitor = $this->user('visitor');
        $this->rooms->act('court', 'case', $visitor, 'raise');
        DB::table('cases')->where('id', 'case')->update(['status' => 'closed']);
        $this->forbidden(fn () => $this->rooms->act('court', 'case', $visitor, 'raise'));
        self::assertSame([], $this->rooms->view('court', 'case', $visitor)['queue']);
        DB::table('legislatures')->where('id', 'chamber')->update(['status' => 'dissolved']);
        $this->forbidden(fn () => $this->rooms->act('legislature', 'chamber', $visitor, 'raise'));
        DB::table('boards')->where('id', 'board')->update(['status' => 'dissolved']);
        $this->forbidden(fn () => $this->rooms->act('board', 'board', $this->user('member'), 'raise'));
    }

    public function test_full_queue_is_bounded_and_existing_hands_remain_idempotent(): void
    {
        $key = $this->floor->key('fixture', 'bounded');
        for ($i = 0; $i < 200; $i++) $this->floor->raiseHand($key, '@'.$i);
        self::assertCount(200, $this->floor->raiseHand($key, '@0')['queue']);
        try { $this->floor->raiseHand($key, '@overflow'); self::fail('The payload must remain bounded.'); }
        catch (ValidationException $error) { self::assertArrayHasKey('floor', $error->errors()); }
        self::assertCount(200, $this->floor->state($key)['queue']);
        $this->floor->recognize($key);
        self::assertCount(200, $this->floor->raiseHand($key, '@overflow')['queue']);
    }

    public function test_busy_room_lock_preserves_queue_and_does_not_lock_another_room(): void
    {
        $key = $this->floor->key('fixture', 'locked');
        $this->floor->raiseHand($key, '@original');
        $lock = Cache::lock($key.':lock', 10);
        self::assertTrue($lock->get());
        try {
            self::assertCount(1, $this->floor->raiseHand($this->floor->key('fixture', 'other'), '@other')['queue']);
            try { $this->floor->raiseHand($key, '@later'); self::fail('A busy lock must not permit an unlocked write.'); }
            catch (ValidationException $error) { self::assertArrayHasKey('floor', $error->errors()); }
            self::assertSame(['@original'], array_column($this->floor->state($key)['queue'], 'handle'));
        } finally { $lock->release(); }
        self::assertSame(['@original', '@later'], array_column($this->floor->raiseHand($key, '@later')['queue'], 'handle'));
    }
}
