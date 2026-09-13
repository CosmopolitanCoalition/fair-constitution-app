<?php

namespace App\Support;

use App\Http\Presenters\ChamberVotePresenter;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ConstituentConsent;
use App\Models\Executive;
use App\Models\Judiciary;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MultiJurisdictionVote;
use App\Models\User;
use App\Models\VoteCast;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Public acts of one legislature; bounded readers never establish filing authority. */
final class InstitutionActWorkspace
{
    public const ACTIONS = [
        'delegate-executive' => ['form' => 'F-LEG-014', 'kind' => 'exec_delegation', 'name' => 'Delegate an executive committee'],
        'elect-executive' => ['form' => 'F-LEG-015', 'kind' => 'exec_conversion', 'name' => 'Create an elected executive'],
        'create-department' => ['form' => 'F-LEG-016', 'kind' => 'department_creation', 'name' => 'Create a department'],
        'create-court' => ['form' => 'F-LEG-017', 'kind' => 'judiciary_creation', 'name' => 'Create an appointed court'],
        'elect-court' => ['form' => 'F-LEG-018', 'kind' => 'judiciary_conversion', 'name' => 'Convert a court to elections'],
        'create-cgc' => ['form' => 'F-LEG-019', 'kind' => 'cgc_creation', 'name' => 'Create a common-good corporation'],
    ];

    public function __construct(private readonly ChamberVotePresenter $votes) {}

    public function member(Legislature $legislature, ?User $user): ?LegislatureMember
    {
        return $user ? LegislatureMember::query()->where('legislature_id', $legislature->id)->where('user_id', $user->id)->current()->first() : null;
    }

    public function context(Legislature $legislature, ?User $user): array
    {
        $member = $this->member($legislature, $user);
        $executive = Executive::query()->where('jurisdiction_id', $legislature->jurisdiction_id)->first(['id', 'status']);
        $court = Judiciary::query()->where('jurisdiction_id', $legislature->jurisdiction_id)->first(['id', 'status', 'court_name', 'min_judges']);
        $actions = [];
        foreach (self::ACTIONS as $key => $meta) {
            $reason = match ($key) {
                'delegate-executive' => $executive?->status === 'forming' ? null : 'Delegation requires an executive awaiting formation.',
                'elect-executive' => in_array($executive?->status, ['forming', 'delegated'], true) ? null : 'The executive must be awaiting formation or currently delegated.',
                'create-department' => in_array($executive?->status, ['delegated', 'elected'], true) ? null : 'Delegate or elect this jurisdiction’s overseeing executive first.',
                'create-court' => $court?->status === 'forming' ? null : 'Court creation requires a judiciary awaiting formation.',
                'elect-court' => $court?->status === 'appointed' ? null : 'Conversion requires an appointed court.',
                default => null,
            };
            $actions[] = ['key' => $key, 'name' => $meta['name'], 'ready' => $reason === null, 'reason' => $reason];
        }

        return ['actions' => $actions, 'canFile' => $member !== null, 'canCast' => $member !== null && (string) $legislature->speaker_id !== (string) $member->id,
            'isSpeaker' => $member !== null && (string) $legislature->speaker_id === (string) $member->id,
            'role' => $member === null ? 'Public preview' : ((string) $legislature->speaker_id === (string) $member->id ? 'Speaker' : 'Legislator'),
            'executive' => $executive ? ['id' => $executive->id, 'href' => '/executives/'.$executive->id, 'status' => $executive->status] : null,
            'court' => $court ? ['id' => $court->id, 'name' => $court->court_name, 'href' => '/judiciaries/'.$court->id, 'status' => $court->status, 'minimumJudges' => $court->min_judges] : null];
    }

    public function proposals(Request $request, Legislature $legislature): array
    {
        $query = ChamberVoteProposal::query()->where('legislature_id', $legislature->id)->whereIn('proposal_kind', array_column(self::ACTIONS, 'kind'));
        [$page, $pagination] = $this->page($request, $query, $legislature, 'acts');
        $votes = ChamberVote::query()->whereIn('id', $page->getCollection()->pluck('vote_id')->filter())->with('tallies')->get()->keyBy('id');
        $names = array_column(self::ACTIONS, 'name', 'kind');
        $member = $this->member($legislature, $request->user());

        return ['records' => $page->getCollection()->map(function ($proposal) use ($votes, $names, $member, $legislature) {
            $payload = (array) $proposal->payload;
            $details = [];
            if (isset($payload['kind'])) {
                $details[] = ['label' => 'Department function', 'value' => ['chief_executive' => 'Chief executive', 'treasury' => 'Treasury', 'defense' => 'Defense', 'state' => 'State', 'justice' => 'Justice', 'other' => 'Other'][$payload['kind']] ?? 'Other'];
            }
            foreach (['delegated_scope' => 'Delegated powers', 'member_count' => 'Executive seats', 'target_type' => 'Executive structure', 'name' => 'Name', 'court_name' => 'Court name', 'charter_text' => 'Charter', 'function_text' => 'Court function', 'judge_count' => 'Elected judges', 'judges_per_constituent' => 'Judges per constituent', 'committee_judge_count' => 'Committee-selected judges', 'owner_seats' => 'Governor seats', 'goods_services' => 'Goods and services'] as $key => $label) {
                if (isset($payload[$key]) && is_scalar($payload[$key])) {
                    $details[] = ['label' => $label, 'value' => (string) $payload[$key]];
                }
            }
            if (is_string($payload['charter'] ?? null)) {
                $details[] = ['label' => 'Charter', 'value' => $payload['charter']];
            } elseif (is_array($payload['charter'] ?? null)) {
                foreach (['function_text' => 'Department function', 'powers_text' => 'Department powers', 'reporting_interval_months' => 'Report interval (months)'] as $key => $label) {
                    if (isset($payload['charter'][$key])) {
                        $details[] = ['label' => $label, 'value' => (string) $payload['charter'][$key]];
                    }
                }
            }
            $resultPath = ['executives' => '/executives/', 'departments' => '/departments/', 'judiciaries' => '/judiciaries/', 'organizations' => '/organizations/'][$proposal->result_type] ?? null;

            return ['id' => $proposal->id, 'name' => $payload['name'] ?? $payload['court_name'] ?? $names[$proposal->proposal_kind], 'action' => $names[$proposal->proposal_kind],
                'status' => $proposal->status, 'filed_at' => $proposal->created_at?->toIso8601String(), 'details' => $details,
                'result_href' => $resultPath && $proposal->result_id ? $resultPath.$proposal->result_id : null,
                'vote' => $this->vote($votes->get($proposal->vote_id), $legislature, $member, 'chamber_vote_proposal', $proposal->id, $proposal->status === 'open')];
        })->all(), 'pagination' => $pagination];
    }

    public function processes(Request $request, Legislature $legislature): array
    {
        $query = $this->processQuery($legislature);
        $selected = $request->validate(['process' => ['nullable', 'uuid']])['process'] ?? null;
        if ($selected) {
            $query->whereKey($selected);
        }
        [$page, $pagination] = $this->page($request, $query, $legislature, 'processes', $selected ?? '');
        $local = ConstituentConsent::query()->whereIn('process_id', $page->getCollection()->pluck('id'))->where('jurisdiction_id', $legislature->jurisdiction_id)->get()->keyBy('process_id');
        $votes = ChamberVote::query()->whereIn('id', $local->pluck('chamber_vote_id')->filter())->with('tallies')->get()->keyBy('id');
        $member = $this->member($legislature, $request->user());

        return ['records' => $page->getCollection()->map(fn ($process) => [
            'id' => $process->id, 'name' => $process->kind === 'judiciary_convert' ? 'Elected court consent' : 'Elected executive consent',
            'status' => $process->status, 'yes' => $process->yes_count, 'no' => $process->no_count, 'required' => $process->required, 'total' => $process->constituent_total,
            'href' => $this->base($legislature).'?process='.$process->id,
            'canOpen' => $member !== null && $process->status === 'open' && $local->get($process->id)?->result === 'pending' && ! $local->get($process->id)?->chamber_vote_id,
            'open_url' => $this->base($legislature).'/consents/'.$process->id,
            'local_result' => $local->get($process->id)?->result,
            'vote' => $this->vote($votes->get($local->get($process->id)?->chamber_vote_id), $legislature, $member, 'constituent_consent', $local->get($process->id)?->id, $process->status === 'open' && $local->get($process->id)?->result === 'pending'),
        ])->all(), 'pagination' => $pagination];
    }

    public function constituents(Request $request, Legislature $legislature): ?array
    {
        $id = $request->validate(['process' => ['nullable', 'uuid']])['process'] ?? null;
        if (! $id) {
            return null;
        }
        $process = $this->processQuery($legislature)->whereKey($id)->firstOrFail();
        [$page, $pagination] = $this->page($request, ConstituentConsent::query()->where('process_id', $id), $legislature, 'consents', $id);
        $placeIds = $page->getCollection()->pluck('jurisdiction_id');
        $places = Jurisdiction::query()->whereIn('id', $placeIds)->pluck('name', 'id');
        $chambers = Legislature::query()->whereIn('jurisdiction_id', $placeIds)->where('status', '!=', 'dissolved')->orderBy('id')->get(['id', 'jurisdiction_id']);
        $byId = $chambers->keyBy('id');
        $byPlace = $chambers->groupBy('jurisdiction_id')->map->first();

        return ['name' => $process->kind === 'judiciary_convert' ? 'Court conversion: constituent decisions' : 'Executive conversion: constituent decisions',
            'records' => $page->getCollection()->map(function ($consent) use ($byId, $byPlace, $places, $id) {
                $chamber = $consent->legislature_id ? $byId->get($consent->legislature_id) : $byPlace->get($consent->jurisdiction_id);

                return ['id' => $consent->id, 'name' => $places[$consent->jurisdiction_id] ?? 'Constituent jurisdiction', 'result' => $consent->result,
                    'href' => $chamber && (string) $chamber->jurisdiction_id === (string) $consent->jurisdiction_id ? $this->base($chamber).'?process='.$id : null];
            })->all(), 'pagination' => $pagination];
    }

    public function processQuery(Legislature $legislature)
    {
        $kinds = ['exec_office_create', 'judiciary_convert'];
        $initiated = MultiJurisdictionVote::query()->whereIn('kind', $kinds)->where('initiating_legislature_id', $legislature->id);
        $local = MultiJurisdictionVote::query()->select('multi_jurisdiction_votes.*')
            ->join('constituent_consents as local_consent', 'local_consent.process_id', '=', 'multi_jurisdiction_votes.id')
            ->where('local_consent.jurisdiction_id', $legislature->jurisdiction_id)->whereIn('multi_jurisdiction_votes.kind', $kinds)
            ->where(fn ($q) => $q->where('initiating_legislature_id', '!=', $legislature->id)->orWhereNull('initiating_legislature_id'));

        // The branches are disjoint. Each starts in an indexed local scope;
        // there is no per-world-process membership probe or global total.
        return MultiJurisdictionVote::query()->fromSub($initiated->unionAll($local)->toBase(), 'multi_jurisdiction_votes');
    }

    private function vote(?ChamberVote $vote, Legislature $legislature, ?LegislatureMember $member, string $type, ?string $subjectId, bool $pending): ?array
    {
        if (! $vote || $vote->body_type !== 'legislature' || (string) $vote->body_id !== (string) $legislature->id
            || (string) $vote->legislature_id !== (string) $legislature->id || (string) $vote->jurisdiction_id !== (string) $legislature->jurisdiction_id
            || $vote->stage !== 'floor' || $vote->votable_type !== $type || (string) $vote->votable_id !== (string) $subjectId) {
            return null;
        }
        $speaker = $member !== null && (string) $legislature->speaker_id === (string) $member->id;
        $cast = $member ? VoteCast::query()->where('vote_id', $vote->id)->where('member_id', $member->id)->first(['value']) : null;
        $lane = $vote->bicameral ? $member?->seatKind() : 'all';
        $tally = $vote->tallies->firstWhere('lane', $lane);

        return ['tally' => $this->votes->tallyProps($vote), 'my_cast' => $cast?->value,
            'can_cast' => $pending && $member !== null && ! $speaker && ! $cast && $vote->status === 'open',
            'cast_url' => '/votes/'.$vote->id.'/cast', 'tiebreak_url' => '/votes/'.$vote->id.'/tiebreak',
            'can_tiebreak' => $pending && $speaker && $vote->status === 'closed' && $vote->outcome === 'tied' && ! $cast
                && $vote->threshold_basis === ChamberVote::BASIS_MAJORITY && ! $vote->speaker_tiebreak
                && $tally !== null && $tally->yes === $tally->no && $tally->yes === $tally->required_yes - 1,
            'read_only_reason' => $member === null ? 'Serving members of this legislature cast public votes.' : ($speaker ? 'The Speaker votes only to break a tied decision.' : null)];
    }

    private function page(Request $request, $query, Legislature $legislature, string $name, string $extra = ''): array
    {
        $key = $name.'_cursor';
        $scope = hash('sha256', $legislature->id.':'.$name.':'.$extra);
        $input = $request->validate([$key => ['nullable', 'string', 'max:1024'], 'action' => ['nullable', 'string', 'max:40'], 'process' => ['nullable', 'uuid']]);
        $cursor = null;
        if (! empty($input[$key])) {
            try {
                $data = json_decode(base64_decode(strtr($input[$key], '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 3 || ($data['scope'] ?? null) !== $scope || ! Str::isUuid($data['id'] ?? '') || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                    throw new \InvalidArgumentException;
                }
                $cursor = new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$key => 'This page link is invalid. Return to the first page.']);
            }
        }
        $context = array_filter(['action' => $input['action'] ?? null, 'process' => $input['process'] ?? null]);
        $path = $this->base($legislature);
        $page = $query->orderByDesc('id')->cursorPaginate(20, ['*'], $key, $cursor);
        $url = fn ($position) => $position ? $path.'?'.http_build_query($context + [$key => (new Cursor(['id' => $position->parameter('id'), 'scope' => $scope], $position->pointsToNextItems()))->encode()]) : null;

        return [$page, ['previous' => $url($page->previousCursor()), 'next' => $url($page->nextCursor()), 'first' => $path.($context ? '?'.http_build_query($context) : '')]];
    }

    public function base(Legislature $legislature): string
    {
        return '/legislatures/'.$legislature->id.'/institution-acts';
    }
}
