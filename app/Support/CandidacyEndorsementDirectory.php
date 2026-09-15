<?php

namespace App\Support;

use App\Http\Presenters\CandidacyPanel;
use App\Models\Candidacy;
use App\Models\Endorsement;
use App\Models\EndorsementRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;

/** Independent readers on the one person profile; no eager election-wide web. */
class CandidacyEndorsementDirectory
{
    private function received(Candidacy $candidate, string $type)
    {
        return Endorsement::query()->logicalType($type)
            ->where('endorsements.candidate_id', $candidate->id)
            ->where('endorsements.election_id', $candidate->election_id)
            ->where('endorsements.is_active', true)->whereNull('endorsements.withdrawn_at');
    }

    public function organizations(Request $request, Candidacy $candidate): array
    {
        $query = $this->received($candidate, Endorsement::ENDORSER_ORGANIZATION)->where('endorsements.is_public', true)
            ->join('organizations as o', 'o.id', '=', 'endorsements.endorser_id')
            ->select(['o.id as organization_id', 'o.name', 'o.type', 'endorsements.endorsed_at']);

        return $this->page($request, $candidate, 'orgs', $query, 'endorsements.id', fn ($row) => [
            'id' => $row->organization_id, 'name' => $row->name, 'type' => $row->type,
            'granted_at' => $row->endorsed_at, 'href' => '/organizations/'.$row->organization_id,
        ]);
    }

    public function individuals(Request $request, Candidacy $candidate): array
    {
        $query = $this->received($candidate, Endorsement::ENDORSER_USER);
        // Only anonymous totals cross the privacy boundary. No private IDs are loaded.
        $counts = (clone $query)->toBase()->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN endorsements.is_public THEN 1 ELSE 0 END), 0) AS public')->first();
        $result = $this->page($request, $candidate, 'people', $query->where('endorsements.is_public', true)
            ->select('endorsements.endorser_id'), 'endorsements.id', fn ($row) => ['user_id' => $row->endorser_id]);
        $ids = array_column($result['rows'], 'user_id');
        $names = CandidacyPanel::displayNames($ids);
        $alsoCandidates = $ids === [] ? [] : Candidacy::query()->where('election_id', $candidate->election_id)
            ->whereIn('user_id', $ids)->distinct()->pluck('user_id')->all();
        $result['rows'] = array_map(fn ($row) => $row + ['name' => $names[$row['user_id']],
            'alsoCandidate' => in_array($row['user_id'], $alsoCandidates, true)], $result['rows']);
        $result['counts'] = ['total' => (int) $counts->total, 'public' => (int) $counts->public,
            'private' => (int) $counts->total - (int) $counts->public];

        return $result;
    }

    public function web(Request $request, Candidacy $candidate): ?array
    {
        $id = $request->query('public_endorser');
        if ($id === null || $id === '') return null;
        // Never let a forged bookmark expose an anonymous endorser or a removed edge.
        if (! is_string($id) || ! Str::isUuid($id) || ! $this->hasPublicEdge($candidate, $id)) {
            return ['endorser' => null, 'rows' => [], 'notice' => __('This public endorsement is no longer available.')];
        }
        $query = $this->givenQuery($id)->where('endorsements.election_id', $candidate->election_id)
            ->where('endorsements.candidate_id', '<>', $candidate->id);
        $result = $this->page($request, $candidate, 'web', $query, 'endorsements.id', $this->targetRow(...), $id);
        $result['rows'] = $this->nameTargets($result['rows']);
        $result['endorser'] = ['user_id' => $id, 'name' => CandidacyPanel::displayNames([$id])[$id]];

        return $result;
    }

    private function hasPublicEdge(Candidacy $candidate, string $userId): bool
    {
        // Two unique-key lookups preserve canonical precedence without scanning
        // the person's other endorsements to establish this particular edge.
        $query = Endorsement::query()->where('election_id', $candidate->election_id)
            ->where('candidate_id', $candidate->id)->where('endorser_id', $userId);
        $columns = ['is_active', 'is_public', 'withdrawn_at'];
        $edge = (clone $query)->where('endorser_type', Endorsement::ENDORSER_USER)->first($columns)
            ?? (clone $query)->where('endorser_type', 'users')->first($columns);

        return $edge !== null && $edge->is_active && $edge->is_public && $edge->withdrawn_at === null;
    }

    public function requests(Request $request, Candidacy $candidate, ?User $viewer): ?array
    {
        if ($viewer === null || (string) $viewer->id !== (string) $candidate->user_id) return null;
        $query = EndorsementRequest::query()->where('candidacy_id', $candidate->id)
            ->leftJoin('organizations as o', 'o.id', '=', 'endorsement_requests.organization_id')
            ->select(['o.name as org_name', 'endorsement_requests.requested_at', 'endorsement_requests.status']);

        return $this->page($request, $candidate, 'requests', $query, 'endorsement_requests.id', fn ($row) => [
            'org_name' => $row->org_name, 'requested_at' => $row->requested_at, 'status' => $row->status,
        ]);
    }

    public function given(Request $request, string $userId): array
    {
        $result = $this->page($request, null, 'given', $this->givenQuery($userId), 'endorsements.id', $this->targetRow(...), $userId);
        $result['rows'] = $this->nameTargets($result['rows']);

        return $result;
    }

    private function givenQuery(string $id)
    {
        return Endorsement::query()->logicalType(Endorsement::ENDORSER_USER)
            ->where('endorsements.endorser_id', $id)->where('endorsements.is_active', true)
            ->whereNull('endorsements.withdrawn_at')->where('endorsements.is_public', true)
            ->join('candidacies as c', 'c.id', '=', 'endorsements.candidate_id')->whereNull('c.deleted_at')
            ->select(['c.id as candidacy_id', 'c.user_id as candidate_user_id', 'endorsements.endorsed_at']);
    }

    private function targetRow($row): array
    {
        return ['candidacy_id' => $row->candidacy_id, 'user_id' => $row->candidate_user_id, 'endorsed_at' => $row->endorsed_at];
    }

    private function nameTargets(array $rows): array
    {
        $names = CandidacyPanel::displayNames(array_column($rows, 'user_id'));

        return array_map(fn ($row) => $row + ['name' => $names[$row['user_id']] ?? 'Candidate'], $rows);
    }

    private function page(Request $request, ?Candidacy $candidate, string $kind, $query, string $idColumn, callable $map, string $person = ''): array
    {
        $key = 'endorsement_'.$kind.'_cursor';
        $scope = hash('sha256', ($candidate?->user_id ?? $person).':'.($candidate?->id ?? '').':'.$kind.':'.$person);
        $cursor = null;
        $notice = null;
        try {
            $token = $request->query($key);
            if ($token !== null && $token !== '') {
                if (! is_string($token) || strlen($token) > 2048) throw new \InvalidArgumentException;
                $data = json_decode(base64_decode(strtr($token, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 3 || ($data['scope'] ?? null) !== $scope
                    || ! is_bool($data['_pointsToNextItems'] ?? null) || ! is_string($data['seek_id'] ?? null)
                    || ! Str::isUuid($data['seek_id'])) throw new \InvalidArgumentException;
                $cursor = new Cursor(['seek_id' => $data['seek_id']], $data['_pointsToNextItems']);
            }
        } catch (\Throwable) {
            $notice = __('This page link is invalid or belongs to a different selection. Showing the first page.');
        }
        $page = $query->addSelect($idColumn.' as seek_id')->orderByDesc('seek_id')->toBase()->cursorPaginate(20, ['*'], $key, $cursor);
        $context = $candidate ? ['who' => $candidate->user_id, 'tab' => 'candidacy', 'candidacy' => $candidate->id]
            : ['who' => $person, 'tab' => 'record'];
        if ($kind === 'web') $context['public_endorser'] = $person;
        $first = '/people?'.http_build_query($context);
        $url = fn (?Cursor $position) => $position ? $first.'&'.http_build_query([$key => (new Cursor([
            'seek_id' => $position->parameter('seek_id'), 'scope' => $scope,
        ], $position->pointsToNextItems()))->encode()]) : null;

        return ['rows' => $page->getCollection()->map($map)->all(), 'pages' => [
            'previous' => $url($page->previousCursor()), 'next' => $url($page->nextCursor()), 'first' => $first,
        ], 'notice' => $notice];
    }
}
