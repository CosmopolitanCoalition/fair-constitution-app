<?php

namespace App\Services\Economy;

use App\Models\OrgContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase M slice M-3 — the Open Market.
 *
 * "The Open Market" is a CONSTITUTIONAL TERM, not a product name: Art. III §5
 * uses it twice, for legislatures purchasing monopolistic enterprises and for
 * selling Common Good Corporations. So the marketplace is the venue the
 * constitution already names for buying and selling, and CGCs trade on
 * IDENTICAL terms to private enterprise (Art. III §5) — the badge is
 * informational, never a different rule.
 *
 * Both sides of the board, because a market with only sellers is a catalogue:
 *   OFFERS   — listings, goods (pointing at a registered asset) or services
 *   REQUESTS — work postings (LaborBoardService) and assistance requests
 *
 * SETTLEMENT is one transaction with three effects, or none:
 *   1. money moves    (AccountService::transfer → a balanced ledger posting)
 *   2. the thing moves (AssetService::transfer → append-only provenance)
 *   3. an agreement exists (org_contracts kind='commercial')
 *
 * That third effect is why `commercial` was in the schema from Phase D with a
 * two-signature cosign constraint and no writer: a sale IS a contract between
 * two parties, and the app already knew how to model "both parties agreed".
 * This is the writer it was waiting for.
 *
 * Art. I floor, non-negotiable: no clause of any agreement can waive a
 * constitutional right. A listing may never require one as a condition of
 * sale — see NO_FEE_FORMS for the civic-rights side of the same rail.
 *
 * PARKED (operator, 2026-07-25): DocuSeal as an open-source option for
 * contract execution/signature when contracting is built out. Recorded as a
 * future option; deliberately not built now.
 */
class MarketplaceService
{
    public function __construct(
        private AccountService $accounts,
        private AssetService $assets,
    ) {}

    /** List a good (pointing at an asset) or a service. */
    public function list(
        string $sellerAccountId,
        string $kind,
        string $title,
        string $price,
        string $currencyId,
        ?string $assetId = null,
        ?string $description = null,
        string $quantity = '1',
    ): string {
        if (! in_array($kind, ['good', 'service'], true)) {
            throw new InvalidArgumentException("A listing is a good or a service, got [{$kind}].");
        }

        if ($kind === 'good' && $assetId === null) {
            throw new InvalidArgumentException('A good must point at a registered asset — the market sells things, not descriptions.');
        }

        if (bccomp($price, '0', 6) === -1) {
            throw new InvalidArgumentException('A price cannot be negative.');
        }

        $id = (string) Str::uuid();

        DB::transaction(function () use ($id, $sellerAccountId, $kind, $title, $price, $currencyId, $assetId, $description, $quantity) {
            if ($assetId !== null) {
                $asset = DB::table('assets')->where('id', $assetId)->first();

                if ($asset === null || $asset->owner_account_id !== $sellerAccountId) {
                    throw new InvalidArgumentException('You can only list a thing you hold.');
                }

                DB::table('assets')->where('id', $assetId)->update(['status' => 'listed', 'updated_at' => now()]);
            }

            DB::table('marketplace_listings')->insert([
                'id'                => $id,
                'seller_account_id' => $sellerAccountId,
                'kind'              => $kind,
                'asset_id'          => $assetId,
                'title'             => $title,
                'description'       => $description,
                'quantity'          => $quantity,
                'price'             => $price,
                'currency_id'       => $currencyId,
                'status'            => 'open',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        });

        return $id;
    }

    /** Place an order against an open listing (F-IND-022). */
    public function order(string $listingId, string $buyerAccountId, string $quantity = '1'): string
    {
        $listing = DB::table('marketplace_listings')->where('id', $listingId)->first();

        if ($listing === null || $listing->status !== 'open') {
            throw new RuntimeException('That listing is not open.');
        }

        if ($listing->seller_account_id === $buyerAccountId) {
            throw new InvalidArgumentException('You cannot buy your own listing.');
        }

        $id = (string) Str::uuid();

        DB::table('marketplace_orders')->insert([
            'id'                => $id,
            'listing_id'        => $listingId,
            'buyer_account_id'  => $buyerAccountId,
            'quantity'          => $quantity,
            'price'             => bcmul((string) $listing->price, $quantity, 6),
            'status'            => 'placed',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $id;
    }

    /**
     * Settle an order: money, thing and agreement, atomically.
     *
     * @return array{entry_group:string, contract_id:?string, asset_id:?string}
     */
    public function settle(string $orderId): array
    {
        return DB::transaction(function () use ($orderId) {
            $order = DB::table('marketplace_orders')->where('id', $orderId)->lockForUpdate()->first();

            if ($order === null || $order->status !== 'placed') {
                throw new RuntimeException('That order is not awaiting settlement.');
            }

            $listing = DB::table('marketplace_listings')->where('id', $order->listing_id)->lockForUpdate()->first();

            if ($listing === null || $listing->status !== 'open') {
                throw new RuntimeException('That listing is no longer open.');
            }

            // 1. Money. Throws on insufficient balance BEFORE anything moves —
            //    there is no overdraft in the individual economy.
            $entryGroup = $this->accounts->transfer(
                $order->buyer_account_id,
                $listing->seller_account_id,
                $listing->currency_id,
                (string) $order->price,
                'order',
                'marketplace order ' . $orderId,
            );

            // 2. The agreement — but ONLY when an organisation is a party.
            //
            //    `org_contracts.organization_id` carries an FK to
            //    `organizations`, so the table models a contract in which an
            //    ORG is one side. That is the right shape and I should not
            //    widen it: a peer-to-peer sale between two residents is not an
            //    organisational contract, and forcing one would mean either a
            //    fake org row or a broken FK.
            //
            //    So a sale by an org gets a `commercial` org_contract (the
            //    writer that constraint has waited for since Phase D); a sale
            //    between two people is recorded by its market_transaction and
            //    its asset_transfer, which together are the complete record of
            //    who agreed to what and what moved. A dedicated peer agreement
            //    object belongs with the redline/negotiation work, not here.
            $contractId = null;
            $sellerOrgId = DB::table('economic_account_bindings')
                ->where('account_id', $listing->seller_account_id)
                ->where('owner_type', 'organizations')
                ->value('owner_id');

            if ($sellerOrgId !== null) {
                $contractId = (string) Str::uuid();
                DB::table('org_contracts')->insert([
                    'id'                        => $contractId,
                    'organization_id'           => $sellerOrgId,
                    'counterparty_type'         => 'users',
                    'counterparty_id'           => $order->buyer_account_id,
                    'kind'                      => OrgContract::KIND_COMMERCIAL,
                    'terms'                     => sprintf(
                        "Sale of \"%s\" — quantity %s at %s per unit.\n\nArt. I floor: no term of this agreement waives a constitutional right.",
                        $listing->title,
                        $order->quantity,
                        $listing->price
                    ),
                    'signed_by_org_user_id'     => null,
                    'signed_by_org_at'          => now(),
                    'signed_by_counterparty_at' => now(),
                    'status'                    => 'active',
                    'effective_at'              => now(),
                    'created_at'                => now(),
                    'updated_at'                => now(),
                ]);
            }

            // 3. The thing.
            $movedAssetId = null;
            if ($listing->asset_id !== null) {
                $movedAssetId = $this->assets->transfer(
                    $listing->asset_id,
                    $order->buyer_account_id,
                    (string) $order->quantity,
                    $orderId,
                    $contractId,
                );
            }

            DB::table('marketplace_orders')->where('id', $orderId)->update([
                'status'      => 'settled',
                'contract_id' => $contractId,
                'entry_group' => $entryGroup,
                'updated_at'  => now(),
            ]);

            DB::table('marketplace_listings')->where('id', $listing->id)->update([
                'status'     => 'sold',
                'updated_at' => now(),
            ]);

            return [
                'entry_group' => $entryGroup,
                'contract_id' => $contractId,
                'asset_id'    => $movedAssetId,
            ];
        });
    }
}
