<?php

namespace Tests\Feature;

use App\Models\ClusterAdoptionRequest;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G3c) — the join-wizard NEGOTIATION rides the /adopt body and is persisted
 * on the host's adoption request for the operator to review. Pins: a keyless adopt
 * carries requested_relation / scope / applicant_name / note through the raw signed
 * body into the ClusterAdoptionRequest; co_member is ADVISORY (still STATUS_PENDING,
 * still admission_method=request — it never auto-grants anything); a bogus relation
 * is sanitized to null (cannot poison the CHECK constraint).
 *
 * Live-pg posture; the applicant signs the tofu /adopt with its own key.
 */
class JoinNegotiationTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_join_negotiation';

    public function test_negotiation_rides_the_adopt_body_into_the_request(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            app(InstanceIdentityService::class)->setEnabled(true);

            $scope = (string) Str::uuid();

            // A keyless adopt carrying the full wizard negotiation.
            [$serverId] = $this->adopt([
                'requested_relation'              => 'co_member',
                'requested_scope_jurisdiction_id' => $scope,
                'applicant_name'                  => 'Test Mirror',
                'note'                            => 'we run a vetted node here',
            ], 202);

            $req = ClusterAdoptionRequest::query()->where('applicant_server_id', $serverId)->first();
            $this->assertNotNull($req, 'a pending adoption request is created');
            $this->assertSame(ClusterAdoptionRequest::STATUS_PENDING, $req->status, 'co_member is advisory — still pending');
            $this->assertSame('co_member', $req->requested_relation);
            $this->assertSame($scope, $req->requested_scope_jurisdiction_id);
            $this->assertSame('Test Mirror', $req->applicant_name);
            $this->assertSame('we run a vetted node here', $req->note);
            $this->assertSame('https://applicant.example', $req->applicant_url);

            // A bogus relation is sanitized to null — it cannot poison the enum CHECK.
            [$serverId2] = $this->adopt(['requested_relation' => 'sovereign', 'applicant_name' => 'Bogus'], 202);
            $req2 = ClusterAdoptionRequest::query()->where('applicant_server_id', $serverId2)->first();
            $this->assertNotNull($req2);
            $this->assertNull($req2->requested_relation, 'an out-of-enum relation is dropped to null');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /**
     * Sign + POST a keyless /adopt with the given negotiation. Returns [serverId].
     *
     * @param  array<string,mixed>  $negotiation
     * @return array{0:string}
     */
    private function adopt(array $negotiation, int $expect): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_bin2base64(sodium_crypto_sign_publickey($keypair), SODIUM_BASE64_VARIANT_ORIGINAL);
        $serverId = (string) Str::uuid();

        $body = json_encode(array_merge([
            'public_key' => $public,
            'nonce'      => bin2hex(random_bytes(16)),
            'url'        => 'https://applicant.example',
        ], $negotiation), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ts = now()->timestamp;
        $signingString = FederationClient::signingString('POST', '/api/federation/adopt', $ts, (string) $body);
        $signature = sodium_bin2base64(sodium_crypto_sign_detached($signingString, $secret), SODIUM_BASE64_VARIANT_ORIGINAL);

        $resp = $this->call('POST', '/api/federation/adopt',
            server: [
                'HTTP_X_FEDERATION_SERVER_ID' => $serverId,
                'HTTP_X_FEDERATION_TIMESTAMP' => (string) $ts,
                'HTTP_X_FEDERATION_SIGNATURE' => $signature,
                'CONTENT_TYPE'                => 'application/json',
                'HTTP_ACCEPT'                 => 'application/json',
            ],
            content: (string) $body);

        $resp->assertStatus($expect);

        return [$serverId];
    }
}
