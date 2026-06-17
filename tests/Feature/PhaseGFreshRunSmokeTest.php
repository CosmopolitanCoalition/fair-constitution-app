<?php

namespace Tests\Feature;

use App\Models\FederationPeer;
use App\Models\ReadWriteRequest;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\OperatorIdentityService;
use App\Services\Mirror\MirrorJoinKeyService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G — the canonical FRESH-RUN smoke test: the whole read-only adoption dance
 * driven through the REAL HTTP endpoints + signature middleware in one test, so a
 * fresh cross-machine run's failure can only be a deploy/environment issue, not a
 * logic bug. Walks: host operator mints a join key → a (simulated) mirror adopts
 * over POST /api/federation/adopt (keyed, tofu) → it is pinned as a read-only
 * mirror and we host it → the mirror petitions read-write over the pinned S2S
 * endpoint → the host operator DENIES it from the console route. Granting stays the
 * governed flow (G-VER) and is deliberately not exercised.
 *
 * Live-pg posture; a simulated mirror (whose key the test holds) stands in for the
 * second box.
 */
class PhaseGFreshRunSmokeTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_fresh_run';

    public function test_mint_adopt_petition_deny_end_to_end(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $operator = app(OperatorIdentityService::class)->register('runop_'.Str::lower(Str::random(8)), 'correct horse battery');

            // 1) The host operator mints an invite key.
            [$plaintext, $key] = app(MirrorJoinKeyService::class)->mint(1, null);
            $this->assertTrue($key->isLive());

            // 2) A simulated mirror adopts with the key (keyed /adopt, tofu-signed by its own key).
            $mirrorKeypair = sodium_crypto_sign_keypair();
            $mirrorSecret = sodium_crypto_sign_secretkey($mirrorKeypair);
            $mirrorPublic = sodium_bin2base64(sodium_crypto_sign_publickey($mirrorKeypair), SODIUM_BASE64_VARIANT_ORIGINAL);
            $mirrorServerId = (string) Str::uuid();

            $adoptBody = json_encode([
                'public_key' => $mirrorPublic,
                'key'        => $plaintext,
                'nonce'      => bin2hex(random_bytes(16)),
                'url'        => 'https://mirror.example',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->call('POST', '/api/federation/adopt',
                server: $this->signed('POST', '/api/federation/adopt', (string) $adoptBody, $mirrorServerId, $mirrorSecret),
                content: (string) $adoptBody)
                ->assertStatus(200)
                ->assertJsonPath('admitted', true);

            // The applicant is now our read-only mirror (authoritative for nothing) and we host it.
            $peer = FederationPeer::query()->where('server_id', $mirrorServerId)->first();
            $this->assertNotNull($peer, 'the mirror is pinned as a peer');
            $this->assertSame(FederationPeer::RELATION_MIRROR, $peer->relation, 'pinned as relation=mirror');

            // 3) The mirror (now pinned) petitions for read-write over the signed S2S endpoint.
            $jur = (string) (DB::table('jurisdictions')->whereNull('deleted_at')->value('id') ?? Str::uuid());
            $rwBody = json_encode([
                'root_jurisdiction_id' => $jur,
                'note'                 => 'we run a vetted node here',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->call('POST', '/api/federation/request-read-write',
                server: $this->signed('POST', '/api/federation/request-read-write', (string) $rwBody, $mirrorServerId, $mirrorSecret),
                content: (string) $rwBody)
                ->assertStatus(200)
                ->assertJsonPath('state', ReadWriteRequest::STATUS_SUBMITTED);

            $rw = ReadWriteRequest::query()
                ->where('applicant_server_id', $mirrorServerId)
                ->where('status', ReadWriteRequest::STATUS_SUBMITTED)
                ->first();
            $this->assertNotNull($rw, 'the petition is recorded as a submitted intake');

            // 4) The host operator denies it from the console (granting is the governed G-VER flow).
            $this->actingAs($operator, 'operator')
                ->post("/federation/host/rw/{$rw->id}/deny")
                ->assertRedirect();
            $this->assertSame(ReadWriteRequest::STATUS_DENIED, $rw->fresh()->status, 'the operator denied the petition');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /** @return array<string,string> */
    private function signed(string $method, string $target, string $body, string $serverId, string $secret): array
    {
        $ts = now()->timestamp;
        $signingString = FederationClient::signingString($method, $target, $ts, $body);
        $signature = sodium_bin2base64(sodium_crypto_sign_detached($signingString, $secret), SODIUM_BASE64_VARIANT_ORIGINAL);

        return [
            'HTTP_X_FEDERATION_SERVER_ID' => $serverId,
            'HTTP_X_FEDERATION_TIMESTAMP' => (string) $ts,
            'HTTP_X_FEDERATION_SIGNATURE' => $signature,
            'CONTENT_TYPE'                => 'application/json',
            'HTTP_ACCEPT'                 => 'application/json',
        ];
    }
}
