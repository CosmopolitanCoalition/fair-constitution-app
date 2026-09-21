<?php

namespace App\Services\Economy;

use App\Models\Economy\{Currency, EconomicAccount};
use Illuminate\Support\Facades\DB;

/** Provision an empty private wallet after residency, without issuing money. */
class ResidentWalletService
{
    public function __construct(private AccountService $accounts) {}

    public function currency(): ?Currency
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        return $root === null ? null : Currency::where('jurisdiction_id', $root)->first();
    }

    public function eligible(string $userId, Currency $currency): bool
    {
        return DB::table('residency_confirmations')->where('user_id', $userId)
            ->where('jurisdiction_id', $currency->jurisdiction_id)->where('is_active', true)->exists();
    }

    public function ensure(string $userId): ?EconomicAccount
    {
        $currency = $this->currency();
        if ($currency === null || ! $this->eligible($userId, $currency)) {
            return null;
        }

        return $this->accounts->open('users', $userId, (string) $currency->id);
    }
}
