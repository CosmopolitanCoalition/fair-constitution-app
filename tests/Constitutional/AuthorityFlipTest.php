<?php

namespace Tests\Constitutional;

use App\Models\AuthorityClaim;
use App\Models\PartitionExport;
use App\Services\Federation\AuthorityFlipService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PartitionExportService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase F authority flip (WF-JUR-08, Art. V §2). Sovereignty
 * over a jurisdiction subtree transfers between instances, both sides recorded:
 *
 *   exportFlip — the subtree's authoritative_server_id moves to the peer; a
 *     recognized authority_claim + an outbound partition_export are written; the
 *     flip is on the audit chain. Idempotent; reversible.
 *   importFlip — a peer's SIGNED manifest is verified, the subtree's
 *     authoritative_server_id returns to NULL (we are now authoritative), and an
 *     inbound flip_committed export is recorded. A forged signature is refused.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class AuthorityFlipTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_authority_flip';

    public function test_export_flip_transfers_authority_is_idempotent_and_reverts(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $flips = app(AuthorityFlipService::class);
            $partition = app(PartitionExportService::class);
            $peer = $this->makeTrustedPeer();

            $rootId = $this->smallSubtreeRoot($conn);
            $descendants = $partition->descendants($rootId);
            $this->assertGreaterThanOrEqual(2, count($descendants), 'a subtree has the root plus children');

            // ── exportFlip: authority moves to the peer ──────────────────────
            $export = $flips->exportFlip($rootId, $peer);

            $this->assertSame(PartitionExport::STATUS_TRANSMITTED, $export->status);
            $stillUs = $conn->table('jurisdictions')->whereIn('id', $descendants)
                ->whereNull('authoritative_server_id')->count();
            $this->assertSame(0, $stillUs, 'every subtree row now points at the peer');
            $this->assertSame(
                count($descendants),
                $conn->table('jurisdictions')->whereIn('id', $descendants)
                    ->where('authoritative_server_id', $peer->server_id)->count(),
                'authoritative_server_id transferred to the peer'
            );

            $claim = AuthorityClaim::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();
            $this->assertNotNull($claim);
            $this->assertSame($peer->id, (string) $claim->claimed_by_peer_id, 'the peer holds the recognized claim');
            $this->assertSame(AuthorityClaim::RESOLUTION_RECOGNIZED, $claim->resolution);

            $this->assertTrue(
                $conn->table('audit_log')->where('event', 'authority.flip_exported')
                    ->where('payload->root_jurisdiction_id', $rootId)->exists(),
                'the flip is chained'
            );

            // ── Idempotent: a second export returns the same record ──────────
            $again = $flips->exportFlip($rootId, $peer);
            $this->assertSame($export->id, $again->id, 'a repeat export is a no-op');
            $this->assertSame(1, PartitionExport::query()->where('jurisdiction_id', $rootId)
                ->where('direction', 'outbound')->count(), 'no duplicate export row');

            // ── revert: we reclaim authority ─────────────────────────────────
            $flips->revert($export->refresh());
            $this->assertSame(PartitionExport::STATUS_REVERTED, $export->refresh()->status);
            $this->assertSame(
                count($descendants),
                $conn->table('jurisdictions')->whereIn('id', $descendants)->whereNull('authoritative_server_id')->count(),
                'revert restores our authority over the subtree'
            );
            $this->assertNull(
                AuthorityClaim::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first(),
                'the recognized claim is retired on revert'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    public function test_import_flip_verifies_the_signed_manifest_and_assumes_authority(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $flips = app(AuthorityFlipService::class);
            $partition = app(PartitionExportService::class);
            $peerA = $this->makeTrustedPeer();

            $rootId = $this->smallSubtreeRoot($conn);
            $descendants = $partition->descendants($rootId);

            // Peer A currently owns the subtree.
            $conn->table('jurisdictions')->whereIn('id', $descendants)
                ->update(['authoritative_server_id' => $peerA->server_id]);

            $manifest = $partition->buildManifest($rootId, $descendants, 1);
            $signature = sodium_bin2base64(
                sodium_crypto_sign_detached($partition->signingPayload($manifest), $this->peerSecret),
                SODIUM_BASE64_VARIANT_ORIGINAL
            );

            // ── A forged signature is refused ────────────────────────────────
            try {
                $flips->importFlip($manifest, sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SIGN_BYTES), SODIUM_BASE64_VARIANT_ORIGINAL), $peerA);
                $this->fail('A forged partition signature must be refused.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('signature', $e->getMessage());
            }

            // ── The genuine signed manifest is accepted ──────────────────────
            $export = $flips->importFlip($manifest, $signature, $peerA);

            $this->assertSame(PartitionExport::STATUS_FLIP_COMMITTED, $export->status);
            $this->assertSame('inbound', $export->direction);
            $this->assertSame(
                count($descendants),
                $conn->table('jurisdictions')->whereIn('id', $descendants)->whereNull('authoritative_server_id')->count(),
                'we are now authoritative for the whole subtree'
            );
            $claim = AuthorityClaim::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();
            $this->assertNotNull($claim);
            $this->assertNull($claim->claimed_by_peer_id, 'the claim is now LOCAL (we are authoritative)');
            $this->assertTrue(
                $conn->table('audit_log')->where('event', 'authority.flip_imported')
                    ->where('payload->root_jurisdiction_id', $rootId)->exists(),
                'the import is chained'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * A small subtree we are authoritative for: a parent whose children are ALL
     * leaves (so descendants = parent + direct children only). The EXISTS/NOT
     * EXISTS predicates terminate early on the parent_id index — no full sort.
     */
    private function smallSubtreeRoot(\Illuminate\Database\Connection $conn): string
    {
        $row = $conn->selectOne(
            'SELECT p.id
             FROM jurisdictions p
             WHERE p.deleted_at IS NULL
               AND p.authoritative_server_id IS NULL
               AND EXISTS (SELECT 1 FROM jurisdictions c WHERE c.parent_id = p.id AND c.deleted_at IS NULL)
               AND NOT EXISTS (
                   SELECT 1 FROM jurisdictions c
                   JOIN jurisdictions gc ON gc.parent_id = c.id AND gc.deleted_at IS NULL
                   WHERE c.parent_id = p.id AND c.deleted_at IS NULL
               )
             LIMIT 1'
        );

        if ($row === null) {
            $this->markTestSkipped('Live DB has no parent-of-leaves jurisdiction — seed the hierarchy.');
        }

        $rootId = (string) $row->id;

        if (count(app(PartitionExportService::class)->descendants($rootId)) > 2000) {
            $this->markTestSkipped('Subtree unexpectedly large — skip to avoid a heavy rolled-back flip.');
        }

        return $rootId;
    }
}
