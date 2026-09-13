<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrganizationFinancialHistory
{
    public static function empty(): array
    {
        return ['records' => [], 'pagination' => ['previous' => null, 'next' => null]];
    }

    public function taxes(Request $request, array $accountIds, string $path): array
    {
        $cursor = FinancialHistoryCursor::read($request, 'taxes_cursor');
        if ($accountIds === []) return self::empty();
        // Page the filings first; enrich only the displayed records with levy law.
        $page = DB::table('tax_filings')->whereIn('account_id', $accountIds)->orderByDesc('id')
            ->cursorPaginate(20, ['id', 'levy_id', 'period', 'declared', 'assessed', 'status'], 'taxes_cursor', $cursor)->withPath($path);
        $levies = DB::table('levies as l')->leftJoin('revenue_streams as r', 'r.id', '=', 'l.revenue_stream_id')
            ->whereIn('l.id', array_values(array_unique(array_column($page->items(), 'levy_id'))))
            ->get(['l.id', 'l.base', 'l.rate', 'l.civic_exempt', 'r.name as stream'])->keyBy('id');
        return ['records' => array_map(function ($filing) use ($levies) {
            $levy = $levies->get($filing->levy_id);
            return ['id' => (string) $filing->id, 'period' => (string) $filing->period,
                'declared' => $filing->declared === null ? null : (string) $filing->declared,
                'assessed' => $filing->assessed === null ? null : (string) $filing->assessed,
                'status' => (string) $filing->status, 'stream' => $levy?->stream,
                'base' => $levy?->base, 'rate' => $levy?->rate === null ? null : (string) $levy->rate,
                'civic_exempt' => $levy?->civic_exempt === null ? null : (bool) $levy->civic_exempt];
        }, $page->items()), 'pagination' => FinancialHistoryCursor::links($page)];
    }

    public function conversions(Request $request, string $organizationId, string $path): array
    {
        $cursor = FinancialHistoryCursor::read($request, 'conversions_cursor');
        $page = DB::table('org_conversions')->where('organization_id', $organizationId)->whereNull('deleted_at')
            ->orderByDesc('id')->cursorPaginate(20, ['id', 'direction', 'via', 'status', 'fair_market_floor', 'fair_market_basis', 'completed_at'], 'conversions_cursor', $cursor)->withPath($path);
        return ['records' => array_map(fn ($row) => [
            'id' => (string) $row->id, 'direction' => (string) $row->direction, 'via' => (string) $row->via,
            'status' => (string) $row->status, 'fair_market_floor' => $row->fair_market_floor === null ? null : (string) $row->fair_market_floor,
            'fair_market_basis' => $row->fair_market_basis, 'completed_at' => $row->completed_at === null ? null : \Carbon\CarbonImmutable::parse($row->completed_at)->toIso8601String(),
        ], $page->items()), 'pagination' => FinancialHistoryCursor::links($page)];
    }
}
