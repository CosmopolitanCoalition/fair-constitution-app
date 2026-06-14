<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G2). A read-only MIRROR is authoritative for
 * NOTHING: `ConstitutionalEngine::file()` refuses EVERY constitutional filing on
 * a mirror — HTTP, queue, or clock — with a ConstitutionalViolation, and records
 * the refusal as a rejected edge on the mirror's OWN local chain. (Mirrored host
 * records live in `public_records` with `audit_seq=null`, NEVER in `audit_log`, so
 * the rejected edge cannot fork the replicated chain.) The write surface is
 * indistinguishable from absent.
 *
 * If an edit breaks this, the edit is the violation — fix the edit, not the test.
 *
 * Live-pg posture: guarded connection set as default, one rolled-back txn.
 */
class MirrorIsAuthoritativeForNothingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_mirror_guard';

    public function test_a_mirror_refuses_every_filing_and_records_the_refusal(): void
    {
        $this->onLivePg(function () {
            $engine = app(ConstitutionalEngine::class);

            // Become a read-only mirror.
            $settings = InstanceSettings::current();
            $settings->mirror_of_server_id = (string) Str::uuid();
            $settings->mirror_adopted_at = now();
            $settings->save();
            $this->assertTrue(InstanceSettings::current()->isMirror());

            $before = (int) DB::table('audit_log')->max('seq');

            try {
                $engine->file('F-LEG-003', null, ['note' => 'a mirror must refuse this']);
                $this->fail('a mirror must refuse a constitutional filing');
            } catch (ConstitutionalViolation $e) {
                $this->assertStringContainsStringIgnoringCase('mirror', $e->getMessage());
            }

            // The refusal is recorded as a rejected edge on the mirror's own chain.
            $rejected = DB::table('audit_log')->where('seq', '>', $before)->orderByDesc('seq')->first();
            $this->assertNotNull($rejected, 'the refusal is chained on the mirror');
            $this->assertTrue((bool) $rejected->rejected, 'the edge is rejected=true');
            $this->assertStringContainsStringIgnoringCase('mirror', (string) $rejected->blocked_reason);
        });
    }

    public function test_a_normal_instance_is_never_blocked_by_the_mirror_guard(): void
    {
        $this->onLivePg(function () {
            $engine = app(ConstitutionalEngine::class);

            // A normal (non-mirror) instance: the SAME call is gated only by
            // ordinary validation — never by the mirror guard.
            $this->assertFalse(InstanceSettings::current()->isMirror());

            try {
                $engine->file('F-LEG-003', null, ['note' => 'missing required fields']);
                // Reaching here means the guard certainly did not fire.
            } catch (\Throwable $e) {
                if ($e instanceof ConstitutionalViolation) {
                    $this->assertStringNotContainsStringIgnoringCase(
                        'mirror', $e->getMessage(),
                        'a normal instance is never blocked by the mirror guard'
                    );
                }
                // Any non-mirror failure proves the mirror guard did not gate it.
            }

            $this->assertTrue(true);
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
