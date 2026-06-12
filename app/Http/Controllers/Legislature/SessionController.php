<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureSession;
use App\Models\Motion;
use App\Models\PublicRecord;
use App\Models\SessionAttendance;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C3 — SessionConsole (PHASE_C_DESIGN_frontend.md §B.2; surface
 * legislature/session-console).
 *
 * Route-gated to chamber members (R-09..R-13 derive through the member
 * row) + R-29 admin staff; everyone else 302s to the Chamber — sessions
 * are run by members; the minutes publish to the public record.
 *
 * Every POST is one engine filing (F-SPK-001/002/003/008/009,
 * F-LEG-002/006/007, F-LEG-004/005/008/011 casts, F-SPK-004 tie-break);
 * ConstitutionalViolation 422s surface as errors.constitution with the
 * citation verbatim.
 */
class SessionController extends Controller
{
    use ResolvesChamber;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ChamberVotePresenter $votes,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // GET /legislatures/{legislature}/session
    // =========================================================================

    public function show(Request $request, Legislature $legislature): Response|RedirectResponse
    {
        $legislature->loadMissing('jurisdiction');

        $viewer = $this->viewerMember($legislature, $request->user());
        $isAdminStaff = in_array('R-29', $this->roles->rolesFor($request->user()), true);

        if ($viewer === null && ! $isAdminStaff) {
            return redirect("/legislatures/{$legislature->id}/chamber")->with(
                'status',
                'Sessions are run by members; minutes publish to the public record.'
            );
        }

        $session = LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->orderByDesc('session_no')
            ->first();

        $isSpeaker = $viewer !== null
            && $legislature->speaker_id !== null
            && (string) $legislature->speaker_id === (string) $viewer->id;

        $live = $session !== null && in_array($session->status, [
            LegislatureSession::STATUS_SCHEDULED,
            LegislatureSession::STATUS_OPEN,
            LegislatureSession::STATUS_FAILED_QUORUM,
        ], true);

        return Inertia::render('Legislature/SessionConsole', [
            'surface'       => SurfaceMeta::for('legislature/session-console'),
            'legislature'   => $this->legislatureProps($legislature),
            'session'       => $session !== null ? $this->sessionProps($session) : null,
            'dueBanner'     => $this->dueBanner($legislature),
            'motions'       => $session !== null ? $this->motionRows($session) : [],
            'speakerBallot' => $this->speakerBallotProps($legislature),
            'myAttendanceMarked' => $session !== null && $viewer !== null
                && SessionAttendance::query()
                    ->where('session_id', $session->id)
                    ->where('member_id', $viewer->id)
                    ->whereIn('status', SessionAttendance::COUNTED_PRESENT)
                    ->exists(),
            'can' => [
                'call'          => $isSpeaker && ! $live,
                'launchSpeakerBallot' => $viewer !== null && $legislature->speaker_id === null,
                'setAgenda'     => $isSpeaker && $session?->status === LegislatureSession::STATUS_OPEN,
                'publishQuorum' => $isSpeaker && in_array($session?->status, [LegislatureSession::STATUS_OPEN, LegislatureSession::STATUS_FAILED_QUORUM], true),
                'compel'        => $isSpeaker && $session?->status === LegislatureSession::STATUS_FAILED_QUORUM,
                'adjourn'       => ($isSpeaker || $isAdminStaff) && in_array($session?->status, [LegislatureSession::STATUS_OPEN, LegislatureSession::STATUS_FAILED_QUORUM], true),
                'submitMotion'  => $viewer !== null && $session?->status === LegislatureSession::STATUS_OPEN,
                'vote'          => $viewer !== null,
                'statement'     => $viewer !== null,
                'attendance'    => $viewer !== null && $session?->status === LegislatureSession::STATUS_OPEN,
                'isSpeaker'     => $isSpeaker,
            ],
        ]);
    }

    // =========================================================================
    // Engine-backed POSTs
    // =========================================================================

    /** F-SPK-001 — call & open. */
    public function store(Request $request, Legislature $legislature): RedirectResponse
    {
        $this->engine->file('F-SPK-001', $request->user(), [
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'scheduled_for'   => $request->input('scheduled_for'),
            'open_now'        => (bool) $request->boolean('open_now', true),
        ]);

        return back()->with('status', 'Session called and opened (F-SPK-001) — attendance and the quorum call are live.');
    }

    /**
     * F-LEG-008 action=open — launch the speaker balloting. System-filed
     * (no R-10 exists before the first Speaker); guarded to chamber
     * members of a speakerless chamber. Replacement ballots open through
     * an adopted replace_speaker motion instead.
     */
    public function launchSpeakerBallot(Request $request, Legislature $legislature): RedirectResponse
    {
        $viewer = $this->viewerMember($legislature, $request->user());

        abort_unless($viewer !== null, 403, 'Speaker ballotings concern chamber members.');
        abort_unless(
            $legislature->speaker_id === null,
            422,
            'The chamber has a Speaker — replacement runs through a replace_speaker motion (Art. II §3).'
        );

        $this->engine->file('F-LEG-008', null, [
            'action'          => 'open',
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
        ]);

        return back()->with('status', 'Speaker balloting opened — every serving member files their ranking (F-LEG-008, supermajority RCV).');
    }

    /** F-LEG-002 — own attendance. */
    public function attendance(Request $request, LegislatureSession $session): RedirectResponse
    {
        $this->engine->file('F-LEG-002', $request->user(), [
            'session_id'      => (string) $session->id,
            'jurisdiction_id' => (string) $session->legislature?->jurisdiction_id,
        ]);

        return back()->with('status', 'Attendance registered (F-LEG-002) — feeds the quorum call, never a vote denominator.');
    }

    /** F-SPK-003 — quorum count publication. */
    public function quorum(Request $request, LegislatureSession $session): RedirectResponse
    {
        $result = $this->engine->file('F-SPK-003', $request->user(), [
            'session_id'      => (string) $session->id,
            'jurisdiction_id' => (string) $session->legislature?->jurisdiction_id,
        ]);

        $met = (bool) ($result->recorded['met'] ?? false);

        return back()->with('status', $met
            ? 'Quorum met — published to the public record (F-SPK-003).'
            : 'Quorum NOT met — WF-LEG-20: compel attendance (F-SPK-008), then re-count or adjourn & reschedule.');
    }

    /** F-SPK-002 — agenda tail reorder / slot-1 acknowledgment. */
    public function agenda(Request $request, LegislatureSession $session): RedirectResponse
    {
        $this->engine->file('F-SPK-002', $request->user(), [
            'session_id'            => (string) $session->id,
            'jurisdiction_id'       => (string) $session->legislature?->jurisdiction_id,
            'items'                 => $request->input('items', []),
            'mark_addressed_ref_id' => $request->input('mark_addressed_ref_id'),
        ]);

        return back()->with('status', 'Agenda updated (F-SPK-002) — the locked head is engine-composed and immutable.');
    }

    /** F-LEG-007 — motion (opens its procedural_motion vote in the same filing). */
    public function motion(Request $request, LegislatureSession $session): RedirectResponse
    {
        $this->engine->file('F-LEG-007', $request->user(), [
            'session_id'      => (string) $session->id,
            'jurisdiction_id' => (string) $session->legislature?->jurisdiction_id,
            'kind'            => (string) $request->input('kind', ''),
            'text'            => (string) $request->input('text', ''),
            'bill_id'         => $request->input('bill_id'),
            'committee_id'    => $request->input('committee_id'),
            'amendment_text'  => $request->input('amendment_text'),
        ]);

        return back()->with('status', 'Motion submitted (F-LEG-007) — its majority vote is open; every cast publishes.');
    }

    /** F-LEG-006 — statement into the immutable public record. */
    public function statement(Request $request, LegislatureSession $session): RedirectResponse
    {
        $this->engine->file('F-LEG-006', $request->user(), [
            'legislature_id'  => (string) $session->legislature_id,
            'jurisdiction_id' => (string) $session->legislature?->jurisdiction_id,
            'title'           => $request->input('title'),
            'body'            => (string) $request->input('body', ''),
            'subject_type'    => 'legislature_session',
            'subject_id'      => (string) $session->id,
        ]);

        return back()->with('status', 'Statement entered verbatim into the immutable public record (F-LEG-006 · WF-SYS-03).');
    }

    /** F-SPK-008 — attendance compulsion (WF-LEG-20). */
    public function compel(Request $request, LegislatureSession $session): RedirectResponse
    {
        $this->engine->file('F-SPK-008', $request->user(), [
            'session_id'      => (string) $session->id,
            'jurisdiction_id' => (string) $session->legislature?->jurisdiction_id,
        ]);

        return back()->with('status', 'Compulsion order issued (F-SPK-008) — recorded publicly; re-publish the quorum count when members arrive.');
    }

    /** F-SPK-009 — minutes + adjournment; CLK-02 re-arms from the meeting. */
    public function adjourn(Request $request, LegislatureSession $session): RedirectResponse
    {
        $this->engine->file('F-SPK-009', $request->user(), [
            'session_id'      => (string) $session->id,
            'jurisdiction_id' => (string) $session->legislature?->jurisdiction_id,
            'minutes_body'    => (string) $request->input('minutes_body', ''),
            'minutes_title'   => $request->input('minutes_title'),
        ]);

        $dueBy = $session->legislature?->fresh()?->next_meeting_due_by?->toDateString();

        return back()->with('status', sprintf(
            'Session adjourned — minutes sealed to the public record (F-SPK-009).%s',
            $dueBy !== null ? " CLK-02 re-armed: next meeting due by {$dueBy}." : ''
        ));
    }

    /**
     * POST /votes/{vote}/cast — ONE endpoint for every chamber cast; the
     * vote row resolves the canonical form (§B.2/§B.4 "same endpoint, body
     * resolves the form"):
     *   committee body                → F-LEG-005
     *   speaker_elect/replace ballots → F-LEG-008 (rankings)
     *   committee_chair / seat fills  → F-LEG-011 (rankings)
     *   everything else (floor)       → F-LEG-004
     */
    public function cast(Request $request, ChamberVote $vote): RedirectResponse
    {
        $explanation = $request->input('explanation');
        $rankings    = $request->input('rankings');

        if (in_array($vote->vote_type, ['speaker_elect', 'speaker_replace'], true)) {
            $this->engine->file('F-LEG-008', $request->user(), [
                'legislature_id'  => (string) $vote->legislature_id,
                'jurisdiction_id' => (string) $vote->jurisdiction_id,
                'rankings'        => $rankings,
                'explanation'     => $explanation,
            ]);

            return back()->with('status', 'Ranking filed (F-LEG-008) — the ballot closes when every serving member has cast.');
        }

        if (in_array($vote->vote_type, ['committee_chair', 'committee_seat_fill'], true)) {
            $this->engine->file('F-LEG-011', $request->user(), [
                'committee_id'    => (string) $vote->body_id,
                'jurisdiction_id' => (string) $vote->jurisdiction_id,
                'rankings'        => $rankings,
                'explanation'     => $explanation,
            ]);

            return back()->with('status', 'Ranking filed (F-LEG-011).');
        }

        $formId = $vote->body_type === ChamberVote::BODY_COMMITTEE ? 'F-LEG-005' : 'F-LEG-004';

        $this->engine->file($formId, $request->user(), [
            'vote_id'         => (string) $vote->id,
            'jurisdiction_id' => (string) $vote->jurisdiction_id,
            'value'           => $request->input('value'),
            'rankings'        => $rankings,
            'explanation'     => $explanation,
        ]);

        return back()->with('status', "Vote cast ({$formId}) — published with your name" . ($explanation ? ' and explanation' : '') . ' (Art. II §2).');
    }

    /** F-SPK-004 — the only Speaker vote. */
    public function tiebreak(Request $request, ChamberVote $vote): RedirectResponse
    {
        $this->engine->file('F-SPK-004', $request->user(), [
            'vote_id'         => (string) $vote->id,
            'jurisdiction_id' => (string) $vote->jurisdiction_id,
            'value'           => (string) $request->input('value', ''),
            'explanation'     => $request->input('explanation'),
        ]);

        return back()->with('status', 'Tie broken (F-SPK-004) — recomputed against the unchanged peg threshold.');
    }

    // =========================================================================
    // Props assembly
    // =========================================================================

    private function sessionProps(LegislatureSession $session): array
    {
        $session->loadMissing('legislature');

        $attendance = SessionAttendance::query()
            ->where('session_id', $session->id)
            ->with('member.user:id,name,display_name')
            ->get()
            ->sortBy(fn (SessionAttendance $row) => $row->member?->seat_no ?? 999)
            ->values()
            ->map(fn (SessionAttendance $row) => [
                'member_id' => (string) $row->member_id,
                'name'      => $this->memberDisplayName($row->member),
                'seat_no'   => $row->member?->seat_no,
                'seat_kind' => $row->member?->seatKind(),
                'status'    => $row->status,
            ])
            ->all();

        $present = SessionAttendance::query()
            ->where('session_id', $session->id)
            ->whereIn('status', SessionAttendance::COUNTED_PRESENT)
            ->count();

        $minutesHref = null;
        if ($session->minutes_record_id !== null) {
            // public_records.id is the uuid; the PK is `seq` (bigint) — query
            // by the uuid column, not whereKey().
            $seq = PublicRecord::query()->where('id', $session->minutes_record_id)->value('audit_seq');
            $minutesHref = $seq !== null ? "/system/audit-chain?seq={$seq}" : '/system/public-records';
        }

        return [
            'id'                      => (string) $session->id,
            'session_no'              => (int) $session->session_no,
            'status'                  => $session->status,
            'scheduled_for'           => $session->scheduled_for?->toIso8601String(),
            'opened_at'               => $session->opened_at?->toIso8601String(),
            'adjourned_at'            => $session->adjourned_at?->toIso8601String(),
            'serving_at_open'         => $session->serving_at_open,
            'quorum_required'         => $session->quorum_required,
            'quorum_required_by_kind' => $session->quorum_required_by_kind,
            'serving_by_kind'         => $session->serving_by_kind,
            'quorum_met'              => $session->quorum_met,
            'present'                 => $present,
            'attendance'              => $attendance,
            'agenda'                  => $this->agendaDisplay($session),
            'minutes_record_href'     => $minutesHref,
        ];
    }

    /**
     * AgendaStrip shape with the locked head ALWAYS visible: slots 1–2
     * render even when empty — the locked slot existing-but-empty is the
     * constitutional statement (Art. II §2; §7).
     */
    private function agendaDisplay(LegislatureSession $session): array
    {
        $agenda = collect($session->agenda ?? []);

        $kindMap = [
            'emergency_power'       => 'emergency_powers',
            'constitutional_matter' => 'constitutional_matters',
            'committee_report'      => 'committee_report',
            'bill_floor'            => 'priority',
            'motion'                => 'motion',
            'statement'             => 'statement',
            'general'               => 'other',
        ];

        $items = [];

        $emergencies = $agenda->where('kind', 'emergency_power')->values();

        if ($emergencies->isEmpty()) {
            $items[] = [
                'position' => 1, 'locked' => true, 'kind' => 'emergency_powers',
                'title'    => 'No outstanding emergency powers', 'subject' => null,
                'status'   => 'none', 'ref_id' => null,
            ];
        } else {
            foreach ($emergencies as $item) {
                $items[] = [
                    'position' => 1,
                    'locked'   => true,
                    'kind'     => 'emergency_powers',
                    'title'    => (string) ($item['title'] ?? 'Emergency powers review'),
                    'subject'  => null,
                    'status'   => ($item['status'] ?? 'pending') === 'addressed' ? 'done' : ($item['status'] ?? 'pending'),
                    'ref_id'   => $item['ref_id'] ?? null,
                ];
            }
        }

        $items[] = [
            'position' => 2, 'locked' => true, 'kind' => 'constitutional_matters',
            'title'    => 'No constitutional matters before the chamber (Art. IV §5 challenges arrive in Phase E)',
            'subject'  => null, 'status' => 'none', 'ref_id' => null,
        ];

        $position = 3;

        foreach ($agenda->filter(fn (array $item) => ! ($item['locked'] ?? false))->values() as $item) {
            $items[] = [
                'position' => $position++,
                'locked'   => false,
                'kind'     => $kindMap[$item['kind'] ?? 'general'] ?? 'other',
                'raw_kind' => $item['kind'] ?? 'general',
                'title'    => (string) ($item['title'] ?? ''),
                'subject'  => ($item['ref_type'] ?? null) === 'bill' && ($item['ref_id'] ?? null) !== null
                    ? ['type' => 'bill', 'id' => $item['ref_id'], 'href' => "/bills/{$item['ref_id']}"]
                    : null,
                'status'   => $item['status'] ?? 'pending',
                'ref_id'   => $item['ref_id'] ?? null,
            ];
        }

        return $items;
    }

    private function motionRows(LegislatureSession $session): array
    {
        return Motion::query()
            ->where('session_id', $session->id)
            ->with(['movedBy.user:id,name,display_name', 'vote.tallies'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Motion $motion) => [
                'id'      => (string) $motion->id,
                'kind'    => $motion->kind,
                'text'    => $motion->text,
                'status'  => $motion->status,
                'bill_id' => $motion->bill_id !== null ? (string) $motion->bill_id : null,
                'moved_by' => $this->memberDisplayName($motion->movedBy),
                'vote'    => $motion->vote !== null ? $this->votes->tallyProps($motion->vote) : null,
                'casts'   => $motion->vote !== null ? $this->votes->casts($motion->vote) : null,
            ])
            ->values()
            ->all();
    }

    private function speakerBallotProps(Legislature $legislature): ?array
    {
        $ballot = ChamberVote::query()
            ->where('body_type', ChamberVote::BODY_LEGISLATURE)
            ->where('body_id', (string) $legislature->id)
            ->whereIn('vote_type', ['speaker_elect', 'speaker_replace'])
            ->orderByDesc('opened_at')
            ->with('tallies')
            ->first();

        if ($ballot === null && $legislature->speaker_id !== null) {
            return null; // seated speaker, no balloting history to show
        }

        $candidates = \App\Models\LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', \App\Models\LegislatureMember::CURRENT_STATUSES)
            ->with('user:id,name,display_name')
            ->orderBy('seat_no')
            ->get()
            ->map(fn ($member) => [
                'id'   => (string) $member->id,
                'name' => $this->memberDisplayName($member),
            ])
            ->values()
            ->all();

        return [
            'vote'       => $ballot !== null ? $this->votes->tallyProps($ballot) : null,
            'rounds'     => $ballot !== null ? $this->votes->rcvRounds($ballot) : null,
            'candidates' => $candidates,
            'cast_count' => $ballot !== null
                ? \App\Models\VoteCast::query()->where('vote_id', $ballot->id)->count()
                : 0,
        ];
    }

    private function dueBanner(Legislature $legislature): ?array
    {
        $dueBy = $legislature->next_meeting_due_by;

        if ($dueBy === null) {
            return null;
        }

        return [
            'due_at'    => $dueBy->toDateString(),
            'days_left' => (int) CarbonImmutable::now()->startOfDay()->diffInDays($dueBy, false),
        ];
    }
}
