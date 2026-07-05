<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\OperatorAccount;
use App\Models\PeerUpgradeProposal;
use App\Models\SyncLogEntry;
use App\Services\Federation\CapabilityProber;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\MeshGateService;
use App\Services\Federation\TransportService;
use App\Services\PeerUpgradeAgreementService;
use App\Support\SurfaceMeta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * mockups-v3-wiring Phase 4 — the operator/* console suite READ layer
 * (PHASE_4_DESIGN_peerage.md §3.1). A pure WRAPPER over the existing mesh
 * services (MeshGateService / CapabilityService / PeerUpgradeAgreementService /
 * TransportService) — it renders their truth, it never re-implements a meter,
 * a probe, or an authority rule.
 *
 * Gating mirrors /operator/operations exactly: the page shell is reachable by
 * any authenticated user, but the operator data block is built ONLY for an
 * authenticated OPERATOR (auth:operator guard) — a citizen sees `authed: false`
 * and a null data prop (the sign-in prompt renders client-side). Secrets never
 * ride a prop (public keys and fingerprints only; the private key is $hidden
 * on InstanceSettings and never read here).
 *
 * Console language (settled slate): "authority" always attaches to a
 * JURISDICTION — "this node holds the home copy of N places" — never to a node
 * as a rank; the G3c read-write petition ladder is NOT presented here (design
 * flag 1 — the legacy /federation page keeps it).
 */
class MeshConsoleController extends Controller
{
    /** GET /operator — the readiness rollup + named-role chips (Operator/Home). */
    public function home(MeshGateService $gates, CapabilityService $capabilities): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Home', [
            'surface' => SurfaceMeta::for('operator/home'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'readiness' => $authed ? $this->readiness($gates, $capabilities) : null,
        ]);
    }

    /** GET /operator/console — health + role cards + channel grid + the three meters (Operator/Console). */
    public function console(MeshGateService $gates, PeerUpgradeAgreementService $upgrades): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Console', [
            'surface' => SurfaceMeta::for('operator/console'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'console' => $authed ? $this->consoleData($gates, $upgrades) : null,
        ]);
    }

    /** GET /operator/roles — the qualify → request → approve → join board (Operator/Roles). */
    public function roles(MeshGateService $gates, PeerUpgradeAgreementService $upgrades): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Roles', [
            'surface' => SurfaceMeta::for('operator/roles'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'roles' => $authed ? $this->rolesData($gates, $upgrades) : null,
            // The last qualify probe, flashed back by POST /operator/roles/qualify.
            'probe' => $authed ? session('roles_probe') : null,
            // Founding node: every role self-asserts (no dual-meter, no scope) —
            // the UI drops the qualify/request dance and offers a single "Turn on".
            'founding' => \App\Support\FoundingContext::isFounding(),
        ]);
    }

    /** GET /operator/mesh — peers, transports, gates, sync-log excerpt (Operator/Mesh). */
    public function mesh(MeshGateService $gates, TransportService $transports): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Mesh', [
            'surface' => SurfaceMeta::for('operator/mesh'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'mesh' => $authed ? $this->meshData($gates, $transports) : null,
        ]);
    }

    /** GET /operator/identity — node identity + operator account + device-key registry (Operator/Identity). */
    public function identity(): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Identity', [
            'surface' => SurfaceMeta::for('operator/identity'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'identity' => $authed ? $this->identityData($operator) : null,
        ]);
    }

    /** GET /operator/versioning — our versions, peer versions, open proposals + per-meter status (Operator/Versioning). */
    public function versioning(PeerUpgradeAgreementService $upgrades, CapabilityProber $prober): Response
    {
        $operator = Auth::guard('operator')->user();
        $authed = $operator !== null;

        return Inertia::render('Operator/Versioning', [
            'surface' => SurfaceMeta::for('operator/versioning'),
            'authed' => $authed,
            'operator' => $authed ? ($operator->username ?? null) : null,
            'versioning' => $authed ? $this->versioningData($upgrades, $prober) : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // prop builders (operator-only)
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function readiness(MeshGateService $gates, CapabilityService $capabilities): array
    {
        $gateRows = $gates->evaluate();

        // Authority the settled way: a count of PLACES whose home copy lives here
        // (AuthorityResolver::OURS ⇔ authoritative_server_id IS NULL) — never a node rank.
        $total = (int) DB::table('jurisdictions')->whereNull('deleted_at')->count();
        $peerHeld = (int) DB::table('jurisdictions')->whereNull('deleted_at')
            ->whereNotNull('authoritative_server_id')->count();

        $lastSync = SyncLogEntry::query()->orderByDesc('seq')->first();

        return [
            'ready' => ! in_array(MeshGateService::FAIL, array_column($gateRows, 'status'), true),
            'gates' => $gateRows,
            'peers' => [
                'total' => FederationPeer::query()->whereNull('deleted_at')->count(),
                'trusted' => FederationPeer::query()->whereNull('deleted_at')
                    ->whereIn('status', [
                        FederationPeer::STATUS_TRUST_ESTABLISHED, FederationPeer::STATUS_SYNCING,
                        FederationPeer::STATUS_CONFLICT_RESOLUTION, FederationPeer::STATUS_BORDER_SETTLED,
                    ])->count(),
                'last_heartbeat_at' => FederationPeer::query()->whereNull('deleted_at')
                    ->max('last_heartbeat_at'),
                'last_sync' => $lastSync === null ? null : [
                    'seq' => (int) $lastSync->seq,
                    'direction' => $lastSync->direction,
                    'result' => $lastSync->result,
                    'created_at' => $lastSync->created_at?->toIso8601String(),
                ],
            ],
            'channels' => array_map(fn (array $c) => [
                'capability' => $c['capability'],
                'kind' => \App\Models\InstanceCapability::isGoverned($c['capability']) ? 'governed' : 'self-asserted',
                'priority' => $c['priority'],
                'granted_by_server_id' => $c['granted_by_server_id'],
            ], $capabilities->selfCapabilities()),
            'roles' => $gates->roles($this->rootScope()),
            'authority' => [
                'home_copies' => $total - $peerHeld,
                'peer_held' => $peerHeld,
                'total' => $total,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function consoleData(MeshGateService $gates, PeerUpgradeAgreementService $upgrades): array
    {
        $scope = $this->rootScope();
        $gateRows = $gates->evaluate();

        $leg = $scope !== null ? $upgrades->applicableConsentLeg($scope) : 'operator';
        $coAffected = $scope !== null ? count($upgrades->coAffectedPeerServerIds($scope)) : 0;

        $openByKind = PeerUpgradeProposal::query()
            ->where('status', PeerUpgradeProposal::STATUS_OPEN)
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind');

        return [
            'health' => [
                'ready' => ! in_array(MeshGateService::FAIL, array_column($gateRows, 'status'), true),
                'gates' => $gateRows,
            ],
            'scope' => $scope,
            'roles' => $gates->roles($scope),
            'channels' => $gates->channels($scope),
            // The three meters EXPLAINED — copy is server-authored so every client
            // speaks the settled language; live counts read straight off the services.
            'meters' => [
                'consent_leg' => $leg,
                'a' => [
                    'label' => 'Meter A — the operator board',
                    'explain' => 'While no government is seated for the scope, the vetted operator board attests. Scaling consent: 1 operator ⇒ 1, 2 ⇒ both, 3+ ⇒ two-thirds of active operators.',
                    'applies' => $leg === 'operator',
                    'active_operators' => OperatorAccount::query()
                        ->where('status', OperatorAccount::STATUS_ACTIVE)->whereNull('deleted_at')->count(),
                ],
                'b' => [
                    'label' => 'Meter B — the seated government',
                    'explain' => 'Once a government seats for the scope, its supermajority consent supersedes the operator board — operators can no longer attest on its behalf.',
                    'applies' => $leg === 'seated',
                ],
                'c' => [
                    'label' => 'Meter C — co-affected peers',
                    'explain' => 'Every trust-established peer that holds the home copy of a co-affected place must consent (unanimity). It auto-passes when no such peer exists.',
                    'applies' => $coAffected > 0,
                    'co_affected_peers' => $coAffected,
                ],
                'open_proposals' => [
                    'total' => (int) $openByKind->sum(),
                    'by_kind' => (object) $openByKind->map(fn ($n) => (int) $n)->all(),
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function rolesData(MeshGateService $gates, PeerUpgradeAgreementService $upgrades): array
    {
        $scope = $this->rootScope();

        // The pending role-grant requests + their LIVE meter state — the console
        // renders the meters, it never re-implements approval (the ★14 mapping).
        $pending = PeerUpgradeProposal::query()
            ->where('kind', PeerUpgradeProposal::KIND_ROLE_GRANT)
            ->where('status', PeerUpgradeProposal::STATUS_OPEN)
            ->orderByDesc('created_at')->limit(50)->get()
            ->map(fn (PeerUpgradeProposal $p) => [
                'id' => (string) $p->id,
                'capability' => (string) $p->capability,
                'scope_jurisdiction_id' => (string) $p->affected_root_jurisdiction_id,
                'requested_by_server_id' => (string) $p->proposed_by_server_id,
                'consent_leg' => $upgrades->applicableConsentLeg($p->affected_root_jurisdiction_id),
                'meter_a' => $upgrades->meterAPassed($p),
                'meter_b' => $upgrades->meterBPassed($p),
                'meter_c' => $upgrades->meterCPassed($p),
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values()->all();

        return [
            'scope' => $scope,
            'named' => $gates->roles($scope),
            'channels' => $gates->channels($scope),
            'pending' => $pending,
        ];
    }

    /** @return array<string,mixed> */
    private function meshData(MeshGateService $gates, TransportService $transports): array
    {
        return [
            'peers' => FederationPeer::query()->whereNull('deleted_at')
                ->orderByDesc('updated_at')->limit(100)->get()
                ->map(fn (FederationPeer $p) => [
                    'server_id' => (string) $p->server_id,
                    'name' => $p->name,
                    'url' => $p->url,
                    'status' => $p->status,
                    'relation' => $p->relation ?? FederationPeer::RELATION_SOVEREIGN,
                    'trusted' => $p->isTrusted(),
                    'constitutional_version' => $p->constitutional_version,
                    'app_release' => $p->app_release,
                    'last_heartbeat_at' => $p->last_heartbeat_at?->toIso8601String(),
                    'last_synced_seq' => $p->last_synced_seq,
                    'peer_head_seq' => $p->peer_head_seq,
                ])->values()->all(),
            'transports' => $transports->selfEndpoints(),
            'self_url' => config('cga.federation_self_url'),
            'gates' => $gates->evaluate(),
            // The cheap sync-log excerpt (sync_log is the existing append-only ledger).
            'sync' => SyncLogEntry::query()->orderByDesc('seq')->limit(25)->get()
                ->map(fn (SyncLogEntry $s) => [
                    'seq' => (int) $s->seq,
                    'peer_id' => $s->peer_id !== null ? (string) $s->peer_id : null,
                    'direction' => $s->direction,
                    'result' => $s->result,
                    'from_seq' => $s->from_seq,
                    'to_seq' => $s->to_seq,
                    'created_at' => $s->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function identityData(OperatorAccount $operator): array
    {
        // Read the singleton directly — a GET must never MINT an identity
        // (InstanceIdentityService::serverId() would); an unminted box renders null.
        $settings = InstanceSettings::current();

        return [
            'node' => [
                'server_id' => $settings->server_id,
                'instance_name' => $settings->instance_name,
                'public_key' => $settings->public_key,
                'public_key_fp' => $this->fingerprint($settings->public_key),
                'key_generated_at' => $settings->signing_key_generated_at?->toIso8601String(),
                'federation_enabled' => (bool) $settings->federation_enabled,
            ],
            'account' => [
                'username' => $operator->username,
                'status' => $operator->status,
                'mesh_operator_id' => $operator->mesh_operator_id !== null ? (string) $operator->mesh_operator_id : null,
                'created_at' => $operator->created_at?->toIso8601String(),
                'last_login_at' => $operator->last_login_at?->toIso8601String(),
            ],
            // The operator-plane device-key registry (OperatorDevice, G-OP): PUBLIC
            // keys only, surfaced as fingerprints. Empty list = nothing enrolled yet.
            'devices' => $operator->devices()->whereNull('deleted_at')
                ->orderBy('enrolled_at')->get()
                ->map(fn ($d) => [
                    'id' => (string) $d->id,
                    'label' => $d->label,
                    'fingerprint' => $this->fingerprint($d->device_public_key),
                    'enrolled_at' => $d->enrolled_at?->toIso8601String(),
                    'revoked_at' => $d->revoked_at?->toIso8601String(),
                    'active' => ! $d->isRevoked(),
                ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function versioningData(PeerUpgradeAgreementService $upgrades, CapabilityProber $prober): array
    {
        $settings = InstanceSettings::current();
        $ourCv = $settings->constitutionalVersion();

        $proposals = PeerUpgradeProposal::query()
            ->where('status', PeerUpgradeProposal::STATUS_OPEN)
            ->orderByDesc('created_at')->limit(50)->get()
            ->map(function (PeerUpgradeProposal $p) use ($upgrades, $prober) {
                $leg = $upgrades->applicableConsentLeg($p->affected_root_jurisdiction_id);

                // Which meters gate THIS kind (read-only re-statement of the services'
                // own rules): a constitutional_bump takes its consent leg + Meter C; a
                // role_grant takes its leg + Meter C only when the channel acts under a
                // peer's subtree; schema/app_release bumps take no meters at all.
                $metered = in_array($p->kind, [
                    PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP,
                    PeerUpgradeProposal::KIND_ROLE_GRANT,
                ], true);
                $cApplies = $metered && (
                    $p->kind === PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP
                    || $prober->affectsPeerSubtree((string) $p->capability)
                );

                return [
                    'id' => (string) $p->id,
                    'kind' => $p->kind,
                    'capability' => $p->capability,
                    'status' => $p->status,
                    'from_constitutional_version' => $p->from_constitutional_version,
                    'to_constitutional_version' => $p->to_constitutional_version,
                    'from_app_release' => $p->from_app_release,
                    'to_app_release' => $p->to_app_release,
                    'from_schema_version' => $p->from_schema_version,
                    'to_schema_version' => $p->to_schema_version,
                    'affected_root_jurisdiction_id' => (string) $p->affected_root_jurisdiction_id,
                    'proposed_by_server_id' => (string) $p->proposed_by_server_id,
                    'consent_leg' => $leg,
                    'meters' => [
                        'a' => [
                            'applies' => $metered && $leg === 'operator',
                            'passed' => $metered && $leg === 'operator' ? $upgrades->meterAPassed($p) : null,
                        ],
                        'b' => [
                            'applies' => $metered && $leg === 'seated',
                            'passed' => $metered && $leg === 'seated' ? $upgrades->meterBPassed($p) : null,
                        ],
                        'c' => [
                            'applies' => $cApplies,
                            'passed' => $cApplies ? $upgrades->meterCPassed($p) : null,
                        ],
                    ],
                    'created_at' => $p->created_at?->toIso8601String(),
                ];
            })->values()->all();

        return [
            'ours' => [
                'constitutional_version' => $ourCv,
                'pinned' => $settings->constitutional_version !== null,
                'version_pinned_at' => $settings->version_pinned_at?->toIso8601String(),
                'app_release' => $settings->app_release ?? config('cga.app_release'),
                'schema_version' => (string) config('cga.schema_version', '1'),
            ],
            'peers' => FederationPeer::query()->whereNull('deleted_at')
                ->orderByDesc('updated_at')->limit(100)->get()
                ->map(fn (FederationPeer $p) => [
                    'server_id' => (string) $p->server_id,
                    'name' => $p->name,
                    'status' => $p->status,
                    'constitutional_version' => $p->constitutional_version,
                    'app_release' => $p->app_release,
                    'version_match' => $p->constitutional_version !== null
                        ? $p->constitutional_version === $ourCv
                        : null,
                ])->values()->all(),
            'proposals' => $proposals,
        ];
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /** The root jurisdiction id (the MeshRoleCommand default scope), null on an unseeded box. */
    private function rootScope(): ?string
    {
        $id = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        return $id !== null ? (string) $id : null;
    }

    /** The console-standard short fingerprint over a base64 public key (never the key's secret half). */
    private function fingerprint(?string $publicKey): ?string
    {
        return $publicKey !== null && $publicKey !== ''
            ? implode(':', str_split(substr(hash('sha256', $publicKey), 0, 16), 4))
            : null;
    }
}
