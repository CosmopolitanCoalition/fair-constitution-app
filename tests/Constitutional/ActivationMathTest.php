<?php

namespace Tests\Constitutional;

use App\Services\ActivationService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — legislature sizing law (cube root, q-ledger #3) and
 * the bicameral trigger (Art. V §3) as implemented by the activation
 * engine (WI-7).
 *
 * Deliberately DB-free (established posture: the phpunit sqlite :memory:
 * connection has no schema and RefreshDatabase is forbidden on the live
 * dev DB). ActivationService's sizing math lives in pure statics
 * (cubeRootSeats / seatPlan / quorumRequired — Type B via TypeBSeatLadder)
 * precisely so this suite can pin it; the DB-touching pipeline is
 * exercised by the live-stack jurisdiction:activate verification.
 *
 * If an edit to ActivationService breaks these tests, that edit is a
 * constitutional violation — fix the edit, never the test.
 */
class ActivationMathTest extends TestCase
{
    // ─── Cube-root law ───────────────────────────────────────────────────

    public function test_cube_root_sizing_on_known_populations(): void
    {
        // round(population^(1/3))
        $this->assertSame(2000, ActivationService::cubeRootSeats(8_000_000_000)); // Earth-scale regression
        $this->assertSame(100, ActivationService::cubeRootSeats(1_000_000));
        $this->assertSame(10, ActivationService::cubeRootSeats(1_000));
        $this->assertSame(5, ActivationService::cubeRootSeats(125));

        // Live-stack demo fixtures (San Marino):
        $this->assertSame(32, ActivationService::cubeRootSeats(33_313)); // Σ castelli populations
        $this->assertSame(10, ActivationService::cubeRootSeats(1_002));  // Montegiardino (leaf own pop)
    }

    public function test_floor_of_five_seats_applies(): void
    {
        // Art. II §2 — never below 5 seats, however small the population.
        $this->assertSame(5, ActivationService::cubeRootSeats(1));
        $this->assertSame(5, ActivationService::cubeRootSeats(0));
        $this->assertSame(5, ActivationService::cubeRootSeats(64));   // ∛64 = 4 → floored
        $this->assertSame(5, ActivationService::cubeRootSeats(91));   // ∛91 ≈ 4.50 → rounds 4–5, floor guarantees 5

        // First population whose cube root rounds to 6 (5.5³ = 166.375).
        $this->assertSame(5, ActivationService::cubeRootSeats(166));
        $this->assertSame(6, ActivationService::cubeRootSeats(167));
    }

    public function test_rounding_is_round_half_up_not_truncation(): void
    {
        // 6.999…³ ≈ 342.6 — truncation would give 6, rounding gives 7.
        $this->assertSame(7, ActivationService::cubeRootSeats(343)); // 7³ exactly
        $this->assertSame(7, ActivationService::cubeRootSeats(300)); // ∛300 ≈ 6.69 → 7
        $this->assertSame(6, ActivationService::cubeRootSeats(250)); // ∛250 ≈ 6.30 → 6
    }

    // ─── Bicameral trigger (Art. V §3) + the Type B ladder (2026-07-19) ──

    public function test_seat_plan_with_constituents_is_bicameral_over_children_population(): void
    {
        // type_a from Σ children population, NOT own population. Type B from
        // the LADDER: San Marino's 9 castelli @5 = 45 > 32, @4 = 36 > 32,
        // @3 = 27 ≤ 32 → rep 3, Type B 27 — equal representation, bound by
        // the lawful legislature size (Type A).
        $castelli = [4_000, 3_500, 3_500, 4_500, 3_800, 3_600, 3_400, 3_500, 3_513];
        $plan = ActivationService::seatPlan(33_312.0, 33_313.0, $castelli);

        $this->assertTrue($plan['bicameral']);
        $this->assertSame(32, $plan['type_a']); // ∛33313 ≈ 32.17 → 32
        $this->assertSame(27, $plan['type_b']); // the ladder settles at 3 per castello
        $this->assertSame(3, $plan['type_b_rep_floor']);
        $this->assertFalse($plan['type_b_needs_districting']);
    }

    public function test_seat_plan_leaf_falls_back_to_own_population_unicameral(): void
    {
        $plan = ActivationService::seatPlan(1_002.0, 0.0, []);

        $this->assertFalse($plan['bicameral']);
        $this->assertSame(10, $plan['type_a']); // ∛1002 ≈ 10.01 → 10 — UNCLAMPED
        $this->assertSame(0, $plan['type_b']);  // unicameral — no constituents
    }

    public function test_seat_plan_leaf_floor(): void
    {
        // Tiny leaf still seats a 5-member chamber (Art. II §2 floor).
        $plan = ActivationService::seatPlan(12.0, 0.0, []);

        $this->assertSame(5, $plan['type_a']);
        $this->assertSame(0, $plan['type_b']);
        $this->assertFalse($plan['bicameral']);
    }

    public function test_seat_plan_never_mixes_chambers(): void
    {
        // Constituents present ⇒ type_b > 0 (both kinds required, Art. V §3);
        // absent ⇒ type_b == 0. There is no third configuration.
        foreach ([0, 1, 2, 9, 50, 250] as $children) {
            $pops = array_fill(0, $children, 20_000);
            $plan = ActivationService::seatPlan(1_000_000.0, 1_000_000.0, $pops);

            $this->assertSame($children > 0, $plan['bicameral']);
            $this->assertSame($children > 0, $plan['type_b'] > 0);
            $this->assertGreaterThanOrEqual(5, $plan['type_a']);
        }
    }

    // ─── Quorum at instantiation ─────────────────────────────────────────

    public function test_quorum_required_matches_seeding_path(): void
    {
        // max(3, ceil(total/2)) — identical to ApportionmentSeedCommand.
        $this->assertSame(3, ActivationService::quorumRequired(5));
        $this->assertSame(3, ActivationService::quorumRequired(6));
        $this->assertSame(4, ActivationService::quorumRequired(7));
        $this->assertSame(5, ActivationService::quorumRequired(9));
        $this->assertSame(30, ActivationService::quorumRequired(59)); // San Marino: 32 + 27 (the ladder)
        $this->assertSame(3, ActivationService::quorumRequired(1));   // floor of 3
    }
}
