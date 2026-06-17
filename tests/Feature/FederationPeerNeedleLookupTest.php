<?php

namespace Tests\Feature;

use App\Models\FederationPeer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Regression: the federation CLI commands (cold-sync, flip:export,
 * peer:handshake, sync:push) accept a "{peer}" arg that may be a server_id
 * (uuid) OR a URL. The lookup used to do
 * `where('server_id', $needle)->orWhere('url', ...)`, which on Postgres throws
 * SQLSTATE 22P02 ("invalid input syntax for type uuid") when $needle is a URL —
 * the exact crash a fresh mirror hit running `federation:cold-sync <host-url>`.
 * FederationPeer::scopeMatchingNeedle branches on the needle's shape; both forms
 * resolve the same peer and a URL no longer fatals.
 *
 * Live-pg posture (FederationRoundTripTest): a guarded connection set as the
 * default, one transaction always rolled back.
 */
class FederationPeerNeedleLookupTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_fed_needle';

    public function test_a_peer_resolves_by_both_server_id_and_url_without_a_uuid_cast_error(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            // server_id = a uuid, url = http://host.docker.internal:9998
            $peer = $this->makeTrustedPeer();

            // By server_id (uuid) — the only form that worked before.
            $byId = FederationPeer::query()->matchingNeedle($peer->server_id)->first();
            $this->assertNotNull($byId, 'peer should resolve by server_id');
            $this->assertSame($peer->server_id, $byId->server_id);

            // By URL — used to throw 22P02 (uuid cast) on Postgres. This is the fix.
            $byUrl = FederationPeer::query()->matchingNeedle($peer->url)->first();
            $this->assertNotNull($byUrl, 'peer should resolve by url (no uuid-cast crash)');
            $this->assertSame($peer->server_id, $byUrl->server_id);

            // A trailing slash is trimmed to match how urls are stored.
            $bySlashUrl = FederationPeer::query()->matchingNeedle($peer->url.'/')->first();
            $this->assertNotNull($bySlashUrl, 'peer should resolve by url with a trailing slash');
            $this->assertSame($peer->server_id, $bySlashUrl->server_id);

            // A non-matching uuid resolves nothing — and does not error.
            $this->assertNull(
                FederationPeer::query()->matchingNeedle((string) Str::uuid())->first(),
                'an unknown uuid should match no peer'
            );
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($originalDefault);
        }
    }
}
