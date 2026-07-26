<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Economy\Currency;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\MarketplaceService;

/**
 * F-IND-022 — Marketplace Listing / Order (R-01). Art. I economic freedom;
 * Art. III §5 — "the Open Market", which is a CONSTITUTIONAL TERM the
 * Template uses twice, not a product name.
 *
 * One form, three lawful actions, because they are one transaction in the
 * player's head: `list` a thing or a service, `order` against a listing,
 * `settle` an order.
 *
 * A DOOR ON A BUILT SERVICE. Everything routes through MarketplaceService,
 * which `institutions:demo-treasury` already drives end to end. Settlement
 * there is ONE transaction with three effects or none:
 *   1. money moves    (a balanced ledger posting)
 *   2. the thing moves (append-only asset provenance)
 *   3. an agreement exists (org_contracts kind='commercial', when an
 *      organisation is a party — a peer-to-peer sale is recorded by its
 *      transaction and its asset transfer instead)
 *
 * Nothing here writes any of that directly, and nothing here can make a sale
 * happen that the service would refuse. The buyer having insufficient funds
 * is a REFUSAL, surfaced as a constitutional rejection — because there is no
 * overdraft in the individual economy and a market that lets you spend money
 * you do not have is not modelling anything real.
 *
 * CGCs trade on IDENTICAL terms to private enterprise (Art. III §5) — the
 * badge is informational, never a different rule.
 */
class MarketplaceListingOrder implements FormHandler
{
    public function __construct(
        private readonly MarketplaceService $market,
        private readonly AccountService $accounts,
    ) {
    }

    public function module(): string
    {
        return 'economy';
    }

    public function event(): string
    {
        return 'market.transacted';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'Buying and selling is done by a person — system filing is not defined.',
                'CGA Forms Catalog (F-IND-022)'
            );
        }

        $action   = (string) ($payload['action'] ?? 'list');
        $currency = $this->currency($payload);
        $accountId = $this->accounts->accountIdFor('users', (string) $actor->id, $currency->id);

        if ($accountId === null) {
            throw new ConstitutionalViolation(
                'You have no wallet in this currency yet — one opens with confirmed residency.',
                'Art. I · as implemented'
            );
        }

        try {
            return match ($action) {
                'list'   => $this->list($accountId, $currency, $payload),
                'order'  => $this->order($accountId, $payload),
                'settle' => $this->settle($accountId, $payload),
                default  => throw new ConstitutionalViolation(
                    "Unknown market action [{$action}] — list, order or settle.",
                    'CGA Forms Catalog (F-IND-022)'
                ),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The service's refusals ARE the constitutional answer — an
            // empty wallet, a closed listing, buying your own goods. Surface
            // them with a citation rather than as a 500.
            throw new ConstitutionalViolation($e->getMessage(), 'Art. I · as implemented');
        }
    }

    private function list(string $accountId, Currency $currency, array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));

        if ($title === '') {
            throw new ConstitutionalViolation('A listing needs a title.', 'CGA Forms Catalog (F-IND-022)');
        }

        $listingId = $this->market->list(
            $accountId,
            (string) ($payload['kind'] ?? 'service'),
            $title,
            (string) ($payload['price'] ?? '0'),
            (string) $currency->id,
            isset($payload['asset_id']) ? (string) $payload['asset_id'] : null,
            isset($payload['description']) ? (string) $payload['description'] : null,
            isset($payload['quantity']) ? (string) $payload['quantity'] : '1',
        );

        return ['action' => 'listed', 'listing_id' => $listingId, 'seller_account_id' => $accountId];
    }

    private function order(string $accountId, array $payload): array
    {
        $listingId = (string) ($payload['listing_id'] ?? '');

        if ($listingId === '') {
            throw new ConstitutionalViolation('An order names the listing.', 'CGA Forms Catalog (F-IND-022)');
        }

        $orderId = $this->market->order(
            $listingId,
            $accountId,
            isset($payload['quantity']) ? (string) $payload['quantity'] : '1',
        );

        return ['action' => 'ordered', 'order_id' => $orderId, 'buyer_account_id' => $accountId];
    }

    private function settle(string $accountId, array $payload): array
    {
        $orderId = (string) ($payload['order_id'] ?? '');

        if ($orderId === '') {
            throw new ConstitutionalViolation('Settlement names the order.', 'CGA Forms Catalog (F-IND-022)');
        }

        // Only the SELLER settles — accepting an order is the seller's act,
        // and letting a buyer self-settle would let them take the goods.
        $order = \Illuminate\Support\Facades\DB::table('marketplace_orders')->where('id', $orderId)->first();

        if ($order === null) {
            throw new ConstitutionalViolation('Unknown order.', 'CGA Forms Catalog (F-IND-022)');
        }

        $sellerAccountId = \Illuminate\Support\Facades\DB::table('marketplace_listings')
            ->where('id', $order->listing_id)->value('seller_account_id');

        if ((string) $sellerAccountId !== $accountId) {
            throw new ConstitutionalViolation(
                'Only the seller accepts an order — a buyer cannot settle their own purchase.',
                'Art. I · as implemented'
            );
        }

        $settled = $this->market->settle($orderId);

        return [
            'action'      => 'settled',
            'order_id'    => $orderId,
            'entry_group' => $settled['entry_group'],
            'contract_id' => $settled['contract_id'],
            'asset_id'    => $settled['asset_id'],
        ];
    }

    private function currency(array $payload): Currency
    {
        if (isset($payload['currency_id'])) {
            $currency = Currency::query()->find((string) $payload['currency_id']);
        } else {
            $rootId = \Illuminate\Support\Facades\DB::table('jurisdictions')
                ->whereNull('parent_id')->whereNull('deleted_at')->value('id');

            $currency = $rootId === null
                ? null
                : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();
        }

        if ($currency === null) {
            throw new ConstitutionalViolation(
                'This world has no currency yet — the root jurisdiction defines one (Art. V §5).',
                'Art. V §5'
            );
        }

        return $currency;
    }
}
