<?php

namespace Tests\Unit;

use App\Http\Controllers\LegislatureController;
use App\Services\DistrictingService;
use Tests\TestCase;

/**
 * THE COMPOSITE TRANSACTION SPLIT source pins (2026-09-02).
 *
 * The composite arm of executeMassReseedSweep opens no whole-draw
 * transaction. runAutoCompositeForScope runs Steps 1-8 with no transaction
 * open and wraps ONLY its write phase (Step 9 clears through Step 12
 * inserts and recompute) in one transaction it commits or rolls back
 * itself. The early residue plane wraps its own write the same way.
 *
 * Source pins, because the draw itself needs PostGIS: they read the two
 * method bodies through reflection and anchor on the code markers that the
 * engine already carries. If an edit moves a transaction back around the
 * planner, the edit is the defect.
 */
class CompositeTransactionSplitPinTest extends TestCase
{
    public function test_composite_arm_opens_no_whole_draw_transaction(): void
    {
        $arm = $this->slice(
            $this->methodSource(LegislatureController::class, 'executeMassReseedSweep'),
            '// Compute per-scope seat budget',
            'THE STALE-SCOPE PURGE'
        );

        $this->assertStringContainsString('runAutoCompositeForScope(', $arm);
        $this->assertStringNotContainsString('DB::beginTransaction()', $arm,
            'the composite arm holds no transaction around the draw: Steps 1-8 run at transaction level zero');
        $this->assertStringNotContainsString('DB::transaction(', $arm);
        $this->assertStringNotContainsString('DB::commit()', $arm);
    }

    public function test_write_phase_is_the_only_transaction_in_the_composite_draw(): void
    {
        $body = $this->methodSource(DistrictingService::class, 'runAutoCompositeForScope');

        $marker    = strpos($body, 'THE WRITE PHASE IS THE TRANSACTION');
        $step9     = strpos($body, '// ── Step 9:');
        // 'step12' is the whole Step 12 loop timer; it closes right before
        // DB::commit(). ('step12.geometry' names the PostGIS union+hull time
        // and lives inside recomputeDistrict, outside this body.)
        $step12End = strpos($body, "\$this->stepEnd('step12');");
        $this->assertNotFalse($marker);
        $this->assertNotFalse($step9);
        $this->assertNotFalse($step12End);
        $this->assertLessThan($step9, $marker, 'the transaction opens right before Step 9, the first write');
        $this->assertLessThan($step12End, $step9);

        $planner = substr($body, 0, $marker);
        $this->assertStringNotContainsString('DB::beginTransaction()', $planner,
            'Steps 1-8 open no transaction');
        $this->assertSame(1, substr_count($planner, 'DB::transaction('),
            'the early residue plane is the one write inside Steps 1-8 and wraps itself');
        $this->assertStringContainsString('insertZeroBudgetResidueDistrict(',
            substr($planner, strpos($planner, 'DB::transaction(')));

        $opening = substr($body, $marker, $step9 - $marker);
        $this->assertStringContainsString('DB::beginTransaction();', $opening);
        $this->assertStringContainsString('try {', $opening);

        $writePhase = substr($body, $step9, $step12End - $step9);
        $this->assertStringNotContainsString('DB::beginTransaction()', $writePhase,
            'one transaction for the whole write phase, no nested opens');
        $this->assertStringNotContainsString('DB::commit()', $writePhase);

        $closing = substr($body, $step12End, 400);
        $this->assertStringContainsString('DB::commit();', $closing);
        $this->assertStringContainsString('catch (\Throwable $e)', $closing);
        $this->assertStringContainsString('DB::rollBack();', $closing);
        $this->assertStringContainsString('throw $e;', $closing,
            'a failed write phase rolls back and rethrows: the lane never keeps an aborted transaction');
    }

    public function test_heartbeat_writes_ride_the_beat_connection(): void
    {
        $body = $this->methodSource(DistrictingService::class, 'publishMassProgress');
        $lane = $this->slice($body, 'AutoscaleContext::active()', 'THE CACHE IS NEVER LOAD-BEARING');

        $this->assertStringContainsString('DB::connection(self::BEAT_CONNECTION)', $lane);
        $this->assertStringNotContainsString("DB::table('apportionment_ledger_scopes')", $lane,
            'the scope beat never rides the default connection');
        $this->assertStringNotContainsString('DB::update(', $lane,
            'the lease beat never rides the default connection');
        $this->assertStringContainsString("->where('claim_token', \$token)", $lane,
            'the scope beat carries the claim-token guard');
        $this->assertStringContainsString('COALESCE(?::integer, pg_backend_pid)', $lane,
            'the lease keeps the WORK backend pid; the beat session\'s own pid is never recorded');
        $this->assertSame('pgsql_beat', DistrictingService::BEAT_CONNECTION);
    }

    private function methodSource(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);
        $lines = file($m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    private function slice(string $body, string $from, string $to): string
    {
        $start = strpos($body, $from);
        $end = strpos($body, $to);
        $this->assertNotFalse($start, "marker not found: {$from}");
        $this->assertNotFalse($end, "marker not found: {$to}");
        $this->assertLessThan($end, $start);

        return substr($body, $start, $end - $start);
    }
}
