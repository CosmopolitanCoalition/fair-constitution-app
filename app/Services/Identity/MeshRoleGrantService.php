<?php

namespace App\Services\Identity;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationPeer;
use App\Models\InstanceCapability;
use App\Models\PeerUpgradeProposal;
use App\Services\AuditService;
use App\Services\Federation\BrokerAuthorizationService;
use App\Services\Federation\BrokerCredentialService;
use App\Services\Federation\CapabilityProber;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MultiplexClient;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mesh Roles & Channels of Trust (★6) — the qualify → request → approve → join orchestrator for GOVERNED
 * capability channels. A near-exact structural clone of PeerUpgradeAgreementService applied to
 * capabilities instead of constitutional versions: a grant is REQUESTED (signed, scoped), CONSENTED to
 * through the IDENTICAL dual-meter machinery (reused verbatim — no forked vote math), and only RATIFIED
 * when every required gate clears (the LocalAutonomyService::finalize discipline). On ratify, an
 * authority box MINTS + SIGNS the capability grant (the cryptographic receipt of approval) and writes it
 * onto the grantee's instance_capabilities row, flipping the channel enabled — so a governed role always
 * FOLLOWS legitimacy.
 *
 * Self-asserted channels (mesh.member/mirror/etl) never come here — they go straight to
 * CapabilityService::registerSelf. The Cloudflare token / authority private key never appear in a grant;
 * the grant carries only PUBLIC keys + names (§4.3).
 */
class MeshRoleGrantService
{
    /** The grant TTL — matches the cert-broker's revocation-by-expiry (the 90-day LE clock, §3.3). */
    private const GRANT_TTL_DAYS = 90;

    public function __construct(
        private readonly PeerUpgradeAgreementService $agreement,
        private readonly CapabilityProber $prober,
        private readonly CapabilityService $capabilities,
        private readonly InstanceIdentityService $identity,
        private readonly AuditService $audit,
        private readonly MultiplexClient $multiplex,
        private readonly BrokerAuthorizationService $brokerAuth,
        private readonly BrokerCredentialService $brokerCredentials,
    ) {}

    // -- QUALIFY + REQUEST ------------------------------------------------------------------------------

    /**
     * Open a signed role-grant request for a GOVERNED channel (the REQUEST step). Self-asserted channels
     * are refused here — they need no consent, so the caller registers them directly. QUALIFY runs first:
     * a channel whose prober fails never opens a proposal (capable-before-request).
     */
    public function request(string $capability, string $scopeJurisdictionId): PeerUpgradeProposal
    {
        if (! in_array($capability, InstanceCapability::CHANNELS, true)) {
            throw new ConstitutionalViolation("Unknown capability channel [{$capability}].", 'Mesh Roles & Channels of Trust');
        }
        if (! InstanceCapability::isGoverned($capability)) {
            throw new ConstitutionalViolation(
                "[{$capability}] is self-asserted — enable it directly (CapabilityService::registerSelf), it needs no grant.",
                'Mesh Roles & Channels of Trust · §3.2',
            );
        }

        $exists = DB::table('jurisdictions')->where('id', $scopeJurisdictionId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            throw new ConstitutionalViolation("Unknown scope jurisdiction {$scopeJurisdictionId}.", 'Mesh Roles & Channels of Trust');
        }

        // QUALIFY — refuse before opening if the box cannot host the channel.
        $this->prober->assertQualified($capability, $scopeJurisdictionId);

        $core = [
            'kind' => PeerUpgradeProposal::KIND_ROLE_GRANT,
            'capability' => $capability,
            'affected_root_jurisdiction_id' => $scopeJurisdictionId,
            'requested_by_server_id' => $this->identity->serverId(),
        ];

        return DB::transaction(function () use ($capability, $scopeJurisdictionId, $core): PeerUpgradeProposal {
            $proposal = PeerUpgradeProposal::create([
                'kind' => PeerUpgradeProposal::KIND_ROLE_GRANT,
                'capability' => $capability,
                'affected_root_jurisdiction_id' => $scopeJurisdictionId,
                'proposed_by_server_id' => $this->identity->serverId(),
                'signature' => $this->identity->sign(AuditService::canonicalJson($core)),
                'status' => PeerUpgradeProposal::STATUS_OPEN,
            ]);

            $this->audit->append('federation', 'role.requested', [
                'proposal_id' => (string) $proposal->id,
                'capability' => $capability,
                'scope_jurisdiction_id' => $scopeJurisdictionId,
                'requested_by_server_id' => $this->identity->serverId(),
                'consent_leg' => $this->agreement->applicableConsentLeg($scopeJurisdictionId),
                'affects_peer_subtree' => $this->prober->affectsPeerSubtree($capability),
            ], 'MESH-ROLES', null, $scopeJurisdictionId);

            return $proposal->refresh();
        });
    }

    /**
     * FOUNDING self-grant — enable a GOVERNED channel during founding by minting a
     * SELF-SIGNED grant. Legitimate because on a founding node the operator is the
     * sole constitutional authority: no seated government, no mesh peers whose zones
     * the role could touch, so Meter A/B/C are all trivially satisfied (Meter A with
     * one operator = self-attestation, Meter C N/A with no peers). The grant is
     * authored and signed by THIS server (authority == grantee == self), which is
     * exactly what ratify() does for a same-box grantee — minus the jurisdiction
     * scope, which has no place to attach to yet during founding.
     *
     * Once setup completes and a government seats, governed channels go back through
     * the normal request → dual-meter → ratify path for any later change.
     */
    public function selfGrantFounding(string $capability): InstanceCapability
    {
        if (! in_array($capability, InstanceCapability::CHANNELS, true)) {
            throw new ConstitutionalViolation("Unknown capability channel [{$capability}].", 'Mesh Roles & Channels of Trust');
        }
        if (! \App\Support\FoundingContext::isFounding()) {
            throw new ConstitutionalViolation(
                'A governed channel self-grants only while the node is being founded; after setup it needs the dual-meter consent.',
                'Mesh Roles & Channels of Trust · §5',
            );
        }
        // A self-asserted channel needs no grant at all.
        if (! InstanceCapability::isGoverned($capability)) {
            return $this->capabilities->registerSelf($capability);
        }

        $this->identity->ensureIdentity();
        $server = $this->identity->serverId();
        $envelope = [
            'capability'          => $capability,
            'authority_server_id' => $server,
            'grantee_server_id'   => $server,
            'scope'               => null,     // founding — no jurisdiction to attach to yet
            'founding'            => true,
            'expires_at'          => null,     // does not expire; superseded when governed consent later applies
        ];
        $signature = $this->identity->sign(AuditService::canonicalJson($envelope));

        $cap = $this->capabilities->grantSelf($capability, $server, $signature, null);

        $this->audit->append('federation', 'role.self_granted_founding', [
            'capability'          => $capability,
            'authority_server_id' => $server,
        ], 'MESH-ROLES', null, null);

        return $cap;
    }

    // -- APPROVE + JOIN (ratify) ------------------------------------------------------------------------

    /**
     * Ratify a role-grant request — refusing (with citation) unless every required meter cleared, then
     * minting the signed grant and flipping the channel enabled. The consent legs are evaluated LIVE
     * (a government that seated mid-request supersedes the operator board), reusing
     * PeerUpgradeAgreementService's meter methods verbatim. Meter C is added only for a channel that acts
     * under a peer's subtree (per the prober's declaration); it auto-N/As when no co-affected peer exists.
     */
    public function ratify(PeerUpgradeProposal $proposal): PeerUpgradeProposal
    {
        $proposal = $proposal->refresh();

        if ($proposal->kind !== PeerUpgradeProposal::KIND_ROLE_GRANT) {
            throw new ConstitutionalViolation('Not a role-grant proposal.', 'Mesh Roles & Channels of Trust');
        }
        if (! $proposal->isOpen()) {
            throw new ConstitutionalViolation('The role-grant request is not open.', 'Mesh Roles & Channels of Trust');
        }

        $capability = (string) $proposal->capability;
        $scope = (string) $proposal->affected_root_jurisdiction_id;

        // Meter A or B — the operator board (bootstrap) or the seated government's supermajority.
        $leg = $this->agreement->applicableConsentLeg($scope);
        if ($leg === 'seated') {
            if (! $this->agreement->meterBPassed($proposal)) {
                throw new ConstitutionalViolation(
                    "Granting [{$capability}] in a jurisdiction with a seated government requires that "
                    .'government\'s supermajority consent (Meter B) — it has not been reached.',
                    'Mesh Roles & Channels of Trust · §5',
                );
            }
        } elseif (! $this->agreement->meterAPassed($proposal)) {
            throw new ConstitutionalViolation(
                "Granting [{$capability}] in bootstrap mode requires the operator board's attestation "
                .'(Meter A) — the scaling-consent threshold has not been reached.',
                'Mesh Roles & Channels of Trust · §5',
            );
        }

        // Meter C — co-affected peer unanimity, only when the channel acts under a peer's subtree.
        if ($this->prober->affectsPeerSubtree($capability) && ! $this->agreement->meterCPassed($proposal)) {
            throw new ConstitutionalViolation(
                "[{$capability}] acts under a peer's subtree — every co-affected peer must consent "
                .'(Meter C, unanimity) before it is granted.',
                'Mesh Roles & Channels of Trust · §5',
            );
        }

        // Only an AUTHORITY box mints (it must be authoritative for the scope + hold a signing identity).
        $authProbe = $this->prober->probe('authority.grant', $scope);
        if (! $authProbe['ok']) {
            throw new ConstitutionalViolation(
                "This box cannot mint the grant — {$authProbe['detail']}. An authority.grant holder for the "
                .'scope mints + signs it (the grant is delivered to the grantee for cross-instance requests).',
                'Mesh Roles & Channels of Trust · §4.3',
            );
        }

        $granteeServerId = (string) $proposal->proposed_by_server_id;
        $granteePubKey = $this->resolvePubKey($granteeServerId);
        if ($granteePubKey === null) {
            throw new ConstitutionalViolation(
                "Cannot resolve the grantee's public key (server {$granteeServerId}) — discover/handshake it first.",
                'Mesh Roles & Channels of Trust',
            );
        }

        [$envelope, $signature] = $this->mintGrant($capability, $scope, $granteeServerId, $granteePubKey);

        $result = DB::transaction(function () use ($proposal, $capability, $envelope, $signature, $granteeServerId): PeerUpgradeProposal {
            // JOIN — write the grant receipt onto the grantee's instance_capabilities row, flip enabled.
            // Same-box grantee (the dev/bootstrap path): grant ourselves. A peer grantee receives the
            // grant over S2S (★11/★17) and grantSelf's on its own side; we record the envelope for delivery.
            if ($granteeServerId === $this->identity->serverId()) {
                $this->capabilities->grantSelf(
                    $capability,
                    (string) $envelope['authority_server_id'],
                    $signature,
                    (int) $envelope['expires_at'],
                );
            }

            $proposal->forceFill([
                'status' => PeerUpgradeProposal::STATUS_RATIFIED,
                'grant_payload' => $envelope + ['signature' => $signature],
                'ratified_at' => now(),
            ])->save();

            $this->audit->append('federation', 'role.ratified', [
                'proposal_id' => (string) $proposal->id,
                'capability' => $capability,
                'grantee_server_id' => $granteeServerId,
                'authority_server_id' => (string) $envelope['authority_server_id'],
                'expires_at' => (int) $envelope['expires_at'],
            ], 'MESH-ROLES', null, $proposal->affected_root_jurisdiction_id);

            return $proposal->refresh();
        });

        // A1 — a ratified broker role PUBLISHES the broker-routing fact for each configured domain, so the
        // box becomes mesh-routable as a broker AND its authority key populates the GrantVerifier's
        // authority_keys (the missing link "role approved" → "broker usable"). Self-broker only (the box is
        // both authority + broker); the fact then gossips to peers on the next handshake. A cross-box broker
        // grant would carry the broker's domains on the request — out of scope for the A↔B campaign.
        if (in_array($capability, ['broker.dns', 'broker.tls'], true) && $granteeServerId === $this->identity->serverId()) {
            foreach ($this->configuredBrokerDomains() as $domain) {
                $this->brokerAuth->attest($domain, $granteeServerId);
            }
        }

        return $result;
    }

    /** Domains this box is configured to broker — the operator credential store ∪ static config. */
    private function configuredBrokerDomains(): array
    {
        return array_values(array_unique(array_merge(
            $this->brokerCredentials->domains(),
            array_map('strval', array_keys((array) config('cga.broker.domains', []))),
        )));
    }

    // -- JOIN delivery (★17 — cross-instance) -----------------------------------------------------------

    /**
     * Deliver a ratified capability grant to a PEER grantee over the survival mesh (Mesh Roles ★17). The
     * grantee's /api/federation/role-grant verifies it against OUR (the authority's) pinned key and applies
     * it (grantSelf) — no trust is conferred by delivery alone. A self-grant was already applied at ratify,
     * so there is nothing to deliver; returns null. The live cross-instance hop is the rig leg.
     */
    public function deliverGrant(PeerUpgradeProposal $proposal): ?Response
    {
        $proposal = $proposal->refresh();

        if ($proposal->status !== PeerUpgradeProposal::STATUS_RATIFIED || ! is_array($proposal->grant_payload)) {
            throw new ConstitutionalViolation('No ratified grant to deliver.', 'Mesh Roles & Channels of Trust');
        }

        $granteeServerId = (string) $proposal->proposed_by_server_id;
        if ($granteeServerId === $this->identity->serverId()) {
            return null; // self-grant already applied at ratify
        }

        $envelope = collect($proposal->grant_payload)->except('signature')->all();

        return $this->multiplex->reach($granteeServerId, 'POST', '/api/federation/role-grant', [
            'grant' => $envelope,
            'grant_signature' => (string) ($proposal->grant_payload['signature'] ?? ''),
        ]);
    }

    // -- REVOKE / LAPSE (de-promotion, always unilateral to STOP) ---------------------------------------

    /** Drop one of OUR channels (operator-driven revoke or expiry lapse). Stopping a service is unilateral. */
    public function revoke(string $capability, string $reason = 'revoked'): bool
    {
        $dropped = $this->capabilities->disableSelf($capability);

        if ($dropped) {
            $this->audit->append('federation', 'role.dropped', [
                'capability' => $capability,
                'reason' => $reason,
                'server_id' => $this->identity->serverId(),
            ], 'MESH-ROLES');
        }

        return $dropped;
    }

    // -- the grant mint (canonical-JSON + detached Ed25519, byte-identical to the broker) ---------------

    /**
     * Mint + sign the universal capability grant (§4.3). The envelope carries ONLY public keys + names —
     * never the Cloudflare token or any private key. Canonical-JSON is byte-identical to the broker's
     * Canonical.php (== AuditService::canonicalJson), so the grant verifies identically on Box C or in-mesh.
     *
     * @return array{0: array<string,mixed>, 1: string}  [envelope, base64 detached signature]
     */
    private function mintGrant(string $capability, string $scope, string $granteeServerId, string $granteePubKey): array
    {
        $envelope = [
            'v' => 1,
            'type' => 'capability_grant',
            'capability' => $capability,
            'scope_jurisdiction_id' => $scope,
            'peer_server_id' => $granteeServerId,
            'peer_pubkey' => $granteePubKey,
            'authority_server_id' => $this->identity->serverId(),
            'authority_pubkey' => $this->identity->publicKey(),
            'issued_at' => now()->getTimestamp(),
            'expires_at' => now()->addDays(self::GRANT_TTL_DAYS)->getTimestamp(),
        ];

        $signature = $this->identity->sign(AuditService::canonicalJson($envelope));

        return [$envelope, $signature];
    }

    /** The grantee's base64 Ed25519 public key — ours if self, else the pinned peer key. */
    private function resolvePubKey(string $serverId): ?string
    {
        if ($serverId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }

        $key = FederationPeer::query()
            ->where('server_id', $serverId)
            ->whereNull('deleted_at')
            ->value('public_key');

        return $key !== null ? (string) $key : null;
    }
}
