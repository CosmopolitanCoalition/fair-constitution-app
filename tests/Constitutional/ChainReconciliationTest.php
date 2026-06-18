<?php

namespace Tests\Constitutional;

use App\Models\AuditChainReconciliation;
use App\Services\AuditService;
use App\Services\ChainReconciliationService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the audit chain is tamper-EVIDENT, not tamper-PROOF. A
 * genuine break is never silently rewritten; a legitimate authority ACKNOWLEDGES
 * it on the record (with a reason), and the chain re-grounds forward. These pins:
 *  - an UNacknowledged break still fails verifyChain (tamper-evidence holds);
 *  - an acknowledgement (by the de-facto operator collective here) re-grounds the
 *    chain AND is itself recorded as a `chain.reconciled` audit entry;
 *  - a mis-described acknowledgement (wrong observed parent) does NOT bless a break;
 *  - acknowledging requires a reason and refuses a seq that chains cleanly.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ChainReconciliationTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_reconcile';

    public function test_unacknowledged_break_fails_then_an_acknowledgement_regrounds_it(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $audit = app(AuditService::class);
            $recon = app(ChainReconciliationService::class);
            $operator = app(OperatorIdentityService::class)->register('recon_'.Str::lower(Str::random(8)), 'correct horse battery');

            $head = DB::table('audit_log')->orderByDesc('seq')->first(['seq', 'hash']);

            // A clean child R of the head, then a FORK sibling F (same parent as R) —
            // exactly the pre-fix concurrency race, reproduced deterministically.
            $r = $audit->append('test', 'probe.r', ['x' => 1]);
            $forkCanonical = AuditService::canonicalJson(['fork' => 1]);
            $forkHash = AuditService::chainHash((string) $head->hash, $forkCanonical);
            DB::insert(
                'INSERT INTO audit_log (occurred_at, module, event, payload, prev_hash, hash, rejected, created_at)
                 VALUES (now(), ?, ?, ?::jsonb, ?, ?, false, now())',
                ['test', 'probe.fork', $forkCanonical, (string) $head->hash, $forkHash],
            );
            $forkSeq = (int) DB::table('audit_log')->where('hash', $forkHash)->value('seq');

            // Tamper-evident: the unacknowledged fork fails verification at exactly F.
            $this->assertSame($forkSeq, $audit->verifyChain((int) $r->seq), 'an unacknowledged break must fail');
            $breaks = $recon->detectBreaks((int) $r->seq);
            $this->assertSame($forkSeq, $breaks[0]['break_seq']);
            $this->assertFalse($breaks[0]['acknowledged']);

            // The de-facto operator collective acknowledges it, on the record.
            $ack = $recon->acknowledge(
                $forkSeq,
                'Pre-advisory-lock append race forked the chain; grounded by the operator.',
                AuditChainReconciliation::AUTHORITY_OPERATOR_COLLECTIVE,
                $operator,
                null,
                ['operators' => [$operator->id], 'threshold' => '1-of-1 (single-box de-facto board)'],
            );

            // Re-grounded: the chain now verifies across the acknowledged break.
            $this->assertTrue($audit->verifyChain((int) $r->seq) === true, 'an acknowledged break re-grounds the chain');
            $this->assertTrue($recon->detectBreaks((int) $r->seq)[0]['acknowledged'], 'the break is now acknowledged');

            // The acknowledgement is itself ON the tamper-evident record.
            $this->assertNotNull($ack->audit_seq);
            $this->assertSame('chain.reconciled', DB::table('audit_log')->where('seq', $ack->audit_seq)->value('event'));
            $this->assertSame($operator->id, $ack->acknowledged_by_operator_id);
            $this->assertStringContainsString('append race', $ack->reason);
        });
    }

    public function test_a_misdescribed_acknowledgement_does_not_bless_a_break(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $audit = app(AuditService::class);

            $head = DB::table('audit_log')->orderByDesc('seq')->first(['seq', 'hash']);
            $r = $audit->append('test', 'probe.r', ['x' => 1]);
            $forkCanonical = AuditService::canonicalJson(['fork' => 2]);
            $forkHash = AuditService::chainHash((string) $head->hash, $forkCanonical);
            DB::insert(
                'INSERT INTO audit_log (occurred_at, module, event, payload, prev_hash, hash, rejected, created_at)
                 VALUES (now(), ?, ?, ?::jsonb, ?, ?, false, now())',
                ['test', 'probe.fork', $forkCanonical, (string) $head->hash, $forkHash],
            );
            $forkSeq = (int) DB::table('audit_log')->where('hash', $forkHash)->value('seq');

            // An acknowledgement pointing at the WRONG parent must not ground the break.
            AuditChainReconciliation::create([
                'break_seq' => $forkSeq,
                'observed_prev_hash' => str_repeat('e', 64), // not the real prev_hash
                'expected_prev_hash' => (string) $r->hash,
                'reason' => 'bogus',
                'authority_kind' => AuditChainReconciliation::AUTHORITY_OPERATOR_COLLECTIVE,
                'acknowledged_at' => now(),
            ]);

            $this->assertSame($forkSeq, $audit->verifyChain((int) $r->seq), 'a mis-described acknowledgement blesses nothing');
        });
    }

    public function test_acknowledge_requires_a_reason_and_a_real_break(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $audit = app(AuditService::class);
            $recon = app(ChainReconciliationService::class);
            $operator = app(OperatorIdentityService::class)->register('recon2_'.Str::lower(Str::random(8)), 'correct horse battery');

            $clean = $audit->append('test', 'probe.clean', ['ok' => 1]); // chains cleanly

            try {
                $recon->acknowledge((int) $clean->seq, 'x', AuditChainReconciliation::AUTHORITY_OPERATOR_COLLECTIVE, $operator);
                $this->fail('a cleanly-chaining seq is not reconcilable');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('nothing to reconcile', $e->getMessage());
            }
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
