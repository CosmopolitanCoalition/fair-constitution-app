<?php

namespace App\Support;

use App\Models\Candidacy;
use App\Models\ElectionRace;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Page the race before loading candidate profiles, endorsements, or private switches. */
final class ApprovalDirectory
{
    public const PAGE_SIZE = 20;

    public const ORGANIZATION_PREVIEW_SIZE = 3;

    private const STANDING = ['validated', 'in_pool', 'finalist', 'non_finalist'];

    private const GROUP = 'CASE WHEN s.rank IS NULL THEN 1 ELSE 0 END';

    private const RANK = 'COALESCE(s.rank, 0)';

    private const DATE = "COALESCE(c.validated_at, c.created_at, '1970-01-01 00:00:00')";

    // Chosen civic name, then an explicitly public social pseudonym. Legal
    // users.name and private social handles are never selected or searched.
    private const PUBLIC_NAME = "COALESCE(NULLIF(u.display_name, ''), CASE WHEN p.visibility = 'public' THEN COALESCE(NULLIF(p.display_name, ''), NULLIF('@' || p.handle, '@')) END, '')";

    public function page(Request $request, ElectionRace $race, ?User $viewer): array
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'organization' => ['nullable', 'string', 'max:160'],
            'endorser' => ['nullable', Rule::in(['any', 'organizations', 'individuals', 'none'])],
            'incumbents' => ['nullable', 'boolean'],
            'approved' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $filters = [
            'q' => trim($values['q'] ?? ''),
            'organization' => trim($values['organization'] ?? ''),
            'endorser' => $values['endorser'] ?? 'any',
            'incumbents' => (bool) ($values['incumbents'] ?? false),
            'approved' => (bool) ($values['approved'] ?? false),
        ];
        $date = DB::table('approval_standings')->where('race_id', $race->id)->where('is_frozen', true)->max('as_of_date')
            ?? DB::table('approval_standings')->where('race_id', $race->id)->max('as_of_date');
        $query = DB::table('candidacies as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('social_profiles as p', fn ($join) => $join->on('p.user_id', '=', 'u.id')->whereNull('p.deleted_at'))
            ->leftJoin('approval_standings as s', function ($join) use ($race, $date) {
                $join->on('s.candidacy_id', '=', 'c.id')->where('s.race_id', $race->id);
                $date === null ? $join->whereRaw('1 = 0') : $join->where('s.as_of_date', $date);
            })
            ->where('c.race_id', $race->id)->where('c.election_id', $race->election_id)->whereNull('c.deleted_at')->whereNull('u.deleted_at')
            ->whereIn('c.status', [...self::STANDING, 'withdrawn', 'elected', 'defeated'])
            ->where(fn ($q) => $q->whereNotNull('s.id')->orWhereIn('c.status', self::STANDING));
        $total = (clone $query)->count('c.id');
        $private = fn () => DB::table('approvals as a')->join('candidacies as ac', 'ac.id', '=', 'a.candidacy_id')
            ->where('a.user_id', $viewer?->getKey())->where('a.election_id', $race->election_id)
            ->where('ac.race_id', $race->id)->whereNull('ac.deleted_at')->whereNull('a.revoked_at');
        $ownTotal = $viewer === null ? 0 : $private()->count();

        if ($filters['q'] !== '') {
            $like = $this->like($filters['q']);
            $query->where(fn ($q) => $q->whereRaw('LOWER('.self::PUBLIC_NAME.") LIKE ? ESCAPE '\\'", [$like])
                ->orWhere(fn ($q) => $q->where('p.visibility', 'public')->whereRaw("LOWER('@' || p.handle) LIKE ? ESCAPE '\\'", [$like]))
                ->orWhereRaw("LOWER(CAST(c.id AS TEXT)) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(COALESCE(c.platform_statement, '')) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(COALESCE(CAST(c.position_tags AS TEXT), '')) LIKE ? ESCAPE '\\'", [$like]));
        }
        if ($filters['organization'] !== '' || $filters['endorser'] === 'organizations') {
            $query->whereExists(function ($q) use ($filters) {
                $q->selectRaw('1')->from('endorsements as e')->join('organizations as o', 'o.id', '=', 'e.endorser_id')
                    ->whereColumn('e.candidate_id', 'c.id')->where('e.endorser_type', 'organization')
                    ->where('e.is_active', true)->where('e.is_public', true)->whereNull('e.withdrawn_at')->whereNull('o.deleted_at');
                if ($filters['organization'] !== '') {
                    $q->whereRaw("LOWER(o.name) LIKE ? ESCAPE '\\'", [$this->like($filters['organization'])]);
                }
            });
        }
        if ($filters['endorser'] === 'individuals') {
            $query->whereExists(fn ($q) => $this->endorsementExists($q)->where('e.endorser_type', 'user'));
        } elseif ($filters['endorser'] === 'none') {
            $query->whereNotExists(fn ($q) => $this->endorsementExists($q)->where(function ($q) {
                $q->where('e.endorser_type', 'user')->orWhere(fn ($q) => $q->where('e.endorser_type', 'organization')
                    ->where('e.is_public', true)->whereExists(fn ($q) => $q->selectRaw('1')->from('organizations as o')
                    ->whereColumn('o.id', 'e.endorser_id')->whereNull('o.deleted_at')));
            }));
        }
        if ($filters['incumbents']) {
            $query->whereExists(fn ($q) => $this->incumbentQuery($q, $race));
        }
        if ($filters['approved']) {
            $viewer === null ? $query->whereRaw('1 = 0') : $query->whereExists(fn ($q) => $q->selectRaw('1')->from('approvals as a')
                ->whereColumn('a.candidacy_id', 'c.id')->where('a.election_id', $race->election_id)
                ->where('a.user_id', $viewer->getKey())->whereNull('a.revoked_at'));
        }
        $context = hash('sha256', json_encode([$race->id, $date, $filters, $filters['approved'] ? $viewer?->getKey() : null]));
        $notice = null;
        try {
            $cursor = $this->cursor($values['cursor'] ?? null, $context, ['group', 'rank', 'date', 'id']);
        } catch (ValidationException) {
            // GET validation redirects back, which can loop for a stale bookmarked
            // cursor (or after an approval redirects to yesterday's standings).
            $cursor = null;
            $notice = __('The standings or search have changed, or the page link expired. Showing the first page.');
        }
        $previous = ($cursor['direction'] ?? null) === 'previous';
        if ($cursor) {
            $query->whereRaw('('.self::GROUP.', '.self::RANK.', '.self::DATE.', c.id) '.($previous ? '<' : '>').' (?, ?, ?, ?)',
                [$cursor['group'], $cursor['rank'], $cursor['date'], $cursor['id']]);
        }
        $direction = $previous ? 'desc' : 'asc';
        $rows = $query->select(['c.id', 'c.user_id', 'c.status', 'c.platform_statement', 'c.position_tags', 'p.handle', 'p.visibility',
            's.rank', 's.approvals_count', 's.delta'])
            ->selectRaw(self::PUBLIC_NAME.' as public_name')
            ->selectRaw(self::GROUP.' as page_group, '.self::RANK.' as page_rank, '.self::DATE.' as page_date')
            ->orderByRaw(self::GROUP.' '.$direction)->orderByRaw(self::RANK.' '.$direction)
            ->orderByRaw(self::DATE.' '.$direction)->orderBy('c.id', $direction)->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        if ($previous) {
            $rows = $rows->reverse()->values();
        }
        $ids = $rows->pluck('id')->all();
        $userIds = $rows->pluck('user_id')->all();
        $legislature = $race->election?->legislature_id;
        $incumbents = $ids === [] || $legislature === null ? [] : DB::table('legislature_members')
            ->where('legislature_id', $legislature)->whereIn('user_id', $userIds)
            ->whereIn('status', ['elected', 'seated'])->whereNull('deleted_at')->distinct()->pluck('user_id')->all();
        $own = $viewer === null || $ids === [] ? [] : $private()->whereIn('a.candidacy_id', $ids)->pluck('a.candidacy_id')->all();
        $counts = $ids === [] ? collect() : DB::table('endorsements')->whereIn('candidate_id', $ids)
            ->where('endorser_type', 'user')->where('is_active', true)->whereNull('withdrawn_at')
            ->groupBy('candidate_id')->selectRaw('candidate_id, count(*) as total')->pluck('total', 'candidate_id');
        $standings = $rows->map(function ($row) use ($incumbents, $counts) {
            $organizations = $this->organizationQuery($row->id)->orderBy('e.id')->limit(self::ORGANIZATION_PREVIEW_SIZE + 1)
                ->get(['o.id', 'o.name', 'o.type']);

            return [
                'rank' => $row->rank === null ? null : (int) $row->rank,
                'approvals' => (int) ($row->approvals_count ?? 0), 'delta' => (int) ($row->delta ?? 0),
                'candidacy_id' => (string) $row->id, 'status' => $row->status,
                'candidacy' => [
                    'id' => (string) $row->id, 'name' => $row->public_name ?: 'Resident-'.substr(hash('sha256', $row->user_id), 0, 8),
                    'public_handle' => $row->visibility === 'public' && $row->handle ? '@'.$row->handle : null,
                    'profile_reference' => (string) $row->id,
                    'statement' => $row->platform_statement === null ? null : Str::limit($row->platform_statement, 1200),
                    'position_tags' => array_slice(json_decode($row->position_tags ?? '[]', true) ?: [], 0, 12),
                    'incumbent' => in_array($row->user_id, $incumbents, true), 'profile_href' => '/candidates/'.$row->id,
                    'endorsements' => ['orgs' => $organizations->take(self::ORGANIZATION_PREVIEW_SIZE)->map(fn ($o) => (array) $o)->all(),
                        'more_organizations' => $organizations->count() > self::ORGANIZATION_PREVIEW_SIZE,
                        'individual_count' => (int) ($counts[$row->id] ?? 0)],
                ],
            ];
        })->all();
        $path = '/elections/'.$race->election_id.'/open-ballot';
        $parameters = ['race' => $race->id] + $filters;
        $pageLink = fn ($row, $direction) => $path.'?'.http_build_query($parameters + ['cursor' => $this->encode([
            'context' => $context, 'direction' => $direction, 'group' => (int) $row->page_group,
            'rank' => (int) $row->page_rank, 'date' => $row->page_date, 'id' => $row->id,
        ])]);

        return ['standings' => $standings, 'myApprovals' => $own, 'filters' => $filters, 'notice' => $notice,
            'asOf' => $date, 'total' => $total, 'myActiveApprovals' => $ownTotal,
            'pagination' => ['pageSize' => self::PAGE_SIZE,
                'previous' => $rows->isNotEmpty() && ($previous ? $hasMore : $cursor !== null) ? $pageLink($rows->first(), 'previous') : null,
                'next' => $rows->isNotEmpty() && ($previous ? $cursor !== null : $hasMore) ? $pageLink($rows->last(), 'next') : null]];
    }

    public function endorsements(Request $request, ElectionRace $race): ?array
    {
        $values = $request->validate(['endorsements_for' => ['nullable', 'uuid'], 'endorsement_cursor' => ['nullable', 'string', 'max:2048']]);
        if (empty($values['endorsements_for'])) {
            return null;
        }
        $candidate = Candidacy::query()->where('race_id', $race->id)->where('election_id', $race->election_id)
            ->whereKey($values['endorsements_for'])->firstOrFail(['id']);
        $context = hash('sha256', 'endorsements:'.$race->id.':'.$candidate->id);
        $notice = null;
        try {
            $cursor = $this->cursor($values['endorsement_cursor'] ?? null, $context, ['id'], 'endorsement_cursor');
        } catch (ValidationException) {
            $cursor = null;
            $notice = __('This endorsement page link expired. Showing the first page.');
        }
        $previous = ($cursor['direction'] ?? null) === 'previous';
        $query = $this->organizationQuery($candidate->id);
        if ($cursor) {
            $query->where('e.id', $previous ? '<' : '>', $cursor['id']);
        }
        $rows = $query->orderBy('e.id', $previous ? 'desc' : 'asc')->limit(self::PAGE_SIZE + 1)
            ->get(['e.id as endorsement_id', 'o.id', 'o.name', 'o.type']);
        $hasMore = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        if ($previous) {
            $rows = $rows->reverse()->values();
        }
        $link = fn ($row, $direction) => '/elections/'.$race->election_id.'/open-ballot?'.http_build_query([
            'race' => $race->id, 'endorsements_for' => $candidate->id,
            'endorsement_cursor' => $this->encode(['context' => $context, 'direction' => $direction, 'id' => $row->endorsement_id]),
        ] + $request->only(['q', 'organization', 'endorser', 'incumbents', 'approved', 'cursor']));

        return ['candidateId' => $candidate->id, 'notice' => $notice, 'organizations' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'type' => $r->type])->all(),
            'previous' => $rows->isNotEmpty() && ($previous ? $hasMore : $cursor !== null) ? $link($rows->first(), 'previous') : null,
            'next' => $rows->isNotEmpty() && ($previous ? $cursor !== null : $hasMore) ? $link($rows->last(), 'next') : null];
    }

    private function organizationQuery(string $candidate): Builder
    {
        return DB::table('endorsements as e')->join('organizations as o', 'o.id', '=', 'e.endorser_id')
            ->where('e.candidate_id', $candidate)->where('e.endorser_type', 'organization')
            ->where('e.is_active', true)->where('e.is_public', true)->whereNull('e.withdrawn_at')->whereNull('o.deleted_at');
    }

    private function endorsementExists(Builder $query): Builder
    {
        return $query->selectRaw('1')->from('endorsements as e')->whereColumn('e.candidate_id', 'c.id')
            ->where('e.is_active', true)->whereNull('e.withdrawn_at');
    }

    private function incumbentQuery(Builder $query, ElectionRace $race): Builder
    {
        return $query->selectRaw('1')->from('legislature_members as m')->whereColumn('m.user_id', 'c.user_id')
            ->where('m.legislature_id', $race->election?->legislature_id)->whereIn('m.status', ['elected', 'seated'])->whereNull('m.deleted_at');
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($value)).'%';
    }

    private function encode(array $values): string
    {
        return rtrim(strtr(base64_encode(json_encode($values)), '+/', '-_'), '=');
    }

    private function cursor(?string $encoded, string $context, array $keys, string $field = 'cursor'): ?array
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $value = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value) || count($value) !== count($keys) + 2 || ($value['context'] ?? null) !== $context
                || ! in_array($value['direction'] ?? null, ['previous', 'next'], true)
                || ! is_string($value['id'] ?? null) || ! Str::isUuid($value['id'])) {
                throw new \InvalidArgumentException;
            }
            if (in_array('rank', $keys, true) && (! in_array($value['group'] ?? null, [0, 1], true)
                || ! is_int($value['rank'] ?? null) || $value['rank'] < 0
                || ! is_string($value['date'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}(?::?\d{2})?|Z)?$/D', $value['date']))) {
                throw new \InvalidArgumentException;
            }
            if (isset($value['date'])) {
                new \DateTimeImmutable($value['date']);
            }

            return $value;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('This page link is no longer valid. Return to the first page.')]);
        }
    }
}
