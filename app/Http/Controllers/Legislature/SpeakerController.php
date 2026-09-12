<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Models\AuditEntry;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\LegislatureSession;
use App\Models\RemovalProceeding;
use App\Models\VoteCast;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C7 — SpeakerTools (PHASE_C_DESIGN_frontend.md §B.7).
 *
 * Route table (registered in routes/web.php by the route owner):
 *
 *   GET  /legislatures/{legislature}/speaker             show
 *   POST /legislatures/{legislature}/priorities          storePriority  F-SPK-006
 *
 * Gating (§B.7): members of this chamber only — R-10 gets the live
 * launchpad, R-09 the read-only "what the Speaker can do" variant
 * (actions hidden; the engine rejects them anyway). Non-members 302 to
 * the chamber page. No Speaker elected → 302 to the session console's
 * speaker-election state (the chamber cannot conduct business it has no
 * neutral chair for).
 */
class SpeakerController extends Controller
{
    use ResolvesChamber;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
    ) {
    }

    public function show(Request $request, Legislature $legislature)
    {
        $legislature->loadMissing('jurisdiction:id,name,slug,parent_id,adm_level');

        $viewer = $this->viewerMember($legislature, $request->user());

        if ($viewer === null) {
            return redirect("/legislatures/{$legislature->id}/chamber")
                ->with('status', 'Speaker tools are a member surface — chamber business publishes to the public record.');
        }

        if ($legislature->speaker_id === null) {
            return redirect("/legislatures/{$legislature->id}/session")
                ->with('status', 'No Speaker is seated — the Speaker election is the first order of the first session (F-LEG-008).');
        }

        $isSpeaker = $this->viewerIsSpeaker($legislature, $viewer);

        $targetSession = LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', [LegislatureSession::STATUS_SCHEDULED, LegislatureSession::STATUS_OPEN])
            ->orderBy('scheduled_for')
            ->first();

        $speakerMember = LegislatureMember::query()
            ->with('user:id,display_name')
            ->find($legislature->speaker_id);

        $members = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->current()
            ->with('user:id,display_name')
            ->orderBy('seat_no')
            ->get();

        $priorityRecord = $this->priorities($legislature, $members);

        return Inertia::render('Legislature/SpeakerTools', [
            'workspace' => \App\Support\LegislatureWorkspace::for($legislature, $legislature->jurisdiction, true),
            'jurisdictionContext' => $legislature->jurisdiction ? \App\Support\JurisdictionContext::for($legislature->jurisdiction) : null,
            'surface'     => SurfaceMeta::for('legislature/speaker-tools'),
            'legislature' => $this->legislatureProps($legislature),
            'speaker'     => [
                'member_id' => (string) $legislature->speaker_id,
                'name'      => ($speakerMember?->user?->display_name ?: 'Member'),
                'is_viewer' => $isSpeaker,
            ],
            'readOnly' => ! $isSpeaker,
            'tieBreaks' => $this->tieBreaks($legislature),
            'priorities' => $priorityRecord['rows'],
            'priorityPages' => $priorityRecord['pages'],
            'prioritySession' => $targetSession !== null ? [
                'id'         => (string) $targetSession->id,
                'session_no' => (int) $targetSession->session_no,
                'status'     => $targetSession->status,
            ] : null,
            'members' => $members->map(fn (LegislatureMember $m) => [
                'id'   => (string) $m->id,
                'name' => ($m?->user?->display_name ?: 'Member'),
            ])->values()->all(),
            'pendingProceedings' => $this->pendingProceedings($legislature),
            'can' => [
                'facilitate' => $isSpeaker && $targetSession !== null,
                'preside'    => $isSpeaker,
            ],
            'urls' => [
                'priorities' => "/legislatures/{$legislature->id}/priorities",
                'session'    => "/legislatures/{$legislature->id}/session",
                'committees' => "/legislatures/{$legislature->id}/committees",
                'oversight'  => "/legislatures/{$legislature->id}/oversight",
            ],
        ]);
    }

    /** F-SPK-006 — Member Priority Communication Facilitation. */
    public function storePriority(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'member_id'  => ['required', 'uuid'],
            'text'       => ['required', 'string', 'max:1000'],
        ]);

        $this->engine->file('F-SPK-006', $request->user(), [
            'session_id'      => $validated['session_id'],
            'member_id'       => $validated['member_id'],
            'text'            => $validated['text'],
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
        ]);

        return back()->with(
            'status',
            'Member priority facilitated (F-SPK-006) — added to the session\'s unlocked agenda tail; the filing is the priorities log.'
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    /**
     * The tie-break record — every F-SPK-004 cast this chamber has seen
     * (chamber_votes.speaker_tiebreak), with the pre-break tally restored
     * for the mockup grammar "4–4 → Speaker broke the tie".
     */
    private function tieBreaks(Legislature $legislature): array
    {
        return ChamberVote::query()
            ->where('legislature_id', $legislature->id)
            ->where('speaker_tiebreak', true)
            ->orderByDesc('decided_at')
            ->with('tallies')
            ->get()
            ->map(function (ChamberVote $vote) {
                $cast = VoteCast::query()
                    ->where('vote_id', $vote->id)
                    ->where('is_tiebreak', true)
                    ->first();

                $yes = (int) $vote->tallies->sum('yes');
                $no  = (int) $vote->tallies->sum('no');

                // Restore the pre-break tally for the record line.
                $preYes = $cast?->value === VoteCast::VALUE_YES ? $yes - 1 : $yes;
                $preNo  = $cast?->value === VoteCast::VALUE_NO ? $no - 1 : $no;

                $billHref = $vote->votable_type === 'bill' && $vote->votable_id !== null
                    ? '/bills/' . $vote->votable_id
                    : null;

                return [
                    'vote_id'   => (string) $vote->id,
                    'context'   => $vote->vote_type . ($vote->stage !== null ? " · {$vote->stage}" : ''),
                    'tally'     => "{$preYes}–{$preNo}",
                    'cast'      => $cast?->value,
                    'outcome'   => sprintf('%s %d–%d', $vote->outcome, $yes, $no),
                    'at'        => $vote->decided_at?->toIso8601String(),
                    'vote_href' => $billHref,
                    'explanation' => $cast?->explanation,
                ];
            })
            ->all();
    }

    /**
     * The priorities log = the F-SPK-006 filings themselves (audit chain,
     * event session.member_priority), joined to live session/agenda state.
     */
    private function priorities(Legislature $legislature, $members): array
    {
        $names = $members->mapWithKeys(fn (LegislatureMember $m) => [
            (string) $m->id => ($m?->user?->display_name ?: 'Member'),
        ]);

        $sessions = LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->get(['id', 'session_no', 'status'])
            ->keyBy(fn (LegislatureSession $s) => (string) $s->id);

        $entries = AuditEntry::query()
            ->where('module', 'legislature')
            ->where('event', 'session.member_priority')
            ->whereIn('payload->session_id', $sessions->keys())
            ->where('rejected', false)
            ->orderByDesc('seq')
            ->simplePaginate(50, ['seq', 'payload', 'occurred_at'], 'priorities_page')
            ->withQueryString();

        $rows = $entries->getCollection()
            ->map(function (AuditEntry $entry) use ($names, $sessions) {
                $payload = (array) $entry->payload;
                $session = $sessions->get((string) ($payload['session_id'] ?? ''));

                return [
                    'id'            => (int) $entry->seq,
                    'who'           => $names[(string) ($payload['member_id'] ?? '')] ?? 'Member',
                    'text'          => (string) ($payload['text'] ?? ''),
                    'when'          => $entry->occurred_at?->toIso8601String(),
                    'session_no'    => $session !== null ? (int) $session->session_no : null,
                    'agenda_status' => $session?->status,
                ];
            })
            ->values()
            ->all();

        return ['rows' => $rows, 'pages' => ['newer' => $entries->previousPageUrl(), 'older' => $entries->nextPageUrl()]];
    }

    /**
     * Pending removal proceedings with the own-case guard surfaced: the
     * Speaker's own case renders blocked (the engine enforces
     * removal.presider — this page shows the block honestly).
     */
    private function pendingProceedings(Legislature $legislature): array
    {
        $speakerId = (string) $legislature->speaker_id;

        return RemovalProceeding::query()
            ->where('legislature_id', $legislature->id)
            ->where('status', '!=', RemovalProceeding::STATUS_CLOSED)
            ->orderBy('created_at')
            ->get()
            ->map(function (RemovalProceeding $proceeding) use ($speakerId) {
                $subjectName = null;

                if ($proceeding->subject_type === 'legislature_members') {
                    $subject = LegislatureMember::query()
                        ->with('user:id,display_name')
                        ->find($proceeding->subject_id);
                    $subjectName = ($subject?->user?->display_name ?: 'Member');
                }

                return [
                    'id'      => (string) $proceeding->id,
                    'kind'    => $proceeding->kind,
                    'subject' => $subjectName ?? ($proceeding->subject_type . ' ' . $proceeding->subject_id),
                    'status'  => $proceeding->status,
                    'presiding_blocked' => $proceeding->subject_type === 'legislature_members'
                        && (string) $proceeding->subject_id === $speakerId,
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
}
