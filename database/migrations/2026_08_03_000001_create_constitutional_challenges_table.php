<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-CHALLENGE E-1 (PHASE_E_DESIGN_challenge_law §A) — `constitutional_challenges`,
 * THE Art. IV §5 machine (ESM-CC). The challenge is its own durable entity,
 * distinct from a `cases` row: a challenge MAY be heard inside a case, but the
 * §5 resolution (finding → windows → three paths) is challenge-scoped state
 * that outlives any single hearing. One challenge ⇒ at most one finding ⇒ at
 * most one remedy recommendation ⇒ exactly one terminal path.
 *
 * The CLK-11/CLK-12 timers point at THIS row (subject_type
 * 'constitutional_challenges'); the legislature's amendment/override is checked
 * against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('constitutional_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The law's binding jurisdiction or a descendant under it (§5.1).
            $table->uuid('jurisdiction_id');
            $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();

            // The court hearing it (resolved from the law's scope_judiciary_id
            // or the jurisdiction's active court — B.1).
            $table->uuid('judiciary_id');
            $table->foreign('judiciary_id')->references('id')->on('judiciaries')->restrictOnDelete();

            // The legislation alleged contradictory (§5.1), pinned at the
            // version filed against.
            $table->uuid('challenged_law_id');
            $table->foreign('challenged_law_id')->references('id')->on('laws')->restrictOnDelete();
            $table->smallInteger('challenged_version_no');

            // The inhabitant (R-03) — §5.1. Absolute-right standing.
            $table->uuid('filed_by_user_id');
            $table->foreign('filed_by_user_id')->references('id')->on('users')->restrictOnDelete();

            $table->text('claim_text');

            // §5.2 "contradictory to other law or The Constitution".
            $table->string('claimed_basis', 20);
            $table->uuid('cited_authority_law_id')->nullable();
            $table->foreign('cited_authority_law_id')->references('id')->on('laws')->nullOnDelete();
            $table->string('constitutional_citation', 64)->nullable();

            // The cases row the court opens to hear it (judiciary-core);
            // NULL until panelled.
            $table->uuid('case_id')->nullable();
            $table->foreign('case_id')->references('id')->on('cases')->nullOnDelete();

            $table->string('status', 28);

            $table->uuid('finding_id')->nullable();
            $table->uuid('remedy_id')->nullable();

            // The terminal Art. IV §5 path; NULL until closed.
            // varchar(24): 'legislative_amendment' is 21 chars (the Phase D
            // column-width lesson — confirm the longest enum value fits).
            $table->string('resolution_path', 24)->nullable();
            $table->string('resolution_ref_type', 40)->nullable();
            $table->uuid('resolution_ref_id')->nullable();

            $table->timestampTz('filed_at')->nullable();
            $table->timestampTz('heard_at')->nullable();
            $table->timestampTz('finding_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->uuid('record_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['challenged_law_id', 'status']);
            $table->index(['judiciary_id', 'status']);
        });

        DB::statement('ALTER TABLE constitutional_challenges ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            'ALTER TABLE constitutional_challenges ADD CONSTRAINT constitutional_challenges_claimed_basis_check '.
            "CHECK (claimed_basis IN ('constitution', 'other_law'))"
        );

        // ESM-CC — the challenge lifecycle status enum (the mockup's state
        // strip, made precise). The three branch states + dismissed each
        // transition to closed; kept distinct so the docket shows WHICH path.
        DB::statement(
            'ALTER TABLE constitutional_challenges ADD CONSTRAINT constitutional_challenges_status_check CHECK (status IN ('.
            "'filed', 'under_review', 'dismissed', 'finding_issued', 'remedy_recommended', ".
            "'legislative_window_open', 'amended_by_legislature', 'overridden', ".
            "'judicial_remedy_applied', 'closed'))"
        );

        DB::statement(
            'ALTER TABLE constitutional_challenges ADD CONSTRAINT constitutional_challenges_resolution_path_check '.
            'CHECK (resolution_path IS NULL OR resolution_path IN '.
            "('legislative_amendment', 'legislature_override', 'judicial_remedy', 'dismissed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('constitutional_challenges');
    }
};
