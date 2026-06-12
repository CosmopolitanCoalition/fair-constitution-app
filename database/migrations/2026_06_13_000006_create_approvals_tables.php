<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-6 — `approvals` + `approval_standings`: ESM-04 Approval Standing
 * (PHASE_B_DESIGN_schema_lifecycle §A B-6).
 *
 * `approvals` are SECRET individual approvals (WF-CIV-08) — deliberately
 * NOT a form (design §C): an audit row user→candidacy would violate the
 * constitutional secrecy of individual approvals, so casting/revoking goes
 * through ApprovalService with ZERO per-approval audit entries (the
 * documented audit exception, pinned by ApprovalSecrecyTest). Append +
 * revoke only: no updated_at, no soft deletes. Row access is owner-scoped
 * at the model layer (Approval's global scope); aggregates only ever leave
 * through `approval_standings`.
 *
 * `approval_standings` are the PUBLIC daily aggregates — recomputed by the
 * daily ApprovalStandingsRollupJob, NEVER per-request (Earth-scale rule).
 * `is_frozen = true` marks the finalist-cutoff snapshot archived to the
 * audit chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── approvals (secret; owner-scoped) ────────────────────────────────
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_id');
            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();

            $table->uuid('candidacy_id');
            $table->foreign('candidacy_id')->references('id')->on('candidacies')->cascadeOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Append + revoke only — no updated_at, no soft deletes.
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();

            // The "your active approvals" panel.
            $table->index(['user_id', 'election_id']);
        });

        DB::statement('ALTER TABLE approvals ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // One ACTIVE approval per (candidacy, user); revoked history kept.
        DB::statement(
            'CREATE UNIQUE INDEX approvals_one_active ON approvals (candidacy_id, user_id) ' .
            'WHERE revoked_at IS NULL'
        );
        // Rollup count.
        DB::statement(
            'CREATE INDEX approvals_active_by_candidacy ON approvals (candidacy_id) ' .
            'WHERE revoked_at IS NULL'
        );

        // ── approval_standings (public daily aggregates) ────────────────────
        Schema::create('approval_standings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('race_id');
            $table->foreign('race_id')->references('id')->on('election_races')->cascadeOnDelete();

            $table->uuid('candidacy_id');
            $table->foreign('candidacy_id')->references('id')->on('candidacies')->cascadeOnDelete();

            $table->date('as_of_date');
            $table->integer('approvals_count');
            $table->smallInteger('rank');

            // vs prior day.
            $table->integer('delta')->default(0);

            // Cutoff snapshot — archived to chain.
            $table->boolean('is_frozen')->default(false);

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['candidacy_id', 'as_of_date']);
            $table->index(['race_id', 'as_of_date']);
        });

        DB::statement('ALTER TABLE approval_standings ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_standings');
        Schema::dropIfExists('approvals');
    }
};
