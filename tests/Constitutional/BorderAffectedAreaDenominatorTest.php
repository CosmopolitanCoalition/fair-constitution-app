<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\BorderSettlement;
use App\Services\ConstitutionalValidator;
use App\Services\Jurisdictions\BorderSettlementService;
use App\Support\CivicPopulation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. V §2 (Border settlement). A boundary change is
 * adopted on a SUPERMAJORITY of the population IN THE AFFECTED AREA — the
 * denominator is CivicPopulation::forArea over the affected sub-jurisdictions
 * ONLY, never the whole civic population of either bordering jurisdiction. The
 * proof: a yes-count that is a supermajority of the (small) affected area — far
 * below a supermajority of the (large) whole jurisdiction — still adopts.
 *
 * If an edit breaks this test, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class BorderAffectedAreaDenominatorTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_border_area';

    public function test_the_referendum_denominator_is_the_affected_area_only(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $svc = app(BorderSettlementService::class);

            // A LARGE jurisdiction (the root — everyone is associated up to it).
            $whole = (string) DB::table('jurisdictions')->whereNull('deleted_at')->where('adm_level', 0)->value('id');
            $other = (string) DB::table('jurisdictions')->whereNull('deleted_at')->where('adm_level', '>', 0)->value('id');

            // A SMALL affected sub-jurisdiction: the fewest active residents.
            $affected = (string) DB::table('residency_confirmations')
                ->where('is_active', true)
                ->select('jurisdiction_id')
                ->groupBy('jurisdiction_id')
                ->orderByRaw('count(*) asc')
                ->value('jurisdiction_id');

            if ($whole === '' || $other === '' || $affected === '') {
                $this->markTestSkipped('Live DB needs a root jurisdiction, another jurisdiction, and a resident-bearing area.');
            }

            $affectedPop = CivicPopulation::forArea([$affected]);
            $wholePop = CivicPopulation::of($whole);
            if ($affectedPop < 1 || $wholePop <= $affectedPop) {
                $this->markTestSkipped('Need an affected area strictly smaller than the whole jurisdiction.');
            }

            $settlement = $svc->open($whole, $other, [$affected]);

            // The recorded denominator is the AFFECTED area, not the whole.
            $this->assertSame($affectedPop, (int) $settlement->affected_population, 'denominator = affected-area population');

            $affectedRequired = ConstitutionalValidator::supermajority($affectedPop);
            $wholeRequired = ConstitutionalValidator::supermajority($wholePop);
            $this->assertLessThan($wholeRequired, $affectedRequired, 'the affected-area bar is far below the whole-jurisdiction bar');

            // A yes-count one short of the affected-area supermajority is rejected.
            $svc->recordReferendum($settlement, $affectedRequired - 1);
            $this->expectViolation(fn () => $svc->adopt($settlement->refresh()));

            // A yes-count meeting the AFFECTED-AREA supermajority adopts —
            // even though it is nowhere near a supermajority of the whole.
            $reopened = $svc->open($whole, $other, [$affected]);
            $svc->recordReferendum($reopened, $affectedRequired);
            $adopted = $svc->adopt($reopened->refresh());
            $this->assertSame(BorderSettlement::STATUS_ADOPTED, $adopted->status);
            $this->assertNotNull($adopted->jurisdiction_map_id, 'adoption writes a new boundary map version');
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    private function expectViolation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a ConstitutionalViolation.');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. V §2', $e->citation);
        }
    }
}
