<?php

namespace App\Http\Controllers\Economy;

use App\Http\Controllers\Controller;
use App\Models\Economy\Currency;
use App\Services\ConstitutionalValidator;
use App\Services\Economy\AccountService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\SettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase L+M read surfaces. The prop shapes are published FIRST, in
 * docs/plans/economy/ECONOMY_PROP_CONTRACT.md, and pinned by
 * EconomyPropContractTest — because parallel prop/page work is exactly the
 * geometry that produced the v3 mapper's "Proposing…" freeze (a panel read a
 * key the backend never shipped, the formatter threw inside render, the Vue
 * patch aborted, and it looked like a hang rather than a type error).
 *
 * Two rules that follow from that, and are load-bearing here:
 *   1. MONEY IS A STRING. numeric(24,6) through a float silently loses
 *      precision on a ledger, and a page that does arithmetic on it is wrong
 *      anyway — format, never compute.
 *   2. NOTHING IS ABSENT. Collections are [] when empty, money is "0.000000"
 *      when zero, and the only nullable fields are the ones the contract
 *      names. A missing key is what kills a render; an empty array is not.
 *
 * Read-only in v1. Writes arrive with F-IND-022/023/024.
 *
 * PRIVACY: every row here is account-scoped. No user_id crosses this
 * boundary — resolving an account to a person is economic_account_bindings'
 * job and no page needs it.
 */
class EconomyController extends Controller
{
    public function __construct(
        private LedgerService $ledger,
        private IssuanceService $issuance,
        private AccountService $accounts,
        private SettingsResolver $settings,
    ) {}

    public function home(): Response
    {
        $currency = $this->currency();
        $rootId   = $this->rootId();

        $lastRun = $currency === null ? null : DB::table('ubi_disbursements')
            ->where('currency_id', $currency->id)
            ->orderByDesc('ran_at')
            ->first();

        $supply = $currency === null ? '0.000000' : $this->issuance->supply($currency->id);
        $imbalance = $currency === null
            ? '0.000000'
            : ($this->ledger->imbalanceByCurrency()[$currency->id] ?? '0.000000');

        return Inertia::render('Economy/Home', [
            'currency' => $this->currencyProp($currency),
            'supply'   => $supply,
            'ledger'   => [
                'entries'  => DB::table('ledger_entries')->count(),
                'verified' => $this->ledger->verifyChain() === true,
                // A healthy ledger sits at exactly −supply: issuance is the
                // only lawful way for value to enter, everything else
                // conserves. The residual is what must read zero.
                'residual' => bcadd($imbalance, $supply, 6),
            ],
            'counts' => [
                'wallets'    => DB::table('economic_accounts')->whereNull('deleted_at')->count(),
                'listings'   => DB::table('marketplace_listings')->where('status', 'open')->whereNull('deleted_at')->count(),
                'postings'   => DB::table('work_postings')->where('status', 'open')->whereNull('deleted_at')->count(),
                'assistance' => DB::table('assistance_requests')->where('status', 'open')->whereNull('deleted_at')->count(),
                'assets'     => DB::table('assets')->whereNull('deleted_at')->count(),
            ],
            'stipend' => [
                'enabled'        => $rootId === null ? true : ($this->settings->resolve($rootId, 'stipend_enabled') ?? true) == true,
                'floor'          => $this->settingString($rootId, 'civic_stipend_floor', '50'),
                'cap'            => $this->settingString($rootId, 'stipend_bump_cap', '20'),
                'interval'       => (string) ($rootId === null ? 'monthly' : ($this->settings->resolve($rootId, 'stipend_interval') ?? 'monthly')),
                'funding_source' => (string) ($rootId === null ? 'minted' : ($this->settings->resolve($rootId, 'stipend_funding_source') ?? 'minted')),
                'last_run'       => $lastRun === null ? null : [
                    'ran_at'     => $this->iso($lastRun->ran_at),
                    'recipients' => (int) $lastRun->recipients,
                    'total'      => (string) $lastRun->total,
                    'short_paid' => (bool) $lastRun->short_paid,
                ],
            ],
        ]);
    }

    public function wallet(Request $request): Response
    {
        $currency = $this->currency();
        $accountId = null;

        if ($currency !== null && $request->user() !== null) {
            $accountId = $this->accounts->accountIdFor('users', (string) $request->user()->id, $currency->id);
        }

        $account = $accountId === null ? null : DB::table('economic_accounts')->where('id', $accountId)->first();

        $transactions = [];
        $receipts = [];

        if ($accountId !== null) {
            $rows = DB::table('market_transactions')
                ->where(fn ($q) => $q->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            foreach ($rows as $row) {
                $out = $row->from_account_id === $accountId;

                $transactions[] = [
                    'id'                      => (string) $row->id,
                    'direction'               => $out ? 'out' : 'in',
                    'amount'                  => (string) $row->amount,
                    'kind'                    => (string) $row->kind,
                    'memo'                    => $row->memo,
                    'at'                      => $this->iso($row->created_at),
                    'counterparty_account_id' => $out ? $row->to_account_id : $row->from_account_id,
                ];
            }

            $receipts = DB::table('ubi_receipts')
                ->where('account_id', $accountId)
                ->orderByDesc('created_at')
                ->limit(12)
                ->get()
                ->map(fn ($r) => [
                    'id'     => (string) $r->id,
                    'base'   => (string) $r->base,
                    'bump'   => (string) $r->bump,
                    'amount' => (string) $r->amount,
                    'at'     => $this->iso($r->created_at),
                ])->all();
        }

        return Inertia::render('Economy/Wallet', [
            'currency'     => $this->currencyProp($currency),
            'account'      => $account === null ? null : [
                'id'      => (string) $account->id,
                'balance' => (string) $account->balance,
                'status'  => (string) $account->status,
            ],
            'transactions' => $transactions,
            'receipts'     => $receipts,
        ]);
    }

    public function market(): Response
    {
        return Inertia::render('Economy/Market', [
            'currency'   => $this->currencyProp($this->currency()),
            'offers'     => $this->offers(),
            'work'       => DB::table('work_postings')
                ->where('status', 'open')->whereNull('deleted_at')
                ->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($p) => [
                    'id'              => (string) $p->id,
                    'title'           => (string) $p->title,
                    'terms'           => (string) $p->terms,
                    'rate'            => $p->rate === null ? null : (string) $p->rate,
                    'status'          => (string) $p->status,
                    'organization_id' => (string) $p->organization_id,
                    'applications'    => DB::table('work_applications')->where('posting_id', $p->id)->count(),
                ])->all(),
            // PRIVACY: 'private' assistance requests never leave the node's
            // owner. Mutual aid is Art. I association, private by default.
            'assistance' => DB::table('assistance_requests')
                ->where('status', 'open')->where('privacy', '!=', 'private')->whereNull('deleted_at')
                ->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($a) => [
                    'id'      => (string) $a->id,
                    'title'   => (string) $a->title,
                    'need'    => (string) $a->need,
                    'privacy' => (string) $a->privacy,
                    'status'  => (string) $a->status,
                ])->all(),
        ]);
    }

    public function listing(Request $request, string $listing): Response
    {
        $offers = $this->offers($listing);
        abort_if($offers === [], 404);

        $row = $offers[0];

        $myAccountId = null;
        $currency = $this->currency();
        if ($currency !== null && $request->user() !== null) {
            $myAccountId = $this->accounts->accountIdFor('users', (string) $request->user()->id, $currency->id);
        }

        return Inertia::render('Economy/Listing', [
            'currency'  => $this->currencyProp($currency),
            'listing'   => $row,
            'orders'    => DB::table('marketplace_orders')->where('listing_id', $listing)->count(),
            'can_order' => $row['status'] === 'open'
                && $myAccountId !== null
                && $myAccountId !== $row['seller_account_id'],
        ]);
    }

    public function treasury(): Response
    {
        $currency = $this->currency();

        return Inertia::render('Economy/Treasury', [
            'currency' => $this->currencyProp($currency),
            'accounts' => DB::table('treasury_accounts')->whereNull('deleted_at')->orderBy('label')->get()
                ->map(fn ($a) => [
                    'id'         => (string) $a->id,
                    'owner_type' => (string) $a->owner_type,
                    'owner_id'   => (string) $a->owner_id,
                    'label'      => $a->label,
                    'balance'    => (string) $a->balance,
                    'public'     => (bool) $a->public,
                ])->all(),
            'ledger' => DB::table('ledger_entries')->orderByDesc('seq')->limit(50)->get()
                ->map(fn ($e) => [
                    'seq'          => (int) $e->seq,
                    'at'           => $this->iso($e->created_at),
                    'direction'    => (string) $e->direction,
                    'amount'       => (string) $e->amount,
                    'kind'         => (string) $e->kind,
                    'account_type' => (string) $e->account_type,
                    'account_id'   => (string) $e->account_id,
                    'hash'         => (string) $e->hash,
                ])->all(),
            'issuance' => DB::table('issuance_events')->orderByDesc('created_at')->limit(20)->get()
                ->map(fn ($i) => [
                    'id'        => (string) $i->id,
                    'direction' => (string) $i->direction,
                    'amount'    => (string) $i->amount,
                    'reason'    => (string) $i->reason,
                    'at'        => $this->iso($i->created_at),
                ])->all(),
            'budgets' => DB::table('budgets')->whereNull('deleted_at')->orderByDesc('created_at')->limit(20)->get()
                ->map(fn ($b) => [
                    'id'           => (string) $b->id,
                    'fiscal_label' => (string) $b->fiscal_label,
                    'total'        => (string) $b->total,
                    'status'       => (string) $b->status,
                    'enacted_at'   => $this->iso($b->enacted_at),
                    'lines'        => DB::table('budget_lines')->where('budget_id', $b->id)->count(),
                ])->all(),
            'revenue' => DB::table('revenue_streams')->whereNull('deleted_at')->orderBy('name')->get()
                ->map(fn ($r) => [
                    'id'     => (string) $r->id,
                    'name'   => (string) $r->name,
                    'kind'   => (string) $r->kind,
                    'status' => (string) $r->status,
                ])->all(),
            'totals' => [
                'supply'           => $currency === null ? '0.000000' : $this->issuance->supply($currency->id),
                'treasury_balance' => (string) (DB::table('treasury_accounts')->whereNull('deleted_at')->sum('balance') ?: '0.000000'),
            ],
        ]);
    }

    public function units(): Response
    {
        $currency = $this->currency();
        $rootId   = $this->rootId();
        $bounds   = ConstitutionalValidator::SETTING_BOUNDS;

        $levers = [];
        foreach (ConstitutionalValidator::MONETARY_KEYS as $key) {
            $levers[] = [
                'key'       => $key,
                'label'     => ucfirst(str_replace('_', ' ', $key)),
                'value'     => $rootId === null ? null : $this->settings->resolve($rootId, $key),
                // Every monetary key is dual-door: the recipients of a stipend
                // overlap the legislators who set it, so the constituents whose
                // money it is must consent too.
                'dual_door' => in_array($key, ConstitutionalValidator::DUAL_DOOR_KEYS, true),
                'citation'  => (string) ($bounds[$key]['citation'] ?? ''),
                'bounds'    => isset($bounds[$key])
                    ? array_intersect_key($bounds[$key], array_flip(['min', 'max', 'allowed']))
                    : null,
            ];
        }

        return Inertia::render('Economy/Units', [
            'currency'             => $this->currencyProp($currency),
            'levers'               => $levers,
            'supply'               => $currency === null ? '0.000000' : $this->issuance->supply($currency->id),
            'issuance_rate_bps'    => $rootId === null ? null : $this->nullableInt($this->settings->resolve($rootId, 'issuance_rate_bps')),
            'inflation_target_bps' => $rootId === null ? null : $this->nullableInt($this->settings->resolve($rootId, 'inflation_target_bps')),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function offers(?string $onlyId = null): array
    {
        $query = DB::table('marketplace_listings as l')
            ->leftJoin('assets as a', 'a.id', '=', 'l.asset_id')
            ->whereNull('l.deleted_at')
            ->select([
                'l.id', 'l.kind', 'l.title', 'l.description', 'l.price', 'l.quantity',
                'l.status', 'l.seller_account_id',
                'a.id as asset_id', 'a.kind as asset_kind', 'a.name as asset_name', 'a.attributes as asset_attributes',
            ]);

        if ($onlyId !== null) {
            $query->where('l.id', $onlyId);
        } else {
            $query->where('l.status', 'open')->orderByDesc('l.created_at')->limit(100);
        }

        return $query->get()->map(fn ($l) => [
            'id'                => (string) $l->id,
            'kind'              => (string) $l->kind,
            'title'             => (string) $l->title,
            'description'       => $l->description,
            'price'             => (string) $l->price,
            'quantity'          => (string) $l->quantity,
            'status'            => (string) $l->status,
            'seller_account_id' => (string) $l->seller_account_id,
            'asset'             => $l->asset_id === null ? null : [
                'id'         => (string) $l->asset_id,
                'kind'       => (string) $l->asset_kind,
                'name'       => (string) $l->asset_name,
                'attributes' => $l->asset_attributes === null ? null : json_decode((string) $l->asset_attributes, true),
            ],
        ])->all();
    }

    private function currency(): ?Currency
    {
        $rootId = $this->rootId();

        return $rootId === null
            ? null
            : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();
    }

    /** @return array<string, mixed>|null */
    private function currencyProp(?Currency $currency): ?array
    {
        return $currency === null ? null : [
            'id'        => (string) $currency->id,
            'name'      => (string) $currency->name,
            'code'      => (string) $currency->code,
            'symbol'    => (string) $currency->symbol,
            'precision' => (int) $currency->precision,
        ];
    }

    private function rootId(): ?string
    {
        $id = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    private function settingString(?string $rootId, string $key, string $default): string
    {
        if ($rootId === null) {
            return $default;
        }

        $value = $this->settings->resolve($rootId, $key);

        return $value === null ? $default : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format(\DateTimeInterface::ATOM)
            : \Illuminate\Support\Carbon::parse((string) $value)->toIso8601String();
    }
}
