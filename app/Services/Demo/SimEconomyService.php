<?php

namespace App\Services\Demo;

use App\Models\Economy\Currency;
use App\Models\Jurisdiction;
use App\Services\Economy\AccountService;
use App\Services\Economy\CurrencyService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\StipendService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE SIM MONEY PLANE (W7 item 8). Opens resident wallets and runs the civic
 * stipend for the simulated world, THROUGH the real economy services so the
 * demo's money is the same machine a live world runs, not a drawing of one.
 *
 * ACCOUNTS-NEVER-PEOPLE holds: a wallet is an economic_account bound to a user
 * (AccountService owns the one restricted link); every movement rides the real
 * ledger. Currency is ROOT-ONLY (Art. V §5), so there is exactly one — defined
 * once, race-safe, and every wallet and stipend uses it.
 *
 * PER-JURISDICTION IS THE CHUNK (THE ETL RULE). The roster is bounded per
 * jurisdiction (IdentityStage sizes it to Σ(seats + 1)), so a stipend run over
 * ONE jurisdiction's residents is bounded — the real StipendService is called
 * per scope rather than once over the planet, which is the unbounded
 * transaction the demo command warns about. The hardened short-pay math is
 * untouched: each per-jurisdiction run computes its own ratio over its own
 * recipients.
 */
class SimEconomyService
{
    /** One advisory-lock key for the root-currency define (serialize the lanes). */
    private const CURRENCY_LOCK = 528491;

    /** Opening supply minted once, so the root treasury shows a balance. */
    private const OPENING_SUPPLY = '100000000';

    /** Process cache: resolved once per worker, then reused across its items. */
    private static ?array $resolved = null;

    /** Drop the process cache — tests roll back the row the cache points at. */
    public static function resetCache(): void
    {
        self::$resolved = null;
    }

    public function __construct(
        private readonly AccountService $accounts,
        private readonly StipendService $stipend,
    ) {}

    /**
     * The root currency and its treasury, defined once and race-safe. Cheap on
     * the hot path: the currency is looked up without the lock; the lock is
     * taken only when it must be defined.
     *
     * @return array{currency: Currency, treasury_id: string}
     */
    public function ensureCurrency(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $root = Jurisdiction::query()
            ->whereNull('parent_id')->whereNull('deleted_at')
            ->orderBy('adm_level')->first();

        if ($root === null) {
            throw new \RuntimeException('No root jurisdiction — the sim money plane needs one to issue currency (Art. V §5).');
        }

        $currency = Currency::query()->where('jurisdiction_id', $root->id)->first();

        if ($currency === null) {
            // Only the define is serialized; the find above already passed for
            // everyone once it exists.
            $currency = DB::transaction(function () use ($root) {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::CURRENCY_LOCK]);

                // Re-check inside the lock — another lane may have defined it.
                $existing = Currency::query()->where('jurisdiction_id', $root->id)->first();
                if ($existing !== null) {
                    return $existing;
                }

                return app(CurrencyService::class)->define(
                    $root,
                    'Civic Credit',
                    'CVC',
                    'ç',
                );
            });
        }

        $treasuryId = $this->treasuryFor($root->id, (string) $currency->id);

        if (bccomp(app(IssuanceService::class)->supply((string) $currency->id), '0', 6) < 1) {
            app(IssuanceService::class)->mint($currency, $treasuryId, self::OPENING_SUPPLY, 'sim: opening supply');
        }

        return self::$resolved = ['currency' => $currency, 'treasury_id' => $treasuryId];
    }

    /**
     * Open a wallet for each user in the root currency (idempotent per owner).
     * Bounded by the caller (a jurisdiction's roster).
     *
     * @param  list<string>  $userIds
     */
    public function openWalletsFor(array $userIds, ?\Closure $beat = null): int
    {
        if ($userIds === []) {
            return 0;
        }

        $currencyId = (string) $this->ensureCurrency()['currency']->id;
        $opened = 0;

        foreach ($userIds as $userId) {
            $beat && $beat();
            $this->accounts->open('users', (string) $userId, $currencyId);
            $opened++;
        }

        return $opened;
    }

    /**
     * Run the civic stipend for ONE jurisdiction over its active-residency sim
     * residents (the constitutional eligibility gate — Art. I, nothing else).
     * Seated legislature members carry the office_holder bump so the capped-sum
     * arithmetic is visible. Returns null when the place has no residents.
     *
     * @return array{disbursement_id:string, recipients:int, total:string, short_paid:bool}|null
     */
    public function runStipendFor(string $jurisdictionId, ?\Closure $beat = null): ?array
    {
        $env = $this->ensureCurrency();
        $currencyId = (string) $env['currency']->id;

        // The office-holder set for this jurisdiction — seated chamber members.
        $officeHolders = DB::table('legislature_members as m')
            ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
            ->where('l.jurisdiction_id', $jurisdictionId)->whereNull('l.deleted_at')
            ->whereIn('m.status', ['elected', 'seated'])->whereNull('m.deleted_at')
            ->whereNotNull('m.user_id')
            ->pluck('m.user_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        // Every sim resident of this jurisdiction with a wallet. Active
        // residency is the whole gate; the wallet is what the credit lands in.
        $recipients = [];
        DB::table('residency_confirmations as rc')
            ->join('users as u', 'u.id', '=', 'rc.user_id')
            ->join('economic_account_bindings as b', function ($j) {
                $j->on('b.owner_id', '=', 'rc.user_id')->where('b.owner_type', '=', 'users');
            })
            ->join('economic_accounts as a', function ($j) use ($currencyId) {
                $j->on('a.id', '=', 'b.account_id')->where('a.currency_id', '=', $currencyId);
            })
            ->where('rc.jurisdiction_id', $jurisdictionId)
            ->where('rc.is_active', true)
            ->where('u.email', 'like', 'sim-%@demo.invalid')
            ->whereNull('a.deleted_at')
            ->select('rc.user_id', 'a.id as account_id')
            ->orderBy('a.id')
            ->chunk(1000, function ($rows) use (&$recipients, $officeHolders, $beat) {
                $beat && $beat();
                foreach ($rows as $r) {
                    $recipients[] = [
                        'account_id' => (string) $r->account_id,
                        'roles' => $officeHolders->has((string) $r->user_id) ? ['office_holder'] : [],
                    ];
                }
            });

        if ($recipients === []) {
            return null;
        }

        return $this->stipend->run($jurisdictionId, $env['currency'], $recipients, $env['treasury_id']);
    }

    /** Find-or-open the jurisdiction's public treasury in a currency. */
    private function treasuryFor(string $ownerId, string $currencyId): string
    {
        $existing = DB::table('treasury_accounts')
            ->where('owner_type', 'jurisdictions')
            ->where('owner_id', $ownerId)
            ->where('currency_id', $currencyId)
            ->whereNull('deleted_at')
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();
        DB::table('treasury_accounts')->insert([
            'id' => $id,
            'owner_type' => 'jurisdictions',
            'owner_id' => $ownerId,
            'currency_id' => $currencyId,
            'balance' => 0,
            'public' => true,
            'label' => 'Public treasury',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
