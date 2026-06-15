<?php

namespace Tests\Constitutional;

use App\Models\FederationPeer;
use App\Models\ForwardedWrite;
use App\Services\Federation\AuthorityResolver;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\ForwardedWriteRefused;
use App\Services\Federation\WriteRouterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G4 write-routing). A write for a jurisdiction we
 * don't own is forwarded to its authoritative leader and executed there through
 * the NORMAL ConstitutionalEngine — never a bypass — and recorded exactly-once.
 * The pins:
 *  1. AuthorityResolver reads ONLY authoritative_server_id → OURS | peer | UNTRACKED;
 *  2. the extracted resolver leaves authorityDisposition()'s 5-way mapping
 *     BYTE-FOR-BYTE unchanged (the behavior-preserving extraction);
 *  3. the router routes local-vs-forward off that authority;
 *  4. a forwarded write runs through engine->file() and is idempotent on
 *     (origin_server_id, idempotency_key) — a replay never re-files;
 *  5. a citizen-actor claim is refused pre-G-ID (system-only forwarding);
 *  6. a misdirected forward (we are not authoritative) is refused.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class WriteRoutingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_write_routing';

    public function test_authority_resolver_distinguishes_ours_peer_and_untracked(): void
    {
        $this->onLivePg(function () {
            $resolver = app(AuthorityResolver::class);
            [$ours, $owned] = $this->twoOwnJurisdictions();

            // We own it (authoritative_server_id IS NULL).
            $this->assertSame(AuthorityResolver::OURS, $resolver->authorityFor($ours));
            $this->assertTrue($resolver->isLocalAuthority($ours));
            $this->assertNull($resolver->authoritativeServerIdFor($ours));

            // A peer owns it.
            $peerId = (string) Str::uuid();
            DB::table('jurisdictions')->where('id', $owned)->update(['authoritative_server_id' => $peerId]);
            $this->assertSame($peerId, $resolver->authorityFor($owned));
            $this->assertFalse($resolver->isLocalAuthority($owned));
            $this->assertSame($peerId, $resolver->authoritativeServerIdFor($owned));

            // System scope / a jurisdiction we don't track.
            $this->assertSame(AuthorityResolver::UNTRACKED, $resolver->authorityFor(null));
            $this->assertSame(AuthorityResolver::UNTRACKED, $resolver->authorityFor((string) Str::uuid()));
            $this->assertTrue($resolver->isLocalAuthority(null));
            $this->assertNull($resolver->authoritativeServerIdFor(null));
        });
    }

    public function test_authority_disposition_mapping_is_behavior_preserving(): void
    {
        $this->onLivePg(function () {
            $svc = app(FederationSyncService::class);
            $disposition = new ReflectionMethod($svc, 'authorityDisposition');
            $disposition->setAccessible(true);

            $peerServerId = (string) Str::uuid();
            $peer = $this->makePeer($peerServerId);
            [$ours, $owned] = $this->twoOwnJurisdictions();

            // global record → apply
            $this->assertSame('apply', $disposition->invoke($svc, null, $peer));
            // a jurisdiction we don't track → apply (the peer's own)
            $this->assertSame('apply', $disposition->invoke($svc, (string) Str::uuid(), $peer));
            // we are authoritative → conflict (our copy wins)
            $this->assertSame('conflict', $disposition->invoke($svc, $ours, $peer));
            // the SENDING peer is authoritative → apply
            DB::table('jurisdictions')->where('id', $owned)->update(['authoritative_server_id' => $peerServerId]);
            $this->assertSame('apply', $disposition->invoke($svc, $owned, $peer));
            // a THIRD party is authoritative → non_authoritative
            DB::table('jurisdictions')->where('id', $owned)->update(['authoritative_server_id' => (string) Str::uuid()]);
            $this->assertSame('non_authoritative', $disposition->invoke($svc, $owned, $peer));
        });
    }

    public function test_router_chooses_local_vs_forward_off_authority(): void
    {
        $this->onLivePg(function () {
            $router = app(WriteRouterService::class);
            [$ours, $owned] = $this->twoOwnJurisdictions();

            $this->assertTrue($router->isLocalAuthority(['jurisdiction_id' => $ours]));
            $this->assertNull($router->routeFor(['jurisdiction_id' => $ours]));
            // No jurisdiction scope (system write) → local.
            $this->assertTrue($router->isLocalAuthority(['note' => 'system']));

            $peerId = (string) Str::uuid();
            DB::table('jurisdictions')->where('id', $owned)->update(['authoritative_server_id' => $peerId]);
            $this->assertFalse($router->isLocalAuthority(['jurisdiction_id' => $owned]));
            $this->assertSame($peerId, $router->routeFor(['jurisdiction_id' => $owned]));
        });
    }

    public function test_forwarded_write_runs_through_the_engine_and_is_idempotent(): void
    {
        $this->onLivePg(function () {
            $router = app(WriteRouterService::class);
            $peer = $this->makePeer((string) Str::uuid());

            // System-scoped (no jurisdiction_id → UNTRACKED → we execute locally).
            // F-LEG-003 with a bare payload reaches the engine deterministically
            // (the same call MirrorIsAuthoritativeForNothingTest relies on).
            $key = 'k-'.Str::uuid();
            $envelope = [
                'form_id' => 'F-LEG-003',
                'payload' => ['note' => 'a forwarded system filing'],
                'origin_server_id' => $peer->server_id,
                'idempotency_key' => $key,
            ];

            $before = (int) DB::table('audit_log')->max('seq');
            $out1 = $router->executeForwarded($envelope, $peer);
            $afterFirst = (int) DB::table('audit_log')->max('seq');

            // It reached the engine (an audit edge — executed or rejected — was
            // appended) and the outcome was settled + recorded exactly once.
            $this->assertGreaterThan($before, $afterFirst, 'the forward reached the engine');
            $this->assertContains($out1['status'], [ForwardedWrite::STATUS_EXECUTED, ForwardedWrite::STATUS_REJECTED]);
            $this->assertSame(1, ForwardedWrite::where('idempotency_key', $key)->count());

            // Replay — identical outcome, the engine is NOT re-invoked (no new
            // audit edge), still exactly one ledger row.
            $out2 = $router->executeForwarded($envelope, $peer);
            $afterReplay = (int) DB::table('audit_log')->max('seq');

            $this->assertSame($out1, $out2, 'a replay returns the recorded outcome verbatim');
            $this->assertSame($afterFirst, $afterReplay, 'a replay never re-files');
            $this->assertSame(1, ForwardedWrite::where('idempotency_key', $key)->count());
        });
    }

    public function test_a_forwarded_unverifiable_actor_claim_is_refused(): void
    {
        $this->onLivePg(function () {
            $router = app(WriteRouterService::class);
            $peer = $this->makePeer((string) Str::uuid());

            $key = 'k-'.Str::uuid();
            $envelope = [
                'form_id' => 'F-LEG-003',
                'payload' => ['note' => 'forwarded with an unverifiable actor'],
                // A bare claim with no G-ID attestation block — citizen forwarding
                // requires a verifiable attestation (G-ID), so this is refused as
                // malformed; nothing reaches the engine.
                'actor' => ['user_id' => (string) Str::uuid()],
                'origin_server_id' => $peer->server_id,
                'idempotency_key' => $key,
            ];

            try {
                $router->executeForwarded($envelope, $peer);
                $this->fail('an unverifiable citizen-actor claim must be refused');
            } catch (ForwardedWriteRefused $e) {
                $this->assertSame(422, $e->status, 'a forward whose actor block carries no attestation is malformed');
            }

            // Refused before the engine — nothing claimed, nothing filed.
            $this->assertSame(0, ForwardedWrite::where('idempotency_key', $key)->count());
        });
    }

    public function test_a_misdirected_forward_is_refused(): void
    {
        $this->onLivePg(function () {
            $router = app(WriteRouterService::class);
            $peer = $this->makePeer((string) Str::uuid());
            [, $owned] = $this->twoOwnJurisdictions();

            // A jurisdiction a peer owns — this instance is NOT authoritative for it.
            DB::table('jurisdictions')->where('id', $owned)->update(['authoritative_server_id' => (string) Str::uuid()]);

            $key = 'k-'.Str::uuid();
            $envelope = [
                'form_id' => 'F-LEG-003',
                'payload' => ['jurisdiction_id' => $owned, 'note' => 'misdirected'],
                'origin_server_id' => $peer->server_id,
                'idempotency_key' => $key,
            ];

            try {
                $router->executeForwarded($envelope, $peer);
                $this->fail('a forward we are not authoritative for must be refused');
            } catch (ForwardedWriteRefused $e) {
                $this->assertSame(421, $e->status);
            }

            $this->assertSame(0, ForwardedWrite::where('idempotency_key', $key)->count());
        });
    }

    /**
     * Two distinct jurisdictions this instance is authoritative for (NULL
     * authoritative_server_id). Mutations are rolled back with the live-pg txn.
     *
     * @return array{0:string,1:string}
     */
    private function twoOwnJurisdictions(): array
    {
        $ids = DB::table('jurisdictions')
            ->whereNull('authoritative_server_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->all();

        if (count($ids) < 2) {
            $this->markTestSkipped('needs ≥2 seeded jurisdictions we are authoritative for');
        }

        return [(string) $ids[0], (string) $ids[1]];
    }

    private function makePeer(string $serverId): FederationPeer
    {
        return FederationPeer::create([
            'server_id' => $serverId,
            'name' => 'test-peer',
            'url' => 'https://peer.test',
            'public_key' => base64_encode(str_repeat('k', 32)),
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'relation' => FederationPeer::RELATION_SOVEREIGN,
        ]);
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
