<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-O5 (PHASE_D_DESIGN_organizations §A) — self-managed org document
 * packages (charters, bylaws, HR/compensation policies, custom internal
 * forms) with append-only versioning.
 *
 * Engine rule (F-ORG-001 handler, pinned by test): a package `key` may
 * never collide with a canonical/alias constitutional form ID
 * (FormRegistry::exists() → reject with citation) — self-managed internal
 * forms live ABOVE the constitutional floor and can never override it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_document_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->string('key', 64);
            $table->string('name');
            $table->string('kind', 20);
            $table->string('status', 8)->default('active');

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE org_document_packages ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement(
            "ALTER TABLE org_document_packages ADD CONSTRAINT org_document_packages_kind_check CHECK (kind IN (" .
            "'charter', 'bylaws', 'hr_policy', 'compensation_policy', 'custom_form', 'other'))"
        );
        DB::statement("ALTER TABLE org_document_packages ADD CONSTRAINT org_document_packages_status_check CHECK (status IN ('active', 'retired'))");
        DB::statement('CREATE UNIQUE INDEX org_document_packages_key_unique ON org_document_packages (organization_id, key) WHERE deleted_at IS NULL');

        Schema::create('org_document_package_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('package_id');
            $table->foreign('package_id')->references('id')->on('org_document_packages')->cascadeOnDelete();

            $table->smallInteger('version_no');
            $table->text('content');

            $table->uuid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('created_at')->useCurrent();
            // Versions append, never edit — no updated_at, no soft deletes.

            $table->unique(['package_id', 'version_no']);
        });

        DB::statement('ALTER TABLE org_document_package_versions ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('org_document_package_versions');
        Schema::dropIfExists('org_document_packages');
    }
};
