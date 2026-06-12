<?php

namespace Tests\Constitutional;

use App\Models\Election;
use App\Services\Legislature\ElectionBoardTransitionService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WF-ELE-10 bootstrap-retirement rules (PHASE_C_DESIGN_
 * chamber_ops §E.2) DB-free:
 *
 *  - readiness = no unresolved nominations AND seated ≥ the configured
 *    minimum (no constitutional number exists — "as implemented");
 *  - custody immutability: FINAL and CANCELLED elections keep their
 *    historical bootstrap board id — provenance is immutable; everything
 *    in flight transfers.
 *
 * The live half (one-transaction flip, custody UPDATE, R-08 derivation
 * flip for the operator and the appointees) is exercised by the
 * Montegiardino tinker E2E — same posture as Phase B.
 */
class BoardTransitionTest extends TestCase
{
    public function test_readiness_requires_all_nominations_resolved_and_minimum_seated(): void
    {
        $this->assertTrue(ElectionBoardTransitionService::ready(0, 3, 3));
        $this->assertTrue(ElectionBoardTransitionService::ready(0, 5, 3));

        $this->assertFalse(ElectionBoardTransitionService::ready(1, 3, 3), 'a pending nomination blocks the flip');
        $this->assertFalse(ElectionBoardTransitionService::ready(0, 2, 3), 'below the minimum blocks the flip');
        $this->assertFalse(ElectionBoardTransitionService::ready(2, 0, 3));
    }

    public function test_custody_immutable_statuses_are_exactly_final_and_cancelled(): void
    {
        $this->assertSame(
            [Election::STATUS_FINAL, Election::STATUS_CANCELLED],
            ElectionBoardTransitionService::CUSTODY_IMMUTABLE_STATUSES,
            'certified history keeps its bootstrap provenance; everything else transfers'
        );
    }

    public function test_no_resurrection_api_exists_for_the_bootstrap_board(): void
    {
        // Honest-gap architecture pin: a proper board falling below the
        // minimum does NOT resurrect the bootstrap board — the service
        // surface exposes no method that could reactivate one.
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(ElectionBoardTransitionService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        sort($methods);

        $this->assertSame(
            ['__construct', 'maybeTransition', 'ready', 'transition'],
            $methods,
            'the transition is one-way: retire bootstrap, activate proper — nothing reverses it'
        );
    }
}
