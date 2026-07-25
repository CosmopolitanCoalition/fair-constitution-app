<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Services\ConstitutionalValidator;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the hardened bounds registry
 * (ConstitutionalValidator::SETTING_BOUNDS) and the F-LEG-031 setting-change
 * check: in-range values pass, out-of-range and non-amendable keys are
 * rejected PRE-COMMIT with their constitutional citation.
 *
 * DB-free (established posture) — checkSettingChange is pure validation.
 * The rejected=true chain entry for engine rejections is exercised by the
 * live-stack audit verification, not here.
 *
 * If an edit to ConstitutionalValidator breaks these tests, that edit is a
 * constitutional violation — fix the edit, never the test.
 */
class SettingsBoundsTest extends TestCase
{
    private ConstitutionalValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConstitutionalValidator();
    }

    private function change(string $key, mixed $value, array $extra = []): void
    {
        $this->validator->checkSettingChange(array_merge([
            'setting_key' => $key,
            'value'       => $value,
        ], $extra));
    }

    // ─── Registry shape ──────────────────────────────────────────────────

    public function test_every_amendable_setting_from_the_constitution_is_bounded(): void
    {
        // The CLAUDE.md amendable-settings table, pinned. Growing this list
        // requires constitutional review; shrinking it never happens.
        $expected = [
            'election_interval_months',
            'voting_method',
            'special_election_min_days',
            'special_election_max_days',
            'legislature_min_seats',
            'legislature_max_seats',
            'legislature_sizing_law',
            'supermajority_numerator',
            'supermajority_denominator',
            'max_days_between_meetings',
            'emergency_powers_max_days',
            'civil_appointment_years',
            'judicial_appointment_years',
            'judiciary_min_judges_per_race',
            'judiciary_is_elected',
            'worker_rep_min_employees',
            'worker_rep_parity_employees',
            'residency_confirmation_days',
            'initiative_petition_threshold_pct',
            // Phase B (WI-B4) — open-ballot phase settings (migration B-12):
            'finalist_multiplier',
            'ranked_window_days',
            'approval_min_days',
            // Phase L (slice L-1) — the monetary levers, added under
            // constitutional review per this test's own instruction.
            // Rationale: the nine economy columns setup-wizard-v2 shipped were
            // collected at founding and then unreachable — absent from these
            // bounds, so checkSettingChange rejected any F-LEG-031 bill
            // targeting them; absent from DUAL_DOOR_KEYS; and absent from the
            // settings register, so no screen showed them after setup. A
            // founder authored a currency and a stipend that no legislature
            // could ever amend. These entries make monetary policy a
            // legislative lever, which is what Art. V §5 requires of it.
            // All are [POLICY] — the Template mandates no stipend and sets no
            // rate; the citations record the clause that AUTHORISES the lever
            // (Art. II §9 Treasury, Art. V §5 currency), never one that
            // requires the payment.
            'stipend_enabled',
            'stipend_funding_source',
            'civic_stipend_floor',
            'stipend_bump_cap',
            'pay_node_operator',
            'pay_social_moderator',
            'pay_office_holder',
            'stipend_interval',
            'stipend_period_days',
            'issuance_rate_bps',
            'inflation_target_bps',
        ];

        $this->assertSame($expected, array_keys(ConstitutionalValidator::SETTING_BOUNDS));
    }

    public function test_every_bound_carries_its_constitutional_citation(): void
    {
        foreach (ConstitutionalValidator::SETTING_BOUNDS as $key => $bounds) {
            $this->assertArrayHasKey('citation', $bounds, "{$key} has no citation");
            $this->assertNotSame('', $bounds['citation'], "{$key} has an empty citation");
            $this->assertTrue(
                isset($bounds['allowed']) || (isset($bounds['min']) && isset($bounds['max'])),
                "{$key} has neither a whitelist nor min/max bounds"
            );
        }
    }

    // ─── In-range accepted ───────────────────────────────────────────────

    public function test_in_range_values_are_accepted(): void
    {
        $this->change('residency_confirmation_days', 30);
        $this->change('election_interval_months', 60);
        $this->change('legislature_min_seats', 5);
        $this->change('legislature_max_seats', 9);
        $this->change('emergency_powers_max_days', 90);
        $this->change('max_days_between_meetings', 90);
        $this->change('voting_method', 'stv_droop');
        // judiciary_is_elected is a DUAL_DOOR_KEYS setting (Art. IV §3 — Phase E
        // lands the constituent-consent gate the validator reserved): it passes
        // the bounds check only WITH the constituent-consent flag (Door 2a).
        $this->change('judiciary_is_elected', false, ['requires_constituent_consent' => true]);
        $this->change('initiative_petition_threshold_pct', 5.00);
        // Phase B defaults (B-12) are in range by construction.
        $this->change('finalist_multiplier', 3);
        $this->change('ranked_window_days', 14);
        $this->change('approval_min_days', 30);

        $this->expectNotToPerformAssertions();
    }

    public function test_phase_b_election_settings_are_bounded(): void
    {
        foreach ([
            ['finalist_multiplier', 0],   // X must be ≥ seats (multiplier ≥ 1)
            ['finalist_multiplier', 11],
            ['ranked_window_days', 0],
            ['ranked_window_days', 61],
            ['approval_min_days', 0],
            ['approval_min_days', 366],
        ] as [$key, $value]) {
            try {
                $this->change($key, $value);
                $this->fail("{$key} = {$value} must be rejected");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §2 · as implemented', $e->citation);
            }
        }
    }

    // ─── Hardened ceilings/floors rejected with citation ─────────────────

    public function test_legislature_seat_band_is_hardened_at_5_and_9(): void
    {
        foreach ([
            ['legislature_min_seats', 4],   // below the constitutional floor
            ['legislature_min_seats', 10],
            ['legislature_max_seats', 10],  // above the constitutional ceiling
            ['legislature_max_seats', 4],
        ] as [$key, $value]) {
            try {
                $this->change($key, $value);
                $this->fail("{$key} = {$value} must be rejected (Art. II §2)");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §2', $e->citation);
            }
        }
    }

    public function test_emergency_powers_ceiling_is_90_days(): void
    {
        try {
            $this->change('emergency_powers_max_days', 91);
            $this->fail('91 emergency days must be rejected (Art. II §7)');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §7', $e->citation);
        }
    }

    public function test_meeting_interval_ceiling_is_90_days(): void
    {
        $this->expectException(ConstitutionalViolation::class);
        $this->change('max_days_between_meetings', 91);
    }

    public function test_voting_method_ratchet_rejects_less_proportional_methods(): void
    {
        foreach (['fptp', 'plurality', 'borda', ''] as $method) {
            try {
                $this->change('voting_method', $method);
                $this->fail("voting_method '{$method}' must be rejected (Art. II §2)");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §2', $e->citation);
            }
        }
    }

    // ─── Phase L — the monetary levers ───────────────────────────────────

    /**
     * Art. V §5 · the anti-self-dealing rail. Every monetary lever is
     * dual-door: the people who receive a stipend overlap the legislators who
     * set it, so Door 1 alone would let a chamber vote itself a raise.
     */
    public function test_every_monetary_lever_is_dual_door(): void
    {
        foreach (ConstitutionalValidator::MONETARY_KEYS as $key) {
            $this->assertContains(
                $key,
                ConstitutionalValidator::DUAL_DOOR_KEYS,
                "{$key} is a monetary lever and must require constituent consent"
            );
            $this->assertArrayHasKey(
                $key,
                ConstitutionalValidator::SETTING_BOUNDS,
                "{$key} must be bounded, or checkSettingChange rejects it as non-amendable"
            );
        }
    }

    public function test_a_monetary_lever_cannot_be_amended_by_one_chamber_alone(): void
    {
        try {
            $this->change('civic_stipend_floor', 75);
            $this->fail('a monetary lever must not pass through Door 1 alone (Art. IV §3)');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. IV §3', $e->citation);
        }

        // With the constituent door flagged, the same change is lawful.
        $this->change('civic_stipend_floor', 75, ['requires_constituent_consent' => true]);
    }

    /**
     * The cross-field rule the setup wizard never had: the field it labels
     * "max stacked" bounded nothing, so a founder could set a cap of 20 and a
     * per-role pay of 500.
     */
    public function test_a_role_differential_may_not_exceed_the_stipend_bump_cap(): void
    {
        $consent = ['requires_constituent_consent' => true];

        // Raising one role above the cap is rejected.
        try {
            $this->change('pay_office_holder', 500, $consent + ['stipend_bump_cap' => 20]);
            $this->fail('a role differential above the cap must be rejected');
        } catch (ConstitutionalViolation $e) {
            $this->assertStringContainsString('stipend bump cap', $e->getMessage());
        }

        // Lowering the cap below a standing differential is rejected from the
        // other side — the rule is symmetric, not a one-way check.
        try {
            $this->change('stipend_bump_cap', 3, $consent);
            $this->fail('a cap below a standing differential must be rejected');
        } catch (ConstitutionalViolation $e) {
            $this->assertStringContainsString('stipend bump cap', $e->getMessage());
        }

        // At or under the cap is lawful.
        $this->change('pay_office_holder', 20, $consent + ['stipend_bump_cap' => 20]);
        $this->change('stipend_bump_cap', 50, $consent);
    }

    public function test_monetary_bounds_reject_out_of_range_values(): void
    {
        $consent = ['requires_constituent_consent' => true];

        foreach ([
            ['civic_stipend_floor', -1],
            ['stipend_period_days', 0],
            ['stipend_period_days', 366],
            ['issuance_rate_bps', 10001],
            ['inflation_target_bps', -1],
        ] as [$key, $value]) {
            try {
                $this->change($key, $value, $consent);
                $this->fail("{$key} = {$value} must be out of range");
            } catch (ConstitutionalViolation $e) {
                $this->assertNotSame('', $e->citation);
            }
        }

        foreach ([
            ['stipend_funding_source', 'borrowed'],
            ['stipend_interval', 'hourly'],
        ] as [$key, $value]) {
            try {
                $this->change($key, $value, $consent);
                $this->fail("{$key} = {$value} must not be permitted");
            } catch (ConstitutionalViolation $e) {
                $this->assertNotSame('', $e->citation);
            }
        }

        // The two funding sources the design ships are both lawful.
        $this->change('stipend_funding_source', 'minted', $consent);
        $this->change('stipend_funding_source', 'treasury_draw', $consent);
    }

    /**
     * The currency's IDENTITY is not a lever — it changes through F-LEG-040
     * (root-only), never F-LEG-031. Free text has neither a whitelist nor a
     * numeric range, and every bounded key must carry one.
     */
    public function test_currency_identity_is_not_an_amendable_setting(): void
    {
        foreach (['currency_name', 'currency_code', 'currency_symbol'] as $key) {
            try {
                $this->change($key, 'Doubloon', ['requires_constituent_consent' => true]);
                $this->fail("{$key} must not be amendable through F-LEG-031");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. VII', $e->citation);
            }
        }
    }

    // ─── Non-amendable keys rejected outright ────────────────────────────

    public function test_unknown_or_hardened_keys_are_not_amendable(): void
    {
        foreach (['ballot_secrecy', 'cgc_intellectual_property', 'quorum_formula', 'nonsense_key'] as $key) {
            try {
                $this->change($key, 1);
                $this->fail("{$key} must not be amendable");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. VII', $e->citation);
            }
        }
    }

    public function test_missing_setting_key_is_rejected(): void
    {
        $this->expectException(ConstitutionalViolation::class);
        $this->validator->checkSettingChange(['value' => 30]);
    }

    public function test_non_numeric_value_for_numeric_bound_is_rejected(): void
    {
        $this->expectException(ConstitutionalViolation::class);
        $this->change('residency_confirmation_days', 'thirty');
    }

    // ─── Supermajority fraction guard (Art. VII) ─────────────────────────

    public function test_supermajority_fraction_must_exceed_one_half(): void
    {
        // 1/2 exactly: not a supermajority.
        try {
            $this->change('supermajority_numerator', 1, ['supermajority_denominator' => 2]);
            $this->fail('1/2 must be rejected (Art. VII)');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. VII', $e->citation);
        }

        // Greater than 1: impossible fraction.
        $this->expectException(ConstitutionalViolation::class);
        $this->change('supermajority_numerator', 4, ['supermajority_denominator' => 3]);
    }

    public function test_constitutional_two_thirds_fraction_is_accepted(): void
    {
        $this->change('supermajority_numerator', 2, ['supermajority_denominator' => 3]);
        $this->change('supermajority_denominator', 3, ['supermajority_numerator' => 2]);
        // 3/4 — stricter than 2/3 is permissible.
        $this->change('supermajority_numerator', 3, ['supermajority_denominator' => 4]);

        $this->expectNotToPerformAssertions();
    }
}
