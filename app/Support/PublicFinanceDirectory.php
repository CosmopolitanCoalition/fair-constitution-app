<?php

namespace App\Support;

use App\Models\Jurisdiction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Public finance is browsed one place/account at a time; identities stay account-only. */
final class PublicFinanceDirectory
{
    private ?Jurisdiction $place;
    private array $selection;
    private array $resolved = [];

    public function __construct(private Request $request, private ?string $currencyId, ?string $defaultPlaceId)
    {
        $this->selection = $request->validate([
            'account' => ['nullable', 'uuid'], 'budget' => ['nullable', 'uuid'], 'revenue_source' => ['nullable', 'uuid'],
            'ledger_scope' => ['nullable', Rule::in(['account', 'currency'])],
        ]);
        $this->place = JurisdictionContext::requested($request)
            ?? ($defaultPlaceId === null ? null : Jurisdiction::query()->find($defaultPlaceId, ['id', 'name', 'slug', 'parent_id', 'adm_level']));
    }

    public function context(): ?array { return $this->place === null ? null : JurisdictionContext::forRoom($this->place); }
    public function place(): ?array { return JurisdictionContext::chip($this->place); }
    public function ledgerScope(): string { return $this->selection['ledger_scope'] ?? 'account'; }

    public function url(array $changes = []): string
    {
        $query = array_merge($this->request->only([
            'account', 'budget', 'revenue_source', 'ledger_scope', 'accounts_cursor', 'ledger_cursor', 'issuance_cursor',
            'budgets_cursor', 'borrowings_cursor', 'revenue_cursor', 'lines_cursor', 'levies_cursor', 'places_cursor',
        ]), ['jurisdiction' => $this->place?->id], $changes);
        return '/economy/treasury'.(($query = http_build_query(array_filter($query, fn ($v) => $v !== null && $v !== ''))) ? '?'.$query : '');
    }

    public function children(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'places_cursor');
        if ($this->place === null) return $this->empty('places_cursor');
        return $this->page(DB::table('jurisdictions')->where('parent_id', $this->place->id)->whereNull('deleted_at'),
            'places_cursor', ['id', 'name', 'slug', 'adm_level'], fn ($p) => JurisdictionContext::chip($p), $cursor);
    }

    private function accountsQuery(): Builder
    {
        $base = DB::table('treasury_accounts as a')->where('a.currency_id', $this->currencyId)->whereNull('a.deleted_at')->where('a.public', true)
            ->select(['a.id', 'a.owner_type', 'a.owner_id', 'a.label', 'a.balance', 'a.public']);
        $jurisdiction = (clone $base)->where('a.owner_type', 'jurisdictions')->where('a.owner_id', $this->place?->id);
        $departments = (clone $base)->join('departments as d', 'd.id', '=', 'a.owner_id')
            ->where('a.owner_type', 'departments')->where('d.jurisdiction_id', $this->place?->id)->whereNull('d.deleted_at');
        // Separate indexed owner paths: avoid walking every currency account to test an OR subquery.
        return DB::query()->fromSub($jurisdiction->unionAll($departments), 'public_accounts');
    }

    public function selectedAccount(): ?array
    {
        if (array_key_exists('account', $this->resolved)) return $this->resolved['account'];
        $id = $this->selection['account'] ?? null;
        if ($this->place === null || $this->currencyId === null) {
            abort_if($id !== null, 404);
            return $this->resolved['account'] = null;
        }
        $query = $this->accountsQuery();
        $row = $id ? $query->where('id', $id)->first() : $query->where('owner_type', 'jurisdictions')->first();
        abort_if($id !== null && $row === null, 404);
        return $this->resolved['account'] = $row === null ? null : $this->accountRow($row);
    }

    private function accountRow(object $row): array
    {
        return ['id' => (string) $row->id, 'owner_type' => (string) $row->owner_type, 'owner_id' => (string) $row->owner_id,
            'label' => $row->label, 'balance' => (string) $row->balance, 'public' => (bool) $row->public];
    }

    public function accounts(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'accounts_cursor');
        if ($this->place === null || $this->currencyId === null) return $this->empty('accounts_cursor');
        return $this->page($this->accountsQuery(), 'accounts_cursor', ['id', 'owner_type', 'owner_id', 'label', 'balance', 'public'], $this->accountRow(...), $cursor);
    }

    public function ledger(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'ledger_cursor', 'seq');
        $account = $this->selectedAccount();
        if ($this->currencyId === null || ($this->ledgerScope() === 'account' && $account === null)) return $this->empty('ledger_cursor');
        $query = DB::table('ledger_entries')->where('currency_id', $this->currencyId);
        if ($this->ledgerScope() === 'account') $query->where('account_type', 'treasury_accounts')->where('account_id', $account['id']);
        // Economic-account legs remain publicly readable by account ID. Never join bindings/users.
        return $this->page($query, 'ledger_cursor', ['seq', 'created_at', 'direction', 'amount', 'kind', 'account_type', 'account_id', 'hash'],
            fn ($e) => ['seq' => (int) $e->seq, 'at' => $this->iso($e->created_at), 'direction' => (string) $e->direction,
                'amount' => (string) $e->amount, 'kind' => (string) $e->kind, 'account_type' => (string) $e->account_type,
                'account_id' => (string) $e->account_id, 'hash' => (string) $e->hash], $cursor, 'seq', 50);
    }

    public function issuance(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'issuance_cursor', 'transaction');
        if ($this->currencyId === null) return $this->empty('issuance_cursor');
        return $this->page(DB::table('issuance_events')->where('currency_id', $this->currencyId)->orderByDesc('created_at'),
            'issuance_cursor', ['id', 'direction', 'amount', 'reason', 'created_at'],
            fn ($i) => ['id' => (string) $i->id, 'direction' => (string) $i->direction, 'amount' => (string) $i->amount,
                'reason' => (string) $i->reason, 'at' => $this->iso($i->created_at)], $cursor);
    }

    private function placeQuery(string $table): Builder
    {
        $query = DB::table($table)->where('jurisdiction_id', $this->place?->id)->where('currency_id', $this->currencyId);
        return $table === 'borrowings' ? $query : $query->whereNull('deleted_at');
    }

    public function budgets(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'budgets_cursor');
        if ($this->place === null || $this->currencyId === null) return $this->empty('budgets_cursor');
        return $this->withActs($this->page($this->placeQuery('budgets'), 'budgets_cursor',
            ['id', 'fiscal_label', 'total', 'status', 'enacted_at', 'enacting_act_id'],
            fn ($b) => ['id' => (string) $b->id, 'fiscal_label' => (string) $b->fiscal_label, 'total' => (string) $b->total,
                'status' => (string) $b->status, 'is_current' => $b->status === 'enacted', 'enacted_at' => $this->iso($b->enacted_at),
                'enacting_act_id' => $b->enacting_act_id, 'lines' => null, 'line_items' => []], $cursor));
    }

    public function borrowings(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'borrowings_cursor');
        if ($this->place === null || $this->currencyId === null) return $this->empty('borrowings_cursor');
        return $this->page($this->placeQuery('borrowings'), 'borrowings_cursor', ['id', 'principal', 'terms', 'status', 'lender_account_id', 'created_at'],
            fn ($b) => ['id' => (string) $b->id, 'principal' => (string) $b->principal, 'terms' => (string) $b->terms,
                'status' => (string) $b->status, 'lender_account_id' => $b->lender_account_id, 'at' => $this->iso($b->created_at)], $cursor);
    }

    public function revenue(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'revenue_cursor');
        if ($this->place === null || $this->currencyId === null) return $this->empty('revenue_cursor');
        return $this->withActs($this->page($this->placeQuery('revenue_streams'), 'revenue_cursor', ['id', 'name', 'kind', 'status', 'enacting_act_id'],
            fn ($r) => ['id' => (string) $r->id, 'name' => (string) $r->name, 'kind' => (string) $r->kind,
                'status' => (string) $r->status, 'enacting_act_id' => $r->enacting_act_id, 'levies' => []], $cursor));
    }

    private function withActs(array $page): array
    {
        $ids = array_values(array_unique(array_filter(array_column($page['records'], 'enacting_act_id'))));
        $acts = $ids === [] ? collect() : DB::table('laws')->whereIn('id', $ids)->get(['id', 'act_number', 'title'])->keyBy('id');
        $page['records'] = array_map(function ($row) use ($acts) {
            $act = $acts->get($row['enacting_act_id']);
            $row['enacting_act'] = $act === null ? null : ['act_number' => $act->act_number, 'title' => (string) $act->title];
            unset($row['enacting_act_id']);
            return $row;
        }, $page['records']);
        return $page;
    }

    public function selectedBudget(): ?array { return $this->selected('budget', 'budgets', ['id', 'fiscal_label']); }
    public function selectedRevenue(): ?array { return $this->selected('revenue_source', 'revenue_streams', ['id', 'name']); }
    private function selected(string $key, string $table, array $columns): ?array
    {
        if (array_key_exists($key, $this->resolved)) return $this->resolved[$key];
        $id = $this->selection[$key] ?? null;
        if ($id === null) return $this->resolved[$key] = null;
        $row = $this->placeQuery($table)->where('id', $id)->first($columns);
        abort_if($row === null, 404);
        return $this->resolved[$key] = (array) $row;
    }

    public function lines(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'lines_cursor');
        $budget = $this->selectedBudget();
        if ($budget === null) return $this->empty('lines_cursor');
        return $this->page(DB::table('budget_lines')->where('budget_id', $budget['id']), 'lines_cursor', ['id', 'line', 'amount'],
            fn ($l) => ['id' => (string) $l->id, 'line' => (string) $l->line, 'amount' => (string) $l->amount], $cursor);
    }

    public function levies(): array
    {
        $cursor = FinancialHistoryCursor::read($this->request, 'levies_cursor');
        $stream = $this->selectedRevenue();
        if ($stream === null) return $this->empty('levies_cursor');
        return $this->page(DB::table('levies')->where('revenue_stream_id', $stream['id']), 'levies_cursor', ['id', 'base', 'rate', 'civic_exempt'],
            fn ($l) => ['id' => (string) $l->id, 'base' => (string) $l->base, 'rate' => (string) $l->rate, 'civic_exempt' => (bool) $l->civic_exempt], $cursor);
    }

    private function empty(string $cursor): array
    {
        return ['records' => [], 'pagination' => ['previous' => null, 'next' => null], 'first' => $this->url([$cursor => null])];
    }

    private function page(Builder $query, string $name, array $columns, callable $map, $cursor, string $order = 'id', int $size = 20): array
    {
        $page = $query->orderByDesc($order)->cursorPaginate($size, $columns, $name, $cursor)->withPath('/economy/treasury');
        parse_str((string) parse_url($this->url([$name => null]), PHP_URL_QUERY), $params);
        $page->appends($params);
        return ['records' => array_map($map, $page->items()), 'pagination' => FinancialHistoryCursor::links($page), 'first' => $this->url([$name => null])];
    }

    private function iso(?string $value): ?string { return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String(); }
}
