<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Term;
use Carbon\CarbonImmutable;

/**
 * THE civil-appointment term opener (Art. II §9 / Art. III §4 — 10-year
 * civil appointments, CLK-09 armed at expiry).
 *
 * Extracted VERBATIM from ChamberActService::openCivilTerm (Phase C)
 * under the PHASE_D_DESIGN_executive §C.2 refactor ruling: election-board
 * members and admin-office staff (Phase C call sites, via
 * ChamberActService) and BOARD GOVERNORS (Phase D, via
 * BoardGovernorService — office_kind 'board_governor') open their terms
 * through this ONE path. One CLK-09 arm, zero behavioral change
 * (TermLockstepTest's civil-appointment whitelist moves here with it —
 * constitutional review note in that test).
 *
 * `ends_on` is written exactly once, at creation — civil appointments
 * are not lockstep terms but inherit the same write-once discipline (the
 * no-update source-scan pin covers every term class).
 */
class CivilAppointmentService
{
    public function __construct(
        private readonly ClockService $clocks,
    ) {
    }

    public function openCivilTerm(
        string $officeKind,
        string $officeType,
        string $officeId,
        string $holderUserId,
        string $jurisdictionId,
        ?string $legislatureId,
        Appointment $appointment,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
    ): Term {
        $term = Term::create([
            'office_kind'           => $officeKind,
            'office_type'           => $officeType,
            'office_id'             => $officeId,
            'holder_user_id'        => $holderUserId,
            'jurisdiction_id'       => $jurisdictionId,
            'legislature_id'        => $legislatureId,
            'term_class'            => Term::CLASS_CIVIL_APPOINTMENT,
            'starts_on'             => $starts->toDateString(),
            'ends_on'               => $ends->toDateString(),
            'source_appointment_id' => $appointment->id,
            'status'                => Term::STATUS_ACTIVE,
        ]);

        $appointment->forceFill([
            'status'  => Appointment::STATUS_SEATED,
            'term_id' => $term->id,
        ])->save();

        // CLK-09 — civil-officer term expiry (deadline timer at ends_on).
        $this->clocks->arm(
            'CLK-09',
            $jurisdictionId,
            'term',
            (string) $term->id,
            $ends->startOfDay(),
            ['step' => 'civil_term_expiry', 'ends_on' => $ends->toDateString()],
        );

        return $term;
    }
}
