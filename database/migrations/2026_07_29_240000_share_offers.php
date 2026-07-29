<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * share_offers — holder-to-holder equity RESALE on the exchange (Wave 4 ②;
 * operator ruled IN, A; migration slot granted by the desk 2026-07-29). ONE
 * additive table: an open sell-offer becomes 'filled' when a buyer takes it
 * (buyer + settled_at recorded on the same row), so the exchange floor = open
 * offers and the equity trade tape = filled offers.
 *
 * TWO PLANES held apart, as everywhere in the economy:
 *   - OWNERSHIP (who owns): seller/buyer are NAMED holders (holder_type/holder_id
 *     over users|organizations|jurisdictions) — the named plane, Ruling B.
 *   - MONEY (what was paid): the cash leg is an account-scoped transfer recorded
 *     in market_transactions; money_transfer_id points at it. No account id lives
 *     on this row — the money never joins the named ownership record.
 *
 * A share is equity, never currency (Art. V §5). Only a STOCK org has shares to
 * trade; status/holder-type/units are app-enforced in ShareTradeService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');           // whose equity is offered
            $table->string('seller_holder_type', 16);  // users | organizations | jurisdictions
            $table->uuid('seller_holder_id');
            $table->decimal('units', 24, 6);           // units offered (≤ seller's open stake)
            $table->decimal('price_per_unit', 24, 6);  // money — a string at the boundary
            $table->uuid('currency_id');
            $table->string('status', 16)->default('open'); // open | filled | cancelled

            // Filled-only: who bought, and the money leg (account plane) it rode.
            $table->string('buyer_holder_type', 16)->nullable();
            $table->uuid('buyer_holder_id')->nullable();
            $table->uuid('money_transfer_id')->nullable(); // → market_transactions.id
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['seller_holder_type', 'seller_holder_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_offers');
    }
};
