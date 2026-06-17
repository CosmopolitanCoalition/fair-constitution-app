<?php

namespace Tests\Feature;

use App\Models\ReadWriteRequest;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\ReadWriteRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G3c) — the read-write petition intake. A pinned mirror POSTs a signed
 * petition to /api/federation/request-read-write; it is recorded as a submitted
 * read_write_requests intake (NOT an adoption — the applicant stays a read-only
 * mirror). Pins: the S2S round-trip, idempotency per (applicant, jurisdiction),
 * and the host's deny. The GRANT is the governed flow (G6 / G-VER), out of scope.
 *
 * Live-pg posture; a simulated trusted peer stands in for the requesting mirror.
 */
class ReadWriteRequestTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_rw_request';

    public function test_a_pinned_mirror_petitions_for_read_write_and_the_host_denies(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();
            $jur = (string) (DB::table('jurisdictions')->whereNull('deleted_at')->value('id') ?? Str::uuid());

            // S2S: the mirror submits a read-write petition.
            $body = json_encode(
                ['root_jurisdiction_id' => $jur, 'note' => 'we run a vetted node here'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $target = '/api/federation/request-read-write';

            $resp = $this->call('POST', $target,
                server: $this->signedRequest('POST', $target, $body, (string) $peer->server_id),
                content: $body);

            $resp->assertStatus(200)->assertJsonPath('state', ReadWriteRequest::STATUS_SUBMITTED);

            $forPeer = fn () => ReadWriteRequest::query()
                ->where('applicant_server_id', $peer->server_id)
                ->where('status', ReadWriteRequest::STATUS_SUBMITTED)->count();
            $this->assertSame(1, $forPeer(), 'the petition is recorded as a submitted intake');

            // Idempotent: a duplicate submit for the same (applicant, jurisdiction) does not stack.
            app(ReadWriteRequestService::class)->submit((string) $peer->server_id, (string) $peer->public_key, $jur, 'again');
            $this->assertSame(1, $forPeer(), 'a duplicate open petition is idempotent');

            // The host denies it.
            $req = ReadWriteRequest::query()->where('applicant_server_id', $peer->server_id)->first();
            app(ReadWriteRequestService::class)->deny($req);
            $this->assertSame(ReadWriteRequest::STATUS_DENIED, $req->fresh()->status);
            $this->assertSame(0, $forPeer(), 'a denied petition is no longer pending');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
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
            'CONTENT_TYPE'                => 'application/json',
            'HTTP_ACCEPT'                 => 'application/json',
        ];
    }
}
