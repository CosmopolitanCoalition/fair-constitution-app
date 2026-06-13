<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Appointment;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Department;
use App\Models\ExecutiveMember;
use App\Models\GovernorRemovalRequest;
use App\Models\Legislature;
use App\Models\Term;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\CivilAppointmentService;
use App\Services\ClockService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Board-of-Governors pipeline (PHASE_D_DESIGN_executive §C.2/§C.3 —
 * WF-EXE-05/06): F-EXE-001 nomination → F-LEG-020 consent (cast via
 * F-LEG-004 under the `bog_consent` vote type — ordinary majority of ALL
 * serving) → seating with a 10-year civil-appointment term (CLK-09 armed
 * through the shared CivilAppointmentService — the ONE Art. II §9 path).
 *
 * Nominee eligibility = active jurisdiction association ONLY (Art. I —
 * neutrality is a duty of office, not an eligibility test; the F-LEG-012
 * posture). Removal (F-EXE-003) is ORDINARY-MAJORITY hiring-and-firing
 * (owner ruling #14) — deliberately not the supermajority
 * `officeholder_remove` machinery.
 */
class BoardGovernorService
{
    public const CONSENT_VOTE_TYPE = 'bog_consent';

    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly CivilAppointmentService $civil,
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
        private readonly SettingsResolver $settings,
        private readonly ClockService $clocks,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // F-EXE-001 — nomination
    // =========================================================================

    /**
     * Nominate a governor onto a vacant governor seat of the department's
     * board, publishing the dossier and opening the F-LEG-020 consent
     * vote in the jurisdiction's legislature.
     *
     * @return array{appointment_id: string, seat_id: string, consent_vote_id: string}
     */
    public function nominate(
        Department $department,
        ExecutiveMember $nominator,
        string $nomineeUserId,
        ?string $dossier = null,
    ): array {
        if ((string) $nominator->executive_id !== (string) $department->executive_id
            || $nominator->status !== ExecutiveMember::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'F-EXE-001 is filed by a seated member of the OVERSEEING executive (R-14/15/16).',
                'Art. III §4'
            );
        }

        return $this->openNomination($department, $nomineeUserId, (string) $nominator->user_id, $dossier);
    }

    /**
     * Nominations carried on the F-LEG-016 act itself (design §C.1 —
     * `nominees` payload; nominated_by = the proposing legislator).
     */
    public function nominateFromAct(Department $department, string $nomineeUserId, ?string $proposedByMemberId): array
    {
        $nominatedBy = $proposedByMemberId !== null
            ? DB::table('legislature_members')->where('id', $proposedByMemberId)->value('user_id')
            : null;

        return $this->openNomination(
            $department,
            $nomineeUserId,
            $nominatedBy !== null ? (string) $nominatedBy : null,
            null,
        );
    }

    /** @return array{appointment_id: string, seat_id: string, consent_vote_id: string} */
    private function openNomination(
        Department $department,
        string $nomineeUserId,
        ?string $nominatedByUserId,
        ?string $dossier,
    ): array {
        $this->assertNomineeAssociation($nomineeUserId, (string) $department->jurisdiction_id);

        $seat = BoardSeat::query()
            ->where('board_id', $department->board_id)
            ->where('seat_class', BoardSeat::CLASS_GOVERNOR)
            ->where('status', BoardSeat::STATUS_VACANT)
            ->orderBy('seat_no')
            ->lockForUpdate()
            ->first();

        if ($seat === null) {
            throw new ConstitutionalViolation(
                'No vacant governor seat exists on this department\'s board.',
                'Art. III §4'
            );
        }

        $legislature = $this->legislatureOf((string) $department->jurisdiction_id);

        $appointment = Appointment::create([
            'appointable_type'   => 'board_seats',
            'appointable_id'     => (string) $seat->id,
            'nominee_user_id'    => $nomineeUserId,
            'nominated_by'       => $nominatedByUserId,
            'nominated_via_form' => 'F-EXE-001',
            'status'             => Appointment::STATUS_NOMINATED,
        ]);

        $seat->forceFill(['appointment_id' => (string) $appointment->id, 'status' => BoardSeat::STATUS_NOMINATED])->save();

        // Dossier text published at nomination (design §C.2.2).
        $this->records->publish(
            kind: 'other',
            title: sprintf('Governor nominated — %s, seat %d', $department->name, (int) $seat->seat_no),
            body: $dossier,
            attrs: [
                'actor_user_id'   => $nominatedByUserId,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-EXE-001',
                'subject_type'    => 'appointments',
                'subject_id'      => (string) $appointment->id,
            ],
        );

        // F-LEG-020 IS the consent vote (cast via F-LEG-004 — the form
        // stays unregistered as a handler; FormRegistry posture).
        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: self::CONSENT_VOTE_TYPE,
            votable: $appointment,
            stage: ChamberVote::STAGE_FLOOR,
        );

        $appointment->forceFill(['consent_vote_id' => (string) $vote->id])->save();

        if (in_array($department->status, [Department::STATUS_OVERSIGHT_ASSIGNED, Department::STATUS_CHARTERED], true)) {
            $department->forceFill(['status' => Department::STATUS_GOVERNORS_NOMINATED])->save();
        }

        return [
            'appointment_id'  => (string) $appointment->id,
            'seat_id'         => (string) $seat->id,
            'consent_vote_id' => (string) $vote->id,
        ];
    }

    // =========================================================================
    // Consent close (ChamberActService::resolveConsentVote dispatch)
    // =========================================================================

    /**
     * Adopted consent → seat the governor: board_seats `seated`, the
     * 10-year civil term (CLK-09 armed at expiry via the shared
     * CivilAppointmentService), certification record, R-18 derivable.
     *
     * @return array<string, mixed>
     */
    public function seat(Appointment $appointment): array
    {
        $seat       = BoardSeat::query()->whereKey($appointment->appointable_id)->lockForUpdate()->firstOrFail();
        $board      = Board::query()->whereKey($seat->board_id)->firstOrFail();
        $department = $this->departmentOf($board);

        $starts = CarbonImmutable::now('UTC')->startOfDay();
        $years  = $this->settings->resolveInt((string) $department->jurisdiction_id, 'civil_appointment_years', 10);
        $ends   = $starts->addYears($years);

        $legislature = $this->legislatureOf((string) $department->jurisdiction_id);

        $term = $this->civil->openCivilTerm(
            officeKind: 'board_governor',
            officeType: 'board_seats',
            officeId: (string) $seat->id,
            holderUserId: (string) $appointment->nominee_user_id,
            jurisdictionId: (string) $department->jurisdiction_id,
            legislatureId: (string) $legislature->id,
            appointment: $appointment,
            starts: $starts,
            ends: $ends,
        );

        $seat->forceFill([
            'holder_user_id' => (string) $appointment->nominee_user_id,
            'term_id'        => (string) $term->id,
            'status'         => BoardSeat::STATUS_SEATED,
        ])->save();

        $this->records->publish(
            kind: 'certification',
            title: sprintf('Governor seated — %s, seat %d', $department->name, (int) $seat->seat_no),
            body: sprintf(
                'Appointee %s consented by majority of all serving (F-LEG-020) and seated '
                . '(civil appointment, %d years — Art. III §4 · Art. II §9; CLK-09 armed at %s).',
                (string) $appointment->nominee_user_id,
                $years,
                $ends->toDateString()
            ),
            attrs: [
                'actor_user_id'   => (string) $appointment->nominee_user_id,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-020',
                'subject_type'    => 'board_seats',
                'subject_id'      => (string) $seat->id,
            ],
        );

        $this->roles->flushUser((string) $appointment->nominee_user_id);

        $department = $department->refresh();
        $operating  = app(DepartmentService::class)->maybeAdvanceToOperating($department);

        if (! $operating
            && in_array($department->status, [Department::STATUS_GOVERNORS_NOMINATED, Department::STATUS_OVERSIGHT_ASSIGNED], true)) {
            $department->forceFill(['status' => Department::STATUS_CONSENTED])->save();
        }

        return [
            'appointment_id' => (string) $appointment->id,
            'seat_id'        => (string) $seat->id,
            'term_id'        => (string) $term->id,
            'operating'      => $operating,
        ];
    }

    /** Rejected consent → the seat reopens for renomination (the loop). */
    public function handleRejectedNomination(Appointment $appointment): void
    {
        $seat = BoardSeat::query()->whereKey($appointment->appointable_id)->first();

        if ($seat === null || $seat->status !== BoardSeat::STATUS_NOMINATED) {
            return;
        }

        $seat->forceFill(['appointment_id' => null, 'status' => BoardSeat::STATUS_VACANT])->save();
    }

    /**
     * R-30 thin slice (design §E.2): department civil staff consented
     * through the SAME pipeline — appointable_type 'departments', term
     * office_kind 'civil_officer'.
     *
     * @return array<string, mixed>
     */
    public function seatCivilOfficer(Appointment $appointment): array
    {
        $department = Department::query()->whereKey($appointment->appointable_id)->firstOrFail();

        $starts = CarbonImmutable::now('UTC')->startOfDay();
        $years  = $this->settings->resolveInt((string) $department->jurisdiction_id, 'civil_appointment_years', 10);
        $ends   = $starts->addYears($years);

        $legislature = $this->legislatureOf((string) $department->jurisdiction_id);

        $term = $this->civil->openCivilTerm(
            officeKind: 'civil_officer',
            officeType: 'appointments',
            officeId: (string) $appointment->id,
            holderUserId: (string) $appointment->nominee_user_id,
            jurisdictionId: (string) $department->jurisdiction_id,
            legislatureId: (string) $legislature->id,
            appointment: $appointment,
            starts: $starts,
            ends: $ends,
        );

        $this->records->publish(
            kind: 'certification',
            title: sprintf('Department civil officer seated — %s', $department->name),
            body: sprintf(
                'Appointee %s seated (civil appointment, %d years — Art. II §9).',
                (string) $appointment->nominee_user_id,
                $years
            ),
            attrs: [
                'actor_user_id'   => (string) $appointment->nominee_user_id,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-020',
                'subject_type'    => 'departments',
                'subject_id'      => (string) $department->id,
            ],
        );

        $this->roles->flushUser((string) $appointment->nominee_user_id);

        return [
            'appointment_id' => (string) $appointment->id,
            'department_id'  => (string) $department->id,
            'term_id'        => (string) $term->id,
        ];
    }

    // =========================================================================
    // F-EXE-003 — removal (ordinary majority, owner ruling #14)
    // =========================================================================

    /**
     * File a removal request: grounds published immediately, seat →
     * removal_requested, ordinary-majority chamber vote opens.
     *
     * @return array{request_id: string, vote_id: string}
     */
    public function requestRemoval(BoardSeat $seat, ExecutiveMember $requester, string $grounds): array
    {
        if ($seat->status !== BoardSeat::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'Removal requests run against SEATED board members.',
                'Art. III §4'
            );
        }

        $board      = Board::query()->whereKey($seat->board_id)->firstOrFail();
        $department = $this->departmentOf($board);

        if ((string) $requester->executive_id !== (string) $department->executive_id
            || $requester->status !== ExecutiveMember::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'F-EXE-003 is filed by a seated member of the OVERSEEING executive (good-faith finding).',
                'Art. III §4'
            );
        }

        if (trim($grounds) === '') {
            throw new ConstitutionalViolation(
                'A removal request states good-faith competence/ethics grounds — published at filing.',
                'Art. III §4'
            );
        }

        $legislature = $this->legislatureOf((string) $department->jurisdiction_id);

        $request = GovernorRemovalRequest::create([
            'board_seat_id'          => (string) $seat->id,
            'requested_by_member_id' => (string) $requester->id,
            'grounds'                => $grounds,
            'outcome'                => GovernorRemovalRequest::OUTCOME_PENDING,
        ]);

        $record = $this->records->publish(
            kind: 'other',
            title: sprintf('Governor removal requested — %s, seat %d', $department->name, (int) $seat->seat_no),
            body: $grounds,
            attrs: [
                'actor_user_id'   => $requester->user_id !== null ? (string) $requester->user_id : null,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-EXE-003',
                'subject_type'    => 'governor_removal_requests',
                'subject_id'      => (string) $request->id,
            ],
        );

        // Ordinary majority — deliberately NOT officeholder_remove
        // (owner ruling #14; GovernorRemovalOrdinaryMajorityTest pins it).
        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: ExecutiveActService::GOVERNOR_REMOVAL_VOTE_TYPE,
            votable: $request,
            stage: ChamberVote::STAGE_FLOOR,
        );

        $request->forceFill(['record_id' => (string) $record->id, 'vote_id' => (string) $vote->id])->save();
        $seat->forceFill(['status' => BoardSeat::STATUS_REMOVAL_REQUESTED])->save();

        return ['request_id' => (string) $request->id, 'vote_id' => (string) $vote->id];
    }

    /** votable_type 'governor_removal' (vote-engine dispatch, same txn). */
    public function resolveRemovalVote(ChamberVote $vote, string $outcome): void
    {
        $request = GovernorRemovalRequest::query()->find($vote->votable_id);

        if ($request === null || $request->outcome !== GovernorRemovalRequest::OUTCOME_PENDING) {
            return; // idempotent
        }

        $seat       = BoardSeat::query()->whereKey($request->board_seat_id)->lockForUpdate()->firstOrFail();
        $board      = Board::query()->whereKey($seat->board_id)->firstOrFail();
        $department = $this->departmentOf($board);

        if ($outcome !== ChamberVote::OUTCOME_ADOPTED) {
            $request->forceFill(['outcome' => GovernorRemovalRequest::OUTCOME_RETAINED, 'decided_at' => now()])->save();
            $seat->forceFill(['status' => BoardSeat::STATUS_SEATED])->save();

            return;
        }

        $holder = $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null;

        // Term closes, CLK-09 timer cancelled.
        if ($seat->term_id !== null) {
            $term = Term::query()->whereKey($seat->term_id)->first();

            if ($term !== null && $term->status === Term::STATUS_ACTIVE) {
                $term->forceFill(['status' => Term::STATUS_REMOVED])->save();

                foreach (\App\Models\ClockTimer::query()
                    ->armed()
                    ->where('clock_id', 'CLK-09')
                    ->where('subject_type', 'term')
                    ->where('subject_id', (string) $term->id)
                    ->get() as $timer) {
                    $this->clocks->cancel($timer, 'governor removed by ordinary-majority vote');
                }
            }
        }

        $request->forceFill(['outcome' => GovernorRemovalRequest::OUTCOME_REMOVED, 'decided_at' => now()])->save();

        $seat->forceFill([
            'status'         => BoardSeat::STATUS_REMOVED,
            'holder_user_id' => null,
            'appointment_id' => null,
            'term_id'        => null,
            'is_chair'       => false,
        ])->save();

        // The seat reopens for renomination (the WF-EXE-05 loop).
        BoardSeat::create([
            'board_id'   => (string) $board->id,
            'seat_class' => BoardSeat::CLASS_GOVERNOR,
            'seat_no'    => $this->nextSeatNo($board),
            'status'     => BoardSeat::STATUS_VACANT,
        ]);

        if ($holder !== null) {
            $this->roles->flushUser($holder);
        }

        $this->records->publish(
            kind: 'act',
            title: sprintf('Governor removed — %s, seat %d', $department->name, (int) $seat->seat_no),
            body: sprintf(
                'Removal carried by ordinary majority of all serving (hiring-and-firing — never the '
                . 'impeachment machinery). Grounds on record %s. Renomination open.',
                (string) $request->record_id
            ),
            attrs: [
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'via_form'        => 'F-EXE-003',
                'subject_type'    => 'board_seats',
                'subject_id'      => (string) $seat->id,
            ],
        );
    }

    // =========================================================================
    // CLK-09 expiry (fired by CivilTermExpiryJob)
    // =========================================================================

    /** Term expiry → seat term_ended; renomination opens on the record. */
    public function expireGovernorTerm(Term $term): void
    {
        $seat = BoardSeat::query()
            ->where('term_id', $term->id)
            ->where('status', BoardSeat::STATUS_SEATED)
            ->first();

        if ($seat === null) {
            return;
        }

        $board      = Board::query()->whereKey($seat->board_id)->firstOrFail();
        $department = $this->departmentOf($board);

        if ($term->status === Term::STATUS_ACTIVE) {
            $term->forceFill(['status' => Term::STATUS_COMPLETED])->save();
        }

        $holder = $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null;

        $seat->forceFill(['status' => BoardSeat::STATUS_TERM_ENDED, 'is_chair' => false])->save();

        BoardSeat::create([
            'board_id'   => (string) $board->id,
            'seat_class' => BoardSeat::CLASS_GOVERNOR,
            'seat_no'    => $this->nextSeatNo($board),
            'status'     => BoardSeat::STATUS_VACANT,
        ]);

        if ($holder !== null) {
            $this->roles->flushUser($holder);
        }

        $this->records->publish(
            kind: 'other',
            title: sprintf('Governor term ended — %s, seat %d: renomination open', $department->name, (int) $seat->seat_no),
            body: sprintf(
                'The %s civil appointment reached its expiry (CLK-09). The seat reopens for '
                . 'F-EXE-001 nomination and F-LEG-020 consent.',
                $term->ends_on?->toDateString() ?? ''
            ),
            attrs: [
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'via_clock'       => 'CLK-09',
                'subject_type'    => 'board_seats',
                'subject_id'      => (string) $seat->id,
            ],
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function departmentOf(Board $board): Department
    {
        if ($board->boardable_type !== Board::BOARDABLE_DEPARTMENTS) {
            throw new ConstitutionalViolation(
                'The governor pipeline serves DEPARTMENT boards; org boards seat through their '
                . 'own election tracks (Art. III §6).',
                'Art. III §4'
            );
        }

        return Department::query()->whereKey($board->boardable_id)->firstOrFail();
    }

    private function legislatureOf(string $jurisdictionId): Legislature
    {
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->first();

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                'No legislature exists to consent — the BoG pipeline requires the chartering chamber.',
                'Art. III §4'
            );
        }

        return $legislature;
    }

    private function nextSeatNo(Board $board): int
    {
        return (int) BoardSeat::query()
            ->where('board_id', $board->id)
            ->withTrashed()
            ->max('seat_no') + 1;
    }

    private function assertNomineeAssociation(string $userId, string $jurisdictionId): void
    {
        $associated = DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $associated) {
            throw new ConstitutionalViolation(
                'F-EXE-001 nominee holds no active association with the jurisdiction — association '
                . 'is the ONLY eligibility check (Art. I; neutrality is a duty of office).',
                'Art. I'
            );
        }
    }
}
