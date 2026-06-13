<?php

namespace App\Services\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\AdminOffice;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MisconductInvestigation;
use App\Models\RemovalProceeding;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * Oversight machinery (chamber ops §D.3): misconduct investigations
 * (I-ADM docket) + removal proceedings (F-LEG-022 supermajority votes,
 * F-SPK-007 presiding — never over one's own case: PROTECTED rule
 * removal.presider, Art. II §3).
 *
 * Intake has NO catalog form (flagged registry gap) — it is an audited
 * non-form action (any resident / own motion / system). The CLK-02
 * repeated-quorum-failure referral calls intake() with a NULL complainant.
 *
 * Outcome loop: a removal/expulsion adoption system-files F-LEG-036 —
 * the constitutional trigger into the Phase B vacancy machinery
 * (countback → certify-or-special), closing the dev-only `vacancy:declare`
 * gap. The vote engine's votable-effect dispatch routes closed
 * removal_proceeding votes to resolveRemovalVote() in the same
 * transaction as the closing cast.
 */
class OversightService
{
    /** kind → adopted outcome (Art. II §3/§5; Phase D adds Art. III §3). */
    public const ADOPTED_OUTCOMES = [
        RemovalProceeding::KIND_IMPEACHMENT       => RemovalProceeding::OUTCOME_REMOVED,
        RemovalProceeding::KIND_EXPULSION         => RemovalProceeding::OUTCOME_EXPELLED,
        RemovalProceeding::KIND_CENSURE           => RemovalProceeding::OUTCOME_CENSURED,
        RemovalProceeding::KIND_EXECUTIVE_REMOVAL => RemovalProceeding::OUTCOME_REMOVED,
    ];

    /** Outcomes that vacate the seat (→ F-LEG-036). */
    public const SEAT_VACATING_OUTCOMES = [
        RemovalProceeding::OUTCOME_REMOVED,
        RemovalProceeding::OUTCOME_EXPELLED,
    ];

    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
    ) {
    }

    // =========================================================================
    // Investigations (I-ADM)
    // =========================================================================

    /**
     * Docket a misconduct investigation (audited non-form action — the
     * registry has no I-ADM intake form; flagged).
     */
    public function intake(
        AdminOffice $office,
        string $subjectType,
        string $subjectId,
        string $summary,
        ?User $complainant = null,
        string $via = 'manual',
    ): MisconductInvestigation {
        // Phase D extends the docket with executive members and board
        // seats (F-EXE-004 legislative referral — design §D).
        if (! in_array($subjectType, ['legislature_members', 'users', 'legislatures', 'executive_members', 'board_seats'], true)) {
            throw new ConstitutionalViolation(
                "Investigation subjects are legislature_members, users, legislatures, executive_members, "
                . "or board_seats — not [{$subjectType}].",
                'CGA Forms Catalog (I-ADM)'
            );
        }

        $run = function () use ($office, $subjectType, $subjectId, $summary, $complainant, $via): MisconductInvestigation {
            $year = now()->format('Y');

            $sequence = MisconductInvestigation::query()
                ->where('admin_office_id', $office->id)
                ->where('code', 'like', "INV-{$year}-%")
                ->withTrashed()
                ->count() + 1;

            $investigation = MisconductInvestigation::create([
                'admin_office_id'     => $office->id,
                'code'                => sprintf('INV-%s-%02d', $year, $sequence),
                'subject_type'        => $subjectType,
                'subject_id'          => $subjectId,
                'complainant_user_id' => $complainant?->getKey(),
                'summary'             => $summary,
                'status'              => MisconductInvestigation::STATUS_INTAKE,
            ]);

            $legislature = $office->legislature()->firstOrFail();

            $this->audit->append(
                module: 'legislature',
                event: 'oversight.investigation_opened',
                payload: [
                    'investigation_id' => (string) $investigation->id,
                    'code'             => $investigation->code,
                    'subject_type'     => $subjectType,
                    'subject_id'       => $subjectId,
                    'via'              => $via,
                ],
                ref: 'I-ADM',
                actorId: $complainant?->getKey() !== null ? (string) $complainant->getKey() : null,
                jurisdictionId: (string) $legislature->jurisdiction_id,
            );

            return $investigation;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    /**
     * Publish findings and either refer to a removal proceeding or close
     * with no finding.
     */
    public function publishFindings(
        MisconductInvestigation $investigation,
        string $findings,
        bool $refer,
        ?string $proceedingKind = null,
    ): MisconductInvestigation {
        $office      = $investigation->office()->firstOrFail();
        $legislature = $office->legislature()->firstOrFail();

        return DB::transaction(function () use ($investigation, $findings, $refer, $proceedingKind, $legislature) {
            $record = $this->records->publish(
                kind: 'other',
                title: sprintf('Investigation %s findings published', $investigation->code),
                body: $findings,
                attrs: [
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'legislature_id'  => (string) $legislature->id,
                    'via_workflow'    => 'I-ADM',
                    'subject_type'    => 'misconduct_investigations',
                    'subject_id'      => (string) $investigation->id,
                ],
            );

            $investigation->forceFill(['findings_record_id' => (string) $record->id]);

            if (! $refer) {
                $investigation->forceFill(['status' => MisconductInvestigation::STATUS_CLOSED_NO_FINDING])->save();

                return $investigation;
            }

            $proceeding = $this->openProceeding(
                $legislature,
                $proceedingKind ?? RemovalProceeding::KIND_IMPEACHMENT,
                $investigation->subject_type,
                (string) $investigation->subject_id,
                openedVia: 'F-SPK-007',
                sourceInvestigation: $investigation,
            );

            $investigation->forceFill([
                'status'                 => MisconductInvestigation::STATUS_REFERRED,
                'referred_proceeding_id' => $proceeding->id,
            ])->save();

            return $investigation;
        });
    }

    // =========================================================================
    // Removal proceedings (F-SPK-007 / F-LEG-022)
    // =========================================================================

    public function openProceeding(
        Legislature $legislature,
        string $kind,
        string $subjectType,
        string $subjectId,
        ?LegislatureMember $presider = null,
        string $openedVia = 'F-SPK-007',
        ?MisconductInvestigation $sourceInvestigation = null,
    ): RemovalProceeding {
        if (! in_array($kind, RemovalProceeding::ACTIVE_KINDS, true)) {
            throw new ConstitutionalViolation(
                "Proceeding kind [{$kind}] has no seated subjects in Phase C — judge/executive removal "
                . 'activates when those institutions seat (removal parity preserved in the enum).',
                'Art. II §3 · deferred'
            );
        }

        if ($subjectType === 'legislature_members') {
            $subject = LegislatureMember::query()->whereKey($subjectId)->first();

            if ($subject === null
                || (string) $subject->legislature_id !== (string) $legislature->id
                || ! in_array($subject->status, LegislatureMember::CURRENT_STATUSES, true)) {
                throw new ConstitutionalViolation(
                    'Removal proceedings run against CURRENT members of this chamber.',
                    'Art. II §3'
                );
            }
        }

        // Phase D (design §B.4 — removal parity): executive members are
        // removable by the SAME supermajority machinery. The subject must
        // be seated on THIS jurisdiction's executive.
        if ($subjectType === 'executive_members') {
            $subject = \App\Models\ExecutiveMember::query()->whereKey($subjectId)->first();

            $belongs = $subject !== null
                && $subject->status === \App\Models\ExecutiveMember::STATUS_SEATED
                && \App\Models\Executive::query()
                    ->whereKey($subject->executive_id)
                    ->where('jurisdiction_id', $legislature->jurisdiction_id)
                    ->exists();

            if (! $belongs) {
                throw new ConstitutionalViolation(
                    'Executive-removal proceedings run against SEATED members of this jurisdiction\'s executive.',
                    'Art. III §3'
                );
            }
        }

        if ($presider !== null) {
            ConstitutionalValidator::assertRemovalPresider((string) $presider->id, $subjectType, $subjectId);
        }

        return RemovalProceeding::create([
            'legislature_id'          => $legislature->id,
            'kind'                    => $kind,
            'subject_type'            => $subjectType,
            'subject_id'              => $subjectId,
            'source_investigation_id' => $sourceInvestigation?->id,
            'presided_by_member_id'   => $presider?->id,
            'opened_via'              => $openedVia,
            'status'                  => $presider !== null
                ? RemovalProceeding::STATUS_PRESIDING_DESIGNATED
                : RemovalProceeding::STATUS_OPENED,
        ]);
    }

    /**
     * Designate (or re-designate) the presider — the Speaker for every
     * case except their own, where the chamber designates a substitute
     * (designate_presider motion; the console pre-suggests the most senior
     * member, but the chamber chooses).
     */
    public function designatePresider(RemovalProceeding $proceeding, LegislatureMember $presider): RemovalProceeding
    {
        ConstitutionalValidator::assertRemovalPresider(
            (string) $presider->id,
            $proceeding->subject_type,
            (string) $proceeding->subject_id,
        );

        if (! in_array($proceeding->status, [RemovalProceeding::STATUS_OPENED, RemovalProceeding::STATUS_PRESIDING_DESIGNATED], true)) {
            throw new ConstitutionalViolation(
                "Proceeding [{$proceeding->id}] is past presider designation (status: {$proceeding->status}).",
                'Art. II §3'
            );
        }

        $proceeding->forceFill([
            'presided_by_member_id' => $presider->id,
            'status'                => RemovalProceeding::STATUS_PRESIDING_DESIGNATED,
        ])->save();

        return $proceeding;
    }

    /**
     * Open the F-LEG-022 supermajority vote on a proceeding with a
     * designated presider.
     */
    public function openRemovalVote(RemovalProceeding $proceeding, ?LegislatureMember $opener = null): ChamberVote
    {
        if ($proceeding->status !== RemovalProceeding::STATUS_PRESIDING_DESIGNATED) {
            throw new ConstitutionalViolation(
                'The removal vote opens only once a presider is designated (removal.presider, Art. II §3).',
                'Art. II §3'
            );
        }

        if ($proceeding->vote_id !== null) {
            throw new ConstitutionalViolation('This proceeding already has its vote.', 'Art. II §3');
        }

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $proceeding->legislature_id,
            voteType: 'officeholder_remove',
            votable: $proceeding,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $opener,
        );

        $proceeding->forceFill([
            'vote_id' => (string) $vote->id,
            'status'  => RemovalProceeding::STATUS_VOTED,
        ])->save();

        return $vote;
    }

    /**
     * Record one member's F-LEG-022 cast. The engine auto-closes when
     * every member able to cast has cast (the Speaker is structurally
     * excluded from yes/no business) and dispatches resolveRemovalVote().
     *
     * @return array{vote_id: string, closed: bool, outcome: string|null}
     */
    public function recordRemovalCast(
        ChamberVote $vote,
        RemovalProceeding $proceeding,
        LegislatureMember $member,
        string $value,
        ?string $explanation = null,
    ): array {
        $this->votes->cast(
            vote: $vote,
            member: $member,
            value: $value,
            rankings: null,
            explanation: $explanation,
            viaForm: 'F-LEG-022',
        );

        $vote->refresh();
        $proceeding->refresh();

        return [
            'vote_id' => (string) $vote->id,
            'closed'  => $vote->status === ChamberVote::STATUS_CLOSED,
            'outcome' => $proceeding->outcome,
        ];
    }

    /**
     * Vote-close side-effect (ChamberVoteService dispatch, same
     * transaction): apply the proceeding outcome; a removal/expulsion
     * system-files F-LEG-036 (the Phase B vacancy loop). A `tied` close
     * never reaches here (the dispatch skips ties; supermajority ties are
     * arithmetically impossible anyway).
     */
    public function resolveRemovalVote(ChamberVote $vote, string $outcome): void
    {
        $proceeding = RemovalProceeding::query()->find($vote->votable_id);

        if ($proceeding === null || $proceeding->status === RemovalProceeding::STATUS_CLOSED) {
            return; // idempotent
        }

        $resolved = $outcome === ChamberVote::OUTCOME_ADOPTED
            ? self::ADOPTED_OUTCOMES[$proceeding->kind]
            : RemovalProceeding::OUTCOME_RETAINED;

        $proceeding->forceFill([
            'status'    => RemovalProceeding::STATUS_CLOSED,
            'outcome'   => $resolved,
            'closed_at' => now(),
        ])->save();

        $legislature = $proceeding->legislature()->firstOrFail();

        $this->records->publish(
            kind: 'act',
            title: sprintf('%s proceeding closed: %s', ucfirst($proceeding->kind), $resolved),
            body: sprintf(
                'Proceeding %s against %s %s closed with outcome "%s" (supermajority of all serving — Art. VII).',
                (string) $proceeding->id,
                $proceeding->subject_type,
                (string) $proceeding->subject_id,
                $resolved
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-022',
                'subject_type'    => 'removal_proceedings',
                'subject_id'      => (string) $proceeding->id,
            ],
        );

        // The closed loop: removal/expulsion → F-LEG-036 (system) →
        // VacancyService → countback → certify-or-special (Phase B).
        if (in_array($resolved, self::SEAT_VACATING_OUTCOMES, true)
            && $proceeding->subject_type === 'legislature_members') {
            app(ConstitutionalEngine::class)->file('F-LEG-036', null, [
                'member_id'       => (string) $proceeding->subject_id,
                'reason'          => 'removed',
                'member_status'   => LegislatureMember::STATUS_REMOVED,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'via_workflow'    => 'F-LEG-022',
            ]);
        }

        // Phase D (design §B.4): an executive member's removal closes their
        // EXECUTIVE seat only (the legislative seat is untouched — expelling
        // the legislator is a separate F-LEG-022). Delegated committees that
        // drop below their act-fixed size top up by the SAME selection math;
        // an individual principal's removal triggers advisor succession.
        if ($resolved === RemovalProceeding::OUTCOME_REMOVED
            && $proceeding->subject_type === 'executive_members') {
            $member = \App\Models\ExecutiveMember::query()->find((string) $proceeding->subject_id);

            if ($member !== null && $member->status === \App\Models\ExecutiveMember::STATUS_SEATED) {
                $member->forceFill([
                    'status'  => \App\Models\ExecutiveMember::STATUS_REMOVED,
                    'left_at' => now()->toDateString(),
                ])->save();

                $executive = \App\Models\Executive::query()->findOrFail((string) $member->executive_id);
                $formation = app(\App\Services\Executive\ExecutiveFormationService::class);

                if ($member->selection === \App\Models\ExecutiveMember::SELECTION_DELEGATED_PROPORTIONAL) {
                    $formation->topUpDelegated($executive);
                } elseif ($executive->type === \App\Models\Executive::TYPE_INDIVIDUAL
                    && $member->role === \App\Models\ExecutiveMember::ROLE_PRINCIPAL) {
                    $formation->succeedPrincipal($executive);
                }
            }
        }
    }
}
