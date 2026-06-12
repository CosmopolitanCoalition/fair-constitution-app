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

    /** F-SPK form id → the surface where the live control lives (§B.7). */
    private const FORM_SURFACES = [
        'F-SPK-001' => 'session',
        'F-SPK-002' => 'session',
        'F-SPK-003' => 'session',
        'F-SPK-004' => 'session',
        'F-SPK-005' => 'committees',
        'F-SPK-006' => 'speaker',     // this page's queue
        'F-SPK-007' => 'oversight',
        'F-SPK-008' => 'session',
        'F-SPK-009' => 'session',
    ];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
    ) {
    }

    public function show(Request $request, Legislature $legislature)
    {
        $legislature->loadMissing('jurisdiction:id,name');

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
            ->with('user:id,name,display_name')
            ->find($legislature->speaker_id);

        $members = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->current()
            ->with('user:id,name,display_name')
            ->orderBy('seat_no')
            ->get();

        return Inertia::render('Legislature/SpeakerTools', [
            'surface'     => SurfaceMeta::for('legislature/speaker-tools'),
            'legislature' => $this->legislatureProps($legislature),
            'speaker'     => [
                'member_id' => (string) $legislature->speaker_id,
                'name'      => $this->memberDisplayName($speakerMember),
                'is_viewer' => $isSpeaker,
            ],
            'readOnly' => ! $isSpeaker,
            'forms'    => $this->formCards($legislature),
            'tieBreaks' => $this->tieBreaks($legislature),
            'priorities' => $this->priorities($legislature, $members),
            'prioritySession' => $targetSession !== null ? [
                'id'         => (string) $targetSession->id,
                'session_no' => (int) $targetSession->session_no,
                'status'     => $targetSession->status,
            ] : null,
            'members' => $members->map(fn (LegislatureMember $m) => [
                'id'   => (string) $m->id,
                'name' => $this->memberDisplayName($m),
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
     * The 9 F-SPK cards: registry name/roles/citation from SurfaceMeta
     * (FormRegistry-backed), plus the launchpad target — this page links
     * to where each control actually lives, never duplicating a console.
     */
    private function formCards(Legislature $legislature): array
    {
        $surfaceForms = SurfaceMeta::for('legislature/speaker-tools')['forms'];

        $hrefs = [
            'session'    => "/legislatures/{$legislature->id}/session",
            'committees' => "/legislatures/{$legislature->id}/committees",
            'oversight'  => "/legislatures/{$legislature->id}/oversight",
            'speaker'    => null, // this page
        ];

        return array_values(array_map(function (array $form) use ($hrefs) {
            $target = self::FORM_SURFACES[$form['id']] ?? 'session';

            return $form + [
                'surface'      => $target,
                'surface_href' => $hrefs[$target],
            ];
        }, $surfaceForms));
    }

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
            (string) $m->id => $this->memberDisplayName($m),
        ]);

        $sessions = LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->get()
            ->keyBy(fn (LegislatureSession $s) => (string) $s->id);

        return AuditEntry::query()
            ->where('module', 'legislature')
            ->where('event', 'session.member_priority')
            ->where('rejected', false)
            ->orderByDesc('seq')
            ->limit(50)
            ->get()
            ->filter(function (AuditEntry $entry) use ($sessions) {
                $sessionId = (string) (((array) $entry->payload)['session_id'] ?? '');

                return $sessionId !== '' && $sessions->has($sessionId);
            })
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
                        ->with('user:id,name,display_name')
                        ->find($proceeding->subject_id);
                    $subjectName = $this->memberDisplayName($subject);
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
