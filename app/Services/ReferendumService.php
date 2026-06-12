<?php

namespace App\Services;

use App\Domain\Ballots\BallotBox;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Election;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Petition;
use App\Models\ReferendumQuestion;
use App\Support\CivicPopulation;
use Illuminate\Support\Facades\DB;

/**
 * C-R1 (PHASE_C_DESIGN_votes_laws §D) — referendums: delegation
 * (F-LEG-023) and petition questions riding the NEXT jurisdiction-wide
 * ballot through the Phase B election machinery, population-pegged
 * thresholds, enactment with the CLK-19 same-term shield, and the
 * F-LEG-034 modification path.
 *
 * THRESHOLD DISCIPLINE (hardened):
 *  - `threshold` is DERIVED from act_type (deriveThreshold) — no API ever
 *    accepts a threshold input; the DB CHECK is the backstop.
 *  - pass/fail resolves ONLY through the PROTECTED
 *    ConstitutionalValidator::quorum()/supermajority() functions over the
 *    CIVIC population (CivicPopulation::of — active associations, never
 *    WorldPop). Absent voters are arithmetically identical to a no —
 *    the population peg, same semantics as the chamber lanes.
 *  - `referendum_passed_by_supermajority` is computed REGARDLESS of the
 *    question's threshold class (a majority-class question passing at
 *    population-supermajority strength earns the CLK-19 shield —
 *    Art. II §6 shields "acts passed by population supermajority", not
 *    "supermajority-class questions"; flagged interpretation).
 *
 * CLK-19 has NO timer — it is a validator gate (rule referendum.shield)
 * evaluated at filing time against laws.shield_expires_with_election_id;
 * releaseShields() (called by certification of the shield election) is
 * the lapse point.
 */
class ReferendumService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ConstitutionalValidator $validator,
        private readonly PublicRecordService $records,
        private readonly SettingsResolver $settings,
        private readonly ChamberVoteService $votes,
        private readonly BallotBox $box,
    ) {
    }

    // =========================================================================
    // PURE threshold derivation — pinned by ReferendumShieldTest
    // =========================================================================

    /**
     * The act type fixes the population threshold — "majority or
     * supermajority of population matching the legislative equivalent"
     * (Art. II §6). Never an input; never editable.
     */
    public static function deriveThreshold(string $actType): string
    {
        return $actType === 'supermajority'
            ? ReferendumQuestion::THRESHOLD_SUPERMAJORITY
            : ReferendumQuestion::THRESHOLD_MAJORITY;
    }

    // =========================================================================
    // F-LEG-023 — delegation
    // =========================================================================

    /**
     * Validate the question and open the referendum_delegate supermajority
     * chamber vote (the question row is created only on ADOPTION — a
     * failed delegation leaves a failed vote, never a queued question).
     *
     * @param  array{question: string, law_text: string, act_type: string,
     *               targets_setting_key?: ?string, proposed_value?: mixed}  $payload
     * @return array{proposal_id: string, vote_id: string}
     */
    public function proposeDelegation(Legislature $legislature, LegislatureMember $proposer, array $payload): array
    {
        $this->validateQuestionPayload($payload);

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_REFERENDUM_DELEGATION,
            'payload'               => [
                'question'            => (string) $payload['question'],
                'law_text'            => (string) $payload['law_text'],
                'act_type'            => (string) $payload['act_type'],
                'targets_setting_key' => $payload['targets_setting_key'] ?? null,
                'proposed_value'      => $payload['proposed_value'] ?? null,
            ],
            'proposed_by_member_id' => $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'referendum_delegate',
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /** Adoption side-effect (ChamberActService dispatch, same transaction). */
    public function createFromDelegation(ChamberVoteProposal $proposal, ChamberVote $vote): ReferendumQuestion
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;
        $actType     = (string) $payload['act_type'];

        $question = ReferendumQuestion::create([
            'jurisdiction_id'     => (string) $legislature->jurisdiction_id,
            'origin'              => ReferendumQuestion::ORIGIN_DELEGATION,
            'delegating_vote_id'  => (string) $vote->id,
            'question'            => (string) $payload['question'],
            'law_text'            => (string) $payload['law_text'],
            'act_type'            => $actType,
            'threshold'           => self::deriveThreshold($actType), // derived — never an input
            'targets_setting_key' => $payload['targets_setting_key'] ?? null,
            'proposed_value'      => $payload['proposed_value'] ?? null,
            'status'              => ReferendumQuestion::STATUS_QUEUED,
        ]);

        $this->records->publish(
            kind: 'act',
            title: 'Referendum delegated — ' . mb_strimwidth((string) $payload['question'], 0, 120, '…'),
            body: sprintf(
                "Delegated to referendum by supermajority resolution (F-LEG-023). Threshold: %s of the civic population — derived from the act type, never editable. The question rides the next jurisdiction-wide ballot (WF-ELE-07).\n\n%s",
                $question->threshold,
                (string) $payload['law_text']
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-023',
                'subject_type'    => 'referendum_question',
                'subject_id'      => (string) $question->id,
            ],
        );

        return $question;
    }

    // =========================================================================
    // Petition → question (votes_laws §E "Validated → ballot")
    // =========================================================================

    public function queueFromPetition(Petition $petition): ReferendumQuestion
    {
        if ($petition->status !== Petition::STATUS_VALIDATED) {
            throw new ConstitutionalViolation(
                'Only a validated petition queues to the ballot (ESM-10).',
                'Art. II §6'
            );
        }

        $question = ReferendumQuestion::create([
            'jurisdiction_id'     => (string) $petition->jurisdiction_id,
            'origin'              => ReferendumQuestion::ORIGIN_PETITION,
            'petition_id'         => (string) $petition->id,
            'question'            => $petition->title,
            'law_text'            => $petition->law_text,
            'act_type'            => $petition->act_type,
            'threshold'           => self::deriveThreshold($petition->act_type),
            'targets_setting_key' => $petition->targets_setting_key,
            'proposed_value'      => $petition->proposed_value,
            'status'              => ReferendumQuestion::STATUS_QUEUED,
        ]);

        $petition->forceFill(['referendum_question_id' => $question->id])->save();

        $this->audit->append(
            module: 'civic',
            event: 'petition.queued_to_ballot',
            payload: [
                'petition_id' => (string) $petition->id,
                'question_id' => (string) $question->id,
                'threshold'   => $question->threshold,
            ],
            ref: 'WF-CIV-06',
            jurisdictionId: (string) $petition->jurisdiction_id,
        );

        return $question;
    }

    // =========================================================================
    // Attach — the NEXT jurisdiction-wide ballot (hooked from F-ELB-001 /
    // CLK-18 via ElectionLifecycleService)
    // =========================================================================

    /**
     * Attach every QUEUED question whose jurisdiction matches the
     * election's (exact-match only in Phase C; cross-scope piggybacking
     * deferred) to this election. Idempotent; runs whenever a covering
     * election is scheduled or advances toward its ranked window.
     */
    public function attachQueued(Election $election): int
    {
        if (! $this->carriesReferendums($election)) {
            return 0;
        }

        $attached = 0;

        $queued = ReferendumQuestion::query()
            ->where('jurisdiction_id', (string) $election->jurisdiction_id)
            ->where('status', ReferendumQuestion::STATUS_QUEUED)
            ->lockForUpdate()
            ->get();

        foreach ($queued as $question) {
            $question->forceFill([
                'election_id' => $election->id,
                'status'      => ReferendumQuestion::STATUS_SCHEDULED,
            ])->save();

            if ($question->petition_id !== null) {
                Petition::query()->whereKey($question->petition_id)->update([
                    'status'     => Petition::STATUS_ON_BALLOT,
                    'updated_at' => now(),
                ]);
            }

            $this->audit->append(
                module: 'elections',
                event: 'referendum.scheduled',
                payload: [
                    'question_id' => (string) $question->id,
                    'election_id' => (string) $election->id,
                    'origin'      => $question->origin,
                    'threshold'   => $question->threshold,
                ],
                ref: 'WF-ELE-07',
                jurisdictionId: (string) $election->jurisdiction_id,
            );

            $attached++;
        }

        return $attached;
    }

    /**
     * Which elections carry referendum questions: the open general (every
     * active legislature permanently has one), a board-scheduled
     * kind='referendum' election, or a special whose footprint is the
     * WHOLE jurisdiction (every race at-large). Attachment happens before
     * the ranked window opens — never mid-vote.
     */
    private function carriesReferendums(Election $election): bool
    {
        if (! in_array($election->status, [
            Election::STATUS_SCHEDULED,
            Election::STATUS_APPROVAL_OPEN,
            Election::STATUS_FINALIST_CUTOFF,
        ], true)) {
            return false;
        }

        if (in_array($election->kind, [Election::KIND_GENERAL, Election::KIND_REFERENDUM], true)) {
            return true;
        }

        return $election->kind === Election::KIND_SPECIAL
            && ! $election->races()->whereNotNull('district_id')->exists();
    }

    // =========================================================================
    // Tally (riding the tabulation pipeline) + certification
    // =========================================================================

    /**
     * Count every SCHEDULED question of this election: decrypt through
     * the single secrecy-boundary reader, write yes/no, snapshot the
     * civic-population denominator. Chain-of-custody parity with race
     * tabulations: the tally entry seals a record hash over the counts.
     */
    public function tallyForElection(Election $election): int
    {
        $tallied = 0;

        $questions = ReferendumQuestion::query()
            ->where('election_id', (string) $election->id)
            ->where('status', ReferendumQuestion::STATUS_SCHEDULED)
            ->lockForUpdate()
            ->get();

        foreach ($questions as $question) {
            $yes = 0;
            $no  = 0;

            foreach ($this->box->decryptReferendumForCount($question) as $choice) {
                $choice === 'yes' ? $yes++ : $no++;
            }

            $eligible = CivicPopulation::of((string) $question->jurisdiction_id);

            $question->forceFill([
                'yes_count'           => $yes,
                'no_count'            => $no,
                'eligible_population' => $eligible,
                'status'              => ReferendumQuestion::STATUS_VOTED,
            ])->save();

            // Mark the counted ballots (parity with race tabulation) —
            // through BallotBox, the secrecy tables' single writer.
            $this->box->markReferendumBallotsCounted($question);

            $record = [
                'question_id'         => (string) $question->id,
                'election_id'         => (string) $election->id,
                'yes'                 => $yes,
                'no'                  => $no,
                'eligible_population' => $eligible,
            ];

            $this->audit->append(
                module: 'elections',
                event: 'referendum.tallied',
                payload: $record + ['record_hash' => hash('sha256', json_encode($record))],
                ref: 'WF-ELE-07',
                jurisdictionId: (string) $question->jurisdiction_id,
            );

            $tallied++;
        }

        return $tallied;
    }

    /**
     * F-ELB-004 side-effect (CertificationService::certify, general path):
     * resolve every VOTED question of this election against the population
     * peg, enact passed questions (CLK-19 shield inputs computed), archive
     * failed ones. $shieldElectionId = the legislature's open successor
     * general (created in the same certification transaction).
     *
     * @return list<array<string, mixed>> per-question outcome (rides the F-ELB-004 payload)
     */
    public function certifyForElection(Election $election, ?string $shieldElectionId): array
    {
        // Safety net: a question that somehow missed the tabulation tally
        // is counted now (idempotent — only scheduled questions tally).
        $this->tallyForElection($election);

        $outcomes = [];

        $questions = ReferendumQuestion::query()
            ->where('election_id', (string) $election->id)
            ->where('status', ReferendumQuestion::STATUS_VOTED)
            ->lockForUpdate()
            ->get();

        foreach ($questions as $question) {
            $jurisdictionId = (string) $question->jurisdiction_id;
            $eligible       = (int) $question->eligible_population;
            $yes            = (int) $question->yes_count;

            $numerator   = $this->settings->resolveInt($jurisdictionId, 'supermajority_numerator', 2);
            $denominator = $this->settings->resolveInt($jurisdictionId, 'supermajority_denominator', 3);

            // The population peg — the SAME two PROTECTED functions as
            // every chamber lane; absent voters count exactly like a no.
            $requiredMajority = ConstitutionalValidator::quorum($eligible);
            $requiredSuper    = ConstitutionalValidator::supermajority($eligible, $numerator, $denominator);

            $required = $question->threshold === ReferendumQuestion::THRESHOLD_SUPERMAJORITY
                ? $requiredSuper
                : $requiredMajority;

            $passed = $yes >= $required;

            // Computed regardless of threshold class (flagged interpretation).
            $passedBySupermajority = $yes >= $requiredSuper;

            $law = null;

            if ($passed) {
                $law = app(EnactmentService::class)->enactFromReferendum(
                    $question,
                    $passedBySupermajority,
                    $shieldElectionId,
                );

                $question->forceFill([
                    'status'           => ReferendumQuestion::STATUS_PASSED,
                    'resulting_law_id' => $law->id,
                    'certified_at'     => now(),
                ])->save();
            } else {
                $question->forceFill([
                    'status'       => ReferendumQuestion::STATUS_FAILED,
                    'certified_at' => now(),
                ])->save();
            }

            if ($question->petition_id !== null) {
                Petition::query()->whereKey($question->petition_id)->update([
                    'status'     => $passed ? Petition::STATUS_ADOPTED : Petition::STATUS_REJECTED,
                    'updated_at' => now(),
                ]);
            }

            $outcome = [
                'question_id'              => (string) $question->id,
                'threshold'                => $question->threshold,
                'yes'                      => $yes,
                'no'                       => (int) $question->no_count,
                'eligible_population'      => $eligible,
                'required_yes'             => $required,
                'passed'                   => $passed,
                'passed_by_supermajority'  => $passedBySupermajority,
                'law_id'                   => $law?->id !== null ? (string) $law->id : null,
                'act_number'               => $law?->act_number,
            ];

            $this->audit->append(
                module: 'elections',
                event: 'referendum.certified',
                payload: $outcome,
                ref: 'F-ELB-004',
                jurisdictionId: $jurisdictionId,
            );

            $this->records->publish(
                kind: 'certification',
                title: sprintf(
                    'Referendum %s — %s (%d yes / %d no; needed %d of %d civic population)',
                    $passed ? 'PASSED' : 'failed',
                    mb_strimwidth($question->question, 0, 100, '…'),
                    $yes,
                    (int) $question->no_count,
                    $required,
                    $eligible
                ),
                body: $passed && $law !== null
                    ? "Enacted as {$law->act_number}." . ($passedBySupermajority
                        ? ' Passed by population supermajority — shielded from legislative modification until the next general election certifies (Art. II §6 · CLK-19).'
                        : '')
                    : 'The question did not reach its population threshold; absent voters count exactly like a no.',
                attrs: [
                    'jurisdiction_id' => $jurisdictionId,
                    'via_form'        => 'F-ELB-004',
                    'subject_type'    => 'referendum_question',
                    'subject_id'      => (string) $question->id,
                ],
            );

            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /**
     * CLK-19 shield RELEASE (certification of the shield election, general
     * only): protected referendum acts convert to ordinary law — the
     * Phase B placeholder, now real.
     */
    public function releaseShields(Election $certified): int
    {
        $released = 0;

        $laws = Law::query()
            ->where('shield_expires_with_election_id', (string) $certified->id)
            ->lockForUpdate()
            ->get();

        foreach ($laws as $law) {
            $law->forceFill([
                'referendum_passed_by_supermajority' => null,
                'shield_expires_with_election_id'    => null,
            ])->save();

            $this->audit->append(
                module: 'legislature',
                event: 'law.shield_released',
                payload: [
                    'law_id'      => (string) $law->id,
                    'act_number'  => $law->act_number,
                    'election_id' => (string) $certified->id,
                ],
                ref: 'CLK-19',
                jurisdictionId: (string) $law->jurisdiction_id,
            );

            $this->records->publish(
                kind: 'act',
                title: "{$law->act_number} — referendum act converted to ordinary law",
                body: 'The next general election has certified; the Art. II §6 same-term protection has lapsed. '
                    . 'The act now amends through the ordinary legislative path (WF-LEG-18).',
                attrs: [
                    'jurisdiction_id' => (string) $law->jurisdiction_id,
                    'legislature_id'  => (string) $law->legislature_id,
                    'via_clock'       => 'CLK-19',
                    'subject_type'    => 'law',
                    'subject_id'      => (string) $law->id,
                ],
            );

            $released++;
        }

        return $released;
    }

    // =========================================================================
    // F-LEG-034 — referendum act modification (same term, supermajority)
    // =========================================================================

    /**
     * Open the referendum_act_modify supermajority vote. The CLK-19
     * validator rule already ran at the engine boundary; re-asserted here
     * (defense in depth — this service is reachable from other handlers).
     *
     * @return array{proposal_id: string, vote_id: string}
     */
    public function proposeModification(Legislature $legislature, LegislatureMember $proposer, Law $law, string $newText): array
    {
        if ($law->origin !== Law::ORIGIN_REFERENDUM) {
            throw new ConstitutionalViolation(
                'F-LEG-034 modifies referendum-passed acts only — other laws amend through the bill path.',
                'Art. II §6'
            );
        }

        ConstitutionalValidator::assertReferendumActModifiable(
            (bool) $law->referendum_passed_by_supermajority,
            $this->shieldElectionPending($law),
        );

        if ((string) $law->legislature_id !== (string) $legislature->id) {
            throw new ConstitutionalViolation(
                'A referendum act is modified by the legislature of its own jurisdiction.',
                'Art. II §6 · as implemented'
            );
        }

        if (trim($newText) === '') {
            throw new ConstitutionalViolation('A modification carries replacement law text.', 'Art. II §6 · as implemented');
        }

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_REFERENDUM_ACT_MODIFICATION,
            'payload'               => ['law_id' => (string) $law->id, 'text' => $newText],
            'proposed_by_member_id' => $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'referendum_act_modify',
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /** Adoption side-effect: append the referendum_modification version. */
    public function applyModification(ChamberVoteProposal $proposal, ChamberVote $vote): Law
    {
        $payload = (array) $proposal->payload;
        $law     = Law::query()->lockForUpdate()->findOrFail($payload['law_id']);

        return app(EnactmentService::class)->amendLaw(
            $law,
            (string) $payload['text'],
            LawVersion::SOURCE_REFERENDUM_MODIFICATION,
            'chamber_vote',
            (string) $vote->id,
            'F-LEG-034',
        );
    }

    /** Is the law's shield election still pending (not yet certified)? */
    public function shieldElectionPending(Law $law): bool
    {
        if ($law->shield_expires_with_election_id === null) {
            return false;
        }

        $status = Election::query()
            ->whereKey($law->shield_expires_with_election_id)
            ->value('status');

        return $status !== null && ! in_array($status, [
            Election::STATUS_CERTIFIED,
            Election::STATUS_AUDIT_RERUN,
            Election::STATUS_FINAL,
            Election::STATUS_CANCELLED,
        ], true);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Shared F-LEG-023 / question-shape validation (setting bounds pre-vote). */
    private function validateQuestionPayload(array $payload): void
    {
        if (trim((string) ($payload['question'] ?? '')) === '') {
            throw new ConstitutionalViolation('A referendum carries ballot question text.', 'Art. II §6 · as implemented');
        }

        if (trim((string) ($payload['law_text'] ?? '')) === '') {
            throw new ConstitutionalViolation(
                'A referendum carries the binding law text it would enact.',
                'Art. II §6 · as implemented'
            );
        }

        $actType = (string) ($payload['act_type'] ?? '');

        if (! in_array($actType, ReferendumQuestion::ACT_TYPES, true)) {
            throw new ConstitutionalViolation(
                "Unknown referendum act_type [{$actType}] — ordinary, setting_change, or supermajority.",
                'Art. II §6 · as implemented'
            );
        }

        $settingKey = $payload['targets_setting_key'] ?? null;

        if (($actType === 'setting_change') !== ($settingKey !== null)) {
            throw new ConstitutionalViolation(
                'setting_change questions (and only they) target a setting key.',
                'Art. VII'
            );
        }

        if ($settingKey !== null) {
            // PRE-VOTE bounds check — the same PROTECTED path as bills.
            $this->validator->checkSettingChange([
                'setting_key' => $settingKey,
                'value'       => $payload['proposed_value'] ?? null,
            ]);
        }
    }
}
