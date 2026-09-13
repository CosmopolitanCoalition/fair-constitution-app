<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Page public ownership lots before resolving the names on that page. */
final class OrgShareDirectory
{
    public const PAGE_SIZE = 20;

    public function page(Request $request, Organization $org): array
    {
        $values = $request->validate(['share_cursor' => ['nullable', 'string', 'max:2048']]);
        $cursor = $this->cursor($values['share_cursor'] ?? null, (string) $org->id);
        $query = DB::table('org_ownership_stakes')->where('organization_id', $org->id)->whereNull('ended_at');
        $page = (clone $query)->select(['id', 'holder_type', 'holder_id', 'units', 'pct', 'acquired_via'])
            ->orderByDesc('id')->cursorPaginate(self::PAGE_SIZE, cursorName: 'share_cursor', cursor: $cursor);
        $rows = $page->getCollection();
        $names = [];
        foreach (['users', 'organizations', 'jurisdictions'] as $type) {
            $ids = $rows->where('holder_type', $type)->pluck('holder_id')->unique()->values()->all();
            if ($ids === []) {
                continue;
            }
            $lookup = DB::table($type)->whereIn('id', $ids)->whereNull('deleted_at');
            $names[$type] = $type === 'users'
                ? $lookup->select(['id', 'display_name', 'name'])->get()->mapWithKeys(fn ($user) => [
                    (string) $user->id => trim((string) $user->display_name) !== '' ? $user->display_name : $user->name,
                ])->all()
                : $lookup->pluck('name', 'id')->all();
        }
        $issuable = (string) $org->structure === Organization::STRUCTURE_STOCK;

        return [
            'issued' => $rows->isNotEmpty() || ($cursor !== null && $query->exists()),
            'issuable' => $issuable,
            'holders' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'holder' => (string) ($names[$row->holder_type][$row->holder_id] ?? match ($row->holder_type) {
                    'organizations' => 'An organization', 'jurisdictions' => 'A jurisdiction', default => 'A holder',
                }),
                'units' => (string) $row->units,
                'pct' => $row->pct === null ? null : (string) $row->pct,
                'via' => (string) $row->acquired_via,
            ])->all(),
            'previous' => $this->url($page->previousCursor(), (string) $org->id),
            'next' => $this->url($page->nextCursor(), (string) $org->id),
            'pageSize' => self::PAGE_SIZE,
            'note' => $issuable
                ? 'Each row is one current share lot. A holder can have several lots. Ownership is public; payments remain private.'
                : ($org->structure === null
                    ? 'No ownership structure is recorded. Only a stock organization can issue shares.'
                    : 'Ownership here is by membership. Only a stock organization can issue shares.'),
        ];
    }

    private function cursor(?string $encoded, string $organizationId): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $data = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 3 || ($data['organization'] ?? null) !== $organizationId
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
                || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                throw new \InvalidArgumentException;
            }

            return new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['share_cursor' => 'This page link is invalid. Open the organization again.']);
        }
    }

    private function url(?Cursor $cursor, string $organizationId): ?string
    {
        if ($cursor === null) {
            return null;
        }
        $scoped = new Cursor(['id' => $cursor->parameter('id'), 'organization' => $organizationId], $cursor->pointsToNextItems());

        return '/organizations/'.$organizationId.'/economy?'.http_build_query(['share_cursor' => $scoped->encode()]);
    }
}
