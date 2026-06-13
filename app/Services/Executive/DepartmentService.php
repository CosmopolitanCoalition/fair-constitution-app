<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Department;
use App\Models\DepartmentReport;
use App\Models\DepartmentRule;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\Legislature;
use App\Models\PolicyProposal;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\EnactmentService;
use App\Services\PublicRecordService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Departments (PHASE_D_DESIGN_executive §C/§D) — ESM-17 lifecycle:
 * F-LEG-016 creation (charter law + department + unified board), the
 * mandatory-five checklist posture (NEVER auto-seeded — Art. II §9 says
 * legislatures create them; the checklist is the nudge, the engine never
 * blocks), policy proposals (F-EXE-002 — the BOARD decides), rules
 * (F-BOG-001 — versioned, enabling-instrument-bound), and reports
 * (F-BOG-002 — charter-data cadence: due_on + nightly sweep, no new
 * clock code).
 */
class DepartmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly ChamberVoteService $votes,
    ) {
    }

    // =========================================================================
    // F-LEG-016 — creation (WF-EXE-04)
    // =========================================================================

    /**
     * Filing-time payload validation (called by ExecutiveActService
     * before the proposal opens). Returns the normalized payload.
     */
    public static function validateCreationPayload(Legislature $legislature, array $payload): array
    {
        $kind = (string) ($payload['kind'] ?? '');

        if (! in_array($kind, [...Department::MANDATORY_KINDS, Department::KIND_OTHER], true)) {
            throw new ConstitutionalViolation("Unknown department kind [{$kind}].", 'Art. II §9');
        }

        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new ConstitutionalViolation('A department creation act names the department.', 'Art. II §9');
        }

        $executive = Executive::query()->find((string) ($payload['executive_id'] ?? ''));

        if ($executive === null
            || (string) $executive->jurisdiction_id !== (string) $legislature->jurisdiction_id) {
            throw new ConstitutionalViolation(
                'Oversight is assigned to THIS jurisdiction\'s executive (named in the act).',
                'Art. II §9 · Art. III §4'
            );
        }

        if (! in_array($executive->status, [Executive::STATUS_DELEGATED, Executive::STATUS_ELECTED], true)) {
            throw new ConstitutionalViolation(
                "The overseeing executive must be delegated or elected (status: {$executive->status}).",
                'Art. III §1'
            );
        }

        $charter = (array) ($payload['charter'] ?? []);

        if (trim((string) ($charter['function_text'] ?? '')) === '') {
            throw new ConstitutionalViolation(
                'The charter states the department\'s function.',
                'Art. II §9'
            );
        }

        $ownerSeats = (int) ($payload['owner_seats'] ?? 0);

        if ($ownerSeats < 1) {
            throw new ConstitutionalViolation(
                'The charter fixes at least one governor seat.',
                'Art. III §4'
            );
        }

        if ($kind !== Department::KIND_OTHER) {
            $exists = Department::query()
                ->where('jurisdiction_id', $legislature->jurisdiction_id)
                ->where('kind', $kind)
                ->where('status', '!=', Department::STATUS_DISSOLVED)
                ->exists();

            if ($exists) {
                throw new ConstitutionalViolation(
                    "This jurisdiction already has a live [{$kind}] department.",
                    'Art. II §9'
                );
            }
        }

        $interval = $charter['reporting_interval_months'] ?? null;

        return [
            'name'         => $name,
            'kind'         => $kind,
            'executive_id' => (string) $executive->id,
            'charter'      => [
                'function_text'             => (string) $charter['function_text'],
                'powers_text'               => (string) ($charter['powers_text'] ?? ''),
                'reporting_interval_months' => $interval !== null ? (int) $interval : null,
            ],
            'owner_seats'  => $ownerSeats,
            'nominees'     => array_values(array_map('strval', (array) ($payload['nominees'] ?? []))),
        ];
    }

    /**
     * Adoption effect (one transaction): charter law → department
     * (chartered → oversight_assigned immediately — oversight is named in
     * the act) → unified board + vacant governor seats → optional
     * F-EXE-001 nominations → first periodic report row → public record.
     *
     * @return array{0: string, 1: string} [result_type, result_id]
     */
    public function createFromProposal(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;
        $charter     = (array) $payload['charter'];

        $law = $this->enactments->enactDirect(
            $legislature,
            'charter',
            "Department Creation Act — {$payload['name']}",
            trim($charter['function_text'] . "\n\n" . ($charter['powers_text'] ?? '')),
            $vote,
        );

        $department = Department::create([
            'jurisdiction_id'           => (string) $legislature->jurisdiction_id,
            'executive_id'              => (string) $payload['executive_id'],
            'kind'                      => (string) $payload['kind'],
            'name'                      => (string) $payload['name'],
            'charter_law_id'            => (string) $law->id,
            'reporting_interval_months' => $charter['reporting_interval_months'] ?? null,
            'status'                    => Department::STATUS_OVERSIGHT_ASSIGNED,
        ]);

        $board = Board::create([
            'boardable_type' => Board::BOARDABLE_DEPARTMENTS,
            'boardable_id'   => (string) $department->id,
            'owner_seats'    => (int) $payload['owner_seats'],
            'worker_seats'   => 0,
            'status'         => Board::STATUS_FORMING,
        ]);

        for ($no = 1; $no <= (int) $payload['owner_seats']; $no++) {
            BoardSeat::create([
                'board_id'   => (string) $board->id,
                'seat_class' => BoardSeat::CLASS_GOVERNOR,
                'seat_no'    => $no,
                'status'     => BoardSeat::STATUS_VACANT,
            ]);
        }

        $department->forceFill(['board_id' => (string) $board->id])->save();

        // First periodic report obligation (charter-data cadence).
        if ($department->reporting_interval_months !== null) {
            $this->seedNextPeriodicReport($department, CarbonImmutable::now('UTC'));
        }

        // Optional nominations filed with the act (the F-LEG-012 pattern).
        $nominations = [];
        foreach ((array) $payload['nominees'] as $userId) {
            $nominations[] = app(BoardGovernorService::class)->nominateFromAct(
                $department->refresh(),
                (string) $userId,
                $proposal->proposed_by_member_id !== null ? (string) $proposal->proposed_by_member_id : null,
            );
        }

        $this->records->publish(
            kind: 'act',
            title: "Department chartered — {$department->name}",
            body: sprintf(
                'Department %s (%s) chartered by %s; oversight assigned to executive %s; %d governor '
                . 'seat(s) await nomination and consent (F-EXE-001 → F-LEG-020).',
                $department->name,
                $department->kind,
                $law->act_number,
                (string) $department->executive_id,
                (int) $payload['owner_seats']
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-016',
                'subject_type'    => 'departments',
                'subject_id'      => (string) $department->id,
            ],
        );

        $this->audit->append(
            module: 'executive',
            event: 'department.chartered',
            payload: [
                'department_id' => (string) $department->id,
                'board_id'      => (string) $board->id,
                'law_id'        => (string) $law->id,
                'kind'          => $department->kind,
                'owner_seats'   => (int) $payload['owner_seats'],
                'nominations'   => $nominations,
            ],
            ref: 'F-LEG-016',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );

        if ($nominations !== []) {
            $department->forceFill(['status' => Department::STATUS_GOVERNORS_NOMINATED])->save();
        }

        return ['departments', (string) $department->id];
    }

    /**
     * The mandatory-five checklist (Art. II §9 — surface nudge, never an
     * engine block).
     *
     * @return array<string, bool> kind => exists
     */
    public function mandatoryChecklist(string $jurisdictionId): array
    {
        $existing = Department::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('status', '!=', Department::STATUS_DISSOLVED)
            ->pluck('kind')
            ->all();

        $out = [];
        foreach (Department::MANDATORY_KINDS as $kind) {
            $out[$kind] = in_array($kind, $existing, true);
        }

        return $out;
    }

    /**
     * All governor seats seated AND the board composition valid →
     * consented → operating (design §C.2.4). Board flips active.
     */
    public function maybeAdvanceToOperating(Department $department): bool
    {
        $board = $department->board_id !== null ? Board::query()->find($department->board_id) : null;

        if ($board === null || ! $board->composition_valid) {
            return false;
        }

        $vacantGovernors = BoardSeat::query()
            ->where('board_id', $board->id)
            ->where('seat_class', BoardSeat::CLASS_GOVERNOR)
            ->where('status', '!=', BoardSeat::STATUS_SEATED)
            ->exists();

        if ($vacantGovernors) {
            return false;
        }

        $board->forceFill(['status' => Board::STATUS_ACTIVE])->save();
        $department->forceFill(['status' => Department::STATUS_OPERATING])->save();

        $this->audit->append(
            module: 'executive',
            event: 'department.operating',
            payload: [
                'department_id' => (string) $department->id,
                'board_id'      => (string) $board->id,
            ],
            ref: 'WF-EXE-05',
            jurisdictionId: (string) $department->jurisdiction_id,
        );

        return true;
    }

    // =========================================================================
    // F-EXE-002 — policy proposals (the board decides)
    // =========================================================================

    /** File a policy proposal + open the department-board yes/no vote. */
    public function proposePolicy(
        Department $department,
        ExecutiveMember $proposer,
        string $title,
        string $text,
    ): array {
        if ($department->board_id === null) {
            throw new ConstitutionalViolation(
                'The department has no board to decide the proposal — proposals never bypass the board.',
                'Art. III §4'
            );
        }

        if ((string) $proposer->executive_id !== (string) $department->executive_id) {
            throw new ConstitutionalViolation(
                'F-EXE-002 is filed by a member of the OVERSEEING executive.',
                'Art. III §4'
            );
        }

        $proposalRow = PolicyProposal::create([
            'executive_id'          => (string) $department->executive_id,
            'department_id'         => (string) $department->id,
            'proposed_by_member_id' => (string) $proposer->id,
            'title'                 => $title,
            'text'                  => $text,
            'decision'              => PolicyProposal::DECISION_PENDING,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_BOARD,
            bodyId: (string) $department->board_id,
            voteType: 'procedural_motion',
            votable: $proposalRow,
        );

        $proposalRow->forceFill(['board_vote_id' => (string) $vote->id])->save();

        return ['policy_proposal_id' => (string) $proposalRow->id, 'board_vote_id' => (string) $vote->id];
    }

    /** votable_type 'policy_proposal' (vote-engine dispatch, same txn). */
    public function resolvePolicyVote(ChamberVote $vote, string $outcome): void
    {
        $proposal = PolicyProposal::query()->find($vote->votable_id);

        if ($proposal === null || $proposal->decision !== PolicyProposal::DECISION_PENDING) {
            return; // idempotent
        }

        $decision = $outcome === ChamberVote::OUTCOME_ADOPTED
            ? ($proposal->amended_text !== null ? PolicyProposal::DECISION_AMENDED : PolicyProposal::DECISION_ADOPTED)
            : PolicyProposal::DECISION_DECLINED;

        $proposal->forceFill([
            'decision'   => $decision,
            'decided_at' => now(),
        ])->save();

        $department = $proposal->department()->firstOrFail();

        $this->records->publish(
            kind: 'act',
            title: sprintf('Department policy proposal %s — %s', $decision, $proposal->title),
            body: $proposal->amended_text ?? $proposal->text,
            attrs: [
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'via_form'        => 'F-EXE-002',
                'subject_type'    => 'policy_proposals',
                'subject_id'      => (string) $proposal->id,
            ],
        );
    }

    // =========================================================================
    // F-BOG-001 — department rules
    // =========================================================================

    /**
     * File a versioned rule citing a live enabling instrument. The filer
     * is a seated member of THIS department's board (R-18).
     */
    public function fileRule(Department $department, BoardSeat $seat, array $payload): DepartmentRule
    {
        if ((string) $seat->board_id !== (string) $department->board_id
            || $seat->status !== BoardSeat::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'F-BOG-001 is filed by a seated member of THIS department\'s board (R-18).',
                'Art. III §4 · §6'
            );
        }

        $executive = $department->executive()->firstOrFail();

        $enablingType = (string) ($payload['enabling_type'] ?? '');
        $enablingId   = (string) ($payload['enabling_id'] ?? '');

        $instrument = EnablingInstruments::assertLive($enablingType, $enablingId, $executive, $department);

        $supersedes = null;
        $versionNo  = 1;

        if (isset($payload['supersedes_rule_id'])) {
            $supersedes = DepartmentRule::query()
                ->where('department_id', $department->id)
                ->whereKey((string) $payload['supersedes_rule_id'])
                ->first();

            if ($supersedes === null) {
                throw new ConstitutionalViolation(
                    'The superseded rule does not belong to this department.',
                    'Art. III §4'
                );
            }

            $versionNo = (int) $supersedes->version_no + 1;
        }

        $year     = now()->format('Y');
        $sequence = DepartmentRule::query()
            ->where('department_id', $department->id)
            ->where('rule_code', 'like', "%-R-{$year}-%")
            ->withTrashed()
            ->count() + 1;

        $code = sprintf('%s-R-%s-%02d', strtoupper(substr($department->kind, 0, 4)), $year, $sequence);

        $rule = DepartmentRule::create([
            'department_id'         => (string) $department->id,
            'rule_code'             => $code,
            'name'                  => (string) $payload['name'],
            'text'                  => (string) $payload['text'],
            'enabling_type'         => $enablingType,
            'enabling_id'           => $enablingId,
            'expires_with_enabling' => $enablingType === 'emergency_power',
            'version_no'            => $versionNo,
            'supersedes_rule_id'    => $supersedes?->id,
            'filed_by_seat_id'      => (string) $seat->id,
            'status'                => DepartmentRule::STATUS_IN_FORCE,
        ]);

        if ($supersedes !== null) {
            $supersedes->forceFill(['status' => DepartmentRule::STATUS_SUPERSEDED])->save();
        }

        $record = $this->records->publish(
            kind: 'act',
            title: "Department rule filed — {$code}",
            body: (string) $payload['text'],
            attrs: [
                'actor_user_id'   => $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'via_form'        => 'F-BOG-001',
                'subject_type'    => 'department_rules',
                'subject_id'      => (string) $rule->id,
            ],
        );

        $rule->forceFill(['record_id' => (string) $record->id])->save();

        $this->audit->append(
            module: 'executive',
            event: 'department.rule_filed',
            payload: [
                'department_id' => (string) $department->id,
                'rule_id'       => (string) $rule->id,
                'rule_code'     => $code,
                'enabling'      => $instrument,
                'version_no'    => $versionNo,
            ],
            ref: 'F-BOG-001',
            actorId: $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null,
            jurisdictionId: (string) $department->jurisdiction_id,
        );

        return $rule;
    }

    /**
     * CLK-03 cascade hook (called by EmergencyPowerService when a power
     * expires or is struck): rules enabled by the dead power expire with
     * it — nothing rolls over silently.
     */
    public function expireRulesForEmergencyPower(string $powerId): int
    {
        $rules = DepartmentRule::query()
            ->where('enabling_type', 'emergency_power')
            ->where('enabling_id', $powerId)
            ->where('status', DepartmentRule::STATUS_IN_FORCE)
            ->get();

        foreach ($rules as $rule) {
            $rule->forceFill(['status' => DepartmentRule::STATUS_EXPIRED])->save();

            $this->audit->append(
                module: 'executive',
                event: 'department.rule_expired_with_power',
                payload: [
                    'rule_id'   => (string) $rule->id,
                    'rule_code' => $rule->rule_code,
                    'power_id'  => $powerId,
                ],
                ref: 'CLK-03',
                jurisdictionId: (string) $rule->department()->value('jurisdiction_id'),
            );
        }

        return $rules->count();
    }

    // =========================================================================
    // F-BOG-002 — reports (charter-data cadence)
    // =========================================================================

    /** File the due report; create the next periodic obligation. */
    public function fileReport(Department $department, BoardSeat $seat, array $payload): DepartmentReport
    {
        if ((string) $seat->board_id !== (string) $department->board_id
            || $seat->status !== BoardSeat::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'F-BOG-002 is filed by a seated member of THIS department\'s board (R-18).',
                'Art. III §4'
            );
        }

        $kind = (string) ($payload['kind'] ?? DepartmentReport::KIND_PERIODIC);

        $report = null;

        if ($kind === DepartmentReport::KIND_PERIODIC) {
            $report = DepartmentReport::query()
                ->where('department_id', $department->id)
                ->where('kind', DepartmentReport::KIND_PERIODIC)
                ->whereIn('status', [DepartmentReport::STATUS_DUE, DepartmentReport::STATUS_OVERDUE])
                ->orderBy('due_on')
                ->first();
        }

        if ($report === null) {
            $report = DepartmentReport::create([
                'department_id' => (string) $department->id,
                'kind'          => DepartmentReport::KIND_SPECIAL,
                'period_label'  => (string) ($payload['period_label'] ?? 'special'),
                'due_on'        => now()->toDateString(),
                'status'        => DepartmentReport::STATUS_DUE,
            ]);
        }

        $record = $this->records->publish(
            kind: 'other',
            title: sprintf('Department report filed — %s (%s)', $department->name, $report->period_label ?? $report->kind),
            body: (string) ($payload['body'] ?? ''),
            attrs: [
                'actor_user_id'   => $seat->holder_user_id !== null ? (string) $seat->holder_user_id : null,
                'jurisdiction_id' => (string) $department->jurisdiction_id,
                'via_form'        => 'F-BOG-002',
                'subject_type'    => 'department_reports',
                'subject_id'      => (string) $report->id,
            ],
        );

        $report->forceFill([
            'status'           => DepartmentReport::STATUS_FILED,
            'filed_at'         => now(),
            'filed_by_seat_id' => (string) $seat->id,
            'record_id'        => (string) $record->id,
        ])->save();

        // The cadence loop: filing a periodic report seeds the next one.
        if ($report->kind === DepartmentReport::KIND_PERIODIC && $department->reporting_interval_months !== null) {
            $this->seedNextPeriodicReport($department, CarbonImmutable::parse($report->due_on));
        }

        if ($department->status === Department::STATUS_OPERATING) {
            $department->forceFill(['status' => Department::STATUS_REPORTING])->save();
        }

        return $report->refresh();
    }

    /** Nightly sweep: due_on passed without filing → overdue. */
    public function sweepOverdueReports(): int
    {
        return DepartmentReport::query()
            ->where('status', DepartmentReport::STATUS_DUE)
            ->where('due_on', '<', now()->toDateString())
            ->update(['status' => DepartmentReport::STATUS_OVERDUE, 'updated_at' => now()]);
    }

    private function seedNextPeriodicReport(Department $department, CarbonImmutable $from): DepartmentReport
    {
        $due = $from->addMonthsNoOverflow((int) $department->reporting_interval_months);

        return DepartmentReport::create([
            'department_id' => (string) $department->id,
            'kind'          => DepartmentReport::KIND_PERIODIC,
            'period_label'  => $due->format('Y-m'),
            'due_on'        => $due->toDateString(),
            'status'        => DepartmentReport::STATUS_DUE,
        ]);
    }
}
