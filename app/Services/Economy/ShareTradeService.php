<?php

namespace App\Services\Economy;

use App\Models\Economy\Currency;
use App\Models\Organization;
use App\Models\OrgOwnershipStake;
use App\Models\User;
use App\Services\Organizations\OrgOwnershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Wave 4 ② — secondary share trading: holder-to-holder equity RESALE on the
 * exchange (operator ruled IN, A; migration slot granted 2026-07-29).
 *
 * TWO PLANES, held apart the same way the whole economy holds them apart:
 *   - OWNERSHIP (who owns): a stake is a NAMED holder (Ruling B). A sale MOVES
 *     units from the seller's cap-table position to the buyer's, VIA_TRANSFER.
 *   - MONEY (what was paid): the cash leg is an ordinary account-scoped transfer
 *     (AccountService), pseudonymous, with no overdraft. The two are linked only
 *     by money_transfer_id on the offer row — the money never joins the named
 *     ownership record.
 *
 * A share is equity, never currency (Art. V §5); only a STOCK org has shares.
 * A REFUSAL IS AN ANSWER: selling more than you hold, or buying without funds,
 * comes back as a ConstitutionalViolation carrying its citation, and BOTH legs
 * roll back together (one DB transaction — underfunded means nothing moved).
 *
 * This service is the write core; F-IND-021 "Share Trade" is the engine door.
 * There is no second write API.
 */
class ShareTradeService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly OrgOwnershipService $ownership,
    ) {}

    /**
     * Offer some of the seller's OWN equity in an org for sale at a fixed
     * per-unit price. The seller must be a STOCK org's shareholder and hold at
     * least the units offered right now (re-checked again at buy time).
     *
     * @return array{offer_id: string, units: string, price_per_unit: string}
     */
    public function offer(User $seller, string $organizationId, float $units, string $pricePerUnit): array
    {
        if ($units <= 0) {
            throw new RuntimeException('A share offer must be for a positive number of units.');
        }
        if (bccomp($pricePerUnit, '0', 6) < 0) {
            throw new RuntimeException('A share price cannot be negative.');
        }

        $org = Organization::query()->find($organizationId);
        if ($org === null) {
            throw new RuntimeException('Unknown organization.');
        }
        if ((string) $org->structure !== Organization::STRUCTURE_STOCK) {
            throw new \App\Domain\Engine\ConstitutionalViolation(
                'Only a stock organization has shares to trade — ownership elsewhere is by membership.',
                'Art. III §5'
            );
        }

        $held = $this->heldUnits($organizationId, (string) $seller->id);
        if ($held + 1e-9 < $units) {
            throw new \App\Domain\Engine\ConstitutionalViolation(
                'You cannot offer more shares than you hold.',
                'Art. III §5'
            );
        }

        $currency = $this->rootCurrency();

        $id = (string) Str::uuid();
        DB::table('share_offers')->insert([
            'id'                 => $id,
            'organization_id'    => $organizationId,
            'seller_holder_type' => OrgOwnershipStake::HOLDER_USERS,
            'seller_holder_id'   => (string) $seller->id,
            'units'              => $units,
            'price_per_unit'     => $pricePerUnit,
            'currency_id'        => (string) $currency->id,
            'status'             => 'open',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return [
            'offer_id'       => $id,
            'units'          => (string) $units,
            'price_per_unit' => (string) $pricePerUnit,
        ];
    }

    /**
     * Buy an open offer. ONE transaction: pay the seller (no overdraft), move
     * the units (seller down, buyer up), settle the offer. Any failure rolls
     * back both legs — an underfunded buy moves nothing.
     *
     * @return array{offer_id: string, units: string, total: string, money_transfer_id: string}
     */
    public function buy(User $buyer, string $offerId): array
    {
        return DB::transaction(function () use ($buyer, $offerId) {
            // Lock the offer for the length of the settlement so two buyers
            // cannot both take the same shares.
            $offer = DB::table('share_offers')->where('id', $offerId)->lockForUpdate()->first();

            if ($offer === null) {
                throw new RuntimeException('Unknown share offer.');
            }
            if ($offer->status !== 'open') {
                throw new \App\Domain\Engine\ConstitutionalViolation(
                    'This share offer is no longer open.',
                    'CGA Forms Catalog (F-IND-021)'
                );
            }
            if ((string) $offer->seller_holder_id === (string) $buyer->id) {
                throw new RuntimeException('You cannot buy your own share offer.');
            }

            $sellerId = (string) $offer->seller_holder_id;
            $units    = (float) $offer->units;
            $total    = bcmul((string) $offer->units, (string) $offer->price_per_unit, 6);

            // The offer can go stale — re-check the seller still holds enough.
            if ($this->heldUnits((string) $offer->organization_id, $sellerId) + 1e-9 < $units) {
                throw new \App\Domain\Engine\ConstitutionalViolation(
                    'The seller no longer holds enough shares to honor this offer.',
                    'Art. III §5'
                );
            }

            // MONEY leg (account plane). ensure both wallets, then transfer.
            // assertSufficient inside transfer() refuses an overdraft.
            $currencyId    = (string) $offer->currency_id;
            $buyerAccount  = $this->accounts->open('users', (string) $buyer->id, $currencyId)->id;
            $sellerAccount = $this->accounts->open('users', $sellerId, $currencyId)->id;

            $entryGroup = bccomp($total, '0', 6) > 0
                ? $this->accounts->transfer(
                    (string) $buyerAccount,
                    (string) $sellerAccount,
                    $currencyId,
                    $total,
                    'share_trade',
                    'Secondary share purchase',
                )
                : null; // a zero-price gift still moves ownership, no money leg

            // OWNERSHIP leg (named plane, Ruling B). Consolidate the seller's
            // open stakes, reopen the remainder, open the buyer's — all
            // VIA_TRANSFER, tagged with the money leg they rode.
            $org = Organization::query()->findOrFail((string) $offer->organization_id);
            $held = $this->heldUnits((string) $offer->organization_id, $sellerId);

            OrgOwnershipStake::query()
                ->where('organization_id', $offer->organization_id)
                ->where('holder_type', OrgOwnershipStake::HOLDER_USERS)
                ->where('holder_id', $sellerId)
                ->open()
                ->update(['ended_at' => now(), 'updated_at' => now()]);

            // NB: source_transfer_id on a stake FK-references org_transfers (the
            // internal-restructuring instrument), NOT a money entry. A share
            // trade is not an org_transfer, so the stake carries null there; the
            // money↔ownership link lives on share_offers.money_transfer_id below.
            $remainder = round($held - $units, 6);
            if ($remainder > 1e-9) {
                $this->ownership->openStake($org, OrgOwnershipStake::HOLDER_USERS, $sellerId, $remainder, OrgOwnershipStake::VIA_TRANSFER, null);
            } else {
                // Seller kept nothing — recompute so the cap table drops them.
                $this->ownership->recomputePct((string) $org->id);
            }

            $this->ownership->openStake($org, OrgOwnershipStake::HOLDER_USERS, (string) $buyer->id, $units, OrgOwnershipStake::VIA_TRANSFER, null);

            DB::table('share_offers')->where('id', $offerId)->update([
                'status'            => 'filled',
                'buyer_holder_type' => OrgOwnershipStake::HOLDER_USERS,
                'buyer_holder_id'   => (string) $buyer->id,
                'money_transfer_id' => $entryGroup,
                'settled_at'        => now(),
                'updated_at'        => now(),
            ]);

            return [
                'offer_id'          => $offerId,
                'units'             => (string) $offer->units,
                'total'             => $total,
                'money_transfer_id' => (string) ($entryGroup ?? ''),
            ];
        });
    }

    /** Withdraw an open offer. Only its seller, only while open. */
    public function cancel(User $seller, string $offerId): array
    {
        $offer = DB::table('share_offers')->where('id', $offerId)->first();
        if ($offer === null) {
            throw new RuntimeException('Unknown share offer.');
        }
        if ((string) $offer->seller_holder_id !== (string) $seller->id) {
            throw new \App\Domain\Engine\ConstitutionalViolation(
                'Only the seller may withdraw their own share offer.',
                'CGA Forms Catalog (F-IND-021)'
            );
        }
        if ($offer->status !== 'open') {
            throw new RuntimeException('Only an open offer can be withdrawn.');
        }

        DB::table('share_offers')->where('id', $offerId)->update([
            'status'     => 'cancelled',
            'updated_at' => now(),
        ]);

        return ['offer_id' => $offerId, 'status' => 'cancelled'];
    }

    /** The seller's total OPEN units in an org (stakes are fungible, summed). */
    private function heldUnits(string $organizationId, string $holderId): float
    {
        return (float) OrgOwnershipStake::query()
            ->where('organization_id', $organizationId)
            ->where('holder_type', OrgOwnershipStake::HOLDER_USERS)
            ->where('holder_id', $holderId)
            ->open()
            ->sum('units');
    }

    private function rootCurrency(): Currency
    {
        $rootId = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        $currency = $rootId === null
            ? null
            : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();

        if ($currency === null) {
            throw new RuntimeException('No currency exists to price a share trade.');
        }

        return $currency;
    }
}
