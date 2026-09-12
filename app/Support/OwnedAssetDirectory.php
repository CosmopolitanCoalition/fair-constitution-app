<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** An account's item roster. The controller supplies the already-authorized account. */
final class OwnedAssetDirectory
{
    public const PAGE_SIZE = 20;

    public static function empty(): array
    {
        return ['assets' => [], 'query' => '', 'previous' => null, 'next' => null, 'pageSize' => self::PAGE_SIZE, 'available' => false];
    }

    public function page(Request $request, ?string $accountId, bool $unlistedOnly = false): array
    {
        $values = $request->validate([
            'asset_q' => ['nullable', 'string', 'max:120'],
            'asset_cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $search = trim($values['asset_q'] ?? '');
        $prefix = mb_strtolower($search);
        $cursor = $this->cursor($values['asset_cursor'] ?? null, $prefix);
        if ($accountId === null) {
            return self::empty();
        }

        $nameKey = DB::getDriverName() === 'pgsql' ? 'lower(name) COLLATE "C"' : 'lower(name)';
        $query = DB::table('assets')->select(['id', 'name', 'kind', 'quantity', 'origin', 'created_at'])
            ->selectRaw($nameKey.' as directory_name')
            ->where('owner_account_id', $accountId)->whereNull('deleted_at');
        if ($prefix !== '') {
            $literalPrefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);
            $query->whereRaw($nameKey." LIKE ? ESCAPE '!'", [$literalPrefix.'%']);
        }
        if ($unlistedOnly) {
            // OFFSET 0 is an intentional PostgreSQL correlation barrier: keep
            // an indexed per-asset subplan instead of letting the planner hash
            // the world's open listings into a decorrelated anti join.
            $query->whereNotExists(fn ($listing) => $listing->selectRaw('1')->from('marketplace_listings')
                ->whereColumn('marketplace_listings.asset_id', 'assets.id')->where('marketplace_listings.status', 'open')
                ->limit(1)->offset(0));
        }
        if ($cursor !== null) {
            $query->whereRaw('('.$nameKey.', id) '.($cursor->pointsToNextItems() ? '>' : '<').' (?, ?)', [
                $cursor->parameter('directory_name'), $cursor->parameter('id'),
            ]);
        }
        $page = $query->orderBy('directory_name')->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE, cursorName: 'asset_cursor', cursor: $cursor)
            ->withPath($unlistedOnly ? '/economy/market' : '/economy/wallet')
            ->appends(array_merge($unlistedOnly ? ['tab' => 'offers', 'asset_picker' => 1] : [], ['asset_q' => $search]));
        if ($unlistedOnly && is_string($request->query('cursor')) && strlen($request->query('cursor')) <= 2048) {
            // MarketDirectory validates this independent public-board cursor.
            // Browsing private items must not move the public board's page.
            $page->appends(['cursor' => $request->query('cursor')]);
        }

        return [
            'assets' => $page->getCollection()->map(fn ($asset) => [
                'id' => (string) $asset->id,
                'name' => (string) $asset->name,
                'kind' => (string) $asset->kind,
                'quantity' => (string) $asset->quantity,
                'origin' => (string) $asset->origin,
                'at' => $asset->created_at === null ? null : Carbon::parse((string) $asset->created_at)->toIso8601String(),
            ])->all(),
            'query' => $search,
            'previous' => $page->previousPageUrl(),
            'next' => $page->nextPageUrl(),
            'pageSize' => self::PAGE_SIZE,
            'available' => true,
        ];
    }

    private function cursor(?string $encoded, string $prefix): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $data = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 3 || ! is_bool($data['_pointsToNextItems'] ?? null)
                || ! is_string($data['directory_name'] ?? null) || mb_strlen($data['directory_name']) > 255
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
                || ($prefix !== '' && ! str_starts_with($data['directory_name'], $prefix))) {
                throw new \InvalidArgumentException;
            }

            return new Cursor(['directory_name' => $data['directory_name'], 'id' => $data['id']], $data['_pointsToNextItems']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['asset_cursor' => 'This item page link is invalid. Search for your item again.']);
        }
    }
}
