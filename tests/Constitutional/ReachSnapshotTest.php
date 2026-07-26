<?php

namespace Tests\Constitutional;

use App\Services\LegitimacyService as Reach;
use PHPUnit\Framework\TestCase;

/**
 * Phase I — pins on REACH (enrolment) and its k-anonymity rails.
 *
 * DB-free by design, matching ActivationMathTest's posture: the honest-state
 * ladder and the suppression rules are pure functions precisely so they can be
 * pinned without a schema.
 *
 * If an edit breaks these, the edit is the constitutional violation —
 * fix the edit, not the test.
 */
class ReachSnapshotTest extends TestCase
{
    /**
     * An unmeasured place is NEVER rendered as 0%. "We do not know how many
     * people live here" and "nobody lives here" are different claims, and
     * collapsing them slanders the place.
     */
    public function test_no_population_estimate_is_unmeasurable_not_zero(): void
    {
        $r = Reach::reachRatio(12, null);

        $this->assertSame(Reach::STATE_UNMEASURABLE, $r['state']);
        $this->assertNull($r['ratio_micro']);
        $this->assertNotSame(0, $r['ratio_micro']);
    }

    /** Below the floor: no number, no curve — publishing either could point at a person. */
    public function test_sub_k_counts_are_suppressed_on_the_write_side(): void
    {
        foreach ([1, 2, 3, 4] as $verified) {
            $r = Reach::reachRatio($verified, 1_000);

            $this->assertSame(Reach::STATE_ACTIVATING, $r['state'], "count {$verified} leaked");
            $this->assertNull($r['verified'], "count {$verified} reached storage");
            $this->assertNull($r['ratio_micro'], "count {$verified} leaked a ratio");
        }
    }

    /** At and above the floor a real ratio publishes, in exact millionths. */
    public function test_measured_reach_is_exact_millionths(): void
    {
        $r = Reach::reachRatio(5, 1_000);

        $this->assertSame(Reach::STATE_MEASURED, $r['state']);
        $this->assertSame(5_000, $r['ratio_micro']);   // 0.5% of a thousand
        $this->assertSame(5, $r['verified']);
    }

    /**
     * More verified residents than the estimate admits means the ESTIMATE lags
     * the place. Report it as capped and disclose the figure — never clamp
     * silently, which would hide a real data problem behind a tidy 100%.
     */
    public function test_over_unity_reach_is_capped_and_disclosed(): void
    {
        $r = Reach::reachRatio(150, 100);

        $this->assertSame(Reach::STATE_CAPPED, $r['state']);
        $this->assertGreaterThan(Reach::MICRO, $r['ratio_micro']);
        $this->assertSame(150, $r['verified']);
    }

    /**
     * THE DIFFERENCING ATTACK. The residency ancestor sweep makes
     * verified(parent) = Σ verified(children) EXACTLY, so exactly one
     * suppressed child is recovered as parent − Σ(published siblings).
     * The parent must therefore not publish.
     */
    public function test_a_single_sensitive_child_blocks_the_parent(): void
    {
        // children: 40, 12, and one suppressed 3 → 3 is recoverable by subtraction
        $this->assertFalse(Reach::mayPublishParent([40, 12, 3]));
    }

    /** Two sensitive children summing to >= k cannot be pinned on either place. */
    public function test_two_sensitive_children_summing_over_the_floor_unblock_the_parent(): void
    {
        $this->assertTrue(Reach::mayPublishParent([40, 3, 4]));   // 3+4 = 7 >= 5
    }

    /** Two sensitive children summing BELOW the floor are still too revealing. */
    public function test_two_tiny_children_below_the_floor_still_block(): void
    {
        $this->assertFalse(Reach::mayPublishParent([40, 1, 1]));  // 1+1 = 2 < 5
    }

    /**
     * A child with zero residents is NOT sensitive — "nobody lives here"
     * points at nobody, and treating it as sensitive would suppress most of
     * the planet for no privacy gain.
     */
    public function test_empty_children_are_not_sensitive(): void
    {
        $this->assertTrue(Reach::mayPublishParent([40, 0, 0, 0]));
    }

    /** No children, or all children safely above the floor: nothing to recover. */
    public function test_parents_with_no_sensitive_children_publish(): void
    {
        $this->assertTrue(Reach::mayPublishParent([]));
        $this->assertTrue(Reach::mayPublishParent([10, 20, 30]));
    }

    /**
     * A suppressed parent publishes NO count even when its own total is large —
     * the block comes from what the number would reveal about its children,
     * not from its own magnitude.
     */
    public function test_a_blocked_parent_publishes_no_count_despite_a_large_total(): void
    {
        $r = Reach::reachRatio(5_000, 100_000, suppressed: true);

        $this->assertSame(Reach::STATE_ACTIVATING, $r['state']);
        $this->assertNull($r['verified']);
        $this->assertNull($r['ratio_micro']);
    }
}
