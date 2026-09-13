<?php

namespace App\Http\Controllers\Economy;

use App\Http\Controllers\Controller;
use App\Models\Economy\Currency;
use App\Models\Organization;
use App\Services\ConstitutionalValidator;
use App\Services\Economy\AccountService;
use App\Services\Economy\CurrencyTelemetryService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\Economy\StipendService;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private CurrencyTelemetryService $telemetry,
    ) {}

    public function home(Request $request): Response
    {
        $currency = $this->currency();
        $accountId = $currency === null || $request->user() === null
            ? null
            : $this->accounts->accountIdFor('users', (string) $request->user()->id, $currency->id);
        // Read the existing wallet only. Opening a hub must not mint an account
        // or walk the world ledger. Its balance is already stored on this row.
        $account = $accountId === null ? null : DB::table('economic_accounts')
            ->where('id', $accountId)->whereNull('deleted_at')->first(['id', 'balance', 'status']);

        return Inertia::render('Economy/Home', [
            'surface'  => SurfaceMeta::for('economy/home'),
            'currency' => $this->currencyProp($currency),
            'account'  => $account === null ? null : [
                'id' => (string) $account->id,
                'balance' => (string) $account->balance,
                'status' => (string) $account->status,
            ],
            // Preserve rollout keys, but do not label an unchecked ledger
            // healthy or present unavailable world totals as zero.
            'supply' => null,
            'ledger' => ['entries' => null, 'verified' => null, 'residual' => null, 'status' => 'not_checked'],
            'counts' => [
                'wallets' => null, 'listings' => null, 'postings' => null, 'assistance' => null, 'assets' => null,
            ],
            'stipend' => [
                'enabled' => null, 'floor' => null, 'cap' => null, 'interval' => null,
                'funding_source' => null, 'last_run' => null, 'status' => 'not_loaded',
            ],
            'clock' => ['interval' => null, 'period_days' => null, 'last_run' => null, 'next_run' => null],
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

        $assetDirectory = null;
        $assetPage = function () use (&$assetDirectory, $request, $accountId) {
            return $assetDirectory ??= (new \App\Support\OwnedAssetDirectory)->page($request, $accountId);
        };

        $transactions = function () use ($accountId) {
            if ($accountId === null) return [];
            $transactions = [];
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

            return $transactions;
        };
        $receipts = function () use ($accountId) {
            if ($accountId === null) return [];
            return DB::table('ubi_receipts')
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

        };

        return Inertia::render('Economy/Wallet', [
            'surface'      => SurfaceMeta::for('economy/wallet'),
            'currency'     => $this->currencyProp($currency),
            'account'      => $account === null ? null : [
                'id'      => (string) $account->id,
                'balance' => (string) $account->balance,
                'status'  => (string) $account->status,
            ],
            'transactions' => $transactions,
            'receipts'     => $receipts,
            'assets'       => fn () => $assetPage()['assets'],
            'asset_directory' => $assetPage,
        ]);
    }

    public function market(Request $request): Response
    {
        $directory = null;
        $directoryPage = function () use (&$directory, $request) {
            return $directory ??= (new \App\Support\MarketDirectory)->page($request);
        };
        $currency = $this->currency();
        $accountId = $currency === null || $request->user() === null ? null
            : $this->accounts->accountIdFor('users', (string) $request->user()->id, $currency->id);
        $assets = null;
        $assetPage = function () use (&$assets, $request, $accountId) {
            return $assets ??= (($request->query('tab') ?? 'offers') === 'offers'
                ? (new \App\Support\OwnedAssetDirectory)->page($request, $accountId, true)
                : \App\Support\OwnedAssetDirectory::empty());
        };

        return Inertia::render('Economy/Market', [
            'surface'    => SurfaceMeta::for('economy/marketplace'),
            'currency'   => $this->currencyProp($currency),
            'tab'        => fn () => $directoryPage()['tab'],
            'pagination' => fn () => $directoryPage()['pagination'],
            'offers'     => function () use ($directoryPage) {
                $directory = $directoryPage();
                $offers = $directory['tab'] === 'offers' ? $this->offers(pageIds: $directory['offer_ids']) : [];
                $sellers = $this->orgSellers(array_column($offers, 'seller_account_id'));
                return array_map(fn ($offer) => $offer + ['seller_org' => $sellers[$offer['seller_account_id']] ?? null], $offers);
            },
            // Things you hold that are not already on the market, so the
            // "offer something" form can only point at what you actually have.
            'my_assets'  => fn () => $assetPage()['assets'],
            'asset_directory' => $assetPage,
            'work'       => fn () => $directoryPage()['work'],
            'assistance' => fn () => $directoryPage()['assistance'],
        ]);
    }

    public function listing(Request $request, string $listing): Response
    {
        $pendingDirectory = new \App\Support\PendingOrderDirectory();
        $pendingDirectory->cursor($request);
        $offers = $this->offers($listing);
        abort_if($offers === [], 404);

        $row = $offers[0];

        $myAccountId = null;
        $currency = $this->currency();
        if ($currency !== null && $request->user() !== null) {
            $myAccountId = $this->accounts->accountIdFor('users', (string) $request->user()->id, $currency->id);
        }

        $isSeller = $myAccountId !== null && $myAccountId === $row['seller_account_id'];

        // Orders awaiting acceptance. SELLER ONLY, and even then the buyer
        // appears as an ACCOUNT — the seller needs to know which order to
        // settle, which is not the same as being told who bought it.
        $pending = $pendingDirectory->page($request, $listing, $myAccountId);

        return Inertia::render('Economy/Listing', [
            'surface'   => SurfaceMeta::for('economy/listing-detail'),
            'currency'  => $this->currencyProp($currency),
            'listing'   => $row + [
                'seller_org' => $this->orgSellers([$row['seller_account_id']])[$row['seller_account_id']] ?? null,
            ],
            'orders'    => DB::table('marketplace_orders')->where('listing_id', $listing)->count(),
            'can_order' => $row['status'] === 'open'
                && $myAccountId !== null
                && $myAccountId !== $row['seller_account_id'],
            'is_seller'       => $isSeller,
            'pending_orders'  => $pending['orders'],
            'pending_order_pages' => $pending['pagination'],
        ]);
    }

    public function treasury(): Response
    {
        $currency = $this->currency();

        return Inertia::render('Economy/Treasury', [
            'surface'  => SurfaceMeta::for('economy/treasury'),
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
                    // The cycle state, in the open: a budget is enacted or it
                    // is not, and only an enacted one is directing money now.
                    'is_current'   => $b->status === 'enacted',
                    'enacted_at'   => $this->iso($b->enacted_at),
                    'enacting_act' => $this->actLabel($b->enacting_act_id === null ? null : (string) $b->enacting_act_id),
                    'lines'        => DB::table('budget_lines')->where('budget_id', $b->id)->count(),
                    // The lines themselves — where public money is DIRECTED,
                    // which is the half a count cannot show.
                    'line_items'   => DB::table('budget_lines')->where('budget_id', $b->id)->orderBy('line')->limit(50)->get()
                        ->map(fn ($l) => [
                            'line'   => (string) $l->line,
                            'amount' => (string) $l->amount,
                        ])->all(),
                ])->all(),
            // Art. V §4 — borrowing is a JURISDICTION instrument (there is no
            // personal credit anywhere in this economy). Lenders appear as
            // accounts, never people.
            'borrowings' => DB::table('borrowings')->orderByDesc('created_at')->limit(20)->get()
                ->map(fn ($b) => [
                    'id'                => (string) $b->id,
                    'principal'         => (string) $b->principal,
                    'terms'             => (string) $b->terms,
                    'status'            => (string) $b->status,
                    'lender_account_id' => $b->lender_account_id === null ? null : (string) $b->lender_account_id,
                    'at'                => $this->iso($b->created_at),
                ])->all(),
            'revenue' => DB::table('revenue_streams')->whereNull('deleted_at')->orderBy('name')->get()
                ->map(fn ($r) => [
                    'id'     => (string) $r->id,
                    'name'   => (string) $r->name,
                    'kind'   => (string) $r->kind,
                    'status' => (string) $r->status,
                    // Art. V §4 — how public money is RAISED is public: each
                    // levy's base and rate, and whether civic use is exempt.
                    // A rate is a ratio, not money, but it crosses as a string
                    // for the same reason money does — never a lossy float.
                    'levies' => DB::table('levies')->where('revenue_stream_id', $r->id)->orderBy('created_at')->get()
                        ->map(fn ($l) => [
                            'base'         => (string) $l->base,
                            'rate'         => (string) $l->rate,
                            'civic_exempt' => (bool) $l->civic_exempt,
                        ])->all(),
                    'enacting_act' => $this->actLabel($r->enacting_act_id === null ? null : (string) $r->enacting_act_id),
                ])->all(),
            // The economic clock — when the next stipend disbursement is due.
            // Derived, shared with the overview and the units page.
            'clock'  => $this->economicClock(),
            'totals' => [
                'supply'           => $currency === null ? '0.000000' : $this->issuance->supply($currency->id),
                'treasury_balance' => (string) (DB::table('treasury_accounts')->whereNull('deleted_at')->sum('balance') ?: '0.000000'),
            ],
        ]);
    }

    public function units(): Response
    {
        $currency = $this->currency();
        $report = $currency === null ? null : app(\App\Services\Economy\CurrencyReportService::class)->read($currency->id);
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
                // Which act last moved this lever — the per-lever provenance
                // the mockup asks for. Honestly null while a lever sits at its
                // constitutional default (no setting_changes row yet).
                'enacting_act' => $this->latestSettingAct($rootId, $key),
            ];
        }

        return Inertia::render('Economy/Units', [
            'surface'              => SurfaceMeta::for('economy/units'),
            'currency'             => $this->currencyProp($currency),
            'levers'               => $levers,
            'supply'               => $report['data']['supply'] ?? null,
            'issuance_rate_bps'    => $rootId === null ? null : $this->nullableInt($this->settings->resolve($rootId, 'issuance_rate_bps')),
            'inflation_target_bps' => $rootId === null ? null : $this->nullableInt($this->settings->resolve($rootId, 'inflation_target_bps')),
            // Who issues the unit — the standards authority behind it (Art. V §5:
            // currency is root-reserved). The root jurisdiction, by name.
            'issuer'               => $this->currencyIssuer($currency),
            // The economic clock — when the next disbursement is due. Same
            // derivation the overview and treasury share.
            'clock'                => $this->economicClock(),
            // Account-clean distribution telemetry (Design Round 2 ④): read
            // only, aggregated over accounts and never people. The levers above
            // still move only by dual-door act — nothing here adjusts a rate.
            'telemetry'            => $report['data'] ?? null,
            'report'               => $report,
        ]);
    }

    public function refreshReport(Request $request, \App\Services\Economy\CurrencyReportService $reports): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $currency = $this->currency();
        abort_unless($currency, 404);
        $runId = $reports->start($currency->id);
        $reports->dispatch($currency->id, $runId);

        return redirect()->route('economy.units');
    }

    /**
     * The unit's issuing authority — the root jurisdiction, by name. Currency
     * is root-reserved (Art. V §5); the issuer is a public fact, not private.
     */
    private function currencyIssuer(?Currency $currency): ?string
    {
        if ($currency === null) {
            return null;
        }

        return (string) (DB::table('jurisdictions')->where('id', $currency->jurisdiction_id)->value('name') ?? 'The root legislature');
    }

    /**
     * The exchange — the fungible + non-fungible INSTRUMENTS venue (Design
     * Round 2 ①; operator ruling 2026-07-29). It is NOT the mockup's
     * continuous order book: that matching engine is deliberately not built.
     * Instruments trade at a fixed price through the SAME rail as the open
     * market (F-IND-022, one order at a time) — the venue is a lens on
     * asset-backed offers, not a second settlement path.
     *
     * Fungible = a divisible stack (more than one of the same registered
     * thing); unique = a one-of-a-kind holding. SHARES (equity) join this
     * floor with F-ORG-008 issuance (piece 4); until an org can issue there is
     * nothing to show, so the shares floor is honestly empty, not faked.
     *
     * Reader privacy: a seller that is an ORGANISATION resolves to its public
     * name (its offer is its public act); a human seller stays an account.
     */
    public function exchange(Request $request): Response
    {
        $currency = $this->currency();
        $myId = (string) ($request->user()?->id ?? '');
        $myId = $myId === '' ? null : $myId;

        $offers = $this->openShareOffers($request, $myId);

        return Inertia::render('Economy/Exchange', [
            'surface'     => SurfaceMeta::for('economy/exchange'),
            'currency'    => $this->currencyProp($currency),
            // Goods/assets have one home in Market. Ownership registers live
            // with each organization. Keep rollout keys truthful without
            // calculating world telemetry or ownership totals on a GET.
            'instruments' => [],
            'shares'      => [],
            'kpis'        => null,
            'telemetry_status' => 'not_loaded',
            'tape'        => [],
            'offers'      => $offers['rows'],
            'pagination'  => $offers['pagination'],
            'my_holdings' => $this->viewerHoldings($myId),
            'my_id'       => $myId,
            // The continuous order book is deliberately not built; trades
            // settle at a fixed price through F-IND-022. Stated, not simulated.
            'order_book'  => false,
        ]);
    }

    /**
     * Open share sell-offers — the populated equity floor (Wave 4 ②). A share
     * offer is a public act on the named ownership plane (the seller's stake is
     * already on the public cap table), so the seller resolves to a name. The
     * money leg never appears here.
     *
     * @return list<array<string, mixed>>
     */
    private function openShareOffers(Request $request, ?string $myId): array
    {
        // Select a small offer page before resolving organizations or people.
        $page = DB::table('share_offers')->where('status', 'open')->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->from('organizations')->whereColumn('organizations.id', 'share_offers.organization_id')->whereNull('organizations.deleted_at'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(20, ['*'], 'cursor', $request->query('cursor'))
            ->withPath('/economy/exchange');
        $organizations = DB::table('organizations')->whereIn('id', $page->getCollection()->pluck('organization_id'))
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'type'])->keyBy('id');
        $rows = $page->getCollection()
            ->filter(fn ($r) => $organizations->has($r->organization_id))
            ->map(fn ($r) => [
                'id'             => (string) $r->id,
                'org_id'         => (string) $r->organization_id,
                'org_name'       => (string) $organizations[$r->organization_id]->name,
                'is_cgc'         => $organizations[$r->organization_id]->type === 'common_good_corp',
                'units'          => (string) $r->units,
                'price_per_unit' => (string) $r->price_per_unit,
                'seller'         => $this->holderName((string) $r->seller_holder_type, (string) $r->seller_holder_id),
                'is_mine'        => $myId !== null
                    && $r->seller_holder_type === 'users'
                    && (string) $r->seller_holder_id === $myId,
            ])->values()->all();

        return ['rows' => $rows, 'pagination' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()]];
    }

    /**
     * The viewer's own equity holdings, per stock org — what they could offer
     * for sale. Empty for a guest or a non-holder (a normal state).
     *
     * @return list<array<string, mixed>>
     */
    private function viewerHoldings(?string $myId): array
    {
        if ($myId === null) {
            return [];
        }

        return DB::table('org_ownership_stakes as s')
            ->join('organizations as o', 'o.id', '=', 's.organization_id')
            ->where('s.holder_type', 'users')->where('s.holder_id', $myId)
            ->whereNull('s.ended_at')->whereNull('o.deleted_at')
            ->where('o.structure', Organization::STRUCTURE_STOCK)
            ->groupBy('o.id', 'o.name')
            ->orderByRaw('sum(s.units) desc')
            ->get(['o.id', 'o.name', DB::raw('sum(s.units) as units')])
            ->map(fn ($r) => [
                'org_id'   => (string) $r->id,
                'org_name' => (string) $r->name,
                'units'    => (string) $r->units,
            ])->all();
    }

    /**
     * Resolve a NAMED holder (the ownership plane, Ruling B) — distinct from the
     * pseudonymous money plane. Equity ownership is a public fact.
     */
    private function holderName(string $type, string $id): string
    {
        if ($type === 'organizations') {
            return (string) (DB::table('organizations')->where('id', $id)->value('name') ?? 'An organization');
        }
        if ($type === 'jurisdictions') {
            return (string) (DB::table('jurisdictions')->where('id', $id)->value('name') ?? 'A jurisdiction');
        }

        // users — the chosen PUBLIC name (display_name), never the legal name,
        // and only because this is the named ownership plane (Ruling B).
        $u = DB::table('users')->where('id', $id)->first(['display_name', 'name']);

        return (string) ($u->display_name ?? $u->name ?? 'A holder');
    }

    /**
     * Resident agreements — the person-to-person / N-party consent plane
     * (Design Round 2 ③, F-IND-020). PARTY-SCOPED: a resident sees only the
     * agreements they are a party to. Parties are shown BY NAME (a signature
     * is a name — the consent plane, distinct from the pseudonymous money
     * plane). Each agreement carries its signer roster, the clause overlay,
     * and any pending redlines to resolve.
     */
    public function residentAgreements(Request $request): Response|RedirectResponse
    {
        $selected = $request->query('agreement');
        $compose = $selected === null && $request->boolean('new');
        if ($selected === null && ! $compose) {
            return redirect('/economy/agreements');
        }
        abort_if($selected !== null && (! is_string($selected) || ! Str::isUuid($selected)), 404);
        $me = $request->user();
        $surface = SurfaceMeta::for('economy/resident-agreements');

        if ($me === null) {
            abort_if($selected !== null, 404);
            return Inertia::render('Economy/ResidentAgreements', [
                'surface' => $surface, 'agreements' => [], 'candidates' => [], 'my_id' => null, 'compose' => true,
            ]);
        }

        $myId = (string) $me->id;

        // A detail request resolves one authorized agreement before its terms,
        // signers or negotiation records. The composer loads no agreements.
        $rows = $compose ? collect() : $this->residentAgreementQuery($myId)->where('id', $selected)->limit(1)->get();
        abort_if(! $compose && $rows->isEmpty(), 404);
        $agreements = $rows
            ->map(function ($a) use ($myId) {
                $signers = DB::table('resident_agreement_signers as s')
                    ->join('users as u', 'u.id', '=', 's.signer_user_id')
                    ->where('s.agreement_id', $a->id)
                    ->get(['u.name', 's.signer_user_id', 's.signed_at'])
                    ->map(fn ($s) => [
                        'name'   => (string) $s->name,
                        'signed' => $s->signed_at !== null,
                        'is_me'  => (string) $s->signer_user_id === $myId,
                    ])->all();

                $iSigned = collect($signers)->firstWhere('is_me')['signed'] ?? false;

                return [
                    'id'            => (string) $a->id,
                    'title'         => (string) $a->title,
                    'terms'         => (string) $a->terms,
                    'status'        => (string) $a->status,
                    'is_initiator'  => (string) $a->initiator_user_id === $myId,
                    'can_sign'      => ! $iSigned && $a->status !== 'active' && $a->status !== 'ended' && $a->status !== 'voided',
                    'signers'       => $signers,
                    'clauses'       => DB::table('clauses')
                        ->where('subject_type', 'resident')->where('subject_id', $a->id)->whereNull('deleted_at')
                        ->orderBy('ordinal')->get(['id', 'heading', 'body'])
                        ->map(fn ($c) => ['id' => (string) $c->id, 'heading' => $c->heading, 'body' => (string) $c->body])->all(),
                    'redlines'      => DB::table('redlines')
                        ->where('subject_type', 'resident')->where('subject_id', $a->id)->where('status', 'pending')
                        ->orderBy('created_at')->get(['id', 'kind', 'body', 'rationale', 'proposer_user_id'])
                        ->map(fn ($r) => [
                            'id'        => (string) $r->id,
                            'kind'      => (string) $r->kind,
                            'body'      => (string) $r->body,
                            'rationale' => $r->rationale,
                            'is_mine'   => (string) $r->proposer_user_id === $myId,
                        ])->all(),
                ];
            })->all();

        $partyDirectory = $compose
            ? (new \App\Support\AgreementPartyDirectory)->page($request, $myId)
            : \App\Support\AgreementPartyDirectory::empty();

        return Inertia::render('Economy/ResidentAgreements', [
            'surface'    => $surface,
            'agreements' => $agreements,
            'candidates' => $partyDirectory['candidates'],
            'party_directory' => $partyDirectory,
            'my_id'      => $myId,
            'compose'    => $compose,
        ]);
    }

    /**
     * One work posting, end to end — the rate, the organisation, the
     * lifecycle it triggers when accepted, and the co-determination
     * thresholds it counts toward. Thresholds are RESOLVED from the
     * amendable settings, never the 100/2000 literals (Art. III §6 — the
     * values legislate; the math is hardened elsewhere).
     */
    public function workPosting(Request $request, string $posting): Response
    {
        $row = DB::table('work_postings')->where('id', $posting)->whereNull('deleted_at')->first();

        abort_if($row === null, 404);

        $org = DB::table('organizations')->where('id', $row->organization_id)->first();

        // The org's jurisdiction scopes the thresholds; root is the fallback
        // for an org with no jurisdiction on record.
        $scopeId = ($org->jurisdiction_id ?? null) !== null ? (string) $org->jurisdiction_id : $this->rootId();

        $myAccountId = null;
        $currency = $this->currency();
        if ($currency !== null && $request->user() !== null) {
            $myAccountId = $this->accounts->accountIdFor('users', (string) $request->user()->id, $currency->id);
        }

        $hasApplied = $myAccountId !== null && DB::table('work_applications')
            ->where('posting_id', $posting)
            ->where('applicant_account_id', $myAccountId)
            ->exists();

        return Inertia::render('Economy/RequestDetail', [
            'surface'  => SurfaceMeta::for('economy/request-detail'),
            'currency' => $this->currencyProp($currency),
            'posting'  => [
                'id'           => (string) $row->id,
                'title'        => (string) $row->title,
                'terms'        => (string) $row->terms,
                'rate'         => $row->rate === null ? null : (string) $row->rate,
                'status'       => (string) $row->status,
                'org_name'     => $org === null ? 'An organization' : (string) $org->name,
                'org_id'       => $org === null ? null : (string) $org->id,
                'org_href'     => $org === null ? null : '/organizations/' . rawurlencode((string) $org->id) . ($org->type === 'common_good_corp' ? '/cgc' : ''),
                'applications' => DB::table('work_applications')->where('posting_id', $row->id)->count(),
                'at'           => $this->iso($row->created_at),
            ],
            'codetermination' => [
                // Amendable per jurisdiction (CLK-13/14 defaults) — resolved,
                // never hardcoded.
                'first_seat_at' => $scopeId === null ? 100 : (int) ($this->settings->resolve($scopeId, 'worker_rep_min_employees') ?? 100),
                'parity_at'     => $scopeId === null ? 2000 : (int) ($this->settings->resolve($scopeId, 'worker_rep_parity_employees') ?? 2000),
                // Only the COUNT crosses this boundary — never worker rows.
                'headcount'     => DB::table('org_workers')
                    ->where('employer_type', 'organizations')
                    ->where('employer_id', $row->organization_id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->count(),
            ],
            'can_apply'   => $row->status === 'open' && $myAccountId !== null && ! $hasApplied,
            'has_applied' => $hasApplied,
        ]);
    }

    /**
     * The civic stipend, as a page: the live formula values, when it runs,
     * the public aggregate of the last run, and the k-anonymity rule that
     * keeps a small class from identifying its one member. Worked examples
     * are computed by the REAL formula (StipendService::bumpFor) on
     * synthetic role sets — no real person's receipt is ever derivable from
     * this page.
     */
    public function stipend(StipendService $stipends): Response
    {
        $currency = $this->currency();
        $rootId   = $this->rootId();

        $floor = $this->settingString($rootId, 'civic_stipend_floor', '50');
        $cap   = $this->settingString($rootId, 'stipend_bump_cap', '20');
        $bumps = [
            'node_operator'    => $this->settingString($rootId, 'pay_node_operator', '8'),
            'social_moderator' => $this->settingString($rootId, 'pay_social_moderator', '5'),
            'office_holder'    => $this->settingString($rootId, 'pay_office_holder', '12'),
        ];

        $lastRun = $currency === null ? null : DB::table('ubi_disbursements')
            ->where('currency_id', $currency->id)
            ->orderByDesc('ran_at')
            ->first();

        $periodDays = $rootId === null ? null : $this->nullableInt($this->settings->resolve($rootId, 'stipend_period_days'));

        $examples = [];
        foreach ([
            ['label' => 'A resident with no serving roles', 'roles' => []],
            ['label' => 'A node operator',                  'roles' => ['node_operator']],
            ['label' => 'A moderator who also holds office', 'roles' => ['social_moderator', 'office_holder']],
            ['label' => 'All three duties at once',          'roles' => ['node_operator', 'social_moderator', 'office_holder']],
        ] as $case) {
            $bump = $stipends->bumpFor($case['roles'], $bumps, $cap);

            $examples[] = [
                'label'  => $case['label'],
                'roles'  => $case['roles'],
                'base'   => $floor,
                'bump'   => $bump,
                'amount' => bcadd($floor, $bump, 6),
                'capped' => bccomp($bump, $cap, 6) === 0 && $case['roles'] !== [],
            ];
        }

        return Inertia::render('Economy/Stipend', [
            'surface'  => SurfaceMeta::for('economy/stipend'),
            'currency' => $this->currencyProp($currency),
            'stipend'  => [
                'enabled'        => $rootId === null ? true : ($this->settings->resolve($rootId, 'stipend_enabled') ?? true) == true,
                'floor'          => $floor,
                'cap'            => $cap,
                'bumps'          => $bumps,
                'interval'       => (string) ($rootId === null ? 'monthly' : ($this->settings->resolve($rootId, 'stipend_interval') ?? 'monthly')),
                'period_days'    => $periodDays,
                'funding_source' => (string) ($rootId === null ? 'minted' : ($this->settings->resolve($rootId, 'stipend_funding_source') ?? 'minted')),
            ],
            'clock' => [
                'last_run' => $lastRun === null ? null : [
                    'ran_at'     => $this->iso($lastRun->ran_at),
                    'recipients' => (int) $lastRun->recipients,
                    'total'      => (string) $lastRun->total,
                    'short_paid' => (bool) $lastRun->short_paid,
                ],
                'next_run_estimate' => ($lastRun === null || $periodDays === null)
                    ? null
                    : \Illuminate\Support\Carbon::parse((string) $lastRun->ran_at)->addDays($periodDays)->toIso8601String(),
            ],
            'k_anon_floor' => StipendService::K_ANON_FLOOR,
            'examples'     => $examples,
        ]);
    }

    /**
     * The agreements register — the viewer's OWN instruments (org_contracts).
     *
     * PRIVACY MODEL (different plane than money): a contract is CONSENT
     * between parties, so parties may see each other by name — that is the
     * point of a signature. What stays private is the instrument itself:
     * the register lists only agreements the viewer is party to (their own
     * counterparty side, a contract they signed for an organization, or an
     * organization they hold ACTIVE membership in). Terms never ship to a
     * non-party. No raw user ids cross the boundary — names only.
     *
     * The both-sign floor is DB-enforced (org_contracts_cosign_check:
     * status='active' requires both signatures) — the page RENDERS the
     * floor; the database is what holds it.
     */
    public function agreements(Request $request): Response
    {
        $all = [];
        $pagination = ['org' => ['previous' => null, 'next' => null], 'resident' => ['previous' => null, 'next' => null]];
        if ($request->user() !== null) {
            // Both consent families have one directory, with independent
            // cursors so no history is lost and neither requires a count.
            $orgPage = $this->visibleContractQuery((string) $request->user()->id)
                ->orderByDesc('c.created_at')->orderByDesc('c.id')
                ->cursorPaginate(20, ['c.*'], 'org_cursor', $request->query('org_cursor'))
                ->withPath('/economy/agreements')->appends($request->only('resident_cursor'));
            $org = $this->withContractOrganizations($orgPage->getCollection())
                ->map(fn ($c) => $this->contractCard($c, $request) + [
                    'family' => 'org', 'href' => "/economy/agreements/{$c->id}",
                    'sort_at' => $this->iso($c->created_at) ?? '',
                ])->all();
            $residentPage = $this->residentAgreementQuery((string) $request->user()->id)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->cursorPaginate(20, ['id', 'title', 'status', 'created_at'], 'resident_cursor', $request->query('resident_cursor'))
                ->withPath('/economy/agreements')->appends($request->only('org_cursor'));
            $resident = $this->visibleResidentAgreements($request, $residentPage->getCollection());
            $all = array_merge($org, $resident);
            $pagination = [
                'org' => ['previous' => $orgPage->previousPageUrl(), 'next' => $orgPage->nextPageUrl()],
                'resident' => ['previous' => $residentPage->previousPageUrl(), 'next' => $residentPage->nextPageUrl()],
            ];
        }

        return Inertia::render('Economy/Agreements', [
            'surface'    => SurfaceMeta::for('economy/agreements'),
            'agreements' => $all,
            'pagination' => $pagination,
        ]);
    }

    /**
     * The viewer's resident (person-to-person) agreements as register rows.
     * Party-scoped by signer; parties shown by NAME (the consent plane). These
     * link to the resident-agreements surface, which can sign and negotiate.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleResidentAgreements(Request $request, \Illuminate\Support\Collection $rows): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $myId = (string) $user->id;

        return $rows
            ->map(function ($a) use ($myId) {
                $signers = DB::table('resident_agreement_signers as s')
                    ->join('users as u', 'u.id', '=', 's.signer_user_id')
                    ->where('s.agreement_id', $a->id)
                    ->get(['u.name', 's.signer_user_id', 's.signed_at'])
                    ->map(fn ($s) => [
                        'name'   => (string) $s->name,
                        'signed' => $s->signed_at !== null,
                        'is_me'  => (string) $s->signer_user_id === $myId,
                    ])->all();

                return [
                    'id'      => (string) $a->id,
                    'family'  => 'resident',
                    'title'   => (string) $a->title,
                    'status'  => (string) $a->status,
                    'signers' => $signers,
                    'href'    => '/economy/resident-agreements?agreement=' . rawurlencode((string) $a->id),
                    'sort_at' => $this->iso($a->created_at) ?? '',
                ];
            })->all();
    }

    private function residentAgreementQuery(string $userId): \Illuminate\Database\Query\Builder
    {
        return DB::table('resident_agreements')->whereNull('deleted_at')
            ->whereIn('id', DB::table('resident_agreement_signers')->select('agreement_id')->where('signer_user_id', $userId));
    }

    /** One instrument, in full — parties only (404 to anyone else). */
    public function agreement(Request $request, string $contract): Response
    {
        $myId = (string) ($request->user()?->id ?? '');
        abort_if($myId === '', 404);
        // A newly appointed agent may not have signed or joined as a member.
        // Add that authority only for this selected instrument, never a global
        // contract-directory lane or public organization terms.
        $row = DB::table('org_contracts as selected')
            ->join('organizations as org', 'org.id', '=', 'selected.organization_id')
            ->where('selected.id', $contract)->whereNull('selected.deleted_at')
            ->where(function ($query) use ($myId, $contract) {
                $query->whereIn('selected.id', $this->visibleContractQuery($myId, $contract)->select('c.id'))
                    ->orWhere(fn ($agent) => $agent->where('org.agent_user_id', $myId)->whereNull('org.deleted_at'));
            })->first(['selected.*', 'org.name as org_name', 'org.agent_user_id as current_agent_id',
                'org.status as org_status', 'org.deleted_at as org_deleted_at']);

        abort_if($row === null, 404);
        $canCosign = (string) $row->current_agent_id === $myId && $row->org_deleted_at === null
            && $row->org_status === Organization::STATUS_ACTIVE
            && in_array($row->status, ['draft', 'offered'], true) && $row->signed_by_org_at === null;

        $signerName = $row->signed_by_org_user_id === null
            ? null
            : DB::table('users')->where('id', $row->signed_by_org_user_id)->value('name');

        return Inertia::render('Economy/AgreementDetail', [
            'surface'   => SurfaceMeta::for('economy/agreement-detail'),
            'agreement' => $this->contractCard($row, $request) + [
                'terms_full'   => (string) $row->terms,
                'org_signer'   => $signerName,
                'effective_at' => $this->iso($row->effective_at),
                'ended_at'     => $this->iso($row->ended_at),
                'created_at'   => $this->iso($row->created_at),
            ],
            // The negotiation overlay (Design Round 2 ③, F-IND-020) — wired in
            // for Wave 4. The base terms above stay authoritative; a clause is a
            // negotiated AMENDMENT to them, a redline a pending change. Accepting
            // one voids the signatures (a signature is on a specific text), so the
            // parties re-sign the changed instrument.
            'clauses'       => DB::table('clauses')
                ->where('subject_type', 'org_contract')->where('subject_id', $row->id)->whereNull('deleted_at')
                ->orderBy('ordinal')->get(['id', 'heading', 'body'])
                ->map(fn ($c) => ['id' => (string) $c->id, 'heading' => $c->heading, 'body' => (string) $c->body])->all(),
            'redlines'      => DB::table('redlines')
                ->where('subject_type', 'org_contract')->where('subject_id', $row->id)->where('status', 'pending')
                ->orderBy('created_at')->get(['id', 'kind', 'body', 'rationale', 'proposer_user_id'])
                ->map(fn ($r) => [
                    'id'        => (string) $r->id,
                    'kind'      => (string) $r->kind,
                    'body'      => (string) $r->body,
                    'rationale' => $r->rationale,
                    'is_mine'   => (string) $r->proposer_user_id === $myId,
                ])->all(),
            // A live instrument (draft/offered/active) can still be negotiated;
            // an ended or voided one is history.
            'can_negotiate' => in_array($row->status, ['draft', 'offered', 'active'], true),
            'can_cosign' => $canCosign,
            'cosign_url' => $canCosign ? '/contracts/'.$row->id.'/cosign' : null,
            'my_id'         => $myId === '' ? null : $myId,
        ]);
    }

    /**
     * Joint ledgers — co-owned accounts whose movements need agreement.
     *
     * VISIBILITY: a PUBLIC ledger (a jurisdiction-owned shared fund) is
     * anyone's to watch; a PRIVATE one is readable only by its co-owners —
     * like a ballot. Parties render as ACCOUNTS (this is the money plane;
     * accounts-never-people holds), with the viewer's own marked.
     */
    public function jointLedgers(Request $request, \App\Services\Economy\JointLedgerService $joint): Response
    {
        $currency = $this->currency();

        $myAccountId = null;
        if ($currency !== null && $request->user() !== null) {
            $myAccountId = $this->accounts->accountIdFor('users', (string) $request->user()->id, $currency->id);
        }

        $rows = DB::table('joint_ledgers')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($myAccountId) {
                $q->where('public', true);

                if ($myAccountId !== null) {
                    $q->orWhereExists(fn ($p) => $p->from('joint_ledger_parties')
                        ->whereColumn('joint_ledger_parties.joint_ledger_id', 'joint_ledgers.id')
                        ->where('joint_ledger_parties.account_id', $myAccountId));
                }
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $ledgers = $rows->map(function ($l) use ($joint, $myAccountId) {
            $parties = DB::table('joint_ledger_parties')
                ->where('joint_ledger_id', $l->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($p) => [
                    'account_id' => (string) $p->account_id,
                    'role'       => (string) $p->role,
                    'is_me'      => $myAccountId !== null && (string) $p->account_id === $myAccountId,
                ])->all();

            $isParty = $myAccountId !== null
                && in_array($myAccountId, array_column($parties, 'account_id'), true);

            $needed = $l->approval_rule === 'all' ? count($parties) : intdiv(count($parties), 2) + 1;

            $movements = DB::table('joint_ledger_movements')
                ->where('joint_ledger_id', $l->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(function ($m) use ($myAccountId, $needed, $isParty) {
                    $approvals = json_decode((string) $m->approvals, true) ?: [];
                    $iApproved = $myAccountId !== null && in_array($myAccountId, $approvals, true);

                    return [
                        'id'            => (string) $m->id,
                        'to_account_id' => (string) $m->to_account_id,
                        'amount'        => (string) $m->amount,
                        'memo'          => $m->memo,
                        'status'        => (string) $m->status,
                        'approvals'     => count($approvals),
                        'needed'        => $needed,
                        'i_approved'    => $iApproved,
                        'can_approve'   => $isParty && ! $iApproved && $m->status === 'pending',
                        'at'            => $this->iso($m->created_at),
                    ];
                })->all();

            // The escrow account is the balance's truth; the mirror column
            // is for lists. Read the truth on the detail surface.
            $escrowId = null;
            $balance = (string) $l->balance;
            try {
                $escrowId = $joint->escrowAccountId((string) $l->id);
                $balance = $this->accounts->balance($escrowId);
            } catch (\RuntimeException) {
                // A ledger without an escrow renders its mirror — visible,
                // not actionable, and the seed/service never produce one.
            }

            return [
                'id'                => (string) $l->id,
                'name'              => (string) $l->name,
                'purpose'           => $l->purpose,
                'public'            => (bool) $l->public,
                'approval_rule'     => (string) $l->approval_rule,
                'balance'           => $balance,
                'escrow_account_id' => $escrowId,
                'parties'           => $parties,
                'is_party'          => $isParty,
                'movements'         => $movements,
            ];
        })->all();

        return Inertia::render('Economy/JointLedgers', [
            'surface'  => SurfaceMeta::for('economy/joint-ledgers'),
            'currency' => $this->currencyProp($currency),
            'ledgers'  => $ledgers,
            'can_open' => $myAccountId !== null,
            'my_account_id' => $myAccountId,
        ]);
    }

    /**
     * The visibility rule, in one place: counterparty-me, or signed-for-the-
     * org-me, or active membership in the organization.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function visibleContracts(Request $request, ?string $onlyId = null)
    {
        $user = $request->user();

        if ($user === null) {
            return collect();
        }

        $query = $this->visibleContractQuery((string) $user->id, $onlyId);

        return $this->withContractOrganizations($query->orderByDesc('c.created_at')->limit($onlyId === null ? 20 : 1)->get(['c.*']));
    }

    private function visibleContractQuery(string $uid, ?string $onlyId = null): \Illuminate\Database\Query\Builder
    {
        // Start each visibility lane from its indexed person/org input. A
        // correlated OR over the world contract register cannot stop cheaply
        // for someone with no agreements, even with a final LIMIT.
        $counterparty = DB::table('org_contracts')->select('id')->whereNull('deleted_at')
            ->where('counterparty_type', 'users')->where('counterparty_id', $uid);
        $signed = DB::table('org_contracts')->select('id')->whereNull('deleted_at')->where('signed_by_org_user_id', $uid);
        $membership = DB::table('org_memberships as m')->join('org_contracts as owned', 'owned.organization_id', '=', 'm.organization_id')
            ->select('owned.id')->where('m.user_id', $uid)->where('m.status', 'active')
            ->whereNull('m.deleted_at')->whereNull('owned.deleted_at');
        if ($onlyId !== null) {
            $counterparty->where('id', $onlyId);
            $signed->where('id', $onlyId);
            $membership->where('owned.id', $onlyId);
        }

        return DB::table('org_contracts as c')
            ->whereNull('c.deleted_at')
            ->whereExists(fn ($q) => $q->from('organizations')->whereColumn('organizations.id', 'c.organization_id'))
            ->whereIn('c.id', $counterparty->union($signed)->union($membership));
    }

    private function withContractOrganizations(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }
        $names = DB::table('organizations')->whereIn('id', $rows->pluck('organization_id'))->pluck('name', 'id');

        return $rows->map(function ($row) use ($names) {
            $row->org_name = $names[$row->organization_id] ?? 'An organization';

            return $row;
        });
    }

    /** @return array<string, mixed> */
    private function contractCard(object $c, Request $request): array
    {
        $uid = (string) $request->user()?->id;

        $counterparty = match (true) {
            $c->counterparty_type === 'users' && (string) $c->counterparty_id === $uid => 'You',
            $c->counterparty_type === 'users' => (string) (DB::table('users')->where('id', $c->counterparty_id)->value('name') ?? 'A resident'),
            default => (string) (DB::table('organizations')->where('id', $c->counterparty_id)->value('name') ?? 'An organization'),
        };

        return [
            'id'           => (string) $c->id,
            'kind'         => (string) $c->kind,
            'org_name'     => (string) $c->org_name,
            'counterparty' => $counterparty,
            'terms'        => \Illuminate\Support\Str::limit((string) $c->terms, 200),
            'status'       => (string) $c->status,
            'signed_by_org'          => $c->signed_by_org_at !== null,
            'signed_by_counterparty' => $c->signed_by_counterparty_at !== null,
            'signed_by_org_at'          => $this->iso($c->signed_by_org_at),
            'signed_by_counterparty_at' => $this->iso($c->signed_by_counterparty_at),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function offers(?string $onlyId = null, array $pageIds = []): array
    {
        // Bound listing input before joining the optional registered asset.
        $ids = collect($pageIds);
        if ($onlyId !== null) {
            $ids = DB::table('marketplace_listings')->whereNull('deleted_at')->where('id', $onlyId)->limit(1)->pluck('id');
        }
        if ($ids->isEmpty()) {
            return [];
        }
        $query = DB::table('marketplace_listings as l')
            ->leftJoin('assets as a', 'a.id', '=', 'l.asset_id')
            ->whereNull('l.deleted_at')
            ->whereIn('l.id', $ids)
            ->select([
                'l.id', 'l.kind', 'l.title', 'l.description', 'l.price', 'l.quantity',
                'l.status', 'l.seller_account_id',
                'a.id as asset_id', 'a.kind as asset_kind', 'a.name as asset_name', 'a.attributes as asset_attributes',
            ]);

        if ($onlyId !== null) {
            $query->where('l.id', $onlyId);
        } else {
            $query->where('l.status', 'open')->orderByRaw("COALESCE(l.created_at, '1970-01-01 00:00:00+00') DESC")->orderByDesc('l.id');
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
            // The measurement-standards power: what the unit IS and how it
            // divides. Both are the legislature's to define; both may be
            // honestly absent ([] / null) in a world that has not exercised
            // the power yet.
            'unit_kind'    => (string) $currency->unit_kind,
            'worth_basis'  => $currency->worth_basis === null ? null : (string) $currency->worth_basis,
            'subdivisions' => $currency->subdivisions === null
                ? []
                : (is_array($currency->subdivisions) ? $currency->subdivisions : (json_decode((string) $currency->subdivisions, true) ?? [])),
        ];
    }

    /**
     * Resolve a seller account to its ORGANIZATION, when it has one.
     *
     * PRIVACY BOUNDARY, drawn exactly here: an organization is a public
     * entity trading under its own name — listing goods IS its public act —
     * so an org seller resolves to a name and a type (the CGC badge is
     * informational; Art. III §5 gives identical terms either way). A HUMAN
     * seller never resolves: people stay accounts, and this helper returns
     * null for them without ever reading the user row.
     *
     * @return array<string, array{name: string, type: string, is_cgc: bool}>
     *         keyed by account id — only org-owned accounts appear
     */
    private function orgSellers(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return DB::table('economic_account_bindings as b')
            ->join('organizations as o', 'o.id', '=', 'b.owner_id')
            ->where('b.owner_type', 'organizations')
            ->whereIn('b.account_id', array_unique($accountIds))
            ->whereNull('o.deleted_at')
            ->get(['b.account_id', 'o.name', 'o.type'])
            ->mapWithKeys(fn ($r) => [(string) $r->account_id => [
                'name'   => (string) $r->name,
                'type'   => (string) $r->type,
                'is_cgc' => $r->type === 'common_good_corp',
            ]])->all();
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

    /**
     * The enacting act behind a value, as a public label. Every fiscal and
     * monetary lever in this economy moves only by an act ON THE RECORD
     * (Art. V §4 · §5), so the surfaces name the act, not just the number.
     * Soft reference to `laws` (there is no separate acts table): null when
     * no act is recorded, which is honest for a value still at its default.
     *
     * @return array{act_number: string|null, title: string}|null
     */
    private function actLabel(?string $actId): ?array
    {
        if ($actId === null) {
            return null;
        }

        $law = DB::table('laws')->where('id', $actId)->first(['act_number', 'title']);

        return $law === null ? null : [
            'act_number' => $law->act_number === null ? null : (string) $law->act_number,
            'title'      => (string) $law->title,
        ];
    }

    /**
     * Which act last moved a given constitutional setting — the per-lever
     * provenance the units page needs. `setting_changes` records every lawful
     * move (key, old→new, the law that carried it); the latest row for a key
     * is the act currently in force. Honestly null until a lever is first
     * moved, at which point it lights up on its own.
     *
     * @return array{act_number: string|null, title: string}|null
     */
    private function latestSettingAct(?string $rootId, string $key): ?array
    {
        if ($rootId === null) {
            return null;
        }

        $lawId = DB::table('setting_changes')
            ->where('jurisdiction_id', $rootId)
            ->where('setting_key', $key)
            ->orderByDesc('applied_at')
            ->value('law_id');

        return $this->actLabel($lawId === null ? null : (string) $lawId);
    }

    /**
     * The economic clock — the stipend disbursement cycle, derived (never
     * stored): the last run from ubi_disbursements, the interval from the
     * amendable setting, the next run projected from the two. Honestly null
     * where a world has not run its first disbursement or set no period.
     *
     * @return array{interval: string, period_days: int|null, last_run: string|null, next_run: string|null}
     */
    private function economicClock(): array
    {
        $currency = $this->currency();
        $rootId   = $this->rootId();

        $last = $currency === null ? null : DB::table('ubi_disbursements')
            ->where('currency_id', $currency->id)
            ->orderByDesc('ran_at')
            ->first();

        $periodDays = $rootId === null ? null : $this->nullableInt($this->settings->resolve($rootId, 'stipend_period_days'));
        $interval   = (string) ($rootId === null ? 'monthly' : ($this->settings->resolve($rootId, 'stipend_interval') ?? 'monthly'));

        return [
            'interval'    => $interval,
            'period_days' => $periodDays,
            'last_run'    => $last === null ? null : $this->iso($last->ran_at),
            'next_run'    => ($last === null || $periodDays === null)
                ? null
                : \Illuminate\Support\Carbon::parse((string) $last->ran_at)->addDays($periodDays)->toIso8601String(),
        ];
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
