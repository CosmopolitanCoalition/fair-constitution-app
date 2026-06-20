<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationPeer;
use App\Models\Jurisdiction;
use App\Models\OperatorAccount;
use App\Models\PeerUpgradeProposal;
use App\Services\Federation\CapabilityProber;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\MeshRoleGrantService;
use App\Services\PeerUpgradeAgreementService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Mesh Roles & Channels of Trust (★5-7), the qualify → request → approve → join
 * lifecycle for GOVERNED capability channels. A capability grant flows through the IDENTICAL dual-meter
 * consent (Meter A operator board / Meter B seated government / Meter C co-affected peers) that gates a
 * constitutional bump — reused verbatim, no forked vote math. THE INVARIANTS: a self-asserted channel is
 * refused at request (it needs no grant); an UNQUALIFIED governed channel never opens a proposal
 * (capable-before-request); ratify refuses until the meter clears, then MINTS a signed grant and flips
 * the channel enabled; a peer-subtree-affecting channel (authority.grant/broker.dns) adds Meter C.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MeshRoleGrantTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_rolegrant';

    public function test_a_self_asserted_channel_is_refused_at_request(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(MeshRoleGrantService::class);
            $scope = $this->jurisdiction(null);

            $this->assertThrows(
                fn () => $svc->request('mesh.member', $scope->id),
                ConstitutionalViolation::class,
            );
        });
    }

    public function test_an_unqualified_governed_channel_is_refused_before_opening(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            config(['services.cloudflare.dns_token' => '']); // broker.dns cannot qualify
            $svc = app(MeshRoleGrantService::class);
            $scope = $this->jurisdiction(null);

            $this->assertThrows(
                fn () => $svc->request('broker.dns', $scope->id),
                ConstitutionalViolation::class,
            );
            $this->assertSame(0, PeerUpgradeProposal::query()->where('kind', PeerUpgradeProposal::KIND_ROLE_GRANT)->count(),
                'an unqualified request must not open a proposal');
        });
    }

    public function test_meter_a_board_attestation_ratifies_a_governed_grant_and_flips_enabled(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            config(['matrix.homeserver_url' => 'http://synapse:8008']); // matrix.homeserver qualifies
            $svc = app(MeshRoleGrantService::class);
            $agreement = app(PeerUpgradeAgreementService::class);

            $scope = $this->jurisdiction(null); // unseated ⇒ Meter A (operator board)
            $this->assertSame('operator', $agreement->applicableConsentLeg($scope->id));

            $operator = $this->operator();
            $proposal = $svc->request('matrix.homeserver', $scope->id);

            // The finalize discipline: refuse to grant before the board attests.
            $this->assertThrows(fn () => $svc->ratify($proposal), ConstitutionalViolation::class);
            $this->assertFalse(app(CapabilityService::class)->holds($identity->serverId(), 'matrix.homeserver'));

            $agreement->recordOperatorConsent($proposal, $operator, true);
            $ratified = $svc->ratify($proposal);

            $this->assertSame(PeerUpgradeProposal::STATUS_RATIFIED, $ratified->status);
            $this->assertTrue(app(CapabilityService::class)->holds($identity->serverId(), 'matrix.homeserver'),
                'ratify flips the governed channel enabled');

            // The minted grant is a signed receipt carrying ONLY public keys — never a secret.
            $grant = $ratified->grant_payload;
            $this->assertSame('capability_grant', $grant['type']);
            $this->assertSame('matrix.homeserver', $grant['capability']);
            $this->assertSame($identity->serverId(), $grant['authority_server_id']);
            $this->assertTrue(InstanceIdentityService::verify(
                $grant['authority_pubkey'],
                \App\Services\AuditService::canonicalJson(collect($grant)->except('signature')->all()),
                $grant['signature'],
            ), 'the grant signature verifies against the authority pubkey');
        });
    }

    public function test_a_peer_subtree_channel_adds_meter_c(): void
    {
        $this->assertTrue(app(CapabilityProber::class)->affectsPeerSubtree('authority.grant'));
        $this->assertTrue(app(CapabilityProber::class)->affectsPeerSubtree('broker.dns'));
        $this->assertFalse(app(CapabilityProber::class)->affectsPeerSubtree('matrix.homeserver'));

        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(MeshRoleGrantService::class);
            $agreement = app(PeerUpgradeAgreementService::class);

            $scope = $this->jurisdiction(null);        // root — ours
            $child = $this->jurisdiction($scope->id);  // a co-affected subtree a PEER is authoritative for
            $peerServerId = (string) Str::uuid();
            DB::table('jurisdictions')->where('id', $child->id)->update(['authoritative_server_id' => $peerServerId]);
            $this->trustedPeer($peerServerId);

            $operator = $this->operator();
            $proposal = $svc->request('authority.grant', $scope->id); // peer-subtree-affecting ⇒ Meter C applies
            $agreement->recordOperatorConsent($proposal, $operator, true); // Meter A satisfied

            // Meter A passed, but the co-affected peer has NOT consented — ratify must refuse (Meter C).
            $this->assertThrows(fn () => $svc->ratify($proposal), ConstitutionalViolation::class);

            $agreement->recordPeerConsent($proposal, $peerServerId, true);
            $this->assertSame(PeerUpgradeProposal::STATUS_RATIFIED, $svc->ratify($proposal)->status,
                'with both Meter A and Meter C cleared, the peer-subtree grant ratifies');
        });
    }

    // -- fixtures ---------------------------------------------------------------------------------------

    private function jurisdiction(?string $parentId): Jurisdiction
    {
        $j = new Jurisdiction();
        $j->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'Role '.Str::random(6),
            'slug' => 'role-'.Str::lower(Str::random(12)),
            'adm_level' => $parentId === null ? 5 : 6,
            'parent_id' => $parentId,
            'source' => 'user_defined',
        ])->save();

        return $j;
    }

    private function operator(): OperatorAccount
    {
        return OperatorAccount::create([
            'server_id' => (string) Str::uuid(),
            'username' => 'op-'.Str::lower(Str::random(10)),
            'password' => 'secret-'.Str::random(8),
            'status' => OperatorAccount::STATUS_ACTIVE,
        ]);
    }

    private function trustedPeer(string $serverId): FederationPeer
    {
        return FederationPeer::create([
            'server_id' => $serverId,
            'name' => 'Peer '.Str::random(5),
            'url' => 'http://peer-'.Str::lower(Str::random(8)).'.invalid',
            'public_key' => sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL),
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
