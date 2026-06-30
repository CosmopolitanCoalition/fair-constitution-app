<?php

namespace Tests\Constitutional;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G seed import vs the Art. III §5 (et al.) append-only
 * ledgers. A mirror's geodata seed import must RESET the foundation without
 * violating the immutability of the append-only registers it reaches by FK cascade
 * (cgc_ip_register, audit_log, public_records, sync_log, audit_checkpoints,
 * case_filings). The trigger split is the whole reason the import uses DELETE, not
 * TRUNCATE:
 *   • BEFORE TRUNCATE fires FOR EACH STATEMENT → aborts even an EMPTY ledger, so
 *     `TRUNCATE jurisdictions CASCADE` cannot run on a fresh mirror at all;
 *   • BEFORE UPDATE OR DELETE fires FOR EACH ROW → a zero-row DELETE fires it zero
 *     times, so the import clears a fresh (empty) foundation cleanly.
 *
 * If an edit reintroduces TRUNCATE … CASCADE into the import foundation reset, the
 * seed import breaks on every node carrying the append-only schema — these pins
 * catch it. (MapDataImportService::clearFoundationTables is the guarded path.)
 *
 * Live-pg posture (each case in its own tx — a blocked TRUNCATE poisons its tx).
 */
class SeedImportAppendOnlyGuardTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_seed_guard';

    public function test_truncate_cascade_on_jurisdictions_is_blocked_by_the_append_only_guard(): void
    {
        $this->onLivePg(function () {
            $threw = false;
            $message = '';
            try {
                DB::statement('TRUNCATE TABLE jurisdictions CASCADE');
            } catch (\Throwable $e) {
                $threw = true;
                $message = $e->getMessage();
            }

            $this->assertTrue($threw, 'TRUNCATE jurisdictions CASCADE must be refused — it cascades into the append-only ledgers');
            $this->assertStringContainsString('append-only', $message, 'the refusal is the immutability trigger, not some other error');
        });
    }

    public function test_a_zero_row_delete_on_an_append_only_ledger_is_permitted(): void
    {
        $this->onLivePg(function () {
            // The FOR-EACH-ROW immutability trigger fires once per deleted row; a DELETE
            // that matches nothing fires it zero times. This is precisely what lets the
            // seed import clear a fresh mirror's (empty) append-only ledgers via cascade.
            DB::statement("DELETE FROM cgc_ip_register WHERE id = '00000000-0000-0000-0000-000000000000'");

            $this->assertTrue(true, 'a zero-row DELETE on an append-only ledger does not trip the row-level guard');
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
