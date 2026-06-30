<?php

namespace App\Services\Operator;

use App\Models\InstanceSettings;
use App\Models\Jurisdiction;
use App\Services\Federation\BrokerCredentialService;
use App\Services\Federation\CertGrantStore;
use App\Services\Federation\MeshGateService;
use Throwable;

/**
 * Operator Operations console — the READ-ONLY infrastructure & identity inventory
 * (Phase 1). The operator plane: every knob that is hardcoded / env-baked / file-
 * managed today, surfaced in one place with its current value, its APPLY TIER, and
 * live status (cert expiry, channel state). DELIBERATELY SEPARATE from
 * `constitutional_settings` — the operator manages the BOX; the constitution governs
 * the polity.
 *
 * Secret discipline (load-bearing): a secret's VALUE never leaves this service. For
 * tokens/keys/secrets we surface only `configured` (present?) and `dev_default`
 * (still the shared `cga_dev_*` placeholder?) — never the value, never to a prop, a
 * log, or the mesh. This mirrors BrokerCredentialService::status().
 *
 * APPLY TIERS:
 *   • instant — DB/config value the app reads at runtime; an edit could apply live.
 *   • restart — env/yaml-baked; an edit needs a host-side rewrite + container
 *     recreate (the deferred host-apply supervisor).
 *   • locked  — peer-pinned / identity (server_name, federation_self_url,
 *     schema_version, server_id); changing it breaks the mesh — not operator-editable.
 */
class OperatorInfraService
{
    public const TIER_INSTANT = 'instant';

    public const TIER_RESTART = 'restart';

    public const TIER_LOCKED = 'locked';

    public function __construct(
        private readonly BrokerCredentialService $brokerCredentials,
        private readonly CertGrantStore $grants,
        private readonly MeshGateService $gates,
        private readonly OperatorSettingsService $settings,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inventory(): array
    {
        // Channel states (established/requested/qualifiable/needs-config) for the
        // governed infra channels — computed once, distributed to the sections.
        $channels = $this->channelStates();

        return [
            'sections' => [
                $this->identitySection(),
                $this->tlsSection(),
                $this->dnsBrokerSection($channels),
                $this->voiceSection($channels),
                $this->matrixSection($channels),
            ],
            'tiers' => [
                ['key' => self::TIER_INSTANT, 'label' => 'Instant', 'note' => 'Read at runtime — an edit could apply live (no restart).'],
                ['key' => self::TIER_RESTART, 'label' => 'Restart', 'note' => 'env/yaml-baked — an edit needs a host-side rewrite + container recreate.'],
                ['key' => self::TIER_LOCKED, 'label' => 'Locked', 'note' => 'Peer-pinned / identity — changing it breaks the mesh; not operator-editable.'],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ── sections ─────────────────────────────────────────────────────────────

    private function identitySection(): array
    {
        $settings = InstanceSettings::current();

        return [
            'key' => 'identity',
            'label' => 'Identity & Federation',
            'summary' => "This box's mesh identity and the federation tuning knobs.",
            'doc' => 'docs/operator/federation.md',
            'items' => [
                $this->item('server_id', 'Server ID', $settings->server_id, self::TIER_LOCKED, "This box's stable mesh identity (Ed25519-rooted); never changes."),
                $this->item('federation_self_url', 'Self URL', config('cga.federation_self_url'), self::TIER_LOCKED, 'Advertised to peers at handshake and pinned by them — a change requires a re-handshake.'),
                $this->item('schema_version', 'Schema version', config('cga.schema_version'), self::TIER_LOCKED, 'Must match peers for FF&C sync; bumped only by a migration.'),
                $this->item('federation_enabled', 'Federation enabled', $settings->federation_enabled ? 'yes' : 'no', self::TIER_INSTANT, 'Master gate: is this box reachable on the mesh.', 'bool'),
                $this->item('heartbeat_minutes', 'Heartbeat interval (min)', config('cga.federation_heartbeat_minutes'), self::TIER_INSTANT, 'How often the self-draining heartbeat pulls peer tails.', 'int', $this->settings->isOverridden('heartbeat_minutes')),
                $this->item('http_timeout', 'Federation HTTP timeout (s)', config('cga.federation_http_timeout_seconds'), self::TIER_INSTANT, '', 'int', $this->settings->isOverridden('http_timeout')),
                $this->item('sync_page_size', 'Cold-sync page size', config('cga.federation_sync_page_size'), self::TIER_INSTANT, 'Records per page during a backfill drain.', 'int', $this->settings->isOverridden('sync_page_size')),
                $this->item('geodata_origin', 'Geodata origin', config('cga.geodata_origin'), self::TIER_INSTANT, 'Where a joining node pulls the geodata seed from (blank = the cluster host).', 'text', $this->settings->isOverridden('geodata_origin')),
                $this->item('seed_transport', 'Foundation seed transport', config('cga.federation_seed_transport'), self::TIER_INSTANT, "How a joining mirror loads the geodata foundation: 'paginated' (visible, resumable, per-table bars — default) or 'tarball' (legacy pg_restore fallback).", 'text', $this->settings->isOverridden('seed_transport')),
            ],
        ];
    }

    private function tlsSection(): array
    {
        return [
            'key' => 'tls',
            'label' => 'TLS Certificates',
            'summary' => 'ACME issuance config plus the certs installed on this box and any delivered grants.',
            'doc' => 'docs/operator/tls.md',
            'items' => [
                $this->item('acme_provider', 'ACME provider', config('cga.broker.acme.provider'), self::TIER_RESTART, "'lego' for real Let's Encrypt issuance; 'stub' for offline."),
                $this->item('acme_email', 'ACME account email', config('cga.broker.acme.email'), self::TIER_RESTART),
                $this->item('acme_staging', "Let's Encrypt staging", config('cga.broker.acme.staging') ? 'yes (test certs)' : 'no (production)', self::TIER_RESTART, 'Staging issues untrusted test certs but never burns the production rate limit.'),
                $this->item('dns_resolvers', 'DNS-01 resolvers', config('cga.broker.acme.dns_resolvers'), self::TIER_RESTART),
                $this->item('tls_path', 'Cert storage path', config('cga.broker.tls_path', storage_path('app/mesh-tls')), self::TIER_RESTART),
            ],
            'certs' => $this->installedCerts(),
            'grants' => $this->safe(fn () => $this->grants->fqdns(), []),
        ];
    }

    private function dnsBrokerSection(array $channels): array
    {
        return [
            'key' => 'dns_broker',
            'label' => 'DNS & Broker',
            'summary' => 'The DNS-edit credentials (write-only) and the broker capability channels.',
            'doc' => 'docs/operator/dns-broker.md',
            'items' => [
                $this->item('credentials_path', 'Credential store path', config('cga.broker.credentials_path', storage_path('app/broker/credentials.json')), self::TIER_RESTART, 'The Cloudflare token lives here, encrypted at rest; set it write-only on the Federation console.'),
            ],
            'credentials' => $this->safe(fn () => $this->brokerCredentials->status(), []),
            'channels' => $this->pickChannels($channels, ['broker.dns', 'broker.tls', 'authority.grant', 'client.serve']),
        ];
    }

    private function voiceSection(array $channels): array
    {
        return [
            'key' => 'voice',
            'label' => 'Voice / SFU (LiveKit)',
            'summary' => 'The MatrixRTC SFU for live voice/video. ICE networking (node_ip / ports / use_external_ip) lives in docker/livekit/livekit.yaml + docker-compose.',
            'doc' => 'docs/operator/livekit.md',
            'items' => [
                $this->item('livekit_url', 'SFU URL (internal)', config('matrix.livekit.url'), self::TIER_RESTART, 'Docker-internal address the appservice mints tokens against.'),
                $this->item('livekit_public_url', 'SFU URL (browser-reachable)', config('matrix.livekit.public_url'), self::TIER_RESTART, 'The wss:// URL a remote browser dials.'),
                $this->item('livekit_node_ip', 'ICE node IP', env('LIVEKIT_NODE_IP'), self::TIER_RESTART, 'Host-reachable IP the SFU advertises for ICE, or set use_external_ip in the yaml to STUN-discover it.'),
                $this->secretItem('livekit_api_key', 'API key', config('matrix.livekit.api_key'), self::TIER_RESTART),
                $this->secretItem('livekit_api_secret', 'API secret', config('matrix.livekit.api_secret'), self::TIER_RESTART, 'The appservice signs join tokens with this — never exposed to a client.'),
            ],
            'channels' => $this->pickChannels($channels, ['voice.sfu']),
        ];
    }

    private function matrixSection(array $channels): array
    {
        return [
            'key' => 'matrix',
            'label' => 'Matrix (Plane B)',
            'summary' => 'The homeserver + appservice + MAS/OIDC identity bridge.',
            'doc' => 'docs/operator/matrix.md',
            'items' => [
                $this->item('matrix_impl', 'Homeserver impl', config('matrix.impl'), self::TIER_RESTART, 'synapse (dev/default) or dendrite.'),
                $this->item('matrix_server_name', 'Server name', config('matrix.server_name'), self::TIER_LOCKED, 'The @user:<server_name> domain — pinned by peers at S2S; a change orphans every existing identity.'),
                $this->item('matrix_synapse_url', 'Synapse URL (internal)', config('matrix.synapse_url'), self::TIER_RESTART),
                $this->item('matrix_homeserver_url', 'Mesh-facing homeserver URL', config('matrix.homeserver_url'), self::TIER_RESTART, 'Operator opt-in to HOST Plane B for the mesh; drives the matrix.homeserver channel (blank = not offered).'),
                $this->item('matrix_mas_issuer', 'MAS issuer', config('matrix.mas.issuer'), self::TIER_RESTART),
                $this->item('matrix_oidc_issuer', 'OIDC issuer (game)', config('matrix.oidc.issuer'), self::TIER_RESTART),
                $this->item('matrix_appservice_id', 'Appservice ID', config('matrix.appservice.id'), self::TIER_RESTART),
                $this->secretItem('matrix_admin_token', 'Synapse admin token', config('matrix.admin_token'), self::TIER_RESTART, 'Operator-supplied; enables the M-5 media byte-delete. Null on the dev stack.'),
                $this->secretItem('matrix_as_token', 'Appservice as_token', config('matrix.appservice.as_token'), self::TIER_RESTART),
                $this->secretItem('matrix_hs_token', 'Appservice hs_token', config('matrix.appservice.hs_token'), self::TIER_RESTART),
                $this->secretItem('matrix_oidc_secret', 'OIDC client secret', config('matrix.oidc.client.secret'), self::TIER_RESTART),
            ],
            'channels' => $this->pickChannels($channels, ['matrix.homeserver']),
        ];
    }

    // ── builders ─────────────────────────────────────────────────────────────

    /**
     * A non-secret knob: its value is shown verbatim. `control` makes it editable in the
     * console ('int' / 'text' / 'bool'; 'none' = read-only); `overridden` flags an
     * instant-tier knob currently carrying an operator override (vs the env default).
     */
    private function item(string $key, string $label, mixed $value, string $tier, string $note = '', string $control = 'none', bool $overridden = false): array
    {
        $str = $value === null ? null : (string) $value;

        return [
            'key' => $key,
            'label' => $label,
            'value' => $str,
            'secret' => false,
            'configured' => $str !== null && $str !== '',
            'dev_default' => false,
            'tier' => $tier,
            'note' => $note,
            'control' => $control,
            'editable' => $control !== 'none',
            'overridden' => $overridden,
        ];
    }

    /** A secret knob: the value NEVER leaves — only present? and still-the-dev-placeholder?. */
    private function secretItem(string $key, string $label, mixed $value, string $tier, string $note = ''): array
    {
        $str = (string) ($value ?? '');

        return [
            'key' => $key,
            'label' => $label,
            'value' => null,
            'secret' => true,
            'configured' => $str !== '',
            'dev_default' => str_starts_with($str, 'cga_dev_'),
            'tier' => $tier,
            'note' => $note,
            'control' => 'none',
            'editable' => false,
            'overridden' => false,
        ];
    }

    /**
     * Installed certs under the TLS path, with parsed subject + expiry. Defensive:
     * an unreadable/garbage cert is skipped, never fatal to the dashboard.
     *
     * @return list<array<string,mixed>>
     */
    private function installedCerts(): array
    {
        return $this->safe(function (): array {
            $dir = (string) config('cga.broker.tls_path', storage_path('app/mesh-tls'));
            if (! is_dir($dir)) {
                return [];
            }
            $out = [];
            foreach (glob($dir.'/*.crt') ?: [] as $path) {
                $pem = @file_get_contents($path);
                if ($pem === false) {
                    continue;
                }
                $parsed = @openssl_x509_parse($pem);
                if (! is_array($parsed)) {
                    continue;
                }
                $notAfter = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
                $daysLeft = $notAfter !== null ? (int) floor(($notAfter - time()) / 86400) : null;
                $out[] = [
                    'fqdn' => (string) ($parsed['subject']['CN'] ?? basename($path, '.crt')),
                    'not_after' => $notAfter !== null ? gmdate('c', $notAfter) : null,
                    'days_left' => $daysLeft,
                    'expiring' => $daysLeft !== null && $daysLeft >= 0 && $daysLeft < 30,
                    'expired' => $daysLeft !== null && $daysLeft < 0,
                ];
            }

            return $out;
        }, []);
    }

    /** All governed-channel states (cap => state), computed once; defensive. */
    private function channelStates(): array
    {
        return $this->safe(function (): array {
            $rootId = (string) Jurisdiction::query()->whereNull('parent_id')->whereNull('deleted_at')->value('id');
            $rows = $this->gates->channels($rootId === '' ? null : $rootId);
            $out = [];
            foreach ($rows as $c) {
                $cap = $c['capability'] ?? null;
                if ($cap === null) {
                    continue;
                }
                $out[(string) $cap] = [
                    'capability' => (string) $cap,
                    'label' => (string) ($c['label'] ?? $cap),
                    'state' => $c['state'] ?? null,
                    'kind' => $c['kind'] ?? null,
                ];
            }

            return $out;
        }, []);
    }

    /**
     * @param  array<string,array<string,mixed>>  $channels
     * @param  list<string>  $caps
     * @return list<array<string,mixed>>
     */
    private function pickChannels(array $channels, array $caps): array
    {
        $out = [];
        foreach ($caps as $cap) {
            if (isset($channels[$cap])) {
                $out[] = $channels[$cap];
            }
        }

        return $out;
    }

    /** Run a fallible read so one broken source never blanks the whole dashboard. */
    private function safe(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return $default;
        }
    }
}
