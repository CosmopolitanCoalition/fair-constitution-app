<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\PanelJudge;
use App\Models\SentencingOrder;
use App\Models\Verdict;
use App\Services\AuditService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * ESM-CASE owner (PHASE_E_DESIGN_cases_juries §C) — the case lifecycle. No
 * other class mutates `cases.status`. Every transition guards the legal ESM
 * edge, writes the audit row, and (for public stages) publishes the record.
 *
 *   filed → accepted → paneled → [jury_empaneled] → heard → deliberation →
 *           decided → sentenced → closed
 *                 ↘ dismissed                       ↘ closed (no sentence)
 *
 * `open()` is the shared seam E-CHALLENGE will also use for the case-row half
 * of F-IND-016 (constitutional challenges enter `filed` then branch). Docket
 * numbers are allocated under pg_advisory_xact_lock (the
 * EnactmentService::allocateActNumber pattern).
 */
class CaseService
{
    /** Legal ESM edges (from => [to, …]); CaseService is the only mover. */
    public const TRANSITIONS = [
        CourtCase::STATUS_FILED => [CourtCase::STATUS_ACCEPTED, CourtCase::STATUS_DISMISSED],
        CourtCase::STATUS_ACCEPTED => [CourtCase::STATUS_PANELED, CourtCase::STATUS_DISMISSED],
        CourtCase::STATUS_PANELED => [CourtCase::STATUS_JURY_EMPANELED, CourtCase::STATUS_HEARD],
        CourtCase::STATUS_JURY_EMPANELED => [CourtCase::STATUS_HEARD],
        CourtCase::STATUS_HEARD => [CourtCase::STATUS_DELIBERATION],
        CourtCase::STATUS_DELIBERATION => [CourtCase::STATUS_DECIDED],
        CourtCase::STATUS_DECIDED => [CourtCase::STATUS_SENTENCED, CourtCase::STATUS_CLOSED, CourtCase::STATUS_APPEALED],
        CourtCase::STATUS_SENTENCED => [CourtCase::STATUS_CLOSED, CourtCase::STATUS_APPEALED],
    ];

    public function __construct(
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
    ) {}

    // =========================================================================
    // open (the shared seam: F-IND-017 / F-ADV-001 / F-IND-016 case-row half)
    // =========================================================================

    /**
     * Create a case in `filed` with its opening party set. Standing is
     * association-only (Art. I — "no standing gatekeeper beyond jurisdictional
     * association"); the engine never gates filing by a merits test.
     *
     * @param  array{
     *     judiciary_id:string, jurisdiction_id:string, kind:string, title:string,
     *     statement_of_claim?:?string, claimed_severity?:?string,
     *     filed_via_form:string, filed_by_user_id?:?string,
     *     filed_on_behalf_of_user_id?:?string, advocate_id?:?string,
     *     parties?:list<array<string,mixed>>
     * } $attrs
     */
    public function open(array $attrs): CourtCase
    {
        $judiciaryId = (string) $attrs['judiciary_id'];
        $judiciary = Judiciary::query()->findOrFail($judiciaryId);

        if (! in_array($judiciary->status, Judiciary::OPERATING_STATUSES, true)) {
            throw new ConstitutionalViolation(
                'A case is filed before a court that is hearing cases (appointed or elected).',
                'Art. IV §1'
            );
        }

        $docketNo = $this->allocateDocketNumber($judiciaryId);

        $case = CourtCase::create([
            'docket_no' => $docketNo,
            'judiciary_id' => $judiciaryId,
            'jurisdiction_id' => (string) $attrs['jurisdiction_id'],
            'kind' => (string) $attrs['kind'],
            'title' => (string) $attrs['title'],
            'statement_of_claim' => $attrs['statement_of_claim'] ?? null,
            'claimed_severity' => $attrs['claimed_severity'] ?? null,
            'filed_via_form' => (string) $attrs['filed_via_form'],
            'filed_by_user_id' => $attrs['filed_by_user_id'] ?? null,
            'filed_on_behalf_of_user_id' => $attrs['filed_on_behalf_of_user_id'] ?? null,
            'advocate_id' => $attrs['advocate_id'] ?? null,
            'status' => CourtCase::STATUS_FILED,
        ]);

        foreach ($attrs['parties'] ?? [] as $party) {
            CaseParty::create(array_merge(
                ['case_id' => (string) $case->id, 'status' => CaseParty::STATUS_ACTIVE],
                $party,
            ));
        }

        $this->records->publish(
            kind: 'other',
            title: sprintf('Case filed — %s (%s)', $case->title, $case->docket_no),
            body: $case->statement_of_claim,
            attrs: [
                'jurisdiction_id' => (string) $case->jurisdiction_id,
                'via_form' => (string) $case->filed_via_form,
                'subject_type' => 'cases',
                'subject_id' => (string) $case->id,
            ],
        );

        $this->seal('case.filed', $case, ['docket_no' => $docketNo, 'kind' => $case->kind]);

        return $case;
    }

    // =========================================================================
    // accept / dismiss (F-JDG-001 — court classification)
    // =========================================================================

    /**
     * Confirm justiciability, fix the court_severity (drives panel size), and
     * set jury_entitled (criminal + not waived). filed → accepted.
     */
    public function accept(CourtCase $case, string $courtSeverity, bool $juryWaived = false): CourtCase
    {
        $this->assertTransition($case, CourtCase::STATUS_ACCEPTED);

        if (! in_array($courtSeverity, [
            CourtCase::SEVERITY_MINOR, CourtCase::SEVERITY_MODERATE,
            CourtCase::SEVERITY_SERIOUS, CourtCase::SEVERITY_CONSTITUTIONAL_MAJOR,
        ], true)) {
            throw new ConstitutionalViolation(
                "Unknown court severity classification [{$courtSeverity}].",
                'Art. IV §4'
            );
        }

        $juryEntitled = $case->kind === CourtCase::KIND_CRIMINAL && ! $juryWaived;

        $case->forceFill([
            'court_severity' => $courtSeverity,
            'jury_waived' => $juryWaived,
            'jury_entitled' => $juryEntitled,
            'status' => CourtCase::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ])->save();

        $this->seal('case.accepted', $case, [
            'court_severity' => $courtSeverity,
            'jury_entitled' => $juryEntitled,
        ]);

        return $case;
    }

    /** Not justiciable / withdrawn — filed/accepted → dismissed (terminal). */
    public function dismiss(CourtCase $case, ?string $reason = null): CourtCase
    {
        $this->assertTransition($case, CourtCase::STATUS_DISMISSED);

        $case->forceFill([
            'status' => CourtCase::STATUS_DISMISSED,
            'closed_at' => now(),
        ])->save();

        $this->records->publish(
            kind: 'other',
            title: sprintf('Case dismissed — %s (%s)', $case->title, $case->docket_no),
            body: $reason,
            attrs: [
                'jurisdiction_id' => (string) $case->jurisdiction_id,
                'subject_type' => 'cases',
                'subject_id' => (string) $case->id,
            ],
        );

        $this->seal('case.dismissed', $case, ['reason' => $reason]);

        return $case;
    }

    // =========================================================================
    // panel / jury / hearing transitions (set by Panel/Jury services)
    // =========================================================================

    /** accepted → paneled (PanelService seats the bench, sets panel_id). */
    public function markPaneled(CourtCase $case, string $panelId): CourtCase
    {
        $this->assertTransition($case, CourtCase::STATUS_PANELED);

        $case->forceFill(['panel_id' => $panelId, 'status' => CourtCase::STATUS_PANELED])->save();
        $this->seal('case.paneled', $case, ['panel_id' => $panelId]);

        return $case;
    }

    /** paneled → jury_empaneled (JuryService draws + summons, sets jury_id). */
    public function markJuryEmpaneled(CourtCase $case, string $juryId): CourtCase
    {
        if (! $case->jury_entitled || $case->jury_waived) {
            throw new ConstitutionalViolation(
                'A jury empanels only for a jury-entitled, un-waived criminal case (Art. IV §4).',
                'Art. IV §4'
            );
        }

        $this->assertTransition($case, CourtCase::STATUS_JURY_EMPANELED);

        $case->forceFill(['jury_id' => $juryId, 'status' => CourtCase::STATUS_JURY_EMPANELED])->save();
        $this->seal('case.jury_empaneled', $case, ['jury_id' => $juryId]);

        return $case;
    }

    /** paneled/jury_empaneled → heard (arguments, evidence, motions open). */
    public function advanceToHearing(CourtCase $case): CourtCase
    {
        $this->assertTransition($case, CourtCase::STATUS_HEARD);

        $case->forceFill(['status' => CourtCase::STATUS_HEARD])->save();
        $this->seal('case.heard', $case, []);

        return $case;
    }

    /** heard → deliberation (chambers + jury room, the only unrecorded space). */
    public function enterDeliberation(CourtCase $case): CourtCase
    {
        $this->assertTransition($case, CourtCase::STATUS_DELIBERATION);

        $case->forceFill(['status' => CourtCase::STATUS_DELIBERATION])->save();
        $this->seal('case.deliberation', $case, []);

        return $case;
    }

    // =========================================================================
    // recordVerdict (deliberation → decided; sets the double-jeopardy facts)
    // =========================================================================

    /**
     * deliberation → decided. A criminal verdict sets cases.double_jeopardy_locked
     * AND the verdict's double_jeopardy_flag ATOMICALLY (Art. II §8). There is
     * no F-JDG verdict FORM — the verdict is a case-state transition (the
     * mockup's stage 9, between Deliberation and Opinion).
     *
     * @param  array{decided_by:string, outcome:string, summary?:?string,
     *     panel_vote_for?:?int, panel_vote_against?:?int, jury_unanimous?:?bool}  $attrs
     */
    public function recordVerdict(CourtCase $case, array $attrs): Verdict
    {
        $this->assertTransition($case, CourtCase::STATUS_DECIDED);
        // The verdict math lives HERE so no caller can bypass it (operator
        // ruling 2026-09-13): the panel vote must sum to the panel size and
        // carry the outcome by a majority; a jury verdict must be unanimous.
        $this->assertVerdictRecordable($case, $attrs);

        return DB::transaction(function () use ($case, $attrs): Verdict {
            $isCriminal = $case->kind === CourtCase::KIND_CRIMINAL;

            $record = $this->records->publish(
                kind: 'certification',
                title: sprintf('Verdict — %s (%s): %s', $case->title, $case->docket_no, (string) $attrs['outcome']),
                body: $attrs['summary'] ?? null,
                attrs: [
                    'jurisdiction_id' => (string) $case->jurisdiction_id,
                    'subject_type' => 'cases',
                    'subject_id' => (string) $case->id,
                ],
            );

            $verdict = Verdict::create([
                'case_id' => (string) $case->id,
                'decided_by' => (string) $attrs['decided_by'],
                'outcome' => (string) $attrs['outcome'],
                'panel_vote_for' => $attrs['panel_vote_for'] ?? null,
                'panel_vote_against' => $attrs['panel_vote_against'] ?? null,
                'jury_unanimous' => $attrs['jury_unanimous'] ?? null,
                'summary' => $attrs['summary'] ?? null,
                // Art. II §8 — the flag pins the implication: flag ⇔ criminal.
                'double_jeopardy_flag' => $isCriminal,
                'record_id' => (string) $record->id,
                'decided_at' => now(),
            ]);

            // The persisted Art. II §8 fact — set the moment a CRIMINAL case
            // reaches a terminal verdict, ATOMIC with the verdict's flag.
            $case->forceFill([
                'status' => CourtCase::STATUS_DECIDED,
                'decided_at' => now(),
                'double_jeopardy_locked' => $isCriminal,
            ])->save();

            $this->seal('case.decided', $case, [
                'verdict_id' => (string) $verdict->id,
                'outcome' => $verdict->outcome,
                'double_jeopardy_locked' => $isCriminal,
            ]);

            return $verdict;
        });
    }

    // =========================================================================
    // sentence / close
    // =========================================================================

    /**
     * decided → sentenced. Sentencing is rejected unless the operative verdict
     * is a guilty CRIMINAL verdict (F-JDG-009 SentencingOrder).
     */
    public function sentence(CourtCase $case, SentencingOrder $order): CourtCase
    {
        $this->assertTransition($case, CourtCase::STATUS_SENTENCED);

        $case->forceFill(['status' => CourtCase::STATUS_SENTENCED])->save();
        $this->seal('case.sentenced', $case, ['sentencing_order_id' => (string) $order->id]);

        return $case;
    }

    /** decided/sentenced → closed (opinion published; terminal). */
    public function close(CourtCase $case): CourtCase
    {
        $this->assertTransition($case, CourtCase::STATUS_CLOSED);

        $case->forceFill([
            'status' => CourtCase::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        $this->seal('case.closed', $case, []);

        return $case;
    }

    // =========================================================================
    // verdict guards (operator ruling 2026-09-13 — the verdict is not a form,
    // so the recordability rules live on the service the route calls)
    // =========================================================================

    /** Outcomes that AFFIRM the accusation/claim (the "for" side carries them). */
    private const AFFIRMATIVE_OUTCOMES = [
        Verdict::OUTCOME_GUILTY,
        Verdict::OUTCOME_LIABLE,
        Verdict::OUTCOME_FOR_PETITIONER,
    ];

    /** Outcomes that DENY the accusation/claim (the "against" side carries them). */
    private const NEGATIVE_OUTCOMES = [
        Verdict::OUTCOME_NOT_GUILTY,
        Verdict::OUTCOME_NOT_LIABLE,
        Verdict::OUTCOME_FOR_RESPONDENT,
        Verdict::OUTCOME_DISMISSED,
    ];

    /**
     * The verdict recordability rules (operator ruling 2026-09-13). Called at
     * the top of recordVerdict, so the route AND any future caller are held to
     * the same law:
     *   - decided_by is panel or jury;
     *   - outcome is a Verdict outcome consistent with the case kind
     *     (criminal: guilty/not_guilty; otherwise the civil set);
     *   - a PANEL verdict records for + against == the panel size, with the
     *     outcome carried by the majority side;
     *   - a JURY verdict records unanimity, and the outcome as recorded.
     *
     * @param  array{decided_by?:mixed, outcome?:mixed, panel_vote_for?:mixed,
     *     panel_vote_against?:mixed, jury_unanimous?:mixed}  $attrs
     */
    public function assertVerdictRecordable(CourtCase $case, array $attrs): void
    {
        $decidedBy = (string) ($attrs['decided_by'] ?? '');

        if (! in_array($decidedBy, [Verdict::BY_PANEL, Verdict::BY_JURY], true)) {
            throw new ConstitutionalViolation(
                'A verdict is recorded by the panel or by the jury.',
                'Art. IV §4'
            );
        }

        $outcome = (string) ($attrs['outcome'] ?? '');
        $criminal = $case->kind === CourtCase::KIND_CRIMINAL;

        $allowed = $criminal
            ? [Verdict::OUTCOME_GUILTY, Verdict::OUTCOME_NOT_GUILTY]
            : [
                Verdict::OUTCOME_LIABLE, Verdict::OUTCOME_NOT_LIABLE,
                Verdict::OUTCOME_FOR_PETITIONER, Verdict::OUTCOME_FOR_RESPONDENT,
                Verdict::OUTCOME_DISMISSED,
            ];

        if (! in_array($outcome, $allowed, true)) {
            throw new ConstitutionalViolation(
                sprintf('Outcome [%s] is not a lawful %s verdict.', $outcome, $case->kind),
                'Art. IV §4'
            );
        }

        if ($decidedBy === Verdict::BY_PANEL) {
            $this->assertPanelVerdict($case, $outcome, $attrs);

            return;
        }

        // A jury verdict is recorded only for a case that actually empaneled a
        // jury (mirrors the panel branch's presence guard). jury_id is set only
        // by markJuryEmpaneled, which itself enforces jury_entitled && !waived,
        // so a null jury_id proves no jury sat — decided_by=jury cannot then be
        // used to bypass the panel majority math or fabricate a jury unanimity.
        if ($case->jury_id === null) {
            throw new ConstitutionalViolation(
                'A jury verdict is recorded only for a case that empaneled a jury.',
                'Art. IV §4'
            );
        }

        // A jury verdict must be unanimous (the recorded fact).
        if (($attrs['jury_unanimous'] ?? null) !== true) {
            throw new ConstitutionalViolation(
                'A jury verdict is recorded only when the jury is unanimous.',
                'Art. IV §4'
            );
        }
    }

    /**
     * A panel verdict: for + against == the seated panel size, no tie (panels
     * are odd), and the outcome matches the winning side — "for" carries the
     * affirmative outcome (guilty/liable/for_petitioner), "against" the
     * negative.
     */
    private function assertPanelVerdict(CourtCase $case, string $outcome, array $attrs): void
    {
        $panel = $case->panel;

        if ($panel === null) {
            throw new ConstitutionalViolation(
                'A panel verdict is recorded by the panel that heard the case.',
                'Art. IV §4'
            );
        }

        if (! array_key_exists('panel_vote_for', $attrs) || $attrs['panel_vote_for'] === null
            || ! array_key_exists('panel_vote_against', $attrs) || $attrs['panel_vote_against'] === null) {
            throw new ConstitutionalViolation(
                'A panel verdict records the votes for and against.',
                'Art. IV §4'
            );
        }

        $for = (int) $attrs['panel_vote_for'];
        $against = (int) $attrs['panel_vote_against'];
        $size = (int) $panel->size;

        if ($for + $against !== $size) {
            throw new ConstitutionalViolation(
                sprintf('The panel vote (%d for, %d against) must sum to the panel size of %d.', $for, $against, $size),
                'Art. IV §4'
            );
        }

        if ($for === $against) {
            throw new ConstitutionalViolation(
                'A panel verdict is carried by a majority — an odd panel never ties.',
                'Art. IV §4'
            );
        }

        $affirmative = $for > $against;
        $carries = $affirmative ? self::AFFIRMATIVE_OUTCOMES : self::NEGATIVE_OUTCOMES;

        if (! in_array($outcome, $carries, true)) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Outcome [%s] is not the one the majority carried (%d for, %d against).',
                    $outcome,
                    $for,
                    $against
                ),
                'Art. IV §4'
            );
        }
    }

    /**
     * The verdict actor must sit on THIS case's panel — a seat on the court is
     * not enough (JudicialActor::seat proves the court seat; this proves the
     * panel membership). The entry layer (CaseController::verdict) calls this
     * before recordVerdict.
     */
    public function assertActorOnPanel(CourtCase $case, JudicialSeat $seat): void
    {
        $panel = $case->panel;

        if ($panel === null) {
            throw new ConstitutionalViolation(
                'A verdict is recorded by a judge on the panel that heard the case — no panel is seated.',
                'Art. IV §4'
            );
        }

        $onPanel = PanelJudge::query()
            ->where('panel_id', (string) $panel->id)
            ->where('judicial_seat_id', (string) $seat->id)
            ->where('status', PanelJudge::STATUS_SEATED)
            ->whereNull('deleted_at')
            ->exists();

        if (! $onPanel) {
            throw new ConstitutionalViolation(
                'A verdict is recorded by a judge seated on THIS case\'s panel (Art. IV §4).',
                'Art. IV §4'
            );
        }
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Guard the legal ESM edge — every transition flows through here. */
    private function assertTransition(CourtCase $case, string $to): void
    {
        $allowed = self::TRANSITIONS[$case->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new ConstitutionalViolation(
                sprintf('Illegal case transition %s → %s (ESM-CASE).', $case->status, $to),
                'Art. IV §4'
            );
        }
    }

    private function seal(string $event, CourtCase $case, array $extra): void
    {
        $this->audit->append(
            module: 'judiciary',
            event: $event,
            payload: array_merge(['case_id' => (string) $case->id, 'docket_no' => (string) $case->docket_no], $extra),
            ref: (string) $case->filed_via_form,
            jurisdictionId: (string) $case->jurisdiction_id,
        );
    }

    /**
     * "case-{YYYY}-{NNN}" per judiciary per year under pg_advisory_xact_lock —
     * the EnactmentService::allocateActNumber pattern. The unique
     * (judiciary_id, docket_no) index is the DB backstop.
     */
    private function allocateDocketNumber(string $judiciaryId): string
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('case_docket:' || ?))", [$judiciaryId]);

        $year = now()->year;

        $taken = CourtCase::query()
            ->where('judiciary_id', $judiciaryId)
            ->where('docket_no', 'like', "case-{$year}-%")
            ->withTrashed()
            ->count();

        return sprintf('case-%d-%03d', $year, $taken + 1);
    }
}
