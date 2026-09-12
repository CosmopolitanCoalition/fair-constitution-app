<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Search the consent-name roster; never resolve people from economic accounts. */
final class AgreementPartyDirectory
{
    public const PAGE_SIZE = 20;

    public static function empty(): array
    {
        return ['query' => '', 'searched' => false, 'candidates' => [], 'previous' => null, 'next' => null, 'pageSize' => self::PAGE_SIZE];
    }

    public function page(Request $request, string $viewerId): array
    {
        $values = $request->validate([
            'party_q' => ['nullable', 'string', 'max:120'],
            'party_cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $search = trim($values['party_q'] ?? '');
        $prefix = mb_strtolower($search);
        $encoded = $values['party_cursor'] ?? '';
        $cursor = null;

        // Validate before opening a database connection. Laravel's cursor
        // decoder alone accepts unexpected parameters/direction values.
        if ($encoded !== '') {
            try {
                $json = base64_decode(strtr($encoded, '-_', '+/'), true);
                $data = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 3
                    || ! is_string($data['directory_name'] ?? null)
                    || mb_strlen($data['directory_name']) > 255
                    || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
                    || ! is_bool($data['_pointsToNextItems'] ?? null)
                    || $prefix === '' || ! str_starts_with($data['directory_name'], $prefix)) {
                    throw new \InvalidArgumentException;
                }
                $cursor = new Cursor(['directory_name' => $data['directory_name'], 'id' => $data['id']], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['party_cursor' => 'This page link is invalid. Search for the person again.']);
            }
        }

        if ($search === '') {
            return self::empty();
        }

        $nameKey = DB::getDriverName() === 'pgsql' ? 'lower(name) COLLATE "C"' : 'lower(name)';
        $literalPrefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);
        $query = DB::table('users')->select(['id', 'name'])->selectRaw($nameKey.' as directory_name')
            ->whereNull('deleted_at')->where('id', '<>', $viewerId)
            ->whereRaw($nameKey." LIKE ? ESCAPE '!'", [$literalPrefix.'%']);
        if ($cursor !== null) {
            // Direct tuple seek uses the same composite index as the initial
            // prefix search. No offset scan or full-roster name sort.
            $query->whereRaw('('.$nameKey.', id) '.($cursor->pointsToNextItems() ? '>' : '<').' (?, ?)', [
                $cursor->parameter('directory_name'), $cursor->parameter('id'),
            ]);
        }
        $page = $query->orderBy('directory_name')->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE, cursorName: 'party_cursor', cursor: $cursor)
            ->withPath('/economy/resident-agreements')->appends(['new' => 1, 'party_q' => $search]);

        return [
            'query' => $search,
            'searched' => true,
            'candidates' => $page->getCollection()->map(fn ($row) => ['id' => (string) $row->id, 'name' => (string) $row->name])->all(),
            'previous' => $page->previousPageUrl(),
            'next' => $page->nextPageUrl(),
            'pageSize' => self::PAGE_SIZE,
        ];
    }
}
