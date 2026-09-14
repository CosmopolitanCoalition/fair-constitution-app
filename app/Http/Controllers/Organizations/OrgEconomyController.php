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
        // IO-5: share issuance is the 'shares' bucket — the agent OR a shares
        // delegate may issue, if the org is a live stock enterprise.
        $canIssue = app(\App\Services\Organizations\OrgDelegationService::class)
            ->mayPerform($organization, $request->user(), \App\Domain\Organizations\StaffTask::SHARES)
            && $organization->structure === Organization::STRUCTURE_STOCK
            && $organization->status !== Organization::STATUS_DISSOLVED;
        $accountIds = null;
        $accounts = function () use ($organization, &$accountIds) {
            return $accountIds ??= $this->orgAccountIds($organization);
        };
        $history = new \App\Support\OrganizationFinancialHistory;
        $path = '/organizations/'.$organization->id.'/economy';
        $taxDirectory = $conversionDirectory = null;
        $taxPage = function () use (&$taxDirectory, $canSteer, $history, $request, $accounts, $path) {
            return $taxDirectory ??= ($canSteer ? $history->taxes($request, $accounts(), $path) : $history::empty());
        };
        $conversionPage = function () use (&$conversionDirectory, $history, $request, $organization, $path) {
            return $conversionDirectory ??= $history->conversions($request, (string) $organization->id, $path);
        };

        return Inertia::render('Economy/OrgSettings', [
            'surface'    => SurfaceMeta::for('economy/org-settings'),
            'can_steer'  => $canSteer,
            // Dues ride F-ORG-001 update_settings (the profile bucket), so the
            // page control follows the same authority the handler enforces.
            'can_update_dues' => app(\App\Services\Organizations\OrgDelegationService::class)
                ->mayPerform($organization, $request->user(), \App\Domain\Organizations\StaffTask::PROFILE),
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
                ? $this->ledgerProp($request, $accounts(), $path)
                : ['has_account' => false, 'balance' => null, 'movements' => [], 'restricted' => true],
            'taxes'       => fn () => $taxPage()['records'],
            'tax_pages'   => fn () => $taxPage()['pagination'],
            'conversions' => fn () => $conversionPage()['records'],
            'conversion_pages' => fn () => $conversionPage()['pagination'],
        ]);
    }

    public function issueShares(Request $request, Organization $organization, ConstitutionalEngine $engine): RedirectResponse
    {
        abort_unless($request->user()
            && app(\App\Services\Organizations\OrgDelegationService::class)
                ->mayPerform($organization, $request->user(), \App\Domain\Organizations\StaffTask::SHARES), 403);
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
     * Pay membership dues (F-IND-023, kind='dues'). A member pays the
     * organization's published dues amount into the organization's account.
     * The handler resolves the amount from the dues policy and refuses when
     * the organization charges no dues or the filer is not an active member.
     * Dues are voluntary and never gate a civic right.
     */
    public function payDues(Request $request, Organization $organization, ConstitutionalEngine $engine): RedirectResponse
    {
        abort_unless($request->user(), 403);

        $engine->file('F-IND-023', $request->user(), [
            'action'          => 'dues',
            'organization_id' => (string) $organization->id,
        ]);

        return redirect('/organizations/'.$organization->id.'/economy')
            ->with('status', 'Dues paid. The payment is on the public ledger, recorded as dues.');
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
    private function ledgerProp(Request $request, array $accountIds, string $path): array
    {
        $page = (new \App\Support\TransactionHistory)->page($request, $accountIds, $path);
        $balance = $accountIds === [] ? '0.000000' : (string) (DB::table('economic_accounts')
            ->whereIn('id', $accountIds)->whereNull('deleted_at')->sum('balance') ?: '0.000000');
        return ['has_account' => $accountIds !== [], 'balance' => $balance,
            'movements' => $page['transactions'], 'pagination' => $page['pagination']];
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
