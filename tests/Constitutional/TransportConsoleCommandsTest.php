<?php

namespace Tests\Constitutional;

use App\Models\DirectoryEntry;
use App\Models\FederationTransport;
use App\Models\Jurisdiction;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G8b) transport/directory CLI glue. The bootstrap
 * layer drives the registry through these commands (previously reachable only from
 * tinker). The pins:
 *   1. transport:register adds an enabled self-transport; transport:list shows it;
 *   2. an unknown transport is REJECTED (the same hard allowlist the engine enforces);
 *   3. transport:disable stops advertising/dialing a channel (reversibly);
 *   4. directory:publish signs THIS node's endpoint set for a jurisdiction, and
 *      refuses when no transports are registered yet.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class TransportConsoleCommandsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_transport_cli';

    public function test_register_list_and_disable_round_trip(): void
    {
        $this->onLivePg(function () {
            $serverId = app(InstanceIdentityService::class)->serverId();

            $this->artisan('transport:register', ['transport' => 'https', 'address' => 'https://node.test:8081', '--priority' => 200])
                ->assertSuccessful();
            $this->artisan('transport:list')->expectsOutputToContain('https://node.test:8081')->assertSuccessful();

            $row = FederationTransport::query()->where('server_id', $serverId)->where('transport', 'https')->firstOrFail();
            $this->assertTrue((bool) $row->is_self);
            $this->assertTrue((bool) $row->enabled);
            $this->assertSame(200, (int) $row->priority);

            $this->artisan('transport:disable', ['transport' => 'https'])->assertSuccessful();
            $this->assertFalse((bool) $row->refresh()->enabled);
        });
    }

    public function test_register_rejects_an_unknown_transport(): void
    {
        $this->onLivePg(function () {
            $this->artisan('transport:register', ['transport' => 'carrier_pigeon', 'address' => 'coop://1'])
                ->assertFailed();

            $this->assertSame(0, FederationTransport::query()->where('transport', 'carrier_pigeon')->count());
        });
    }

    public function test_directory_publish_signs_our_endpoints_for_a_jurisdiction(): void
    {
        $this->onLivePg(function () {
            $serverId = app(InstanceIdentityService::class)->serverId();
            $jurisdictionId = (string) Jurisdiction::query()->value('id');
            $this->assertNotSame('', $jurisdictionId, 'the dev DB has at least one jurisdiction');

            // No transports yet → refuse.
            $this->artisan('directory:publish', ['jurisdiction' => $jurisdictionId])->assertFailed();

            $this->artisan('transport:register', ['transport' => 'https', 'address' => 'https://node.test:8081', '--priority' => 200])
                ->assertSuccessful();
            $this->artisan('directory:publish', ['jurisdiction' => $jurisdictionId])->assertSuccessful();

            $entry = DirectoryEntry::query()
                ->where('jurisdiction_id', $jurisdictionId)
                ->where('server_id', $serverId)
                ->firstOrFail();
            $this->assertNotEmpty($entry->signature, 'the published entry is signed');
            $this->assertSame('https://node.test:8081', $entry->endpoints[0]['url']);
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
