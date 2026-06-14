<?php

namespace App\Services\Legislature;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\AdminOffice;
use App\Models\Appointment;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ElectionBoard;
use App\Models\ElectionBoardMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Term;
use App\Services\ChamberVoteService;
use App\Services\ClockService;
use App\Services\EnactmentService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Chamber-ops act votes (chamber ops §D/§E): the proposal → chamber-vote →
 * adoption-effect loop for F-LEG-009/012/013/032/033, plus the
 * appointment-consent pipeline (election board seats, admin office staff)
 * riding the Phase B `appointments` table.
 *
 * The vote ENGINE owns lanes/thresholds/outcomes; this service owns what
 * an adopted act DOES. Proposals live in `chamber_vote_proposals` (the
 * institution row is created only on adoption — a failed vote leaves a
 * rejected proposal, never a half-born institution). Casts ride F-LEG-004;
 * the engine auto-closes at full participation and its votable-effect
 * dispatch routes back here (resolveProposalVote / resolveConsentVote) in
 * the SAME transaction as the closing cast.
 *
 * Vote-type keys (config/constitution/vote_types.php): committee_create
 * (supermajority) for F-LEG-009; procedural_motion (the "unstated votes =
 * ordinary majority of all serving" owner ruling) for F-LEG-013,
 * F-LEG-032/033 and appointment consents. F-LEG-012 is supermajority-class
 * per the chamber-ops design but the 33-row registry carries no dedicated
 * key — FLAGGED REGISTRY GAP: opened under committee_create (identical
 * threshold class) with the proposal kind distinguishing the act.
 */
class ChamberActService
{
    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly CommitteeService $committees,
        private readonly ElectionBoardTransitionService $boardTransition,
        private readonly SettingsResolver $settings,
        private readonly ClockService $clocks,
        private readonly RoleService $roles,
    ) {}

    // =========================================================================
    // Proposals (filed by the F-LEG-012/013/032/033 handlers;
    // F-LEG-009 routes through CommitteeService::proposeCreation)
    // =========================================================================

    /**
     * F-LEG-012 — Election Board Creation Act (supermajority). Nominees
     * optional at filing; eligibility = active association in the
     * jurisdiction (Art. I — the only check; independence is a duty of
     * the office, not an eligibility test).
     *
     * @param  list<string>  $nominees  user ids
     */
    public function proposeElectionBoard(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $jurisdictionId,
        array $nominees,
    ): array {
        if ($jurisdictionId !== (string) $legislature->jurisdiction_id) {
            throw new ConstitutionalViolation(
                'A legislature constitutes the election board of ITS OWN jurisdiction.',
                'CGA Forms Catalog (F-LEG-012)'
            );
        }

        foreach ($nominees as $userId) {
            $this->assertNomineeAssociation((string) $userId, $jurisdictionId, 'F-LEG-012');
        }

        $existingProper = ElectionBoard::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_bootstrap', false)
            ->whereIn('status', [ElectionBoard::STATUS_FORMING, ElectionBoard::STATUS_ACTIVE])
            ->exists();

        if ($existingProper) {
            throw new ConstitutionalViolation(
                'A proper election board already exists (forming or active) for this jurisdiction.',
                'WF-ELE-10'
            );
        }

        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_ELECTION_BOARD_CREATION,
            ['jurisdiction_id' => $jurisdictionId, 'nominees' => array_values(array_map('strval', $nominees))],
            'committee_create', // supermajority class — registry gap, see class docblock
        );
    }

    /**
     * F-LEG-013 — Administrative Office Creation Act (ordinary majority —
     * unstated threshold = majority of all serving). One live office per
     * legislature.
     *
     * @param  list<string>  $nominees  optional staff nominee user ids
     */
    public function proposeAdminOffice(Legislature $legislature, LegislatureMember $proposer, array $nominees = []): array
    {
        $existing = AdminOffice::query()
            ->where('legislature_id', $legislature->id)
            ->where('status', '!=', AdminOffice::STATUS_DISSOLVED)
            ->exists();

        if ($existing) {
            throw new ConstitutionalViolation(
                'This legislature already has a live administrative office.',
                'CGA Forms Catalog (F-LEG-013)'
            );
        }

        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_ADMIN_OFFICE_CREATION,
            ['nominees' => array_values(array_map('strval', $nominees))],
            'procedural_motion',
        );
    }

    /**
     * F-LEG-032/033 — Rules of Order / Ethics Code adoption (ordinary
     * majority; direct-adoption path — the act becomes a LAW via
     * EnactmentService::enactDirect on adoption; re-adoption appends a
     * version, never edits).
     */
    public function proposeDirectLaw(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $kind,
        string $title,
        string $text,
    ): array {
        if (! in_array($kind, [ChamberVoteProposal::KIND_RULES_OF_ORDER, ChamberVoteProposal::KIND_ETHICS_CODE], true)) {
            throw new ConstitutionalViolation("Unknown direct-adoption law kind [{$kind}].", 'CGA Forms Catalog');
        }

        if (trim($title) === '' || trim($text) === '') {
            throw new ConstitutionalViolation(
                'Rules/Ethics adoption requires a non-empty title and text.',
                'CGA Forms Catalog (F-LEG-032/033)'
            );
        }

        return $this->propose(
            $legislature,
            $proposer,
            $kind,
            ['title' => $title, 'text' => $text],
            'procedural_motion',
        );
    }

    /**
     * F-LEG-028 — Cultural Institution Recognition (Art. V §2, supermajority).
     * On adoption a POWERLESS row is recorded.
     */
    public function proposeCulturalInstitution(Legislature $legislature, LegislatureMember $proposer, string $name, ?string $description): array
    {
        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_CULTURAL_INSTITUTION,
            ['name' => $name, 'description' => $description],
            'cultural_institution',
        );
    }

    /**
     * F-LEG-029 — Union Formation/Join Vote (Art. V §7). The initiating chamber
     * supermajority OPENS the dual-meter ratification on adoption (applicant
     * referendum + constituent MJV). Supermajority class via the committee_create
     * registry-gap key (the F-LEG-012 precedent).
     *
     * @param  list<string>  $applicantIds
     * @param  list<string>  $constituentIds
     */
    public function proposeUnion(Legislature $legislature, LegislatureMember $proposer, string $kind, array $applicantIds, array $constituentIds, ?string $unionJurisdictionId): array
    {
        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_UNION,
            [
                'kind' => $kind,
                'applicant_ids' => array_values(array_map('strval', $applicantIds)),
                'constituent_ids' => array_values(array_map('strval', $constituentIds)),
                'union_jurisdiction_id' => $unionJurisdictionId,
            ],
            'committee_create',
        );
    }

    /**
     * F-LEG-030 — Disintermediation Vote (Art. V §8). The initiating chamber
     * supermajority OPENS the UNANIMITY constituent MJV on adoption.
     *
     * @param  list<string>  $constituentIds
     */
    public function proposeDisintermediation(Legislature $legislature, LegislatureMember $proposer, string $intermediaryId, string $encompassingId, array $constituentIds): array
    {
        return $this->propose(
            $legislature,
            $proposer,
            ChamberVoteProposal::KIND_DISINTERMEDIATION,
            [
                'intermediary_id' => $intermediaryId,
                'encompassing_id' => $encompassingId,
                'constituent_ids' => array_values(array_map('strval', $constituentIds)),
            ],
            'committee_create',
        );
    }

    // =========================================================================
    // Vote-close side-effects (ChamberVoteService dispatch — same txn)
    // =========================================================================

    /** Deadline/presiding close for an act vote (the all-cast path closes itself). */
    public function closeActVote(ChamberVote $vote, ?LegislatureMember $closer = null): ChamberVote
    {
        return $this->votes->close($vote, $closer);
    }

    /** votable_type 'chamber_vote_proposal'. */
    public function resolveProposalVote(ChamberVote $vote, string $outcome): void
    {
        $proposal = ChamberVoteProposal::query()->find($vote->votable_id);

        if ($proposal === null || $proposal->status !== ChamberVoteProposal::STATUS_OPEN) {
            return; // idempotent
        }

        if ($outcome !== ChamberVote::OUTCOME_ADOPTED) {
            $proposal->forceFill([
                'status' => ChamberVoteProposal::STATUS_REJECTED,
                'decided_at' => now(),
            ])->save();

            // Phase E (Path 2): a FAILED override leaves the challenge
            // legislative_window_open — Path 1/3 stay available, the clocks
            // keep running (§5.4). Record the failure for the docket.
            if ($proposal->proposal_kind === ChamberVoteProposal::KIND_JUDICIARY_OVERRIDE) {
                app(\App\Services\Judiciary\JudiciaryOverrideService::class)->noteOverrideFailed($proposal);
            }

            return;
        }

        $this->applyProposalAdoption($vote, $proposal);
    }

    /** votable_type 'appointment_consent'. */
    public function resolveConsentVote(ChamberVote $vote, string $outcome): void
    {
        $appointment = Appointment::query()->find($vote->votable_id);

        if ($appointment === null || $appointment->status !== Appointment::STATUS_NOMINATED) {
            return; // idempotent
        }

        if ($outcome !== ChamberVote::OUTCOME_ADOPTED) {
            $appointment->forceFill(['status' => Appointment::STATUS_REJECTED])->save();

            // Phase D: a rejected governor nomination reopens its board
            // seat for renomination (the WF-EXE-05 loop).
            if ($appointment->appointable_type === 'board_seats') {
                app(\App\Services\Executive\BoardGovernorService::class)->handleRejectedNomination($appointment);
            }

            // Phase E: a rejected judge nomination reopens its judicial seat
            // for renomination (the §B.3 loop).
            if ($appointment->appointable_type === 'judicial_seats') {
                app(\App\Services\Judiciary\JudicialSeatService::class)->handleRejectedNomination($appointment);
            }

            return;
        }

        match ($appointment->appointable_type) {
            'election_boards' => $this->seatBoardAppointment($appointment),
            'admin_offices' => $this->seatOfficeAppointment($appointment),
            // Phase D (PHASE_D_DESIGN_executive §C.2/§E.2): board-governor
            // consent (F-EXE-001 → F-LEG-020 via bog_consent) and the thin
            // R-30 department-staff slice ride the SAME consent pipeline.
            'board_seats' => app(\App\Services\Executive\BoardGovernorService::class)->seat($appointment),
            'departments' => app(\App\Services\Executive\BoardGovernorService::class)->seatCivilOfficer($appointment),
            // Phase E (PHASE_E_DESIGN_judiciary §B.3): judicial nomination
            // consent (F-LEG-021 via bog_consent) seats a judge with a
            // 10-year civil-appointment term — the SAME consent pipeline.
            'judicial_seats' => app(\App\Services\Judiciary\JudicialSeatService::class)->seat($appointment),
            default => throw new ConstitutionalViolation(
                'Chamber-ops consent knows election_boards, admin_offices, board_seats, departments, '
                ."and judicial_seats targets, not [{$appointment->appointable_type}].",
                'WF-SYS-04'
            ),
        };
    }

    // =========================================================================
    // Adoption effects
    // =========================================================================

    private function applyProposalAdoption(ChamberVote $vote, ChamberVoteProposal $proposal): void
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload = (array) $proposal->payload;

        [$resultType, $resultId] = match ($proposal->proposal_kind) {
            ChamberVoteProposal::KIND_COMMITTEE_CREATION => (function () use ($proposal) {
                $committee = $this->committees->createFromProposal($proposal);

                return ['committees', (string) $committee->id];
            })(),

            ChamberVoteProposal::KIND_ELECTION_BOARD_CREATION => (function () use ($proposal, $legislature, $payload) {
                $board = ElectionBoard::create([
                    'jurisdiction_id' => (string) $payload['jurisdiction_id'],
                    'legislature_id' => (string) $legislature->id,
                    'is_bootstrap' => false,
                    'status' => ElectionBoard::STATUS_FORMING,
                ]);

                $appointments = $this->nominate(
                    $legislature,
                    'election_boards',
                    (string) $board->id,
                    (array) ($payload['nominees'] ?? []),
                    'F-LEG-012',
                    $proposal->proposed_by_member_id,
                );

                $this->records->publish(
                    kind: 'act',
                    title: 'Proper election board constituted (forming)',
                    body: sprintf(
                        'Board %s constituted by supermajority act of legislature %s; %d nominee(s) await '
                        .'consent. The bootstrap board retires when the proper board seats (WF-ELE-10).',
                        (string) $board->id,
                        (string) $legislature->id,
                        count($appointments)
                    ),
                    attrs: [
                        'jurisdiction_id' => (string) $payload['jurisdiction_id'],
                        'legislature_id' => (string) $legislature->id,
                        'via_form' => 'F-LEG-012',
                        'subject_type' => 'election_boards',
                        'subject_id' => (string) $board->id,
                    ],
                );

                return ['election_boards', (string) $board->id];
            })(),

            ChamberVoteProposal::KIND_ADMIN_OFFICE_CREATION => (function () use ($proposal, $legislature, $payload, $vote) {
                $office = AdminOffice::create([
                    'legislature_id' => (string) $legislature->id,
                    'created_by_vote_id' => (string) $vote->id,
                    'status' => AdminOffice::STATUS_CREATED,
                ]);

                $appointments = $this->nominate(
                    $legislature,
                    'admin_offices',
                    (string) $office->id,
                    (array) ($payload['nominees'] ?? []),
                    'F-LEG-013',
                    $proposal->proposed_by_member_id,
                );

                $this->records->publish(
                    kind: 'act',
                    title: 'Administrative office created',
                    body: sprintf(
                        'Office %s created by majority act; %d staff nominee(s) await consent.',
                        (string) $office->id,
                        count($appointments)
                    ),
                    attrs: [
                        'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                        'legislature_id' => (string) $legislature->id,
                        'via_form' => 'F-LEG-013',
                        'subject_type' => 'admin_offices',
                        'subject_id' => (string) $office->id,
                    ],
                );

                return ['admin_offices', (string) $office->id];
            })(),

            ChamberVoteProposal::KIND_RULES_OF_ORDER,
            ChamberVoteProposal::KIND_ETHICS_CODE => (function () use ($proposal, $legislature, $payload, $vote) {
                $law = $this->enactments->enactDirect(
                    $legislature,
                    $proposal->proposal_kind, // 'rules_of_order' | 'ethics_code' — laws.kind enum
                    (string) $payload['title'],
                    (string) $payload['text'],
                    $vote,
                );

                return ['laws', (string) $law->id];
            })(),

            // ── Votes-laws batch 2 (C-8/C-9) ────────────────────────────────
            ChamberVoteProposal::KIND_REFERENDUM_DELEGATION => (function () use ($proposal, $vote) {
                $question = app(\App\Services\ReferendumService::class)->createFromDelegation($proposal, $vote);

                return ['referendum_questions', (string) $question->id];
            })(),

            ChamberVoteProposal::KIND_REFERENDUM_ACT_MODIFICATION => (function () use ($proposal, $vote) {
                $law = app(\App\Services\ReferendumService::class)->applyModification($proposal, $vote);

                return ['laws', (string) $law->id];
            })(),

            ChamberVoteProposal::KIND_EMERGENCY_INVOCATION => (function () use ($proposal, $vote) {
                $power = app(\App\Services\EmergencyPowerService::class)->activateFromProposal($proposal, $vote);

                return ['emergency_powers', (string) $power->id];
            })(),

            ChamberVoteProposal::KIND_EMERGENCY_RENEWAL => (function () use ($proposal, $vote) {
                $renewal = app(\App\Services\EmergencyPowerService::class)->renewFromProposal($proposal, $vote);

                return ['emergency_power_renewals', (string) $renewal->id];
            })(),

            // ── Phase D executive scope (PHASE_D_DESIGN_executive §B/§C):
            // F-LEG-014 / F-LEG-015 / F-LEG-016 adoption effects ─────────
            ChamberVoteProposal::KIND_EXEC_DELEGATION => app(\App\Services\Executive\ExecutiveFormationService::class)->applyDelegation($proposal, $vote),

            ChamberVoteProposal::KIND_EXEC_CONVERSION => app(\App\Services\Executive\ExecutiveFormationService::class)->applyConversionAdoption($proposal, $vote),

            ChamberVoteProposal::KIND_DEPARTMENT_CREATION => app(\App\Services\Executive\DepartmentService::class)->createFromProposal($proposal, $vote),

            // ── Phase D organizations scope (PHASE_D_DESIGN_organizations
            // §D.2): F-LEG-019 / F-LEG-026 / F-LEG-027 adoption effects ──
            ChamberVoteProposal::KIND_CGC_CREATION => app(\App\Services\Organizations\CgcService::class)->adoptCreation($vote, $proposal),

            ChamberVoteProposal::KIND_MONOPOLY_ACQUISITION => app(\App\Services\Organizations\OrgConversionService::class)->adoptMonopolyAcquisition($vote, $proposal),

            ChamberVoteProposal::KIND_CGC_REORG_SALE => app(\App\Services\Organizations\OrgConversionService::class)->adoptReorgSale($vote, $proposal),

            // ── Phase E judiciary scope (PHASE_E_DESIGN_judiciary §B):
            // F-LEG-017 creation / F-LEG-018 conversion adoption effects
            // (the EXEC_KINDS precedent — dispatched to
            // JudiciaryFormationService) ────────────────────────────────
            ChamberVoteProposal::KIND_JUDICIARY_CREATION => app(\App\Services\Judiciary\JudiciaryFormationService::class)->applyCreation($proposal, $vote),

            ChamberVoteProposal::KIND_JUDICIARY_CONVERSION => app(\App\Services\Judiciary\JudiciaryFormationService::class)->applyConversionAdoption($proposal, $vote),

            // ── Phase E challenge & law (PHASE_E_DESIGN_challenge_law §B.5):
            // F-LEG-035 supermajority override of a constitutional finding
            // (Path 2). On adoption within CLK-11 the law stands UNCHANGED and
            // the challenge closes `overridden` (returns the result tuple). ──
            ChamberVoteProposal::KIND_JUDICIARY_OVERRIDE => array_values(app(\App\Services\Judiciary\JudiciaryOverrideService::class)->resolveOverrideAdoption($vote, $proposal)),

            // ── Phase F — the four jurisdiction processes (resolved by the
            // Jurisdictions services): F-LEG-028 recognizes a powerless cultural
            // institution; F-LEG-029/030 OPEN the dual-meter / unanimity process
            // on adoption (the constituent MJV + referendum then run). ─────────
            ChamberVoteProposal::KIND_CULTURAL_INSTITUTION => app(\App\Services\Jurisdictions\CulturalInstitutionService::class)->adoptRecognition($proposal, $vote),

            ChamberVoteProposal::KIND_UNION => app(\App\Services\Jurisdictions\UnionService::class)->adoptOpen($proposal, $vote),

            ChamberVoteProposal::KIND_DISINTERMEDIATION => app(\App\Services\Jurisdictions\DisintermediationService::class)->adoptOpen($proposal, $vote),

            default => throw new ConstitutionalViolation(
                "Unknown proposal kind [{$proposal->proposal_kind}].",
                'WF-SYS-04'
            ),
        };

        $proposal->forceFill([
            'status' => ChamberVoteProposal::STATUS_ADOPTED,
            'decided_at' => now(),
            'result_type' => $resultType,
            'result_id' => $resultId,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function seatBoardAppointment(Appointment $appointment): array
    {
        $board = ElectionBoard::query()->whereKey($appointment->appointable_id)->firstOrFail();

        $starts = CarbonImmutable::now('UTC')->startOfDay();
        $years = $this->settings->resolveInt((string) $board->jurisdiction_id, 'civil_appointment_years', 10);
        $ends = $starts->addYears($years);

        $memberRow = ElectionBoardMember::create([
            'election_board_id' => $board->id,
            'user_id' => $appointment->nominee_user_id,
            'appointment_id' => $appointment->id,
            'status' => 'seated',
            'term_starts_on' => $starts->toDateString(),
            'term_ends_on' => $ends->toDateString(),
        ]);

        $term = $this->openCivilTerm(
            officeKind: 'election_board_member',
            officeType: 'election_board_members',
            officeId: (string) $memberRow->id,
            holderUserId: (string) $appointment->nominee_user_id,
            jurisdictionId: (string) $board->jurisdiction_id,
            legislatureId: $board->legislature_id !== null ? (string) $board->legislature_id : null,
            appointment: $appointment,
            starts: $starts,
            ends: $ends,
        );

        $this->records->publish(
            kind: 'certification',
            title: 'Election board member seated',
            body: sprintf(
                'Appointee %s seated on board %s (civil appointment, %d years — Art. II §9).',
                (string) $appointment->nominee_user_id,
                (string) $board->id,
                $years
            ),
            attrs: [
                'actor_user_id' => (string) $appointment->nominee_user_id,
                'jurisdiction_id' => (string) $board->jurisdiction_id,
                'legislature_id' => $board->legislature_id !== null ? (string) $board->legislature_id : null,
                'via_form' => 'F-LEG-012',
                'subject_type' => 'election_board_members',
                'subject_id' => (string) $memberRow->id,
            ],
        );

        $this->roles->flushUser((string) $appointment->nominee_user_id);

        // WF-ELE-10: the proper board may now be ready.
        $transition = $this->boardTransition->maybeTransition($board->refresh());

        return [
            'appointment_id' => (string) $appointment->id,
            'board_member_id' => (string) $memberRow->id,
            'term_id' => (string) $term->id,
            'transitioned' => $transition !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function seatOfficeAppointment(Appointment $appointment): array
    {
        $office = AdminOffice::query()->whereKey($appointment->appointable_id)->firstOrFail();
        $legislature = $office->legislature()->firstOrFail();

        $starts = CarbonImmutable::now('UTC')->startOfDay();
        $years = $this->settings->resolveInt((string) $legislature->jurisdiction_id, 'civil_appointment_years', 10);
        $ends = $starts->addYears($years);

        $term = $this->openCivilTerm(
            officeKind: 'admin_staff',
            officeType: 'appointments',
            officeId: (string) $appointment->id,
            holderUserId: (string) $appointment->nominee_user_id,
            jurisdictionId: (string) $legislature->jurisdiction_id,
            legislatureId: (string) $legislature->id,
            appointment: $appointment,
            starts: $starts,
            ends: $ends,
        );

        if ($office->status === AdminOffice::STATUS_CREATED) {
            $office->forceFill(['status' => AdminOffice::STATUS_STAFFED])->save();
        }

        $this->records->publish(
            kind: 'certification',
            title: 'Administrative office staffed',
            body: sprintf(
                'Appointee %s seated at office %s (civil appointment, %d years — Art. II §9).',
                (string) $appointment->nominee_user_id,
                (string) $office->id,
                $years
            ),
            attrs: [
                'actor_user_id' => (string) $appointment->nominee_user_id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-013',
                'subject_type' => 'admin_offices',
                'subject_id' => (string) $office->id,
            ],
        );

        $this->roles->flushUser((string) $appointment->nominee_user_id);

        return [
            'appointment_id' => (string) $appointment->id,
            'office_id' => (string) $office->id,
            'term_id' => (string) $term->id,
        ];
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function propose(
        Legislature $legislature,
        LegislatureMember $proposer,
        string $kind,
        array $payload,
        string $voteType,
    ): array {
        $proposal = ChamberVoteProposal::create([
            'legislature_id' => $legislature->id,
            'proposal_kind' => $kind,
            'payload' => $payload,
            'proposed_by_member_id' => $proposer->id,
            'status' => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: $voteType,
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /**
     * Create nominated appointments + open one consent vote per nominee
     * (ordinary majority — unstated threshold rule).
     *
     * @param  list<string>  $nominees
     * @return list<array{appointment_id: string, consent_vote_id: string, nominee_user_id: string}>
     */
    private function nominate(
        Legislature $legislature,
        string $appointableType,
        string $appointableId,
        array $nominees,
        string $viaForm,
        ?string $nominatedByMemberId,
    ): array {
        $out = [];

        $nominatedBy = $nominatedByMemberId !== null
            ? LegislatureMember::query()->whereKey($nominatedByMemberId)->value('user_id')
            : null;

        foreach ($nominees as $userId) {
            $appointment = Appointment::create([
                'appointable_type' => $appointableType,
                'appointable_id' => $appointableId,
                'nominee_user_id' => (string) $userId,
                'nominated_by' => $nominatedBy !== null ? (string) $nominatedBy : null,
                'nominated_via_form' => $viaForm,
                'status' => Appointment::STATUS_NOMINATED,
            ]);

            $vote = $this->votes->open(
                bodyType: ChamberVote::BODY_LEGISLATURE,
                bodyId: (string) $legislature->id,
                voteType: 'procedural_motion',
                votable: $appointment,
                stage: ChamberVote::STAGE_FLOOR,
            );

            $appointment->forceFill(['consent_vote_id' => (string) $vote->id])->save();

            $out[] = [
                'appointment_id' => (string) $appointment->id,
                'consent_vote_id' => (string) $vote->id,
                'nominee_user_id' => (string) $userId,
            ];
        }

        return $out;
    }

    /**
     * Phase D refactor (PHASE_D_DESIGN_executive §C.2): the term-opening
     * mechanics moved VERBATIM to the shared CivilAppointmentService so
     * board governors ride the SAME CLK-09 path. Identical signature,
     * zero behavioral change to the Phase C call sites.
     */
    private function openCivilTerm(
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
        return app(\App\Services\CivilAppointmentService::class)->openCivilTerm(
            officeKind: $officeKind,
            officeType: $officeType,
            officeId: $officeId,
            holderUserId: $holderUserId,
            jurisdictionId: $jurisdictionId,
            legislatureId: $legislatureId,
            appointment: $appointment,
            starts: $starts,
            ends: $ends,
        );
    }

    private function assertNomineeAssociation(string $userId, string $jurisdictionId, string $formId): void
    {
        $associated = DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $associated) {
            throw new ConstitutionalViolation(
                "{$formId} nominee [{$userId}] holds no active association with the jurisdiction — "
                .'association is the only eligibility check (Art. I).',
                'Art. I'
            );
        }
    }
}
