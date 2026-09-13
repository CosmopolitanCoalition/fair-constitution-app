<?php

namespace App\Http\Controllers\Organizations;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Economy\Currency;
use App\Models\Organization;
use App\Services\Organizations\OrgSettingsService;
use App\Services\Organizations\OrgOwnershipService;
use App\Support\OrgShareDirectory;
use App\Support\OrgShareRecipientDirectory;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 * Share issuance uses F-ORG-008. Both issuance and the current settings
 * handler require the exact agent; seated board members retain private
 * ledger access. Ownership and recipient directories load independently.
 */
class OrgEconomyController extends Controller
{
    public function __construct(private OrgSettingsService $orgSettings) {}

    public function show(Request $request, Organization $organization): Response
    {
        abort_unless($request->user(), 403);
        // Public ownership and private wallet access are separate from the
        // exact-agent authority used by the existing settings/issuance forms.
        $canSteer = $this->maySteer($organization, $request);
        $isAgent = (string) $organization->agent_user_id === (string) $request->user()->getKey();
        $canIssue = $isAgent && $organization->structure === Organization::STRUCTURE_STOCK
            && $organization->status !== Organization::STATUS_DISSOLVED;
        $accountIds = null;
        $accounts = function () use ($organization, &$accountIds) {
            return $accountIds ??= $this->orgAccountIds($organization);
        };

        return Inertia::render('Economy/OrgSettings', [
            'surface'    => SurfaceMeta::for('economy/org-settings'),
            'can_steer'  => $canSteer,
            'can_update_dues' => $isAgent,
            'can_issue_shares' => $canIssue,
            'compose' => $request->boolean('issue'),
            'currency'   => fn () => $this->currencyProp($this->rootCurrency()),
            'org'        => [
                'id'        => (string) $organization->id,
                'name'      => (string) $organization->name,
                'type'      => (string) $organization->type,
                'structure' => $organization->structure === null ? null : (string) $organization->structure,
                'is_cgc'    => (bool) $organization->is_cgc,
            ],
            'dues'        => fn () => $this->duesProp($organization),
            'shares'      => fn () => app(OrgShareDirectory::class)->page($request, $organization),
            'recipient_directory' => fn () => $canIssue
                ? app(OrgShareRecipientDirectory::class)->page($request, $organization)
                : OrgShareRecipientDirectory::empty(),
            // Design Round 2 ② — the economy half, filled from records that
            // already exist: the org's own ledger, what it owes in levies, and
            // any conversion that fixed a fair-market price for its equity.
            'ledger'      => fn () => $canSteer
                ? $this->ledgerProp($accounts())
                : ['has_account' => false, 'balance' => null, 'movements' => [], 'restricted' => true],
            'taxes'       => fn () => $canSteer ? $this->taxesProp($accounts()) : [],
            'conversions' => fn () => $this->conversionsProp($organization),
        ]);
    }

    public function issueShares(Request $request, Organization $organization, ConstitutionalEngine $engine): RedirectResponse
    {
        abort_unless($request->user()
            && (string) $organization->agent_user_id === (string) $request->user()->getKey(), 403);
        abort_unless($organization->structure === Organization::STRUCTURE_STOCK, 422, 'Only stock organizations issue shares.');
        abort_if($organization->status === Organization::STATUS_DISSOLVED, 422, 'A dissolved organization cannot issue shares.');
        $data = $request->validate([
            'holder_type' => ['required', Rule::in(['users', 'organizations'])],
            'holder_id' => ['required', 'uuid'],
            'units' => ['required', 'string', 'regex:/\A\d{1,14}(?:\.\d{1,6})?\z/'],
        ]);
        $request->validate(['holder_id' => [Rule::exists($data['holder_type'], 'id')->whereNull('deleted_at')]]);
        try {
            $units = OrgOwnershipService::normalizeUnits($data['units']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['units' => $error->getMessage()]);
        }
        $engine->file('F-ORG-008', $request->user(), [
            'action' => 'issue_shares', 'organization_id' => (string) $organization->id,
            'holder_type' => $data['holder_type'], 'holder_id' => $data['holder_id'], 'units' => $units,
        ]);

        $recipient = DB::table($data['holder_type'])->where('id', $data['holder_id'])->whereNull('deleted_at')
            ->first($data['holder_type'] === 'users' ? ['display_name', 'name'] : ['name']);
        $name = trim((string) ($recipient->display_name ?? '')) ?: ($recipient->name ?? 'the selected recipient');

        return redirect('/organizations/'.$organization->id.'/economy')
            ->with('status', 'Shares issued: '.$units.' units to '.$name.'. Public ownership is recorded; no payment was made.');
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
