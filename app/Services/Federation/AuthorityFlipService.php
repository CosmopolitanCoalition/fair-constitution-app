<?php

namespace App\Services\Federation;

use App\Models\AuthorityClaim;
use App\Models\FederationPeer;
use App\Models\PartitionExport;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Authority flip (Phase F, WF-JUR-08) — transfer sovereignty over a jurisdiction
 * subtree between instances. Consequential, so it is a SERVICE-WITH-AUDIT: every
 * half is recorded on the hash chain (module=federation) and gated to an
 * operator at the caller (CLI / console), rather than a citizen-filed catalog
 * form.
 *
 * Two phases across two databases (no distributed transaction):
 *   exportFlip — on the currently-authoritative instance: sign a subtree
 *     manifest, set the subtree's authoritative_server_id → the peer, record the
 *     claim + partition_export (transmitted).
 *   importFlip — on the receiving instance: verify the signed manifest, set the
 *     subtree's authoritative_server_id → NULL (we are now authoritative), record
 *     the claim + partition_export (flip_committed).
 * revert restores the prior authority for a flip that the peer never ACKed.
 */
class AuthorityFlipService
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly PartitionExportService $partition,
        private readonly FederationSyncService $sync,
        private readonly AuditService $audit,
    ) {}

    /**
     * Outbound flip: hand the subtree rooted at $rootId to $peer. Idempotent —
     * re-running an already-completed flip returns the existing export.
     */
    public function exportFlip(string $rootId, FederationPeer $peer, ?string $operatorUserId = null): PartitionExport
    {
        return DB::transaction(function () use ($rootId, $peer, $operatorUserId) {
            $juris = DB::table('jurisdictions')->where('id', $rootId)->whereNull('deleted_at')->first();

            if ($juris === null) {
                throw new RuntimeException("Unknown jurisdiction {$rootId}.");
            }
            if ($juris->authoritative_server_id !== null) {
                if ((string) $juris->authoritative_server_id === (string) $peer->server_id) {
                    $existing = PartitionExport::query()
                        ->where('jurisdiction_id', $rootId)->where('peer_id', $peer->id)
                        ->where('direction', PartitionExport::DIRECTION_OUTBOUND)
                        ->orderByDesc('created_at')->first();
                    if ($existing !== null) {
                        return $existing; // already flipped to this peer
                    }
                }
                throw new RuntimeException('Jurisdiction is authoritative on another server — cannot export.');
            }

            $descendants = $this->partition->descendants($rootId);
            $checkpoint = $this->sync->publishCheckpoint();
            $manifest = $this->partition->buildManifest($rootId, $descendants, (int) $checkpoint->audit_seq);
            $checksum = $this->partition->checksum($manifest);
            $signature = $this->identity->sign($this->partition->signingPayload($manifest));

            $export = PartitionExport::create([
                'jurisdiction_id' => $rootId,
                'direction' => PartitionExport::DIRECTION_OUTBOUND,
                'peer_id' => $peer->id,
                'manifest' => $manifest,
                'checksum' => $checksum,
                'checkpoint_audit_seq' => (int) $checkpoint->audit_seq,
                'signed_by' => $this->identity->serverId(),
                'signature' => $signature,
                'status' => PartitionExport::STATUS_SIGNED,
            ]);

            // Transfer authority on the subtree → the peer.
            DB::table('jurisdictions')->whereIn('id', $descendants)->update([
                'authoritative_server_id' => $peer->server_id,
                'authoritative_server_url' => $peer->url,
                'last_synced_at' => now(),
            ]);

            $this->recognize($rootId, $peer->id, $export->id);

            $export->status = PartitionExport::STATUS_TRANSMITTED;
            $export->authority_flipped_at = now();
            $export->save();

            $this->audit->append('federation', 'authority.flip_exported', [
                'root_jurisdiction_id' => $rootId,
                'to_peer_server_id' => $peer->server_id,
                'descendant_count' => count($descendants),
                'partition_export_id' => $export->id,
            ], 'WF-JUR-08', $operatorUserId, $rootId);

            return $export->refresh();
        });
    }

    /**
     * Inbound flip: assume authority for a subtree a peer is handing us. Verifies
     * the signed manifest against the peer's pinned key.
     *
     * @param  array<string,mixed>  $manifest
     */
    public function importFlip(array $manifest, string $signature, FederationPeer $fromPeer, ?string $operatorUserId = null): PartitionExport
    {
        $rootId = (string) ($manifest['root_jurisdiction_id'] ?? '');
        $checkpointSeq = (int) ($manifest['checkpoint_audit_seq'] ?? 0);

        if ($fromPeer->public_key === null
            || ! InstanceIdentityService::verify((string) $fromPeer->public_key, $this->partition->signingPayload($manifest), $signature)) {
            throw new RuntimeException('Partition bundle signature invalid.');
        }

        $descendants = array_map('strval', (array) ($manifest['descendant_ids'] ?? []));

        return DB::transaction(function () use ($rootId, $checkpointSeq, $manifest, $signature, $fromPeer, $descendants, $operatorUserId) {
            // We become authoritative (NULL) for every subtree row we hold.
            $present = DB::table('jurisdictions')->whereIn('id', $descendants)->whereNull('deleted_at')
                ->pluck('id')->map(fn ($i) => (string) $i)->all();

            DB::table('jurisdictions')->whereIn('id', $present)->update([
                'authoritative_server_id' => null,
                'authoritative_server_url' => null,
                'last_synced_at' => now(),
            ]);

            $export = PartitionExport::create([
                'jurisdiction_id' => $rootId,
                'direction' => PartitionExport::DIRECTION_INBOUND,
                'peer_id' => $fromPeer->id,
                'manifest' => $manifest,
                'checksum' => $this->partition->checksum($manifest),
                'checkpoint_audit_seq' => $checkpointSeq,
                'signed_by' => $fromPeer->server_id,
                'signature' => $signature,
                'status' => PartitionExport::STATUS_FLIP_COMMITTED,
                'authority_flipped_at' => now(),
            ]);

            $this->recognize($rootId, null, $export->id);

            $this->audit->append('federation', 'authority.flip_imported', [
                'root_jurisdiction_id' => $rootId,
                'from_peer_server_id' => $fromPeer->server_id,
                'descendant_count' => count($present),
                'missing_locally' => count($descendants) - count($present),
                'partition_export_id' => $export->id,
            ], 'WF-JUR-08', $operatorUserId, $rootId);

            return $export;
        });
    }

    /** Restore the prior authority for a flip the peer never confirmed. */
    public function revert(PartitionExport $export): void
    {
        DB::transaction(function () use ($export) {
            $descendants = array_map('strval', (array) ($export->manifest['descendant_ids'] ?? []));

            if ($export->direction === PartitionExport::DIRECTION_OUTBOUND) {
                // We reclaim authority (back to NULL).
                DB::table('jurisdictions')->whereIn('id', $descendants)->update([
                    'authoritative_server_id' => null,
                    'authoritative_server_url' => null,
                ]);
            } else {
                // Return authority to the peer it came from.
                $peer = $export->peer;
                DB::table('jurisdictions')->whereIn('id', $descendants)->update([
                    'authoritative_server_id' => $peer?->server_id,
                    'authoritative_server_url' => $peer?->url,
                ]);
            }

            AuthorityClaim::query()->where('partition_export_id', $export->id)->get()->each->delete();

            $export->status = PartitionExport::STATUS_REVERTED;
            $export->save();

            $this->audit->append('federation', 'authority.flip_reverted', [
                'partition_export_id' => $export->id,
                'root_jurisdiction_id' => $export->jurisdiction_id,
            ], 'WF-JUR-08');
        });
    }

    /**
     * Record the single recognized authority claim for a jurisdiction, retiring
     * any prior recognized claim first (the one-authority-per-jurisdiction index).
     */
    private function recognize(string $rootId, ?string $peerId, string $exportId): void
    {
        AuthorityClaim::query()
            ->where('jurisdiction_id', $rootId)
            ->whereIn('resolution', [AuthorityClaim::RESOLUTION_UNCONTESTED, AuthorityClaim::RESOLUTION_RECOGNIZED])
            ->whereNull('deleted_at')
            ->get()->each->delete();

        AuthorityClaim::create([
            'jurisdiction_id' => $rootId,
            'claimed_by_peer_id' => $peerId,
            'resolution' => AuthorityClaim::RESOLUTION_RECOGNIZED,
            'authority_flipped_at' => now(),
            'partition_export_id' => $exportId,
        ]);
    }
}
