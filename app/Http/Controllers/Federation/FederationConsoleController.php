<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\AuditCheckpoint;
use App\Models\AuthorityClaim;
use App\Models\ClusterJoinKey;
use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\Jurisdiction;
use App\Models\SyncLogEntry;
use App\Services\Federation\MeshGateService;
use App\Services\Federation\MeshProbeService;
use App\Services\Federation\PeerService;
use App\Services\Federation\ReadWriteRequestService;
use App\Services\Federation\TransportService;
use App\Services\Mirror\MirrorService;
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
    public function show(MirrorService $mirror, ReadWriteRequestService $rw, MeshGateService $gates, TransportService $transports): Response
    {
        $settings = InstanceSettings::current();

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
}
