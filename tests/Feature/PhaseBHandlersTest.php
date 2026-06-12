<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\FormRegistry;
use App\Domain\Forms\Handlers\BallotSubmission;
use App\Domain\Forms\Handlers\CandidacyWithdrawal;
use App\Domain\Forms\Handlers\ElectionSchedulingOrder;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * WI-B4 — the 11 Phase B engine handlers (PHASE_B_DESIGN_schema_lifecycle
 * §C): registry wiring + the PURE validation guards.
 *
 * Deliberately DB-free (established posture — the phpunit sqlite :memory:
 * connection has no schema and RefreshDatabase is forbidden on the live
 * dev DB). The handlers expose their date/shape guards as pure statics
 * precisely so this suite can pin them; the DB-backed paths (the three
 * signature rejections against fabricated rows, role-derivation flips,
 * rejected chain entries with citations) are exercised by the live-stack
 * tinker verification, same as Phase A.
 */
class PhaseBHandlersTest extends TestCase
{
    /** The §C handler map — 11 forms, pinned. */
    private const PHASE_B_FORMS = [
        'F-ELB-001', // Election Scheduling Order
        'F-ELB-002', // Candidate Validation
        'F-ELB-003', // Subdivision Boundary Drawing
        'F-ELB-004', // Election Results Certification
        'F-ELB-006', // Recount/Audit Order
        'F-IND-007', // Ballot Submission (Ranked Choice)
        'F-IND-011', // Candidacy Registration
        'F-CAN-001', // Campaign Profile Setup
        'F-CAN-002', // Endorsement Request
        'F-CAN-003', // Candidacy Withdrawal
        'F-ORG-002', // Candidate Endorsement Grant
    ];

    // ─── Registry wiring ─────────────────────────────────────────────────

    public function test_all_eleven_phase_b_forms_have_registered_handlers(): void
    {
        foreach (self::PHASE_B_FORMS as $formId) {
            $class = FormRegistry::handlerFor($formId);

            $this->assertNotNull($class, "{$formId} has no handler registered");
            $this->assertTrue(class_exists($class), "{$formId} handler class {$class} missing");
            $this->assertTrue(
                is_subclass_of($class, FormHandler::class),
                "{$formId} handler does not implement FormHandler"
            );
        }
    }

    public function test_handler_role_gates_match_the_forms_catalog(): void
    {
        foreach (self::PHASE_B_FORMS as $formId) {
            $handler = app(FormRegistry::handlerFor($formId));

            $this->assertSame(
                FormRegistry::FORMS[$formId]['roles'],
                $handler->requiredRoles(),
                "{$formId} role gate drifted from the Forms Catalog"
            );
            $this->assertSame('elections', $handler->module(), "{$formId} module");
            $this->assertNotSame('', $handler->event(), "{$formId} event empty");
            $this->assertFalse($handler->systemOnly(), "{$formId} must allow board/individual filing");
        }
    }

    // ─── F-ELB-001 pure guards ───────────────────────────────────────────

    public function test_scheduling_order_requires_ordered_windows(): void
    {
        $base = CarbonImmutable::parse('2026-07-01T00:00:00Z');

        // Ordered schedule passes.
        ElectionSchedulingOrder::assertWindowOrdering([
            'approval_opens_at'  => $base,
            'finalist_cutoff_at' => $base->addDays(30),
            'ranked_opens_at'    => $base->addDays(31),
            'ranked_closes_at'   => $base->addDays(45),
        ]);

        // Cutoff may coincide with the ranked opening.
        ElectionSchedulingOrder::assertWindowOrdering([
            'approval_opens_at'  => $base,
            'finalist_cutoff_at' => $base->addDays(30),
            'ranked_opens_at'    => $base->addDays(30),
            'ranked_closes_at'   => $base->addDays(44),
        ]);

        // Disordered (ranked window before the cutoff) is rejected.
        try {
            ElectionSchedulingOrder::assertWindowOrdering([
                'approval_opens_at'  => $base,
                'finalist_cutoff_at' => $base->addDays(30),
                'ranked_opens_at'    => $base->addDays(10),
                'ranked_closes_at'   => $base->addDays(20),
            ]);
            $this->fail('Disordered schedule must be rejected');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §2', $e->citation);
        }
    }

    public function test_special_election_window_is_hard_bounded(): void
    {
        $declared = CarbonImmutable::parse('2026-07-01T00:00:00Z');

        // Inside [declared+90d, declared+180d] passes.
        ElectionSchedulingOrder::assertSpecialWindow(
            $declared,
            $declared->addDays(100),
            $declared->addDays(114),
            90,
            180,
        );

        // SIGNATURE REJECTION (design §C): out-of-window special dates →
        // rejected with citation Art. II §5.
        foreach ([
            [$declared->addDays(10), $declared->addDays(24)],    // too early
            [$declared->addDays(170), $declared->addDays(184)],  // closes too late
        ] as [$opens, $closes]) {
            try {
                ElectionSchedulingOrder::assertSpecialWindow($declared, $opens, $closes, 90, 180);
                $this->fail('Out-of-window special schedule must be rejected (Art. II §5)');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §5', $e->citation);
            }
        }
    }

    public function test_scheduling_order_dates_must_parse(): void
    {
        try {
            ElectionSchedulingOrder::parseDates(['finalist_cutoff_at' => 'not-a-date']);
            $this->fail('Unparsable date must be rejected');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §2', $e->citation);
        }

        // Missing phase boundary is rejected; approval_opens_at defaults.
        $this->expectException(ConstitutionalViolation::class);
        ElectionSchedulingOrder::parseDates([
            'finalist_cutoff_at' => '2026-08-01T00:00:00Z',
            'ranked_opens_at'    => '2026-08-02T00:00:00Z',
            // ranked_closes_at missing
        ]);
    }

    // ─── F-CAN-003 pure guard (the ballot lock) ──────────────────────────

    public function test_withdrawal_is_blocked_after_the_finalist_cutoff(): void
    {
        $cutoff = CarbonImmutable::parse('2026-08-01T00:00:00Z');

        // Before the cutoff: open.
        CandidacyWithdrawal::assertBeforeCutoff($cutoff, $cutoff->subMinute());

        // No published cutoff yet: open.
        CandidacyWithdrawal::assertBeforeCutoff(null, $cutoff);

        // SIGNATURE REJECTION (design §C): at/after the cutoff the ballot
        // is locked — rejected with the CLK-21 citation.
        foreach ([$cutoff, $cutoff->addSecond(), $cutoff->addDays(3)] as $now) {
            try {
                CandidacyWithdrawal::assertBeforeCutoff($cutoff, $now);
                $this->fail('Post-cutoff withdrawal must be rejected (ballot lock)');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('CLK-21 · open-ballot spec (ballot lock)', $e->citation);
            }
        }
    }

    // ─── F-IND-007 pure guard (ranking shape) ────────────────────────────

    public function test_rankings_must_be_well_formed(): void
    {
        $a = '0b5e3f44-1111-4222-8333-444455556666';
        $b = '0b5e3f44-7777-4888-9999-aaaabbbbcccc';

        // Distinct UUID list passes (case-normalized).
        $this->assertSame([$a, $b], BallotSubmission::assertWellFormedRankings([$a, strtoupper($b)]));

        foreach ([
            null,                  // missing
            [],                    // empty
            'not-a-list',          // wrong type
            [$a, $a],              // duplicate rank
            [$a, 'not-a-uuid'],    // malformed entry
            ['first' => $a],       // not a list
        ] as $bad) {
            try {
                BallotSubmission::assertWellFormedRankings($bad);
                $this->fail('Malformed rankings must be rejected: ' . json_encode($bad));
            } catch (ConstitutionalViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
