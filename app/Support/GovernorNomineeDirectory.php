<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Public-name prefix seeks for one jurisdiction; no legal-name or private-profile fallback. */
final class GovernorNomineeDirectory
{
    public const PAGE_SIZE = 20;

    public static function empty(): array
    {
        return ['query' => '', 'by' => 'name', 'searched' => false, 'candidates' => [], 'previous' => null, 'next' => null];
    }

    public function page(Request $request, string $organizationId, string $jurisdictionId): array
    {
        $data = $request->validate([
            'nominee_q' => ['nullable', 'string', 'max:120'],
            'nominee_by' => ['nullable', Rule::in(['name', 'reference'])],
            'nominee_cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $search = trim($data['nominee_q'] ?? '');
        $by = $data['nominee_by'] ?? 'name';
        $prefix = mb_strtolower($search);
        $scope = hash('sha256', $organizationId."\n".$jurisdictionId."\n".$by."\n".$prefix);
        $cursor = $this->cursor($data['nominee_cursor'] ?? null, $scope, $prefix);
        $result = array_replace(self::empty(), ['query' => $search, 'by' => $by, 'searched' => $search !== '']);
        if ($search === '') {
            return $result;
        }

        if ($by === 'reference') {
            if (! Str::isUuid($search)) {
                throw ValidationException::withMessages(['nominee_q' => 'Enter the complete profile reference.']);
            }
            $ids = $this->associated(DB::table('users as u')->whereNull('u.deleted_at')->where('u.id', $search), $jurisdictionId)
                ->limit(1)->pluck('u.id')->all();
            $names = \App\Http\Presenters\CandidacyPanel::displayNames($ids);
            $rows = array_map(fn ($id) => (object) ['id' => (string) $id, 'name' => $names[$id]], $ids);
        } else {
            $forward = $cursor['forward'] ?? true;
            $collation = DB::getDriverName() === 'pgsql' ? ' COLLATE "C"' : '';
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
            $rows = [];
            // Separate indexable public-name lanes. Every lane returns at most
            // 21 rows; their bounded merge yields the next 20 names/IDs.
            foreach (str_starts_with($prefix, '@') ? ['handle'] : ['chosen', 'social'] as $lane) {
                $query = DB::table('users as u')->whereNull('u.deleted_at');
                if ($lane === 'chosen') {
                    $query->whereNotNull('u.display_name')->where('u.display_name', '!=', '');
                    $name = 'u.display_name';
                } else {
                    $query->join('social_profiles as p', 'p.user_id', '=', 'u.id')
                        ->where('p.visibility', 'public')->whereNull('p.deleted_at');
                    if ($lane === 'social') {
                        $query->where(fn ($q) => $q->whereNull('u.display_name')->orWhere('u.display_name', ''));
                        $query->whereNotNull('p.display_name')->where('p.display_name', '!=', '');
                        $name = 'p.display_name';
                    } else {
                        $query->whereNotNull('p.handle')->where('p.handle', '!=', '');
                        $name = "'@' || p.handle";
                    }
                }
                $key = 'lower('.$name.')'.$collation;
                $query = $this->associated($query, $jurisdictionId)->whereRaw($key." LIKE ? ESCAPE '!'", [$literal]);
                if ($cursor) {
                    $query->whereRaw('('.$key.', u.id) '.($forward ? '>' : '<').' (?, ?)', [$cursor['name'], $cursor['id']]);
                }
                $rows = array_merge($rows, $query->selectRaw('u.id, '.$name.' as name, '.$key.' as directory_name')
                    ->orderByRaw($key.($forward ? ' ASC' : ' DESC'))->orderBy('u.id', $forward ? 'asc' : 'desc')
                    ->limit(self::PAGE_SIZE + 1)->get()->all());
            }
            usort($rows, fn ($a, $b) => ($forward ? 1 : -1) * (strcmp($a->directory_name, $b->directory_name) ?: strcmp($a->id, $b->id)));
            $more = count($rows) > self::PAGE_SIZE;
            $rows = array_slice($rows, 0, self::PAGE_SIZE);
            if (! $forward) {
                $rows = array_reverse($rows);
            }
            $link = function ($row, bool $next) use ($organizationId, $search, $by, $scope) {
                if (! $row) {
                    return null;
                }
                $encoded = rtrim(strtr(base64_encode(json_encode(['scope' => $scope, 'name' => $row->directory_name, 'id' => $row->id, 'forward' => $next])), '+/', '-_'), '=');

                return '/organizations/'.$organizationId.'/board-elections?'.http_build_query([
                    'nominee_q' => $search, 'nominee_by' => $by, 'nominee_cursor' => $encoded,
                ]);
            };
            $result['previous'] = ($forward ? $cursor !== null : $more) ? $link($rows[0] ?? null, false) : null;
            $result['next'] = ($forward ? $more : $cursor !== null) ? $link($rows[count($rows) - 1] ?? null, true) : null;
        }
        $names = \App\Http\Presenters\CandidacyPanel::displayNames(array_column($rows, 'id'));
        $context = PublicPersonSelectionContext::forIds(array_column($rows, 'id'));
        $result['candidates'] = array_map(fn ($row) => ['id' => (string) $row->id, 'name' => $names[$row->id]] + $context[$row->id], $rows);

        return $result;
    }

    private function associated(Builder $query, string $jurisdiction): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('residency_confirmations as r')
            ->whereColumn('r.user_id', 'u.id')->where('r.jurisdiction_id', $jurisdiction)->where('r.is_active', true));
    }

    private function cursor(?string $encoded, string $scope, string $prefix): ?array
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        try {
            $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 4 || ($data['scope'] ?? null) !== $scope
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id']) || ! is_bool($data['forward'] ?? null)
                || ! is_string($data['name'] ?? null) || $prefix === '' || ! str_starts_with($data['name'], $prefix)) {
                throw new \InvalidArgumentException;
            }

            return $data;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['nominee_cursor' => 'This nominee page link is invalid. Search for the name again.']);
        }
    }
}
