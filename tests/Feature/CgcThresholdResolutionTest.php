<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CLK-13/14 thresholds are AMENDABLE (worker_rep_min_employees /
 * worker_rep_parity_employees, Art. III §6). A surface that renders the
 * Template defaults as constants shows a world its constitution instead of
 * its law — a jurisdiction that lawfully amended the first-seat threshold
 * would watch the CGC page contradict its own co-determination engine.
 *
 * CgcController carried exactly that: `['min' => 100, 'parity' => 2000]`
 * hardcoded, with a comment admitting it (Wave 2 item 6c). This pin keeps
 * the fix in place: the controller resolves the settings the same way the
 * engine does at evaluation time, and the literal pair must never return.
 */
class CgcThresholdResolutionTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(app_path('Http/Controllers/Organizations/CgcController.php'));
    }

    public function test_the_cgc_surface_resolves_the_amendable_thresholds(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            "resolveInt(\$jurisdictionId, 'worker_rep_min_employees', 100)",
            $source,
            'the first-seat threshold must be RESOLVED for the org\'s jurisdiction (100 is only the fallback)'
        );

        $this->assertStringContainsString(
            "resolveInt(\$jurisdictionId, 'worker_rep_parity_employees', 2000)",
            $source,
            'the parity threshold must be RESOLVED for the org\'s jurisdiction (2000 is only the fallback)'
        );
    }

    public function test_the_literal_threshold_pair_never_returns(): void
    {
        $this->assertStringNotContainsString(
            "'thresholds' => ['min' => 100, 'parity' => 2000]",
            $this->source(),
            'the hardcoded Template defaults must not come back — the values legislate'
        );
    }

    /** The next-step projection must honor the same resolved values. */
    public function test_the_next_step_projection_uses_the_resolved_values(): void
    {
        $this->assertStringContainsString(
            'nextStep($workerSeats, $ownerSeats, $min, $par)',
            $this->source(),
            'nextStep called without the resolved thresholds silently projects against the defaults'
        );
    }
}
