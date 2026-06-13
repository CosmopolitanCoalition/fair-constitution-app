<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ConstituentConsent;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MultiJurisdictionVote;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\ElectionLifecycleService;
use App\Services\EnactmentService;
use App\Services\Legislature\CommitteeAssignmentService;
use App\Services\Legislature\CommitteeService;
use App\Services\MultiJurisdictionVoteService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * Executive formation (PHASE_D_DESIGN_executive §B) — delegation
 * (F-LEG-014, WF-EXE-01), conversion to elected office (F-LEG-015,
 * WF-EXE-02 — the FIRST live MultiJurisdictionVoteService consumer),
 * alteration (WF-EXE-03), succession, and the delegated-member lifecycle.
 *
 * PROPORTIONAL SELECTION REUSE (Art. III §2 — "in the same manner as
 * legislative committees"): the delegated executive is modeled as ONE
 * synthetic committee of n seats and selection runs through the existing
 * pure CommitteeAssignmentService::assign() — bicameral kind-split via
 * CommitteeService::kindSplit(), extras to the highest normalized vote
 * shares (#q2 currency). NO parallel selection math exists in this class
 * (pinned by ExecDelegationProportionalityTest).
 */
class ExecutiveFormationService
{
    /** The synthetic single-committee id fed to assign(). */
    public const SYNTHETIC_COMMITTEE = 'executive';

    public function __construct(
        private readonly AuditService $audit,
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly MultiJurisdictionVoteService $processes,
        private readonly ChamberVoteService $votes,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // Pure constitutional asserts (DB-free — pinned by the test suite)
    // =========================================================================

    /**
     * Art. III §2 — a delegated executive committee carries at least 5
     * members; it cannot exceed the chamber that delegates it.
     */
    public static function assertDelegationSize(int $memberCount, int $serving): void
    {
        if ($memberCount < 5) {
            throw new ConstitutionalViolation(
                "A delegated executive committee has at least 5 members (got {$memberCount}).",
                'Art. III §2'
            );
        }

        if ($memberCount > $serving) {
            throw new ConstitutionalViolation(
                "The delegated committee ({$memberCount}) cannot exceed the chamber's serving members ({$serving}).",
                'Art. III §2'
            );
        }
    }

    /**
     * Art. III §3 — conversion target: committee model floors at 5 (no
     * ceiling); individual model is exactly one office.
     */
    public static function assertConversionTarget(string $targetType, ?int $memberCount): void
    {
        if (! in_array($targetType, [Executive::TYPE_COMMITTEE, Executive::TYPE_INDIVIDUAL], true)) {
            throw new ConstitutionalViolation(
                "Unknown executive target type [{$targetType}].",
                'Art. III §2 · §3'
            );
        }

        if ($targetType === Executive::TYPE_COMMITTEE && ($memberCount === null || $memberCount < 5)) {
            throw new ConstitutionalViolation(
                'An elected executive committee has at least 5 members (Art. III §2 floors the '
                . 'committee model at 5; there is no ceiling).',
                'Art. III §2'
            );
        }
    }

    // =========================================================================
    // F-LEG-014 — delegation adoption (WF-EXE-01)
    // =========================================================================

    /**
     * Adoption effect (vote-engine dispatch, same txn): creation-act law,
     * executive forming → delegated, proportional member selection via
     * CommitteeAssignmentService::assign().
     *
     * @return array{0: string, 1: string} [result_type, result_id]
     */
    public function applyDelegation(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;

        $executive = Executive::query()->whereKey((string) $payload['executive_id'])->firstOrFail();
        $n         = (int) $payload['member_count'];
        $scope     = (string) $payload['delegated_scope'];

        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->get();

        self::assertDelegationSize($n, $serving->count());

        $law = $this->enactments->enactDirect(
            $legislature,
            'creation_act',
            'Executive Committee Delegation Act',
            $scope,
            $vote,
        );

        $selection = $this->selectDelegatedMembers($legislature, $serving, $n);

        $memberRows = [];
        $today      = now()->toDateString();

        foreach ($selection['placements'] as $placement) {
            $member = $serving->firstWhere('id', $placement['member_id']);

            $row = ExecutiveMember::create([
                'executive_id'          => $executive->id,
                'user_id'               => $member->user_id,
                'role'                  => ExecutiveMember::ROLE_PRINCIPAL,
                'rank'                  => 0,
                'joined_at'             => $today,
                'legislature_member_id' => $member->id,
                // Ex officio: the member's term IS their legislative
                // seat's term — term_id stays NULL by design (ESM-16).
                'term_id'               => null,
                'selection'             => ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL,
                'status'                => ExecutiveMember::STATUS_SEATED,
            ]);

            $memberRows[] = [
                'executive_member_id'   => (string) $row->id,
                'legislature_member_id' => (string) $member->id,
                'user_id'               => (string) $member->user_id,
                'seat_kind'             => $placement['seat_kind'],
            ];

            $this->roles->flushUser((string) $member->user_id);
        }

        $executive->forceFill([
            'status'                 => Executive::STATUS_DELEGATED,
            'type'                   => Executive::TYPE_COMMITTEE,
            'delegation_law_id'      => $law->id,
            'delegated_scope'        => $scope,
            'delegated_member_count' => $n,
            'source_legislature_id'  => $legislature->id,
        ])->save();

        // Full selection snapshot is the audit payload (F-SPK-005 posture).
        $this->audit->append(
            module: 'executive',
            event: 'executive.delegated',
            payload: [
                'executive_id' => (string) $executive->id,
                'law_id'       => (string) $law->id,
                'member_count' => $n,
                'members'      => $memberRows,
                'selection'    => $selection,
            ],
            ref: 'F-LEG-014',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );

        $this->records->publish(
            kind: 'act',
            title: 'Executive authority delegated to committee',
            body: sprintf(
                "Executive %s delegated by supermajority act %s (Art. III §1–2): %d members selected "
                . "in the same manner as legislative committees. Scope: %s",
                (string) $executive->id,
                $law->act_number,
                $n,
                $scope
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-014',
                'subject_type'    => 'executives',
                'subject_id'      => (string) $executive->id,
            ],
        );

        return ['executives', (string) $executive->id];
    }

    /**
     * The Art. III §2 selection: ONE synthetic committee of n seats run
     * through the pure assign() — zero new selection math.
     *
     * @param  \Illuminate\Support\Collection<int, LegislatureMember>  $serving
     * @return array{placements: list<array>, partitions: array, contests: list<array>, exhaustion: list<array>}
     */
    private function selectDelegatedMembers(Legislature $legislature, $serving, int $n): array
    {
        $bicameral = (int) $legislature->type_b_seats > 0;

        if ($bicameral) {
            $servingA = $serving->filter(fn ($m) => $m->seatKind() === 'type_a')->count();
            $servingB = $serving->filter(fn ($m) => $m->seatKind() === 'type_b')->count();

            // Art. V §3 mirror — the same split committees use.
            $split = CommitteeService::kindSplit($n, $servingA, $servingB);

            $committeeInput = [self::SYNTHETIC_COMMITTEE => [
                'type_a' => $split['type_a'],
                'type_b' => $split['type_b'],
            ]];
        } else {
            $committeeInput = [self::SYNTHETIC_COMMITTEE => ['all' => $n]];
        }

        $memberInput = [];
        foreach ($serving as $member) {
            $memberInput[(string) $member->id] = [
                'kind'    => $bicameral ? $member->seatKind() : 'all',
                'share'   => (int) round(((float) ($member->vote_share_norm ?? 0)) * CommitteeAssignmentService::SHARE_SCALE),
                'seat_no' => $member->seat_no !== null ? (int) $member->seat_no : null,
            ];
        }

        // One committee ⇒ preference order is constant; the n placements
        // fall to the highest normalized-quota vote shares (#q2).
        return CommitteeAssignmentService::assign($committeeInput, $memberInput, []);
    }

    // =========================================================================
    // F-LEG-015 — conversion adoption + the constituent dual leg (WF-EXE-02)
    // =========================================================================

    /**
     * Adoption effect: conversion-act law; constituents resolved (direct
     * child jurisdictions holding a non-dissolved legislature — the
     * WF-JUR-04 precedent); none ⇒ the process is decided immediately
     * (Art. III §3 "where constituents exist") and the election schedules;
     * else the MultiJurisdictionVote process opens.
     *
     * @return array{0: string, 1: string} [result_type, result_id]
     */
    public function applyConversionAdoption(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;

        $executive = Executive::query()->whereKey((string) $payload['executive_id'])->firstOrFail();

        self::assertConversionTarget((string) $payload['target_type'], (int) $payload['member_count']);

        $law = $this->enactments->enactDirect(
            $legislature,
            'creation_act',
            'Executive Office Creation/Conversion Act',
            (string) $payload['charter_text'],
            $vote,
        );

        $executive->forceFill(['conversion_law_id' => $law->id])->save();

        $constituents = $this->constituentJurisdictionIds($legislature);

        if ($constituents === []) {
            // No constituents: the chamber supermajority alone decides
            // (Art. III §3) — straight to the election.
            $executive->forceFill(['status' => Executive::STATUS_CONVERSION_VOTED])->save();

            $this->records->publish(
                kind: 'act',
                title: 'Executive office conversion adopted — no constituents to consent',
                body: sprintf(
                    'Act %s: no direct constituent jurisdiction holds a legislature able to vote; '
                    . 'the conversion completes on the chamber supermajority alone (Art. III §3).',
                    $law->act_number
                ),
                attrs: [
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'legislature_id'  => (string) $legislature->id,
                    'via_form'        => 'F-LEG-015',
                    'subject_type'    => 'executives',
                    'subject_id'      => (string) $executive->id,
                ],
            );

            $this->scheduleConversionElection($executive, $legislature, $payload);

            return ['executives', (string) $executive->id];
        }

        $process = $this->processes->open(
            'exec_office_create',
            $legislature,
            $constituents,
            MultiJurisdictionVote::BASIS_SUPERMAJORITY,
            $vote,
            'executives',
            (string) $executive->id,
        );

        $executive->forceFill([
            'status'                => Executive::STATUS_CONVERSION_VOTED,
            'conversion_process_id' => $process->id,
        ])->save();

        // Constituents without legislatures cannot consent — their
        // absence is on the process record (flagged q-ledger candidate).
        $childless = $this->directChildrenWithoutLegislatures($legislature);

        $this->records->publish(
            kind: 'act',
            title: 'Executive office conversion adopted — constituent consent requested',
            body: sprintf(
                'Act %s: dual-supermajority process %s opened across %d constituent legislature(s); '
                . 'required: %d (Art. III §3 · Art. VII).%s',
                $law->act_number,
                (string) $process->id,
                count($constituents),
                (int) $process->required,
                $childless === [] ? '' : sprintf(
                    ' %d direct child jurisdiction(s) hold no legislature and cannot consent: %s.',
                    count($childless),
                    implode(', ', $childless)
                )
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-015',
                'subject_type'    => 'multi_jurisdiction_votes',
                'subject_id'      => (string) $process->id,
            ],
        );

        return ['multi_jurisdiction_votes', (string) $process->id];
    }

    /**
     * The constituent-consent vote (design §B.2.4 — built generically so
     * Phase E judiciary conversion and Art. VII reuse it): a constituent
     * chamber's OWN ordinary-majority vote on a pending consent row.
     */
    public function openConstituentConsentVote(
        MultiJurisdictionVote $process,
        Legislature $constituentChamber,
        LegislatureMember $opener,
    ): ChamberVote {
        if ($process->status !== MultiJurisdictionVote::STATUS_OPEN) {
            throw new ConstitutionalViolation(
                'The constituent process is not open.',
                'Art. VII · as implemented'
            );
        }

        $consent = ConstituentConsent::query()
            ->where('process_id', $process->id)
            ->where('jurisdiction_id', $constituentChamber->jurisdiction_id)
            ->first();

        if ($consent === null) {
            throw new ConstitutionalViolation(
                'This chamber\'s jurisdiction is not a constituent of the process.',
                'Art. VII · as implemented'
            );
        }

        if ($consent->result !== ConstituentConsent::RESULT_PENDING) {
            throw new ConstitutionalViolation('This constituent has already decided.', 'Art. VII · as implemented');
        }

        if ($consent->chamber_vote_id !== null) {
            throw new ConstitutionalViolation('This constituent\'s consent vote is already open.', 'Art. VII · as implemented');
        }

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $constituentChamber->id,
            voteType: ExecutiveActService::CONSTITUENT_CONSENT_VOTE_TYPE,
            votable: $consent,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $opener,
        );

        $consent->forceFill(['chamber_vote_id' => (string) $vote->id, 'legislature_id' => (string) $constituentChamber->id])->save();

        return $vote;
    }

    /**
     * votable_type 'constituent_consent' (vote-engine dispatch, same txn):
     * record the constituent's decision; the process evaluates itself;
     * a decided process fires its subject effect.
     */
    public function resolveConstituentConsentVote(ChamberVote $vote, string $outcome): void
    {
        $consent = ConstituentConsent::query()->find($vote->votable_id);

        if ($consent === null || $consent->result !== ConstituentConsent::RESULT_PENDING) {
            return; // idempotent
        }

        $process = MultiJurisdictionVote::query()->findOrFail($consent->process_id);

        $this->processes->recordConsent(
            $process,
            (string) $consent->jurisdiction_id,
            $outcome === ChamberVote::OUTCOME_ADOPTED,
            $vote,
            $vote->legislature_id !== null ? (string) $vote->legislature_id : null,
        );

        $this->onProcessEvaluated($process->refresh());
    }

    /**
     * Process decided → subject effect. exec_office_create: passed ⇒
     * schedule the executive election; failed ⇒ the office reverts to its
     * pre-conversion footing (the act stands as a record; nothing seats).
     */
    public function onProcessEvaluated(MultiJurisdictionVote $process): void
    {
        if ($process->subject_type !== 'executives'
            || ! in_array($process->kind, ['exec_office_create', 'exec_office_alter'], true)) {
            return;
        }

        if ($process->status === MultiJurisdictionVote::STATUS_OPEN) {
            return;
        }

        $executive   = Executive::query()->findOrFail((string) $process->subject_id);
        $legislature = Legislature::query()->findOrFail((string) $process->initiating_legislature_id);

        if ($process->kind !== 'exec_office_create') {
            return; // alteration effects are payload-specific (minimal in D)
        }

        if ($process->status === MultiJurisdictionVote::STATUS_PASSED) {
            $payload = $this->conversionPayloadFor($process);

            $this->scheduleConversionElection($executive, $legislature, $payload);

            return;
        }

        // Failed/expired: revert to the pre-conversion footing.
        $executive->forceFill([
            'status' => $executive->delegation_law_id !== null
                ? Executive::STATUS_DELEGATED
                : Executive::STATUS_FORMING,
        ])->save();

        $this->records->publish(
            kind: 'act',
            title: 'Executive office conversion failed at constituent consent',
            body: sprintf(
                'Process %s closed %s (%d yes / %d no of %d; required %d) — the office keeps its '
                . 'current footing (Art. III §3 · Art. VII).',
                (string) $process->id,
                $process->status,
                (int) $process->yes_count,
                (int) $process->no_count,
                (int) $process->constituent_total,
                (int) $process->required
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-015',
                'subject_type'    => 'executives',
                'subject_id'      => (string) $executive->id,
            ],
        );
    }

    /**
     * WF-EXE-03 — alter an existing ELECTED office: constituent
     * supermajority ONLY (registry `exec_office_alter`, engine
     * multi_jurisdiction — no chamber-supermajority leg). Minimal D
     * surface: open the process; effects on pass are payload-specific.
     */
    public function openAlteration(Legislature $legislature, Executive $executive, array $changes): MultiJurisdictionVote
    {
        if ($executive->status !== Executive::STATUS_ELECTED) {
            throw new ConstitutionalViolation(
                'Office alteration applies to an ELECTED executive office (Art. III §2).',
                'Art. III §2'
            );
        }

        $constituents = $this->constituentJurisdictionIds($legislature);

        if ($constituents === []) {
            throw new ConstitutionalViolation(
                'No constituent legislatures exist to consent to the alteration.',
                'Art. III §2'
            );
        }

        $process = $this->processes->open(
            'exec_office_alter',
            $legislature,
            $constituents,
            MultiJurisdictionVote::BASIS_SUPERMAJORITY,
            null,
            'executives',
            (string) $executive->id,
        );

        $this->audit->append(
            module: 'executive',
            event: 'executive.alteration_opened',
            payload: [
                'executive_id' => (string) $executive->id,
                'process_id'   => (string) $process->id,
                'changes'      => $changes,
            ],
            ref: 'F-LEG-015',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );

        return $process;
    }

    // =========================================================================
    // Election scheduling + member lifecycle
    // =========================================================================

    /**
     * Process passed → the executive election (design §B.2.5): kind
     * `executive`, lockstep-anchored to the legislature; one race —
     * committee ⇒ exec_committee/n (PR-STV), individual ⇒ single/1
     * (RCV + advisor derivation at certification).
     */
    public function scheduleConversionElection(Executive $executive, Legislature $legislature, array $payload): void
    {
        $election = app(ElectionLifecycleService::class)->scheduleExecutive(
            $executive,
            $legislature,
            (string) $payload['target_type'],
            (int) $payload['member_count'],
        );

        $this->audit->append(
            module: 'executive',
            event: 'executive.election_scheduled',
            payload: [
                'executive_id' => (string) $executive->id,
                'election_id'  => (string) $election->id,
                'target_type'  => (string) $payload['target_type'],
                'seats'        => (int) $payload['member_count'],
            ],
            ref: 'F-LEG-015',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );
    }

    /**
     * Resolve the adopted F-LEG-015 payload through the process's
     * initiating chamber vote (the proposal stores target_type /
     * member_count / charter_text).
     */
    private function conversionPayloadFor(MultiJurisdictionVote $process): array
    {
        $proposal = $process->initiating_vote_id !== null
            ? ChamberVoteProposal::query()->where('vote_id', (string) $process->initiating_vote_id)->first()
            : null;

        if ($proposal === null) {
            throw new ConstitutionalViolation(
                'The conversion process has no resolvable F-LEG-015 act payload.',
                'Art. III §3 · as implemented'
            );
        }

        return (array) $proposal->payload;
    }

    /**
     * Chamber-turnover hook (design §B.1.5, called from the PROTECTED
     * CertificationService::turnOverChamber): delegated members are ex
     * officio — they leave the executive when their legislative seat
     * ends. Idempotent; never touches elected-era rows.
     */
    public function closeDelegatedMembersOnTurnover(Legislature $legislature): void
    {
        $executive = Executive::query()
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->where('status', Executive::STATUS_DELEGATED)
            ->first();

        if ($executive === null) {
            return;
        }

        $closed = [];

        $rows = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('selection', ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->get();

        foreach ($rows as $row) {
            $row->forceFill([
                'status'  => ExecutiveMember::STATUS_LEFT,
                'left_at' => now()->toDateString(),
            ])->save();

            $closed[] = (string) $row->id;

            if ($row->user_id !== null) {
                $this->roles->flushUser((string) $row->user_id);
            }
        }

        if ($closed !== []) {
            $this->audit->append(
                module: 'executive',
                event: 'executive.delegated_members_left',
                payload: [
                    'executive_id' => (string) $executive->id,
                    'members'      => $closed,
                    'reason'       => 'chamber_turnover',
                ],
                ref: 'F-ELB-004',
                jurisdictionId: (string) $legislature->jurisdiction_id,
            );
        }
    }

    /**
     * Top-up a delegated committee that dropped below its act-fixed size
     * (design §B.4): an immediate audit-chained re-run of the selection
     * algorithm for the open slots — members already seated keep their
     * seats; new slots fill from the remaining chamber by the SAME math.
     */
    public function topUpDelegated(Executive $executive): array
    {
        if ($executive->status !== Executive::STATUS_DELEGATED || $executive->delegated_member_count === null) {
            return [];
        }

        $legislature = Legislature::query()->findOrFail((string) $executive->source_legislature_id);

        $seated = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->pluck('legislature_member_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        $open = (int) $executive->delegated_member_count - count($seated);

        if ($open < 1) {
            return [];
        }

        $eligible = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->whereNotIn('id', $seated)
            ->get();

        if ($eligible->isEmpty()) {
            return [];
        }

        $bicameral = (int) $legislature->type_b_seats > 0;

        $memberInput = [];
        foreach ($eligible as $member) {
            $memberInput[(string) $member->id] = [
                'kind'    => $bicameral ? $member->seatKind() : 'all',
                'share'   => (int) round(((float) ($member->vote_share_norm ?? 0)) * CommitteeAssignmentService::SHARE_SCALE),
                'seat_no' => $member->seat_no !== null ? (int) $member->seat_no : null,
            ];
        }

        // Single-kind synthetic committee over the open slots: when the
        // chamber is bicameral the open slots stay in the kind(s) that
        // vacated — minimal D: refill kind-blind through the 'all' lane
        // of the remaining members' shares (audit carries the snapshot).
        $committeeInput = [self::SYNTHETIC_COMMITTEE => ['all' => min($open, $eligible->count())]];
        foreach ($memberInput as &$facts) {
            $facts['kind'] = 'all';
        }
        unset($facts);

        $selection = CommitteeAssignmentService::assign($committeeInput, $memberInput, []);

        $added = [];
        foreach ($selection['placements'] as $placement) {
            $member = $eligible->firstWhere('id', $placement['member_id']);

            $row = ExecutiveMember::create([
                'executive_id'          => $executive->id,
                'user_id'               => $member->user_id,
                'role'                  => ExecutiveMember::ROLE_PRINCIPAL,
                'rank'                  => 0,
                'joined_at'             => now()->toDateString(),
                'legislature_member_id' => $member->id,
                'selection'             => ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL,
                'status'                => ExecutiveMember::STATUS_SEATED,
            ]);

            $added[] = (string) $row->id;
            $this->roles->flushUser((string) $member->user_id);
        }

        $this->audit->append(
            module: 'executive',
            event: 'executive.delegated_top_up',
            payload: [
                'executive_id' => (string) $executive->id,
                'open_slots'   => $open,
                'added'        => $added,
                'selection'    => $selection,
            ],
            ref: 'F-LEG-014',
            jurisdictionId: (string) $legislature->jurisdiction_id,
        );

        return $added;
    }

    /**
     * Individual-model succession (design §B.2.7): principal vacancy →
     * the lowest-rank seated advisor flips principal (`succession`,
     * SAME term — inherited, never extended). Returns the successor or
     * null when the advisor chain is exhausted (special-election
     * fallback, CLK-04 window — Phase D leaves the trigger to the
     * vacancy machinery).
     */
    public function succeedPrincipal(Executive $executive): ?ExecutiveMember
    {
        if ($executive->type !== Executive::TYPE_INDIVIDUAL) {
            throw new ConstitutionalViolation(
                'Succession applies to the individual executive model (Art. III §3).',
                'Art. III §3'
            );
        }

        $advisor = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('role', ExecutiveMember::ROLE_ADVISOR)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->orderBy('rank')
            ->first();

        if ($advisor === null) {
            return null;
        }

        $advisor->forceFill([
            'role'      => ExecutiveMember::ROLE_PRINCIPAL,
            'rank'      => 0,
            'selection' => ExecutiveMember::SELECTION_SUCCESSION,
            // term_id unchanged — the term identity is the pin.
        ])->save();

        if ($advisor->user_id !== null) {
            $this->roles->flushUser((string) $advisor->user_id);
        }

        $this->audit->append(
            module: 'executive',
            event: 'executive.succession',
            payload: [
                'executive_id'        => (string) $executive->id,
                'successor_member_id' => (string) $advisor->id,
                'term_id'             => $advisor->term_id !== null ? (string) $advisor->term_id : null,
            ],
            ref: 'WF-EXE-02',
            jurisdictionId: (string) $executive->jurisdiction_id,
        );

        return $advisor;
    }

    // =========================================================================
    // Constituent resolution
    // =========================================================================

    /**
     * Constituents = DIRECT child jurisdictions holding a non-dissolved
     * legislature (a body that can vote) — the WF-JUR-04 precedent;
     * flagged q-ledger candidate.
     *
     * @return list<string>
     */
    public function constituentJurisdictionIds(Legislature $legislature): array
    {
        return DB::table('jurisdictions as j')
            ->join('legislatures as l', 'l.jurisdiction_id', '=', 'j.id')
            ->where('j.parent_id', $legislature->jurisdiction_id)
            ->whereNull('j.deleted_at')
            ->whereNull('l.deleted_at')
            ->where('l.status', '!=', 'dissolved')
            ->pluck('j.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** @return list<string> direct children with NO legislature (record note). */
    private function directChildrenWithoutLegislatures(Legislature $legislature): array
    {
        return DB::table('jurisdictions as j')
            ->leftJoin('legislatures as l', function ($join) {
                $join->on('l.jurisdiction_id', '=', 'j.id')->whereNull('l.deleted_at');
            })
            ->where('j.parent_id', $legislature->jurisdiction_id)
            ->whereNull('j.deleted_at')
            ->whereNull('l.id')
            ->pluck('j.name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
