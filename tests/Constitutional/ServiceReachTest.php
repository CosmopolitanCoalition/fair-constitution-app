<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationPeer;
use App\Models\FederationTransportHealth;
use App\Models\InstanceCapability;
use App\Models\Jurisdiction;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\NoReachableHolder;
use App\Services\Federation\ServiceReachService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — governed-live-service mesh reach (Mesh Roles ★20/★21/★25, the mixed environment).
 * THE INVARIANTS: reach accepts ONLY the live-service channels (matrix.homeserver / voice.sfu) and refuses
 * every other channel (copy AND non-live governed) — so the copy-channel governed refusal is NOT weakened;
 * a node that hosts the channel serves LOCALLY; otherwise it picks the HEALTHIEST/fastest reachable trusted
 * peer and SKIPS a tripped (open-circuit) holder; no reachable holder SAFE-DEGRADES (NoReachableHolder, not
 * a hard fail); and a reach NEVER mutates `authoritative_server_id` (it's a read, not an authority transfer).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ServiceReachTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_servicereach';

    public function test_reach_accepts_only_live_service_channels(): void
    {
        $this->onLivePg(function () {
            $reach = app(ServiceReachService::class);

            // Copy / self-asserted + NON-live governed channels are all refused — only live services reach.
            foreach (['etl', 'mirror', 'broker.dns', 'authority.grant', 'client.serve'] as $channel) {
                $threw = false;
                try {
                    $reach->reachLiveService($channel);
                } catch (ConstitutionalViolation) {
                    $threw = true;
                }
                $this->assertTrue($threw, "[{$channel}] must be refused — it is not a live-service channel");
            }
        });
    }

    public function test_a_node_that_hosts_the_channel_serves_locally(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $this->clearSlate();

            // WE hold matrix.homeserver.
            InstanceCapability::create([
                'server_id' => $identity->serverId(), 'capability' => 'matrix.homeserver',
                'is_self' => true, 'enabled' => true, 'priority' => 100,
            ]);

            $p = app(ServiceReachService::class)->reachLiveService('matrix.homeserver');
            $this->assertTrue($p['local']);
            $this->assertSame($identity->serverId(), $p['server_id']);
            $this->assertSame((string) config('matrix.server_name'), $p['service_endpoint']);
        });
    }

    public function test_reach_picks_the_healthiest_peer_and_skips_a_dead_holder(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $this->clearSlate(); // we do NOT host it; only the peers below hold it

            $fast = $this->holder('https://fast.example', latencyMs: 20, circuit: 'closed');
            $this->holder('https://slow.example', latencyMs: 200, circuit: 'closed');
            $this->holder('https://dead.example', latencyMs: 5, circuit: 'open'); // tripped → unreachable

            $reach = app(ServiceReachService::class);
            $ranked = app(\App\Services\Federation\CapabilityService::class)->holdersOfRanked('matrix.homeserver');

            // Healthiest+fastest first; the dead one ranks last and is not attemptable.
            $this->assertSame((string) $fast->server_id, $ranked[0]['server_id']);
            $this->assertFalse($ranked[count($ranked) - 1]['attemptable'], 'the open-circuit holder is DOWN');

            // The reach picks the fast healthy peer (skips slow + dead), resolving its homeserver endpoint.
            $p = $reach->reachLiveService('matrix.homeserver');
            $this->assertFalse($p['local']);
            $this->assertSame((string) $fast->server_id, $p['server_id']);
            $this->assertSame('fast.example', $p['service_endpoint'], 'matrix endpoint = the peer\'s server_name');
        });
    }

    public function test_safe_degrades_and_never_mutates_authority(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $this->clearSlate();

            // The ONLY holder is dead (tripped circuit) → no reachable holder.
            $this->holder('https://dead-only.example', latencyMs: 5, circuit: 'open');

            // A jurisdiction owned by some authority — reach must never touch this.
            $authority = (string) Str::uuid();
            $j = new Jurisdiction();
            $j->forceFill([
                'id' => (string) Str::uuid(), 'name' => 'Reach '.Str::random(5),
                'slug' => 'reach-'.Str::lower(Str::random(10)), 'adm_level' => 5,
                'parent_id' => null, 'source' => 'user_defined', 'authoritative_server_id' => $authority,
            ])->save();

            $threw = false;
            try {
                app(ServiceReachService::class)->reachLiveService('matrix.homeserver', $j->id);
            } catch (NoReachableHolder $e) {
                $threw = true;
                $this->assertSame('matrix.homeserver', $e->capability);
            }
            $this->assertTrue($threw, 'no reachable holder safe-degrades (NoReachableHolder), never a hard fail');

            $this->assertSame($authority, (string) Jurisdiction::query()->whereKey($j->id)->value('authoritative_server_id'),
                'reach is a READ — it never mutates authoritative_server_id');
        });
    }

    /** Disable every existing live-service capability row so the ranking is deterministic against live data. */
    private function clearSlate(): void
    {
        InstanceCapability::query()
            ->whereIn('capability', ServiceReachService::LIVE_SERVICE_CHANNELS)
            ->update(['enabled' => false]);
    }

    /** A trusted peer that holds matrix.homeserver, with a known transport health on its (legacy-url) rung. */
    private function holder(string $url, int $latencyMs, string $circuit): FederationPeer
    {
        $peer = FederationPeer::create([
            'server_id' => (string) Str::uuid(),
            'name' => 'Holder '.Str::random(4),
            'url' => $url,
            'public_key' => 'k-'.Str::random(8),
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
            'metadata' => ['schema_version' => '1'],
        ]);

        InstanceCapability::create([
            'server_id' => (string) $peer->server_id, 'capability' => 'matrix.homeserver',
            'is_self' => false, 'enabled' => true, 'priority' => 100,
        ]);

        // Health keyed to the inferred legacy-url rung (transport https for an https:// url, url = peer.url).
        FederationTransportHealth::create([
            'server_id' => (string) $peer->server_id,
            'transport' => 'https',
            'url' => $url,
            'circuit_state' => $circuit,
            'latency_ema_ms' => $latencyMs,
            'last_fail_at' => $circuit === 'open' ? now() : null, // fresh failure → stays open → not attemptable
            'consecutive_failures' => $circuit === 'open' ? 5 : 0,
        ]);

        return $peer;
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
