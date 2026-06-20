<?php

namespace App\Services\Federation;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\InstanceCapability;
use Illuminate\Support\Facades\DB;

/**
 * The QUALIFY gate (Mesh Roles & Channels of Trust ★5) — a pure-PHP registry keyed by capability slug
 * (mirroring the TRANSPORTS whitelist). Before a box may REQUEST a governed channel it must be capable
 * of hosting it: each channel declares a probe() over its local prerequisites. A request for a channel
 * whose prober FAILS is refused before it can open — capable-before-request.
 *
 * Tokens/keys live in .env / the secret store and NEVER federate (the same rule as the operator password
 * and the Cloudflare token); the probes read presence/health locally and never emit a secret. The probes
 * are dev-stack-safe — they read config + filesystem + the local DB. A LIVE check (a real Cloudflare
 * zones call, a real Synapse health ping) is the rig leg; the dev probe asserts the dependency is
 * configured, which is what gates the REQUEST.
 *
 * affectsPeerSubtree() is the per-capability Meter-C declaration (§3.2.4): a channel that can act under a
 * PEER's subtree (broker.dns for a name in a peer's zone, authority.grant over a peer's jurisdictions)
 * routes through the co-affected-peer unanimity leg; PeerUpgradeAgreementService::meterCPassed auto-N/As
 * it when no co-affected peer exists.
 */
class CapabilityProber
{
    public function __construct(private readonly InstanceIdentityService $identity) {}

    /** Channels that, granted, let the box act under a PEER's subtree → add Meter C (§5). */
    private const PEER_SUBTREE_AFFECTING = ['broker.dns', 'authority.grant'];

    public function affectsPeerSubtree(string $capability): bool
    {
        return in_array($capability, self::PEER_SUBTREE_AFFECTING, true);
    }

    /**
     * Probe whether THIS box can host $capability (optionally scoped to a jurisdiction).
     *
     * @return array{capability:string, ok:bool, detail:string, affects_peer_subtree:bool}
     */
    public function probe(string $capability, ?string $scopeJurisdictionId = null): array
    {
        if (! in_array($capability, InstanceCapability::CHANNELS, true)) {
            throw new ConstitutionalViolation("Unknown capability channel [{$capability}].", 'Mesh Roles & Channels of Trust');
        }

        [$ok, $detail] = match ($capability) {
            'mesh.member', 'mirror' => [true, 'self-asserted — always hostable'],
            'etl'                   => $this->probeEtl(),
            'client.serve'          => $this->probeClientServe(),
            'broker.dns'            => $this->probeBrokerDns(),
            'broker.tls'            => $this->probeBrokerTls(),
            'authority.grant'       => $this->probeAuthorityGrant($scopeJurisdictionId),
            'matrix.homeserver'     => $this->probeMatrixHomeserver(),
            'voice.sfu'             => $this->probeVoiceSfu(),
            default                 => [false, 'no prober registered'],
        };

        return [
            'capability' => $capability,
            'ok' => $ok,
            'detail' => $detail,
            'affects_peer_subtree' => $this->affectsPeerSubtree($capability),
        ];
    }

    /** Throw unless the channel qualifies — the gate REQUEST runs before opening a proposal. */
    public function assertQualified(string $capability, ?string $scopeJurisdictionId = null): void
    {
        $result = $this->probe($capability, $scopeJurisdictionId);
        if (! $result['ok']) {
            throw new ConstitutionalViolation(
                "Cannot request [{$capability}] — this box does not qualify to host it: {$result['detail']}. "
                .'Drop the required tokens/keys and re-probe (capable-before-request).',
                'Mesh Roles & Channels of Trust · §3.2 QUALIFY',
            );
        }
    }

    // -- per-channel probes (dev-stack-safe: config/filesystem/local-DB, never a secret value) ----------

    private function probeEtl(): array
    {
        $archive = (string) config('cga.etl_archive_path', '/archive');

        return is_dir($archive)
            ? [true, "geodata archive mounted at {$archive}"]
            : [false, "geodata archive bind-mount [{$archive}] is not present"];
    }

    private function probeClientServe(): array
    {
        // The box can serve a client bundle once assets are built (Vite manifest present).
        $manifest = public_path('build/manifest.json');

        return is_file($manifest)
            ? [true, 'built client bundle present']
            : [false, 'no built client bundle (run the asset build)'];
    }

    private function probeBrokerDns(): array
    {
        // The Cloudflare DNS-edit token — presence only, NEVER the value. Lives on the broker box.
        $token = (string) config('services.cloudflare.dns_token', '');

        return $token !== ''
            ? [true, 'Cloudflare DNS token configured (presence only; the value never federates)']
            : [false, 'no Cloudflare DNS token configured (services.cloudflare.dns_token)'];
    }

    private function probeBrokerTls(): array
    {
        // ACME issuance needs `lego` on PATH (the broker README dependency).
        $found = $this->onPath('lego');

        return $found
            ? [true, 'lego found on PATH']
            : [false, 'lego is not on PATH (required for ACME DNS-01 issuance)'];
    }

    private function probeAuthorityGrant(?string $scopeJurisdictionId): array
    {
        // Minting grants requires a federation signing identity AND authority over the scope:
        // the box must be authoritative for the scope jurisdiction (authoritative_server_id NULL = us).
        if (! $this->identity->ensureIdentity()->public_key) {
            return [false, 'no federation signing identity on this box'];
        }
        if ($scopeJurisdictionId === null) {
            return [true, 'federation identity present (scope-agnostic check)'];
        }

        $authServer = DB::table('jurisdictions')
            ->where('id', $scopeJurisdictionId)
            ->whereNull('deleted_at')
            ->value('authoritative_server_id');

        $authoritative = $authServer === null || (string) $authServer === $this->identity->serverId();

        return $authoritative
            ? [true, 'authoritative for the scope jurisdiction']
            : [false, 'not authoritative for the scope jurisdiction (a peer holds it)'];
    }

    private function probeMatrixHomeserver(): array
    {
        $url = (string) config('matrix.homeserver_url', config('services.matrix.homeserver_url', ''));

        return $url !== ''
            ? [true, 'Matrix homeserver configured']
            : [false, 'no Matrix homeserver configured'];
    }

    private function probeVoiceSfu(): array
    {
        $url = (string) config('matrix.livekit.url', config('services.livekit.url', ''));

        return $url !== ''
            ? [true, 'LiveKit SFU configured']
            : [false, 'no LiveKit SFU configured (services.livekit.url)'];
    }

    private function onPath(string $binary): bool
    {
        if (! function_exists('exec')) {
            return false;
        }
        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'command -v';
        @exec("{$which} {$binary} 2>/dev/null", $out, $rc);

        return $rc === 0 && ! empty($out);
    }
}
