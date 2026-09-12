<?php

namespace Tests\Unit;

use App\Models\MatrixRoom;
use App\Models\User;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Matrix\VoiceReachFailed;
use App\Services\Matrix\VoiceReachService;
use App\Services\Federation\ServiceReachService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Isolated SQLite fixtures only. Never loads migrations or connects to the simulated world. */
class PublicVoiceRoomAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'room_access_test', 'database.connections.room_access_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('room_access_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('matrix_rooms', function (Blueprint $table) {
            $table->string('id')->primary();
            foreach (['matrix_room_id', 'room_type', 'entity_type', 'entity_id', 'space_type'] as $column) {
                $table->string($column)->nullable();
            }
            $table->boolean('is_public')->default(true);
            $table->boolean('is_encrypted')->default(false);
            $table->timestamp('tombstoned_at')->nullable();
            $table->softDeletes();
        });
        Schema::create('legislatures', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id');
            $table->softDeletes();
        });
        Schema::create('committees', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('legislature_id');
            $table->softDeletes();
        });
        Schema::create('committee_meetings', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('committee_id');
        });
        DB::table('legislatures')->insert(['id' => 'legislature', 'jurisdiction_id' => 'poland']);
        DB::table('committees')->insert(['id' => 'committee', 'legislature_id' => 'legislature']);
        DB::table('committee_meetings')->insert(['id' => 'meeting', 'committee_id' => 'committee']);
        DB::table('matrix_rooms')->insert([
            'id' => 'room', 'matrix_room_id' => '!hearing:example.test',
            'room_type' => MatrixRoom::ROOM_INSTITUTION,
            'entity_type' => MatrixRoom::ENTITY_COMMITTEE_MEETING, 'entity_id' => 'meeting',
        ]);
    }

    private function access(): PublicVoiceRoomAccess
    {
        return new PublicVoiceRoomAccess(\Mockery::mock(MatrixPostingGateService::class));
    }

    private function visitor(): User
    {
        $visitor = new User();
        $visitor->id = 'visitor-with-no-residency';

        return $visitor;
    }

    public function test_public_hearing_accepts_an_authenticated_visitor(): void
    {
        $this->access()->assertMayJoin($this->visitor(), 'poland', '!hearing:example.test');
        $this->addToAssertionCount(1);
    }

    public function test_foreign_jurisdiction_is_rejected(): void
    {
        $this->expectException(VoiceReachFailed::class);
        $this->access()->assertMayJoin($this->visitor(), 'france', '!hearing:example.test');
    }

    public function test_private_room_is_rejected_even_with_a_matching_jurisdiction(): void
    {
        DB::table('matrix_rooms')->update(['is_public' => false]);
        $this->expectException(VoiceReachFailed::class);
        $this->access()->assertMayJoin($this->visitor(), 'poland', '!hearing:example.test');
    }

    public function test_tombstoned_room_is_rejected(): void
    {
        DB::table('matrix_rooms')->update(['tombstoned_at' => now()]);
        $this->expectException(VoiceReachFailed::class);
        $this->access()->assertMayJoin($this->visitor(), 'poland', '!hearing:example.test');
    }

    public function test_unknown_room_is_rejected(): void
    {
        $this->expectException(VoiceReachFailed::class);
        $this->access()->assertMayJoin($this->visitor(), 'poland', '!unknown:example.test');
    }

    public function test_reach_checks_access_before_local_or_peer_service_discovery(): void
    {
        $this->mock(ServiceReachService::class, fn ($mock) => $mock->shouldNotReceive('reachLiveService'));
        $this->expectException(VoiceReachFailed::class);
        app(VoiceReachService::class)->tokenFor($this->visitor(), 'france', '!hearing:example.test', []);
    }

    public function test_encrypted_room_is_rejected_on_the_public_path(): void
    {
        DB::table('matrix_rooms')->update(['is_encrypted' => true]);
        $this->expectException(VoiceReachFailed::class);
        $this->access()->assertMayJoin($this->visitor(), 'poland', '!hearing:example.test');
    }

    public function test_commons_retains_its_existing_access_policy(): void
    {
        DB::table('matrix_rooms')->update([
            'room_type' => MatrixRoom::ROOM_COMMONS, 'entity_type' => MatrixRoom::ENTITY_JURISDICTION,
            'entity_id' => 'poland', 'space_type' => MatrixRoom::SPACE_HALLS,
        ]);
        $posting = \Mockery::mock(MatrixPostingGateService::class);
        $posting->shouldReceive('assertMayAccessCommons')->once()
            ->with(\Mockery::type(User::class), 'poland', '!hearing:example.test');
        (new PublicVoiceRoomAccess($posting))->assertMayJoin($this->visitor(), 'poland', '!hearing:example.test');
    }
}
