<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\AuditCheckpoint;
use App\Models\AuthorityClaim;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\SyncLogEntry;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-F — the federation console (Phase F, WF-JUR-06). A PURE READER of the
 * federation substrate: this instance's identity, its peers (ESM-20 status), the
 * Full-Faith-&-Credit sync ledger, the signed head checkpoints, and the
 * authority claims. Public read — the mesh state is public record (Art. II §2).
 */
class FederationConsoleController extends Controller
{
    public function show(): Response
    {
        $settings = InstanceSettings::current();

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
        ]);
    }
}
