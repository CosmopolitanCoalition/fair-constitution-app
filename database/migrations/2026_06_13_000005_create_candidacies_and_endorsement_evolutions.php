<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-5 — `candidacies` (ESM-06) + `endorsement_requests` + endorsements /
 * organizations evolutions (PHASE_B_DESIGN_schema_lifecycle §A B-5).
 *
 * `candidacies.rejection_reason`: THE ONLY PERMISSIBLE GROUND is enforced
 * by the database itself — 'no_residency_association' (Art. I; Pham v. NY
 * County). Any other rejection ground is a constitutional violation and
 * cannot be stored.
 *
 * `residency_attested_at` is the F-IND-011 checkbox — the only attestation
 * that may exist. `withdrawn_at`: the engine blocks withdrawal after
 * `finalist_cutoff_at` (ballot lock). `race_id` is bound at F-ELB-002
 * validation from the candidate's deepest active residency_confirmations
 * row mapped through legislature_district_jurisdictions; NULL until
 * validated.
 *
 * `endorsements` (verified-empty skeleton): gains its long-deferred FK
 * candidate_id → candidacies, plus `is_public` (individual endorsers
 * disclose by choice — my-record contract; org endorsements are forced
 * true by the F-ORG-002 handler). Nothing dropped.
 *
 * `organizations.agent_user_id`: minimal R-23 substrate so F-ORG-002 has a
 * role gate (full org module stays Phase D).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── candidacies (ESM-06) ────────────────────────────────────────────
        Schema::create('candidacies', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('election_id');
            $table->foreign('election_id')->references('id')->on('elections')->cascadeOnDelete();

            // NULL until F-ELB-002 validation binds it.
            $table->uuid('race_id')->nullable();
            $table->foreign('race_id')->references('id')->on('election_races')->nullOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            $table->string('status', 16)->default('registered');

            $table->text('platform_statement')->nullable();
            $table->jsonb('position_tags')->default('[]');

            // The F-IND-011 checkbox — the only attestation that may exist.
            $table->timestampTz('residency_attested_at');

            $table->timestampTz('validated_at')->nullable();
            $table->uuid('validated_by_member_id')->nullable();
            $table->foreign('validated_by_member_id')->references('id')->on('election_board_members')->nullOnDelete();

            $table->string('rejection_reason', 32)->nullable();

            // Engine blocks after finalist_cutoff_at (ballot lock).
            $table->timestampTz('withdrawn_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['election_id', 'user_id']);
            $table->index(['race_id', 'status']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE candidacies ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement(
            "ALTER TABLE candidacies ADD CONSTRAINT candidacies_status_check CHECK (status IN (" .
            "'registered', 'validated', 'rejected', 'in_pool', 'finalist', 'non_finalist', " .
            "'withdrawn', 'elected', 'defeated'))"
        );
        // Art. I: residency is the ONLY permissible rejection ground.
        DB::statement(
            "ALTER TABLE candidacies ADD CONSTRAINT candidacies_rejection_reason_check " .
            "CHECK (rejection_reason IS NULL OR rejection_reason = 'no_residency_association')"
        );

        // ── endorsements evolutions (verified-empty skeleton) ───────────────
        DB::statement(
            'ALTER TABLE endorsements ADD CONSTRAINT endorsements_candidate_id_foreign ' .
            'FOREIGN KEY (candidate_id) REFERENCES candidacies(id) ON DELETE CASCADE'
        );
        Schema::table('endorsements', function (Blueprint $table) {
            $table->boolean('is_public')->default(false);
        });

        // ── endorsement_requests (F-CAN-002 → F-ORG-002 handshake) ─────────
        Schema::create('endorsement_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('candidacy_id');
            $table->foreign('candidacy_id')->references('id')->on('candidacies')->cascadeOnDelete();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->text('message')->nullable();

            $table->string('status', 12)->default('pending');

            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();

            $table->uuid('endorsement_id')->nullable();
            $table->foreign('endorsement_id')->references('id')->on('endorsements')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['candidacy_id', 'organization_id']);
        });

        DB::statement('ALTER TABLE endorsement_requests ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE endorsement_requests ADD CONSTRAINT endorsement_requests_status_check " .
            "CHECK (status IN ('pending', 'granted', 'declined'))"
        );

        // ── organizations: minimal R-23 substrate ───────────────────────────
        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('agent_user_id')->nullable()
                ->comment('R-23 role gate for F-ORG-002 (full org module is Phase D)');
            $table->foreign('agent_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('agent_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['agent_user_id']);
            $table->dropIndex(['agent_user_id']);
            $table->dropColumn('agent_user_id');
        });

        Schema::dropIfExists('endorsement_requests');

        Schema::table('endorsements', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
        DB::statement('ALTER TABLE endorsements DROP CONSTRAINT IF EXISTS endorsements_candidate_id_foreign');

        Schema::dropIfExists('candidacies');
    }
};
