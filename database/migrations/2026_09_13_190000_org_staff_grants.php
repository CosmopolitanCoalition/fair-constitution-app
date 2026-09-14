<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IO-5 — scoped organization staff delegation (operator ruling 2026-09-13 ·
 * org-staff-delegation-model = A). F-ORG-011 grant/revoke writes here. A grant
 * lets a named person act on ONE coarse task bucket for ONE organization; it is
 * never a constitutional office. R-31 (org delegate) derives from an active row.
 *
 * Additive, real-dated after the latest existing 2026_09_13_150000_* file. The
 * table is new and empty, so a normal (transactional) migration builds it in
 * one step; no CONCURRENTLY index is needed and the default $withinTransaction
 * applies. The desk applies this on the live box; tests build their own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('org_staff_grants')) {
            return;
        }

        Schema::create('org_staff_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('grantee_user_id');
            $t->string('task');
            $t->string('status')->default('active');
            $t->uuid('granted_by_user_id');
            $t->timestampTz('granted_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->uuid('revoked_by_user_id')->nullable();
            $t->string('end_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index('grantee_user_id', 'org_staff_grants_grantee_idx');
        });

        // One active grant per (organization, grantee, task). A partial unique
        // index on pgsql lets a revoked row and a later re-grant coexist in
        // history; other drivers get a plain composite index (the app layer
        // enforces idempotency there).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX org_staff_grants_active_unique
                 ON org_staff_grants (organization_id, grantee_user_id, task)
                 WHERE status = 'active' AND deleted_at IS NULL"
            );
        } else {
            Schema::table('org_staff_grants', function (Blueprint $t) {
                $t->index(['organization_id', 'grantee_user_id', 'task'], 'org_staff_grants_scope_idx');
            });
        }
    }

    public function down(): void
    {
        // Recorded delegation grants and revocations survive a code rollback.
    }
};
