<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (Art. VI §2–3) — Constitutional restoration. Activates when a Fair
 * Government is (a) COUNTERMANDED, (b) CAPTURED/disabled, or (c) DESTROYED —
 * each JUDICIALLY REVIEWED (tied to a Phase E case finding; no unilateral
 * declaration). The three-tier cascade then restores order:
 *   Tier 1 — constituents hold elections to form a new legislature;
 *   Tier 2 — the encompassing jurisdiction calls elections to replace members;
 *   Tier 3 — individuals self-organize into new jurisdictions per the Template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restoration_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            $table->string('condition', 16); // countermanded | captured | destroyed
            $table->jsonb('evidence')->default('{}');

            // The Phase E case whose constitutional finding activated this
            // (no unilateral activation — Art. VI §2 is judicially reviewed).
            $table->uuid('review_case_id')->nullable();
            $table->boolean('judicially_confirmed')->default(false);

            $table->unsignedTinyInteger('tier')->nullable(); // 1 | 2 | 3
            $table->uuid('tier_election_id')->nullable();

            $table->string('status', 16)->default('declared'); // declared | confirmed | restoring | restored | abandoned

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['jurisdiction_id', 'status']);
        });

        DB::statement('ALTER TABLE restoration_events ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE restoration_events ADD CONSTRAINT restoration_events_condition_check CHECK (condition IN ('countermanded','captured','destroyed'))");
        DB::statement("ALTER TABLE restoration_events ADD CONSTRAINT restoration_events_tier_check CHECK (tier IS NULL OR tier IN (1,2,3))");
        DB::statement("ALTER TABLE restoration_events ADD CONSTRAINT restoration_events_status_check CHECK (status IN ('declared','confirmed','restoring','restored','abandoned'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('restoration_events');
    }
};
