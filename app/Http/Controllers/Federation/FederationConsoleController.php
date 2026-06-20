<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\AuditCheckpoint;
use App\Models\AuthorityClaim;
use App\Models\ClusterJoinKey;
use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceCapability;
use App\Models\InstanceSettings;
use App\Models\Jurisdiction;
use App\Models\OperatorAccount;
use App\Models\PeerUpgradeProposal;
use App\Models\SyncLogEntry;
use App\Services\Federation\BrokerCredentialService;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\MeshGateService;
use App\Services\Federation\MeshProbeService;
use App\Services\Federation\PeerService;
use App\Services\Federation\ReadWriteRequestService;
use App\Services\Federation\TransportService;
use App\Services\Identity\MeshRoleGrantService;
use App\Services\Mirror\MirrorService;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-F — the federation console (Phase F, WF-JUR-06). A reader of the federation
 * substrate: this instance's identity, its peers (ESM-20 status), the
 * Full-Faith-&-Credit sync ledger, the signed head checkpoints, and the authority
 * claims. Public read — the mesh state is public record (Art. II §2).
 *
 * Phase G (G3b) adds the "Join a cluster" actions: an operator can adopt THIS
 * instance into an existing cluster as a read-only MIRROR (the browser counterpart
 * to the `cluster:join` / `cluster:request-adoption` CLI — one shared MirrorService
 * path). A mirror is authoritative for nothing.
 */
class FederationConsoleController extends Controller
{
    public function show(MirrorService $mirror, ReadWriteRequestService $rw, MeshGateService $gates, TransportService $transports, PeerUpgradeAgreementService $upgrades, BrokerCredentialService $brokerCredentials): Response
    {
        $settings = InstanceSettings::current();

        // Mesh Roles — the broker credential STATUS (operator-only). status() carries the domain + zone +
        // configured flag; NEVER the token value (the token lives encrypted in storage, write-only from the UI).
        $brokerCreds = Auth::guard('operator')->check() ? $brokerCredentials->status() : [];

        // Mesh Roles ★14 — the Role Board (a box's role = the SET of channels it has established) + the
        // PENDING REQUESTS panel (open role-grant requests + their LIVE dual-meter state, read straight off
        // PeerUpgradeAgreementService — the console renders the meter, it never re-implements approval).
        $rootId = (string) Jurisdiction::query()->whereNull('parent_id')->whereNull('deleted_at')->value('id');
        $pending = collect();
        if (Auth::guard('operator')->check()) {
            $pending = PeerUpgradeProposal::query()
                ->where('kind', PeerUpgradeProposal::KIND_ROLE_GRANT)
                ->where('status', PeerUpgradeProposal::STATUS_OPEN)
                ->orderByDesc('created_at')->limit(50)->get()
                ->map(fn (PeerUpgradeProposal $p) => [
                    'id' => (string) $p->id,
                    'capability' => (string) $p->capability,
                    'scope' => substr((string) $p->affected_root_jurisdiction_id, 0, 8),
                    'requested_by' => substr((string) $p->proposed_by_server_id, 0, 8),
                    'consent_leg' => $upgrades->applicableConsentLeg($p->affected_root_jurisdiction_id),
                    'meter_a' => $upgrades->meterAPassed($p),
                    'meter_b' => $upgrades->meterBPassed($p),
                    'meter_c' => $upgrades->meterCPassed($p),
                    'created_at' => $p->created_at?->toIso8601String(),
                ])->values();
        }

        $mirrorMembership = ClusterMembership::query()
            ->where('role', ClusterMembership::ROLE_MIRROR)
            ->whereNotIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
            ->latest('updated_at')
            ->first();

        return Inertia::render('Jurisdictions/Federation', [
            'instance' => [
                'server_id' => $settings->server_id,
                'name' => $settings->instance_name,
                'enabled' => (bool) $settings->federation_enabled,
                'schema_version' => (string) config('cga.schema_version', '1'),
                'public_key_fp' => $settings->public_key
                    ? implode(':', str_split(substr(hash('sha256', (string) $settings->public_key), 0, 16), 4))
                    : null,
            ],
            // G8b — the operator's "run the gates, get greens" panel: node-readiness
            // gates (always shown), what we advertise, and the last per-peer probe result
            // flashed by probePeer(). The mesh ACTIONS (discover/handshake/probe) are
            // operator-authed routes; this read is harmless public mesh state (Art. II §2).
            'mesh' => [
                'gates' => $gates->evaluate(),
                'transports' => $transports->selfEndpoints(),
                'self_url' => config('cga.federation_self_url'),
                'probe' => session('mesh_probe'),
            ],
            // Mesh Roles ★14 — the Role Board (public-readable mesh state, Art. II §2; the actionable
            // controls below sit behind the operator guard) + pending role-grant requests (operator-only).
            'roles' => [
                'channels' => $gates->channels($rootId === '' ? null : $rootId),
                'scope' => $rootId,
                'pending' => $pending,
                // Operator-only broker credential status (domains + zones, NEVER the token).
                'broker_credentials' => $brokerCreds,
            ],
            'mirror' => [
                'is_mirror' => $mirror->isMirror(),
                'host_server_id' => $settings->mirror_of_server_id,
                'adopted_at' => $settings->mirror_adopted_at?->toIso8601String(),
                'membership_state' => $mirrorMembership?->state,
            ],
            // G3c — root jurisdictions for the mirror-side read-write petition
            // picker. Public info (jurisdiction names, Art. II §2); tiny list.
            'roots' => Jurisdiction::query()->whereNull('parent_id')->whereNull('deleted_at')
                ->orderBy('name')->limit(50)->get(['id', 'name'])
                ->map(fn (Jurisdiction $j) => ['id' => (string) $j->id, 'name' => $j->name])->values(),
            'peers' => FederationPeer::query()->whereNull('deleted_at')
                ->orderByDesc('updated_at')->limit(50)->get()
                ->map(fn (FederationPeer $p) => [
                    'id' => (string) $p->id,
                    'server_id' => (string) $p->server_id,
                    'name' => $p->name,
                    'url' => $p->url,
                    'status' => $p->status,
                    'last_heartbeat_at' => $p->last_heartbeat_at?->toIso8601String(),
                    'last_synced_seq' => $p->last_synced_seq,
                    'peer_head_seq' => $p->peer_head_seq,
                ])->values(),
            'sync' => SyncLogEntry::query()->orderByDesc('seq')->limit(25)->get()
                ->map(fn (SyncLogEntry $s) => [
                    'seq' => $s->seq,
                    'direction' => $s->direction,
                    'result' => $s->result,
                    'from_seq' => $s->from_seq,
                    'to_seq' => $s->to_seq,
                    'created_at' => $s->created_at?->toIso8601String(),
                ])->values(),
            'checkpoints' => AuditCheckpoint::query()->orderByDesc('seq')->limit(10)->get()
                ->map(fn (AuditCheckpoint $c) => [
                    'seq' => $c->seq,
                    'audit_seq' => $c->audit_seq,
                    'head_hash' => substr((string) $c->head_hash, 0, 16).'…',
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->values(),
            'claims' => AuthorityClaim::query()->whereNull('deleted_at')
                ->with('peer:id,name')->orderByDesc('authority_flipped_at')->limit(50)->get()
                ->map(fn (AuthorityClaim $a) => [
                    'id' => (string) $a->id,
                    'jurisdiction_id' => (string) $a->jurisdiction_id,
                    'resolution' => $a->resolution,
                    'authority' => $a->claimed_by_peer_id === null ? 'this instance' : ($a->peer?->name ?? 'a peer'),
                    'flipped_at' => $a->authority_flipped_at?->toIso8601String(),
                ])->values(),
            // G3c — the HOST adoption console, populated ONLY for an authenticated
            // operator (the auth:operator plane). Keys map explicit safe fields —
            // NEVER key_hash. The console page itself stays citizen-public (Art. II §2).
            'host' => Auth::guard('operator')->check()
                ? [
                    'authed' => true,
                    'operator' => Auth::guard('operator')->user()?->username,
                    'keys' => ClusterJoinKey::query()->orderByDesc('created_at')->limit(50)->get()
                        ->map(fn (ClusterJoinKey $k) => [
                            'handle' => $k->handle,
                            'uses' => (int) $k->uses,
                            'max_uses' => (int) $k->max_uses,
                            'expires_at' => $k->expires_at?->toIso8601String(),
                            'revoked_at' => $k->revoked_at?->toIso8601String(),
                            'live' => $k->isLive(),
                        ])->values(),
                    'requests' => $mirror->pendingRequests()
                        ->map(fn ($r) => [
                            'id' => (string) $r->id,
                            'applicant_server_id' => substr((string) $r->applicant_server_id, 0, 8),
                            'applicant_name' => $r->applicant_name,
                            'requested_relation' => $r->requested_relation,
                            'requested_scope' => $r->requested_scope_jurisdiction_id
                                ? substr((string) $r->requested_scope_jurisdiction_id, 0, 8)
                                : null,
                            'note' => $r->note,
                            'created_at' => $r->created_at?->toIso8601String(),
                        ])->values(),
                    'rw_requests' => $rw->pending()
                        ->map(fn ($r) => [
                            'id' => (string) $r->id,
                            'applicant_server_id' => substr((string) $r->applicant_server_id, 0, 8),
                            'root_jurisdiction_id' => substr((string) $r->root_jurisdiction_id, 0, 8),
                            'note' => $r->note,
                            'submitted_at' => $r->submitted_at?->toIso8601String(),
                        ])->values(),
                ]
                : ['authed' => false],
        ]);
    }

    /**
     * G3b — adopt THIS instance into a cluster as a read-only mirror. With a join
     * key it is admitted in one step; without one, a request is queued for the host
     * operator to vouch (re-submit to poll). One shared MirrorService path with the
     * CLI; a mirror is authoritative for nothing.
     */
    public function join(Request $request, MirrorService $mirror): RedirectResponse
    {
        $validated = $request->validate([
            'host_url' => ['required', 'url', 'max:255'],
            'join_key' => ['nullable', 'string', 'max:255'],
            // G3c negotiation (the stepped wizard). co_member is advisory only.
            'requested_relation' => ['nullable', Rule::in(['mirror', 'co_member'])],
            'requested_scope_jurisdiction_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
            'geodata_posture' => ['nullable', Rule::in(['already_have', 'pull_from_origin', 'skip'])],
        ]);

        if ($mirror->isMirror()) {
            return back()->withErrors(['host_url' => 'This instance is already a mirror — leave the current cluster first.']);
        }

        // Record the instance's geodata posture (the signed GEODATA_ORIGIN channel,
        // G3c N3; the raster bytes land with Phase H). Local choice, not federated.
        if (! empty($validated['geodata_posture'])) {
            $settings = InstanceSettings::current();
            $settings->geodata_posture = $validated['geodata_posture'];
            $settings->save();
        }

        $negotiation = [
            'requested_relation' => $validated['requested_relation'] ?? 'mirror',
            'requested_scope_jurisdiction_id' => $validated['requested_scope_jurisdiction_id'] ?? null,
            'note' => $validated['note'] ?? null,
        ];

        try {
            if (! empty($validated['join_key'])) {
                $membership = $mirror->joinHost($validated['host_url'], (string) $validated['join_key'], $negotiation);
                $status = $membership->state === ClusterMembership::STATE_LIVE
                    ? 'Joined — this instance is now a read-only mirror.'
                    : "Adoption accepted; backfilling the host's corpus (state: {$membership->state}).";
            } else {
                $membership = $mirror->requestJoin($validated['host_url'], $negotiation);
                $status = $membership === null
                    ? 'Request submitted — waiting for the host operator to vouch this instance. Re-submit to poll.'
                    : "Vouched and joined — this instance is now a read-only mirror (state: {$membership->state}).";
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['host_url' => $e->getMessage()]);
        }

        return back()->with('status', $status);
    }

    /** G3b — leave the cluster: stop being a read-only mirror (the write-guard switches off). */
    public function leave(MirrorService $mirror): RedirectResponse
    {
        if (! $mirror->isMirror()) {
            return back()->withErrors(['host_url' => 'This instance is not a mirror.']);
        }

        $mirror->leave();

        return back()->with('status', 'Left the cluster — this instance is no longer a mirror.');
    }

    /**
     * G3c — MIRROR side: petition the host for read-write authority over a
     * jurisdiction subtree (the GUI front door to the governed flip). Operator-
     * grade (auth:operator route). It only COMPOSES + SENDS the petition; it
     * grants nothing locally — the host's government decides (Art. V §7).
     */
    public function requestReadWrite(Request $request, MirrorService $mirror): RedirectResponse
    {
        $validated = $request->validate([
            'root_jurisdiction_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $ack = $mirror->petitionReadWrite($validated['root_jurisdiction_id'], $validated['note'] ?? null);
        } catch (\Throwable $e) {
            return back()->withErrors(['rw_request' => $e->getMessage()]);
        }

        return back()->with('status', "Read-write petition sent to the host (state: {$ack['state']}) — its government decides by supermajority (Art. V §7). A mirror stays authoritative for nothing until authority flips.");
    }

    /**
     * G8b — operator: discover a peer by URL (read its public identity + learn its
     * transports). The GUI front door to `federation:peer:discover`.
     */
    public function discoverPeer(Request $request, PeerService $peers): RedirectResponse
    {
        $validated = $request->validate(['url' => ['required', 'url', 'max:255']]);

        try {
            $peer = $peers->discover($validated['url']);
        } catch (\Throwable $e) {
            return back()->withErrors(['mesh' => $e->getMessage()]);
        }

        return back()->with('status', "Discovered {$peer->name} ({$peer->server_id}). Handshake it to establish trust.");
    }

    /**
     * G8b — operator: handshake a discovered peer (mutual identity pin + transport
     * learning → trust_established). The GUI front door to `federation:peer:handshake`.
     */
    public function handshakePeer(Request $request, PeerService $peers): RedirectResponse
    {
        $validated = $request->validate(['peer' => ['required', 'string', 'max:255']]);

        $peer = FederationPeer::query()->matchingNeedle($validated['peer'])->whereNull('deleted_at')->first();

        if ($peer === null) {
            return back()->withErrors(['mesh' => 'No such peer — discover it first.']);
        }

        try {
            $peers->initiateHandshake($peer);
        } catch (\Throwable $e) {
            return back()->withErrors(['mesh' => $e->getMessage()]);
        }

        return back()->with('status', "Handshake complete — {$peer->name} is trust_established.");
    }

    /**
     * G8b — operator: probe a peer over every known transport FROM INSIDE the container
     * (the GUI's mesh:doctor). Flashes the per-transport result; persists nothing.
     */
    public function probePeer(Request $request, MeshProbeService $probe): RedirectResponse
    {
        $validated = $request->validate(['target' => ['required', 'string', 'max:255']]);

        $result = $probe->probe($validated['target']);

        return back()
            ->with('mesh_probe', $result)
            ->with('status', "Probe: {$result['reached']}/{$result['total']} transport(s) reached {$result['target']}.");
    }

    /**
     * Mesh Roles ★15 — establish a SELF-ASSERTED channel (mesh.member/mirror/etl). The GUI front door to
     * `mesh:role request` for the free channels; refuses a governed one (it needs a grant). Operator-grade.
     */
    public function establishChannel(Request $request, CapabilityService $capabilities): RedirectResponse
    {
        $validated = $request->validate(['capability' => ['required', Rule::in(InstanceCapability::SELF_ASSERTED)]]);

        try {
            $capabilities->registerSelf($validated['capability']);
        } catch (\Throwable $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }

        return back()->with('status', "Established [{$validated['capability']}] (self-asserted — no consent needed).");
    }

    /**
     * Mesh Roles ★15 — open a role-grant REQUEST for a GOVERNED channel (QUALIFY runs first; an unqualified
     * channel never opens a proposal). The GUI front door to `mesh:role request`. Grants nothing — the
     * dual-meter consent decides (the request appears in the PENDING REQUESTS panel).
     */
    public function requestChannel(Request $request, MeshRoleGrantService $grants): RedirectResponse
    {
        $validated = $request->validate([
            'capability' => ['required', Rule::in(InstanceCapability::GOVERNED)],
            'scope_jurisdiction_id' => ['required', 'uuid'],
        ]);

        try {
            $proposal = $grants->request($validated['capability'], $validated['scope_jurisdiction_id']);
        } catch (\Throwable $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }

        return back()->with('status', "Requested [{$validated['capability']}] — proposal ".substr((string) $proposal->id, 0, 8).". The dual-meter consent decides.");
    }

    /**
     * Mesh Roles ★15 — approve a role-grant (the bootstrap operator-board path: record Meter A + ratify).
     * A seated government approves through the MultiJurisdictionVote (Meter B), not this button. On all
     * gates passing the channel is granted + enabled. Operator-grade.
     */
    public function approveChannel(Request $request, MeshRoleGrantService $grants, PeerUpgradeAgreementService $upgrades): RedirectResponse
    {
        $validated = $request->validate(['proposal_id' => ['required', 'uuid']]);

        $proposal = PeerUpgradeProposal::query()
            ->where('kind', PeerUpgradeProposal::KIND_ROLE_GRANT)
            ->whereKey($validated['proposal_id'])->first();
        if ($proposal === null) {
            return back()->withErrors(['roles' => 'No such role-grant request.']);
        }

        try {
            if ($upgrades->applicableConsentLeg($proposal->affected_root_jurisdiction_id) === 'operator') {
                $operator = OperatorAccount::query()->whereKey(Auth::guard('operator')->id())->first();
                if ($operator === null) {
                    return back()->withErrors(['roles' => 'No operator account to attest as (Meter A).']);
                }
                $upgrades->recordOperatorConsent($proposal, $operator, true);
            }
            $ratified = $grants->ratify($proposal);
        } catch (\Throwable $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }

        return back()->with('status', "Granted [{$ratified->capability}] — channel enabled, grant minted.");
    }

    /** Mesh Roles ★15 — drop one of our channels (always unilateral). The GUI front door to `mesh:role revoke`. */
    public function revokeChannel(Request $request, MeshRoleGrantService $grants): RedirectResponse
    {
        $validated = $request->validate(['capability' => ['required', Rule::in(InstanceCapability::CHANNELS)]]);

        $grants->revoke($validated['capability'], 'operator-revoked via console');

        return back()->with('status', "Dropped [{$validated['capability']}].");
    }

    /**
     * Mesh Roles ★15 — register / switch a transport (the operator's "switch method", today CLI-only via
     * transport:register). The composable JOIN channel is the operator's own infra choice (no consent gate).
     */
    public function registerTransport(Request $request, TransportService $transports): RedirectResponse
    {
        $validated = $request->validate([
            'transport' => ['required', Rule::in(\App\Models\FederationTransport::TRANSPORTS)],
            'address' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        try {
            $transports->registerSelf($validated['transport'], $validated['address'], (int) ($validated['priority'] ?? 100));
        } catch (\Throwable $e) {
            return back()->withErrors(['roles' => $e->getMessage()]);
        }

        return back()->with('status', "Advertised transport [{$validated['transport']}].");
    }

    /** Mesh Roles ★15 — stop advertising a transport (switch method / drop a dead rung). */
    public function disableTransport(Request $request, TransportService $transports): RedirectResponse
    {
        $validated = $request->validate(['transport' => ['required', Rule::in(\App\Models\FederationTransport::TRANSPORTS)]]);

        $transports->disableSelf($validated['transport']);

        return back()->with('status', "Stopped advertising [{$validated['transport']}].");
    }

    /**
     * Mesh Roles — drop a Cloudflare DNS-edit credential for a domain into THIS box's local broker store
     * (the operator-UI token input you asked for). The token is encrypted at rest in storage (gitignored,
     * never federated), WRITE-ONLY from the UI: it is never read back to a prop or response. Operator-grade.
     * After this + `lego` on PATH, the box qualifies for broker.dns/broker.tls.
     */
    public function setBrokerCredential(Request $request, BrokerCredentialService $credentials): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+$/i'],
            'zone_id' => ['required', 'string', 'max:64'],
            'cloudflare_token' => ['required', 'string', 'max:512'],
        ]);

        $credentials->setCredential($validated['domain'], $validated['zone_id'], $validated['cloudflare_token']);

        return back()->with('status', "Broker credential stored for {$validated['domain']} (encrypted on this box; it never federates). Qualify broker.tls once lego is installed.");
    }

    /** Mesh Roles — remove a stored broker credential for a domain (local only). Operator-grade. */
    public function forgetBrokerCredential(Request $request, BrokerCredentialService $credentials): RedirectResponse
    {
        $validated = $request->validate(['domain' => ['required', 'string', 'max:253']]);

        $credentials->forget($validated['domain']);

        return back()->with('status', "Broker credential removed for {$validated['domain']}.");
    }
}
