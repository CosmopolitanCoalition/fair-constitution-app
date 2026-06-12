<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-9 — `election_certifications` + `election_audits`
 * (PHASE_B_DESIGN_schema_lifecycle §A B-9).
 *
 * `election_certifications`: one F-ELB-004 certification per election
 * (partial unique on status='certified'; an audit re-run supersedes via
 * status='superseded_by_audit' and a fresh certification row).
 * `certified_by_member_id` is NULL only for the bootstrap system board —
 * the synthetic member row makes it effectively NOT NULL; kept nullable
 * with the engine check (WI-B4).
 *
 * `election_audits`: the recount = AUDIT RE-RUN reframing — never a hand
 * count. F-ELB-006 requires a stated cause (text NOT NULL). Engine gate:
 * creatable only when a certification row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── election_certifications ─────────────────────────────────────────
        Schema::create('election_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_id');
            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();

            $table->uuid('election_board_id');
            $table->foreign('election_board_id')->references('id')->on('election_boards')->restrictOnDelete();

            // NULL only when bootstrap-system board (see docblock).
            $table->uuid('certified_by_member_id')->nullable();
            $table->foreign('certified_by_member_id')->references('id')->on('election_board_members')->nullOnDelete();

            $table->timestampTz('certified_at');

            // Hash over all race record_hashes.
            $table->char('count_record_hash', 64);

            $table->string('status', 24)->default('certified');

            $table->timestampsTz();

            $table->index('election_id');
        });

        DB::statement('ALTER TABLE election_certifications ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE election_certifications ADD CONSTRAINT election_certifications_status_check " .
            "CHECK (status IN ('certified', 'superseded_by_audit'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX election_certifications_one_current ON election_certifications ' .
            "(election_id) WHERE status = 'certified'"
        );

        // ── election_audits ─────────────────────────────────────────────────
        Schema::create('election_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_id');
            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();

            $table->uuid('race_id')->nullable();
            $table->foreign('race_id')->references('id')->on('election_races')->nullOnDelete();

            // F-ELB-006 requires a stated cause.
            $table->text('cause');

            $table->uuid('ordered_by')->nullable();
            $table->foreign('ordered_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('ordered_at');

            // The re-run.
            $table->uuid('tabulation_id')->nullable();
            $table->foreign('tabulation_id')->references('id')->on('tabulations')->nullOnDelete();

            $table->string('outcome', 12)->nullable();
            $table->timestampTz('resolved_at')->nullable();

            $table->timestampsTz();

            $table->index('election_id');
        });

        DB::statement('ALTER TABLE election_audits ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE election_audits ADD CONSTRAINT election_audits_outcome_check " .
            "CHECK (outcome IS NULL OR outcome IN ('reaffirmed', 'corrected'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('election_audits');
        Schema::dropIfExists('election_certifications');
    }
};
