<?php

namespace Tests\Constitutional;

use App\Domain\Forms\Handlers\VacancyDeclaration;
use App\Jobs\HandleOfficeholderRelocationJob;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WF-CIV-03 relocation → vacancy linkage (PHASE_C_DESIGN_
 * chamber_ops §F.2) DB-free:
 *
 *  - the footprint predicate: a seat vacates only when NONE of its
 *    footprint jurisdictions appears among the member's NEW active
 *    associations (districted seats look at the district's member
 *    jurisdictions; at-large seats at the legislature's own);
 *  - 'relocation' is a recognized F-LEG-036 reason (the system filing's
 *    enum);
 *  - the grace posture is structural: the job exists only as an
 *    after-commit consequence of a VERIFIED new claim — rights never gap,
 *    because the old claim stays Active until the new claim verifies
 *    (pinned live by the residency E2E).
 */
class RelocationVacancyTest extends TestCase
{
    public function test_seat_stays_while_any_footprint_association_remains(): void
    {
        // Districted seat over three member jurisdictions; the member
        // still holds one of them (e.g. moved within the district).
        $this->assertFalse(HandleOfficeholderRelocationJob::outOfFootprint(
            ['j-1', 'j-2', 'j-3'],
            ['j-3', 'j-earth']
        ));

        // At-large: the legislature's own jurisdiction still associated.
        $this->assertFalse(HandleOfficeholderRelocationJob::outOfFootprint(
            ['j-sanmarino'],
            ['j-sanmarino', 'j-earth']
        ));
    }

    public function test_seat_vacates_when_no_footprint_association_remains(): void
    {
        $this->assertTrue(HandleOfficeholderRelocationJob::outOfFootprint(
            ['j-1', 'j-2', 'j-3'],
            ['j-elsewhere', 'j-earth']
        ));

        $this->assertTrue(HandleOfficeholderRelocationJob::outOfFootprint(
            ['j-montegiardino'],
            []
        ));
    }

    public function test_ancestor_associations_do_not_keep_a_districted_seat(): void
    {
        // Earth association alone never anchors a districted seat — the
        // footprint is the DISTRICT's member jurisdictions, not the
        // member's whole chain.
        $this->assertTrue(HandleOfficeholderRelocationJob::outOfFootprint(
            ['j-district-member'],
            ['j-earth']
        ));
    }

    public function test_relocation_is_a_recognized_vacancy_reason(): void
    {
        $this->assertContains('relocation', VacancyDeclaration::REASONS);
    }
}
