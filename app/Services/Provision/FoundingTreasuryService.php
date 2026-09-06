<?php

namespace App\Services\Provision;

use App\Models\Economy\Currency;
use App\Models\Jurisdiction;
use App\Services\AuditService;
use App\Services\Economy\CurrencyService;
use App\Services\InstitutionProvisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE MONEY PLANE FOUNDING (Wave 6, item 3). At the start of a Step 4 run the
 * world currency is defined by the root jurisdiction (Art. V §5, the founding
 * currency written at setup predates any legislature) and the root's public
 * treasury is opened. The per-jurisdiction treasuries then ride the shell
 * batch (InstitutionProvisionService step 'treasuries'). Idempotent: an
 * existing currency is adopted, never duplicated.
 */
class FoundingTreasuryService
{
    public function __construct(
        private readonly CurrencyService $currencies,
        private readonly AuditService $audit,
        private readonly InstitutionProvisionService $provision,
    ) {}

    /**
     * Ensure the currency and the root treasury exist. Returns the currency
     * id, or null when the world has no root jurisdiction yet.
     */
    public function found(): ?string
    {
        $root = Jurisdiction::query()
            ->whereNull('parent_id')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();
        if ($root === null) {
            return null;
        }

        $currency = Currency::query()->whereNull('deleted_at')->orderBy('created_at')->first();
        if ($currency === null) {
            $settings = DB::table('constitutional_settings')
                ->where('jurisdiction_id', $root->id)
                ->first(['currency_name', 'currency_code', 'currency_symbol']);

            $currency = $this->currencies->define(
                $root,
                (string) ($settings->currency_name ?? 'Civic Value Unit'),
                (string) ($settings->currency_code ?? 'CVU'),
                (string) ($settings->currency_symbol ?? 'ç'),
            );

            $this->audit->append(
                module: 'economy',
                event: 'currency.founded',
                payload: [
                    'currency_id'     => (string) $currency->id,
                    'code'            => $currency->code,
                    'jurisdiction_id' => (string) $root->id,
                    'generator'       => 'Step 4 engine (Wave 6)',
                ],
                ref: 'Art. V §5',
                jurisdictionId: (string) $root->id,
            );
            $this->provision->forgetCurrency();
        }

        $exists = DB::table('treasury_accounts')
            ->where('owner_type', 'jurisdictions')
            ->where('owner_id', $root->id)
            ->where('currency_id', $currency->id)
            ->whereNull('deleted_at')
            ->exists();
        if (! $exists) {
            DB::table('treasury_accounts')->insertOrIgnore([
                'id'          => (string) Str::uuid(),
                'owner_type'  => 'jurisdictions',
                'owner_id'    => (string) $root->id,
                'currency_id' => (string) $currency->id,
                'balance'     => 0,
                'public'      => true,
                'label'       => 'Root Public Treasury',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return (string) $currency->id;
    }
}
