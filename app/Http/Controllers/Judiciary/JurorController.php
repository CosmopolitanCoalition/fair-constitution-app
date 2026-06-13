<?php

namespace App\Http\Controllers\Judiciary;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\CourtCase;
use App\Models\Jury;
use App\Models\JuryMember;
use App\Services\AuditService;
use App\Support\SurfaceMeta;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-E6 — Juror view (PHASE_E_DESIGN_frontend.md §B.6; surface
 * judiciary/juror-view; mockups/judiciary/juror-view.html).
 *
 *   GET  /judiciary/jury/{summons} — the summoned juror's surface: the
 *        6-step service stepper, the summons facts (drawn / pool / draw
 *        integrity → audit chain / report / where + the F-JDG-002 source
 *        card), the voir-dire conflict questionnaire, the two Art. II §8
 *        protections, and the locked deliberation room.
 *   POST /judiciary/jury/{summons}/screening — the voir-dire answers.
 *
 * PUBLIC-READ POSTURE (the defining Phase E rule): the CASE this summons
 * belongs to is public record (Art. II §2), readable via the docket; the
 * one real per-record gate in the phase is the SCREENING questionnaire —
 * it binds to the R-22 summons holder (you cannot answer another juror's
 * questionnaire). `{summons}` is a jury_members row; ownership is enforced
 * here, never as a page-level 403 on the public case.
 *
 * The screening answers are NOT a constitutional instrument — they are a
 * record the court reads (the q-ledger deferral, §B.6) — so the submit
 * rides this thin endpoint and the AuditService append-only chain, NOT
 * ConstitutionalEngine::file(). The juror records their own answers and the
 * member advances summoned → screening; the actual voir-dire EXCUSAL is the
 * panel judge's R-19/R-20 action (JuryService::excuseAndReplace), never the
 * juror's. `flagged` here is the juror's honest self-report, the record the
 * judge then reads.
 */
class JurorController extends Controller
{
    /**
     * The five server-authored conflict questions (juror-view.html
     * QUESTIONS[]). voir dire removes CONFLICTS only — never opinions,
     * demographics, or politics (Art. IV §4).
     *
     * @var list<array{id: string, text: string}>
     */
    private const QUESTIONS = [
        ['id' => 'q1', 'text' => 'Do you know the accused, the advocates, or any listed witness personally?'],
        ['id' => 'q2', 'text' => 'Do you have any financial interest in the outcome of this case?'],
        ['id' => 'q3', 'text' => 'Were you involved in the events at issue, or in the investigation?'],
        ['id' => 'q4', 'text' => 'Have you formed a fixed opinion about this case from coverage or conversation?'],
        ['id' => 'q5', 'text' => 'Have you served on a jury or panel in a related case?'],
    ];

    /** jury_members.screening_status → the 6-step service stepper position. */
    private const SERVICE_STATE = [
        JuryMember::SCREENING_SUMMONED => 'summoned',
        JuryMember::SCREENING_SCREENING => 'conflict_screening',
        JuryMember::SCREENING_CLEARED => 'conflict_screening',
        JuryMember::SCREENING_EMPANELED => 'empaneled',
        JuryMember::SCREENING_EXCUSED => 'discharged',
        JuryMember::SCREENING_DISCHARGED => 'discharged',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * The summons surface. The case is public-readable; the screening form
     * gates to the R-22 holder of THIS summons via `can.submitScreening`.
     */
    public function show(Request $request, JuryMember $summons): Response
    {
        $summons->loadMissing(['jury.case.judiciary.jurisdiction', 'jury.eligibleJurisdiction']);

        $jury = $summons->jury;
        $case = $jury?->case;

        abort_if($jury === null || $case === null, 404, 'This summons has no case on the docket.');

        $isHolder = $request->user() !== null
            && (string) $summons->user_id === (string) $request->user()->getKey();

        $serviceState = self::SERVICE_STATE[$summons->screening_status] ?? 'summoned';

        // The juror can answer only their OWN summons, and only while still
        // summoned/screening (cleared/empaneled/excused/discharged are
        // post-voir-dire — the questionnaire renders read-only then). The
        // engine/court owns the authoritative outcome regardless.
        $canSubmit = $isHolder && in_array(
            $summons->screening_status,
            [JuryMember::SCREENING_SUMMONED, JuryMember::SCREENING_SCREENING],
            true,
        );

        $answers = $this->recordedAnswers($summons);

        return Inertia::render('Judiciary/JurorView', [
            'surface' => SurfaceMeta::for('judiciary/juror-view'),
            'summons' => $this->summonsProps($summons, $jury, $case),
            'questions' => self::QUESTIONS,
            'serviceState' => $serviceState,
            'deliberationRoom' => [
                // The room unlocks only when the case is actually deliberating —
                // an engine snapshot off the case status, never a client toggle.
                'unlocked' => $case->status === CourtCase::STATUS_DELIBERATION,
            ],
            'recordedAnswers' => $answers,
            // null (unanswered) | 'flagged' | 'clean' — the juror's own
            // self-report echo, read back from the recorded answers.
            'recordedOutcome' => $answers === null
                ? null
                : (in_array('yes', array_values($answers), true) ? 'flagged' : 'clean'),
            'can' => [
                'submitScreening' => $canSubmit,
            ],
        ]);
    }

    /**
     * The voir-dire answers — a record on the case attached to THIS summons,
     * R-22 of THIS summons only. A thin endpoint, not the constitutional
     * engine: juror answers are a record the court reads, not an instrument
     * (the q-ledger deferral, §B.6). Recording advances summoned → screening;
     * the panel judge then acts on a flagged conflict (R-19/R-20), never the
     * juror.
     */
    public function screening(Request $request, JuryMember $summons): RedirectResponse
    {
        abort_unless(
            $request->user() !== null
                && (string) $summons->user_id === (string) $request->user()->getKey(),
            403,
            'You can answer only your own jury screening (R-22 of this summons).'
        );

        abort_unless(
            in_array(
                $summons->screening_status,
                [JuryMember::SCREENING_SUMMONED, JuryMember::SCREENING_SCREENING],
                true,
            ),
            422,
            'Screening is closed for this summons — voir dire has moved on.'
        );

        // Normalize each question to a yes/no record; anything else is "no".
        $answers = [];
        foreach (self::QUESTIONS as $q) {
            $answers[$q['id']] = $request->input("answers.{$q['id']}") === 'yes' ? 'yes' : 'no';
        }

        $flagged = in_array('yes', array_values($answers), true);

        // Record the juror's own answers to the append-only chain (the record
        // the panel reads) and advance summoned → screening. The court's
        // voir-dire outcome (excuse + replacement draw) is its own R-19/R-20
        // action — recording here never excuses the juror.
        if ($summons->screening_status === JuryMember::SCREENING_SUMMONED) {
            $summons->forceFill(['screening_status' => JuryMember::SCREENING_SCREENING])->save();
        }

        $this->audit->append(
            module: 'judiciary',
            event: 'jury.screening_answered',
            payload: [
                'jury_member_id' => (string) $summons->id,
                'jury_id' => (string) $summons->jury_id,
                'answers' => $answers,
                'flagged' => $flagged,
            ],
            ref: 'F-JDG-002',
            actorId: (string) $request->user()->getKey(),
        );

        return back()->with(
            'status',
            $flagged
                ? 'Screening answers recorded — flagged for voir dire review (Art. IV §4 · WF-JUD-04). A panel judge follows up; if a conflict is confirmed you are excused without penalty and the draw selects a replacement.'
                : 'Screening answers recorded — no conflicts declared (Art. IV §4 · WF-JUD-04). You remain in the panel pool; empanelment is confirmed at voir dire.'
        );
    }

    // -------------------------------------------------------------------------

    /**
     * The summons facts the surface renders. Timezone-aware due dates are
     * static citations (the live-countdown a11y is a flagged all-phases
     * deferral); the draw seed lives on the published audit chain.
     *
     * @return array<string, mixed>
     */
    private function summonsProps(JuryMember $summons, Jury $jury, CourtCase $case): array
    {
        $tz = $case->judiciary?->jurisdiction?->timezone
            ?? $jury->eligibleJurisdiction?->timezone
            ?? 'UTC';

        $courtName = $case->judiciary?->court_name
            ?? ($case->judiciary?->jurisdiction?->name !== null
                ? "{$case->judiciary->jurisdiction->name} court"
                : 'court');

        return [
            'id' => (string) $summons->id,
            'seat_kind' => $summons->seat_kind,
            'service_state' => self::SERVICE_STATE[$summons->screening_status] ?? 'summoned',
            'screening_status' => $summons->screening_status,
            'case' => [
                'id' => (string) $case->id,
                'title' => $case->title,
                'href' => "/cases/{$case->id}",
            ],
            'drawn_at' => $this->localCitation($jury->created_at, $tz),
            'pool_size' => (int) $jury->pool_size,
            'pool_label' => sprintf(
                '%s eligible jurisdictionally associated residents of %s',
                number_format((int) $jury->pool_size),
                $jury->eligibleJurisdiction?->name ?? 'the jurisdiction',
            ),
            'report_at' => $this->localCitation($jury->report_on, $tz),
            'location' => sprintf(
                '%s — %d jurors + %d alternates will be empaneled',
                $courtName,
                (int) $jury->seats,
                (int) $jury->alternates,
            ),
            // The draw seed is published to the chain — anyone can verify it.
            'seed_audit_href' => '/audit-chain',
        ];
    }

    /**
     * The juror's previously recorded answers (read back from the audit
     * chain) so a screened/discharged summons renders read-only with the
     * positions on the record. Latest entry wins.
     *
     * @return array<string, string>|null
     */
    private function recordedAnswers(JuryMember $summons): ?array
    {
        $payload = AuditEntry::query()
            ->where('module', 'judiciary')
            ->where('event', 'jury.screening_answered')
            ->where('payload->jury_member_id', (string) $summons->id)
            ->orderByDesc('seq')
            ->value('payload');

        // AuditEntry casts `payload` to array; defensively decode a raw string.
        $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true);

        return is_array($decoded['answers'] ?? null) ? $decoded['answers'] : null;
    }

    /** A timezone-aware "{local} · shown in your timezone · stored as UTC" citation. */
    private function localCitation(mixed $instant, string $tz): ?string
    {
        if ($instant === null) {
            return null;
        }

        $at = CarbonImmutable::parse($instant)->setTimezone($tz);

        return sprintf(
            '%s · shown in %s · stored as UTC',
            $at->format('Y-m-d H:i'),
            $tz,
        );
    }
}
