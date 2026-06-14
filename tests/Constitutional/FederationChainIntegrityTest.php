<?php

namespace Tests\Constitutional;

use App\Models\PublicRecord;
use App\Models\SyncLogEntry;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. V §2 Full Faith & Credit, integrity floor. A peer's
 * tail is APPLIED only if (a) its signature verifies against the peer's pinned
 * key AND (b) its foreign audit segment recomputes hash-for-hash. Either failing
 * ⇒ rejected_tamper, nothing applied. A valid signature alone is NOT enough —
 * the chain is independently re-walked, so a peer cannot ship an internally
 * inconsistent history under a good signature.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class FederationChainIntegrityTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_fed_chain_integrity';

    public function test_a_valid_signed_tail_applies_but_every_tamper_is_rejected(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $sync = app(FederationSyncService::class);
            $peer = $this->makeTrustedPeer();

            // ── A valid, signed tail with one record for an unknown (peer-own)
            //    jurisdiction APPLIES (not tamper) ────────────────────────────
            $goodRecord = $this->record(jurisdictionId: null); // global → applies
            $tail = $this->signTail($this->craftTail($conn, $peer, [$goodRecord]));

            $log = $sync->ingestTail($peer, $tail);
            $this->assertSame(SyncLogEntry::RESULT_APPLIED, $log->result, 'a clean signed tail applies');
            $this->assertTrue(
                PublicRecord::query()->where('id', $goodRecord['id'])->exists(),
                'the record is mirrored'
            );

            // ── Tamper #1: mutate a record AFTER signing → signature fails ────
            $tampered = $tail; // still carries the original signature
            $tampered['records'][0]['title'] = 'TAMPERED TITLE';
            $forgedRecordId = (string) \Illuminate\Support\Str::uuid();
            $tampered['records'][0]['id'] = $forgedRecordId;

            $log2 = $sync->ingestTail($peer, $tampered);
            $this->assertSame(SyncLogEntry::RESULT_REJECTED_TAMPER, $log2->result, 'a post-sign mutation is rejected');
            $this->assertFalse(
                PublicRecord::query()->where('id', $forgedRecordId)->exists(),
                'a rejected tail applies NOTHING'
            );

            // ── Tamper #2: break the foreign chain linkage but RE-SIGN with the
            //    peer's key → chain recompute still catches it ────────────────
            $broken = $this->craftTail($conn, $peer, []);
            // Corrupt a middle entry's hash so the next link no longer chains.
            if (count($broken['entries']) >= 2) {
                $broken['entries'][0]['hash'] = str_repeat('0', 64);
            } else {
                $broken['entries'][0]['payload']['injected'] = 'tamper';
            }
            $broken = $this->signTail($broken); // a VALID signature over the broken chain

            $log3 = $sync->ingestTail($peer, $broken);
            $this->assertSame(
                SyncLogEntry::RESULT_REJECTED_TAMPER,
                $log3->result,
                'a validly-signed but internally-inconsistent chain is still rejected (independent recompute)'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }
}
