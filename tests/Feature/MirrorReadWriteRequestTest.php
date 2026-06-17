<?php

namespace Tests\Feature;

use App\Models\ClusterMembership;
use App\Models\InstanceSettings;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Mirror\MirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G3c) — the MIRROR side of the read-write petition: the GUI front door
 * to the governed flip. A mirror operator composes + SENDS a signed S2S petition
 * to its host's /api/federation/request-read-write. Pins: the guard (a non-mirror
 * cannot petition), and that the outbound request is signed, hits the host URL,
 * and carries the subtree + note. The GRANT is the governed flow (G6 / G-VER) and
 * is NOT exercised here — sending the petition flips nothing locally.
 *
 * Live-pg posture; the host is pinned via the real MirrorService primitives and
 * the outbound HTTP is faked (we assert the composed request, not a live host).
 */
class MirrorReadWriteRequestTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_mirror_rw';

    public function test_a_mirror_petitions_its_host_for_read_write_and_a_non_mirror_cannot(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $mirror = app(MirrorService::class);

            // Deterministic clean slate: not a mirror, no active mirror membership.
            ClusterMembership::query()
                ->where('role', ClusterMembership::ROLE_MIRROR)
                ->whereNotIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
                ->update(['state' => ClusterMembership::STATE_DEPARTED]);
            $settings = InstanceSettings::current();
            $settings->mirror_of_server_id = null;
            $settings->mirror_adopted_at = null;
            $settings->save();

            // A non-mirror is refused (a mirror stays authoritative for nothing;
            // a non-mirror has no host to petition).
            try {
                $mirror->petitionReadWrite((string) Str::uuid(), null);
                $this->fail('a non-mirror must not be able to petition for read-write');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not a mirror', $e->getMessage());
            }

            // Become a read-only mirror of a (fake) host with a known URL.
            $hostServerId = (string) Str::uuid();
            $hostPublicKey = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL);
            $host = $mirror->pinHost($hostServerId, $hostPublicKey, ['url' => 'https://host.example']);
            $membership = $mirror->openMirrorMembership($host, ClusterMembership::ADMISSION_JOIN_KEY);
            $mirror->markMirrorLive($membership, $hostServerId);
            $this->assertTrue($mirror->isMirror(), 'setup should leave this instance a mirror');

            $jur = (string) (DB::table('jurisdictions')->whereNull('deleted_at')->value('id') ?? Str::uuid());

            Http::fake([
                'https://host.example/*' => Http::response(
                    ['status' => 'received', 'request_id' => (string) Str::uuid(), 'state' => 'submitted'],
                    200,
                ),
            ]);

            $ack = $mirror->petitionReadWrite($jur, 'we run a vetted node here');

            $this->assertSame('submitted', $ack['state'], 'the host intake acknowledgement is surfaced');
            $this->assertNotNull($ack['request_id']);

            // The outbound petition is signed, hits the host intake, and carries the subtree + note.
            Http::assertSent(function ($request) use ($jur, $identity) {
                $payload = json_decode($request->body(), true) ?? [];

                return str_ends_with($request->url(), '/api/federation/request-read-write')
                    && $request->method() === 'POST'
                    && $request->header('X-Federation-Server-Id')[0] === $identity->serverId()
                    && $request->hasHeader('X-Federation-Signature')
                    && ($payload['root_jurisdiction_id'] ?? null) === $jur
                    && ($payload['note'] ?? null) === 'we run a vetted node here';
            });
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
