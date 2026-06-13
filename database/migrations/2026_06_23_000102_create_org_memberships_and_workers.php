<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-O2 (PHASE_D_DESIGN_organizations §A) — the membership (R-24) and
 * worker (R-25) substrates.
 *
 * `org_workers` is THE worker-count source (F-IND-014; headcount =
 * COUNT(*) WHERE status='active' — owner ruling #12) with a POLYMORPHIC
 * employer (`organizations` | `departments`): Art. III §6 applies
 * identically to departments, CGCs, and private orgs, so ONE worker
 * registry feeds one F-IND-014 handler, one headcount recompute, one
 * CLK-13/14 evaluator (the binding cross-designer contract — exec design
 * D-2 "Worker-headcount contract").
 *
 * `contract_id` FK is a forward ref (org_contracts is D-O3) — declared
 * there, the B-10 pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The ownership class; which kinds an org accepts derives from
            // `structure` (engine map: stock→shareholder, partnership/
            // equal_partnership→partner, member_owned/worker_owned/
            // nonprofit→member).
            $table->string('kind', 12);

            // WF-ORG-03: individual applies, org accepts per bylaws.
            $table->string('status', 10)->default('applied');

            $table->timestampTz('applied_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            // The R-23 who accepted.
            $table->uuid('accepted_by_user_id')->nullable();
            $table->foreign('accepted_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('end_reason', 24)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE org_memberships ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE org_memberships ADD CONSTRAINT org_memberships_kind_check CHECK (kind IN ('member', 'shareholder', 'partner'))");
        DB::statement("ALTER TABLE org_memberships ADD CONSTRAINT org_memberships_status_check CHECK (status IN ('applied', 'active', 'ended', 'declined'))");
        DB::statement(
            "ALTER TABLE org_memberships ADD CONSTRAINT org_memberships_end_reason_check CHECK (end_reason IS NULL OR end_reason IN ('resigned', 'removed', 'transferred', 'dissolved'))"
        );
        DB::statement(
            "CREATE UNIQUE INDEX org_memberships_one_open_per_class ON org_memberships (organization_id, user_id, kind) " .
            "WHERE status IN ('applied', 'active') AND deleted_at IS NULL"
        );
        DB::statement("CREATE INDEX org_memberships_active_by_user ON org_memberships (user_id) WHERE status = 'active'");
        DB::statement("CREATE INDEX org_memberships_active_by_org_kind ON org_memberships (organization_id, kind) WHERE status = 'active'");

        Schema::create('org_workers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Polymorphic employer — existence is service-checked
            // ('organizations' | 'departments').
            $table->string('employer_type', 16);
            $table->uuid('employer_id');

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The recurring-labor contract backing the signup (FK in D-O3).
            $table->uuid('contract_id')->nullable();

            // active = countersigned; headcount = COUNT(*) WHERE
            // status='active' (owner ruling #12).
            $table->string('status', 10)->default('applied');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE org_workers ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE org_workers ADD CONSTRAINT org_workers_employer_type_check CHECK (employer_type IN ('organizations', 'departments'))");
        DB::statement("ALTER TABLE org_workers ADD CONSTRAINT org_workers_status_check CHECK (status IN ('applied', 'active', 'ended'))");
        DB::statement(
            "CREATE UNIQUE INDEX org_workers_one_open_per_employer ON org_workers (employer_type, employer_id, user_id) " .
            "WHERE status IN ('applied', 'active') AND deleted_at IS NULL"
        );
        // THE headcount query.
        DB::statement("CREATE INDEX org_workers_active_by_employer ON org_workers (employer_type, employer_id) WHERE status = 'active'");
        DB::statement("CREATE INDEX org_workers_active_by_user ON org_workers (user_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('org_workers');
        Schema::dropIfExists('org_memberships');
    }
};
