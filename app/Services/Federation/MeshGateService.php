<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Models\InstanceCapability;
use App\Models\InstanceSettings;
use App\Models\PeerUpgradeProposal;
use App\Models\SyncLogEntry;

/**
 * The operator's federation-readiness gates (Phase G, G8b) — the operator's version of
 * "run the tests, get the greens." Each gate is a self-contained, side-effect-free check
 * of whether THIS node is set up to federate two-way; the result is a pass/warn/fail with
 * a one-line detail. Surfaced identically by the `mesh:gates` CLI and the federation
 * console GUI, so an operator can confirm their end before the rig's two-way gates.
 *
 * NODE-READINESS only (is this node configured to federate?). The PER-PEER reachability +
 * version-agreement probe is mesh:doctor (it dials a peer from inside the container); the
 * two together are the operator's pre-rig checklist.
 *
 * FAIL = a hard blocker (federation off / no identity). WARN = a not-yet-done step that
 * does not by itself break federation (no transport advertised, callback URL still local,
 * no peer yet, no sync yet). PASS = good.
 */
class MeshGateService
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Role Board channel states (Mesh Roles ★13, design §6.1). */
    public const STATE_ESTABLISHED = 'established';
    public const STATE_QUALIFIABLE = 'qualifiable';
    public const STATE_NEEDS_CONFIG = 'needs-config';
    public const STATE_REQUESTED = 'requested';
    public const STATE_LAPSED = 'lapsed';

    /** Named-role roll-up states (Operator Roles & Console ★2) — folded from the channel states above. */
    public const ROLE_ESTABLISHED = 'established';
    public const ROLE_PARTIAL = 'partial';
    public const ROLE_REQUESTED = 'requested';
    public const ROLE_QUALIFIABLE = 'qualifiable';
    public const ROLE_NEEDS_CONFIG = 'needs-config';

    public function __construct(
        private readonly TransportService $transports,
        private readonly CapabilityService $capabilities,
        private readonly CapabilityProber $prober,
        private readonly InstanceIdentityService $identity,
        private readonly BrokerAuthorizationService $brokerAuth,
    ) {}

    /**
     * @return list<array{key:string,label:string,status:string,detail:string}>
     */
    public function evaluate(): array
    {
        $settings = InstanceSettings::current();
        $gates = [];

        $gates[] = $this->gate(
            'federation_enabled',
            'Federation enabled (mesh endpoints open)',
            $settings->federation_enabled ? self::PASS : self::FAIL,
            $settings->federation_enabled ? 'on' : 'closed — run federation:init',
        );

        $hasIdentity = $settings->server_id !== null && $settings->public_key !== null;
        $gates[] = $this->gate(
            'identity',
            'Federation identity minted (server_id + keypair)',
            $hasIdentity ? self::PASS : self::FAIL,
            $hasIdentity ? (string) $settings->server_id : 'not minted — run federation:init',
        );

        $self = $this->transports->selfEndpoints();
        $gates[] = $this->gate(
            'transports',
            'At least one transport advertised',
            $self !== [] ? self::PASS : self::WARN,
            $self === []
                ? 'none — run transport:register (federation falls back to the legacy url)'
                : implode(', ', array_map(fn ($e) => $e['transport'], $self)),
        );

        $selfUrl = (string) config('cga.federation_self_url', '');
        $urlStatus = $selfUrl === ''
            ? self::WARN
            : (str_contains($selfUrl, 'host.docker.internal') ? self::WARN : self::PASS);
        $gates[] = $this->gate(
            'self_url',
            'Handshake callback URL is peer-reachable',
            $urlStatus,
            match (true) {
                $selfUrl === '' => 'unset — peers learn no callback URL',
                $urlStatus === self::WARN => $selfUrl.'  (host.docker.internal is local-only — set the overlay/public address)',
                default => $selfUrl,
            },
        );

        $trusted = FederationPeer::query()
            ->where('status', FederationPeer::STATUS_TRUST_ESTABLISHED)
            ->whereNull('deleted_at')
            ->count();
        $gates[] = $this->gate(
            'trusted_peer',
            'At least one trust-established peer',
            $trusted > 0 ? self::PASS : self::WARN,
            $trusted > 0 ? $trusted.' peer(s)' : 'none yet — discover + handshake a peer (then mesh:doctor it)',
        );

        $appliedOut = SyncLogEntry::query()
            ->where('direction', SyncLogEntry::DIRECTION_OUTBOUND)
            ->where('result', SyncLogEntry::RESULT_APPLIED)
            ->exists();
        $gates[] = $this->gate(
            'sync_applied',
            'Full-Faith-&-Credit sync has applied outbound',
            $appliedOut ? self::PASS : self::WARN,
            $appliedOut ? 'yes' : 'not yet — runs on the CLK-20 heartbeat once a peer is trusted',
        );

        return $gates;
    }

    /** True when no gate is a hard FAIL (the node can federate). */
    public function ready(): bool
    {
        foreach ($this->evaluate() as $gate) {
            if ($gate['status'] === self::FAIL) {
                return false;
            }
        }

        return true;
    }

    /**
     * The Role Board (Mesh Roles ★13/★16, design §6.1) — the flat readiness view re-projected as
     * channel-keyed clusters. A box's role is the SET of channels it has established; each channel renders
     * its own state (established / qualifiable / needs-config / requested / lapsed) with a self-contained
     * pass/warn/fail cluster. The flat evaluate() above is unchanged, so `mesh:gates` is identical.
     *
     * @return list<array{capability:string,label:string,what:string,kind:string,state:string,affects_peer_subtree:bool,gates:list<array{key:string,label:string,status:string,detail:string}>}>
     */
    public function channels(?string $scopeJurisdictionId = null): array
    {
        $serverId = $this->identity->serverId();
        $catalog = (array) config('mesh_channels', []);
        $out = [];

        foreach (InstanceCapability::CHANNELS as $cap) {
            $governed = InstanceCapability::isGoverned($cap);
            $established = $this->capabilities->holds($serverId, $cap);
            $lapsed = ! $established && InstanceCapability::query()
                ->where('server_id', $serverId)->where('capability', $cap)
                ->where('is_self', true)->where('enabled', false)->whereNull('deleted_at')->exists();
            $requested = $governed && PeerUpgradeProposal::query()
                ->where('kind', PeerUpgradeProposal::KIND_ROLE_GRANT)
                ->where('capability', $cap)
                ->where('proposed_by_server_id', $serverId)
                ->where('status', PeerUpgradeProposal::STATUS_OPEN)
                ->exists();

            $probe = $this->prober->probe($cap, $scopeJurisdictionId);

            $state = match (true) {
                $established => self::STATE_ESTABLISHED,
                $requested => self::STATE_REQUESTED,
                $lapsed => self::STATE_LAPSED,
                $probe['ok'] => self::STATE_QUALIFIABLE,
                default => self::STATE_NEEDS_CONFIG,
            };

            // The cluster: the prober as the primary gate, plus broker-readiness gates for the broker.* channels.
            $gates = [$this->gate(
                $cap.'.qualify',
                'Hostable on this box',
                ($established || $probe['ok']) ? self::PASS : self::FAIL,
                $established ? 'established' : $probe['detail'],
            )];
            if (in_array($cap, ['broker.dns', 'broker.tls'], true)) {
                $gates = array_merge($gates, $this->brokerGates($cap));
            }

            $out[] = [
                'capability' => $cap,
                'label' => (string) ($catalog[$cap]['label'] ?? $cap),
                'what' => (string) ($catalog[$cap]['what'] ?? ''),
                'kind' => $governed ? 'governed' : 'self-asserted',
                'state' => $state,
                // Infra readiness of the channel, INDEPENDENT of whether the
                // capability is granted. `state` collapses to 'established' the
                // moment the grant lands, which masks unconfigured infra (DNS,
                // TLS/lego, a Matrix homeserver, a LiveKit SFU). `ready` keeps the
                // raw prober result so callers can say "granted, but still needs
                // setup" — a channel is established && !ready when the role is on
                // but its infrastructure isn't configured yet.
                'ready' => (bool) $probe['ok'],
                'affects_peer_subtree' => $this->prober->affectsPeerSubtree($cap),
                'gates' => $gates,
            ];
        }

        return $out;
    }

    /**
     * The 4 named operator-roles (Operator Roles & Console ★2) folded from the per-channel projection.
     * Each role's STATE is DERIVED from its required channels' states — this READS config/mesh_roles.php
     * and reuses channels() (it does NOT re-probe). channels()/evaluate() stay byte-identical, so
     * `mesh:gates` and the existing Role Board are unaffected.
     *
     * @return list<array{role:string,label:string,what:string,duty:string,recommended:bool,channels:list<string>,channel_states:array<string,string>,petition:?string,state:string}>
     */
    public function roles(?string $scopeJurisdictionId = null): array
    {
        $catalog = (array) config('mesh_roles', []);
        $byCap = [];
        foreach ($this->channels($scopeJurisdictionId) as $c) {
            $byCap[$c['capability']] = $c['state'];
        }

        $out = [];
        foreach ($catalog as $key => $role) {
            $required = array_values((array) ($role['channels'] ?? []));
            $states = [];
            foreach ($required as $cap) {
                $states[$cap] = $byCap[$cap] ?? self::STATE_NEEDS_CONFIG;
            }

            $out[] = [
                'role' => $key,
                'label' => (string) ($role['label'] ?? $key),
                'what' => (string) ($role['what'] ?? ''),
                'duty' => (string) ($role['duty'] ?? ''),
                'recommended' => (bool) ($role['recommended'] ?? false),
                'channels' => $required,
                'channel_states' => $states,
                'petition' => isset($role['petition']) ? (string) $role['petition'] : null,
                'state' => $this->rollupRoleState($states),
            ];
        }

        return $out;
    }

    /**
     * Deterministic fold of a role's channel states into one roll-up (Operator Roles ★2):
     *   established  — every required channel established
     *   partial      — some (but not all) established
     *   requested    — none established, at least one requested
     *   qualifiable  — none established/requested, every channel qualifiable
     *   needs-config — otherwise
     *
     * @param  array<string,string>  $states
     */
    private function rollupRoleState(array $states): string
    {
        if ($states === []) {
            return self::ROLE_NEEDS_CONFIG;
        }
        $vals = array_values($states);

        if (! in_array(false, array_map(fn ($s) => $s === self::STATE_ESTABLISHED, $vals), true)) {
            return self::ROLE_ESTABLISHED;
        }
        if (in_array(self::STATE_ESTABLISHED, $vals, true)) {
            return self::ROLE_PARTIAL;
        }
        if (in_array(self::STATE_REQUESTED, $vals, true)) {
            return self::ROLE_REQUESTED;
        }
        if (! in_array(false, array_map(fn ($s) => $s === self::STATE_QUALIFIABLE, $vals), true)) {
            return self::ROLE_QUALIFIABLE;
        }

        return self::ROLE_NEEDS_CONFIG;
    }

    /**
     * Broker-readiness gates (Mesh Roles ★16) — folded into the broker.* channel clusters AND surfaced by
     * mesh:doctor: is a naming root configured, is this box mesh-routable as a broker, has a cert issued.
     *
     * @return list<array{key:string,label:string,status:string,detail:string}>
     */
    public function brokerGates(string $cap): array
    {
        $gates = [];
        $domains = array_keys((array) config('cga.broker.domains', []));

        $gates[] = $this->gate(
            $cap.'.domains',
            'A naming root is configured',
            $domains !== [] ? self::PASS : self::WARN,
            $domains !== [] ? implode(', ', $domains) : 'none — set cga.broker.domains + drop the Cloudflare token',
        );

        if ($cap === 'broker.tls') {
            $serverId = $this->identity->serverId();
            $routable = false;
            foreach ($domains as $d) {
                if (in_array($serverId, $this->brokerAuth->brokersFor($d), true)) {
                    $routable = true;
                    break;
                }
            }
            $gates[] = $this->gate(
                'broker.tls.routable',
                'Mesh-routable as a broker (broker_authorizations)',
                $routable ? self::PASS : self::WARN,
                $routable ? 'an authority has attested this box' : 'no broker_authorization yet — an authority must attest this box',
            );

            $certDir = (string) config('cga.broker.tls_path', storage_path('app/mesh-tls'));
            $hasCert = is_dir($certDir) && ($g = glob($certDir.'/*.crt')) !== false && $g !== [];
            $gates[] = $this->gate(
                'broker.tls.cert',
                'A TLS cert has been issued',
                $hasCert ? self::PASS : self::WARN,
                $hasCert ? 'cert present in '.$certDir : 'none yet — run mesh:request-cert',
            );
        }

        return $gates;
    }

    /**
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function gate(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
