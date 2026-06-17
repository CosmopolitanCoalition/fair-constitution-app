<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\AuditCheckpoint;
use App\Models\AuthorityClaim;
use App\Models\ClusterJoinKey;
use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\SyncLogEntry;
use App\Services\Mirror\MirrorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function show(MirrorService $mirror): Response
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
            'mirror' => [
                'is_mirror' => $mirror->isMirror(),
                'host_server_id' => $settings->mirror_of_server_id,
                'adopted_at' => $settings->mirror_adopted_at?->toIso8601String(),
                'membership_state' => $mirrorMembership?->state,
            ],
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
                            'created_at' => $r->created_at?->toIso8601String(),
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
        ]);

        if ($mirror->isMirror()) {
            return back()->withErrors(['host_url' => 'This instance is already a mirror — leave the current cluster first.']);
        }

        try {
            if (! empty($validated['join_key'])) {
                $membership = $mirror->joinHost($validated['host_url'], (string) $validated['join_key']);
                $status = $membership->state === ClusterMembership::STATE_LIVE
                    ? 'Joined — this instance is now a read-only mirror.'
                    : "Adoption accepted; backfilling the host's corpus (state: {$membership->state}).";
            } else {
                $membership = $mirror->requestJoin($validated['host_url']);
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
}
