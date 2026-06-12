<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Legislature\Concerns\ResolvesChamber;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Election;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\ReferendumQuestion;
use App\Models\VoteCast;
use App\Services\ReferendumService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-C9 — Referendums (PHASE_C_DESIGN_frontend.md §B.9).
 *
 *   GET  /legislatures/{legislature}/referendums   index
 *   POST /legislatures/{legislature}/referendums   store   F-LEG-023
 *   POST /laws/{law}/referendum-modification       modify  F-LEG-034 (CLK-19 gate)
 *
 * Public read (any authenticated resident); delegation/modification gated
 * R-09 by the ENGINE (the page explains, the handler enforces). The
 * threshold is DERIVED from the act type — no API ever accepts a
 * threshold input (Art. II §6); the delegateForm prop carries the derived
 * value per act type so the field renders read-only.
 */
class ReferendumController extends Controller
{
    use ResolvesChamber;

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ReferendumService $referendums,
        private readonly ChamberVotePresenter $votes,
    ) {
    }

    public function index(Request $request, Legislature $legislature): Response
    {
        $legislature->loadMissing('jurisdiction:id,name,slug');

        $viewer = $this->viewerMember($legislature, $request->user());

        return Inertia::render('Legislature/Referendums', [
            'surface'      => SurfaceMeta::for('legislature/referendums'),
            'legislature'  => $this->legislatureProps($legislature),
            'machine'      => config('cga.state_machines.referendum_question'),
            'pending'      => $this->pendingProposalRows($legislature, $viewer),
            'queue'        => $this->queueRows($legislature),
            'results'      => $this->resultRows($legislature),
            'delegateForm' => [
                'actTypes' => array_map(fn (string $type) => [
                    'value'             => $type,
                    'threshold_derived' => ReferendumService::deriveThreshold($type),
                ], ReferendumQuestion::ACT_TYPES),
            ],
            'can' => [
                'delegate' => $viewer !== null,
                // per-law modify map rides each results row ('modifiable').
                'modify'   => $viewer !== null,
            ],
            'urls' => [
                'delegate' => "/legislatures/{$legislature->id}/referendums",
            ],
        ]);
    }

    /** F-LEG-023 — Referendum Delegation Vote (supermajority resolution). */
    public function store(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'question'            => ['required', 'string', 'max:2000'],
            'law_text'            => ['required', 'string', 'max:50000'],
            'act_type'            => ['required', 'string'],
            'targets_setting_key' => ['nullable', 'string', 'max:64'],
            'proposed_value'      => ['nullable'],
        ]);

        $this->engine->file('F-LEG-023', $request->user(), [
            'legislature_id'      => (string) $legislature->id,
            'jurisdiction_id'     => (string) $legislature->jurisdiction_id,
            'question'            => $validated['question'],
            'law_text'            => $validated['law_text'],
            'act_type'            => $validated['act_type'],
            'targets_setting_key' => $validated['targets_setting_key'] ?? null,
            'proposed_value'      => $validated['proposed_value'] ?? null,
        ]);

        return back()->with(
            'status',
            'Delegation filed (F-LEG-023) — supermajority floor vote open. On adoption the question '
            . 'queues to the next jurisdiction-wide ballot (WF-ELE-07); the threshold is derived from '
            . 'the act type, never editable (Art. II §6).'
        );
    }

    /** F-LEG-034 — Referendum Act Modification Vote (CLK-19 server gate). */
    public function modify(Request $request, Law $law): RedirectResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
        ]);

        $this->engine->file('F-LEG-034', $request->user(), [
            'law_id'          => (string) $law->id,
            'jurisdiction_id' => (string) $law->jurisdiction_id,
            'text'            => $validated['text'],
        ]);

        return back()->with(
            'status',
            "Modification filed (F-LEG-034) for {$law->act_number} — same-term change to a referendum "
            . 'act runs at chamber supermajority (Art. II §6 · WF-LEG-19).'
        );
    }

    // =========================================================================
    // Presentation internals
    // =========================================================================

    /** Open F-LEG-023 / F-LEG-034 proposals — the live supermajority votes. */
    private function pendingProposalRows(Legislature $legislature, ?LegislatureMember $viewer): array
    {
        return ChamberVoteProposal::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('proposal_kind', [
                ChamberVoteProposal::KIND_REFERENDUM_DELEGATION,
                ChamberVoteProposal::KIND_REFERENDUM_ACT_MODIFICATION,
            ])
            ->where('status', ChamberVoteProposal::STATUS_OPEN)
            ->orderBy('created_at')
            ->get()
            ->map(function (ChamberVoteProposal $proposal) use ($viewer) {
                $vote    = $proposal->vote_id !== null
                    ? ChamberVote::query()->with('tallies')->find($proposal->vote_id)
                    : null;
                $payload = (array) $proposal->payload;

                return [
                    'id'    => (string) $proposal->id,
                    'kind'  => $proposal->proposal_kind,
                    'label' => $proposal->proposal_kind === ChamberVoteProposal::KIND_REFERENDUM_DELEGATION
                        ? (string) ($payload['question'] ?? 'Referendum delegation')
                        : 'Modification of referendum act',
                    'threshold_derived' => $proposal->proposal_kind === ChamberVoteProposal::KIND_REFERENDUM_DELEGATION
                        ? ReferendumService::deriveThreshold((string) ($payload['act_type'] ?? 'ordinary'))
                        : null,
                    'vote' => $vote !== null ? [
                        'tally'    => $this->votes->tallyProps($vote),
                        'casts'    => $this->votes->casts($vote),
                        'open'     => $vote->status === ChamberVote::STATUS_OPEN,
                        'my_cast'  => $this->memberHasCast($viewer, $vote),
                        'cast_url' => "/votes/{$vote->id}/cast",
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function queueRows(Legislature $legislature): array
    {
        return ReferendumQuestion::query()
            ->where('jurisdiction_id', (string) $legislature->jurisdiction_id)
            ->whereIn('status', [ReferendumQuestion::STATUS_QUEUED, ReferendumQuestion::STATUS_SCHEDULED])
            ->orderBy('created_at')
            ->get()
            ->map(function (ReferendumQuestion $question) {
                $election = $question->election_id !== null
                    ? Election::query()->find($question->election_id)
                    : null;

                return [
                    'id'        => (string) $question->id,
                    'question'  => $question->question,
                    'threshold' => $question->threshold,
                    'origin'    => $question->origin,
                    'via'       => $question->origin === ReferendumQuestion::ORIGIN_PETITION
                        ? ['form' => 'F-IND-009', 'petition_href' => "/civic/petitions/{$question->petition_id}"]
                        : ['form' => 'F-LEG-023', 'act_href' => null],
                    'election'  => $election !== null ? [
                        'id'    => (string) $election->id,
                        'label' => ucfirst((string) $election->kind) . ' election · ' . $election->status,
                        'href'  => "/elections/{$election->id}",
                    ] : null,
                    'status' => $question->status,
                ];
            })
            ->values()
            ->all();
    }

    private function resultRows(Legislature $legislature): array
    {
        return ReferendumQuestion::query()
            ->where('jurisdiction_id', (string) $legislature->jurisdiction_id)
            ->whereIn('status', [
                ReferendumQuestion::STATUS_PASSED,
                ReferendumQuestion::STATUS_FAILED,
                ReferendumQuestion::STATUS_INVALIDATED,
            ])
            ->orderByDesc('certified_at')
            ->get()
            ->map(function (ReferendumQuestion $question) {
                $eligible = max(1, (int) $question->eligible_population);
                $law      = $question->resulting_law_id !== null
                    ? Law::query()->find($question->resulting_law_id)
                    : null;

                $shielded = $law !== null
                    && (bool) $law->referendum_passed_by_supermajority
                    && $this->referendums->shieldElectionPending($law);

                $shieldElection = $law?->shield_expires_with_election_id !== null
                    ? Election::query()->find($law->shield_expires_with_election_id)
                    : null;

                // Shield released (or never granted) on a referendum act
                // whose protection election has certified → ordinary law.
                $lapsed = $law !== null
                    && $law->referendum_passed_by_supermajority === null
                    && $law->shield_expires_with_election_id === null;

                return [
                    'id'        => (string) $question->id,
                    'title'     => $question->question,
                    'threshold' => $question->threshold,
                    'yes'       => (int) $question->yes_count,
                    'no'        => (int) $question->no_count,
                    'eligible'  => (int) $question->eligible_population,
                    'yes_pct'   => round((int) $question->yes_count * 100 / $eligible, 1),
                    'passed'    => $question->status === ReferendumQuestion::STATUS_PASSED,
                    'status'    => $question->status,
                    'law'       => $law !== null ? [
                        'id'         => (string) $law->id,
                        'act_number' => $law->act_number,
                        'href'       => '/system/public-records?q=' . urlencode((string) $law->act_number),
                    ] : null,
                    'shielded'   => $shielded,
                    'lapsed'     => $lapsed,
                    'modifiable' => $law !== null && ! $shielded,
                    'shield_expires_with' => $shieldElection !== null ? [
                        'election_label' => ucfirst((string) $shieldElection->kind) . ' election · ' . $shieldElection->status,
                    ] : null,
                    'modify_url' => $law !== null ? "/laws/{$law->id}/referendum-modification" : null,
                ];
            })
            ->values()
            ->all();
    }

    private function memberHasCast(?LegislatureMember $member, ?ChamberVote $vote): bool
    {
        return $member !== null && $vote !== null && VoteCast::query()
            ->where('vote_id', $vote->id)
            ->where('member_id', (string) $member->id)
            ->exists();
    }
}
