<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Elections\VacancyController;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\AdminOffice;
use App\Models\Appointment;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Election;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MisconductInvestigation;
use App\Models\PublicRecord;
use App\Models\RemovalProceeding;
use App\Models\Term;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VoteCast;
use App\Services\AuditService;
use App\Services\Legislature\OversightService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * FE-C8 — Oversight (PHASE_C_DESIGN_frontend.md §B.8): misconduct intake
 * → investigation → removal proceeding (supermajority of ALL serving —
 * vacancies stay in the denominator) → F-LEG-036 vacancy declaration,
 * closing the loop into the Phase B `/vacancies/{id}` countback page.
 * This page replaces the Phase B `vacancy:declare` dev command.
 *
 * Registered route table (routes/web.php FE-C8 block):
 *
 *   GET  /legislatures/{legislature}/oversight            show
 *   POST /legislatures/{legislature}/investigations       intake             (audited non-form — registry gap)
 *   POST /investigations/{investigation}/refer            referInvestigation (action: investigate | findings, R-29)
 *   POST /legislatures/{legislature}/removal-proceedings  openProceeding     (action: open | designate | open_vote
 *                                                          → F-SPK-007; cast → F-LEG-022)
 *   POST /legislatures/{legislature}/vacancies            declareVacancy     F-LEG-036
 *
 * Office-creation (F-LEG-013) ships as createOffice() — its route
 * (POST /legislatures/{legislature}/admin-office) is NOT yet registered;
 * flagged in the batch report. Floor consents (admin-office act, staff
 * appointments) cast through the shared POST /votes/{vote}/cast.
 */
class OversightController extends Controller
{
    use ResolvesChamber;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly OversightService $oversight,
        private readonly AuditService $audit,
        private readonly ResolvesRoles $roles,
        private readonly ChamberVotePresenter $votes,
    ) {
    }

    // =========================================================================
    // B.8 — the page
    // =========================================================================

    public function show(Request $request, Legislature $legislature)
    {
        $legislature->loadMissing('jurisdiction:id,name');

        $viewer  = $this->viewerMember($legislature, $request->user());
        $isAdmin = $this->isAdminStaff($request->user());

        // Read gate (§B.8): chamber members + R-29. Findings publish to
        // the public record — the register is where citizens read them.
        if ($viewer === null && ! $isAdmin) {
            return redirect("/legislatures/{$legislature->id}/chamber")
                ->with('status', 'Oversight is run by the chamber and its administrative office — findings publish to the public record.');
        }

        $isSpeaker = $this->viewerIsSpeaker($legislature, $viewer);
        $office    = $this->liveOffice($legislature);

        $members = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->current()
            ->with('user:id,name,display_name')
            ->orderBy('seat_no')
            ->get();

        $memberNames = $members->mapWithKeys(fn (LegislatureMember $m) => [
            (string) $m->id => $this->memberDisplayName($m),
        ]);

        return Inertia::render('Legislature/Oversight', [
            'surface'     => SurfaceMeta::for('legislature/oversight'),
            'legislature' => $this->legislatureProps($legislature),
            'adminOffice' => $this->officeProps($legislature, $office, $viewer),
            'investigations' => $office !== null ? $this->investigationRows($office, $memberNames) : [],
            'proceedings'    => $this->proceedingRows($legislature, $memberNames, $viewer),
            'vacancies'      => $this->vacancyRows($legislature, $memberNames),
            'vacancyMachine' => VacancyController::VACANCY_MACHINE,
            'members'        => $members->map(fn (LegislatureMember $m) => [
                'id'      => (string) $m->id,
                'name'    => $this->memberDisplayName($m),
                'seat_no' => $m->seat_no,
                'is_speaker' => $legislature->speaker_id !== null
                    && (string) $legislature->speaker_id === (string) $m->id,
            ])->values()->all(),
            'viewerMemberId' => $viewer !== null ? (string) $viewer->id : null,
            'can' => [
                'intake'         => $request->user() !== null && $office !== null,
                'refer'          => $isAdmin,
                'openProceeding' => $isSpeaker,
                'designate'      => $viewer !== null,
                'declareVacancy' => $viewer !== null,
                'vote'           => $viewer !== null,
                'createOffice'   => $viewer !== null && $office === null,
            ],
            'urls' => [
                'intake'       => "/legislatures/{$legislature->id}/investigations",
                'createOffice' => "/legislatures/{$legislature->id}/admin-office", // NOT YET REGISTERED (reported)
                'proceedings'  => "/legislatures/{$legislature->id}/removal-proceedings",
                'vacancies'    => "/legislatures/{$legislature->id}/vacancies",
            ],
        ]);
    }

    // =========================================================================
    // Writes
    // =========================================================================

    /**
     * Misconduct intake — from any resident, member, or own motion. The
     * catalog carries NO I-ADM intake form (flagged registry gap, chamber
     * ops §D.3): an audited non-form action; the investigation row plus
     * its oversight.investigation_opened chain entry land in one
     * transaction inside OversightService::intake().
     */
    public function intake(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'subject_member_id' => ['required', 'uuid'],
            'summary'           => ['required', 'string', 'max:5000'],
        ]);

        abort_if($request->user() === null, 403);

        $office = $this->liveOffice($legislature);

        if ($office === null) {
            return back()->withErrors([
                'constitution' => 'No administrative office exists — the chamber creates one by majority act (F-LEG-013) before intake can docket (Art. II §2).',
            ]);
        }

        $subject = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereKey($validated['subject_member_id'])
            ->current()
            ->firstOrFail();

        $investigation = $this->oversight->intake(
            $office,
            'legislature_members',
            (string) $subject->id,
            $validated['summary'],
            $request->user(),
            via: 'resident_complaint',
        );

        return back()->with(
            'status',
            "Complaint docketed as {$investigation->code} — intake is open to any resident; the docket is public (I-ADM)."
        );
    }

    /**
     * POST /investigations/{investigation}/refer (R-29) — two doors,
     * body-resolved:
     *   action=investigate → intake → investigating (audited non-form
     *                        action; I-ADM has no catalog form)
     *   action=findings    → publish findings; refer to a removal
     *                        proceeding (presider-less — the chamber
     *                        designates next) or close with no finding
     */
    public function referInvestigation(Request $request, MisconductInvestigation $investigation): RedirectResponse
    {
        abort_unless($this->isAdminStaff($request->user()), 403, 'Investigations are run by administrative-office staff (R-29).');

        if ((string) $request->input('action', 'findings') === 'investigate') {
            if ($investigation->status !== MisconductInvestigation::STATUS_INTAKE) {
                return back()->with('status', "Investigation {$investigation->code} is already past intake ({$investigation->status}).");
            }

            $office      = $investigation->office()->firstOrFail();
            $legislature = $office->legislature()->firstOrFail();

            DB::transaction(function () use ($investigation, $request, $legislature) {
                $investigation->forceFill(['status' => MisconductInvestigation::STATUS_INVESTIGATING])->save();

                $this->audit->append(
                    module: 'legislature',
                    event: 'oversight.investigation_advanced',
                    payload: [
                        'investigation_id' => (string) $investigation->id,
                        'code'             => $investigation->code,
                        'status'           => MisconductInvestigation::STATUS_INVESTIGATING,
                    ],
                    ref: 'I-ADM',
                    actorId: (string) $request->user()->getKey(),
                    jurisdictionId: (string) $legislature->jurisdiction_id,
                );
            });

            return back()->with('status', "Investigation {$investigation->code} is now investigating (I-ADM).");
        }

        $validated = $request->validate([
            'findings' => ['required', 'string', 'max:20000'],
            'refer'    => ['required', 'boolean'],
            'kind'     => ['nullable', 'string', 'in:impeachment,censure,expulsion'],
        ]);

        $investigation = $this->oversight->publishFindings(
            $investigation,
            $validated['findings'],
            (bool) $validated['refer'],
            $validated['kind'] ?? null,
        );

        return back()->with('status', $investigation->status === MisconductInvestigation::STATUS_REFERRED
            ? "Findings published — {$investigation->code} referred to a removal proceeding (F-SPK-007 designates the presider next)."
            : "Findings published — {$investigation->code} closed with no finding; the record stands.");
    }

    /** F-LEG-013 — Administrative Office Creation Act (majority of all serving). */
    public function createOffice(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'nominees'   => ['nullable', 'array'],
            'nominees.*' => ['uuid'],
        ]);

        $this->engine->file('F-LEG-013', $request->user(), [
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'nominees'        => array_values($validated['nominees'] ?? []),
        ]);

        return back()->with(
            'status',
            'Administrative office creation act filed (F-LEG-013) — majority vote open; staff nominees follow the appointment-consent pipeline.'
        );
    }

    /**
     * POST /legislatures/{legislature}/removal-proceedings — the whole
     * proceeding lifecycle on ONE registered endpoint, body-resolved
     * (route comment: "F-SPK-007 + F-LEG-022"):
     *   action=open      → F-SPK-007 {kind, subject_member_id} (Speaker)
     *   action=designate → F-SPK-007 {proceeding_id, presider_member_id}
     *   action=open_vote → F-SPK-007 {proceeding_id} (the presider)
     *   action=cast      → F-LEG-022 {proceeding_id, value, explanation}
     */
    public function openProceeding(Request $request, Legislature $legislature): RedirectResponse
    {
        $action         = (string) $request->input('action', 'open');
        $jurisdictionId = (string) $legislature->jurisdiction_id;

        switch ($action) {
            case 'designate':
                $validated = $request->validate([
                    'proceeding_id'      => ['required', 'uuid'],
                    'presider_member_id' => ['required', 'uuid'],
                ]);

                $this->engine->file('F-SPK-007', $request->user(), [
                    'action'             => 'designate',
                    'proceeding_id'      => $validated['proceeding_id'],
                    'jurisdiction_id'    => $jurisdictionId,
                    'presider_member_id' => $validated['presider_member_id'],
                ]);

                return back()->with('status', 'Presider designated (F-SPK-007) — the engine blocks the subject from presiding.');

            case 'open_vote':
                $validated = $request->validate(['proceeding_id' => ['required', 'uuid']]);

                $this->engine->file('F-SPK-007', $request->user(), [
                    'action'          => 'open_vote',
                    'proceeding_id'   => $validated['proceeding_id'],
                    'jurisdiction_id' => $jurisdictionId,
                ]);

                return back()->with(
                    'status',
                    'Removal vote opened (F-LEG-022) — supermajority of ALL serving; vacancies stay in the denominator (Art. VII).'
                );

            case 'cast':
                $validated = $request->validate([
                    'proceeding_id' => ['required', 'uuid'],
                    'value'         => ['required', 'string', 'in:yes,no,abstain'],
                    'explanation'   => ['nullable', 'string', 'max:2000'],
                ]);

                $this->engine->file('F-LEG-022', $request->user(), [
                    'proceeding_id'   => $validated['proceeding_id'],
                    'jurisdiction_id' => $jurisdictionId,
                    'value'           => $validated['value'],
                    'explanation'     => $validated['explanation'] ?? null,
                ]);

                return back()->with('status', 'Removal vote cast recorded (F-LEG-022) — published with your name · Art. II §2.');
        }

        $validated = $request->validate([
            'kind'              => ['required', 'string', 'in:impeachment,censure,expulsion'],
            'subject_member_id' => ['required', 'uuid'],
        ]);

        $this->engine->file('F-SPK-007', $request->user(), [
            'action'            => 'open',
            'legislature_id'    => (string) $legislature->id,
            'jurisdiction_id'   => $jurisdictionId,
            'kind'              => $validated['kind'],
            'subject_member_id' => $validated['subject_member_id'],
        ]);

        return back()->with(
            'status',
            'Removal proceeding opened (F-SPK-007) — the Speaker presides, never over their own case (Art. II §3).'
        );
    }

    /**
     * F-LEG-036 — Vacancy Declaration (this page closes the FE-B8
     * deferral: the Phase B `vacancy:declare` dev command is replaced by
     * this form). Declarer rule enforced by the PROTECTED validator: the
     * Speaker/system may declare any current seat; a plain member only
     * their own.
     */
    public function declareVacancy(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'member_id' => ['required', 'uuid'],
            'reason'    => ['required', 'string', 'in:resigned,deceased,removed,relocation,other'],
        ]);

        $result = $this->engine->file('F-LEG-036', $request->user(), [
            'member_id'       => $validated['member_id'],
            'reason'          => $validated['reason'],
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
        ]);

        $vacancyId = $result->recorded['vacancy_id'] ?? null;

        return back()->with(
            'status',
            'Vacancy declared (F-LEG-036) — countback queued (Art. II §5 → WF-ELE-03).'
            . ($vacancyId !== null ? " Track it at /vacancies/{$vacancyId}." : '')
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    private function liveOffice(Legislature $legislature): ?AdminOffice
    {
        return AdminOffice::query()
            ->where('legislature_id', $legislature->id)
            ->where('status', '!=', AdminOffice::STATUS_DISSOLVED)
            ->first();
    }

    private function isAdminStaff(?User $user): bool
    {
        return $user !== null && in_array('R-29', $this->roles->rolesFor($user), true);
    }

    /**
     * I-ADM card: the live office + staff terms; with no office, the
     * pending creation proposal (if any) renders its live majority vote.
     */
    private function officeProps(Legislature $legislature, ?AdminOffice $office, ?LegislatureMember $viewer): ?array
    {
        if ($office === null) {
            $proposal = ChamberVoteProposal::query()
                ->where('legislature_id', $legislature->id)
                ->where('proposal_kind', ChamberVoteProposal::KIND_ADMIN_OFFICE_CREATION)
                ->where('status', ChamberVoteProposal::STATUS_OPEN)
                ->first();

            if ($proposal === null) {
                return null;
            }

            $vote = $proposal->vote_id !== null
                ? ChamberVote::query()->with('tallies')->find($proposal->vote_id)
                : null;

            return [
                'status'   => 'proposed',
                'staff'    => [],
                'pending'  => $vote !== null ? [
                    'tally'    => $this->votes->tallyProps($vote),
                    'casts'    => $this->votes->casts($vote),
                    'my_cast'  => $this->memberHasCast($viewer, $vote),
                    'cast_url' => "/votes/{$vote->id}/cast",
                ] : null,
                'consents' => [],
            ];
        }

        $staff = Term::query()
            ->where('legislature_id', $legislature->id)
            ->where('office_kind', 'admin_staff')
            ->where('status', Term::STATUS_ACTIVE)
            ->get()
            ->map(function (Term $term) {
                $user = User::query()->find($term->holder_user_id);

                return [
                    'name'      => $user?->display_name ?: $user?->name ?: 'Staff',
                    'term_ends' => (string) $term->ends_on,
                ];
            })
            ->all();

        // Staffing consents still open (majority floor votes).
        $consents = Appointment::query()
            ->where('appointable_type', 'admin_offices')
            ->where('appointable_id', (string) $office->id)
            ->where('status', Appointment::STATUS_NOMINATED)
            ->get()
            ->map(function (Appointment $appointment) use ($legislature, $viewer) {
                $vote = $appointment->consent_vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($appointment->consent_vote_id)
                    : null;

                $nominee = User::query()->find($appointment->nominee_user_id);

                return [
                    'nominee'  => $nominee?->display_name ?: $nominee?->name ?: 'Nominee',
                    'tally'    => $this->votes->tallyProps($vote),
                    'my_cast'  => $this->memberHasCast($viewer, $vote),
                    'cast_url' => $vote !== null
                        ? "/votes/{$vote->id}/cast"
                        : null,
                ];
            })
            ->all();

        $createdVote = $office->created_by_vote_id !== null
            ? ChamberVote::query()->with('tallies')->find($office->created_by_vote_id)
            : null;

        return [
            'status'     => $office->status,
            'staff'      => $staff,
            'created_by' => $createdVote !== null ? [
                'tally' => $this->votes->tallyProps($createdVote),
            ] : null,
            'pending'    => null,
            'consents'   => $consents,
        ];
    }

    private function investigationRows(AdminOffice $office, $memberNames): array
    {
        $records = PublicRecord::query()
            ->where('subject_type', 'misconduct_investigations')
            ->pluck('audit_seq', 'id');

        return MisconductInvestigation::query()
            ->where('admin_office_id', $office->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (MisconductInvestigation $row) use ($memberNames, $records) {
                $subjectName = $row->subject_type === 'legislature_members'
                    ? ($memberNames[(string) $row->subject_id] ?? 'Member')
                    : $row->subject_type;

                return [
                    'id'      => (string) $row->id,
                    'code'    => $row->code,
                    'subject' => $subjectName,
                    're'      => $row->summary,
                    'state'   => $row->status,
                    'findings_record_href' => $row->findings_record_id !== null
                        && ($seq = $records->get((string) $row->findings_record_id)) !== null
                        ? '/system/audit-chain?seq=' . (int) $seq
                        : null,
                    'urls' => [
                        'refer' => "/investigations/{$row->id}/refer",
                    ],
                ];
            })
            ->all();
    }

    private function proceedingRows(Legislature $legislature, $memberNames, ?LegislatureMember $viewer): array
    {
        return RemovalProceeding::query()
            ->where('legislature_id', $legislature->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (RemovalProceeding $proceeding) use ($legislature, $memberNames, $viewer) {
                $vote = $proceeding->vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($proceeding->vote_id)
                    : null;

                $subjectVacancy = $proceeding->subject_type === 'legislature_members'
                    ? Vacancy::query()
                        ->where('seat_type', 'legislature_members')
                        ->where('seat_id', (string) $proceeding->subject_id)
                        ->orderByDesc('created_at')
                        ->first()
                    : null;

                return [
                    'id'      => (string) $proceeding->id,
                    'kind'    => $proceeding->kind,
                    'subject' => $proceeding->subject_type === 'legislature_members'
                        ? ($memberNames[(string) $proceeding->subject_id] ?? 'Former member')
                        : $proceeding->subject_type,
                    'subject_member_id' => $proceeding->subject_type === 'legislature_members'
                        ? (string) $proceeding->subject_id
                        : null,
                    'presided_by' => $proceeding->presided_by_member_id !== null
                        ? ($memberNames[(string) $proceeding->presided_by_member_id] ?? 'Member')
                        : null,
                    'status'  => $proceeding->status,
                    'outcome' => $proceeding->outcome,
                    'vote'    => $vote !== null ? [
                        'tally'    => $this->votes->tallyProps($vote),
                        'casts'    => $this->votes->casts($vote),
                        'open'     => $vote->status === ChamberVote::STATUS_OPEN,
                        'my_cast'  => $this->memberHasCast($viewer, $vote),
                        'cast_url' => "/votes/{$vote->id}/cast",
                    ] : null,
                    'vacancy' => $subjectVacancy !== null
                        && in_array($proceeding->outcome, [RemovalProceeding::OUTCOME_REMOVED, RemovalProceeding::OUTCOME_EXPELLED], true)
                        ? ['id' => (string) $subjectVacancy->id, 'href' => "/vacancies/{$subjectVacancy->id}", 'status' => $subjectVacancy->status]
                        : null,
                    'urls' => [
                        'lifecycle' => "/legislatures/{$legislature->id}/removal-proceedings",
                    ],
                ];
            })
            ->all();
    }

    private function vacancyRows(Legislature $legislature, $memberNames): array
    {
        return Vacancy::query()
            ->where('legislature_id', $legislature->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Vacancy $vacancy) use ($memberNames) {
                $member = $vacancy->seat_type === 'legislature_members'
                    ? LegislatureMember::query()->with('user:id,name,display_name')->find($vacancy->seat_id)
                    : null;

                $special = $vacancy->special_election_id !== null
                    ? Election::query()->find($vacancy->special_election_id)
                    : null;

                return [
                    'id'      => (string) $vacancy->id,
                    'seat'    => $member?->seat_no,
                    'member'  => $member !== null
                        ? ($this->memberDisplayName($member) ?? $memberNames[(string) $member->id] ?? 'Member')
                        : 'Seat',
                    'status'  => $vacancy->status,
                    'declared_via' => $vacancy->declared_via_form,
                    'countback_href' => "/vacancies/{$vacancy->id}",
                    'special' => $special !== null ? [
                        'scheduled_for' => $special->ranked_opens_at?->toDateString(),
                        'status'        => $special->status,
                    ] : null,
                ];
            })
            ->all();
    }

    /** Whether the member row IS the chamber's Speaker (authoritative pointer). */
    private function viewerIsSpeaker(Legislature $legislature, ?LegislatureMember $member): bool
    {
        return $member !== null
            && $legislature->speaker_id !== null
            && (string) $legislature->speaker_id === (string) $member->id;
    }

    /** Whether the member has already cast on the vote (drives "you have cast" UI). */
    private function memberHasCast(?LegislatureMember $member, ?ChamberVote $vote): bool
    {
        return $member !== null && $vote !== null && VoteCast::query()
            ->where('vote_id', $vote->id)
            ->where('member_id', (string) $member->id)
            ->exists();
    }
}
