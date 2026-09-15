<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Appointment;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\Department;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\GovernorRemovalRequest;
use App\Models\Legislature;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\CivilAppointmentService;
use App\Services\ClockService;
use App\Services\Organizations\OrgBoardService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    ) {}

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
        $department = Department::query()->whereKey($department->id)->lockForUpdate()->firstOrFail();
        $nominator = ExecutiveMember::query()->whereKey($nominator->id)->lockForUpdate()->firstOrFail();
        if ((string) $nominator->executive_id !== (string) $department->executive_id
            || $nominator->status !== ExecutiveMember::STATUS_SEATED || $nominator->role !== ExecutiveMember::ROLE_PRINCIPAL) {
            throw new ConstitutionalViolation(
                __('F-EXE-001 is filed by a seated principal of the OVERSEEING executive (R-14/15/16).'),
                'Art. III §4'
            );
        }

        return $this->openNomination($department, $nomineeUserId, (string) $nominator->user_id, $dossier);
    }

    /** CGCs use the same governor consent/term machinery as departments. */
    public function nominateCgc(Organization $organization, ExecutiveMember $nominator, string $nomineeUserId, ?string $dossier = null): array
    {
        $organization = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
        $this->context($organization);
        $nominator = ExecutiveMember::query()->whereKey($nominator->id)->lockForUpdate()->firstOrFail();
        if ((string) $nominator->executive_id !== (string) $organization->overseen_by_executive_id
            || $nominator->status !== ExecutiveMember::STATUS_SEATED || $nominator->role !== ExecutiveMember::ROLE_PRINCIPAL) {
            throw new ConstitutionalViolation(__('Only a seated principal of this corporation\'s overseeing executive may nominate its governors.'), 'Art. III §5');
        }

        return $this->openNomination($organization, $nomineeUserId, (string) $nominator->user_id, $dossier);
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
        Department|Organization $department,
        string $nomineeUserId,
        ?string $nominatedByUserId,
        ?string $dossier,
    ): array {
        [$department, $board, $legislature] = $this->context($department);
        $this->assertNomineeAssociation($nomineeUserId, (string) $department->jurisdiction_id);

        $seat = BoardSeat::query()
            ->where('board_id', $board->id)
            ->where('seat_class', BoardSeat::CLASS_GOVERNOR)
            ->where('status', BoardSeat::STATUS_VACANT)
            ->whereNull('appointment_id')->whereNull('holder_user_id')->whereNull('term_id')
            ->orderBy('seat_no')
            ->lockForUpdate()
            ->first();

        if ($seat === null) {
            throw new ConstitutionalViolation(
                __('No vacant governor seat exists on this institution\'s current board.'),
                'Art. III §4'
            );
        }

        $appointment = Appointment::create([
            'appointable_type' => 'board_seats',
            'appointable_id' => (string) $seat->id,
            'nominee_user_id' => $nomineeUserId,
            'nominated_by' => $nominatedByUserId,
            'nominated_via_form' => 'F-EXE-001',
            'status' => Appointment::STATUS_NOMINATED,
        ]);

        $seat->forceFill(['appointment_id' => (string) $appointment->id, 'status' => BoardSeat::STATUS_NOMINATED])->save();

        // Dossier text published at nomination (design §C.2.2).
        $this->records->publish(
            kind: 'other',
            title: sprintf('Governor nominated — %s, seat %d', $department->name, (int) $seat->seat_no),
            body: $dossier,
            attrs: [
                'actor_user_id' => $nominatedByUserId,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-EXE-001',
                'subject_type' => 'appointments',
                'subject_id' => (string) $appointment->id,
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

        if ($department instanceof Department && in_array($department->status, [Department::STATUS_OVERSIGHT_ASSIGNED, Department::STATUS_CHARTERED], true)) {
            $department->forceFill(['status' => Department::STATUS_GOVERNORS_NOMINATED])->save();
        }

        return [
            'appointment_id' => (string) $appointment->id,
            'seat_id' => (string) $seat->id,
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
        $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
        $vote = ChamberVote::query()->whereKey($appointment->consent_vote_id)->first();
        if ($vote === null) {
            throw new ConstitutionalViolation(__('Governor seating requires its recorded consent vote.'), 'Art. III §4/§5');
        }
        $this->assertConsentVote($appointment, $vote, ChamberVote::OUTCOME_ADOPTED);
        [$department, $board, $legislature, $seat] = $this->appointmentContext($appointment);

        $starts = CarbonImmutable::now('UTC')->startOfDay();
        $years = $this->settings->resolveInt((string) $department->jurisdiction_id, 'civil_appointment_years', 10);
        $ends = $starts->addYears($years);

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
            'term_id' => (string) $term->id,
            'status' => BoardSeat::STATUS_SEATED,
        ])->save();

        $this->records->publish(
            kind: 'certification',
            title: sprintf('Governor seated — %s, seat %d', $department->name, (int) $seat->seat_no),
            body: sprintf(
                'Appointee %s consented by majority of all serving (F-LEG-020) and seated '
                .'(civil appointment, %d years — Art. III §4 · Art. II §9; CLK-09 armed at %s).',
                (string) $appointment->nominee_user_id,
                $years,
                $ends->toDateString()
            ),
            attrs: [
                'actor_user_id' => (string) $appointment->nominee_user_id,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-020',
                'subject_type' => 'board_seats',
                'subject_id' => (string) $seat->id,
            ],
        );

        $this->roles->flushUser((string) $appointment->nominee_user_id);

        if ($department instanceof Department) {
            $department = $department->refresh();
            $operating = app(DepartmentService::class)->maybeAdvanceToOperating($department);
            if (! $operating && in_array($department->status, [Department::STATUS_GOVERNORS_NOMINATED, Department::STATUS_OVERSIGHT_ASSIGNED], true)) {
                $department->forceFill(['status' => Department::STATUS_CONSENTED])->save();
            }
        } else {
            app(OrgBoardService::class)->onCompositionChange($board);
            $operating = $board->refresh()->status === Board::STATUS_ACTIVE;
        }

        return [
            'appointment_id' => (string) $appointment->id,
            'seat_id' => (string) $seat->id,
            'term_id' => (string) $term->id,
            'operating' => $operating,
        ];
    }

    /** Rejected consent → the seat reopens for renomination (the loop). */
    public function handleRejectedNomination(Appointment $appointment): void
    {
        $seat = BoardSeat::query()->whereKey($appointment->appointable_id)->lockForUpdate()->first();

        if ($seat === null || $seat->status !== BoardSeat::STATUS_NOMINATED
            || (string) $seat->appointment_id !== (string) $appointment->id) {
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
        $years = $this->settings->resolveInt((string) $department->jurisdiction_id, 'civil_appointment_years', 10);
        $ends = $starts->addYears($years);

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
                'actor_user_id' => (string) $appointment->nominee_user_id,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-020',
                'subject_type' => 'departments',
                'subject_id' => (string) $department->id,
            ],
        );

        $this->roles->flushUser((string) $appointment->nominee_user_id);

        return [
            'appointment_id' => (string) $appointment->id,
            'department_id' => (string) $department->id,
            'term_id' => (string) $term->id,
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
                __('Removal requests run against SEATED board members.'),
                'Art. III §4'
            );
        }

        $board = Board::query()->whereKey($seat->board_id)->firstOrFail();
        // Owner-neutral: the owner (department or CGC), its CURRENT board and
        // its consenting legislature all resolve from the board's owner. For a
        // CGC that legislature is created_by_legislature_id; for a department
        // it is the jurisdiction chamber (context returns the same
        // legislatureOf result, so the department path is unchanged and the
        // first-by-jurisdiction lookup is no longer used here).
        [$owner, $board, $legislature] = $this->context($this->ownerOf($board));
        $overseeingExecutiveId = $owner instanceof Organization
            ? (string) $owner->overseen_by_executive_id
            : (string) $owner->executive_id;

        if ((string) $requester->executive_id !== $overseeingExecutiveId
            || $requester->status !== ExecutiveMember::STATUS_SEATED || $requester->role !== ExecutiveMember::ROLE_PRINCIPAL) {
            throw new ConstitutionalViolation(
                __('F-EXE-003 is filed by a seated principal of the OVERSEEING executive (good-faith finding).'),
                'Art. III §4'
            );
        }

        if (trim($grounds) === '') {
            throw new ConstitutionalViolation(
                __('A removal request states good-faith competence/ethics grounds — published at filing.'),
                'Art. III §4'
            );
        }

        $request = GovernorRemovalRequest::create([
            'board_seat_id' => (string) $seat->id,
            'requested_by_member_id' => (string) $requester->id,
            'grounds' => $grounds,
            'outcome' => GovernorRemovalRequest::OUTCOME_PENDING,
        ]);

        $record = $this->records->publish(
            kind: 'other',
            title: sprintf('Governor removal requested — %s, seat %d', $owner->name, (int) $seat->seat_no),
            body: $grounds,
            attrs: [
                'actor_user_id' => $requester->user_id !== null ? (string) $requester->user_id : null,
                'jurisdiction_id' => (string) $owner->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-EXE-003',
                'subject_type' => 'governor_removal_requests',
                'subject_id' => (string) $request->id,
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

        $seat = BoardSeat::query()->whereKey($request->board_seat_id)->lockForUpdate()->firstOrFail();
        if ($seat->status !== BoardSeat::STATUS_REMOVAL_REQUESTED) {
            return; // Expiry or another completed lifecycle event already ended this request's seat.
        }
        $board = Board::query()->whereKey($seat->board_id)->firstOrFail();
        // Owner-neutral, and the current-board guard refuses a superseded
        // board (a stale outcome cannot reopen a seat on a board that is no
        // longer the owner's).
        $owner = $this->ownerOf($board);

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
            'status' => BoardSeat::STATUS_REMOVED,
            'holder_user_id' => null,
            'appointment_id' => null,
            'term_id' => null,
            'is_chair' => false,
        ])->save();

        // The seat reopens for renomination (the WF-EXE-05 loop).
        BoardSeat::create([
            'board_id' => (string) $board->id,
            'seat_class' => BoardSeat::CLASS_GOVERNOR,
            'seat_no' => $this->nextSeatNo($board),
            'status' => BoardSeat::STATUS_VACANT,
        ]);

        if ($holder !== null) {
            $this->roles->flushUser($holder);
        }

        // A CGC board recomputes co-determination composition and supersedes
        // any open chair ballot after the seat changes (matching CLK-09
        // expiry); a department board needs neither.
        if ($owner instanceof Organization) {
            app(OrgBoardService::class)->onCompositionChange($board);
        }

        $this->records->publish(
            kind: 'act',
            title: sprintf('Governor removed — %s, seat %d', $owner->name, (int) $seat->seat_no),
            body: sprintf(
                'Removal carried by ordinary majority of all serving (hiring-and-firing — never the '
                .'impeachment machinery). Grounds on record %s. Renomination open.',
                (string) $request->record_id
            ),
            attrs: [
                'jurisdiction_id' => (string) $owner->jurisdiction_id,
                'via_form' => 'F-EXE-003',
                'subject_type' => 'board_seats',
                'subject_id' => (string) $seat->id,
            ],
        );
    }

    // =========================================================================
    // CLK-09 expiry (fired by CivilTermExpiryJob)
    // =========================================================================

    /** Term expiry → seat term_ended; renomination opens on the record. */
    public function expireGovernorTerm(Term $term): void
    {
        DB::transaction(function () use ($term) {
            $term = Term::query()->whereKey($term->id)->lockForUpdate()->first();
            if ($term === null || $term->status !== Term::STATUS_ACTIVE
                || $term->term_class !== Term::CLASS_CIVIL_APPOINTMENT || $term->office_type !== 'board_seats'
                || ! in_array($term->office_kind, ['board_governor', 'board_seat'], true)
                || $term->ends_on === null || $term->ends_on->isFuture()) {
                return;
            }
            $seat = BoardSeat::query()->whereKey($term->office_id)->first();
            $board = $seat === null ? null : Board::query()->whereKey($seat->board_id)->first();
            if ($board === null) {
                return;
            }
            try {
                [$department, $board] = $this->context($this->ownerOf($board), false);
            } catch (ConstitutionalViolation) {
                return; // A historic/converted owner cannot reopen the current board.
            }
            $seat = BoardSeat::query()->whereKey($term->office_id)->lockForUpdate()->first();
            if ($seat === null || (string) $seat->board_id !== (string) $board->id
                || $seat->seat_class !== BoardSeat::CLASS_GOVERNOR
                || ! in_array($seat->status, [BoardSeat::STATUS_SEATED, BoardSeat::STATUS_REMOVAL_REQUESTED], true)
                || (string) $seat->term_id !== (string) $term->id
                || (string) $seat->holder_user_id !== (string) $term->holder_user_id
                || (string) $department->jurisdiction_id !== (string) $term->jurisdiction_id
                || ($term->office_kind === 'board_seat' && ! ($department instanceof Organization))) {
                return;
            }

            $term->forceFill(['status' => Term::STATUS_COMPLETED])->save();
            $holder = $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null;
            $seat->forceFill(['status' => BoardSeat::STATUS_TERM_ENDED, 'is_chair' => false])->save();
            if ($seat->appointment_id !== null) {
                Appointment::query()->whereKey($seat->appointment_id)->where('term_id', $term->id)
                    ->where('appointable_type', 'board_seats')->where('appointable_id', $seat->id)
                    ->where('status', Appointment::STATUS_SEATED)->update(['status' => Appointment::STATUS_ENDED]);
            }
            BoardSeat::create([
                'board_id' => (string) $board->id, 'seat_class' => BoardSeat::CLASS_GOVERNOR,
                'seat_no' => $this->nextSeatNo($board), 'status' => BoardSeat::STATUS_VACANT,
            ]);
            if ($holder !== null) {
                $this->roles->flushUser($holder);
            }
            if ($department instanceof Organization) {
                app(OrgBoardService::class)->onCompositionChange($board);
            } elseif ((string) $board->chair_seat_id === (string) $seat->id) {
                $board->forceFill(['chair_seat_id' => null])->save();
            }

            $this->records->publish(
                kind: 'other',
                title: sprintf('Governor term ended — %s, seat %d: renomination open', $department->name, (int) $seat->seat_no),
                body: sprintf(
                    'The %s civil appointment reached its expiry (CLK-09). The seat reopens for '
                    .'F-EXE-001 nomination and F-LEG-020 consent.',
                    $term->ends_on?->toDateString() ?? ''
                ),
                attrs: [
                    'jurisdiction_id' => (string) $department->jurisdiction_id,
                    'via_clock' => 'CLK-09',
                    'subject_type' => 'board_seats',
                    'subject_id' => (string) $seat->id,
                ],
            );
        });
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function legislatureOf(string $jurisdictionId): Legislature
    {
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->first();

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                __('No legislature exists to consent — the BoG pipeline requires the chartering chamber.'),
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
        if (! Str::isUuid($userId)) {
            throw new ConstitutionalViolation(__('F-EXE-001 names an existing nominee.'), 'Art. I');
        }
        $associated = DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $associated || ! User::query()->whereKey($userId)->exists()) {
            throw new ConstitutionalViolation(
                __('F-EXE-001 nominee holds no active association with the jurisdiction — association is the ONLY eligibility check (Art. I; neutrality is a duty of office).'),
                'Art. I'
            );
        }
    }

    /** Validate the vote's exact appointment and current institution before either outcome mutates state. */
    public function assertConsentVote(Appointment $appointment, ChamberVote $vote, string $outcome): void
    {
        [$owner, , $legislature] = $this->appointmentContext($appointment);
        if ($appointment->status !== Appointment::STATUS_NOMINATED
            || $appointment->term_id !== null || $appointment->nominated_via_form !== 'F-EXE-001'
            || (string) $appointment->consent_vote_id !== (string) $vote->id
            || $vote->votable_type !== 'appointment_consent' || (string) $vote->votable_id !== (string) $appointment->id
            || $vote->vote_type !== self::CONSENT_VOTE_TYPE || $vote->body_type !== ChamberVote::BODY_LEGISLATURE
            || $vote->stage !== ChamberVote::STAGE_FLOOR
            || (string) $vote->body_id !== (string) $legislature->id || (string) $vote->legislature_id !== (string) $legislature->id
            || (string) $vote->jurisdiction_id !== (string) $owner->jurisdiction_id
            || $vote->status !== ChamberVote::STATUS_CLOSED || $vote->outcome !== $outcome
            || ! in_array($outcome, [ChamberVote::OUTCOME_ADOPTED, ChamberVote::OUTCOME_FAILED], true)) {
            throw new ConstitutionalViolation(__('Governor consent must resolve the current nomination through its creating legislature\'s recorded vote.'), 'Art. III §4/§5');
        }
    }

    private function appointmentContext(Appointment $appointment): array
    {
        $seat = $appointment->appointable_type === 'board_seats' ? BoardSeat::query()->whereKey($appointment->appointable_id)->first() : null;
        $board = $seat === null ? null : Board::query()->whereKey($seat->board_id)->first();
        if ($board === null) {
            throw new ConstitutionalViolation(__('This nomination no longer names a current governor seat.'), 'Art. III §4/§5');
        }
        [$owner, $board, $legislature] = $this->context($this->ownerOf($board));
        $seat = BoardSeat::query()->whereKey($appointment->appointable_id)->lockForUpdate()->first();
        if ($seat === null || (string) $seat->board_id !== (string) $board->id
            || $seat->seat_class !== BoardSeat::CLASS_GOVERNOR || $seat->status !== BoardSeat::STATUS_NOMINATED
            || (string) $seat->appointment_id !== (string) $appointment->id || $seat->holder_user_id !== null || $seat->term_id !== null) {
            throw new ConstitutionalViolation(__('This nomination has been replaced or its governor seat is no longer vacant.'), 'Art. III §4/§5');
        }

        return [$owner, $board, $legislature, $seat];
    }

    /** Current owner pointer and morph identity must agree; historical boards cannot be appointed. */
    private function context(Department|Organization $owner, bool $requireGovernance = true): array
    {
        $owner = $owner::query()->whereKey($owner->id)->lockForUpdate()->first();
        if ($owner === null || ($owner instanceof Department && $owner->status === Department::STATUS_DISSOLVED)
            || ($owner instanceof Organization && (! $owner->is_cgc || $owner->type !== Organization::TYPE_COMMON_GOOD_CORP
                || $owner->status !== Organization::STATUS_ACTIVE || ! $owner->is_active || $owner->dissolved_at !== null))) {
            throw new ConstitutionalViolation(__('Governor appointments require a current department or active Common Good Corporation.'), 'Art. III §4/§5');
        }
        $board = Board::query()->whereKey($owner->board_id)->lockForUpdate()->first();
        $type = $owner instanceof Department ? Board::BOARDABLE_DEPARTMENTS : Board::BOARDABLE_ORGANIZATIONS;
        if ($board === null || ! in_array($board->status, [Board::STATUS_FORMING, Board::STATUS_ACTIVE], true)
            || $board->boardable_type !== $type || (string) $board->boardable_id !== (string) $owner->id) {
            throw new ConstitutionalViolation(__('Governor appointments require this institution\'s current board.'), 'Art. III §4/§5');
        }
        $legislature = null;
        if ($requireGovernance) {
            if ($owner instanceof Department) {
                $legislature = $this->legislatureOf((string) $owner->jurisdiction_id);
            } else {
                $legislature = Legislature::query()->whereKey($owner->created_by_legislature_id)
                    ->where('jurisdiction_id', $owner->jurisdiction_id)->whereIn('status', [Legislature::STATUS_ACTIVE, Legislature::STATUS_FORMING])->first();
                $executive = Executive::query()->whereKey($owner->overseen_by_executive_id)
                    ->where('jurisdiction_id', $owner->jurisdiction_id)
                    ->whereIn('status', [Executive::STATUS_DELEGATED, Executive::STATUS_ELECTED, Executive::STATUS_CONVERSION_VOTED])->first();
                if ($legislature === null || $executive === null) {
                    throw new ConstitutionalViolation(__('This corporation needs its creating legislature and overseeing executive in the same jurisdiction.'), 'Art. III §5');
                }
            }
        }

        return [$owner, $board, $legislature];
    }

    private function ownerOf(Board $board): Department|Organization
    {
        $owner = match ($board->boardable_type) {
            Board::BOARDABLE_DEPARTMENTS => Department::query()->whereKey($board->boardable_id)->first(),
            Board::BOARDABLE_ORGANIZATIONS => Organization::query()->whereKey($board->boardable_id)->first(),
            default => null,
        };
        if ($owner === null || (string) $owner->board_id !== (string) $board->id) {
            throw new ConstitutionalViolation(__('This is not an institution\'s current board.'), 'Art. III §4/§5');
        }

        return $owner;
    }
}
