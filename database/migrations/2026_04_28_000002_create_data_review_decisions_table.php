<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-row decisions captured during the Step 4 manual data review.
 *
 * Operator clicks a row in any of the four review categories
 * (population_gaps, aggregation_discrepancies, orphans, sovereign_territories),
 * sees the full context, and records a decision + optional note. NO autofix —
 * decisions are recorded for later action. The same row can be re-decided
 * (the existing row is updated, not duplicated).
 *
 * Decision values are free-text strings — the frontend offers per-category
 * suggested values but the backend is permissive (operators may need to
 * record nuanced choices we didn't anticipate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_review_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Which review category surfaced this row. Indexed alongside
            // jurisdiction_id for fast (category, entity) lookups when the
            // operator re-opens a row's detail panel.
            $table->string('category', 64);

            // The jurisdictions row this decision is about. NULL for the
            // rare case of a category whose entity isn't a jurisdiction
            // (none today, but keep flexible).
            $table->uuid('jurisdiction_id')->nullable();
            $table->foreign('jurisdiction_id')
                ->references('id')->on('jurisdictions')
                ->cascadeOnDelete();

            // Free-text decision token. Suggested values per category live
            // in DataReviewService::DECISION_VALUES; backend doesn't enforce.
            $table->string('decision', 128);

            // Optional operator note (any context that doesn't fit a token).
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One active decision per (category, jurisdiction_id). Re-deciding
            // updates the existing row; soft-delete + recreate is fine for
            // edge cases (audit history is out of scope for this iteration).
            $table->index(['category', 'jurisdiction_id']);
            $table->unique(
                ['category', 'jurisdiction_id', 'deleted_at'],
                'data_review_decisions_unique_active',
            );
        });

        DB::statement('ALTER TABLE data_review_decisions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('data_review_decisions');
    }
};
