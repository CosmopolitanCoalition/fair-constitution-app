<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\AuditEntry;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteePreference;
use App\Models\CommitteeReport;
use App\Models\CommitteeSeat;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\PublicRecord;
use App\Models\VoteCast;
use App\Services\Legislature\CommitteeAssignmentService;
use App\Services\PublicRecordService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C6 — Committees + CommitteeDetail (PHASE_C_DESIGN_frontend.md §B.5/§B.6).
 *
 * Route table (registered in routes/web.php by the route owner — FE-C0/E):
 *
 *   GET  /legislatures/{legislature}/committees                 index
 *   GET  /committees/{committee}                                show
 *   POST /legislatures/{legislature}/committees                 store              F-LEG-009
 *   POST /legislatures/{legislature}/committee-preferences      storePreferences   F-LEG-010
 *   POST /legislatures/{legislature}/committees/assign          assign             F-SPK-005
 *   POST /committees/{committee}/meetings                       storeMeeting       F-CHR-001
 *   POST /meetings/{meeting}/agenda                             meetingAgenda      F-CHR-002
 *   POST /bills/{bill}/refer-to-floor                           referToFloor       F-CHR-003
 *   POST /committees/{committee}/reports                        storeReport        F-CHR-004
 *   POST /meetings/{meeting}/testimony                          testimony          (audited non-form, R-03)
 *   POST /committees/{committee}/chair-ballot                   openChairBallot    F-LEG-011 (open) — re-ballot
 *                                                               affordance; NOT YET REGISTERED in routes/web.php
 *                                                               (normal flow: assign() system-opens the ballot)
 *
 * Casting rides the ONE registered endpoint — POST /votes/{vote}/cast
 * (SessionController::cast resolves F-LEG-004/005/008/011 by vote shape).
 * Every state change flows through ConstitutionalEngine::file() except
 * testimony (documented non-form audited action — registry gap). Role
 * gates live in the handlers; this controller only shapes `can.*` for
 * honest UI (engine 422 is the boundary).
 */
class CommitteeController extends Controller
{
    use ResolvesChamber;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly PublicRecordService $records,
        private readonly ChamberVotePresenter $votes,
    ) {
    }

    // =========================================================================
    // B.5 — Committees (the chamber's committee register + assignment)
    // =========================================================================

    public function index(Request $request, Legislature $legislature): Response
    {
        $legislature->loadMissing('jurisdiction:id,name');

        $viewer    = $this->viewerMember($legislature, $request->user());
        $isSpeaker = $this->viewerIsSpeaker($legislature, $viewer);
        $summary   = $this->legislatureProps($legislature);
        $bicameral = $summary['mode'] === 'bicameral';

        $committees = Committee::query()
            ->where('legislature_id', $legislature->id)
            ->live()
            ->orderBy('created_at')
            ->orderBy('id')
            ->with(['chair.user:id,name,display_name', 'alternate.user:id,name,display_name'])
            ->get();

        $recheckNotes = [];
        foreach (app(CommitteeAssignmentService::class)->recheck($legislature) as $note) {
            $recheckNotes[$note['committee_id']][] = $note['note'];
        }

        $serving = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->current()
            ->with('user:id,name,display_name')
            ->get();

        $prefRows = CommitteePreference::query()
            ->where('legislature_id', $legislature->id)
            ->get()
            ->keyBy(fn (CommitteePreference $row) => (string) $row->member_id);

        return Inertia::render('Legislature/Committees', [
            'surface'     => SurfaceMeta::for('legislature/committees'),
            'legislature' => $summary,
            'committees'  => $committees
                ->map(fn (Committee $committee) => $this->committeeRow($committee, $viewer, $recheckNotes))
                ->all(),
            'pendingProposals' => $this->pendingCreationProposals($legislature, $viewer),
            'allocation'       => $this->allocation($committees, $summary),
            'myPreferences'    => $this->myPreferences($viewer, $prefRows),
            'preferencesState' => [
                'submitted' => $prefRows->count(),
                'serving'   => $serving->count(),
                'pending'   => $serving
                    ->filter(fn (LegislatureMember $m) => ! $prefRows->has((string) $m->id))
                    ->map(fn (LegislatureMember $m) => $this->memberDisplayName($m))
                    ->values()
                    ->all(),
            ],
            'assignment'  => $this->assignmentSnapshot($legislature, $serving),
            'seatMachine' => config('cga.state_machines.committee_seat', []),
            'can'         => [
                'create'            => $viewer !== null,
                'submitPreferences' => $viewer !== null && $committees->isNotEmpty(),
                'runAssignment'     => $isSpeaker
                    && $committees->contains(fn (Committee $c) => $c->status === Committee::STATUS_CREATED),
                'voteChair'         => $viewer !== null,
            ],
            'urls' => [
                'store'       => "/legislatures/{$legislature->id}/committees",
                'preferences' => "/legislatures/{$legislature->id}/committee-preferences",
                'assign'      => "/legislatures/{$legislature->id}/committees/assign",
            ],
            'bicameral' => $bicameral,
        ]);
    }

    /** F-LEG-009 — Committee Creation Act → supermajority chamber vote. */
    public function store(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:160'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'seats'   => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $this->engine->file('F-LEG-009', $request->user(), [
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'name'            => $validated['name'],
            'purpose'         => $validated['purpose'] ?? null,
            'seats'           => (int) $validated['seats'],
        ]);

        return back()->with(
            'status',
            "Committee creation act filed (F-LEG-009) — the supermajority vote is open; the committee exists only on adoption · Art. II §4."
        );
    }

    /** F-LEG-010 — Committee Preference Ranking (rank every committee). */
    public function storePreferences(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'rankings'   => ['required', 'array', 'min:1'],
            'rankings.*' => ['uuid'],
        ]);

        $this->engine->file('F-LEG-010', $request->user(), [
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
            'rankings'        => array_values($validated['rankings']),
        ]);

        return back()->with(
            'status',
            'Committee preferences recorded (F-LEG-010) — input to the assignment algorithm; re-submittable until a run consumes them.'
        );
    }

    /** F-SPK-005 — the assignment run (R-10; the snapshot is the audit payload). */
    public function assign(Request $request, Legislature $legislature): RedirectResponse
    {
        $payload = [
            'legislature_id'  => (string) $legislature->id,
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
        ];

        if ($request->filled('committee_ids')) {
            $payload['committee_ids'] = array_values((array) $request->input('committee_ids'));
        }

        $result = $this->engine->file('F-SPK-005', $request->user(), $payload);

        $placements = count($result->recorded['placements'] ?? []);
        $contests   = count($result->recorded['contests'] ?? []);

        // Chamber ops §C.5: the SYSTEM opens chair ballotings once the
        // assignment seats a committee (F-LEG-011 action=open, actor null
        // — the engine records the system filing on the chain).
        $chairless = Committee::query()
            ->where('legislature_id', $legislature->id)
            ->where('status', Committee::STATUS_SEATED)
            ->whereNull('chair_member_id')
            ->get();

        foreach ($chairless as $committee) {
            $open = ChamberVote::query()
                ->where('votable_type', 'committee')
                ->where('votable_id', (string) $committee->id)
                ->where('vote_type', 'committee_chair')
                ->where('status', ChamberVote::STATUS_OPEN)
                ->exists();

            if (! $open) {
                $this->engine->file('F-LEG-011', null, [
                    'committee_id'    => (string) $committee->id,
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'action'          => 'open',
                ]);
            }
        }

        return back()->with(
            'status',
            "Assignment run complete (F-SPK-005) — {$placements} placement(s), {$contests} contested seat(s) resolved by normalized vote share (ledger #q2)."
        );
    }

    /** F-LEG-011 (action: open) — launch a chair balloting (Speaker). */
    public function openChairBallot(Request $request, Committee $committee): RedirectResponse
    {
        $this->engine->file('F-LEG-011', $request->user(), [
            'committee_id'    => (string) $committee->id,
            'jurisdiction_id' => (string) $committee->legislature()->value('jurisdiction_id'),
            'action'          => 'open',
        ]);

        return back()->with(
            'status',
            "Chair balloting opened for {$committee->name} — whole-legislature ranked-choice vote (F-LEG-011); candidates are the committee's seated members."
        );
    }

    // =========================================================================
    // B.6 — CommitteeDetail (roster · meetings · testimony · votes · reports)
    // =========================================================================

    public function show(Request $request, Committee $committee): Response
    {
        $legislature = $committee->legislature()->with('jurisdiction:id,name')->firstOrFail();

        $viewer      = $this->viewerMember($legislature, $request->user());
        $isSpeaker   = $this->viewerIsSpeaker($legislature, $viewer);
        $seats       = CommitteeSeat::query()
            ->where('committee_id', $committee->id)
            ->live()
            ->with('member.user:id,name,display_name')
            ->get();
        $memberIds   = $seats->pluck('member_id')->map(fn ($id) => (string) $id)->all();
        $isMember    = $viewer !== null && in_array((string) $viewer->id, $memberIds, true);
        $isChair     = $viewer !== null && (string) $committee->chair_member_id === (string) $viewer->id;
        $isAlternate = $viewer !== null && (string) $committee->alternate_member_id === (string) $viewer->id;

        $meeting = CommitteeMeeting::query()
            ->where('committee_id', $committee->id)
            ->whereIn('status', [CommitteeMeeting::STATUS_SCHEDULED, CommitteeMeeting::STATUS_OPEN])
            ->orderByDesc('scheduled_for')
            ->first();

        $bills = Bill::query()
            ->where('committee_id', $committee->id)
            ->orderByDesc('created_at')
            ->get();

        $reports = CommitteeReport::query()
            ->where('committee_id', $committee->id)
            ->get()
            ->keyBy(fn (CommitteeReport $r) => (string) ($r->bill_id ?? ''));

        $recordSeqs = PublicRecord::query()
            ->whereIn('id', $reports->pluck('report_record_id')->filter()->all())
            ->pluck('audit_seq', 'id');

        return Inertia::render('Legislature/CommitteeDetail', [
            'surface'   => SurfaceMeta::for('legislature/committee-detail'),
            'committee' => [
                'id'          => (string) $committee->id,
                'name'        => $committee->name,
                'purpose'     => $committee->purpose,
                'status'      => $committee->status,
                'seats'       => (int) $committee->seats,
                'by_kind'     => $committee->type_a_seats !== null
                    ? ['type_a' => (int) $committee->type_a_seats, 'type_b' => (int) $committee->type_b_seats]
                    : null,
                'legislature' => [
                    'id'   => (string) $legislature->id,
                    'name' => ($legislature->jurisdiction?->name ?? '') . ' legislature',
                    'href' => "/legislatures/{$legislature->id}/committees",
                ],
                'chair'     => $this->chairCard($committee->chair, $seats),
                'alternate' => $this->chairCard($committee->alternate, $seats),
                'members'   => $seats->map(fn (CommitteeSeat $seat) => [
                    'member_id'    => (string) $seat->member_id,
                    'name'         => $this->memberDisplayName($seat->member),
                    'seat_kind'    => $seat->seat_kind,
                    'status'       => $seat->status,
                    'assigned_via' => $seat->assigned_via,
                    'is_chair'     => (string) $committee->chair_member_id === (string) $seat->member_id,
                ])->values()->all(),
            ],
            'meeting' => $meeting !== null ? [
                'id'            => (string) $meeting->id,
                'status'        => $meeting->status,
                'scheduled_for' => $meeting->scheduled_for?->toIso8601String(),
                'agenda'        => array_values((array) $meeting->agenda),
            ] : null,
            'bills'     => $bills->map(fn (Bill $bill) => $this->billCard($bill, $committee, $viewer, $reports, $recordSeqs))->all(),
            'testimony' => $this->testimonyRows($committee),
            'can'       => [
                'call'         => $isChair || $isAlternate,
                'setAgenda'    => $isChair || $isAlternate,
                'vote'         => $isMember || ($isSpeaker && $isMember),
                'refer'        => $isChair || $isAlternate,
                'fileReport'   => $isChair || $isAlternate,
                'testify'      => $request->user() !== null,
            ],
            'urls' => [
                'meetings' => "/committees/{$committee->id}/meetings",
                'reports'  => "/committees/{$committee->id}/reports",
                'cast'     => "/committees/{$committee->id}/votes",  // + /{vote}/cast
            ],
        ]);
    }

    /** F-CHR-001 — Committee Meeting Call (chair / alternate-when-absent). */
    public function storeMeeting(Request $request, Committee $committee): RedirectResponse
    {
        $validated = $request->validate([
            'scheduled_for'     => ['nullable', 'date'],
            'agenda'            => ['nullable', 'array'],
            'agenda.*'          => ['string', 'max:300'],
            'chair_unavailable' => ['nullable', 'boolean'],
        ]);

        $this->engine->file('F-CHR-001', $request->user(), [
            'committee_id'      => (string) $committee->id,
            'jurisdiction_id'   => (string) $committee->legislature()->value('jurisdiction_id'),
            'scheduled_for'     => $validated['scheduled_for'] ?? null,
            'agenda'            => array_values($validated['agenda'] ?? []),
            'chair_unavailable' => (bool) ($validated['chair_unavailable'] ?? false),
        ]);

        return back()->with('status', 'Committee meeting called (F-CHR-001).');
    }

    /** F-CHR-002 — Committee Agenda Setting. */
    public function meetingAgenda(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'agenda'            => ['required', 'array'],
            'agenda.*'          => ['string', 'max:300'],
            'chair_unavailable' => ['nullable', 'boolean'],
        ]);

        $this->engine->file('F-CHR-002', $request->user(), [
            'meeting_id'        => (string) $meeting->id,
            'jurisdiction_id'   => (string) $meeting->committee?->legislature()->value('jurisdiction_id'),
            'agenda'            => array_values($validated['agenda']),
            'chair_unavailable' => (bool) ($validated['chair_unavailable'] ?? false),
        ]);

        return back()->with('status', 'Meeting agenda set (F-CHR-002).');
    }

    /**
     * F-CHR-003 — Bill Referral to Floor. The engine independently
     * enforces the gate (bill must stand `reported` — a passed committee
     * vote); the page's disabled Btn is UX, never the boundary.
     */
    public function referToFloor(Request $request, Bill $bill): RedirectResponse
    {
        $this->engine->file('F-CHR-003', $request->user(), [
            'bill_id'         => (string) $bill->id,
            'jurisdiction_id' => (string) $bill->legislature()->value('jurisdiction_id'),
        ]);

        return back()->with(
            'status',
            'Bill referred to the floor (F-CHR-003) — the floor vote is open at the act\'s threshold class.'
        );
    }

    /** F-CHR-004 — Committee Report Filing (report body → public record). */
    public function storeReport(Request $request, Committee $committee): RedirectResponse
    {
        $validated = $request->validate([
            'title'             => ['required', 'string', 'max:200'],
            'body'              => ['required', 'string', 'max:20000'],
            'bill_id'           => ['nullable', 'uuid'],
            'chair_unavailable' => ['nullable', 'boolean'],
        ]);

        $this->engine->file('F-CHR-004', $request->user(), [
            'committee_id'      => (string) $committee->id,
            'jurisdiction_id'   => (string) $committee->legislature()->value('jurisdiction_id'),
            'title'             => $validated['title'],
            'body'              => $validated['body'],
            'bill_id'           => $validated['bill_id'] ?? null,
            'chair_unavailable' => (bool) ($validated['chair_unavailable'] ?? false),
        ]);

        return back()->with('status', 'Committee report filed (F-CHR-004) — sealed into the public record (WF-SYS-03).');
    }

    /**
     * Testimony → public record (WF-LEG-08). No catalog form exists
     * (registry gap, chamber ops §C.5) — an audited non-form action open
     * to any authenticated resident; the record row is sealed into the
     * audit chain by PublicRecordService inside this transaction.
     */
    public function testimony(Request $request, CommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:10000'],
        ]);

        $user      = $request->user();
        $committee = $meeting->committee()->firstOrFail();

        abort_if($user === null, 403);

        $legislature = $committee->legislature()->with('jurisdiction:id,name')->firstOrFail();

        DB::transaction(function () use ($validated, $user, $committee, $meeting, $legislature) {
            $this->records->publish(
                kind: 'testimony',
                title: sprintf('Testimony — %s hearing', $committee->name),
                body: $validated['text'],
                attrs: [
                    'actor_user_id'   => (string) $user->getKey(),
                    'actor_display'   => $user->display_name ?: $user->name,
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'legislature_id'  => (string) $legislature->id,
                    'via_workflow'    => 'WF-LEG-08',
                    'subject_type'    => 'committee_meetings',
                    'subject_id'      => (string) $meeting->id,
                ],
            );
        });

        return back()->with('status', 'Testimony entered verbatim into the immutable public record · WF-LEG-08 · WF-SYS-03.');
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    /** One committees-register row (B.5). */
    private function committeeRow(Committee $committee, ?LegislatureMember $viewer, array $recheckNotes): array
    {
        $seats = CommitteeSeat::query()
            ->where('committee_id', $committee->id)
            ->live()
            ->with('member.user:id,name,display_name')
            ->get();

        $creationVote = $committee->created_by_vote_id !== null
            ? ChamberVote::query()->with('tallies')->find($committee->created_by_vote_id)
            : null;

        $chairBallot = ChamberVote::query()
            ->where('votable_type', 'committee')
            ->where('votable_id', (string) $committee->id)
            ->where('vote_type', 'committee_chair')
            ->orderByDesc('opened_at')
            ->with('tallies')
            ->first();

        return [
            'id'      => (string) $committee->id,
            'name'    => $committee->name,
            'purpose' => $committee->purpose,
            'seats'   => (int) $committee->seats,
            'status'  => $committee->status,
            'by_kind' => $committee->type_a_seats !== null
                ? ['type_a' => (int) $committee->type_a_seats, 'type_b' => (int) $committee->type_b_seats]
                : null,
            'created_by' => $creationVote !== null ? [
                'vote_id' => (string) $creationVote->id,
                'summary' => $this->voteSummaryLine($creationVote),
                'tally'   => $this->votes->tallyProps($creationVote),
            ] : null,
            'chair'     => $committee->chair !== null ? ['name' => $this->memberDisplayName($committee->chair)] : null,
            'alternate' => $committee->alternate !== null ? ['name' => $this->memberDisplayName($committee->alternate)] : null,
            'members'   => $seats->map(fn (CommitteeSeat $seat) => [
                'name'      => $this->memberDisplayName($seat->member),
                'seat_kind' => $seat->seat_kind,
                'status'    => $seat->status,
            ])->values()->all(),
            'bills_count' => Bill::query()->where('committee_id', $committee->id)->count(),
            'notes'       => $recheckNotes[(string) $committee->id] ?? [],
            'href'        => "/committees/{$committee->id}",
            'chair_ballot' => $chairBallot !== null ? [
                'vote_id'    => (string) $chairBallot->id,
                'status'     => $chairBallot->status,
                'outcome'    => $chairBallot->outcome,
                'cast_count' => $chairBallot->voteCasts()->count(),
                'expected'   => (int) $chairBallot->serving_snapshot,
                'my_cast'    => $this->memberHasCast($viewer, $chairBallot),
                'cast_url'   => "/votes/{$chairBallot->id}/cast",
                'candidates' => $seats->map(fn (CommitteeSeat $seat) => [
                    'id'   => (string) $seat->member_id,
                    'name' => $this->memberDisplayName($seat->member),
                ])->values()->all(),
            ] : null,
            'open_ballot_url' => "/committees/{$committee->id}/chair-ballot",
        ];
    }

    /** Open F-LEG-009 creation proposals with their live supermajority votes. */
    private function pendingCreationProposals(Legislature $legislature, ?LegislatureMember $viewer): array
    {
        return ChamberVoteProposal::query()
            ->where('legislature_id', $legislature->id)
            ->where('proposal_kind', ChamberVoteProposal::KIND_COMMITTEE_CREATION)
            ->where('status', ChamberVoteProposal::STATUS_OPEN)
            ->orderBy('created_at')
            ->get()
            ->map(function (ChamberVoteProposal $proposal) use ($viewer) {
                $vote = $proposal->vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($proposal->vote_id)
                    : null;

                return [
                    'proposal_id' => (string) $proposal->id,
                    'name'        => (string) ($proposal->payload['name'] ?? ''),
                    'seats'       => (int) ($proposal->payload['seats'] ?? 0),
                    'purpose'     => $proposal->payload['purpose'] ?? null,
                    'tally'       => $this->votes->tallyProps($vote),
                    'casts'       => $vote !== null ? $this->votes->casts($vote) : [],
                    'my_cast'     => $this->memberHasCast($viewer, $vote),
                    'cast_url'    => $vote !== null ? "/votes/{$vote->id}/cast" : null,
                ];
            })
            ->all();
    }

    /**
     * The WF-LEG-03 allocation card: P = total committee seats, M =
     * serving members, base floor(P/M), extras to highest normalized
     * share (the engine's own budget arithmetic, displayed).
     */
    private function allocation($committees, array $summary): array
    {
        $p = (int) $committees->sum('seats');
        $m = (int) $summary['serving'];

        $base   = $m > 0 ? intdiv($p, $m) : 0;
        $extras = $m > 0 ? $p % $m : 0;

        return [
            'total_reps'      => $m,
            'committee_count' => $committees->count(),
            'total_seats'     => $p,
            'base'            => $base,
            'extras'          => $extras,
            'share_formula'   => $m > 0
                ? sprintf(
                    '%d committee seat(s) ÷ %d serving member(s) = %d placement(s) each%s',
                    $p,
                    $m,
                    $base,
                    $extras > 0 ? " + {$extras} extra to the highest normalized vote share (ledger #q2)" : ''
                )
                : '—',
        ];
    }

    private function myPreferences(?LegislatureMember $viewer, $prefRows): ?array
    {
        if ($viewer === null) {
            return null;
        }

        $row = $prefRows->get((string) $viewer->id);

        return $row !== null ? [
            'rankings'     => array_values(array_map('strval', (array) $row->rankings)),
            'submitted_at' => $row->submitted_at?->toIso8601String(),
        ] : null;
    }

    /**
     * The latest F-SPK-005 run from the audit chain — the run's complete
     * input/output snapshot IS the filing's payload; the tie-break table
     * displays both contenders' normalized shares (q-ledger #q2
     * transparency).
     */
    private function assignmentSnapshot(Legislature $legislature, $serving): ?array
    {
        $entry = AuditEntry::query()
            ->where('module', 'legislature')
            ->where('event', 'committee.assignment_run')
            ->where('rejected', false)
            ->where('payload->legislature_id', (string) $legislature->id)
            ->orderByDesc('seq')
            ->first();

        if ($entry === null) {
            return null;
        }

        $payload = (array) $entry->payload;

        $names = $serving->mapWithKeys(fn (LegislatureMember $m) => [
            (string) $m->id => $this->memberDisplayName($m),
        ]);

        $committeeNames = Committee::query()
            ->whereIn('id', array_keys((array) ($payload['committees'] ?? [])))
            ->pluck('name', 'id');

        $scale = CommitteeAssignmentService::SHARE_SCALE;

        $tieBreaks = [];
        foreach ((array) ($payload['contests'] ?? []) as $contest) {
            $shares = [];
            foreach ((array) ($contest['contenders'] ?? []) as $contender) {
                $shares[(string) $contender['member_id']] = number_format($contender['share'] / $scale, 4);
            }

            $tieBreaks[] = [
                'committee' => $committeeNames[(string) $contest['committee_id']] ?? $contest['committee_id'],
                'kind'      => $contest['kind'] ?? null,
                'depth'     => $contest['depth'] ?? null,
                'open'      => $contest['open'] ?? null,
                'winners'   => array_map(fn ($id) => [
                    'name'  => $names[(string) $id] ?? $id,
                    'share' => $shares[(string) $id] ?? null,
                ], (array) ($contest['winners'] ?? [])),
                'losers'    => array_map(fn ($id) => [
                    'name'  => $names[(string) $id] ?? $id,
                    'share' => $shares[(string) $id] ?? null,
                ], (array) ($contest['losers'] ?? [])),
            ];
        }

        return [
            'run_at'     => $entry->occurred_at?->toIso8601String() ?? $entry->created_at?->toIso8601String(),
            'audit_seq'  => (int) $entry->seq,
            'placements' => count((array) ($payload['placements'] ?? [])),
            'tie_breaks' => $tieBreaks,
            'exhaustion' => count((array) ($payload['exhaustion'] ?? [])),
        ];
    }

    private function chairCard(?LegislatureMember $member, $seats): ?array
    {
        if ($member === null) {
            return null;
        }

        $member->loadMissing('user:id,name,display_name');

        return [
            'member_id' => (string) $member->id,
            'name'      => $this->memberDisplayName($member),
            'seat_kind' => $seats->firstWhere('member_id', $member->id)?->seat_kind,
        ];
    }

    /** "supermajority 7–1" — the creation-act chip line (B.5). */
    private function voteSummaryLine(ChamberVote $vote): string
    {
        $tallies = $vote->tallies;
        $yes     = (int) $tallies->sum('yes');
        $no      = (int) $tallies->sum('no');

        return sprintf(
            '%s %d–%d · %s',
            $vote->threshold_basis === ChamberVote::BASIS_SUPERMAJORITY ? 'supermajority' : 'majority',
            $yes,
            $no,
            $vote->outcome ?? $vote->status,
        );
    }

    /** One CommitteeDetail bill card (B.6). */
    private function billCard(Bill $bill, Committee $committee, ?LegislatureMember $viewer, $reports, $recordSeqs): array
    {
        $vote = ChamberVote::query()
            ->where('votable_type', 'bill')
            ->where('votable_id', (string) $bill->id)
            ->where('stage', ChamberVote::STAGE_COMMITTEE)
            ->orderByDesc('opened_at')
            ->with('tallies')
            ->first();

        $report    = $reports->get((string) $bill->id);
        $reportSeq = $report !== null ? $recordSeqs->get((string) $report->report_record_id) : null;

        return [
            'id'     => (string) $bill->id,
            'title'  => $bill->title,
            'status' => $bill->status,
            'vote'   => $vote !== null ? [
                'tally'    => $this->votes->tallyProps($vote),
                'casts'    => $this->votes->casts($vote),
                'passed'   => $vote->outcome === ChamberVote::OUTCOME_ADOPTED,
                'open'     => $vote->status === ChamberVote::STATUS_OPEN,
                'my_cast'  => $this->memberHasCast($viewer, $vote),
                'cast_url' => "/votes/{$vote->id}/cast",
            ] : null,
            'referable'     => $bill->status === Bill::STATUS_REPORTED,
            'refer_url'     => "/bills/{$bill->id}/refer-to-floor",
            'report'        => $report !== null ? [
                'filed_at'    => $report->created_at?->toIso8601String(),
                'record_href' => $reportSeq !== null ? '/system/audit-chain?seq=' . (int) $reportSeq : null,
            ] : null,
        ];
    }

    /** Testimony rows for this committee's meetings (public record reads). */
    private function testimonyRows(Committee $committee): array
    {
        $meetingIds = CommitteeMeeting::query()
            ->where('committee_id', $committee->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($meetingIds === []) {
            return [];
        }

        return PublicRecord::query()
            ->where('kind', 'testimony')
            ->where('subject_type', 'committee_meetings')
            ->whereIn('subject_id', $meetingIds)
            ->orderByDesc('seq')
            ->limit(50)
            ->get()
            ->map(fn (PublicRecord $record) => [
                'who'         => $record->actor_display ?? 'Resident',
                'text'        => $record->body,
                'recorded_at' => $record->published_at?->toIso8601String(),
                'seq'         => (int) $record->seq,
                'record_href' => '/system/audit-chain?seq=' . (int) $record->audit_seq,
            ])
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
