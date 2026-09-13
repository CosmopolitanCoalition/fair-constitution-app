<?php

namespace App\Support;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Presenters\CandidacyPanel;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Committee;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Models\VoteCast;
use App\Services\Judiciary\JudicialNominationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bounded, court-scoped nomination work. Public preview never establishes filing authority. */
final class JudicialNominationWorkspace
{
    public function __construct(private JudicialNominationService $service, private ChamberVotePresenter $presenter) {}

    public function context(Judiciary $court, ?User $user): array
    {
        $reason = null;
        $source = null;
        $committee = null;
        try {
            $source = $this->service->source($court);
        } catch (ConstitutionalViolation $e) {
            $reason = $e->getMessage();
        }
        if ($source && $court->nomination_mode === 'committee') {
            try {
                $committee = $this->service->designatedCommittee($court);
            } catch (ConstitutionalViolation $e) {
                $reason = $e->getMessage();
            }
        }
        $member = $source ? $this->member($source->id, $user) : null;

        return ['mode' => $court->nomination_mode, 'reason' => $reason,
            'can_designate' => $source !== null && $member !== null && $court->nomination_mode === 'committee',
            'source_id' => $source?->id, 'create_committee_url' => $source ? '/legislatures/'.$source->id.'/committees' : null,
            'committee' => $committee ? ['name' => $committee->name, 'href' => '/committees/'.$committee->id] : null,
            'nominate_url' => '/judiciaries/'.$court->id.'/nomination-proposals', 'designate_url' => '/judiciaries/'.$court->id.'/judicial-committee'];
    }

    public function seats(Request $request, Judiciary $court): array
    {
        [$page, $pages] = $this->page($request, JudicialSeat::query()->where('judiciary_id', $court->id)->where('status', 'vacant')
            ->with('nominatingJurisdiction:id,name'), $court, 'seats_cursor');
        $rows = $page->getCollection();
        $legs = Legislature::query()->whereIn('jurisdiction_id', $rows->pluck('nominating_jurisdiction_id')->filter())
            ->whereIn('status', ['active', 'forming'])->orderBy('id')->get(['id', 'jurisdiction_id'])->keyBy('jurisdiction_id');
        $source = Legislature::query()->find($court->source_legislature_id);

        return ['rows' => $rows->map(function ($seat) use ($request, $court, $legs, $source) {
            $leg = $seat->seat_class === 'constituent_nominated' ? $legs->get($seat->nominating_jurisdiction_id) : $source;
            $member = $leg ? $this->member($leg->id, $request->user()) : null;
            $can = false;
            $reason = null;
            try {
                $this->service->source($court);
                if ($seat->seat_class === 'committee_nominated') {
                    $committee = $this->service->designatedCommittee($court);
                    $can = $member && $this->service->committeeMember($committee->id, $member->id);
                } else {
                    $can = $member !== null;
                }
            } catch (ConstitutionalViolation $e) {
                $reason = $e->getMessage();
            }

            return ['id' => $seat->id, 'number' => $seat->seat_number, 'legislature_id' => $leg?->id,
                'nominator' => $seat->nominatingJurisdiction?->name ?? 'Designated judicial committee', 'can_propose' => (bool) $can, 'reason' => $reason];
        })->all(), 'pages' => $pages];
    }

    public function committees(Request $request, Judiciary $court): array
    {
        if ($court->nomination_mode !== 'committee') {
            return ['rows' => [], 'pages' => []];
        }
        [$page, $pages] = $this->page($request, Committee::query()->where('legislature_id', $court->source_legislature_id)->where('status', '!=', 'dissolved'), $court, 'committees_cursor');

        return ['rows' => $page->getCollection()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'href' => '/committees/'.$c->id])->all(), 'pages' => $pages];
    }

    public function proposals(Request $request, Judiciary $court): array
    {
        [$page, $pages] = $this->page($request, ChamberVoteProposal::query()->whereIn('proposal_kind', JudicialNominationService::KINDS)
            ->where('payload->judiciary_id', (string) $court->id), $court, 'judicial_proposals_cursor');
        $rows = $page->getCollection();
        $votes = ChamberVote::query()->whereIn('id', $rows->pluck('vote_id')->filter())->with('tallies')->get()->keyBy('id');
        $ids = $rows->pluck('payload.nominee_user_id')->filter()->unique()->all();
        $names = CandidacyPanel::displayNames($ids);
        $people = PublicPersonSelectionContext::forIds($ids);
        $committees = Committee::query()->whereIn('id', $rows->pluck('payload.committee_id')->filter())->pluck('name', 'id');
        $legs = Legislature::query()->whereIn('id', $rows->pluck('legislature_id'))->with('jurisdiction:id,name')->get()->keyBy('id');
        $seats = JudicialSeat::query()->whereIn('id', $rows->pluck('payload.seat_id')->filter())->pluck('seat_number', 'id');
        $members = $request->user() ? LegislatureMember::query()->where('user_id', $request->user()->id)->whereIn('legislature_id', $legs->keys())
            ->current()->get()->keyBy('legislature_id') : collect();

        return ['rows' => $rows->map(function ($p) use ($court, $votes, $names, $people, $committees, $legs, $members, $seats) {
            $payload = $p->payload;
            $vote = $votes->get($p->vote_id);
            $leg = $legs->get($p->legislature_id);
            $member = $members->get($p->legislature_id);
            $reason = null;
            if ($vote) {
                try {
                    $this->service->assertVoteIdentity($p, $vote);
                } catch (ConstitutionalViolation $e) {
                    $reason = $e->getMessage();
                    $vote = null;
                }
            }
            if ($p->status === 'open') {
                try {
                    $this->service->assertPending($p, $court);
                } catch (ConstitutionalViolation $e) {
                    $reason = $e->getMessage();
                }
            }
            $cast = $member && $vote ? VoteCast::query()->where('vote_id', $vote->id)->where('member_id', $member->id)->first() : null;
            $speaker = $member && $leg?->speaker_id === $member->id;
            $belongs = $member && ($vote?->body_type === 'legislature' || ($vote?->body_type === 'committee' && $this->service->committeeMember($vote->body_id, $member->id)));
            $pending = $p->status === 'open' && $reason === null && $leg && in_array($leg->status, ['active', 'forming'], true);
            $lane = $vote?->bicameral ? $member?->seatKind() : 'all';
            $tally = $vote?->tallies->firstWhere('lane', $lane);
            $nominee = $payload['nominee_user_id'] ?? null;

            return ['id' => $p->id, 'status' => $p->status,
                'title' => $p->proposal_kind === JudicialNominationService::KINDS[1] ? 'Designate '.$committees->get($payload['committee_id'], 'judicial committee') : 'Authorize a judicial nomination',
                'nominee' => $nominee ? ['id' => $nominee, 'name' => $names[$nominee]] + $people[$nominee] : null,
                'statement' => $payload['statement'] ?? '', 'reason' => $reason,
                'seat_number' => $seats->get($payload['seat_id'] ?? ''),
                'body_name' => $vote?->body_type === 'committee' ? $committees->get($payload['committee_id'], 'Judicial committee') : ($leg?->jurisdiction?->name ?? 'Nominating').' legislature',
                'result_href' => $p->status === 'adopted' && $p->proposal_kind === JudicialNominationService::KINDS[0] ? '/judiciaries/'.$court->id.'#judicial-confirmations' : null,
                'vote' => $vote ? ['tally' => $this->presenter->tallyProps($vote), 'cast_url' => '/votes/'.$vote->id.'/cast', 'tiebreak_url' => '/votes/'.$vote->id.'/tiebreak',
                    'my_cast' => $cast?->value, 'can_cast' => (bool) ($pending && $belongs && ! $speaker && ! $cast && $vote->status === 'open'),
                    'can_tiebreak' => (bool) ($pending && $belongs && $speaker && ! $cast && $vote->status === 'closed' && $vote->outcome === 'tied'
                        && $vote->threshold_basis === 'majority' && ! $vote->speaker_tiebreak && $tally && $tally->yes === $tally->no && $tally->yes === $tally->required_yes - 1)] : null];
        })->all(), 'pages' => $pages];
    }

    private function member(string $leg, ?User $user): ?LegislatureMember
    {
        return $user ? LegislatureMember::query()->where('legislature_id', $leg)->where('user_id', $user->id)->current()->first() : null;
    }

    private function page(Request $request, $query, Judiciary $court, string $key): array
    {
        $token = $request->validate([$key => 'nullable|string|max:2048'])[$key] ?? null;
        $cursor = null;
        if ($token) {
            try {
                $data = json_decode(base64_decode(strtr($token, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 3 || ($data['scope'] ?? null) !== $court->id.':'.$key
                    || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id']) || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                    throw new \InvalidArgumentException;
                }
                $cursor = new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$key => 'This page link is invalid. Return to the first page.']);
            }
        }
        $page = $query->orderByDesc('id')->cursorPaginate(20, ['*'], $key, $cursor);
        $path = '/judiciaries/'.$court->id;
        $link = fn (?Cursor $c) => $c ? $path.'?'.http_build_query([$key => (new Cursor(['id' => $c->parameter('id'), 'scope' => $court->id.':'.$key], $c->pointsToNextItems()))->encode()]) : null;

        return [$page, ['previous' => $link($page->previousCursor()), 'next' => $link($page->nextCursor()), 'first' => $path]];
    }
}
