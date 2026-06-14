<?php

namespace Tests\Feature;

use App\Models\AuditCheckpoint;
use App\Models\PublicRecord;
use App\Models\SyncLogEntry;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * F2 round-trip — a peer PUSHES a signed tail to POST /api/federation/sync; it
 * passes the VerifyPeerSignature middleware, the tail is verified + applied, and
 * the exchange lands in sync_log + a fresh checkpoint, with the peer watermark
 * advanced. The full FF&C path over HTTP.
 *
 * Live-pg posture (PhaseDPageSmokeTest): guarded connection set as default so
 * the HTTP request shares it, one transaction always rolled back.
 */
class FederationRoundTripTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_fed_roundtrip';

    public function test_a_signed_push_applies_over_http_and_is_ledgered(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();

            // Delegate a jurisdiction to the peer so its record mirrors.
            $peerOwns = (string) $conn->table('jurisdictions')->whereNull('deleted_at')->value('id');
            if ($peerOwns === '') {
                $this->markTestSkipped('Live DB needs a jurisdiction — seed it first.');
            }
            $conn->table('jurisdictions')->where('id', $peerOwns)->update(['authoritative_server_id' => $peer->server_id]);

            $record = $this->record($peerOwns);
            $tail = $this->signTail($this->craftTail($conn, $peer, [$record]));
            $body = json_encode($tail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $checkpointsBefore = AuditCheckpoint::query()->count();

            $response = $this->call(
                'POST',
                '/api/federation/sync',
                server: $this->signedRequest('POST', '/api/federation/sync', $body, $peer->server_id),
                content: $body,
            );

            $response->assertStatus(200)->assertJsonPath('result', SyncLogEntry::RESULT_APPLIED);

            $this->assertTrue(
                PublicRecord::query()->where('id', $record['id'])->where('source_server_id', $peer->server_id)->exists(),
                'the pushed record is mirrored over HTTP'
            );
            $this->assertTrue(
                SyncLogEntry::query()->where('peer_id', $peer->id)
                    ->where('direction', SyncLogEntry::DIRECTION_INBOUND)
                    ->where('result', SyncLogEntry::RESULT_APPLIED)->exists(),
                'the exchange is on the sync ledger'
            );
            $this->assertGreaterThan($checkpointsBefore, AuditCheckpoint::query()->count(), 'a checkpoint is published');

            $peer->refresh();
            $this->assertSame((int) $tail['to_seq'], (int) $peer->peer_head_seq, 'the inbound watermark advanced to the tail head');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /** @return array<string,string> */
    private function signedRequest(string $method, string $target, string $body, string $serverId): array
    {
        $timestamp = now()->timestamp;
        $signingString = FederationClient::signingString($method, $target, $timestamp, $body);
        $signature = sodium_bin2base64(sodium_crypto_sign_detached($signingString, $this->peerSecret), SODIUM_BASE64_VARIANT_ORIGINAL);

        return [
            'HTTP_X_FEDERATION_SERVER_ID' => $serverId,
            'HTTP_X_FEDERATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FEDERATION_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }
}
