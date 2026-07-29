<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Economy\Currency;
use App\Models\Organization;
use App\Services\Organizations\OrgSettingsService;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Design Round 2, ② — the org-settings ECONOMY half (operator ruling
 * 2026-07-29). This surface is an organization's own economic control
 * panel; it is org-scoped and steered by the org's agent or a seated board
 * member (the mockup's model: "the seated board sets economic policy").
 *
 *   GET /organizations/{organization}/economy — economy/org-settings
 *
 * Piece 1 (dues): DUES ARE A MEMBERSHIP SUBSCRIPTION OBLIGATION, not a tax
 * and not a share system. The org publishes a dues POLICY (amount + period)
 * on organizations.settings via F-ORG-001 'update_settings'; a member's
 * obligation derives from active membership + that policy; a payment is an
 * ordinary F-IND-023 transfer with kind='dues'. There is NO dues engine and
 * NO scheduler. ABSENCE IS HONEST — an org that has set no dues_amount
 * charges no dues, and the page says exactly that. Dues can never gate a
 * civic right: they are voluntary, and a lapse ends membership without
 * withholding any right (Art. I · Art. II §8).
 *
 * Piece 4 (share issuance, F-ORG-008) grows the Shares section here; for now
 * it renders honest-absence. Every value shown is READ from a row — nothing
 * is computed as policy in this controller.
 */
class OrgEconomyController extends Controller
{
    public function __construct(private OrgSettingsService $orgSettings) {}

    public function show(Request $request, Organization $organization): Response
    {
        // A control panel, not a public record: only the people who steer the
        // org's economics may see them. Others are told the page is not theirs
        // rather than shown a half-view.
        abort_unless($this->maySteer($organization, $request), 403);

        $currency = $this->rootCurrency();

        $accountIds = $this->orgAccountIds($organization);

        return Inertia::render('Economy/OrgSettings', [
            'surface'    => SurfaceMeta::for('economy/org-settings'),
            'currency'   => $this->currencyProp($currency),
            'org'        => [
                'id'        => (string) $organization->id,
                'name'      => (string) $organization->name,
                'type'      => (string) $organization->type,
                'structure' => $organization->structure === null ? null : (string) $organization->structure,
                'is_cgc'    => (bool) $organization->is_cgc,
            ],
            'dues'        => $this->duesProp($organization),
            'shares'      => $this->sharesProp($organization),
            // Design Round 2 ② — the economy half, filled from records that
            // already exist: the org's own ledger, what it owes in levies, and
            // any conversion that fixed a fair-market price for its equity.
            'ledger'      => $this->ledgerProp($accountIds),
            'taxes'       => $this->taxesProp($accountIds),
            'conversions' => $this->conversionsProp($organization),
        ]);
    }

    /**
     * The org's economic account ids (the money plane). An org may hold an
     * account per currency; all are its own to view here. Empty is honest —
     * an org with no account has no ledger, and the page says so.
     *
     * @return list<string>
     */
    private function orgAccountIds(Organization $organization): array
    {
        return DB::table('economic_account_bindings')
            ->where('owner_type', 'organizations')
            ->where('owner_id', $organization->id)
            ->pluck('account_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * The org's own ledger card — its balance and recent movements. This is
     * the MONEY plane: a counterparty is an ACCOUNT, never a person, even to
     * the org's own steward. An org that has never transacted shows a clean
     * zero, not an error.
     *
     * @param  list<string>  $accountIds
     * @return array{has_account: bool, balance: string, movements: list<array<string, mixed>>}
     */
    private function ledgerProp(array $accountIds): array
    {
        if ($accountIds === []) {
            return ['has_account' => false, 'balance' => '0.000000', 'movements' => []];
        }

        $balance = (string) (DB::table('economic_accounts')
            ->whereIn('id', $accountIds)->whereNull('deleted_at')->sum('balance') ?: '0.000000');

        $movements = DB::table('market_transactions')
            ->where(fn ($q) => $q->whereIn('from_account_id', $accountIds)->orWhereIn('to_account_id', $accountIds))
            ->orderByDesc('created_at')->limit(25)->get()
            ->map(function ($t) use ($accountIds) {
                $out = in_array((string) $t->from_account_id, $accountIds, true);
                $other = $out ? $t->to_account_id : $t->from_account_id;

                return [
                    'id'                      => (string) $t->id,
                    'direction'               => $out ? 'out' : 'in',
                    'amount'                  => (string) $t->amount,
                    'kind'                    => (string) $t->kind,
                    'memo'                    => $t->memo,
                    'at'                      => $this->iso($t->created_at),
                    'counterparty_account_id' => $other === null ? null : (string) $other,
                ];
            })->all();

        return ['has_account' => true, 'balance' => $balance, 'movements' => $movements];
    }

    /**
     * What the org owes and has declared (Art. V §4). Each filing carries the
     * levy's base and rate — how the charge is computed is public — and whether
     * civic use is exempt. A rate crosses as a string (ratio, but anti-float
     * for the same reason money is).
     *
     * @param  list<string>  $accountIds
     * @return list<array<string, mixed>>
     */
    private function taxesProp(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return DB::table('tax_filings as f')
            ->leftJoin('levies as l', 'l.id', '=', 'f.levy_id')
            ->leftJoin('revenue_streams as r', 'r.id', '=', 'l.revenue_stream_id')
            ->whereIn('f.account_id', $accountIds)
            ->orderByDesc('f.created_at')->limit(25)
            ->get(['f.id', 'f.period', 'f.declared', 'f.assessed', 'f.status',
                'l.base', 'l.rate', 'l.civic_exempt', 'r.name as stream_name'])
            ->map(fn ($f) => [
                'id'           => (string) $f->id,
                'period'       => (string) $f->period,
                'declared'     => $f->declared === null ? null : (string) $f->declared,
                'assessed'     => $f->assessed === null ? null : (string) $f->assessed,
                'status'       => (string) $f->status,
                'stream'       => $f->stream_name === null ? null : (string) $f->stream_name,
                'base'         => $f->base === null ? null : (string) $f->base,
                'rate'         => $f->rate === null ? null : (string) $f->rate,
                'civic_exempt' => $f->civic_exempt === null ? null : (bool) $f->civic_exempt,
            ])->all();
    }

    /**
     * Fair-market conversions on the org's equity (Art. III §5). A conversion
     * fixes a floor and a basis for what a share is worth when ownership
     * changes hands — a public fact on the named ownership plane (Ruling B).
     *
     * @return list<array<string, mixed>>
     */
    private function conversionsProp(Organization $organization): array
    {
        return DB::table('org_conversions')
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn ($c) => [
                'id'                => (string) $c->id,
                'direction'         => (string) $c->direction,
                'via'               => (string) $c->via,
                'status'            => (string) $c->status,
                'fair_market_floor' => $c->fair_market_floor === null ? null : (string) $c->fair_market_floor,
                'fair_market_basis' => $c->fair_market_basis === null ? null : (string) $c->fair_market_basis,
                'completed_at'      => $this->iso($c->completed_at),
            ])->all();
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

    /**
     * The dues POLICY as the page reads it. `has_dues` is the honest-absence
     * switch: false when the org has set no dues_amount, and the page renders
     * "this organization charges no dues" rather than an empty table.
     *
     * @return array{has_dues: bool, amount: string|null, period_days: int|null}
     */
    private function duesProp(Organization $organization): array
    {
        $amount = $this->orgSettings->get($organization, 'dues_amount');
        $period = $this->orgSettings->get($organization, 'dues_period_days');

        return [
            'has_dues'    => $amount !== null,
            'amount'      => $amount === null ? null : (string) $amount,
            'period_days' => $period === null ? null : (int) $period,
        ];
    }

    /**
     * The cap table, on the NAMED ownership plane (Ruling B). Who owns a
     * company is a public fact — equity holders appear by name here, which is
     * NOT the pseudonymous money plane. Only a STOCK organization issues
     * shares (F-ORG-008); elsewhere ownership is by membership, and this
     * renders honest-absence.
     *
     * @return array{issued: bool, issuable: bool, holders: list<array<string, mixed>>, total_units: string, note: string}
     */
    private function sharesProp(Organization $organization): array
    {
        $issuable = (string) $organization->structure === Organization::STRUCTURE_STOCK;

        $stakes = DB::table('org_ownership_stakes')
            ->where('organization_id', $organization->id)
            ->whereNull('ended_at')
            ->orderByDesc('units')
            ->get(['holder_type', 'holder_id', 'units', 'pct', 'acquired_via']);

        $holders = $stakes->map(fn ($s) => [
            'holder' => $this->holderName((string) $s->holder_type, (string) $s->holder_id),
            'units'  => (string) $s->units,
            'pct'    => $s->pct === null ? null : (string) $s->pct,
            'via'    => (string) $s->acquired_via,
        ])->all();

        $total = $stakes->reduce(fn ($c, $s) => bcadd((string) $c, (string) $s->units, 6), '0');

        return [
            'issued'      => count($holders) > 0,
            'issuable'    => $issuable,
            'holders'     => $holders,
            'total_units' => $total,
            'note'        => $issuable
                ? 'Equity shares are a public ownership fact, recorded by name. The money that changes hands when a share trades stays on the private wallet ledger.'
                : 'Ownership here is by membership, not shares — only a stock organization issues equity (Art. III §5).',
        ];
    }

    private function holderName(string $type, string $id): string
    {
        if ($type === 'organizations') {
            return (string) (DB::table('organizations')->where('id', $id)->value('name') ?? 'An organization');
        }
        if ($type === 'jurisdictions') {
            return (string) (DB::table('jurisdictions')->where('id', $id)->value('name') ?? 'A jurisdiction');
        }

        // users — the named ownership plane (Ruling B), never the money plane.
        return (string) (DB::table('users')->where('id', $id)->value('name') ?? 'A holder');
    }

    private function maySteer(Organization $org, Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        if ((string) $org->agent_user_id === (string) $user->id) {
            return true;
        }

        return $org->board_id !== null && DB::table('board_seats')
            ->where('board_id', $org->board_id)
            ->where('holder_user_id', (string) $user->id)
            ->where('status', 'seated')
            ->whereNull('deleted_at')
            ->exists();
    }

    private function rootCurrency(): ?Currency
    {
        $rootId = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

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
}
