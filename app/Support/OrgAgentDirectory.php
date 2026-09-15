<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * IO-4 — the agent-transfer person search. Operator ruling 2026-09-13
 * (org-membership-agent-rules = A): ANY registered user may be named the new
 * agent, so this search is NOT scoped to a jurisdiction (unlike
 * GovernorNomineeDirectory). Public-name prefix seeks over the same indexable
 * public-name lanes — chosen display name, public social display name, public
 * handle — plus a complete profile-reference lookup. The raw users.name legal
 * name is never searched or returned. The current agent is excluded (a
 * transfer to self is a no-op).
 */
final class OrgAgentDirectory
{
    public const PAGE_SIZE = 20;

    public static function empty(): array
    {
        return ['query' => '', 'by' => 'name', 'searched' => false, 'candidates' => [], 'previous' => null, 'next' => null];
    }

    public function page(Request $request, Organization $org, ?string $basePath = null): array
    {
        $data = $request->validate([
            'agent_q'      => ['nullable', 'string', 'max:120'],
            'agent_by'     => ['nullable', Rule::in(['name', 'reference'])],
            'agent_cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $search = trim($data['agent_q'] ?? '');
        $by = $data['agent_by'] ?? 'name';
        $prefix = mb_strtolower($search);
        $current = $org->agent_user_id !== null ? (string) $org->agent_user_id : '';
        $scope = hash('sha256', $org->id."\n".$by."\n".$prefix);
        $cursor = $this->cursor($data['agent_cursor'] ?? null, $scope, $prefix);
        $result = array_replace(self::empty(), ['query' => $search, 'by' => $by, 'searched' => $search !== '']);
        if ($search === '') {
            return $result;
        }
        $base = $basePath ?? '/organizations/'.$org->id;

        if ($by === 'reference') {
            if (! Str::isUuid($search)) {
                throw ValidationException::withMessages(['agent_q' => __('Enter the complete profile reference.')]);
            }
            $rows = DB::table('users')->whereNull('deleted_at')->where('id', $search)
                ->when($current !== '', fn ($q) => $q->where('id', '!=', $current))
                ->limit(1)->get(['id'])->all();
        } else {
            $forward = $cursor['forward'] ?? true;
            $collation = DB::getDriverName() === 'pgsql' ? ' COLLATE "C"' : '';
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
            $rows = [];
            // Indexable public-name lanes. Every lane returns at most PAGE_SIZE+1
            // rows; their bounded merge yields the next PAGE_SIZE names/IDs. No
            // jurisdiction filter — any registered user is eligible (ruling A).
            foreach (str_starts_with($prefix, '@') ? ['handle'] : ['chosen', 'social'] as $lane) {
                $query = DB::table('users as u')->whereNull('u.deleted_at');
                if ($current !== '') {
                    $query->where('u.id', '!=', $current);
                }
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
                $keyExpr = 'lower('.$name.')'.$collation;
                $query->whereRaw($keyExpr." LIKE ? ESCAPE '!'", [$literal]);
                if ($cursor) {
                    $query->whereRaw('('.$keyExpr.', u.id) '.($forward ? '>' : '<').' (?, ?)', [$cursor['name'], $cursor['id']]);
                }
                $rows = array_merge($rows, $query->selectRaw('u.id, '.$keyExpr.' as directory_name')
                    ->orderByRaw($keyExpr.($forward ? ' ASC' : ' DESC'))->orderBy('u.id', $forward ? 'asc' : 'desc')
                    ->limit(self::PAGE_SIZE + 1)->get()->all());
            }
            usort($rows, fn ($a, $b) => ($forward ? 1 : -1) * (strcmp($a->directory_name, $b->directory_name) ?: strcmp($a->id, $b->id)));
            $more = count($rows) > self::PAGE_SIZE;
            $rows = array_slice($rows, 0, self::PAGE_SIZE);
            if (! $forward) {
                $rows = array_reverse($rows);
            }
            $link = function ($row, bool $next) use ($base, $search, $by, $scope) {
                if (! $row) {
                    return null;
                }
                $encoded = rtrim(strtr(base64_encode((string) json_encode(
                    ['scope' => $scope, 'name' => $row->directory_name, 'id' => $row->id, 'forward' => $next]
                )), '+/', '-_'), '=');

                return $base.'?'.http_build_query(['agent_q' => $search, 'agent_by' => $by, 'agent_cursor' => $encoded]);
            };
            $result['previous'] = ($forward ? $cursor !== null : $more) ? $link($rows[0] ?? null, false) : null;
            $result['next'] = ($forward ? $more : $cursor !== null) ? $link($rows[count($rows) - 1] ?? null, true) : null;
        }

        $ids = array_map(fn ($row) => (string) $row->id, $rows);
        $names = \App\Http\Presenters\CandidacyPanel::displayNames($ids);
        $context = PublicPersonSelectionContext::forIds($ids);
        $result['candidates'] = array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'Resident'] + $context[$id], $ids);

        return $result;
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
            throw ValidationException::withMessages(['agent_cursor' => __('This person page link is invalid. Search for the name again.')]);
        }
    }
}
