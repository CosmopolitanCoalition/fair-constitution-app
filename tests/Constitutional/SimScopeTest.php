<?php

namespace Tests\Constitutional;

use App\Models\SimRun;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — dependency-aware sim scope (operator 2026-09-06).
 *
 * The operator's rule: "choosing not to simulate something that is required for
 * another thing I do want to simulate should be impossible." The scope closure
 * enforces exactly that — a chosen aspect always pulls in its prerequisites, and
 * the pump only ever advances to an in-scope phase.
 */
class SimScopeTest extends TestCase
{
    private function mk(?array $aspects): SimRun
    {
        $r = new SimRun();
        $r->forceFill(['options' => $aspects === null ? [] : ['scope_aspects' => $aspects], 'phase' => 'cohorts']);

        return $r;
    }

    public function test_no_scope_runs_everything(): void
    {
        $active = $this->mk(null)->activePhases();

        foreach (['cohorts', 'identities', 'elections', 'counting', 'seating',
                  'governance', 'judiciary', 'civics', 'training', 'stipends', 'done'] as $phase) {
            $this->assertContains($phase, $active, "full scope runs {$phase}");
        }
    }

    public function test_money_pulls_in_seating_but_not_governance(): void
    {
        $active = $this->mk(['money'])->activePhases();

        // base + elections (prerequisite) + money.
        $this->assertContains('identities', $active); // base — wallets
        $this->assertContains('seating', $active);    // elections prereq (role bumps)
        $this->assertContains('stipends', $active);   // money

        // Not chosen and not required.
        $this->assertNotContains('governance', $active);
        $this->assertNotContains('judiciary', $active);
        $this->assertNotContains('civics', $active);
        $this->assertNotContains('training', $active);
    }

    public function test_civic_life_pulls_in_governance_and_elections(): void
    {
        $active = $this->mk(['civic_life'])->activePhases();

        // The transitive closure: civic_life → governance → elections → base.
        foreach (['seating', 'governance', 'judiciary', 'civics'] as $phase) {
            $this->assertContains($phase, $active, "civic_life requires {$phase}");
        }
        $this->assertNotContains('training', $active);
        $this->assertNotContains('stipends', $active);
    }

    public function test_elections_alone_stops_at_seating(): void
    {
        $active = $this->mk(['elections'])->activePhases();

        $this->assertContains('seating', $active);
        foreach (['governance', 'judiciary', 'civics', 'training', 'stipends'] as $phase) {
            $this->assertNotContains($phase, $active, "elections-only excludes {$phase}");
        }
    }

    public function test_the_pump_skips_out_of_scope_phases(): void
    {
        // With money scope, after seating the next in-scope phase is stipends —
        // governance / judiciary / civics / training are skipped.
        $r = $this->mk(['money']);
        $r->forceFill(['phase' => 'seating']);
        $this->assertSame('stipends', $r->nextActivePhase());

        // After stipends, nothing more is in scope → done via verifying.
        $r->forceFill(['phase' => 'stipends']);
        $this->assertContains($r->nextActivePhase(), ['verifying', 'done']);
    }
}
