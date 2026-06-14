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
 * CONSTITUTIONAL PIN — Art. V §2 Full Faith & Credit, AUTHORITATIVE-INSTANCE-
 * WINS. A peer's record for a jurisdiction WE are authoritative for is never
 * applied — our copy is canonical (result=conflict_authoritative_wins, the
 * conflict listed). A record for a jurisdiction the PEER is authoritative for
 * is mirrored (result=applied), tagged with the origin server_id.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class AuthoritativeWinsConflictTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_fed_authoritative_wins';

    public function test_authoritative_instance_wins_and_delegated_records_mirror(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $sync = app(FederationSyncService::class);
            $peer = $this->makeTrustedPeer();

            $jurisdictionIds = $conn->table('jurisdictions')->whereNull('deleted_at')->limit(2)->pluck('id');
            if ($jurisdictionIds->count() < 2) {
                $this->markTestSkipped('Live DB needs ≥2 jurisdictions — seed it first.');
            }
            $weOwn = (string) $jurisdictionIds[0];
            $peerOwns = (string) $jurisdictionIds[1];

            // Delegate $peerOwns to the peer; we keep authority over $weOwn (NULL).
            $conn->table('jurisdictions')->where('id', $peerOwns)->update(['authoritative_server_id' => $peer->server_id]);
            $conn->table('jurisdictions')->where('id', $weOwn)->update(['authoritative_server_id' => null]);

            // ── A record for a jurisdiction WE own → conflict, our copy wins ──
            $oursRecord = $this->record($weOwn);
            $conflictLog = $sync->ingestTail($peer, $this->signTail($this->craftTail($conn, $peer, [$oursRecord])));

            $this->assertSame(SyncLogEntry::RESULT_CONFLICT_AUTHORITATIVE_WINS, $conflictLog->result);
            $this->assertContains($oursRecord['id'], $conflictLog->detail['conflicts'], 'the conflict is listed in the ledger');
            $this->assertFalse(
                PublicRecord::query()->where('id', $oursRecord['id'])->exists(),
                'a record for a jurisdiction we are authoritative for is NOT mirrored'
            );

            // ── A record for a jurisdiction the PEER owns → applied/mirrored ──
            $delegatedRecord = $this->record($peerOwns);
            $appliedLog = $sync->ingestTail($peer, $this->signTail($this->craftTail($conn, $peer, [$delegatedRecord])));

            $this->assertSame(SyncLogEntry::RESULT_APPLIED, $appliedLog->result);
            $mirrored = PublicRecord::query()->where('id', $delegatedRecord['id'])->first();
            $this->assertNotNull($mirrored, 'a delegated record is mirrored');
            $this->assertSame($peer->server_id, (string) $mirrored->source_server_id, 'the mirror is tagged with the origin peer');
            $this->assertNull($mirrored->audit_seq, 'a recognized foreign record is NOT sealed into our chain');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }
}
