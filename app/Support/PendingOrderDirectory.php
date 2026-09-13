<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bounded seller-only order review. Buyer account pseudonyms never resolve to people. */
final class PendingOrderDirectory
{
    public function page(Request $request, string $listingId, ?string $viewerAccountId): array
    {
        $cursor = $this->cursor($request);
        $empty = ['orders' => [], 'pagination' => ['previous' => null, 'next' => null]];
        if ($viewerAccountId === null) return $empty;
        $isSeller = DB::table('marketplace_listings')->where('id', $listingId)
            ->where('seller_account_id', $viewerAccountId)->whereNull('deleted_at')->exists();
        if (! $isSeller) return $empty;

        $page = DB::table('marketplace_orders')->where('listing_id', $listingId)->where('status', 'placed')
            ->orderBy('id')->cursorPaginate(20, ['id', 'buyer_account_id', 'quantity', 'created_at'], 'orders_cursor', $cursor)
            ->withPath('/economy/market/'.$listingId);

        return [
            'orders' => array_map(fn ($row) => [
                'id' => (string) $row->id, 'buyer_account_id' => (string) $row->buyer_account_id,
                'quantity' => (string) $row->quantity,
                'at' => $row->created_at === null ? null : CarbonImmutable::parse($row->created_at)->toIso8601String(),
            ], $page->items()),
            'pagination' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
        ];
    }

    /** Also called at the controller entry before its listing/currency lookups. */
    public function cursor(Request $request): ?Cursor
    {
        $input = $request->validate(['orders_cursor' => ['nullable', 'string', 'max:1024']]);
        $encoded = $input['orders_cursor'] ?? null;
        if ($encoded === null || $encoded === '') return null;
        $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
        if (! is_array($data) || count($data) !== 2 || ! is_bool($data['_pointsToNextItems'] ?? null)
            || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])) {
            throw ValidationException::withMessages(['orders_cursor' => 'This order page link is invalid. Open the listing again.']);
        }
        return new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
    }
}
