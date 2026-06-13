<?php

namespace App\Http\Controllers\Executive;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\ChamberVote;
use App\Models\ConstituentConsent;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Support\SurfaceMeta;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D2 — Executive/Home (PHASE_D_DESIGN_frontend.md §B.1; surface
 * executive/executive-home).
 *
 *   GET /executives/{executive} — the LIVE model of one executive office,
 *   rendered by type + status (ESM-16): forming empty-state / delegated
 *   Westminster panel (ex-officio member rows carrying their legislative
 *   seat link) / elected individual with ranked advisors / elected
 *   committee. Below the model: the ESM-16 StateStrip, the creation-act
 *   card (F-LEG-014 record + its enacted VoteTally), the conversion-act
 *   card (the ConstituentConsentPanel over the live/historical
 *   multi_jurisdiction_votes process, else the F-LEG-015 deep-link), and
 *   the term-lockstep card (CLK-10).
 *
 * PURE READER of engine snapshots — every threshold/required number is
 * read from the chamber_votes / multi_jurisdiction_votes rows through the
 * ChamberVotePresenter; nothing is computed here. Public read (Art. II §2 ·
 * Art. III); the only "actions" are R-09 deep-links into the bill flow —
 * this page never originates a vote.
 */
class ExecutiveController extends Controller
{
    public function __construct(private readonly ChamberVotePresenter $votes) {}

    public function show(Request $request, Executive $executive): Response
    {
        $executive->loadMissing(['jurisdiction', 'sourceLegislature', 'delegationLaw', 'conversionLaw']);

        $sourceLegislature = $executive->sourceLegislature;

        return Inertia::render('Executive/Home', [
            'surface' => SurfaceMeta::for('executive/executive-home'),
            'executive' => $this->executiveHeader($executive),
            'machine' => config('cga.state_machines.executive_office'),
            'delegation' => $this->delegationProps($executive),
            'conversion' => $this->conversionProps($executive),
            'members' => $this->memberRows($executive),
            'departmentsSummary' => $this->departmentsSummary($executive),
            'can' => $this->can($request->user(), $executive, $sourceLegislature),
        ]);
    }

    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function executiveHeader(Executive $executive): array
    {
        $jurisdiction = $executive->jurisdiction;
        $legislature = $executive->sourceLegislature;

        return [
            'id' => (string) $executive->id,
            'type' => $executive->type,
            'status' => $executive->status,
            'scope_text' => $executive->delegated_scope,
            'member_count' => $executive->delegated_member_count,
            'jurisdiction' => $jurisdiction !== null ? [
                'id' => (string) $jurisdiction->id,
                'name' => $jurisdiction->name,
                'href' => '/jurisdictions/'.($jurisdiction->slug ?? $jurisdiction->id),
            ] : null,
            'legislature' => $legislature !== null ? [
                'id' => (string) $legislature->id,
                'name' => $legislature->jurisdiction?->name
                    ? "{$legislature->jurisdiction->name} legislature"
                    : 'Source legislature',
                'chamber_href' => "/legislatures/{$legislature->id}/chamber",
            ] : null,
            'term' => $this->termProps($executive),
        ];
    }

    /**
     * Term-lockstep card (CLK-10). For an ELECTED office the dates live on
     * the executives row; for a DELEGATED committee the members are ex
     * officio (term IS their legislative seat's), so the office row carries
     * no term of its own and the card renders the honest "no office term".
     *
     * @return array<string, mixed>
     */
    private function termProps(Executive $executive): array
    {
        $endsOn = $executive->term_ends_on;

        return [
            'starts_on' => $executive->term_starts_on?->toDateString(),
            'ends_on' => $endsOn?->toDateString(),
            'days_remaining' => $endsOn !== null
                ? max(0, (int) CarbonImmutable::now()->diffInDays(CarbonImmutable::parse($endsOn), false))
                : null,
            'number' => $executive->term_number,
        ];
    }

    /**
     * F-LEG-014 creation-act record + the adopting chamber vote rendered as
     * a VoteTally (the supermajority that delegated the office).
     *
     * @return array<string, mixed>|null
     */
    private function delegationProps(Executive $executive): ?array
    {
        $law = $executive->delegationLaw;

        if ($law === null) {
            return null;
        }

        return [
            'act' => $this->lawChip($law),
            'scope_text' => $executive->delegated_scope,
            'vote' => $this->voteForLaw($law),
        ];
    }

    /**
     * F-LEG-015 conversion. When a constituent process exists (or ran), the
     * ConstituentConsentPanel renders it (the initiating chamber's own
     * supermajority PAIRED with the constituent-jurisdiction supermajority).
     * When no process exists yet, the page falls back to the F-LEG-015
     * deep-link — this controller emits the historical/live process rows
     * verbatim and never originates a vote.
     *
     * @return array<string, mixed>|null
     */
    private function conversionProps(Executive $executive): ?array
    {
        $law = $executive->conversionLaw;

        if ($law === null) {
            return null;
        }

        $process = $executive->conversion_process_id !== null
            ? $executive->conversionProcess()->with('consents')->first()
            : null;

        return [
            'subjectLabel' => 'Executive office conversion to elected office',
            'act' => $this->lawChip($law),
            'legislatureVote' => $this->voteForLaw($law),
            'process' => $process !== null ? $this->processProps($process) : null,
        ];
    }

    /**
     * ConstituentConsentPanel `process` contract — every number is the
     * multi_jurisdiction_votes engine snapshot (`required` = the engine's
     * ceil(total × 2/3), NEVER recomputed here).
     *
     * @return array<string, mixed>
     */
    private function processProps($process): array
    {
        $jurisdictionIds = $process->consents->pluck('jurisdiction_id')->all();

        $names = $jurisdictionIds === []
            ? collect()
            : DB::table('jurisdictions')
                ->whereIn('id', $jurisdictionIds)
                ->pluck('name', 'id');

        $voteSummaries = $this->consentVoteSummaries($process->consents);

        return [
            'id' => (string) $process->id,
            'kind' => $process->kind,
            'status' => $process->status,
            'total' => (int) $process->constituent_total,
            'required' => (int) $process->required,
            'yes' => (int) $process->yes_count,
            'no' => (int) $process->no_count,
            'pending' => (int) $process->constituent_total - (int) $process->yes_count - (int) $process->no_count,
            'closes_at' => $process->closes_at?->toDateString(),
            'consents' => $process->consents->map(function (ConstituentConsent $consent) use ($names, $voteSummaries) {
                $voteId = $consent->chamber_vote_id !== null ? (string) $consent->chamber_vote_id : null;

                // The constituent vote was held as a body in that chamber —
                // its public record lives on the constituent legislature's
                // chamber page (there is no standalone vote-detail route).
                $voteHref = $consent->legislature_id !== null
                    ? "/legislatures/{$consent->legislature_id}/chamber"
                    : null;

                return [
                    'jurisdiction' => [
                        'id' => (string) $consent->jurisdiction_id,
                        'name' => $names[$consent->jurisdiction_id] ?? '—',
                    ],
                    'result' => $consent->result,
                    'chamber_vote' => $voteId !== null && $voteHref !== null ? [
                        'href' => $voteHref,
                        'summary' => $voteSummaries[$voteId] ?? 'chamber vote',
                    ] : null,
                    'decided_at' => $consent->decided_at?->toDateString(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * "{yes} of {serving} serving" summaries for each constituent chamber
     * vote, read straight off the closed chamber_vote_tallies (LANE_ALL) —
     * engine snapshots, never recomputed.
     *
     * @param  \Illuminate\Support\Collection<int, ConstituentConsent>  $consents
     * @return array<string, string>
     */
    private function consentVoteSummaries($consents): array
    {
        $voteIds = $consents->pluck('chamber_vote_id')->filter()->map(fn ($id) => (string) $id)->all();

        if ($voteIds === []) {
            return [];
        }

        return ChamberVote::query()
            ->whereIn('id', $voteIds)
            ->with('tallies')
            ->get()
            ->mapWithKeys(function (ChamberVote $vote) {
                $tally = $vote->tallies->firstWhere('lane', 'all') ?? $vote->tallies->first();

                $yes = (int) ($tally?->yes ?? 0);
                $serving = (int) ($tally?->serving ?? $vote->serving_snapshot ?? 0);

                return [(string) $vote->id => "{$yes} of {$serving} serving"];
            })
            ->all();
    }

    /**
     * Resolve the chamber vote that ENACTED a direct-adoption law (its v1
     * LawVersion soft-refs the chamber_votes row) and present it as a
     * VoteTally. Null when the link cannot be resolved (the card then shows
     * the act record without the meter).
     *
     * @return array<string, mixed>|null
     */
    private function voteForLaw(Law $law): ?array
    {
        $version = LawVersion::query()
            ->where('law_id', $law->id)
            ->where('source_ref_type', 'chamber_vote')
            ->orderBy('version_no')
            ->first();

        if ($version === null || $version->source_ref_id === null) {
            return null;
        }

        $vote = ChamberVote::query()->with('tallies')->find($version->source_ref_id);

        return $vote !== null ? $this->votes->tallyProps($vote) : null;
    }

    /** @return array<string, mixed> */
    private function lawChip(Law $law): array
    {
        return [
            'act_number' => $law->act_number,
            'href' => $law->enacting_bill_id !== null
                ? "/bills/{$law->enacting_bill_id}"
                : '/system/public-records',
            'enacted_at' => $law->enacted_at?->toDateString(),
        ];
    }

    /**
     * Member rows by selection/role: delegated principals carry their
     * legislative seat link ("remains a seated legislator · seat {n}");
     * elected principals/advisors carry rank + the race link. Endorsements
     * surface only for elected members (delegated members were not elected
     * to the office).
     *
     * @return list<array<string, mixed>>
     */
    private function memberRows(Executive $executive): array
    {
        $members = ExecutiveMember::query()
            ->where('executive_id', $executive->id)
            ->where('status', ExecutiveMember::STATUS_SEATED)
            ->with([
                'user:id,name,display_name',
                'legislatureMember:id,seat_no,legislature_id',
                'electedInRace:id,election_id',
            ])
            ->orderBy('role')      // principal before advisor
            ->orderBy('rank')      // advisors 1..4 in succession order
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        $endorsements = $this->endorsementsByMember($members);

        return $members->map(function (ExecutiveMember $member) use ($endorsements) {
            $legMember = $member->legislatureMember;

            return [
                'id' => (string) $member->id,
                'name' => $member->user?->display_name ?: ($member->user?->name ?? 'Unknown member'),
                'role' => $member->role,
                'rank' => (int) $member->rank,
                'selection' => $member->selection,
                'legislature_member' => $legMember !== null ? [
                    'seat_no' => $legMember->seat_no !== null ? (int) $legMember->seat_no : null,
                    'href' => "/legislatures/{$legMember->legislature_id}/chamber",
                ] : null,
                'elected_in_race' => $member->electedInRace?->election_id !== null ? [
                    'href' => "/elections/{$member->electedInRace->election_id}",
                ] : null,
                'endorsements' => $endorsements[(string) $member->id] ?? [],
            ];
        })->values()->all();
    }

    /**
     * Org endorsements of the candidacy each ELECTED member won the office
     * on (same join as ChamberController) — keyed by executive_member id.
     *
     * @param  \Illuminate\Support\Collection<int, ExecutiveMember>  $members
     * @return array<string, list<array{name: string, org_type: string}>>
     */
    private function endorsementsByMember($members): array
    {
        $elected = $members->filter(fn (ExecutiveMember $m) => $m->elected_in_race_id !== null);

        if ($elected->isEmpty()) {
            return [];
        }

        $rows = DB::table('candidacies as c')
            ->join('endorsements as en', 'en.candidate_id', '=', 'c.id')
            ->join('organizations as o', 'o.id', '=', 'en.endorser_id')
            ->where('en.endorser_type', 'organization')
            ->where('en.is_active', true)
            ->whereIn('c.race_id', $elected->pluck('elected_in_race_id')->filter())
            ->whereIn('c.user_id', $elected->pluck('user_id'))
            ->get(['c.race_id', 'c.user_id', 'o.name', 'o.type as organization_type']);

        $out = [];
        foreach ($elected as $member) {
            $out[(string) $member->id] = $rows
                ->where('race_id', (string) $member->elected_in_race_id)
                ->where('user_id', (string) $member->user_id)
                ->map(fn ($row) => ['name' => $row->name, 'org_type' => $row->organization_type])
                ->values()
                ->all();
        }

        return $out;
    }

    /**
     * Departments summary (top 5 + count) — DepartmentCard props sourced
     * from the unified board snapshots (worker_seats/owner_seats/
     * composition_valid are engine outputs, never recomputed).
     *
     * @return array<string, mixed>
     */
    private function departmentsSummary(Executive $executive): array
    {
        $all = $executive->departments()
            ->where('status', '!=', \App\Models\Department::STATUS_DISSOLVED)
            ->with(['board.seats', 'charterLaw:id,act_number,enacting_bill_id'])
            ->orderBy('name')
            ->get();

        $cards = $all->take(5)->map(fn (\App\Models\Department $department) => $this->departmentCard($department))->all();

        return [
            'cards' => $cards,
            'total' => $all->count(),
            'href' => "/executives/{$executive->id}/departments",
        ];
    }

    /** @return array<string, mixed> */
    private function departmentCard(\App\Models\Department $department): array
    {
        $board = $department->board;
        $law = $department->charterLaw;

        return [
            'id' => (string) $department->id,
            'name' => $department->name,
            'kind' => $department->kind,
            'status' => $department->status,
            'worker_count' => (int) ($department->worker_count ?? 0),
            'board' => $board !== null ? [
                'owner_seats' => (int) $board->owner_seats,
                'worker_seats' => (int) $board->worker_seats,
                'composition_valid' => (bool) $board->composition_valid,
                'seats' => $board->seats->map(fn (\App\Models\BoardSeat $seat) => [
                    'id' => (string) $seat->id,
                    'seat_class' => $seat->seat_class,
                    'is_chair' => (bool) $seat->is_chair,
                    'status' => $seat->status,
                ])->values()->all(),
            ] : null,
            'charter' => $law !== null ? [
                'act_number' => $law->act_number,
                'href' => $law->enacting_bill_id !== null
                    ? "/bills/{$law->enacting_bill_id}"
                    : '/system/public-records',
                'reporting_interval_months' => $department->reporting_interval_months,
            ] : null,
            'oversees_cgcs' => [],
            'next_report' => null,
            'href' => "/departments/{$department->id}",
        ];
    }

    /**
     * `can.*` — R-09 of the SOURCE legislature drives the deep-links (not
     * POSTs here). Resolved directly: the viewer holds a current
     * legislature_members row in the office's source legislature. For a
     * `forming` stub (no source legislature yet) the jurisdiction's own
     * legislature is the proposing body.
     *
     * @return array{proposeDelegationBill: bool, proposeConversionBill: bool}
     */
    private function can($user, Executive $executive, ?Legislature $sourceLegislature): array
    {
        if ($user === null) {
            return ['proposeDelegationBill' => false, 'proposeConversionBill' => false];
        }

        $legislatureId = $sourceLegislature?->id
            ?? Legislature::query()
                ->where('jurisdiction_id', $executive->jurisdiction_id)
                ->where('status', '!=', Legislature::STATUS_DISSOLVED)
                ->value('id');

        $isLegislator = $legislatureId !== null && LegislatureMember::query()
            ->where('legislature_id', $legislatureId)
            ->where('user_id', $user->getKey())
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->exists();

        return [
            // Delegation is proposable while the office has not yet been delegated.
            'proposeDelegationBill' => $isLegislator && $executive->status === Executive::STATUS_FORMING,
            // Conversion is proposable once delegated and not already converting/elected.
            'proposeConversionBill' => $isLegislator && $executive->status === Executive::STATUS_DELEGATED,
        ];
    }
}
