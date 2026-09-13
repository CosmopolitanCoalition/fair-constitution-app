<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Named ownership search; callers must authorize share issuance first. */
final class OrgShareRecipientDirectory
{
    public const PAGE_SIZE = 20;
    public const USER_NAME = "lower(COALESCE(NULLIF(trim(display_name), ''), name))";
    public const ORGANIZATION_NAME = 'lower(name)';

    public static function empty(string $type = 'users'): array
    {
        return ['query' => '', 'type' => $type, 'searched' => false, 'candidates' => [], 'previous' => null, 'next' => null, 'pageSize' => self::PAGE_SIZE];
    }

    public function page(Request $request, Organization $org): array
    {
        $values = $request->validate([
            'recipient_q' => ['nullable', 'string', 'max:120'],
            'recipient_type' => ['nullable', Rule::in(['users', 'organizations'])],
            'recipient_cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $search = trim($values['recipient_q'] ?? '');
        $type = $values['recipient_type'] ?? 'users';
        $prefix = mb_strtolower($search);
        $scope = hash('sha256', $org->id."\n".$type."\n".$prefix);
        $cursor = $this->cursor($values['recipient_cursor'] ?? null, $prefix, $scope);
        if ($search === '') {
            return self::empty($type);
        }

        $nameKey = ($type === 'users' ? self::USER_NAME : self::ORGANIZATION_NAME)
            .(DB::getDriverName() === 'pgsql' ? ' COLLATE "C"' : '');
        $literalPrefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);
        $query = DB::table($type)->select($type === 'users' ? ['id', 'display_name', 'name'] : ['id', 'name'])
            ->selectRaw($nameKey.' as directory_name')->whereNull('deleted_at')
            ->whereRaw($nameKey." LIKE ? ESCAPE '!'", [$literalPrefix.'%']);
        if ($cursor !== null) {
            // Seek the name/id index directly, including on backward pages.
            $query->whereRaw('('.$nameKey.', id) '.($cursor->pointsToNextItems() ? '>' : '<').' (?, ?)', [
                $cursor->parameter('directory_name'), $cursor->parameter('id'),
            ]);
        }
        $page = $query->orderBy('directory_name')->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE, cursorName: 'recipient_cursor', cursor: $cursor);
        $contexts = $type === 'users' ? PublicPersonSelectionContext::forIds($page->getCollection()->pluck('id')->all()) : [];

        return [
            'query' => $search, 'type' => $type, 'searched' => true,
            'candidates' => $page->getCollection()->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $type === 'users' && trim((string) $row->display_name) !== '' ? trim((string) $row->display_name) : (string) $row->name,
                'type' => $type,
            ] + ($contexts[(string) $row->id] ?? ['profile_href' => '/organizations/'.$row->id, 'public_handle' => null]))->all(),
            'previous' => $this->url($page->previousCursor(), (string) $org->id, $type, $search, $scope),
            'next' => $this->url($page->nextCursor(), (string) $org->id, $type, $search, $scope),
            'pageSize' => self::PAGE_SIZE,
        ];
    }

    private function cursor(?string $encoded, string $prefix, string $scope): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $data = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 4 || ($data['scope'] ?? null) !== $scope
                || ! is_string($data['directory_name'] ?? null) || mb_strlen($data['directory_name']) > 255
                || $prefix === '' || ! str_starts_with($data['directory_name'], $prefix)
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
                || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                throw new \InvalidArgumentException;
            }

            return new Cursor(['directory_name' => $data['directory_name'], 'id' => $data['id']], $data['_pointsToNextItems']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['recipient_cursor' => 'This page link is invalid. Search for the recipient again.']);
        }
    }

    private function url(?Cursor $cursor, string $organizationId, string $type, string $search, string $scope): ?string
    {
        if ($cursor === null) {
            return null;
        }
        $scoped = new Cursor(['directory_name' => $cursor->parameter('directory_name'), 'id' => $cursor->parameter('id'), 'scope' => $scope], $cursor->pointsToNextItems());

        return '/organizations/'.$organizationId.'/economy?'.http_build_query([
            'issue' => 1, 'recipient_q' => $search, 'recipient_type' => $type, 'recipient_cursor' => $scoped->encode(),
        ]);
    }
}
