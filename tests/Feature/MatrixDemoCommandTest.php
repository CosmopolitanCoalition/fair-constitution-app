<?php

namespace Tests\Feature;

use App\Services\Matrix\MatrixClientService;
use App\Services\RoleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * PIN — Phase K-3 (K3-M), the matrix:demo seeder. It orchestrates the built K3 pieces: a sealed
 * testimony (Plane B → Plane A) and the LEGITIMACY FLIP demonstrated as two matrix_carveout_log rows —
 * a SEATED jurisdiction's judicial-attested carve-out (attestation_id SET) vs. a BOOTSTRAP jurisdiction's
 * operator-relay carve-out (attestation_id NULL). It is an integration seeder but a down homeserver
 * never fails it (the topology is best-effort; the Plane-A artifacts always land). The client is mocked.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MatrixDemoCommandTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_demo';

    public function test_offline_seeds_the_testimony_and_the_two_sided_flip(): void
    {
        $this->onLivePg(function () {
            $code = Artisan::call('matrix:demo', ['--offline' => true]);
            $this->assertSame(0, $code, 'the offline demo seeds cleanly');

            // The Plane B → Plane A testimony seal.
            $this->assertTrue(
                DB::table('matrix_event_snapshots')->where('matrix_event_id', 'like', '$k3demo-testimony%')->exists(),
                'a testimony is sealed with its snapshot back-pointer'
            );

            // The flip: a SEATED judicial-attested row (attestation_id SET) ...
            $judicial = DB::table('matrix_carveout_log')->where('matrix_room_id', 'like', '%k3demo-halls%')->first();
            $this->assertNotNull($judicial, 'the seated judicial-attested carve-out is logged');
            $this->assertNotNull($judicial->attestation_id, 'seated ⇒ a judicial attestation is recorded');
            $this->assertTrue((bool) $judicial->is_seated_at_time);

            // ... and a BOOTSTRAP operator-relay row (attestation_id NULL) — the discriminator.
            $relay = DB::table('matrix_carveout_log')->where('matrix_room_id', 'like', '%k3demo-square%')->first();
            $this->assertNotNull($relay, 'the bootstrap operator-relay carve-out is logged');
            $this->assertNull($relay->attestation_id, 'bootstrap operator-relay ⇒ attestation_id NULL (un-forgeable as judicial)');
            $this->assertFalse((bool) $relay->is_seated_at_time);
        });
    }

    public function test_online_provisions_square_always_and_halls_iff_seated(): void
    {
        $this->onLivePg(function () {
            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('roomVersions')->andReturn(['default' => '12', 'available' => ['12']]);
                $m->shouldReceive('createRoom')->andReturnUsing(fn () => ['room_id' => '!'.Str::random(10).':localhost']);
                $m->shouldReceive('sendStateEvent')->andReturn([]);
            });
            app(RoleService::class)->flush();

            Artisan::call('matrix:demo', ['--fresh' => true]);

            $san = DB::table('jurisdictions')->where('slug', 'smr-1-san-marino')->value('id');
            $this->assertTrue(
                DB::table('matrix_rooms')->where('entity_id', $san)->where('space_type', 'public_square')->whereNull('tombstoned_at')->exists(),
                'a seated jurisdiction gets a #square'
            );
            $this->assertTrue(
                DB::table('matrix_rooms')->where('entity_id', $san)->where('space_type', 'halls')->whereNull('tombstoned_at')->exists(),
                'a seated jurisdiction gets #halls'
            );
        });
    }

    public function test_a_down_homeserver_never_fails_the_demo(): void
    {
        $this->onLivePg(function () {
            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('roomVersions')->andThrow(new \RuntimeException('homeserver down'));
                $m->shouldReceive('createRoom')->andThrow(new \RuntimeException('homeserver down'));
                $m->shouldReceive('sendStateEvent')->andThrow(new \RuntimeException('homeserver down'));
            });
            app(RoleService::class)->flush();

            $code = Artisan::call('matrix:demo', ['--fresh' => true]);

            $this->assertSame(0, $code, 'a down homeserver is best-effort — never fails the demo');
            $this->assertTrue(
                DB::table('matrix_event_snapshots')->where('matrix_event_id', 'like', '$k3demo-testimony%')->exists(),
                'the Plane-A testimony still seals even with the homeserver down'
            );
        });
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
