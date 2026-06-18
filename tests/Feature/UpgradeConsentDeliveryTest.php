<?php

namespace Tests\Feature;

use App\Models\PeerUpgradeConsent;
use App\Models\PeerUpgradeProposal;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G-VER / A2): Meter C consent delivery over S2S. A
 * co-affected pinned peer POSTs its mesh consent to the proposer's
 * /api/federation/upgrade/consent; the proposer records it ONLY after re-verifying the
 * sender is authoritative for a jurisdiction in the affected subtree — delivery confers
 * no standing on its own. The pins:
 *   1. a co-affected trust-peer's signed consent is recorded (result 'yes', meter peer);
 *   2. a peer with NO stake in the subtree is refused with a citation, nothing recorded.
 *
 * Live-pg posture; a simulated trusted peer stands in for the co-affected instance.
 */
class UpgradeConsentDeliveryTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_upgrade_consent';

    public function test_a_co_affected_peer_consent_is_recorded(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $leaf = $this->leafJurisdiction();

            // The peer is authoritative for the (leaf) affected subtree → co-affected.
            DB::table('jurisdictions')->where('id', $leaf)->update(['authoritative_server_id' => $peer->server_id]);
            $proposal = $this->openProposal($leaf);

            [$resp] = $this->postConsent($proposal->id, true, (string) $peer->server_id);

            $resp->assertStatus(200)->assertJsonPath('status', 'recorded')->assertJsonPath('result', 'yes');

            $consent = PeerUpgradeConsent::query()
                ->where('proposal_id', $proposal->id)
                ->where('meter', PeerUpgradeConsent::METER_PEER)
                ->where('peer_server_id', $peer->server_id)
                ->first();
            $this->assertNotNull($consent, 'the peer consent is recorded');
            $this->assertSame(PeerUpgradeConsent::RESULT_YES, $consent->result);
        });
    }

    public function test_a_peer_with_no_stake_in_the_subtree_is_refused(): void
    {
        $this->onLivePg(function () {
            $peer = $this->makeTrustedPeer();
            $leaf = $this->leafJurisdiction();

            // A THIRD party owns the subtree — this peer has no standing.
            DB::table('jurisdictions')->where('id', $leaf)->update(['authoritative_server_id' => (string) Str::uuid()]);
            $proposal = $this->openProposal($leaf);

            [$resp] = $this->postConsent($proposal->id, true, (string) $peer->server_id);

            $resp->assertStatus(409);
            $this->assertSame(0, PeerUpgradeConsent::query()
                ->where('proposal_id', $proposal->id)
                ->where('meter', PeerUpgradeConsent::METER_PEER)
                ->count(), 'nothing is recorded for a peer with no stake');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function openProposal(string $rootJurisdictionId): PeerUpgradeProposal
    {
        return PeerUpgradeProposal::create([
            'kind' => PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP,
            'from_constitutional_version' => 'aaaaaaaaaaaa',
            'to_constitutional_version' => 'bbbbbbbbbbbb',
            'affected_root_jurisdiction_id' => $rootJurisdictionId,
            'proposed_by_server_id' => app(InstanceIdentityService::class)->serverId(),
            'signature' => 'test-proposal-sig',
            'status' => PeerUpgradeProposal::STATUS_OPEN,
        ]);
    }

    private function leafJurisdiction(): string
    {
        $row = DB::selectOne(
            'SELECT id FROM jurisdictions j WHERE j.deleted_at IS NULL AND NOT EXISTS '
            .'(SELECT 1 FROM jurisdictions c WHERE c.parent_id = j.id AND c.deleted_at IS NULL) LIMIT 1'
        );

        return (string) ($row->id ?? Str::uuid());
    }

    /** @return array{0:\Illuminate\Testing\TestResponse} */
    private function postConsent(string $proposalId, bool $consented, string $serverId): array
    {
        $body = json_encode(
            ['proposal_id' => $proposalId, 'consented' => $consented, 'signature' => 'peer-provenance'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $target = '/api/federation/upgrade/consent';

        return [$this->call('POST', $target,
            server: $this->signedRequest('POST', $target, $body, $serverId),
            content: $body)];
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

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
