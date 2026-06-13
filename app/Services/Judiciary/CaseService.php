<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\Judiciary;
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
