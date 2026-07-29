<?php

namespace App\Console\Commands;

use App\Models\Economy\Currency;
use App\Services\Economy\AccountService;
use App\Services\Economy\AssetService;
use App\Services\Economy\CurrencyService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LaborBoardService;
use App\Services\Economy\LedgerService;
use App\Services\Economy\MarketplaceService;
use App\Services\Economy\StipendService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Phase L+M standing demo — the economy, browsable.
 *
 * Named per the roadmap's §8 verification posture, which lists
 * `institutions:demo-treasury` alongside `institutions:demo-d` / `demo-e` as
 * the phase's idempotent `--fresh` seed.
 *
 * It drives the REAL services end to end — CurrencyService, IssuanceService,
 * AccountService, StipendService, AssetService, MarketplaceService — never
 * direct inserts into the planes they own. So what you see on screen is what
 * the engine actually does: if a ledger posting would not balance, this
 * command fails rather than seeding a world that could not have happened.
 *
 * Deliberately does NOT depend on institutions:demo-d. On a freshly-activated
 * bicameral jurisdiction demo-d cannot complete — activation seats Type A only
 * (Type B districting is deferred), and its first step is an F-LEG-014
 * delegation vote that requires bicameral dual agreement, so the vote closes
 * failed. That is a real gap in the demo chain for ANY fresh world, reported
 * separately; the economy does not need an executive to exist.
 */
class TreasuryDemoCommand extends Command
{
    use \App\Console\Concerns\GuardsSyntheticData;

    protected $signature = 'institutions:demo-treasury {--fresh : tear down previously seeded demo rows first}';

    protected $description = 'Seed a standing economy demo (currency, treasury, wallets, a stipend run, a market with a settled sale) so the Phase L+M surfaces are browsable.';

    private const DEMO_CODE = 'CVU';

    public function handle(
        CurrencyService $currencies,
        IssuanceService $issuance,
        AccountService $accounts,
        StipendService $stipend,
        AssetService $assets,
        MarketplaceService $market,
        LaborBoardService $labor,
        LedgerService $ledger,
    ): int {
        // Added 2026-07-28 (the D5 preset audit): this command was one of
        // two demo seeders shipping WITHOUT the synthetic-data guard —
        // nothing stopped it minting a currency, wallets and a stipend run
        // on a production world. Same gate as every other demo command.
        if (! $this->guardSyntheticData()) {
            return self::FAILURE;
        }

        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->first();

        if ($root === null) {
            $this->error('No root jurisdiction — found a world first (setup wizard, or a scoped ETL).');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->teardown();
        }

        $this->line("institutions:demo-treasury — root {$root->slug}");

        // ── 1. The currency (Art. V §5 — root only) ──────────────────────
        $currency = Currency::query()->where('code', self::DEMO_CODE)->first();

        if ($currency === null) {
            $settings = DB::table('constitutional_settings')->where('jurisdiction_id', $root->id)->first();

            $currency = $currencies->define(
                \App\Models\Jurisdiction::query()->findOrFail($root->id),
                $settings->currency_name ?? 'Civic Value Unit',
                $settings->currency_code ?? self::DEMO_CODE,
                $settings->currency_symbol ?? 'ç',
            );
        }
        $this->line("1. currency {$currency->code} ({$currency->symbol}) issued by {$root->slug}");

        // ── 2. The public treasury ───────────────────────────────────────
        $treasuryId = $this->treasuryFor($root->id, $currency->id, 'Root treasury');
        $this->line('2. treasury account opened');

        // ── 3. Mint the opening supply (the ONE lawful imbalance) ────────
        // Only when there is none. The ledger is append-only, so --fresh
        // cannot un-mint; re-running must not inflate the money supply.
        if (bccomp($issuance->supply($currency->id), '0', 6) < 1) {
            $issuance->mint($currency, $treasuryId, '250000', 'demo: opening supply');
            $this->line('3. minted 250,000 ' . $currency->code);
        } else {
            $this->line('3. opening supply already minted — not re-minting (the ledger is append-only)');
        }

        // ── 4. Wallets for everyone with confirmed residency ─────────────
        $residents = DB::table('residency_confirmations')
            ->where('is_active', true)
            ->distinct()
            ->pluck('user_id')
            ->take(60)
            ->all();

        $wallets = [];
        foreach ($residents as $userId) {
            $wallets[$userId] = $accounts->open('users', (string) $userId, $currency->id)->id;
        }
        $this->line('4. ' . count($wallets) . ' wallets opened (active residency)');

        if (count($wallets) < 2) {
            $this->error('Need at least two residents with confirmed residency to demo a market.');

            return self::FAILURE;
        }

        // ── 5. A stipend run — the real service, real eligibility ────────
        // Roles are illustrative: the first few get differentials so the
        // capped-sum arithmetic is visible on screen.
        $recipients = [];
        $i = 0;
        foreach ($wallets as $accountId) {
            $roles = match (true) {
                $i === 0 => ['node_operator', 'social_moderator', 'office_holder'], // stacks past the cap
                $i < 4   => ['office_holder'],
                $i < 8   => ['node_operator'],
                default  => [],
            };
            $recipients[] = ['account_id' => $accountId, 'roles' => $roles];
            $i++;
        }

        $run = $stipend->run($root->id, $currency, $recipients, $treasuryId);
        $this->line("5. stipend run: {$run['recipients']} paid, total {$run['total']} " . $currency->code
            . ($run['short_paid'] ? ' (SHORT-PAID pro-rata)' : ''));

        // ── 6. A market with a real thing in it ──────────────────────────
        $accountIds = array_values($wallets);
        [$sellerId, $buyerId] = [$accountIds[0], $accountIds[1]];

        $assetId = $assets->register(
            $sellerId,
            AssetService::KIND_PHYSICAL,
            'Hand-woven wool blanket',
            'Undyed local wool, twill weave.',
            ['materials' => ['wool'], 'dimensions_cm' => [200, 150], 'weight_g' => 1400],
        );

        $listingId = $market->list($sellerId, 'good', 'Hand-woven wool blanket', '45', $currency->id, $assetId);
        $orderId   = $market->order($listingId, $buyerId);
        $settled   = $market->settle($orderId);

        $this->line('6. market: blanket listed, ordered and SETTLED — money and thing moved in one transaction');
        $this->line($settled['contract_id'] === null
            ? '   peer-to-peer sale: recorded by its market_transaction + asset_transfer (org_contracts models ORG contracts)'
            : '   contract ' . substr($settled['contract_id'], 0, 8) . ' (org_contracts kind=commercial)');

        // A service listing and a virtual item, so both shapes are visible.
        $market->list($sellerId, 'service', 'Two hours of carpentry', '30', $currency->id);
        $virtualId = $assets->register($buyerId, AssetService::KIND_VIRTUAL, 'Cartographer’s compass',
            'A map-maker’s tool.', ['rarity' => 'uncommon', 'accuracy' => 3]);
        $market->list($buyerId, 'good', 'Cartographer’s compass', '120', $currency->id, $virtualId);
        $this->line('   plus a service listing and a virtual item (one engine, two kinds)');

        // ── 7. The work board ────────────────────────────────────────────
        $org = DB::table('organizations')->whereNull('deleted_at')->first();

        if ($org !== null) {
            $postingId = $labor->post((string) $org->id, 'Weaver wanted — two shifts a week',
                'Recurring shifts at the mill. Co-determination applies on acceptance.', '18', $currency->id);
            $labor->apply($postingId, $accountIds[2] ?? $buyerId, 'Available from next week.');
            $this->line('7. work posting + application (acceptance files F-IND-014 — co-determination, not a bypass)');
        } else {
            $this->line('7. work board skipped — no organizations on this box yet');
        }

        // ── 8. Mutual aid — non-market, private by default ───────────────
        DB::table('assistance_requests')->insert([
            'id'                   => (string) Str::uuid(),
            'requester_account_id' => $accountIds[3] ?? $buyerId,
            'jurisdiction_id'      => $root->id,
            'title'                => 'Help moving a loom',
            'need'                 => 'Two people for an hour. No payment — mutual aid.',
            'privacy'              => 'jurisdiction',
            'status'               => 'open',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
        $this->line('8. mutual-aid request (no price, no order, no fee — Art. I association)');

        // ── 9. Prove the ledger ──────────────────────────────────────────
        $chain = $ledger->verifyChain();
        $imbalance = $ledger->imbalanceByCurrency()[$currency->id] ?? '0';
        $supply = $issuance->supply($currency->id);

        $this->newLine();
        // Σdebits − Σcredits. A mint is a CREDIT with no matching debit, so a
        // healthy ledger sits at exactly NEGATIVE the minted supply: every
        // movement other than issuance conserves value, and issuance is the
        // only lawful way for value to enter (Art. V §5).
        $residual = bcadd($imbalance, $supply, 6);

        $this->line('LEDGER: chain ' . ($chain === true ? 'VERIFIED' : "BROKEN at seq {$chain}"));
        $this->line("  minted supply      {$supply}");
        $this->line("  Σdebits − Σcredits {$imbalance}   (= −supply when every non-issuance movement conserves)");
        $this->line("  residual           {$residual}   (MUST be 0)");
        $this->line('  entries            ' . DB::table('ledger_entries')->count());

        if ($chain !== true || bccomp($residual, '0', 6) !== 0) {
            $this->error('Ledger integrity check FAILED — the seeded world is not one the engine could have produced.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Economy seeded. Currency, treasury, wallets, a stipend run, a settled sale, a work posting and mutual aid.');

        return self::SUCCESS;
    }

    private function treasuryFor(string $ownerId, string $currencyId, string $label): string
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
            'id'          => $id,
            'owner_type'  => 'jurisdictions',
            'owner_id'    => $ownerId,
            'currency_id' => $currencyId,
            'balance'     => 0,
            'public'      => true,
            'label'       => $label,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    /**
     * ledger_entries and issuance_events are append-only by trigger, so a
     * "fresh" reseed deliberately does NOT delete them — a ledger you can
     * erase is not a ledger. It clears only the market surface so the demo
     * can be re-run without duplicating listings.
     */
    private function teardown(): void
    {
        foreach (['marketplace_orders', 'marketplace_listings', 'assistance_requests', 'work_applications', 'work_postings'] as $table) {
            DB::table($table)->delete();
        }

        $this->line('--fresh: cleared the market surface (the ledger is append-only and stays).');
    }
}
