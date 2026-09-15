<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Private receipt history; account is supplied only by the authenticated wallet resolver. */
final class StipendReceiptDirectory
{
    public function page(Request $request, ?string $accountId): array
    {
        $input = $request->validate(['receipts_cursor' => ['nullable', 'string', 'max:1024']]);
        $encoded = $input['receipts_cursor'] ?? null;
        $cursor = null;
        if ($encoded !== null && $encoded !== '') {
            $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
            if (! is_array($data) || count($data) !== 2 || ! is_bool($data['_pointsToNextItems'] ?? null)
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])) {
                throw ValidationException::withMessages(['receipts_cursor' => __('This receipt page link is invalid. Open your wallet again.')]);
            }
            $cursor = new Cursor(['id' => $data['id']], $data['_pointsToNextItems']);
        }
        if ($accountId === null) return ['receipts' => [], 'pagination' => ['previous' => null, 'next' => null]];

        $page = DB::table('ubi_receipts')->where('account_id', $accountId)->orderBy('id')
            ->cursorPaginate(20, ['id', 'base', 'bump', 'amount', 'created_at'], 'receipts_cursor', $cursor)
            ->withPath('/economy/wallet');
        return [
            'receipts' => array_map(fn ($row) => [
                'id' => (string) $row->id, 'base' => (string) $row->base, 'bump' => (string) $row->bump,
                'amount' => (string) $row->amount,
                'at' => $row->created_at === null ? null : CarbonImmutable::parse($row->created_at)->toIso8601String(),
            ], $page->items()),
            'pagination' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
        ];
    }
}
