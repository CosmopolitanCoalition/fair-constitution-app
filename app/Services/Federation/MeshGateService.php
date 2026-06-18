<?php

namespace App\Services\Federation;

use App\Models\FederationPeer;
use App\Models\InstanceSettings;
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

    public function __construct(private readonly TransportService $transports) {}

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
     * @return array{key:string,label:string,status:string,detail:string}
     */
    private function gate(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
