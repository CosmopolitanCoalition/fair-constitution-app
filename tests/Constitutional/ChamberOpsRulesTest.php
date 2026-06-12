<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Services\ConstitutionalValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Phase C chamber-ops PROTECTED-validator rules
 * (PHASE_C_DESIGN_chamber_ops §G.3) DB-free:
 *
 *  - speaker.tiebreak_only  (Art. II §3) — tie state only;
 *  - removal.presider       (Art. II §3) — never one's own case;
 *  - committees.kind_split  (Art. V §3)  — mirror, totals, ≥1 per kind;
 *  - vacancy.declarer       (Art. II §5 · a.i.) — never a weapon;
 *  - session.agenda_order   (Art. II §2) — locked head immutable.
 */
class ChamberOpsRulesTest extends TestCase
{
    // ─── speaker.tiebreak_only ───────────────────────────────────────────

    public function test_speaker_may_break_a_resolved_tie(): void
    {
        ConstitutionalValidator::assertSpeakerTieState(4, 4, true);

        $this->assertTrue(true); // no violation thrown
    }

    public function test_speaker_cast_rejected_when_not_tied(): void
    {
        $this->expectException(ConstitutionalViolation::class);

        ConstitutionalValidator::assertSpeakerTieState(5, 3, true);
    }

    public function test_speaker_cast_rejected_while_casts_unresolved(): void
    {
        try {
            ConstitutionalValidator::assertSpeakerTieState(4, 4, false);
            $this->fail('Unresolved casts must reject the tie-break');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §3', $e->citation);
        }
    }

    // ─── removal.presider ────────────────────────────────────────────────

    public function test_presider_must_not_be_the_subject(): void
    {
        ConstitutionalValidator::assertRemovalPresider('m-speaker', 'legislature_members', 'm-subject');

        try {
            ConstitutionalValidator::assertRemovalPresider('m-speaker', 'legislature_members', 'm-speaker');
            $this->fail('No one presides over their own removal');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §3', $e->citation);
        }
    }

    // ─── committees.kind_split ───────────────────────────────────────────

    public function test_bicameral_committee_requires_a_valid_split(): void
    {
        ConstitutionalValidator::assertCommitteeKindSplit(5, 4, 1, true); // San Marino 5-seat shape

        foreach ([
            [5, null, null], // missing split
            [5, 3, 1],       // does not total
            [5, 5, 0],       // a kind with zero seats at seats ≥ 2
        ] as [$seats, $a, $b]) {
            try {
                ConstitutionalValidator::assertCommitteeKindSplit($seats, $a, $b, true);
                $this->fail("Split {$a}/{$b} of {$seats} must be rejected");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. V §3', $e->citation);
            }
        }
    }

    public function test_unicameral_committee_carries_no_split(): void
    {
        ConstitutionalValidator::assertCommitteeKindSplit(5, null, null, false);

        $this->expectException(ConstitutionalViolation::class);

        ConstitutionalValidator::assertCommitteeKindSplit(5, 4, 1, false);
    }

    // ─── vacancy.declarer ────────────────────────────────────────────────

    public function test_system_speaker_and_self_may_declare(): void
    {
        ConstitutionalValidator::assertVacancyDeclarer(true, false, false);   // system
        ConstitutionalValidator::assertVacancyDeclarer(false, true, false);   // speaker
        ConstitutionalValidator::assertVacancyDeclarer(false, false, true);   // own seat

        $this->assertTrue(true);
    }

    public function test_plain_legislator_may_not_declare_anothers_seat(): void
    {
        try {
            ConstitutionalValidator::assertVacancyDeclarer(false, false, false);
            $this->fail('Declaration-as-weapon must be rejected');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §5 · as implemented', $e->citation);
        }
    }

    // ─── session.agenda_order ────────────────────────────────────────────

    public function test_locked_head_is_immutable_and_precedes_general_business(): void
    {
        $locked  = ['kind' => 'emergency_power', 'ref_type' => 'emergency_power', 'ref_id' => 'ep-1', 'locked' => true];
        $general = ['kind' => 'general', 'ref_type' => null, 'ref_id' => null, 'locked' => false];

        // Reordering/inserting AFTER the head is fine.
        ConstitutionalValidator::assertAgendaOrder(
            [$locked, $general],
            [$locked, ['kind' => 'general', 'ref_type' => null, 'ref_id' => 'x', 'locked' => false], $general]
        );

        // Dropping the locked item is a violation.
        try {
            ConstitutionalValidator::assertAgendaOrder([$locked, $general], [$general]);
            $this->fail('Removing a locked item must be rejected');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §2', $e->citation);
        }

        // Demoting it below general business is a violation.
        $this->expectException(ConstitutionalViolation::class);

        ConstitutionalValidator::assertAgendaOrder([$locked, $general], [$general, $locked]);
    }

    public function test_filings_may_not_smuggle_new_locked_items(): void
    {
        $this->expectException(ConstitutionalViolation::class);

        ConstitutionalValidator::assertAgendaOrder(
            [],
            [['kind' => 'general', 'ref_type' => null, 'ref_id' => null, 'locked' => true]]
        );
    }
}
