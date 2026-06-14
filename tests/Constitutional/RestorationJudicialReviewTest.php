<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\RestorationEvent;
use App\Services\Jurisdictions\RestorationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. VI §2–3 (Restoration). A restoration condition is
 * activated only on a JUDICIAL constitutional finding (no unilateral declaration),
 * and the three-tier cascade runs strictly in order (constituents → encompassing
 * → individuals). A tier cannot be skipped or reversed.
 *
 * If an edit breaks this test, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class RestorationJudicialReviewTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_restoration';

    public function test_restoration_needs_judicial_confirmation_and_ordered_tiers(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $svc = app(RestorationService::class);
            $jurisdictionId = (string) DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
            if ($jurisdictionId === '') {
                $this->markTestSkipped('Live DB needs a jurisdiction.');
            }

            // ── No tied case → confirmation refused (no unilateral activation) ─
            $declared = $svc->declare($jurisdictionId, RestorationEvent::CONDITION_CAPTURED);
            $this->assertSame(RestorationEvent::STATUS_DECLARED, $declared->status);
            $this->expectViolation(fn () => $svc->confirm($declared->refresh(), true), 'Art. VI §2');

            // ── Tied case, but no judicial finding → refused ─────────────────
            $event = $svc->declare($jurisdictionId, RestorationEvent::CONDITION_DESTROYED, ['evidence' => 'demo'], (string) Str::uuid());
            $this->expectViolation(fn () => $svc->confirm($event->refresh(), false), 'Art. VI §2');

            // ── A judicial finding confirms ──────────────────────────────────
            $confirmed = $svc->confirm($event->refresh(), true);
            $this->assertSame(RestorationEvent::STATUS_CONFIRMED, $confirmed->status);
            $this->assertTrue($confirmed->judicially_confirmed);

            // ── Tiers run in order: tier 2 cannot precede tier 1 ─────────────
            $this->expectViolation(fn () => $svc->advanceTier($confirmed->refresh(), 2), 'Art. VI §3');
            $t1 = $svc->advanceTier($confirmed->refresh(), 1);
            $this->assertSame(1, (int) $t1->tier);
            $t2 = $svc->advanceTier($t1->refresh(), 2);
            $this->assertSame(2, (int) $t2->tier);
            // ── A tier cannot be reversed ────────────────────────────────────
            $this->expectViolation(fn () => $svc->advanceTier($t2->refresh(), 1), 'Art. VI §3');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    private function expectViolation(callable $fn, string $citation): void
    {
        try {
            $fn();
            $this->fail('Expected a ConstitutionalViolation.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame($citation, $e->citation);
        }
    }
}
