<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

/** Two bounded account-index seeks; an OR scan must not sort an entire wallet. */
final class TransactionHistory
{
    public function page(Request $request, array $accountIds, string $path, string $cursorName = 'transactions_cursor'): array
    {
        $cursor = FinancialHistoryCursor::read($request, $cursorName, 'transaction');
        if ($accountIds === []) return ['transactions' => [], 'pagination' => ['previous' => null, 'next' => null]];
        $previous = $cursor?->pointsToPreviousItems() ?? false;
        $order = $previous ? 'asc' : 'desc';
        $rows = collect();
        foreach (['from_account_id', 'to_account_id'] as $column) {
            $query = DB::table('market_transactions')->whereIn($column, $accountIds);
            // Internal transfers are one record, including transfer-to-self.
            if ($column === 'to_account_id') $query->where(fn ($q) => $q->whereNull('from_account_id')->orWhereNotIn('from_account_id', $accountIds));
            if ($cursor) {
                $op = $previous ? '>' : '<';
                $query->where(fn ($q) => $q->where('created_at', $op, $cursor->parameter('created_at'))
                    ->orWhere(fn ($same) => $same->where('created_at', $cursor->parameter('created_at'))->where('id', $op, $cursor->parameter('id'))));
            }
            $rows = $rows->concat($query->orderBy('created_at', $order)->orderBy('id', $order)->limit(21)
                ->get(['id', 'from_account_id', 'to_account_id', 'amount', 'kind', 'memo', 'created_at']));
        }
        $rows = $rows->sort(function ($a, $b) use ($previous) {
            $compare = CarbonImmutable::parse($a->created_at) <=> CarbonImmutable::parse($b->created_at);
            $compare = $compare ?: strcmp($a->id, $b->id);
            return $previous ? $compare : -$compare;
        })->values();
        $page = new CursorPaginator($rows, 20, $cursor, ['path' => $path, 'cursorName' => $cursorName, 'parameters' => ['created_at', 'id']]);
        return [
            'transactions' => array_map(function ($row) use ($accountIds) {
                $out = in_array((string) $row->from_account_id, $accountIds, true);
                return ['id' => (string) $row->id, 'direction' => $out ? 'out' : 'in', 'amount' => (string) $row->amount,
                    'kind' => (string) $row->kind, 'memo' => $row->memo,
                    'at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
                    'counterparty_account_id' => $out ? $row->to_account_id : $row->from_account_id];
            }, $page->items()),
            'pagination' => FinancialHistoryCursor::links($page),
        ];
    }
}
