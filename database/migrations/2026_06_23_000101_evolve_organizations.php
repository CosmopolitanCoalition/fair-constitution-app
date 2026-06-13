<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-O1 (PHASE_D_DESIGN_organizations §A) — additive evolution of
 * `organizations` (live rows exist: San Marino/Montegiardino/Earth seeds —
 * no drop/recreate).
 *
 *  - `structure`: the 6-value frozen mockup enum (org-registry.html).
 *    "Public good" is NOT a structure value — it is `is_cgc=true` +
 *    `ownership_type='public'`. NULL for CGCs/informal/political parties.
 *  - `status`: ESM-18 resting states. `is_active`/`is_registered` are kept
 *    in sync by OrgRegistryService for existing readers; the boolean drop
 *    is deferred to the all-phases-done pass (flagged, §E.4.1).
 *  - `worker_count` (renamed from `employee_count`): counter cache,
 *    recomputed ONLY by RecomputeWorkerHeadcountJob. "Worker", not
 *    "employee" — owner ruling #12 (Art. III §6 scope is F-IND-014
 *    signups).
 *  - `board_id`: the unified boards row (exec design D-2 contract).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('structure', 20)->nullable();
            $table->string('status', 16)->default('registered');

            // F-IND-012 provenance: the founding R-23. `agent_user_id`
            // stays the CURRENT agent (reassignable via F-ORG-001).
            $table->uuid('registered_by_user_id')->nullable();
            $table->foreign('registered_by_user_id')->references('id')->on('users')->nullOnDelete();

            // 'F-IND-012' (self) | 'F-LEG-019' (CGC charter).
            $table->string('registered_via_form', 12)->nullable();

            $table->text('purpose')->nullable();

            // CGC chartering act (created_by_legislature_id stays as the
            // denormalized convenience).
            $table->uuid('created_by_law_id')->nullable();
            $table->foreign('created_by_law_id')->references('id')->on('laws')->restrictOnDelete();

            $table->uuid('board_id')->nullable();

            // public_records seal of the registration.
            $table->uuid('registration_record_id')->nullable();
        });

        // worker = F-IND-014 signups (owner ruling #12) — "employee" is
        // the wrong word constitutionally.
        if (Schema::hasColumn('organizations', 'employee_count') && ! Schema::hasColumn('organizations', 'worker_count')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->renameColumn('employee_count', 'worker_count');
            });
        }

        // boards substrate exists by ordering (000100 fallback or the exec
        // builder's canonical migration) — attach the FK.
        if (Schema::hasTable('boards')) {
            DB::statement(
                'ALTER TABLE organizations ADD CONSTRAINT organizations_board_id_foreign ' .
                'FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE SET NULL'
            );
        }

        DB::statement(
            "ALTER TABLE organizations ADD CONSTRAINT organizations_structure_check CHECK (structure IS NULL OR structure IN (" .
            "'stock', 'partnership', 'equal_partnership', 'member_owned', 'worker_owned', 'nonprofit'))"
        );
        DB::statement(
            "ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN (" .
            "'registered', 'active', 'transfer_pending', 'transferred', 'converted', 'dissolved'))"
        );

        // ESM-18 backfill from the legacy booleans.
        DB::statement(
            "UPDATE organizations SET status = CASE
                WHEN dissolved_at IS NOT NULL THEN 'dissolved'
                WHEN is_active AND is_registered THEN 'active'
                ELSE 'registered'
             END"
        );

        Schema::table('organizations', function (Blueprint $table) {
            $table->index('status');
        });
        DB::statement(
            "CREATE INDEX organizations_active_by_jurisdiction ON organizations (jurisdiction_id) WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizations_active_by_jurisdiction');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_structure_check');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_status_check');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_board_id_foreign');

        if (Schema::hasColumn('organizations', 'worker_count')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->renameColumn('worker_count', 'employee_count');
            });
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropForeign(['registered_by_user_id']);
            $table->dropForeign(['created_by_law_id']);
            $table->dropColumn([
                'structure', 'status', 'registered_by_user_id', 'registered_via_form',
                'purpose', 'created_by_law_id', 'board_id', 'registration_record_id',
            ]);
        });
    }
};
