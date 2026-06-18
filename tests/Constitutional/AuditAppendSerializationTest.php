<?php

namespace Tests\Constitutional;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the hash-chained audit log must not FORK under concurrency.
 *
 * A `SELECT … ORDER BY seq DESC LIMIT 1 FOR UPDATE` head-lock does NOT serialize
 * appends: a second appender that blocks on the old head re-reads that stale row
 * after the first commits and anchors a sibling — two rows sharing one parent.
 * That actually happened on the live multi-worker stack (scheduler + Horizon),
 * forking the chain. The fix serializes EVERY append on a transaction-scoped
 * advisory lock. These pins assert (1) the lock genuinely mutually excludes, (2)
 * appends stay continuous, and (3) the racy head-row lock is gone for good.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AuditAppendSerializationTest extends TestCase
{
    use FederationSyncSupport;

    public function test_the_append_lock_mutually_excludes_two_appenders(): void
    {
        $a = $this->livePg('pgsql_lock_a');
        $b = $this->livePg('pgsql_lock_b');
        $a->beginTransaction();
        $b->beginTransaction();

        try {
            // A holds the append lock (blocks until acquired).
            $a->statement('SELECT pg_advisory_xact_lock(?)', [AuditService::APPEND_LOCK_KEY]);

            // B cannot acquire it while A holds it — appends are serialized.
            $got = $b->selectOne('SELECT pg_try_advisory_xact_lock(?) AS got', [AuditService::APPEND_LOCK_KEY]);
            $this->assertFalse((bool) $got->got, 'a second appender must not hold the append lock concurrently');
        } finally {
            $a->rollBack();
            $b->rollBack();
        }
    }

    public function test_a_batch_of_appends_stays_one_continuous_chain(): void
    {
        $conn = $this->livePg('pgsql_append_batch');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_append_batch');
        $conn->beginTransaction();

        try {
            $audit = app(AuditService::class);
            $firstNew = $audit->latestSeq() + 1;

            for ($i = 0; $i < 6; $i++) {
                $audit->append('test', 'serialization.probe', ['i' => $i]);
            }

            $this->assertTrue(
                $audit->verifyChain($firstNew) === true,
                'serialized appends recompute as one continuous chain',
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    public function test_the_racy_head_row_lock_is_gone(): void
    {
        $src = file_get_contents(base_path('app/Services/AuditService.php'));

        $this->assertStringContainsString('pg_advisory_xact_lock', $src, 'append must serialize on the advisory lock');
        $this->assertStringNotContainsString(
            'ORDER BY seq DESC LIMIT 1 FOR UPDATE',
            $src,
            'the fork-prone head-row lock must not return',
        );
    }
}
