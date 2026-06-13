<?php

namespace Tests\Constitutional;

use App\Services\ConstitutionalValidator;
use App\Services\Judiciary\PanelSizing;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. IV §4 (the panel-sizing core). "The number of
 * Judges sat to a case should be at least three (3), Odd in number, and scale
 * with the severity of the controversies, offenses, and punishments.
 * Constitutional Questions of significant importance are heard by the entire
 * court handing such matters."
 *
 * PanelSizing::sizeFor is a DB-free total pure function precisely so this suite
 * can pin it exhaustively. Properties pinned:
 *   - every output is odd and ≥ 3;
 *   - constitutional_major ⇒ en_banc = true and size = the whole (forced-odd)
 *     court;
 *   - severity is monotonic non-decreasing in panel size;
 *   - the function never exceeds the seated pool.
 *
 * Deliberately DB-free (the RightsAutomaticTest posture). If an edit to
 * PanelSizing breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class PanelSizingTest extends TestCase
{
    private const SEVERITIES = ['minor', 'moderate', 'serious', 'constitutional_major'];

    /**
     * Every output, across every severity and every seated-pool size from the
     * constitutional minimum (5 judges, Art. IV §1) up, is ODD and ≥ 3.
     */
    public function test_every_panel_is_odd_and_at_least_three(): void
    {
        foreach (range(5, 41) as $seated) {
            foreach (self::SEVERITIES as $severity) {
                ['size' => $size, 'en_banc' => $enBanc] = PanelSizing::sizeFor($severity, $seated);

                $this->assertGreaterThanOrEqual(3, $size, "f({$severity}, {$seated}) ≥ 3 (Art. IV §4).");
                $this->assertSame(1, $size % 2, "f({$severity}, {$seated}) is ODD (Art. IV §4).");
                $this->assertLessThanOrEqual($seated, $size, "f({$severity}, {$seated}) never exceeds the seated pool.");

                // The hardened validator re-assert agrees byte-for-byte.
                ConstitutionalValidator::assertPanelSize($size, $enBanc, $severity, $seated);
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The minimal lawful ladder (design §F deferral 5): minor = moderate = 3,
     * serious = 5 on a court large enough — clamped to the seated pool (forced
     * odd) on a smaller bench.
     */
    public function test_the_severity_ladder_is_the_minimal_lawful_ladder(): void
    {
        // A court of 5 (the constitutional floor) — minor/moderate seat 3,
        // serious seats 5.
        $this->assertSame(['size' => 3, 'en_banc' => false], PanelSizing::sizeFor('minor', 5));
        $this->assertSame(['size' => 3, 'en_banc' => false], PanelSizing::sizeFor('moderate', 5));
        $this->assertSame(['size' => 5, 'en_banc' => false], PanelSizing::sizeFor('serious', 5));

        // A larger court (9) — the ladder does not grow beyond 3/5 for the
        // non-constitutional tiers (severity scales, but minimally).
        $this->assertSame(['size' => 3, 'en_banc' => false], PanelSizing::sizeFor('minor', 9));
        $this->assertSame(['size' => 5, 'en_banc' => false], PanelSizing::sizeFor('serious', 9));

        // A serious case on a 3-judge court clamps to 3 (never below the floor).
        $this->assertSame(['size' => 3, 'en_banc' => false], PanelSizing::sizeFor('serious', 3));
        // …and on a 4-judge court forces odd downward to 3.
        $this->assertSame(['size' => 3, 'en_banc' => false], PanelSizing::sizeFor('serious', 4));
    }

    /**
     * Art. IV §4 — a Constitutional Question of significant importance is heard
     * by the ENTIRE court (en banc), forced odd (an even bench drops the lowest
     * draw so there are no ties).
     */
    public function test_constitutional_major_seats_the_whole_court_en_banc(): void
    {
        // Odd court → the whole court.
        $this->assertSame(['size' => 7, 'en_banc' => true], PanelSizing::sizeFor('constitutional_major', 7));
        $this->assertSame(['size' => 41, 'en_banc' => true], PanelSizing::sizeFor('constitutional_major', 41));

        // Even court → forced odd downward (the lowest draw drops).
        $this->assertSame(['size' => 7, 'en_banc' => true], PanelSizing::sizeFor('constitutional_major', 8));
        $this->assertSame(['size' => 5, 'en_banc' => true], PanelSizing::sizeFor('constitutional_major', 6));

        // Never below the floor even on a tiny court.
        $this->assertSame(['size' => 3, 'en_banc' => true], PanelSizing::sizeFor('constitutional_major', 3));
        $this->assertSame(['size' => 3, 'en_banc' => true], PanelSizing::sizeFor('constitutional_major', 4));

        // en_banc is true ONLY for constitutional_major.
        foreach (['minor', 'moderate', 'serious'] as $severity) {
            foreach (range(3, 20) as $seated) {
                $this->assertFalse(
                    PanelSizing::sizeFor($severity, $seated)['en_banc'],
                    "Only constitutional_major is en banc (got en_banc for {$severity}, {$seated})."
                );
            }
        }
    }

    /**
     * Severity is MONOTONIC non-decreasing in panel size: at any fixed seated
     * pool, minor ≤ moderate ≤ serious ≤ constitutional_major.
     */
    public function test_panel_size_is_monotonic_in_severity(): void
    {
        foreach (range(5, 41) as $seated) {
            $minor = PanelSizing::sizeFor('minor', $seated)['size'];
            $moderate = PanelSizing::sizeFor('moderate', $seated)['size'];
            $serious = PanelSizing::sizeFor('serious', $seated)['size'];
            $major = PanelSizing::sizeFor('constitutional_major', $seated)['size'];

            $this->assertLessThanOrEqual($moderate, $minor, "minor ≤ moderate at {$seated}.");
            $this->assertLessThanOrEqual($serious, $moderate, "moderate ≤ serious at {$seated}.");
            $this->assertLessThanOrEqual($major, $serious, "serious ≤ constitutional_major at {$seated}.");
        }
    }

    /**
     * The hardened validator re-assert (Art. IV §4): a below-floor, even, or
     * over-pool panel is rejected; a constitutional_major panel that is NOT
     * en banc is rejected.
     */
    public function test_validator_rejects_unconstitutional_panels(): void
    {
        $this->expectViolation('Art. IV §4', fn () => ConstitutionalValidator::assertPanelSize(2, false, 'minor', 5));   // below floor
        $this->expectViolation('Art. IV §4', fn () => ConstitutionalValidator::assertPanelSize(4, false, 'minor', 5));   // even
        $this->expectViolation('Art. IV §4', fn () => ConstitutionalValidator::assertPanelSize(7, false, 'minor', 5));   // over pool
        $this->expectViolation('Art. IV §4', fn () => ConstitutionalValidator::assertPanelSize(5, false, 'constitutional_major', 7)); // not en banc

        // A well-formed panel passes silently.
        ConstitutionalValidator::assertPanelSize(3, false, 'minor', 5);
        ConstitutionalValidator::assertPanelSize(5, false, 'serious', 5);
        ConstitutionalValidator::assertPanelSize(7, true, 'constitutional_major', 7);
        $this->addToAssertionCount(3);
    }

    private function expectViolation(string $citationFragment, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected a ConstitutionalViolation citing {$citationFragment}, none thrown.");
        } catch (\App\Domain\Engine\ConstitutionalViolation $e) {
            $this->assertStringContainsString($citationFragment, $e->citation ?? $e->getMessage());
        }
    }
}
