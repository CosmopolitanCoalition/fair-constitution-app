<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase J (W-0300) — the three columns of docs/plans/coalition/PHASE_J_PLAN.md §5.
 *
 *   organizations.public_domain_charter   the VOLUNTARY public-domain charter an
 *                                          organisation may adopt; one-way false
 *                                          to true, guarded in the model beside the
 *                                          Art. III §5 CGC guard. Distinct from
 *                                          ip_is_public_domain on purpose (§6): the
 *                                          constitutional mandate stays legible as a
 *                                          mandate, never mistaken for a choice.
 *   cgc_ip_register.dedication_basis      why a register row is public domain:
 *                                          constitutional_mandate (a CGC) or
 *                                          voluntary_charter. Nullable so every
 *                                          existing row stays valid.
 *   org_memberships.is_public             a member's choice to be listed; privacy
 *                                          is the default, publicity the opt-in.
 *
 * Real-dated, additive, rerunnable. PostgreSQL carries the CHECK; SQLite fixtures
 * take the columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organizations') && ! Schema::hasColumn('organizations', 'public_domain_charter')) {
            Schema::table('organizations', function ($table) {
                $table->boolean('public_domain_charter')->default(false);
            });
        }

        if (Schema::hasTable('cgc_ip_register') && ! Schema::hasColumn('cgc_ip_register', 'dedication_basis')) {
            Schema::table('cgc_ip_register', function ($table) {
                $table->string('dedication_basis', 24)->nullable();
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE cgc_ip_register ADD CONSTRAINT cgc_ip_register_dedication_basis_check CHECK (dedication_basis IS NULL OR dedication_basis IN ('constitutional_mandate', 'voluntary_charter'))");
            }
        }

        if (Schema::hasTable('org_memberships') && ! Schema::hasColumn('org_memberships', 'is_public')) {
            Schema::table('org_memberships', function ($table) {
                $table->boolean('is_public')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('org_memberships') && Schema::hasColumn('org_memberships', 'is_public')) {
            Schema::table('org_memberships', fn ($table) => $table->dropColumn('is_public'));
        }
        if (Schema::hasTable('cgc_ip_register') && Schema::hasColumn('cgc_ip_register', 'dedication_basis')) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE cgc_ip_register DROP CONSTRAINT IF EXISTS cgc_ip_register_dedication_basis_check');
            }
            Schema::table('cgc_ip_register', fn ($table) => $table->dropColumn('dedication_basis'));
        }
        if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'public_domain_charter')) {
            Schema::table('organizations', fn ($table) => $table->dropColumn('public_domain_charter'));
        }
    }
};
