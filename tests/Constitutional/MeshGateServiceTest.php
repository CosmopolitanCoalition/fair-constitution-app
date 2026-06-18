<?php

namespace Tests\Constitutional;

use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MeshGateService;
use App\Services\Federation\TransportService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G8b) mesh readiness gates. The operator's "run the tests,
 * get the greens" for federation setup. The pins:
 *   1. a minted + enabled node with a transport passes the hard gates and is ready();
 *   2. disabling federation is a hard FAIL → not ready (the mesh endpoints are closed);
 *   3. not-yet-done steps (no peer, no sync) are WARN, never FAIL.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MeshGateServiceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_mesh_gates';

    public function test_an_enabled_minted_node_with_a_transport_is_ready(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);
            app(TransportService::class)->registerSelf('https', 'https://node.test', 200);

            $gates = $this->byKey(app(MeshGateService::class)->evaluate());

            // The hard gates we control in this txn.
            $this->assertSame(MeshGateService::PASS, $gates['federation_enabled']['status']);
            $this->assertSame(MeshGateService::PASS, $gates['identity']['status']);
            $this->assertSame(MeshGateService::PASS, $gates['transports']['status']);
            // The data-dependent gates (peer count / prior sync) are PASS-or-WARN on any
            // DB — never a hard FAIL.
            $this->assertNotSame(MeshGateService::FAIL, $gates['trusted_peer']['status']);
            $this->assertNotSame(MeshGateService::FAIL, $gates['sync_applied']['status']);
            $this->assertTrue(app(MeshGateService::class)->ready(), 'no hard FAIL → ready');
        });
    }

    public function test_disabled_federation_is_a_hard_fail(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(false);

            $gates = $this->byKey(app(MeshGateService::class)->evaluate());

            $this->assertSame(MeshGateService::FAIL, $gates['federation_enabled']['status']);
            $this->assertFalse(app(MeshGateService::class)->ready(), 'a closed mesh is not ready');
        });
    }

    /** @return array<string,array{key:string,label:string,status:string,detail:string}> */
    private function byKey(array $gates): array
    {
        $out = [];
        foreach ($gates as $g) {
            $out[$g['key']] = $g;
        }

        return $out;
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
